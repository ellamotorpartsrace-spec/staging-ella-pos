<?php
header("Content-Type: application/json");
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';

requireLogin();
if (!hasPermission('shopee_sync')) {
    echo json_encode(['success' => false, 'error' => 'Permission denied. Please request access from admin Les.']);
    exit;
}


/**
 * Reusable core conflict detection engine
 */
function runConflictDetection($conn) {
    // Ensure SKU indexes exist for high-performance conflict scans (self-healing)
    try {
        $indexes = $conn->query("SHOW INDEX FROM shopee_product_mappings WHERE Key_name = 'idx_parent_sku'")->fetchAll();
        if (empty($indexes)) {
            $conn->exec("ALTER TABLE shopee_product_mappings ADD INDEX idx_parent_sku (shopee_parent_sku)");
        }
        $indexes2 = $conn->query("SHOW INDEX FROM shopee_product_mappings WHERE Key_name = 'idx_variation_sku'")->fetchAll();
        if (empty($indexes2)) {
            $conn->exec("ALTER TABLE shopee_product_mappings ADD INDEX idx_variation_sku (shopee_variation_sku)");
        }
        // Ensure whitelist table exists
        $conn->exec("CREATE TABLE IF NOT EXISTS shopee_duplicate_whitelist (
            sku VARCHAR(255) PRIMARY KEY,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");
    } catch (Exception $idxEx) {
        // Silently log or ignore index errors to prevent blocking the scan
    }

    // 1. Fetch all currently open errors
    $stmt = $conn->query("SELECT id, error_type, shopee_item_id, shopee_model_id, sku FROM shopee_error_logs WHERE status = 'open'");
    $openErrors = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $remainingOpen = [];
    foreach ($openErrors as $err) {
        $remainingOpen[$err['id']] = $err;
    }

    $errorsCount = 0;
    
    // Helper to log or preserve errors
    $markActiveOrInsert = function($type, $itemId, $modelId, $sku, $message) use (&$remainingOpen, $conn, &$errorsCount) {
        $foundId = null;
        foreach ($remainingOpen as $id => $err) {
            if ($err['error_type'] === $type) {
                if (($type === 'missing_sku' || $type === 'unmapped') && $err['shopee_item_id'] == $itemId && $err['shopee_model_id'] == $modelId) {
                    $foundId = $id; break;
                }
                if ($type === 'duplicate_sku' && $err['sku'] === $sku) {
                    $foundId = $id; break;
                }
            }
        }

        if ($foundId !== null) {
            // Exists and still open, remove from remaining list so it won't be resolved
            unset($remainingOpen[$foundId]);
            $conn->prepare("UPDATE shopee_error_logs SET error_message = ? WHERE id = ?")->execute([$message, $foundId]);
        } else {
            // New error, insert it
            $conn->prepare("
                INSERT INTO shopee_error_logs (error_type, shopee_item_id, shopee_model_id, sku, error_message, status, created_at)
                VALUES (?, ?, ?, ?, ?, 'open', NOW())
            ")->execute([$type, $itemId, $modelId, $sku, $message]);
        }
        $errorsCount++;
    };

    // 2. Detect Missing SKUs
    // Missing parent SKU if no variations
    $stmt = $conn->query("
        SELECT id, shopee_item_id, shopee_model_id, shopee_product_name, shopee_variation_name
        FROM shopee_product_mappings 
        WHERE has_variation = 0 AND (shopee_parent_sku IS NULL OR shopee_parent_sku = '')
        AND pos_product_id IS NULL
    ");
    $missingParents = $stmt->fetchAll();
    
    // Missing variation SKU if has variations
    $stmt = $conn->query("
        SELECT id, shopee_item_id, shopee_model_id, shopee_product_name, shopee_variation_name
        FROM shopee_product_mappings 
        WHERE has_variation = 1 AND (shopee_variation_sku IS NULL OR shopee_variation_sku = '')
        AND pos_product_id IS NULL
    ");
    $missingVars = $stmt->fetchAll();

    foreach ($missingParents as $row) {
        $msg = "Product '{$row['shopee_product_name']}' is missing a SKU.";
        $markActiveOrInsert('missing_sku', $row['shopee_item_id'], $row['shopee_model_id'], null, $msg);
    }
    foreach ($missingVars as $row) {
        $msg = "Variation '{$row['shopee_variation_name']}' under '{$row['shopee_product_name']}' is missing a SKU.";
        $markActiveOrInsert('missing_sku', $row['shopee_item_id'], $row['shopee_model_id'], null, $msg);
    }

    // 2.5 Detect Unmapped SKUs (Has SKU, but not matched to POS)
    $stmt = $conn->query("
        SELECT id, shopee_item_id, shopee_model_id, shopee_product_name, shopee_variation_name, shopee_parent_sku
        FROM shopee_product_mappings 
        WHERE has_variation = 0 AND shopee_parent_sku != '' AND shopee_parent_sku IS NOT NULL
        AND pos_product_id IS NULL
    ");
    $unmappedParents = $stmt->fetchAll();

    $stmt = $conn->query("
        SELECT id, shopee_item_id, shopee_model_id, shopee_product_name, shopee_variation_name, shopee_variation_sku
        FROM shopee_product_mappings 
        WHERE has_variation = 1 AND shopee_variation_sku != '' AND shopee_variation_sku IS NOT NULL
        AND pos_product_id IS NULL
    ");
    $unmappedVars = $stmt->fetchAll();

    foreach ($unmappedParents as $row) {
        $msg = "Product '{$row['shopee_product_name']}' has Shopee SKU '{$row['shopee_parent_sku']}' but is not linked to any POS product.";
        $markActiveOrInsert('unmapped', $row['shopee_item_id'], $row['shopee_model_id'], $row['shopee_parent_sku'], $msg);
    }
    foreach ($unmappedVars as $row) {
        $msg = "Variation '{$row['shopee_variation_name']}' under '{$row['shopee_product_name']}' has Shopee SKU '{$row['shopee_variation_sku']}' but is not linked to any POS product.";
        $markActiveOrInsert('unmapped', $row['shopee_item_id'], $row['shopee_model_id'], $row['shopee_variation_sku'], $msg);
    }

    // Reset all non-mapped items to 'unmapped' before applying new dynamic issues
    $conn->exec("
        UPDATE shopee_product_mappings 
        SET mapping_status = 'unmapped' 
        WHERE mapping_status NOT IN ('manual', 'auto')
    ");

    // 3. Detect Duplicate SKUs within Shopee (two shopee items with the same SKU) using UNION ALL (optimized for indexes!)
    $stmt = $conn->query("
        SELECT match_sku, COUNT(*) as cnt FROM (
            SELECT shopee_parent_sku AS match_sku FROM shopee_product_mappings 
            WHERE has_variation = 0 AND shopee_parent_sku != '' AND shopee_parent_sku IS NOT NULL AND mapping_status != 'missing_sku'
            UNION ALL
            SELECT shopee_variation_sku AS match_sku FROM shopee_product_mappings 
            WHERE has_variation = 1 AND shopee_variation_sku != '' AND shopee_variation_sku IS NOT NULL AND mapping_status != 'missing_sku'
        ) as combined
        GROUP BY match_sku
        HAVING cnt > 1
    ");
    $duplicateSkus = $stmt->fetchAll();

    // Fetch whitelist
    $whitelistStmt = $conn->query("SELECT sku FROM shopee_duplicate_whitelist");
    $whitelistedSkus = $whitelistStmt->fetchAll(PDO::FETCH_COLUMN);

    foreach ($duplicateSkus as $row) {
        if (in_array($row['match_sku'], $whitelistedSkus)) {
            continue; // Skip whitelisted shared listings
        }
        $msg = "SKU '{$row['match_sku']}' is used by multiple Shopee products. This will cause stock sync conflicts.";
        $markActiveOrInsert('duplicate_sku', null, null, $row['match_sku'], $msg);
    }

    // Mark any remaining open errors that were NOT detected this scan as RESOLVED!
    if (!empty($remainingOpen)) {
        $resolvedIds = array_keys($remainingOpen);
        $placeholders = str_repeat('?,', count($resolvedIds) - 1) . '?';
        $resolveStmt = $conn->prepare("UPDATE shopee_error_logs SET status = 'resolved', resolved_at = NOW() WHERE id IN ($placeholders)");
        $resolveStmt->execute($resolvedIds);
    }

    // Single highly optimized JOIN query to mark all unmapped duplicate SKUs in one go (uses indexes!)
    if (!empty($duplicateSkus)) {
        // Exclude whitelisted SKUs from being marked as duplicate in the mappings table
        $whitelistFilter = "";
        if (!empty($whitelistedSkus)) {
            $quotedSkus = array_map(function($s) use ($conn) { return $conn->quote($s); }, $whitelistedSkus);
            $whitelistFilter = " AND dup.match_sku NOT IN (" . implode(',', $quotedSkus) . ") ";
        }

        $conn->exec("
            UPDATE shopee_product_mappings spm
            JOIN (
                SELECT match_sku FROM (
                    SELECT shopee_parent_sku AS match_sku FROM shopee_product_mappings 
                    WHERE has_variation = 0 AND shopee_parent_sku != '' AND shopee_parent_sku IS NOT NULL AND mapping_status != 'missing_sku'
                    UNION ALL
                    SELECT shopee_variation_sku AS match_sku FROM shopee_product_mappings 
                    WHERE has_variation = 1 AND shopee_variation_sku != '' AND shopee_variation_sku IS NOT NULL AND mapping_status != 'missing_sku'
                ) as combined
                GROUP BY match_sku
                HAVING COUNT(*) > 1
            ) dup ON (spm.has_variation = 0 AND spm.shopee_parent_sku = dup.match_sku) 
                  OR (spm.has_variation = 1 AND spm.shopee_variation_sku = dup.match_sku)
            SET spm.mapping_status = 'duplicate'
            WHERE spm.mapping_status NOT IN ('manual', 'auto') $whitelistFilter
        ");
    }

    // Update missing_sku status as well (only for unmapped ones!)
    $conn->exec("
        UPDATE shopee_product_mappings 
        SET mapping_status = 'missing_sku' 
        WHERE mapping_status NOT IN ('manual', 'auto')
          AND (
            (has_variation = 0 AND (shopee_parent_sku IS NULL OR shopee_parent_sku = ''))
            OR (has_variation = 1 AND (shopee_variation_sku IS NULL OR shopee_variation_sku = ''))
          )
    ");

    return $errorsCount;
}

// Only execute inline if this script is executed directly
if (basename($_SERVER['SCRIPT_FILENAME']) === 'detect_conflicts.php') {
    try {
        $db = new Database();
        $conn = $db->getConnection();

        $errors = runConflictDetection($conn);

        echo json_encode([
            'success' => true,
            'conflicts_detected' => $errors,
            'message' => "Detected {$errors} SKU conflicts."
        ]);

    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

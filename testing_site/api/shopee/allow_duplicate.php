<?php
// api/shopee/allow_duplicate.php
header("Content-Type: application/json");
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once __DIR__ . '/sync_helpers.php';

requireLogin();
if (!hasPermission('shopee_sync')) {
    echo json_encode(['success' => false, 'error' => 'Permission denied. Please request access from admin Les.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request method']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$sku = $input['sku'] ?? null;

if (empty($sku)) {
    echo json_encode(['success' => false, 'error' => 'Invalid SKU']);
    exit;
}

try {
    $db = new Database();
    $conn = $db->getConnection();
    
    // Ensure table exists just in case
    $conn->exec("CREATE TABLE IF NOT EXISTS shopee_duplicate_whitelist (
        sku VARCHAR(255) PRIMARY KEY,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    $conn->beginTransaction();

    // 1. Add to whitelist
    $stmt = $conn->prepare("INSERT IGNORE INTO shopee_duplicate_whitelist (sku) VALUES (?)");
    $stmt->execute([$sku]);

    // 2. Mark error as resolved
    $stmt2 = $conn->prepare("UPDATE shopee_error_logs SET status = 'resolved', resolved_at = NOW() WHERE error_type = 'duplicate_sku' AND sku = ? AND status = 'open'");
    $stmt2->execute([$sku]);

    // 3. Automatically map the shared listings to the POS product!
    $lookupStmt = $conn->prepare("
        SELECT variation_id, sku 
        FROM product_variations 
        WHERE sku = ? AND sku != '' AND sku IS NOT NULL 
        LIMIT 1
    ");
    $lookupStmt->execute([$sku]);
    $posVar = $lookupStmt->fetch(PDO::FETCH_ASSOC);

    if ($posVar) {
        $updateStmt = $conn->prepare("
            UPDATE shopee_product_mappings 
            SET pos_product_id = ?, 
                matched_pos_sku = ?, 
                mapping_status = 'auto', 
                updated_at = NOW() 
            WHERE mapping_status NOT IN ('manual', 'auto')
              AND (
                  (has_variation = 0 AND shopee_parent_sku = ?) OR 
                  (has_variation = 1 AND shopee_variation_sku = ?)
              )
        ");
        $updateStmt->execute([$posVar['variation_id'], $posVar['sku'], $sku, $sku]);

        // Also explicitly resolve any mapping rows stuck in 'duplicate' status for this SKU
        // (in case they were previously flagged and weren't covered above)
        $conn->prepare("
            UPDATE shopee_product_mappings
            SET mapping_status = 'auto', pos_product_id = ?, matched_pos_sku = ?, updated_at = NOW()
            WHERE mapping_status = 'duplicate'
              AND (
                  (has_variation = 0 AND shopee_parent_sku = ?) OR
                  (has_variation = 1 AND shopee_variation_sku = ?)
              )
        ")->execute([$posVar['variation_id'], $posVar['sku'], $sku, $sku]);

        // Trigger POS stock allocation recalculation!
        // Now that these shared mappings are marked as 'auto', we call propagateStockToPos
        // with mapId = null so it sums up all their shopee_stock and allocates it to the POS inventory.
        propagateStockToPos($conn, $posVar['variation_id'], 0, 'Shared Listing Resolution', $sku, $_SESSION['user_id'] ?? null, null);
    } else {
        // If not found in POS yet, just reset status from 'duplicate' to 'unmapped'
        $resetStmt = $conn->prepare("
            UPDATE shopee_product_mappings 
            SET mapping_status = 'unmapped'
            WHERE mapping_status = 'duplicate'
              AND (
                  (has_variation = 0 AND shopee_parent_sku = ?) OR 
                  (has_variation = 1 AND shopee_variation_sku = ?)
              )
        ");
        $resetStmt->execute([$sku, $sku]);
    }

    // 4. Record action in shopee sync logs
    $namesStmt = $conn->prepare("
        SELECT shopee_product_name, shopee_variation_name 
        FROM shopee_product_mappings 
        WHERE (has_variation = 0 AND shopee_parent_sku = ?) 
           OR (has_variation = 1 AND shopee_variation_sku = ?)
    ");
    $namesStmt->execute([$sku, $sku]);
    $sharedItems = $namesStmt->fetchAll(PDO::FETCH_ASSOC);

    $allowedNames = [];
    foreach ($sharedItems as $si) {
        $n = $si['shopee_product_name'];
        if (!empty($si['shopee_variation_name'])) {
            $n .= ' — ' . $si['shopee_variation_name'];
        }
        $allowedNames[] = $n;
    }
    $productNamesStr = !empty($allowedNames) ? implode(' || ', $allowedNames) : 'Shared Listings';
    if (mb_strlen($productNamesStr) > 250) {
        $productNamesStr = mb_substr($productNamesStr, 0, 247) . '...';
    }

    $logStmt = $conn->prepare("
        INSERT INTO shopee_sync_logs (event_type, product_name, sku, source, status, new_value, created_by, created_at)
        VALUES ('mapping', ?, ?, 'Error Resolution Center', 'success', 'Allowed as Shared Listing', ?, NOW())
    ");
    $logStmt->execute([$productNamesStr, $sku, $_SESSION['user_id'] ?? null]);

    $conn->commit();

    // 5. Re-run conflict detection to immediately remove this from the open error list.
    // Without this, the error would stay visible until the next manual scan or save action.
    require_once __DIR__ . '/detect_conflicts.php';
    runConflictDetection($conn);

    echo json_encode([
        'success' => true,
        'message' => 'SKU added to Shared Listings whitelist and resolved!'
    ]);
} catch (Exception $e) {
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

<?php
/**
 * api/lazada/auto_match.php — Automatically match Lazada items to POS products by SKU
 */
header("Content-Type: application/json");
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';

requireLogin();

if (!hasPermission('lazada_sync')) {
    echo json_encode(['success' => false, 'error' => 'Permission denied. Please request access from admin Les.']);
    exit;
}

try {
    $db = new Database();
    $conn = $db->getConnection();

    // 1. Fetch unmapped items. Target NULL status or statuses that aren't finalized.
    $stmt = $conn->prepare("
        SELECT id, lazada_variation_sku, lazada_parent_sku 
        FROM lazada_product_mappings 
        WHERE mapping_status IS NULL OR mapping_status NOT IN ('manual', 'auto', 'mapped')
    ");
    $stmt->execute();
    $unmapped = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Prevent auto-matching of illegal duplicates
    $wlStmt = $conn->query("SELECT sku FROM lazada_duplicate_whitelist");
    $whitelistMap = array_flip($wlStmt->fetchAll(PDO::FETCH_COLUMN));

    $countsStmt = $conn->query("
        SELECT sku, COUNT(*) as cnt FROM (
            SELECT lazada_parent_sku AS sku FROM lazada_product_mappings WHERE has_variation = 0 AND lazada_parent_sku != ''
            UNION ALL
            SELECT lazada_variation_sku AS sku FROM lazada_product_mappings WHERE has_variation = 1 AND lazada_variation_sku != ''
        ) c GROUP BY sku HAVING cnt > 1
    ");
    $duplicateMap = array_flip($countsStmt->fetchAll(PDO::FETCH_COLUMN));

    $matchedCount = 0;
    
    // 2. Prepare statements. Using exact SKU match for index performance.
    $lookupStmt = $conn->prepare("
        SELECT variation_id, sku 
        FROM product_variations 
        WHERE sku = ? AND sku != '' AND sku IS NOT NULL 
        LIMIT 1
    ");

    $updateStmt = $conn->prepare("
        UPDATE lazada_product_mappings 
        SET pos_product_id = ?, 
            matched_pos_sku = ?, 
            mapping_status = 'auto', 
            updated_at = NOW() 
        WHERE id = ?
    ");

    foreach ($unmapped as $row) {
        // Resolve which SKU to use. Priority: Variation SKU -> Parent SKU.
        // Using strlen check to ensure SKUs like "0" are not treated as empty.
        $skuToMatch = (isset($row['lazada_variation_sku']) && strlen(trim((string)$row['lazada_variation_sku'])) > 0) 
            ? trim((string)$row['lazada_variation_sku']) 
            : trim((string)$row['lazada_parent_sku'] ?? '');

        if ($skuToMatch === '') continue;

        // Skip if this SKU is duplicated across multiple Lazada items AND not explicitly whitelisted
        if (isset($duplicateMap[$skuToMatch]) && !isset($whitelistMap[$skuToMatch])) {
            continue;
        }

        $lookupStmt->execute([$skuToMatch]);
        $posVar = $lookupStmt->fetch(PDO::FETCH_ASSOC);

        if ($posVar) {
            $updateStmt->execute([$posVar['variation_id'], $posVar['sku'], $row['id']]);
            $matchedCount++;
        }
    }

    // Log the summary of the auto-match process
    $summaryMessage = "Successfully auto-matched {$matchedCount} variation(s) by SKU.";
    $logStmt = $conn->prepare("
        INSERT INTO lazada_sync_logs (event_type, lazada_item_id, product_name, sku, old_value, new_value, source, status, created_by, created_at)
        VALUES ('mapping', NULL, 'Bulk Auto-Match', '—', 'Unmapped items', ?, 'Auto-Match (Direct API)', 'success', ?, NOW())
    ");
    $logStmt->execute([
        $summaryMessage,
        $_SESSION['user_id'] ?? null
    ]);

    echo json_encode([
        'success' => true, 
        'message' => "Auto-match completed. $matchedCount items were successfully linked to POS variations.",
        'matched_count' => $matchedCount
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

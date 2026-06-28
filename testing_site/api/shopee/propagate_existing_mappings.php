<?php
/**
 * api/shopee/propagate_existing_mappings.php
 * One-off script to propagate all existing mapped product stocks to the POS inventory
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/sync_helpers.php';

// Only allow execution via CLI or Admin Les session
if (php_sapi_name() !== 'cli') {
    require_once __DIR__ . '/../../includes/auth.php';
    requireLogin();
    if (!hasPermission('shopee_sync')) {
        die("Permission denied.");
    }
}

try {
    $db = new Database();
    $conn = $db->getConnection();

    echo "=== Fetching all mapped products/variations ===\n";
    $stmt = $conn->query("
        SELECT id, shopee_item_id, shopee_model_id, shopee_product_name, shopee_variation_name, 
               shopee_variation_sku, shopee_parent_sku, shopee_stock, shopee_price, pos_product_id, mapping_status
        FROM shopee_product_mappings
        WHERE mapping_status IN ('auto', 'manual') AND pos_product_id IS NOT NULL AND pos_product_id != 0
    ");
    $mappings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $total = count($mappings);
    echo "Found {$total} mapped records to propagate.\n\n";

    $successCount = 0;
    foreach ($mappings as $index => $row) {
        $posProductId = (int)$row['pos_product_id'];
        $shopeeStock = (int)$row['shopee_stock'];

        $prodName = $row['shopee_product_name'];
        if (!empty($row['shopee_variation_name'])) {
            $prodName .= ' — ' . $row['shopee_variation_name'];
        }
        $sku = $row['shopee_variation_sku'] ?: $row['shopee_parent_sku'] ?: '—';

        // Propagate stock to inventory (store_id = 2)
        propagateStockToPos($conn, $posProductId, $shopeeStock, $prodName, $sku, 1, (int)$row['id']);

        $successCount++;
        if (($index + 1) % 50 === 0 || ($index + 1) === $total) {
            echo "Processed " . ($index + 1) . "/{$total} records...\n";
        }
    }

    echo "\n=== Success: Propagated {$successCount} records successfully to POS tables! ===\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

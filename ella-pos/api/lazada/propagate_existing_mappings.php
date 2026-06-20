<?php
/**
 * api/lazada/propagate_existing_mappings.php
 * One-off script to propagate all existing mapped product stocks to the POS inventory
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/sync_helpers.php';

// Only allow execution via CLI or Admin Les session
if (php_sapi_name() !== 'cli') {
    require_once __DIR__ . '/../../includes/auth.php';
    requireLogin();
    if (!hasPermission('lazada_sync')) {
        die("Permission denied.");
    }
}

try {
    $db = new Database();
    $conn = $db->getConnection();

    echo "=== Fetching all mapped products/variations ===\n";
    $stmt = $conn->query("
        SELECT id, lazada_item_id, lazada_model_id, lazada_product_name, lazada_variation_name, 
               lazada_variation_sku, lazada_parent_sku, lazada_stock, lazada_price, pos_product_id, mapping_status
        FROM lazada_product_mappings
        WHERE mapping_status IN ('auto', 'manual') AND pos_product_id IS NOT NULL AND pos_product_id != 0
    ");
    $mappings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $total = count($mappings);
    echo "Found {$total} mapped records to propagate.\n\n";

    $successCount = 0;
    foreach ($mappings as $index => $row) {
        $posProductId = (int)$row['pos_product_id'];
        $lazadaStock = (int)$row['lazada_stock'];

        $prodName = $row['lazada_product_name'];
        if (!empty($row['lazada_variation_name'])) {
            $prodName .= ' — ' . $row['lazada_variation_name'];
        }
        $sku = $row['lazada_variation_sku'] ?: $row['lazada_parent_sku'] ?: '—';

        // Propagate stock to inventory (store_id = 2)
        propagateStockToPos($conn, $posProductId, $lazadaStock, $prodName, $sku, 1, (int)$row['id']);

        $successCount++;
        if (($index + 1) % 50 === 0 || ($index + 1) === $total) {
            echo "Processed " . ($index + 1) . "/{$total} records...\n";
        }
    }

    echo "\n=== Success: Propagated {$successCount} records successfully to POS tables! ===\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

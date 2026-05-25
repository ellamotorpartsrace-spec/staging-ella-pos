<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

try {
    $db = new Database();
    $conn = $db->getConnection();

    // 1. Total duplicate rows (variations) where mapping_status = 'duplicate'
    $stmt = $conn->query("SELECT COUNT(*) FROM shopee_product_mappings WHERE mapping_status = 'duplicate'");
    $duplicateRows = $stmt->fetchColumn();
    echo "Duplicate variations in shopee_product_mappings: " . $duplicateRows . "\n";

    // 2. What are the unique SKUs that are marked as duplicate?
    $stmt = $conn->query("
        SELECT COALESCE(shopee_variation_sku, shopee_parent_sku, '') as sku, COUNT(*) as cnt 
        FROM shopee_product_mappings 
        WHERE mapping_status = 'duplicate'
        GROUP BY sku
    ");
    $uniqueDupes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "Unique duplicate SKUs count: " . count($uniqueDupes) . "\n";

    // Let's print some examples to make it clear for the user:
    echo "Example duplicated SKUs (first 5):\n";
    $i = 0;
    foreach ($uniqueDupes as $u) {
        if ($i++ < 5) {
            echo "  - SKU '" . $u['sku'] . "' is repeated " . $u['cnt'] . " times.\n";
        }
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

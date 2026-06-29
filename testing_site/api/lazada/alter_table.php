<?php
require 'testing_site/config/database.php';
$db = new Database();
$conn = $db->getConnection();
try {
    $conn->exec("ALTER TABLE lazada_product_mappings ADD UNIQUE KEY unique_mapping (platform_name, lazada_item_id, lazada_sku_id);");
    echo "Done adding unique key.\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

<?php
require 'testing_site/config/database.php';
$db = new Database();
$conn = $db->getConnection();
try {
    $conn->prepare("INSERT INTO lazada_sync_logs (platform_name, event_type, product_name, new_value, source, status, created_by, created_at) VALUES ('lazada_main', 'product_import', 'Product Sync (Products Page)', 'test summary', 'Manual Sync (Products Page)', 'success', 1, NOW())")->execute();
    echo "Success!";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}

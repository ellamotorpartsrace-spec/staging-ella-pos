<?php
require 'config/database.php';
$db = new Database();
$conn = $db->getConnection();
try {
    $conn->exec('ALTER TABLE inventory_snapshots ADD COLUMN total_stock_qty INT NOT NULL DEFAULT 0 AFTER total_products');
    echo 'Added column total_stock_qty to inventory_snapshots' . PHP_EOL;
} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage() . PHP_EOL;
}

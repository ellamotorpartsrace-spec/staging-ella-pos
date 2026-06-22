<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';

try {
    $db = new Database();
    $conn = $db->getConnection();

    // Update all historical shopee_sale rows to allocation_adjustment
    $conn->query("UPDATE stock_movements SET type = 'allocation_adjustment' WHERE type = 'shopee_sale'");
    echo "Successfully converted all shopee_sale rows to allocation_adjustment!\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

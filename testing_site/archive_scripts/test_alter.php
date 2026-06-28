<?php
require 'config/database.php';
$db = new Database();
$conn = $db->getConnection();

$sql = "ALTER TABLE stock_movements MODIFY COLUMN type ENUM(
    'shopee_sale',
    'allocation_adjustment',
    'stock_in',
    'stock_out',
    'sales',
    'adjustment',
    'return',
    'allocation_to_online',
    'allocation_to_physical',
    'online_sale',
    'online_adjustment',
    'shopee_balance_sync',
    'lazada_balance_sync'
) NOT NULL";

$conn->exec($sql);
echo "ALTER TABLE SUCCESS\n";

// Also modify inventory table if there's any ENUM there.

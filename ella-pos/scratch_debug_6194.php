<?php
require 'config/database.php';
$db = new Database();
$conn = $db->getConnection();
echo "Inventory:\n";
print_r($conn->query('SELECT * FROM inventory WHERE variation_id=6194')->fetchAll(PDO::FETCH_ASSOC));

echo "\nLast Movement Store 1:\n";
print_r($conn->query('SELECT * FROM stock_movements WHERE variation_id=6194 AND store_id=1 ORDER BY created_at DESC, movement_id DESC LIMIT 1')->fetchAll(PDO::FETCH_ASSOC));

echo "\nLast Movement Store 2:\n";
print_r($conn->query('SELECT * FROM stock_movements WHERE variation_id=6194 AND store_id=2 ORDER BY created_at DESC, movement_id DESC LIMIT 1')->fetchAll(PDO::FETCH_ASSOC));

<?php
require 'config/database.php';
$db = new Database();
$conn = $db->getConnection();
echo "--- PRODUCTS ---\n";
$stmt = $conn->query("SHOW COLUMNS FROM products");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
echo "\n--- INVENTORY ---\n";
$stmt = $conn->query("SHOW COLUMNS FROM inventory");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
echo "\n--- STOCK MOVEMENTS ---\n";
$stmt = $conn->query("SHOW COLUMNS FROM stock_movements");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

<?php
require 'config/database.php';
$db = new Database();
$conn = $db->getConnection();
$stmt = $conn->query('SELECT store_id, quantity FROM inventory WHERE variation_id = 6186');
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
$stmt2 = $conn->query('SELECT movement_id, type, previous_stock, new_stock, created_at, remarks FROM stock_movements WHERE variation_id = 6186 ORDER BY created_at DESC, movement_id DESC LIMIT 5');
print_r($stmt2->fetchAll(PDO::FETCH_ASSOC));

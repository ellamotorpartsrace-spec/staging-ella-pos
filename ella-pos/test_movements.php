<?php
require 'config/database.php';
$db = new Database();
$conn = $db->getConnection();
$stmt = $conn->query("SELECT m.movement_id, m.type, m.quantity, m.previous_stock, m.new_stock, m.created_at FROM stock_movements m JOIN product_variations v ON m.variation_id = v.variation_id WHERE v.sku = 'MB-865' AND m.store_id = 1 ORDER BY m.created_at DESC, m.movement_id DESC LIMIT 15");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

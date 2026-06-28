<?php
require_once "config/database.php";
$db = new Database();
$conn = $db->getConnection();
$stmt = $conn->query("SELECT movement_id, created_at, type, quantity, previous_stock, new_stock, reference, remarks FROM stock_movements WHERE variation_id IN (SELECT variation_id FROM product_variations WHERE variation_name LIKE '%NMAX/AEROX%') ORDER BY movement_id DESC LIMIT 10");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

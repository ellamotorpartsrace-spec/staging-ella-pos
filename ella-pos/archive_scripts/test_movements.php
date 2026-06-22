<?php
require 'config/database.php';
$db = new Database();
$conn = $db->getConnection();
$stmt = $conn->query("SELECT product_name FROM products WHERE product_id = 1410");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

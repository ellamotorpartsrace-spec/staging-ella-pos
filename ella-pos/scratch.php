<?php
require 'config/database.php';
$db = new Database();
$conn = $db->getConnection();
$stmt = $conn->query('SELECT p.product_name, v.variation_name, v.price_retail, v.price_wholesale, v.price_dealer, v.price_capital FROM product_variations v JOIN products p ON v.product_id = p.product_id WHERE v.price_retail = 88 OR v.price_wholesale = 88 OR v.price_dealer = 88 OR v.price_capital = 88 LIMIT 5;');
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

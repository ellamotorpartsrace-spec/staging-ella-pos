<?php
require_once "config/config.php";
require_once "config/database.php";
$db = new Database();
$conn = $db->getConnection();

$stmt = $conn->query("SELECT p.product_name, v.variation_id, i.store_id, i.quantity FROM products p JOIN product_variations v ON p.product_id = v.product_id JOIN inventory i ON v.variation_id = i.variation_id WHERE p.product_name LIKE '%Clutch Shoe Nmax/Aerox/M3/Click%'");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

$stmt = $conn->query("SELECT * FROM shopee_product_mappings WHERE pos_product_id IN (SELECT variation_id FROM products p JOIN product_variations v ON p.product_id = v.product_id WHERE p.product_name LIKE '%Clutch Shoe Nmax/Aerox/M3/Click%')");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

<?php
require_once 'config/config.php';
require_once 'config/database.php';
$db = new Database();
$conn = $db->getConnection();
$stmt = $conn->prepare("SELECT * FROM inventory WHERE variation_id = 6415");
$stmt->execute();
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

require 'testing_site/config/config.php';
$db = new Database();
$conn = $db->getConnection();
$stmt = $conn->query("SELECT variation_id, store_id, quantity FROM inventory WHERE variation_id = (SELECT variation_id FROM product_variations WHERE sku = 'BW-036')");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

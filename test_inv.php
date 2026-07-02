<?php
require 'testing_site/config/config.php';
$db = new Database();
$conn = $db->getConnection();
$stmt = $conn->query("SELECT variation_id, store_id, quantity FROM inventory WHERE variation_id = (SELECT variation_id FROM product_variations WHERE sku = 'BW-036')");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

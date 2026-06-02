<?php
require_once 'config/config.php';
require_once 'config/database.php';
$db = new Database();
$conn = $db->getConnection();

echo "Inventory for variation 399:\n";
$stmt = $conn->prepare("SELECT * FROM inventory WHERE variation_id = 399");
$stmt->execute();
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

echo "\nShopee mappings for pos_product_id 399 or matched_pos_sku matches its SKU:\n";
$stmt = $conn->prepare("SELECT sku FROM product_variations WHERE variation_id = 399");
$stmt->execute();
$sku = $stmt->fetchColumn();

$stmt2 = $conn->prepare("SELECT * FROM shopee_product_mappings WHERE pos_product_id = 399 OR matched_pos_sku = ?");
$stmt2->execute([$sku]);
print_r($stmt2->fetchAll(PDO::FETCH_ASSOC));

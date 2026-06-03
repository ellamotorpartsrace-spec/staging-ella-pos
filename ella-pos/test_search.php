<?php
require_once 'config/config.php';
require_once 'config/database.php';

$db = new Database();
$conn = $db->getConnection();

$stmt = $conn->prepare("SELECT COUNT(*) FROM products");
$stmt->execute();
$count = $stmt->fetchColumn();

echo "Total products: $count\n";

$stmt2 = $conn->prepare("SELECT COUNT(*) FROM product_variations");
$stmt2->execute();
$count2 = $stmt2->fetchColumn();

echo "Total variations: $count2\n";

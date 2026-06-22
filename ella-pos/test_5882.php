<?php
require 'config/database.php';
$db = new Database();
$conn = $db->getConnection();

echo "--- SEARCH BY SKU ---\n";
$stmt = $conn->prepare("SELECT * FROM product_variations WHERE sku LIKE ? OR variation_name LIKE ?");
$stmt->execute(['%750769%', '%100/80-14 PILOT MOTO GP%']);
$variations = $stmt->fetchAll(PDO::FETCH_ASSOC);
print_r($variations);

foreach ($variations as $var) {
    $v_id = $var['variation_id'];
    echo "\n--- INVENTORY FOR variation_id = $v_id ---\n";
    $stmt = $conn->prepare("SELECT * FROM inventory WHERE variation_id = ?");
    $stmt->execute([$v_id]);
    print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

    echo "\n--- STOCK MOVEMENTS FOR variation_id = $v_id ---\n";
    $stmt = $conn->prepare("SELECT * FROM stock_movements WHERE variation_id = ? ORDER BY created_at DESC");
    $stmt->execute([$v_id]);
    print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
}

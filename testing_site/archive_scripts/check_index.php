<?php
require 'config/database.php';
$db = new Database(); $conn = $db->getConnection();
$stmt = $conn->query('SHOW INDEX FROM shopee_product_mappings');
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

$stmt = $conn->query('SHOW INDEX FROM product_variations');
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

$stmt = $conn->query('SHOW INDEX FROM inventory');
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
?>

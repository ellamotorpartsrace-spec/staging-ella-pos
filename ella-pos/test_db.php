<?php
require 'config/database.php';
$db = new Database();
$conn = $db->getConnection();
$stmt = $conn->query('DESCRIBE shopee_product_mappings');
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

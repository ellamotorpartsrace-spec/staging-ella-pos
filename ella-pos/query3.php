<?php
require_once 'config/config.php';
require_once 'config/database.php';
$db = new Database();
$conn = $db->getConnection();
$sql = "SELECT * FROM product_units WHERE variation_id = 399";
$stmt = $conn->prepare($sql);
$stmt->execute();
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

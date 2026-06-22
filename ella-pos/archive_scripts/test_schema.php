<?php
require 'config/database.php';
$db = new Database();
$conn = $db->getConnection();
$stmt = $conn->query("SHOW COLUMNS FROM stock_movements");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

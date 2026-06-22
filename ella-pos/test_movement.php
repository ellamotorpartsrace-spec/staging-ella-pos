<?php
require 'config/database.php';
$db = new Database();
$conn = $db->getConnection();
$stmt = $conn->query("SELECT COUNT(*), SUM(quantity) FROM stock_movements WHERE variation_id = 6192 AND store_id = 2 AND type = 'online_adjustment'");
print_r($stmt->fetch(PDO::FETCH_ASSOC));

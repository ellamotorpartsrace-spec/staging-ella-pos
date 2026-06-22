<?php
$root = __DIR__;
require_once $root . '/config/config.php';
require_once $root . '/config/database.php';

$db   = new Database();
$conn = $db->getConnection();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$stmt2 = $conn->query("SELECT * FROM stock_movements WHERE variation_id = 327 ORDER BY movement_id DESC LIMIT 10");
print_r($stmt2->fetchAll(PDO::FETCH_ASSOC));

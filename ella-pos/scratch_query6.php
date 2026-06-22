<?php
$root = __DIR__;
require_once $root . '/config/config.php';
require_once $root . '/config/database.php';

$db   = new Database();
$conn = $db->getConnection();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$stmt = $conn->query("SELECT movement_id, type, quantity FROM stock_movements WHERE type = 'adjustment' LIMIT 10");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

<?php
$root = __DIR__;
require_once $root . '/config/config.php';
require_once $root . '/config/database.php';

$db   = new Database();
$conn = $db->getConnection();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$stmt = $conn->query("SELECT count(*) FROM stock_movements WHERE type LIKE 'allocation%'");
print_r($stmt->fetchColumn());

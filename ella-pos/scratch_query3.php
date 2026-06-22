<?php
$root = __DIR__;
require_once $root . '/config/config.php';
require_once $root . '/config/database.php';

$db   = new Database();
$conn = $db->getConnection();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$stmt = $conn->query("SELECT movement_id, variation_id, store_id, type, quantity, remarks FROM stock_movements WHERE type IN ('allocation_to_online', 'allocation_to_physical') ORDER BY movement_id DESC LIMIT 10");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

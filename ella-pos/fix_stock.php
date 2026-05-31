<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
$db = new Database();
$conn = $db->getConnection();
$stmt = $conn->prepare("UPDATE stock_movements SET previous_stock = ?, new_stock = ? WHERE type = 'stock_in' AND previous_stock = -465 AND new_stock = -383 AND quantity = 82");
$stmt->execute([0, 82]);
echo "Fixed " . $stmt->rowCount() . " rows";

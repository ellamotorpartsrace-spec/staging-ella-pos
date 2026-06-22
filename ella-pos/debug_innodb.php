<?php
require_once 'config/database.php';
$db = new Database();
$conn = $db->getConnection();

// Show active transactions
$stmt = $conn->query('SELECT * FROM information_schema.innodb_trx');
$txns = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Show processlist
$stmt2 = $conn->query('SHOW FULL PROCESSLIST');
$processes = $stmt2->fetchAll(PDO::FETCH_ASSOC);

header('Content-Type: application/json');
echo json_encode(['transactions' => $txns, 'processlist' => $processes], JSON_PRETTY_PRINT);
?>

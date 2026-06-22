<?php
require_once __DIR__ . '/config/database.php';
$db = new Database();
$conn = $db->getConnection();

// Get active transactions
$stmt = $conn->query('SELECT trx_mysql_thread_id FROM information_schema.innodb_trx WHERE trx_state = "ACTIVE"');
$txns = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($txns as $trx) {
    try {
        $conn->exec('KILL ' . (int)$trx['trx_mysql_thread_id']);
        echo "Killed thread " . $trx['trx_mysql_thread_id'] . "\n";
    } catch (Exception $e) {
        echo "Could not kill " . $trx['trx_mysql_thread_id'] . "\n";
    }
}
echo "Emergency unlock complete.";
?>

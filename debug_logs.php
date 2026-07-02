<?php
require __DIR__ . '/testing_site/config/database.php';
$db = new Database();
$pdo = $db->getConnection();
print_r($pdo->query("SELECT * FROM product_sync_logs WHERE event_type='Mapping' ORDER BY id DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC));

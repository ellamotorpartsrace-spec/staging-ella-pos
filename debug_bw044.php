<?php
require __DIR__ . '/testing_site/config/database.php';
$db = new Database();
$pdo = $db->getConnection();

$stmt=$pdo->query("SELECT * FROM lazada_product_mappings WHERE lazada_item_id = '15444409803'");
echo "\nLazada Mappings for 15444409803:\n";
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

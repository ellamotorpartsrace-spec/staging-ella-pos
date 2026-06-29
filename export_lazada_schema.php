<?php
require 'testing_site/config/database.php';
$db = new Database();
$conn = $db->getConnection();

$tables = $conn->query("SHOW TABLES LIKE 'lazada_%'")->fetchAll(PDO::FETCH_COLUMN);

$sql = "";
foreach ($tables as $table) {
    $create = $conn->query("SHOW CREATE TABLE $table")->fetch(PDO::FETCH_ASSOC);
    $sql .= $create['Create Table'] . ";\n\n";
}

file_put_contents('lazada_schema.sql', $sql);
echo "Exported schema.";

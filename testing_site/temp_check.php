<?php
require 'config/database.php';
$db = new Database();
$conn = $db->getConnection();
$stmt = $conn->query("SELECT app_key, environment FROM lazada_config");
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));

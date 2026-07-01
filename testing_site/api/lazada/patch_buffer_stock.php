<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
$db = new Database();
$conn = $db->getConnection();
try {
    $conn->exec('ALTER TABLE lazada_config ADD COLUMN buffer_stock INT DEFAULT 0;');
    echo "Success";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}

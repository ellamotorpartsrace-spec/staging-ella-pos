<?php
require 'config/database.php';
$db = new Database(); $conn = $db->getConnection();
try {
    $conn->exec('ALTER TABLE shopee_product_mappings ADD INDEX idx_pos_product_id (pos_product_id)');
    echo 'Index added successfully.';
} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage();
}
?>

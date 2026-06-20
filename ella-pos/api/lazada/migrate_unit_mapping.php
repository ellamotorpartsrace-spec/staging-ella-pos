<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
try {
    $db = new Database();
    $conn = $db->getConnection();
    $conn->exec("ALTER TABLE lazada_product_mappings ADD COLUMN pos_unit_id INT DEFAULT NULL AFTER pos_product_id");
    echo "Migration successful";
} catch (Exception $e) {
    if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
        echo "Column already exists";
    } else {
        echo "Error: " . $e->getMessage();
    }
}

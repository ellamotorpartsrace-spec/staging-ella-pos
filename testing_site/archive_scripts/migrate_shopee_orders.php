<?php
require_once 'config/database.php';
$db = new Database();
$c = $db->getConnection();
try {
    $c->exec("ALTER TABLE shopee_orders ADD COLUMN inventory_deducted TINYINT(1) NOT NULL DEFAULT 0");
    echo "Column added successfully.";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
        echo "Column already exists.";
    } else {
        echo "Error: " . $e->getMessage();
    }
}

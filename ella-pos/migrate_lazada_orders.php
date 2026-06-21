<?php
require 'config/database.php';
$db = new Database();
$conn = $db->getConnection();

try {
    $conn->exec("ALTER TABLE lazada_orders ADD COLUMN inventory_deducted TINYINT(1) NOT NULL DEFAULT 0");
    echo "Successfully added inventory_deducted to lazada_orders\n";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
        echo "Column inventory_deducted already exists.\n";
    } else {
        echo "Error: " . $e->getMessage() . "\n";
    }
}

<?php
require 'config/database.php';
$db = new Database();
$conn = $db->getConnection();

try {
    $conn->exec("ALTER TABLE lazada_product_mappings ADD COLUMN sync_status ENUM('active','inactive') DEFAULT 'active'");
    echo "Added sync_status successfully.\n";
} catch (PDOException $e) {
    echo "Error adding sync_status: " . $e->getMessage() . "\n";
}

try {
    $conn->exec("ALTER TABLE lazada_product_mappings MODIFY COLUMN mapping_status ENUM('unmapped', 'auto', 'manual', 'mapped') DEFAULT 'unmapped'");
    echo "Updated mapping_status successfully.\n";
} catch (PDOException $e) {
    echo "Error updating mapping_status: " . $e->getMessage() . "\n";
}

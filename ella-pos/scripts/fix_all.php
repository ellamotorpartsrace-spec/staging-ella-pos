<?php
require_once '../config/config.php';
require_once '../config/database.php';

try {
    $db = new Database();
    $conn = $db->getConnection();
    
    // 1. Delete all the bad 'Stock Repair' and 'System Restore' rows
    $stmt = $conn->query("
        DELETE FROM stock_movements 
        WHERE type = 'adjustment' 
          AND (remarks LIKE 'System Restore%' OR remarks LIKE 'Stock Repair%')
    ");
    
    echo "Deleted bad rows.\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}

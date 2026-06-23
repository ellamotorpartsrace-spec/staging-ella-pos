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
    
    // 2. Fix the bug where older Shopee sales were wrongfully deducted from POS (store_id = 1)
    // Moving them to store_id = 2 will cause sync_inventory to safely ignore them for the POS sum!
    $stmt2 = $conn->query("
        UPDATE stock_movements
        SET store_id = 2
        WHERE type IN ('online_sale', 'online_adjustment') AND store_id = 1
    ");

    echo "Deleted bad rows and fixed misattributed online sales.\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}

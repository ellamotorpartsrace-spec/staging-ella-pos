<?php
require_once 'config/config.php';
require_once 'config/database.php';

try {
    $db = new Database();
    $conn = $db->getConnection();
    
    $stmt = $conn->query("
        SELECT movement_id, type, previous_stock, new_stock, (new_stock - previous_stock) as delta, store_id, created_at 
        FROM stock_movements 
        WHERE variation_id = 6192
        ORDER BY movement_id ASC
    ");
    print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}

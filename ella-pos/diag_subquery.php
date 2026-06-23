<?php
require_once 'config/database.php';
try {
    $db = new Database();
    $conn = $db->getConnection();
    $stmt = $conn->query("
        SELECT movement_id, type, previous_stock, new_stock, created_at 
        FROM stock_movements 
        WHERE variation_id = 4 AND store_id = 1 
        ORDER BY created_at DESC, movement_id DESC 
        LIMIT 5
    ");
    print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
} catch(Exception $e) { echo $e->getMessage(); }

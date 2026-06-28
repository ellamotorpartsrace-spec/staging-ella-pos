<?php
require_once 'config/database.php';
try {
    $db = new Database();
    $conn = $db->getConnection();
    
    $stmt = $conn->query("
        SELECT 
            v.variation_id,
            (
                SELECT new_stock 
                FROM stock_movements sm 
                WHERE sm.variation_id = v.variation_id 
                  AND sm.store_id = 1 
                  AND COALESCE(sm.status, '') <> 'voided'
                ORDER BY created_at DESC, movement_id DESC 
                LIMIT 1
            ) AS base_stock
        FROM product_variations v
        WHERE v.variation_id IN (4, 6192)
    ");
    echo "SUBQUERY LIMIT 1 RETURNS:\n";
    print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

    $stmt2 = $conn->query("
        SELECT movement_id, type, previous_stock, new_stock, created_at, remarks 
        FROM stock_movements 
        WHERE variation_id = 6192 AND store_id = 1
        ORDER BY created_at DESC, movement_id DESC 
        LIMIT 5
    ");
    echo "\n\nLATEST 5 ROWS FOR 6192 IN STORE 1:\n";
    print_r($stmt2->fetchAll(PDO::FETCH_ASSOC));

    $stmt3 = $conn->query("
        SELECT * FROM inventory 
        WHERE variation_id = 6192
    ");
    echo "\n\nINVENTORY TABLE FOR 6192:\n";
    print_r($stmt3->fetchAll(PDO::FETCH_ASSOC));

    require_once 'api/inventory/sync_inventory.php';
    // buildSyncReport is now loaded
    // we want to run buildSyncReport and find the row for 6192
    echo "\n\nSYNC INVENTORY REPORT FOR 6192:\n";
    $report = buildSyncReport($conn);
    foreach ($report['rows'] as $r) {
        if ($r['variation_id'] == 6192) {
            print_r($r);
            break;
        }
    }

} catch(Exception $e) { echo "ERROR: " . $e->getMessage(); }

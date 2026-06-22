<?php
$root = __DIR__;
require_once $root . '/config/config.php';
require_once $root . '/config/database.php';

$db   = new Database();
$conn = $db->getConnection();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$discrepancyQuery = "
    SELECT 
        i.variation_id, 
        i.store_id, 
        i.quantity AS inventory_qty,
        COALESCE(
            (SELECT new_stock 
             FROM stock_movements sm 
             WHERE sm.variation_id = i.variation_id AND sm.store_id = i.store_id AND sm.status = 'active'
             ORDER BY sm.created_at DESC, sm.movement_id DESC LIMIT 1), 0
        ) AS latest_movement_stock
    FROM inventory i
    HAVING inventory_qty != latest_movement_stock
";
$stmt = $conn->query($discrepancyQuery);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
print_r($rows);

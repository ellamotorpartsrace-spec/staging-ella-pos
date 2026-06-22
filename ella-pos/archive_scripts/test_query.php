<?php
require 'config/database.php';
$db = new Database(); $conn = $db->getConnection();
$lastMovStmt = $conn->query("
        SELECT
            i.variation_id,
            i.quantity AS current_qty,
            last_real.last_new_stock
        FROM inventory i
        INNER JOIN (
            SELECT sm.variation_id, sm.new_stock AS last_new_stock
            FROM stock_movements sm
            INNER JOIN (
                SELECT variation_id, MAX(movement_id) AS max_id
                FROM stock_movements
                WHERE store_id = 1
                  AND reference NOT LIKE 'SYS-%'
                  AND type IN ('stock_in','stock_out','sales','adjustment','return',
                               'allocation_to_online','allocation_to_physical','shopee_balance_sync')
                GROUP BY variation_id
            ) latest ON latest.variation_id = sm.variation_id AND latest.max_id = sm.movement_id
        ) last_real ON last_real.variation_id = i.variation_id
        WHERE i.store_id = 1
          AND ABS(i.quantity - last_real.last_new_stock) > 0
        ORDER BY ABS(i.quantity - last_real.last_new_stock) DESC
    ");
$res = $lastMovStmt->fetchAll(PDO::FETCH_ASSOC);
print_r($res);

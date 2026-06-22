<?php
// Script to wipe out duplicate Shopee/Lazada ghost restocks and rebuild store 2 & 3
require_once 'config/database.php';

try {
    $db = new Database();
    $conn = $db->getConnection();
    
    // Start transaction for safety
    $conn->beginTransaction();

    echo "Starting Ghost Stock Cleanup...<br>\n";

    // 1. Delete all the duplicate online_adjustments caused by the old API polling bug
    // These were inserted into store_id 2 and 3 with these specific remarks
    $delStmt = $conn->prepare("DELETE FROM stock_movements WHERE type = 'online_adjustment' AND (remarks LIKE 'Auto-restocked (Shopee%' OR remarks LIKE 'Auto-restocked (Lazada%')");
    $delStmt->execute();
    $deleted = $delStmt->rowCount();
    echo "Deleted $deleted ghost 'online_adjustment' records from the online stores.<br>\n";

    // 2. Rebuild the stock chain ONLY for store_id 2 and 3 (to reset their totals to the true allocated amounts)
    $variations = $conn->query("SELECT DISTINCT variation_id, store_id FROM stock_movements WHERE store_id IN (2, 3)")->fetchAll(PDO::FETCH_ASSOC);
    $total = count($variations);
    $count = 0;

    echo "Rebuilding online store chains...<br>\n";

    foreach ($variations as $var) {
        $vId = $var['variation_id'];
        $sId = $var['store_id'];

        $movements = $conn->prepare("SELECT * FROM stock_movements WHERE variation_id=? AND store_id=? ORDER BY created_at ASC, movement_id ASC");
        $movements->execute([$vId, $sId]);

        $running_balance = 0;

        foreach ($movements->fetchAll(PDO::FETCH_ASSOC) as $mov) {
            $delta = 0;
            $qty = (float)$mov['quantity'];
            
            if (in_array($mov['type'], ['initial', 'restock', 'return', 'transfer_in', 'online_adjustment', 'allocation_to_physical', 'allocation_to_online', 'stock_in'])) {
                $delta = abs($qty);
            } elseif (in_array($mov['type'], ['sale', 'sales', 'damage', 'loss', 'transfer_out', 'online_sale', 'stock_out', 'shopee_sale'])) {
                $delta = -abs($qty);
            } elseif (in_array($mov['type'], ['adjustment', 'allocation_adjustment', 'shopee_balance_sync', 'lazada_balance_sync'])) {
                $delta = $qty; 
            }

            $prev = $running_balance;
            $running_balance += $delta;

            if ($running_balance < 0) {
                $running_balance = 0; 
            }

            $conn->prepare("UPDATE stock_movements SET previous_stock=?, new_stock=? WHERE movement_id=?")->execute([$prev, $running_balance, $mov['movement_id']]);
        }

        // Update the cached inventory for store 2 or 3
        $invCheck = $conn->prepare("SELECT inventory_id FROM inventory WHERE variation_id=? AND store_id=?");
        $invCheck->execute([$vId, $sId]);
        if ($invCheck->rowCount() > 0) {
            $conn->prepare("UPDATE inventory SET quantity=? WHERE variation_id=? AND store_id=?")->execute([$running_balance, $vId, $sId]);
        } else {
            $conn->prepare("INSERT INTO inventory (variation_id, store_id, quantity) VALUES (?, ?, ?)")->execute([$vId, $sId, $running_balance]);
        }
        
        $count++;
    }

    $conn->commit();
    echo "Cleanup Complete! The online ghost stocks have been purged and totals reset. Refresh your page!<br>\n";

} catch (Exception $e) {
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    echo "ERROR: " . $e->getMessage() . "<br>\n";
}

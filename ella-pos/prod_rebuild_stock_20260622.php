<?php
// Script to rebuild stock chains and fix negative stocks for all items
require_once 'config/database.php';

try {
    $db = new Database();
    $conn = $db->getConnection();
    
    // Start transaction for safety
    $conn->beginTransaction();

    echo "Starting Stock Rebuild Process...\n";

    // 1. Remove previously added artificial gapfills
    $delStmt = $conn->prepare("DELETE FROM stock_movements WHERE reference LIKE '%GAPFILL%' OR remarks LIKE '%SYS-GAPFILL%'");
    $delStmt->execute();
    echo "Deleted " . $delStmt->rowCount() . " artificial GAPFILL records.<br>\n";

    // 2. Rebuild the stock chain
    $variations = $conn->query("SELECT DISTINCT variation_id, store_id FROM stock_movements")->fetchAll(PDO::FETCH_ASSOC);
    $total = count($variations);
    $count = 0;
    $fixed = 0;

    foreach ($variations as $var) {
        $vId = $var['variation_id'];
        $sId = $var['store_id'];

        $movements = $conn->prepare("SELECT * FROM stock_movements WHERE variation_id=? AND store_id=? ORDER BY created_at ASC, movement_id ASC");
        $movements->execute([$vId, $sId]);

        $running_balance = 0;

        foreach ($movements->fetchAll(PDO::FETCH_ASSOC) as $mov) {
            $delta = 0;
            // standard movement types
            $qty = (float)$mov['quantity'];
            if (in_array($mov['type'], ['initial', 'restock', 'return', 'transfer_in', 'online_adjustment', 'allocation_to_physical', 'allocation_to_online', 'stock_in'])) {
                $delta = abs($qty);
            } elseif (in_array($mov['type'], ['sale', 'sales', 'damage', 'loss', 'transfer_out', 'online_sale', 'stock_out', 'shopee_sale'])) {
                $delta = -abs($qty);
            } elseif (in_array($mov['type'], ['adjustment', 'allocation_adjustment', 'shopee_balance_sync', 'lazada_balance_sync'])) {
                // Adjustments usually carry their sign natively, or we just trust 'quantity' as the delta
                $delta = $qty; 
            }

            $prev = $running_balance;
            $running_balance += $delta;

            // The core fix: enforce non-negative floor
            if ($running_balance < 0) {
                $running_balance = 0; 
                $fixed++;
            }

            // Update the movement record with corrected previous_stock and new_stock
            $conn->prepare("UPDATE stock_movements SET previous_stock=?, new_stock=? WHERE movement_id=?")->execute([$prev, $running_balance, $mov['movement_id']]);
        }

        // Check if inventory record exists
        $invCheck = $conn->prepare("SELECT inventory_id FROM inventory WHERE variation_id=? AND store_id=?");
        $invCheck->execute([$vId, $sId]);
        if ($invCheck->rowCount() > 0) {
            // Update the cached inventory
            $conn->prepare("UPDATE inventory SET quantity=? WHERE variation_id=? AND store_id=?")->execute([$running_balance, $vId, $sId]);
        } else {
            // Insert new inventory record if it was completely missing
            $conn->prepare("INSERT INTO inventory (variation_id, store_id, quantity) VALUES (?, ?, ?)")->execute([$vId, $sId, $running_balance]);
        }
        
        $count++;
        if ($count % 100 == 0) {
            echo "Processed $count / $total pairs...<br>\n";
        }
    }

    $conn->commit();
    echo "Stock Rebuild Process Complete! Processed $count variation-store pairs. Corrected $fixed negative balance steps.<br>\n";

} catch (Exception $e) {
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    echo "ERROR: " . $e->getMessage() . "<br>\n";
}

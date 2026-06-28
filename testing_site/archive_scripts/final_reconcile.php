<?php
/**
 * Final reconciliation:
 * 1. Log the missing movement records for the 13 products where our Shopee fix
 *    restored store_id=1 stock without a movement record.
 * 2. Correct the store_id=1 for products that are UNDER what movements say.
 * 3. Skip products with 200 stock since their movements are valid (stock_in=200).
 */
require_once 'config/config.php';
require_once 'config/database.php';

$db = new Database();
$conn = $db->getConnection();

echo "=== Final Stock Reconciliation ===\n\n";

// Get all discrepancies
$stmt = $conn->query("
    SELECT 
        sm.variation_id,
        COALESCE(SUM(sm.new_stock - sm.previous_stock), 0) AS movement_total,
        i.quantity AS current_qty,
        (i.quantity - COALESCE(SUM(sm.new_stock - sm.previous_stock), 0)) AS discrepancy,
        p.product_name,
        v.variation_name
    FROM inventory i
    JOIN product_variations v ON v.variation_id = i.variation_id
    JOIN products p ON p.product_id = v.product_id
    LEFT JOIN stock_movements sm ON sm.variation_id = i.variation_id 
        AND sm.store_id = 1
        AND sm.type IN ('stock_in','stock_out','sales','adjustment','return','allocation_to_online','allocation_to_physical','shopee_balance_sync')
    WHERE i.store_id = 1
    GROUP BY sm.variation_id, i.quantity, p.product_name, v.variation_name, i.variation_id
    HAVING ABS(i.quantity - COALESCE(SUM(sm.new_stock - sm.previous_stock), 0)) > 0
    ORDER BY discrepancy DESC
");
$discrepancies = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Found " . count($discrepancies) . " products with discrepancy.\n\n";

// Shopee fix correction IDs (the ones we restored in the previous fix session)
$shopeeFixedVarIds = [1590, 1592, 1591, 2171, 2347, 1717, 1428, 1721, 1748, 1727, 1582, 1583, 1587];

$conn->beginTransaction();

try {
    foreach ($discrepancies as $row) {
        $varId = (int)$row['variation_id'];
        $movementSays = (int)$row['movement_total'];
        $actual = (int)$row['current_qty'];
        $diff = (int)$row['discrepancy']; // actual - movementSays (positive = over-counted)
        $name = $row['product_name'] . ' - ' . $row['variation_name'];
        
        echo "VAR #{$varId}: {$name}\n";
        echo "  Movements say: {$movementSays}, Actual: {$actual}, Diff: {$diff}\n";
        
        if (in_array($varId, $shopeeFixedVarIds)) {
            // This discrepancy was introduced by our Shopee fix restoring stock.
            // The inventory is NOW correct (matches what it should be after undoing wrong deductions).
            // We need to LOG a corrective movement so movements match the inventory.
            
            // Get current stock for movement chain
            $prevStockForLog = $movementSays; // what movements currently say
            $newStockForLog = $actual;         // what inventory actually has
            $qtyForLog = $diff;               // the difference
            
            $type = $diff > 0 ? 'adjustment' : 'adjustment';
            $remarks = 'Shopee fix correction: restored wrongly deducted POS stock (ref: fix_shopee_store_id 2026-06-22)';
            
            $conn->prepare("
                INSERT INTO stock_movements 
                (variation_id, store_id, type, quantity, previous_stock, new_stock, reference, remarks, created_by, created_at)
                VALUES (?, 1, 'adjustment', ?, ?, ?, 'SYS-SHOPEE-FIX', ?, 1, NOW())
            ")->execute([$varId, $qtyForLog, $prevStockForLog, $newStockForLog, $remarks]);
            
            echo "  → Logged corrective movement (+{$diff}) to match inventory\n";
            
        } elseif ($diff < 0) {
            // Inventory is LOWER than what movements say — correct inventory upward to match movements
            echo "  → Inventory too LOW. Correcting from {$actual} to {$movementSays}...\n";
            $conn->prepare("UPDATE inventory SET quantity = ? WHERE variation_id = ? AND store_id = 1")
                 ->execute([$movementSays, $varId]);
            echo "  → Corrected!\n";
        } else {
            // Inventory is HIGHER than movements (not one of our fix IDs) — unknown cause
            echo "  → Unknown over-count. Skipping to avoid data loss.\n";
        }
        echo "\n";
    }
    
    $conn->commit();
    echo "\n=== SUCCESS: Reconciliation complete ===\n";
    
    // Final verification
    $finalCheck = $conn->query("
        SELECT COUNT(*) 
        FROM inventory i
        JOIN (
            SELECT variation_id, COALESCE(SUM(new_stock - previous_stock), 0) AS movement_total
            FROM stock_movements
            WHERE store_id = 1 AND type IN ('stock_in','stock_out','sales','adjustment','return','allocation_to_online','allocation_to_physical','shopee_balance_sync')
            GROUP BY variation_id
        ) sm ON sm.variation_id = i.variation_id
        WHERE i.store_id = 1 AND ABS(i.quantity - sm.movement_total) > 0
    ")->fetchColumn();
    echo "Remaining discrepancies after fix: $finalCheck\n";
    
} catch (Exception $e) {
    $conn->rollBack();
    echo "ERROR: " . $e->getMessage() . "\n";
}

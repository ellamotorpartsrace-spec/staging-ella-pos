<?php
/**
 * Fix script: Migrate wrongly-stored online_sale movements from store_id=1 to store_id=2
 * and correct the inventory table accordingly.
 * 
 * The bug: sync_orders.php was using store_id=1 for online_sale movements instead of store_id=2.
 * This caused POS physical inventory to be deducted by Shopee orders AND movement logs to be wrong.
 */
require_once 'config/config.php';
require_once 'config/database.php';

$db = new Database();
$conn = $db->getConnection();

echo "=== Shopee Stock Corruption Fix ===\n\n";

// 1. Find all online_sale movements on store_id=1 (the wrong store)
$stmt = $conn->query("
    SELECT movement_id, variation_id, quantity, previous_stock, new_stock, reference, created_at
    FROM stock_movements 
    WHERE type = 'online_sale' AND store_id = 1
    ORDER BY movement_id ASC
");
$wrongMovements = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Found " . count($wrongMovements) . " online_sale movements on store_id=1 (wrong)\n\n";

if (empty($wrongMovements)) {
    echo "No corruption detected. Nothing to fix.\n";
    exit;
}

foreach ($wrongMovements as $m) {
    echo "  ID={$m['movement_id']} var={$m['variation_id']} qty={$m['quantity']} prev={$m['previous_stock']} new={$m['new_stock']} ref={$m['reference']}\n";
}

// 2. For each wrongly recorded online_sale on store_id=1:
//    - The inventory.store_id=1 was incorrectly deducted
//    - The inventory.store_id=2 was not touched (should have been deducted)
//    We need to:
//    a) Add back the quantity to store_id=1 (undo the wrong deduction)
//    b) Deduct the quantity from store_id=2 (apply correct deduction)
//    c) Move the movement log from store_id=1 to store_id=2

echo "\n\nDo you want to apply the fix? (y/n): ";
$input = trim(fgets(STDIN));

if (strtolower($input) !== 'y') {
    echo "Fix cancelled.\n";
    exit;
}

$conn->beginTransaction();

try {
    $fixedCount = 0;
    
    // Group by variation_id to aggregate corrections
    $byVariation = [];
    foreach ($wrongMovements as $m) {
        $varId = $m['variation_id'];
        if (!isset($byVariation[$varId])) {
            $byVariation[$varId] = 0;
        }
        $byVariation[$varId] += (int)$m['quantity'];
    }
    
    foreach ($byVariation as $varId => $totalQtyWronglyDeducted) {
        echo "\nFixing variation_id=$varId (total wrong deduction: $totalQtyWronglyDeducted)...\n";
        
        // Get current store_id=1 quantity
        $s1Stmt = $conn->prepare("SELECT quantity FROM inventory WHERE variation_id = ? AND store_id = 1 FOR UPDATE");
        $s1Stmt->execute([$varId]);
        $s1Qty = (int)($s1Stmt->fetchColumn() ?? 0);
        
        // Get current store_id=2 quantity
        $s2Stmt = $conn->prepare("SELECT quantity FROM inventory WHERE variation_id = ? AND store_id = 2 FOR UPDATE");
        $s2Stmt->execute([$varId]);
        $s2Qty = (int)($s2Stmt->fetchColumn() ?? 0);
        
        echo "  Before: store_id=1: $s1Qty, store_id=2: $s2Qty\n";
        
        // Restore store_id=1 by adding back what was wrongly deducted
        $newS1Qty = $s1Qty + $totalQtyWronglyDeducted;
        $conn->prepare("UPDATE inventory SET quantity = ? WHERE variation_id = ? AND store_id = 1")
             ->execute([$newS1Qty, $varId]);
        
        // Deduct from store_id=2 correctly (but don't go below 0)
        $newS2Qty = max(0, $s2Qty - $totalQtyWronglyDeducted);
        $conn->prepare("INSERT INTO inventory (variation_id, store_id, quantity) VALUES (?, 2, ?) ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)")
             ->execute([$varId, $newS2Qty]);
        
        echo "  After:  store_id=1: $newS1Qty, store_id=2: $newS2Qty\n";
    }
    
    // Move online_sale movements from store_id=1 to store_id=2
    $moveIds = array_column($wrongMovements, 'movement_id');
    $placeholders = implode(',', array_fill(0, count($moveIds), '?'));
    $updateStmt = $conn->prepare("UPDATE stock_movements SET store_id = 2 WHERE movement_id IN ($placeholders)");
    $updateStmt->execute($moveIds);
    $fixedCount = $updateStmt->rowCount();
    
    $conn->commit();
    
    echo "\n\nSUCCESS: Fixed $fixedCount movement records.\n";
    echo "- Restored store_id=1 (POS physical) stock for " . count($byVariation) . " variations.\n";
    echo "- Applied correct deduction to store_id=2 (Shopee online) stock.\n";
    echo "- Moved all " . count($wrongMovements) . " online_sale movement logs to store_id=2.\n\n";
    echo "Run the Inventory Sync Tool to finalize any remaining discrepancies.\n";
    
} catch (Exception $e) {
    $conn->rollBack();
    echo "\nERROR: " . $e->getMessage() . "\n";
    echo "Rolled back all changes. No data was modified.\n";
}

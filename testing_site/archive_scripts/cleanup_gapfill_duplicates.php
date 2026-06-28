<?php
/**
 * CLEANUP: Delete the 149,022 duplicate SYS-GAPFILL rows for variation 1126.
 * 
 * The fill_history_gaps.php had an infinite loop bug that created 149,023 identical
 * SYS-GAPFILL rows (all: qty=-18, prev=60, new=42) on Mar 13 2026 at 14:11:06.
 * 
 * We keep the FIRST one (lowest movement_id) and delete all duplicates.
 * Then we also correct the inventory to match the actual movement chain.
 */
require_once 'config/config.php';
require_once 'config/database.php';

$db = new Database();
$conn = $db->getConnection();

echo "=== Cleaning up 149,022 duplicate SYS-GAPFILL rows ===\n\n";

// How many exist?
$count = (int)$conn->query("SELECT COUNT(*) FROM stock_movements WHERE variation_id = 1126 AND reference = 'SYS-GAPFILL'")->fetchColumn();
echo "Total SYS-GAPFILL rows for variation 1126: $count\n";

// Get the first (lowest movement_id) to keep
$firstId = (int)$conn->query("SELECT MIN(movement_id) FROM stock_movements WHERE variation_id = 1126 AND reference = 'SYS-GAPFILL'")->fetchColumn();
echo "Keeping movement_id: $firstId (the original valid one)\n";
echo "Deleting all others (movement_id > $firstId)...\n\n";

$conn->beginTransaction();

try {
    // Delete all duplicates except the first
    $deleteStmt = $conn->prepare("DELETE FROM stock_movements WHERE variation_id = 1126 AND reference = 'SYS-GAPFILL' AND movement_id > ?");
    $deleteStmt->execute([$firstId]);
    $deleted = $deleteStmt->rowCount();
    
    echo "Deleted: $deleted duplicate rows\n";
    
    // Verify what's left
    $remaining = (int)$conn->query("SELECT COUNT(*) FROM stock_movements WHERE variation_id = 1126 AND reference = 'SYS-GAPFILL'")->fetchColumn();
    echo "SYS-GAPFILL rows remaining: $remaining (should be 1)\n\n";
    
    // Now check what the movement chain says the stock should be
    $movSumStmt = $conn->query("
        SELECT COALESCE(SUM(new_stock - previous_stock), 0) as total
        FROM stock_movements
        WHERE variation_id = 1126 AND store_id = 1
        AND type IN ('stock_in','stock_out','sales','adjustment','return','allocation_to_online','allocation_to_physical','shopee_balance_sync')
    ");
    $correctStock = (int)$movSumStmt->fetchColumn();
    echo "Movement history says stock should be: $correctStock\n";
    
    // Get current inventory
    $currentStock = (int)$conn->query("SELECT quantity FROM inventory WHERE variation_id = 1126 AND store_id = 1")->fetchColumn();
    echo "Current inventory.quantity: $currentStock\n";
    
    if ($currentStock !== $correctStock) {
        echo "Correcting inventory from $currentStock to $correctStock...\n";
        $conn->prepare("UPDATE inventory SET quantity = ? WHERE variation_id = 1126 AND store_id = 1")->execute([$correctStock]);
    } else {
        echo "Inventory already correct. No update needed.\n";
    }
    
    $conn->commit();
    echo "\nSUCCESS! Cleanup complete.\n";
    echo "Total stock_movements rows now: ";
    echo $conn->query("SELECT COUNT(*) FROM stock_movements")->fetchColumn() . "\n";
    
} catch (Exception $e) {
    $conn->rollBack();
    echo "ERROR: " . $e->getMessage() . "\n";
}

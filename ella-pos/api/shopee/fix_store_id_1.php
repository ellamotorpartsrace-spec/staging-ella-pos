<?php
require_once '../../config/config.php';
require_once '../../includes/auth.php';
requirePermission('admin');

$db = new Database();
$conn = $db->getConnection();

echo "<pre>";
echo "Fixing Physical POS Inventory...\n";

$conn->beginTransaction();
try {
    // 1. Delete the wrongly inserted online_sale and online_adjustment records from store_id = 1
    $stmt = $conn->prepare("DELETE FROM stock_movements WHERE store_id = 1 AND type IN ('online_sale', 'online_adjustment')");
    $stmt->execute();
    $deletedCount = $stmt->rowCount();
    echo "Deleted $deletedCount Shopee/Lazada records from Physical POS History.\n";

    // 2. Rebuild ALL inventory quantities for store_id = 1 based on the remaining valid movements
    $stmt = $conn->prepare("
        SELECT variation_id, SUM(
            CASE 
                WHEN type IN ('stock_in', 'return', 'adjustment_add', 'allocation_to_physical') THEN quantity 
                WHEN type IN ('stock_out', 'sales', 'allocation_to_online', 'adjustment_deduct') THEN -quantity 
                WHEN type = 'adjustment' THEN quantity 
                ELSE 0 
            END
        ) as actual_qty
        FROM stock_movements
        WHERE store_id = 1
        GROUP BY variation_id
    ");
    $stmt->execute();
    $totals = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $updateStmt = $conn->prepare("UPDATE inventory SET quantity = ? WHERE store_id = 1 AND variation_id = ?");
    $updateCount = 0;
    foreach ($totals as $row) {
        $updateStmt->execute([$row['actual_qty'], $row['variation_id']]);
        $updateCount++;
    }
    echo "Successfully rebuilt POS inventory for $updateCount variations.\n";

    $conn->commit();
    echo "Done! The POS stock is now completely separated from online sales.\n";
} catch (Exception $e) {
    $conn->rollBack();
    echo "Error: " . $e->getMessage() . "\n";
}
echo "</pre>";

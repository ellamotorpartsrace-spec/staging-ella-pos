<?php
require_once 'config/database.php';
$db = new Database();
$conn = $db->getConnection();

// 1. Fix the files
$files = [
    'api/lazada/sync_orders.php',
    'api/shopee/fetch_mapped_live_stocks.php',
    'api/lazada/fetch_mapped_live_stocks.php'
];

foreach ($files as $file) {
    if (!file_exists($file)) continue;
    $content = file_get_contents($file);
    
    // Replace UPDATE and SELECT FOR UPDATE store_ids
    $content = str_replace(
        "WHERE variation_id = ? AND store_id = 1 FOR UPDATE", 
        "WHERE variation_id = ? AND store_id = 2 FOR UPDATE", 
        $content
    );
    $content = str_replace(
        "WHERE variation_id = ? AND store_id = 1\")", 
        "WHERE variation_id = ? AND store_id = 2\")", 
        $content
    );
    
    // Replace INSERT VALUES (1,
    $content = str_replace(
        "VALUES (1, ?, 'online_sale'", 
        "VALUES (2, ?, 'online_sale'", 
        $content
    );
    $content = str_replace(
        "VALUES (1, ?, 'online_adjustment'", 
        "VALUES (2, ?, 'online_adjustment'", 
        $content
    );
    $content = str_replace(
        "VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?, ?)", 
        "VALUES (2, ?, ?, ?, ?, ?, ?, ?, ?, ?)", 
        $content
    );

    file_put_contents($file, $content);
    echo "Updated $file\n";
}

// 2. Fix the database
echo "\nFixing database store_id=1 online_sale movements...\n";
$conn->beginTransaction();
try {
    // A. Delete the wrongly inserted online_sale and online_adjustment records from store_id = 1
    $stmt = $conn->prepare("DELETE FROM stock_movements WHERE store_id = 1 AND type IN ('online_sale', 'online_adjustment')");
    $stmt->execute();
    $deletedCount = $stmt->rowCount();
    echo "Deleted $deletedCount online_sale/adjustment records from store_id 1.\n";

    // B. Rebuild ALL inventory quantities for store_id = 1 based on the remaining valid movements
    // To do this accurately, we just recalculate the total for each variation from stock_movements
    $stmt = $conn->prepare("
        SELECT variation_id, SUM(
            CASE 
                WHEN type IN ('stock_in', 'return', 'adjustment_add', 'allocation_to_physical') THEN quantity 
                WHEN type IN ('stock_out', 'sales', 'allocation_to_online', 'adjustment_deduct') THEN -quantity 
                WHEN type = 'adjustment' THEN quantity -- handle if quantity is positive/negative
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
    echo "Rebuilt inventory for $updateCount variations in store_id 1.\n";

    $conn->commit();
    echo "Done!\n";
} catch (Exception $e) {
    $conn->rollBack();
    echo "Error: " . $e->getMessage() . "\n";
}

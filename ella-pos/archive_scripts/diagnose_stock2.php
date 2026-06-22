<?php
require_once 'config/config.php';
require_once 'config/database.php';

$db = new Database();
$conn = $db->getConnection();

// Check the 200-stock products - look at their movement history
$suspiciousVarIds = [6426, 6398, 6407, 52, 45, 1566, 30, 5783];

echo "=== Products with exactly 200 stock - Movement History Check ===\n\n";

foreach ($suspiciousVarIds as $varId) {
    $stmt = $conn->prepare("
        SELECT p.product_name, v.variation_name,
               (SELECT quantity FROM inventory WHERE variation_id = ? AND store_id = 1) as current_stock
        FROM product_variations v
        JOIN products p ON p.product_id = v.product_id
        WHERE v.variation_id = ?
    ");
    $stmt->execute([$varId, $varId]);
    $prod = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo "VAR #{$varId}: {$prod['product_name']} - {$prod['variation_name']} (current: {$prod['current_stock']})\n";
    
    $mStmt = $conn->prepare("
        SELECT movement_id, store_id, type, quantity, previous_stock, new_stock, reference, created_at
        FROM stock_movements
        WHERE variation_id = ?
        ORDER BY movement_id ASC
    ");
    $mStmt->execute([$varId]);
    $movements = $mStmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($movements)) {
        echo "  NO MOVEMENTS AT ALL - inventory was set directly without any movement record!\n";
    } else {
        $lastMovement = end($movements);
        echo "  Last movement: type={$lastMovement['type']} new_stock={$lastMovement['new_stock']} at={$lastMovement['created_at']}\n";
        echo "  Total movements: " . count($movements) . "\n";
    }
    echo "\n";
}

// Check variation 1126 (the giant discrepancy)
echo "\n=== Variation 1126 (CAMSHAFT RACING NMAX) - massive discrepancy ===\n";
$stmt = $conn->query("SELECT * FROM inventory WHERE variation_id = 1126");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo "  store_id={$r['store_id']} quantity={$r['quantity']}\n";
}
echo "  Movements (most recent 10):\n";
$stmt2 = $conn->query("SELECT movement_id, store_id, type, quantity, previous_stock, new_stock, reference, created_at FROM stock_movements WHERE variation_id=1126 ORDER BY movement_id DESC LIMIT 10");
foreach ($stmt2->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo "    id={$r['movement_id']} store={$r['store_id']} {$r['type']} qty={$r['quantity']} {$r['previous_stock']}→{$r['new_stock']} at={$r['created_at']}\n";
}
echo "  All-time movement SUM (store_id=1): ";
$stmt3 = $conn->query("SELECT SUM(new_stock - previous_stock) FROM stock_movements WHERE variation_id=1126 AND store_id=1 AND type IN ('stock_in','stock_out','sales','adjustment','return','allocation_to_online','allocation_to_physical')");
echo $stmt3->fetchColumn() . "\n";

// Count total movement rows for 1126
$cnt = $conn->query("SELECT COUNT(*) FROM stock_movements WHERE variation_id=1126")->fetchColumn();
echo "  Total movement rows: $cnt\n";

// Find how many have extreme values
echo "  Movements with |new_stock - previous_stock| > 1000:\n";
$stmt4 = $conn->query("SELECT movement_id, type, quantity, previous_stock, new_stock, reference, created_at FROM stock_movements WHERE variation_id=1126 AND ABS(new_stock - previous_stock) > 1000 ORDER BY movement_id LIMIT 5");
foreach ($stmt4->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo "    id={$r['movement_id']} {$r['type']} qty={$r['quantity']} {$r['previous_stock']}→{$r['new_stock']} at={$r['created_at']}\n";
}

// Summary: which products have inventory set but ZERO movements
echo "\n\n=== Products with inventory quantity > 0 but NO stock movements ===\n";
$stmt5 = $conn->query("
    SELECT i.variation_id, i.quantity, p.product_name, v.variation_name
    FROM inventory i
    JOIN product_variations v ON v.variation_id = i.variation_id
    JOIN products p ON p.product_id = v.product_id
    LEFT JOIN stock_movements sm ON sm.variation_id = i.variation_id AND sm.store_id = 1
    WHERE i.store_id = 1 AND i.quantity > 0 AND sm.movement_id IS NULL
    ORDER BY i.quantity DESC
    LIMIT 20
");
$noMovRows = $stmt5->fetchAll(PDO::FETCH_ASSOC);
echo "Found " . count($noMovRows) . " products:\n";
foreach ($noMovRows as $r) {
    echo "  var_id={$r['variation_id']} qty={$r['quantity']} {$r['product_name']} - {$r['variation_name']}\n";
}

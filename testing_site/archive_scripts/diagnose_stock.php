<?php
/**
 * Diagnostic: Find all inventory rows where current quantity doesn't match
 * what stock movements say it should be.
 */
require_once 'config/config.php';
require_once 'config/database.php';

$db = new Database();
$conn = $db->getConnection();

echo "=== STOCK DISCREPANCY REPORT ===\n";
echo "Products where inventory.quantity != sum of movement history\n\n";

// Calculate expected stock from movement history for store_id=1
$stmt = $conn->query("
    SELECT 
        sm.variation_id,
        COALESCE(SUM(sm.new_stock - sm.previous_stock), 0) AS movement_total,
        i.quantity AS current_qty,
        (i.quantity - COALESCE(SUM(sm.new_stock - sm.previous_stock), 0)) AS discrepancy,
        p.product_name,
        v.variation_name,
        v.sku
    FROM inventory i
    JOIN product_variations v ON v.variation_id = i.variation_id
    JOIN products p ON p.product_id = v.product_id
    LEFT JOIN stock_movements sm ON sm.variation_id = i.variation_id 
        AND sm.store_id = 1
        AND sm.type IN ('stock_in','stock_out','sales','adjustment','return','allocation_to_online','allocation_to_physical','shopee_balance_sync')
    WHERE i.store_id = 1
    GROUP BY sm.variation_id, i.quantity, p.product_name, v.variation_name, v.sku, i.variation_id
    HAVING ABS(i.quantity - COALESCE(SUM(sm.new_stock - sm.previous_stock), 0)) > 0
    ORDER BY ABS(i.quantity - COALESCE(SUM(sm.new_stock - sm.previous_stock), 0)) DESC
    LIMIT 30
");

$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Found " . count($rows) . " products with discrepancy:\n\n";
printf("%-8s %-50s %-10s %-10s %-10s\n", "VAR_ID", "PRODUCT", "MOVES_SAY", "ACTUAL", "DIFF");
echo str_repeat("-", 90) . "\n";

foreach ($rows as $r) {
    $name = substr($r['product_name'] . ' - ' . $r['variation_name'], 0, 48);
    printf("%-8s %-50s %-10s %-10s %-10s\n",
        $r['variation_id'],
        $name,
        $r['movement_total'],
        $r['current_qty'],
        ($r['discrepancy'] > 0 ? '+' : '') . $r['discrepancy']
    );
}

// Also check specifically for the 200-stock products
echo "\n\n=== Products with exactly 200 stock (suspicious) ===\n";
$stmt2 = $conn->query("
    SELECT i.variation_id, i.quantity, p.product_name, v.variation_name
    FROM inventory i
    JOIN product_variations v ON v.variation_id = i.variation_id
    JOIN products p ON p.product_id = v.product_id
    WHERE i.store_id = 1 AND i.quantity = 200
    ORDER BY p.product_name
    LIMIT 20
");
foreach ($stmt2->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo "  var_id={$r['variation_id']} {$r['product_name']} - {$r['variation_name']}: {$r['quantity']}\n";
}

// Show when inventory was last modified by checking latest movements vs current stock
echo "\n\n=== Recent movements on store_id=2 (our fix) ===\n";
$stmt3 = $conn->query("
    SELECT sm.variation_id, sm.store_id, sm.type, sm.quantity, sm.previous_stock, sm.new_stock, sm.reference, sm.created_at
    FROM stock_movements sm
    WHERE sm.store_id = 2
    ORDER BY sm.movement_id DESC
    LIMIT 15
");
foreach ($stmt3->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo "  var={$r['variation_id']} store={$r['store_id']} {$r['type']} qty={$r['quantity']} {$r['previous_stock']}→{$r['new_stock']} ref={$r['reference']} at={$r['created_at']}\n";
}

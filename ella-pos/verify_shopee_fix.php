<?php
require_once 'config/config.php';
require_once 'config/database.php';

$db = new Database();
$conn = $db->getConnection();

// Verify no more corrupted movements
$stmt = $conn->query("
    SELECT store_id, type, COUNT(*) as cnt 
    FROM stock_movements 
    WHERE type IN ('online_sale','online_adjustment') 
    GROUP BY store_id, type 
    ORDER BY store_id
");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "=== Online Movements by Store (should all be store_id=2 now) ===\n";
foreach ($rows as $r) {
    $status = $r['store_id'] == 2 ? "✓ CORRECT" : "✗ WRONG";
    echo "  store_id={$r['store_id']} type={$r['type']} count={$r['cnt']} $status\n";
}

// Check variation 2171 (the one from the screenshot)
echo "\n=== Variation 2171 (from screenshot) ===\n";
$stmt2 = $conn->query("SELECT store_id, quantity FROM inventory WHERE variation_id = 2171 ORDER BY store_id");
foreach ($stmt2->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo "  store_id={$r['store_id']} quantity={$r['quantity']}\n";
}

$stmt3 = $conn->query("
    SELECT movement_id, store_id, type, quantity, previous_stock, new_stock, reference
    FROM stock_movements
    WHERE variation_id = 2171
    ORDER BY movement_id DESC LIMIT 10
");
echo "\n  Recent movements:\n";
foreach ($stmt3->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $flag = in_array($r['type'], ['online_sale','online_adjustment']) ? " [ONLINE]" : "";
    echo "  id={$r['movement_id']} store={$r['store_id']} {$r['type']}{$flag} qty={$r['quantity']} {$r['previous_stock']}→{$r['new_stock']}\n";
}

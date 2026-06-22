<?php
require_once 'config/config.php';
require_once 'config/database.php';

$db = new Database();
$conn = $db->getConnection();

echo "=== CRITICAL: Variation 1126 has 149,026 duplicate movement rows ===\n\n";

// Count allocation_to_online duplicates for var 1126
$stmt = $conn->query("
    SELECT type, COUNT(*) as cnt, MIN(created_at) as first_at, MAX(created_at) as last_at
    FROM stock_movements
    WHERE variation_id = 1126
    GROUP BY type
    ORDER BY cnt DESC
");
echo "Movement type breakdown for var 1126:\n";
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo "  type={$r['type']} count={$r['cnt']} from={$r['first_at']} to={$r['last_at']}\n";
}

// Show first 3 and last 3 allocation_to_online movements
echo "\nFirst 5 allocation_to_online:\n";
$stmt2 = $conn->query("SELECT movement_id, qty_col.* FROM (SELECT movement_id, quantity, previous_stock, new_stock, reference, created_at FROM stock_movements WHERE variation_id=1126 AND type='allocation_to_online' ORDER BY movement_id ASC LIMIT 5) qty_col");
$stmt2 = $conn->query("SELECT movement_id, quantity, previous_stock, new_stock, reference, created_at FROM stock_movements WHERE variation_id=1126 AND type='allocation_to_online' ORDER BY movement_id ASC LIMIT 5");
foreach ($stmt2->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo "  id={$r['movement_id']} qty={$r['quantity']} {$r['previous_stock']}→{$r['new_stock']} ref={$r['reference']} at={$r['created_at']}\n";
}

echo "\nLast 5 allocation_to_online:\n";
$stmt3 = $conn->query("SELECT movement_id, quantity, previous_stock, new_stock, reference, created_at FROM stock_movements WHERE variation_id=1126 AND type='allocation_to_online' ORDER BY movement_id DESC LIMIT 5");
foreach ($stmt3->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo "  id={$r['movement_id']} qty={$r['quantity']} {$r['previous_stock']}→{$r['new_stock']} ref={$r['reference']} at={$r['created_at']}\n";
}

// Check for duplicate movement batches (same reference, same timestamp)
echo "\nDuplicate movement batches (same reference + same minute):\n";
$stmt4 = $conn->query("
    SELECT reference, DATE_FORMAT(created_at, '%Y-%m-%d %H:%i') as minute, COUNT(*) as cnt
    FROM stock_movements
    WHERE variation_id = 1126
    GROUP BY reference, DATE_FORMAT(created_at, '%Y-%m-%d %H:%i')
    HAVING cnt > 1
    ORDER BY cnt DESC
    LIMIT 5
");
foreach ($stmt4->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo "  ref={$r['reference']} at_minute={$r['minute']} duplicates={$r['cnt']}\n";
}

// Check all products for mass duplicate movements (the root cause of the corruption)
echo "\n\n=== Products with suspiciously high movement counts (possible infinite loop bug) ===\n";
$stmt5 = $conn->query("
    SELECT variation_id, COUNT(*) as total_movements, 
           SUM(CASE WHEN type='allocation_to_online' THEN 1 ELSE 0 END) as alloc_count
    FROM stock_movements
    WHERE store_id = 1
    GROUP BY variation_id
    HAVING total_movements > 1000
    ORDER BY total_movements DESC
    LIMIT 10
");
foreach ($stmt5->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $stmt6 = $conn->prepare("SELECT CONCAT(p.product_name, ' - ', v.variation_name) FROM product_variations v JOIN products p ON p.product_id=v.product_id WHERE v.variation_id=?");
    $stmt6->execute([$r['variation_id']]);
    $name = $stmt6->fetchColumn();
    echo "  var={$r['variation_id']} name='{$name}' total_movements={$r['total_movements']} allocation_to_online={$r['alloc_count']}\n";
}

// Check what's in Shopee mapping for var 1126
echo "\n\n=== Shopee mapping for var 1126 ===\n";
$stmt7 = $conn->query("SELECT id, shopee_item_id, shopee_model_id, shopee_stock, pos_product_id, mapping_status, updated_at FROM shopee_product_mappings WHERE pos_product_id = 1126");
foreach ($stmt7->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo "  map_id={$r['id']} shopee_stock={$r['shopee_stock']} pos_id={$r['pos_product_id']} status={$r['mapping_status']} updated={$r['updated_at']}\n";
}

// Total movement count in the entire table
$total = $conn->query("SELECT COUNT(*) FROM stock_movements")->fetchColumn();
echo "\n\nTotal stock_movements rows in entire DB: $total\n";
echo "Movements for var 1126 alone: 149,026 (that's " . round(149026/$total*100, 1) . "% of ALL movements!)\n";

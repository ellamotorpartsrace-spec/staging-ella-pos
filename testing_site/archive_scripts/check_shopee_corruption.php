<?php
require_once 'config/config.php';
require_once 'config/database.php';

$db = new Database();
$conn = $db->getConnection();

// Check movements by store_id and type
$stmt = $conn->query("
    SELECT store_id, type, COUNT(*) as cnt, SUM(quantity) as total_qty 
    FROM stock_movements 
    WHERE type IN ('online_sale','online_adjustment') 
    GROUP BY store_id, type 
    ORDER BY store_id
");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "=== Online Sale/Adjustment Movements by Store ===\n";
foreach ($rows as $r) {
    echo "store_id={$r['store_id']} type={$r['type']} count={$r['cnt']} total_qty={$r['total_qty']}\n";
}

// Get affected product for variation 1114 (from screenshot URL)
echo "\n=== Movements for variation_id=1114 ===\n";
$stmt2 = $conn->query("
    SELECT movement_id, store_id, type, quantity, previous_stock, new_stock, reference, created_at
    FROM stock_movements
    WHERE variation_id = 1114
    ORDER BY movement_id DESC
    LIMIT 20
");
$rows2 = $stmt2->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows2 as $r) {
    echo "id={$r['movement_id']} store={$r['store_id']} type={$r['type']} qty={$r['quantity']} prev={$r['previous_stock']} new={$r['new_stock']} ref={$r['reference']} at={$r['created_at']}\n";
}

// Check current inventory for variation_id=1114
echo "\n=== Current Inventory for variation_id=1114 ===\n";
$stmt3 = $conn->query("SELECT store_id, quantity FROM inventory WHERE variation_id = 1114 ORDER BY store_id");
$rows3 = $stmt3->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows3 as $r) {
    echo "store_id={$r['store_id']} quantity={$r['quantity']}\n";
}

// Check shopee_product_mappings for this variation
echo "\n=== Shopee Mapping for variation_id=1114 ===\n";
$stmt4 = $conn->query("SELECT id, shopee_item_id, shopee_model_id, shopee_stock, pos_product_id, mapping_status FROM shopee_product_mappings WHERE pos_product_id = 1114");
$rows4 = $stmt4->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows4 as $r) {
    echo "map_id={$r['id']} shopee_item={$r['shopee_item_id']} model={$r['shopee_model_id']} shopee_stock={$r['shopee_stock']} pos_id={$r['pos_product_id']} status={$r['mapping_status']}\n";
}

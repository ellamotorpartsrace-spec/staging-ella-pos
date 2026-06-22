<?php
// scratch_pilot_motogp.php - Find the "100/80-14 PILOT MOTO GP" tire and diagnose it
if (!class_exists('Database')) { require 'config/database.php'; }
date_default_timezone_set('Asia/Manila');

$db = new Database();
$conn = $db->getConnection();

// Find the product by name/variation
$stmt = $conn->prepare("
    SELECT v.variation_id, v.variation_name, v.sku, p.product_name, p.brand_name,
           COALESCE(i1.quantity,0) as phys_qty,
           COALESCE(i2.quantity,0) as online_qty
    FROM product_variations v
    JOIN products p ON p.product_id = v.product_id
    LEFT JOIN inventory i1 ON i1.variation_id = v.variation_id AND i1.store_id = 1
    LEFT JOIN inventory i2 ON i2.variation_id = v.variation_id AND i2.store_id = 2
    WHERE p.product_name LIKE '%100/80%' OR p.product_name LIKE '%PILOT%MOTO%' OR v.sku LIKE '%750769%'
    ORDER BY v.variation_id
");
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "=== MATCHING PRODUCTS ===\n";
foreach ($rows as $r) {
    echo "  var_id={$r['variation_id']} | {$r['product_name']} {$r['variation_name']} | SKU={$r['sku']} | phys={$r['phys_qty']} online={$r['online_qty']}\n";
}

// Also check if id=6192 is used as a product_id (not variation_id)
echo "\n=== CHECKING IF 6192 IS A PRODUCT_ID ===\n";
$p = $conn->prepare("SELECT product_id, product_name FROM products WHERE product_id = ?");
$p->execute([6192]);
$prod = $p->fetch(PDO::FETCH_ASSOC);
if ($prod) {
    echo "  Found product: {$prod['product_name']}\n";
    $vs = $conn->prepare("SELECT v.variation_id, v.variation_name, v.sku, COALESCE(i.quantity,0) as qty FROM product_variations v LEFT JOIN inventory i ON i.variation_id = v.variation_id AND i.store_id=1 WHERE v.product_id = ?");
    $vs->execute([6192]);
    foreach ($vs->fetchAll(PDO::FETCH_ASSOC) as $v) {
        echo "    var_id={$v['variation_id']} | {$v['variation_name']} | sku={$v['sku']} | qty={$v['qty']}\n";
    }
} else {
    echo "  No product found with product_id=6192\n";
}

// Check if id=6192 is truly a variation_id and what product name it has
echo "\n=== VARIATION_ID 6192 DETAILS ===\n";
$vd = $conn->prepare("SELECT v.variation_id, v.variation_name, v.sku, p.product_name, p.brand_name, COALESCE(i.quantity,0) as qty FROM product_variations v JOIN products p ON p.product_id = v.product_id LEFT JOIN inventory i ON i.variation_id = v.variation_id AND i.store_id=1 WHERE v.variation_id = ?");
$vd->execute([6192]);
$vrow = $vd->fetch(PDO::FETCH_ASSOC);
if ($vrow) {
    echo "  Product: {$vrow['product_name']} | Variation: {$vrow['variation_name']} | SKU: {$vrow['sku']} | Qty: {$vrow['qty']}\n";
} else {
    echo "  variation_id 6192 does not exist!\n";
}

// Now get the full movement chain for the item that shows 803 inventory
// Find items with huge inventory (around 803)
echo "\n=== ITEMS WITH LARGE INVENTORY (around 800) ===\n";
$big = $conn->query("SELECT i.variation_id, i.quantity, p.product_name, v.variation_name, v.sku FROM inventory i JOIN product_variations v ON v.variation_id = i.variation_id JOIN products p ON p.product_id = v.product_id WHERE i.store_id=1 AND i.quantity > 700 AND i.quantity < 900 ORDER BY i.quantity DESC LIMIT 10");
foreach ($big->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo "  var_id={$r['variation_id']} qty={$r['quantity']} | {$r['product_name']} {$r['variation_name']} sku={$r['sku']}\n";
}

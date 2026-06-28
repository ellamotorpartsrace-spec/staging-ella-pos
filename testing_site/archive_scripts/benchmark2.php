<?php
require 'config/database.php';
$db = new Database(); $conn = $db->getConnection();

$baseSql = "
    FROM product_variations v
    JOIN products p ON v.product_id = p.product_id
    LEFT JOIN inventory i_phys ON v.variation_id = i_phys.variation_id AND i_phys.store_id = 1
    LEFT JOIN inventory i_online ON v.variation_id = i_online.variation_id AND i_online.store_id = 2
    WHERE v.status = 'active'
";

$sqlProducts = "
    SELECT v.variation_id, p.product_name " . $baseSql . "
    ORDER BY p.product_name ASC
    LIMIT 100 OFFSET 0
";

$sqlProductsNoOrder = "
    SELECT v.variation_id, p.product_name " . $baseSql . "
    LIMIT 100 OFFSET 0
";

$start = microtime(true);
$conn->query($sqlProducts);
echo "With Order: " . round((microtime(true) - $start) * 1000, 2) . " ms\n";

$start = microtime(true);
$conn->query($sqlProductsNoOrder);
echo "Without Order: " . round((microtime(true) - $start) * 1000, 2) . " ms\n";

// Test Stats Query
$sqlStats = "
    SELECT 
        COUNT(*) as total_items,
        SUM(v.price_capital * (COALESCE(i_phys.quantity, 0) + COALESCE(i_online.quantity, 0))) as total_asset_value,
        SUM(CASE WHEN COALESCE(i_phys.quantity, 0) <= v.low_stock_threshold THEN 1 ELSE 0 END) as low_stock_count
    " . $baseSql;

$start = microtime(true);
$conn->query($sqlStats);
echo "Stats Query: " . round((microtime(true) - $start) * 1000, 2) . " ms\n";

?>

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

$sqlStats = "
    SELECT 
        COUNT(*) as total_items,
        SUM(v.price_capital * (COALESCE(i_phys.quantity, 0) + COALESCE(i_online.quantity, 0))) as total_asset_value,
        SUM(CASE WHEN COALESCE(i_phys.quantity, 0) <= v.low_stock_threshold THEN 1 ELSE 0 END) as low_stock_count
    " . $baseSql;

$start = microtime(true);
$stmtStats = $conn->prepare($sqlStats);
$stmtStats->execute();
$stats = $stmtStats->fetch(PDO::FETCH_ASSOC);
$end = microtime(true);
echo "Stats Query Time: " . round(($end - $start) * 1000, 2) . " ms\n";

$sqlProducts = "
    SELECT v.variation_id, v.variation_name, v.sku, v.unit_type,
           v.price_capital, v.price_retail, v.status, v.low_stock_threshold,
           p.product_name, p.brand_name, p.image_path,
           COALESCE(i_phys.quantity, 0) + COALESCE(i_online.quantity, 0) as current_stock,
           (SELECT 1 FROM shopee_product_mappings WHERE pos_product_id = v.variation_id LIMIT 1) as is_shopee_mapped,
           (
               SELECT COALESCE(SUM(m.shopee_stock * COALESCE(u.multiplier, 1)), 0)
               FROM shopee_product_mappings m
               LEFT JOIN product_units u ON m.pos_unit_id = u.id
               WHERE m.pos_product_id = v.variation_id AND m.mapping_status IN ('auto','manual')
           ) as shopee_allocated
    " . $baseSql . "
    ORDER BY p.product_name ASC
    LIMIT 100 OFFSET 0
";

$start = microtime(true);
$stmt = $conn->prepare($sqlProducts);
$stmt->execute();
$products = $stmt->fetchAll();
$end = microtime(true);
echo "Products Query Time: " . round(($end - $start) * 1000, 2) . " ms\n";

?>

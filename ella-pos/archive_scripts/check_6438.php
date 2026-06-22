<?php
require_once 'config/config.php';
require_once 'config/database.php';
$db = new Database();
$conn = $db->getConnection();

$variation_id = 6438;
$sqlProd = "
    SELECT 
        p.product_name, p.brand_name, 
        v.variation_name, v.sku, v.barcode, v.unit_type,
        COALESCE(i_phys.quantity, 0) as physical_stock,
        COALESCE(i_online.quantity, 0) as online_stock,
        COALESCE(i_phys.quantity, 0) + COALESCE(i_online.quantity, 0) as total_stock,
        CAST((
            SELECT COALESCE(MAX(m.shopee_stock * COALESCE(u.multiplier, 1)), 0)
            FROM shopee_product_mappings m
            LEFT JOIN product_units u ON m.pos_unit_id = u.id
            WHERE (m.pos_product_id = v.variation_id OR (v.sku NOT IN ('', '-', 'N/A', 'NA', 'none', 'null') AND m.matched_pos_sku = v.sku COLLATE utf8mb4_unicode_ci))
              AND m.mapping_status IN ('auto','manual')
              AND (m.pos_bundle_set_id IS NULL OR m.pos_bundle_set_id = 0)
        ) AS SIGNED) as shopee_allocated
    FROM product_variations v
    JOIN products p ON v.product_id = p.product_id
    LEFT JOIN inventory i_phys ON v.variation_id = i_phys.variation_id AND i_phys.store_id = 1
    LEFT JOIN inventory i_online ON v.variation_id = i_online.variation_id AND i_online.store_id = 2
    WHERE v.variation_id = :id
";
$stmt = $conn->prepare($sqlProd);
$stmt->execute([':id' => $variation_id]);
$product = $stmt->fetch(PDO::FETCH_ASSOC);

print_r($product);

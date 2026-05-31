<?php
require 'c:\xampp\htdocs\ella-pos\ella-pos\config\database.php';
$db = new Database();
$conn = $db->getConnection();

$stmt = $conn->query("
    SELECT v.variation_id, v.sku, p.product_name, p.brand_name,
        CAST(
        (
            SELECT COALESCE(SUM(m.shopee_stock * COALESCE(u.multiplier, 1)), 0)
            FROM shopee_product_mappings m
            LEFT JOIN product_units u ON m.pos_unit_id = u.id
            WHERE (m.pos_product_id = v.variation_id OR (v.sku != '' AND m.matched_pos_sku = v.sku COLLATE utf8mb4_unicode_ci))
              AND m.mapping_status IN ('auto','manual')
              AND (m.pos_bundle_set_id IS NULL OR m.pos_bundle_set_id = 0)
        ) AS SIGNED) as online_stock
    FROM product_variations v
    JOIN products p ON v.product_id = p.product_id
    WHERE p.product_name LIKE '%Clutch Shoe Nmax/Aerox/M3/Click%'
");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

<?php
require 'testing_site/config/config.php';
$db = new Database();
$conn = $db->getConnection();
$variation_id = 6415; // from previous check
$sqlProd = "
    SELECT 
        v.variation_id, v.sku,
        COALESCE(i_phys.quantity, 0) as physical_stock,
        COALESCE(i_online.quantity, 0) as online_stock,
        COALESCE(i_phys.quantity, 0) + COALESCE(i_online.quantity, 0) as total_stock,
        CAST((
            SELECT COALESCE(SUM(m.shopee_stock * COALESCE(u.multiplier, 1)), 0)
            FROM shopee_product_mappings m
            LEFT JOIN product_units u ON m.pos_unit_id = u.id
            WHERE (m.pos_product_id = v.variation_id OR (v.sku NOT IN ('', '-', 'N/A', 'NA', 'none', 'null') AND m.matched_pos_sku = v.sku COLLATE utf8mb4_unicode_ci))
              AND m.mapping_status IN ('auto','manual')
              AND (m.pos_bundle_set_id IS NULL OR m.pos_bundle_set_id = 0)
        ) AS SIGNED) as shopee_allocated,
        CAST((
            SELECT COALESCE(SUM(m2.lazada_stock * COALESCE(u2.multiplier, 1)), 0)
            FROM lazada_product_mappings m2
            LEFT JOIN product_units u2 ON m2.pos_unit_id = u2.id
            WHERE (m2.pos_product_id = v.variation_id OR (v.sku NOT IN ('', '-', 'N/A', 'NA', 'none', 'null') AND m2.matched_pos_sku = v.sku COLLATE utf8mb4_unicode_ci))
              AND m2.mapping_status IN ('auto','manual','mapped')
              AND (m2.pos_bundle_set_id IS NULL OR m2.pos_bundle_set_id = 0)
        ) AS SIGNED) as lazada_allocated
    FROM product_variations v
    LEFT JOIN inventory i_phys ON v.variation_id = i_phys.variation_id AND i_phys.store_id = 1
    LEFT JOIN inventory i_online ON v.variation_id = i_online.variation_id AND i_online.store_id = 2
    LEFT JOIN inventory i_lazada ON v.variation_id = i_lazada.variation_id AND i_lazada.store_id = 3
    WHERE v.variation_id = :id
";
$stmt = $conn->prepare($sqlProd);
$stmt->execute([':id' => $variation_id]);
$product = $stmt->fetch(PDO::FETCH_ASSOC);
print_r($product);

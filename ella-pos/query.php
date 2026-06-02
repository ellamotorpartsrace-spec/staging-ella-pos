<?php
require_once 'config/config.php';
require_once 'config/database.php';
$db = new Database();
$conn = $db->getConnection();

$q = "clutch bell nut";
$sql = "
        SELECT 
            v.variation_id,
            p.product_name,
            v.variation_name,
            COALESCE(inv.stock, 0) AS final_stock
        FROM product_variations v
        INNER JOIN products p ON v.product_id = p.product_id
        LEFT JOIN (
            SELECT 
                i.variation_id, 
                GREATEST(0, i.quantity - COALESCE(sa.shopee_allocated, 0)) as stock 
            FROM inventory i
            LEFT JOIN (
                SELECT m.pos_product_id, COALESCE(SUM(m.shopee_stock * COALESCE(u.multiplier, 1)), 0) as shopee_allocated
                FROM shopee_product_mappings m
                LEFT JOIN product_units u ON m.pos_unit_id = u.id
                WHERE m.mapping_status IN ('auto','manual')
                GROUP BY m.pos_product_id
            ) sa ON i.variation_id = sa.pos_product_id
            WHERE i.store_id = 1
        ) inv ON v.variation_id = inv.variation_id
        WHERE p.product_name LIKE :q OR v.variation_name LIKE :q
";

$stmt = $conn->prepare($sql);
$stmt->execute([':q' => "%$q%"]);
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

$sql2 = "
        SELECT 
            variation_id, quantity
        FROM inventory
        WHERE variation_id IN (
            SELECT variation_id FROM product_variations v
            INNER JOIN products p ON v.product_id = p.product_id
            WHERE p.product_name LIKE :q OR v.variation_name LIKE :q
        )
";

$stmt2 = $conn->prepare($sql2);
$stmt2->execute([':q' => "%$q%"]);
echo "\n--- Raw Inventory ---\n";
print_r($stmt2->fetchAll(PDO::FETCH_ASSOC));

$sql3 = "
    SELECT m.pos_product_id, COALESCE(SUM(m.shopee_stock * COALESCE(u.multiplier, 1)), 0) as shopee_allocated
    FROM shopee_product_mappings m
    LEFT JOIN product_units u ON m.pos_unit_id = u.id
    WHERE m.mapping_status IN ('auto','manual')
    AND m.pos_product_id IN (
            SELECT variation_id FROM product_variations v
            INNER JOIN products p ON v.product_id = p.product_id
            WHERE p.product_name LIKE :q OR v.variation_name LIKE :q
    )
    GROUP BY m.pos_product_id
";
$stmt3 = $conn->prepare($sql3);
$stmt3->execute([':q' => "%$q%"]);
echo "\n--- Shopee Allocated ---\n";
print_r($stmt3->fetchAll(PDO::FETCH_ASSOC));

<?php
require_once 'config/config.php';
require_once 'config/database.php';
$db = new Database();
$conn = $db->getConnection();

$sql = "
            SELECT 
                i.variation_id, 
                SUM(i.quantity) as total_qty,
                COALESCE(sa.shopee_allocated, 0) as shopee_alloc,
                GREATEST(0, SUM(i.quantity) - COALESCE(sa.shopee_allocated, 0)) as stock 
            FROM inventory i
            LEFT JOIN (
                SELECT m.pos_product_id, COALESCE(SUM(m.shopee_stock * COALESCE(u.multiplier, 1)), 0) as shopee_allocated
                FROM shopee_product_mappings m
                LEFT JOIN product_units u ON m.pos_unit_id = u.id
                WHERE m.mapping_status IN ('auto','manual')
                GROUP BY m.pos_product_id
            ) sa ON i.variation_id = sa.pos_product_id
            WHERE i.variation_id = 399
            GROUP BY i.variation_id, sa.shopee_allocated
";

$stmt = $conn->prepare($sql);
$stmt->execute();
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

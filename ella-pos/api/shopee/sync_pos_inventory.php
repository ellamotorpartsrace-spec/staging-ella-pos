<?php
require '../../config/database.php';
$db = new Database();
$conn = $db->getConnection();

echo "<pre>";
echo "Starting synchronization of POS inventory with Shopee allocations...\n";

// Get all products mapped to Shopee
$stmt = $conn->query("
    SELECT 
        v.variation_id,
        COALESCE(i_phys.quantity, 0) + COALESCE(i_shopee.quantity, 0) + COALESCE(i_lazada.quantity, 0) as total_stock,
        COALESCE(i_lazada.quantity, 0) as lazada_allocated,
        (
            SELECT COALESCE(MAX(m.shopee_stock * COALESCE(u.multiplier, 1)), 0)
            FROM shopee_product_mappings m
            LEFT JOIN product_units u ON m.pos_unit_id = u.id
            WHERE m.pos_product_id = v.variation_id AND m.mapping_status IN ('auto','manual')
        ) as shopee_allocated
    FROM product_variations v
    LEFT JOIN inventory i_phys ON v.variation_id = i_phys.variation_id AND i_phys.store_id = 1
    LEFT JOIN inventory i_shopee ON v.variation_id = i_shopee.variation_id AND i_shopee.store_id = 2
    LEFT JOIN inventory i_lazada ON v.variation_id = i_lazada.variation_id AND i_lazada.store_id = 3
    WHERE EXISTS (
        SELECT 1 FROM shopee_product_mappings spm 
        WHERE spm.pos_product_id = v.variation_id AND spm.mapping_status IN ('auto','manual')
    )
");

$products = $stmt->fetchAll(PDO::FETCH_ASSOC);
$conn->beginTransaction();

try {
    $fixedCount = 0;
    foreach ($products as $p) {
        $varId = $p['variation_id'];
        $totalStock = (int)$p['total_stock'];
        $shopeeAllocated = (int)$p['shopee_allocated'];
        
        $lazadaAllocated = (int)$p['lazada_allocated'];
        
        // Cap shopee allocation at total stock just in case
        if ($shopeeAllocated > $totalStock) {
            $shopeeAllocated = $totalStock;
        }
        if ($shopeeAllocated < 0) $shopeeAllocated = 0;
        
        $physicalStock = $totalStock - $shopeeAllocated - $lazadaAllocated;
        if ($physicalStock < 0) $physicalStock = 0;

        // Get current physical stock before updating to log movement
        $currStmt = $conn->prepare("SELECT quantity FROM inventory WHERE variation_id = ? AND store_id = 1");
        $currStmt->execute([$varId]);
        $currentPhysical = $currStmt->fetchColumn();
        if ($currentPhysical === false) $currentPhysical = 0;

        // Force store_id = 1 to physicalStock
        $upd1 = $conn->prepare("
            INSERT INTO inventory (variation_id, store_id, quantity) 
            VALUES (?, 1, ?)
            ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)
        ");
        $upd1->execute([$varId, $physicalStock]);

        // Force store_id = 2 to shopeeAllocated
        $upd2 = $conn->prepare("
            INSERT INTO inventory (variation_id, store_id, quantity) 
            VALUES (?, 2, ?)
            ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)
        ");
        $upd2->execute([$varId, $shopeeAllocated]);
        
        // If physical stock changed, log it to fix history balance
        if ($currentPhysical != $physicalStock) {
            $diff = $physicalStock - $currentPhysical;
            $logStmt = $conn->prepare("
                INSERT INTO stock_movements (store_id, variation_id, type, quantity, previous_stock, new_stock, reference, remarks, created_by)
                VALUES (1, ?, 'shopee_balance_sync', ?, ?, ?, 'SYNC-ADJ', 'Shopee Allocation Sync', 1)
            ");
            $logStmt->execute([$varId, $diff, $currentPhysical, $physicalStock]);
        }

        $fixedCount++;
    }

    $conn->commit();
    echo "Successfully synced $fixedCount products.\n";

} catch (Exception $e) {
    $conn->rollBack();
    echo "Error: " . $e->getMessage();
}
echo "</pre>";

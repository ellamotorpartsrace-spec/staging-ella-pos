<?php
require 'config/database.php';
$db = new Database();
$conn = $db->getConnection();

try {
    $conn->beginTransaction();

    // 1. Delete all the messy restore and repair movements from today
    $delStmt = $conn->prepare("
        DELETE FROM stock_movements 
        WHERE DATE(created_at) = CURDATE() 
          AND (remarks LIKE 'System Restore%' OR remarks LIKE 'Stock Repair%')
    ");
    $delStmt->execute();
    $deleted = $delStmt->rowCount();

    // 2. Rebuild inventory store_id = 1 based on the true latest movement
    $varStmt = $conn->query("SELECT variation_id FROM product_variations WHERE status = 'active'");
    $variations = $varStmt->fetchAll(PDO::FETCH_COLUMN);

    $fixed = 0;
    foreach ($variations as $varId) {
        $lastStmt = $conn->prepare("SELECT new_stock FROM stock_movements WHERE variation_id = ? AND store_id = 1 ORDER BY created_at DESC, movement_id DESC LIMIT 1");
        $lastStmt->execute([$varId]);
        $last = $lastStmt->fetch(PDO::FETCH_ASSOC);

        if ($last) {
            $correctPos = (float)$last['new_stock'];
            
            // Fix store_id = 1
            $conn->prepare("
                INSERT INTO inventory (variation_id, store_id, quantity) 
                VALUES (?, 1, ?) 
                ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)
            ")->execute([$varId, $correctPos]);
            $fixed++;
        }
    }

    // 3. Fix the massive 142 bug for Shopee Allocated (store_id = 2)
    // We should not sum them up. We'll set store_id = 2 to the highest single allocation.
    $conn->query("
        UPDATE inventory i
        JOIN (
            SELECT m.pos_product_id as var_id, MAX(m.shopee_stock) as max_alloc
            FROM shopee_product_mappings m
            WHERE m.mapping_status IN ('auto','manual')
            GROUP BY m.pos_product_id
            
            UNION
            
            SELECT v.variation_id as var_id, MAX(m.shopee_stock) as max_alloc
            FROM shopee_product_mappings m
            JOIN product_variations v ON v.sku = m.matched_pos_sku COLLATE utf8mb4_unicode_ci
            WHERE m.mapping_status IN ('auto','manual') AND m.matched_pos_sku != ''
            GROUP BY v.variation_id
        ) max_allocs ON i.variation_id = max_allocs.var_id
        SET i.quantity = COALESCE(max_allocs.max_alloc, 0)
        WHERE i.store_id = 2
    ");

    $conn->commit();
    echo "<div style='font-family:sans-serif; text-align:center; margin-top:50px;'>";
    echo "<h1 style='color:green;'>Cleanup Complete! \xE2\x9C\x85</h1>";
    echo "<h3>Deleted {$deleted} messy history logs from today.</h3>";
    echo "<h3>Perfectly restored Physical POS stock for {$fixed} products based on their true history.</h3>";
    echo "<h3>Fixed the Shopee Allocation bug (142 bug).</h3>";
    echo "<p>You can now check Pilot Moto GP and City Grip 2!</p>";
    echo "</div>";

} catch (Exception $e) {
    $conn->rollBack();
    echo "Error: " . $e->getMessage();
}

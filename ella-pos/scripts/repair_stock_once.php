<?php
/**
 * scripts/repair_stock_once.php
 * ONE-TIME stock repair script — run via Hostinger Cron Job (CLI only).
 * After it runs successfully, remove it from the cron schedule.
 */

if (php_sapi_name() !== 'cli') {
    die("This script must be run via cron/CLI only.\n");
}

$baseDir = dirname(__DIR__);
require_once $baseDir . '/config/config.php';
require_once $baseDir . '/config/database.php';

$db   = new Database();
$conn = $db->getConnection();

echo "=== STOCK REPAIR SCRIPT ===\n";
echo "Run at: " . date('Y-m-d H:i:s') . "\n\n";

// Find ALL corrupted products:
// 1. Products that had a System Restore adjustment today
// 2. Products where Shopee allocated stock > total physical stock (inflated Shopee side)
$affectedStmt = $conn->query("
    SELECT DISTINCT variation_id FROM (
        SELECT variation_id
        FROM stock_movements
        WHERE store_id = 1
          AND type = 'adjustment'
          AND remarks LIKE 'System Restore%'
          AND DATE(created_at) = DATE(NOW())
        UNION
        SELECT i1.variation_id
        FROM inventory i1
        LEFT JOIN inventory i2 ON i1.variation_id = i2.variation_id AND i2.store_id = 2
        WHERE i1.store_id = 1
          AND COALESCE(i2.quantity, 0) > i1.quantity
          AND i1.quantity >= 0
    ) combined
");
$affected = $affectedStmt->fetchAll(PDO::FETCH_COLUMN);
$total    = count($affected);

echo "Found {$total} products affected by today's bad restores.\n";
echo "Processing in batches of 30...\n\n";

if (empty($affected)) {
    echo "Nothing to fix!\n";
    exit;
}

$fixedCount   = 0;
$skippedCount = 0;
$errorCount   = 0;
$ref = 'STOCK-REPAIR-' . date('Ymd-His');

$batches  = array_chunk($affected, 30);
$batchNum = 0;

foreach ($batches as $batch) {
    $batchNum++;
    echo "Batch {$batchNum}/" . count($batches) . "...\n";

    $conn->beginTransaction();

    try {
        foreach ($batch as $varId) {
            $varId = (int)$varId;

            // Find last valid movement before today's nightmare started (True Time-Travel to yesterday's closing balance)
            $lastValidStmt = $conn->prepare("
                SELECT new_stock, created_at
                FROM stock_movements
                WHERE variation_id = ?
                  AND store_id = 1
                  AND created_at < CURDATE()
                ORDER BY created_at DESC
                LIMIT 1
            ");
            $lastValidStmt->execute([$varId]);
            $lastValid = $lastValidStmt->fetch(PDO::FETCH_ASSOC);

            if (!$lastValid) {
                $skippedCount++;
                continue;
            }

            $correctTotal = max(0, (float)$lastValid['new_stock']);

            // Get current stocks
            $curStmt = $conn->prepare("SELECT COALESCE(quantity, 0) FROM inventory WHERE variation_id = ? AND store_id = 1");
            $curStmt->execute([$varId]);
            $currentPhysical = (float)$curStmt->fetchColumn();

            $curShopeeStmt = $conn->prepare("SELECT COALESCE(quantity, 0) FROM inventory WHERE variation_id = ? AND store_id = 2");
            $curShopeeStmt->execute([$varId]);
            $currentShopee = (float)$curShopeeStmt->fetchColumn();

            // Recalculate Shopee from ratio
            $skuStmt = $conn->prepare("SELECT sku FROM product_variations WHERE variation_id = ?");
            $skuStmt->execute([$varId]);
            $sku = $skuStmt->fetchColumn();

            $correctShopeeStmt = $conn->prepare("
                SELECT COALESCE(SUM(
                    FLOOR((? / COALESCE(u.multiplier, 1)) * (m.stock_allocation_ratio / 100.0))
                    * COALESCE(u.multiplier, 1)
                ), 0)
                FROM shopee_product_mappings m
                LEFT JOIN product_units u ON m.pos_unit_id = u.id
                WHERE (m.pos_product_id = ? OR (? != '' AND m.matched_pos_sku = ? COLLATE utf8mb4_unicode_ci))
                  AND m.mapping_status IN ('auto','manual')
                  AND (m.pos_bundle_set_id IS NULL OR m.pos_bundle_set_id = 0)
            ");
            $correctShopeeStmt->execute([$correctTotal, $varId, $sku ?? '', $sku ?? '']);
            $correctShopee = (float)$correctShopeeStmt->fetchColumn();

            $correctPhysicalStore = max(0, $correctTotal - $correctShopee);

            // Apply fixes
            $conn->prepare("
                INSERT INTO inventory (variation_id, store_id, quantity)
                VALUES (?, 1, ?)
                ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)
            ")->execute([$varId, $correctPhysicalStore]);

            $conn->prepare("
                INSERT INTO inventory (variation_id, store_id, quantity)
                VALUES (?, 2, ?)
                ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)
            ")->execute([$varId, $correctShopee]);

            // Fix shopee_product_mappings by pos_product_id
            $conn->prepare("
                UPDATE shopee_product_mappings m
                LEFT JOIN product_units u ON m.pos_unit_id = u.id
                SET m.shopee_stock = FLOOR(
                    (? / COALESCE(u.multiplier, 1)) * (m.stock_allocation_ratio / 100.0)
                ), m.updated_at = NOW()
                WHERE m.pos_product_id = ?
                  AND m.mapping_status IN ('auto','manual')
                  AND (m.pos_bundle_set_id IS NULL OR m.pos_bundle_set_id = 0)
            ")->execute([$correctTotal, $varId]);

            // ALSO fix mappings matched via SKU (matched_pos_sku) 
            $skuStmt = $conn->prepare("SELECT sku FROM product_variations WHERE variation_id = ?");
            $skuStmt->execute([$varId]);
            $sku = $skuStmt->fetchColumn();
            if (!empty($sku) && !in_array($sku, ['', '-', 'N/A', 'NA', 'none', 'null'])) {
                $conn->prepare("
                    UPDATE shopee_product_mappings m
                    LEFT JOIN product_units u ON m.pos_unit_id = u.id
                    SET m.shopee_stock = FLOOR(
                        (? / COALESCE(u.multiplier, 1)) * (m.stock_allocation_ratio / 100.0)
                    ), m.updated_at = NOW()
                    WHERE m.matched_pos_sku = ? COLLATE utf8mb4_unicode_ci
                      AND m.mapping_status IN ('auto','manual')
                      AND (m.pos_bundle_set_id IS NULL OR m.pos_bundle_set_id = 0)
                ")->execute([$correctTotal, $sku]);

                // Queue the corrected stock for each mapping so Shopee gets updated too
                // This prevents auto_sync from reading back the old inflated value
                $mapStmt = $conn->prepare("
                    SELECT m.shopee_item_id, m.shopee_model_id,
                           FLOOR((? / COALESCE(u.multiplier, 1)) * (m.stock_allocation_ratio / 100.0)) as new_shopee_stock
                    FROM shopee_product_mappings m
                    LEFT JOIN product_units u ON m.pos_unit_id = u.id
                    WHERE (m.pos_product_id = ? OR m.matched_pos_sku = ? COLLATE utf8mb4_unicode_ci)
                      AND m.mapping_status IN ('auto','manual')
                      AND (m.pos_bundle_set_id IS NULL OR m.pos_bundle_set_id = 0)
                ");
                $mapStmt->execute([$correctTotal, $varId, $sku]);
                $maps = $mapStmt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($maps as $map) {
                    // Remove stale queue entries first
                    $conn->prepare("DELETE FROM shopee_sync_queue WHERE shopee_item_id = ? AND shopee_model_id <=> ? AND status = 'pending'")
                         ->execute([$map['shopee_item_id'], $map['shopee_model_id']]);
                    // Queue the corrected stock
                    $conn->prepare("
                        INSERT INTO shopee_sync_queue (shopee_item_id, shopee_model_id, target_stock, status, created_at)
                        VALUES (?, ?, ?, 'pending', NOW())
                    ")->execute([$map['shopee_item_id'], $map['shopee_model_id'], max(0, (int)$map['new_shopee_stock'])]);
                }
            }


            // Audit log
            $capitalStmt = $conn->prepare("SELECT COALESCE(price_capital, 0) FROM product_variations WHERE variation_id = ?");
            $capitalStmt->execute([$varId]);
            $capital = (float)$capitalStmt->fetchColumn();

            if (abs($correctPhysicalStore - $currentPhysical) >= 0.001) {
                $conn->prepare("
                    INSERT INTO stock_movements
                    (variation_id, store_id, type, quantity, previous_stock, new_stock, reference, remarks, created_by, capital_cost)
                    VALUES (?, 1, 'adjustment', ?, ?, ?, ?, 'Stock Repair: Reset to last valid movement before bad restores', 1, ?)
                ")->execute([
                    $varId,
                    $correctPhysicalStore - $currentPhysical,
                    $currentPhysical,
                    $correctPhysicalStore,
                    $ref,
                    $capital
                ]);
            }

            $fixedCount++;
        }

        $conn->commit();

    } catch (Exception $e) {
        $conn->rollBack();
        echo "  ERROR batch {$batchNum}: " . $e->getMessage() . "\n";
        $errorCount++;
    }

    // 150ms pause between batches to keep server load gentle
    usleep(150000);
}

echo "\n=== DONE ===\n";
echo "Fixed:   {$fixedCount}\n";
echo "Skipped: {$skippedCount}\n";
echo "Errors:  {$errorCount}\n";
echo "Completed at: " . date('Y-m-d H:i:s') . "\n";

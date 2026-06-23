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

// Find all products affected by today's bad System Restore adjustments
$affectedStmt = $conn->query("
    SELECT DISTINCT variation_id
    FROM stock_movements
    WHERE store_id = 1
      AND type = 'adjustment'
      AND remarks LIKE 'System Restore%'
      AND DATE(created_at) = DATE(NOW())
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

            // Find last valid movement before today's bad restores
            $lastValidStmt = $conn->prepare("
                SELECT new_stock, created_at
                FROM stock_movements
                WHERE variation_id = ?
                  AND store_id = 1
                  AND NOT (
                        type = 'adjustment'
                        AND DATE(created_at) = DATE(NOW())
                        AND (remarks LIKE 'System Restore%' OR remarks LIKE 'Stock Repair%')
                      )
                ORDER BY created_at DESC, id DESC
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
            $correctShopeeStmt = $conn->prepare("
                SELECT COALESCE(SUM(
                    FLOOR((? / COALESCE(u.multiplier, 1)) * (m.stock_allocation_ratio / 100.0))
                    * COALESCE(u.multiplier, 1)
                ), 0)
                FROM shopee_product_mappings m
                LEFT JOIN product_units u ON m.pos_unit_id = u.id
                WHERE m.pos_product_id = ?
                  AND m.mapping_status IN ('auto','manual')
                  AND (m.pos_bundle_set_id IS NULL OR m.pos_bundle_set_id = 0)
            ");
            $correctShopeeStmt->execute([$correctTotal, $varId]);
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

            echo "  Fixed #{$varId}: {$currentPhysical} → {$correctPhysicalStore} (total={$correctTotal}, shopee={$correctShopee})\n";
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

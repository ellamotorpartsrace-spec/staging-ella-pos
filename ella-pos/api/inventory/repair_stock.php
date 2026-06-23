<?php
/**
 * api/inventory/repair_stock.php — STOCK REPAIR SCRIPT v4
 *
 * For every product touched by today's bad System Restore adjustments:
 * 1. Finds the LAST REAL movement BEFORE the bad restores
 * 2. Sets physical stock back to that correct balance
 * 3. Recalculates Shopee allocation using saved stock_allocation_ratio
 *
 * SAFE:
 * - No external Shopee API calls (will NOT trigger DDoS block)
 * - Processes in small batches of 30 with 100ms pause between batches
 * - Each batch has its own transaction (no giant lock held)
 * - Auto rollback on any error
 */

header('Content-Type: text/plain');
set_time_limit(300);

// Flush output so browser shows progress in real-time
if (ob_get_level()) ob_end_flush();
flush();

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();
$role = $_SESSION['role'] ?? '';
if (!in_array($role, ['admin', 'super_admin'])) {
    http_response_code(403);
    die('Unauthorized — Admin only.');
}

$db   = new Database();
$conn = $db->getConnection();

echo "=== STOCK REPAIR SCRIPT v4 ===\n";
echo "Run at: " . date('Y-m-d H:i:s') . "\n";
echo "Goal: Reset each product to its last REAL movement before today's bad restores.\n\n";
flush();

// ── Step 1: Find all variation_ids touched by today's bad System Restore ──────
$affectedStmt = $conn->query("
    SELECT DISTINCT variation_id
    FROM stock_movements
    WHERE store_id = 1
      AND type = 'adjustment'
      AND remarks LIKE 'System Restore%'
      AND DATE(created_at) = '2026-06-23'
");
$affected = $affectedStmt->fetchAll(PDO::FETCH_COLUMN);
$total    = count($affected);

echo "Found {$total} products affected by today's bad restores.\n";
echo "Processing in batches of 30 (with 100ms pause between batches to protect server).\n\n";
flush();

if (empty($affected)) {
    echo "Nothing to fix!\n";
    exit;
}

$fixedCount   = 0;
$skippedCount = 0;
$errorCount   = 0;
$ref = 'STOCK-REPAIR-' . date('Ymd-His');

// ── Step 2: Process in batches to avoid resource spikes ───────────────────────
$batches  = array_chunk($affected, 30);
$batchNum = 0;

foreach ($batches as $batch) {
    $batchNum++;
    echo "--- Batch {$batchNum}/" . count($batches) . " ---\n";
    flush();

    $conn->beginTransaction();

    try {
        foreach ($batch as $varId) {
            $varId = (int)$varId;

            // Find last valid physical movement BEFORE today's bad restores
            $lastValidStmt = $conn->prepare("
                SELECT new_stock, created_at
                FROM stock_movements
                WHERE variation_id = ?
                  AND store_id = 1
                  AND NOT (
                        type = 'adjustment'
                        AND DATE(created_at) = '2026-06-23'
                        AND (remarks LIKE 'System Restore%' OR remarks LIKE 'Stock Repair%')
                      )
                ORDER BY created_at DESC, id DESC
                LIMIT 1
            ");
            $lastValidStmt->execute([$varId]);
            $lastValid = $lastValidStmt->fetch(PDO::FETCH_ASSOC);

            if (!$lastValid) {
                echo "  Skipped #{$varId}: no valid movement history.\n";
                $skippedCount++;
                continue;
            }

            $correctTotal = max(0, (float)$lastValid['new_stock']);

            // Get current physical stock
            $curStmt = $conn->prepare("SELECT COALESCE(quantity, 0) FROM inventory WHERE variation_id = ? AND store_id = 1");
            $curStmt->execute([$varId]);
            $currentPhysical = (float)$curStmt->fetchColumn();

            // Get current Shopee allocated stock
            $curShopeeStmt = $conn->prepare("SELECT COALESCE(quantity, 0) FROM inventory WHERE variation_id = ? AND store_id = 2");
            $curShopeeStmt->execute([$varId]);
            $currentShopee = (float)$curShopeeStmt->fetchColumn();

            // Recalculate Shopee allocation from the saved ratio
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

            // Physical walk-in = total minus Shopee allocation
            $correctPhysicalStore = max(0, $correctTotal - $correctShopee);

            // Apply: fix physical (store_id=1)
            $conn->prepare("
                INSERT INTO inventory (variation_id, store_id, quantity)
                VALUES (?, 1, ?)
                ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)
            ")->execute([$varId, $correctPhysicalStore]);

            // Apply: fix Shopee (store_id=2)
            $conn->prepare("
                INSERT INTO inventory (variation_id, store_id, quantity)
                VALUES (?, 2, ?)
                ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)
            ")->execute([$varId, $correctShopee]);

            // Apply: fix shopee_product_mappings.shopee_stock per-mapping
            $conn->prepare("
                UPDATE shopee_product_mappings m
                LEFT JOIN product_units u ON m.pos_unit_id = u.id
                SET m.shopee_stock = FLOOR(
                    (? / COALESCE(u.multiplier, 1))
                    * (m.stock_allocation_ratio / 100.0)
                ),
                m.updated_at = NOW()
                WHERE m.pos_product_id = ?
                  AND m.mapping_status IN ('auto','manual')
                  AND (m.pos_bundle_set_id IS NULL OR m.pos_bundle_set_id = 0)
            ")->execute([$correctTotal, $varId]);

            // Log a clean repair entry for audit trail
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

            echo "  Fixed #{$varId}: Physical {$currentPhysical}→{$correctPhysicalStore} | Shopee {$currentShopee}→{$correctShopee} | Restored balance: {$correctTotal} (as of {$lastValid['created_at']})\n";
            $fixedCount++;
        }

        $conn->commit();

    } catch (Exception $e) {
        $conn->rollBack();
        echo "  ERROR in batch {$batchNum}: " . $e->getMessage() . "\n";
        echo "  Batch {$batchNum} rolled back. Continuing to next batch...\n";
        $errorCount++;
    }

    // 100ms pause between batches — keeps server load low, prevents DDoS trigger
    usleep(100000);
    flush();
}

echo "\n=== DONE ===\n";
echo "Fixed:   {$fixedCount} products\n";
echo "Skipped: {$skippedCount}\n";
echo "Errors:  {$errorCount} batches\n";
echo "\nAll affected products restored to their last real stock movement balance.\n";
echo "Shopee allocation recalculated from saved ratios.\n";
echo "You may now queue a Shopee sync from the Shopee Allocation page.\n";

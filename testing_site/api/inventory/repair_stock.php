<?php
/**
 * api/inventory/repair_stock.php — DIAGNOSTIC + REPAIR v5
 */

header('Content-Type: text/plain');
set_time_limit(300);
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

echo "=== STOCK REPAIR SCRIPT v5 ===\n";
echo "Run at: " . date('Y-m-d H:i:s') . "\n";
echo "DB Now: " . $conn->query("SELECT NOW()")->fetchColumn() . "\n";
echo "DB Date: " . $conn->query("SELECT DATE(NOW())")->fetchColumn() . "\n\n";
flush();

// ── DIAGNOSTIC: How many System Restore movements are in the DB today? ─────────
$diagStmt = $conn->query("
    SELECT COUNT(*) as cnt, MIN(created_at) as earliest, MAX(created_at) as latest
    FROM stock_movements
    WHERE store_id = 1
      AND type = 'adjustment'
      AND remarks LIKE 'System Restore%'
      AND DATE(created_at) = DATE(NOW())
");
$diag = $diagStmt->fetch(PDO::FETCH_ASSOC);
echo "System Restore movements today: {$diag['cnt']}\n";
echo "Earliest: {$diag['earliest']} | Latest: {$diag['latest']}\n\n";
flush();

// Show sample of what remarks look like
$sampleStmt = $conn->query("
    SELECT DISTINCT LEFT(remarks, 80) as sample_remark
    FROM stock_movements
    WHERE store_id = 1
      AND type = 'adjustment'
      AND DATE(created_at) = DATE(NOW())
    LIMIT 5
");
echo "Sample remarks from today:\n";
foreach ($sampleStmt->fetchAll(PDO::FETCH_COLUMN) as $remark) {
    echo "  -> [{$remark}]\n";
}
echo "\n";
flush();

// ── Step 1: Find affected products ────────────────────────────────────────────
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

// DIAGNOSTIC: spot-check product id=497 specifically
$spot = $conn->prepare("
    SELECT id, created_at, type, remarks, new_stock
    FROM stock_movements
    WHERE variation_id = 497 AND store_id = 1
    ORDER BY created_at DESC, id DESC
    LIMIT 5
");
$spot->execute();
echo "\nDIAGNOSTIC - Last 5 movements for #497:\n";
foreach ($spot->fetchAll(PDO::FETCH_ASSOC) as $row) {
    echo "  [{$row['created_at']}] {$row['type']} | new_stock={$row['new_stock']} | {$row['remarks']}\n";
}
echo "\n";
flush();

if (empty($affected)) {
    echo "Nothing to fix — the query found 0 products.\n";
    echo "This means the System Restore movements were not recorded with today's date,\n";
    echo "or the remarks don't start with 'System Restore'.\n";
    exit;
}

echo "Processing in batches of 30 with 100ms pause.\n\n";
flush();

$fixedCount   = 0;
$skippedCount = 0;
$errorCount   = 0;
$ref = 'STOCK-REPAIR-' . date('Ymd-His');

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

            // Last valid physical movement — exclude today's System Restore + Stock Repair adjustments
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
                echo "  Skipped #{$varId}: no valid movement history.\n";
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

            echo "  Fixed #{$varId}: Physical {$currentPhysical}→{$correctPhysicalStore} | Shopee {$currentShopee}→{$correctShopee} | Total restored: {$correctTotal} ({$lastValid['created_at']})\n";
            $fixedCount++;
        }

        $conn->commit();

    } catch (Exception $e) {
        $conn->rollBack();
        echo "  ERROR in batch {$batchNum}: " . $e->getMessage() . "\n";
        $errorCount++;
    }

    usleep(100000);
    flush();
}

echo "\n=== DONE ===\n";
echo "Fixed:   {$fixedCount}\n";
echo "Skipped: {$skippedCount}\n";
echo "Errors:  {$errorCount}\n";

<?php
/**
 * api/inventory/repair_stock.php — STOCK REPAIR SCRIPT v4
 *
 * For every product that was touched by today's bad System Restore adjustments,
 * this script finds the LAST REAL movement BEFORE the bad restores and
 * sets the physical stock back to that correct balance.
 *
 * Then it recalculates the Shopee allocation using the saved stock_allocation_ratio.
 *
 * Safe: wrapped in a transaction. If anything fails, NOTHING changes.
 */

header('Content-Type: text/plain');
set_time_limit(300);

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
echo "Goal: Set each product's stock to its last REAL movement before today's bad restores.\n\n";

// ── Step 1: Find all variation_ids that were touched by today's bad restores ──
$affectedStmt = $conn->query("
    SELECT DISTINCT variation_id
    FROM stock_movements
    WHERE store_id = 1
      AND type = 'adjustment'
      AND remarks LIKE 'System Restore%'
      AND DATE(created_at) = '2026-06-23'
");
$affected = $affectedStmt->fetchAll(PDO::FETCH_COLUMN);

echo "Found " . count($affected) . " products affected by today's bad restores.\n\n";

if (empty($affected)) {
    echo "Nothing to fix!\n";
    exit;
}

$fixedCount   = 0;
$skippedCount = 0;
$ref = 'STOCK-REPAIR-' . date('Ymd-His');

$conn->beginTransaction();

try {
    foreach ($affected as $varId) {
        $varId = (int)$varId;

        // ── Step 2: Find last valid physical movement BEFORE today's bad restores ──
        // Exclude: System Restore adjustments from today AND any Stock Repair adjustments
        $lastValidStmt = $conn->prepare("
            SELECT new_stock, created_at, type, remarks
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
            echo "Skipped #{$varId}: no valid movement history found.\n";
            $skippedCount++;
            continue;
        }

        $correctPhysical = max(0, (float)$lastValid['new_stock']);

        // ── Step 3: Get current physical stock ────────────────────────────────
        $curStmt = $conn->prepare("SELECT COALESCE(quantity, 0) FROM inventory WHERE variation_id = ? AND store_id = 1");
        $curStmt->execute([$varId]);
        $currentPhysical = (float)$curStmt->fetchColumn();

        // ── Step 4: Recalculate Shopee allocation using ratio ──────────────────
        // Total stock = the correct physical amount (since physical history tracks the real total)
        $totalStock = $correctPhysical;

        $correctShopeeStmt = $conn->prepare("
            SELECT COALESCE(SUM(
                FLOOR((? / COALESCE(u.multiplier, 1)) * (m.stock_allocation_ratio / 100.0))
                * COALESCE(u.multiplier, 1)
            ), 0) as correct_shopee
            FROM shopee_product_mappings m
            LEFT JOIN product_units u ON m.pos_unit_id = u.id
            WHERE m.pos_product_id = ?
              AND m.mapping_status IN ('auto','manual')
              AND (m.pos_bundle_set_id IS NULL OR m.pos_bundle_set_id = 0)
        ");
        $correctShopeeStmt->execute([$totalStock, $varId]);
        $correctShopee = (float)$correctShopeeStmt->fetchColumn();

        // Physical store holds everything MINUS what's reserved for Shopee
        $correctPhysicalStore = max(0, $correctPhysical - $correctShopee);

        $currentShopeeStmt = $conn->prepare("SELECT COALESCE(quantity, 0) FROM inventory WHERE variation_id = ? AND store_id = 2");
        $currentShopeeStmt->execute([$varId]);
        $currentShopee = (float)$currentShopeeStmt->fetchColumn();

        // ── Step 5: Apply corrections ──────────────────────────────────────────
        // Fix physical store (store_id = 1)
        $conn->prepare("
            INSERT INTO inventory (variation_id, store_id, quantity)
            VALUES (?, 1, ?)
            ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)
        ")->execute([$varId, $correctPhysicalStore]);

        // Fix Shopee allocated (store_id = 2)
        $conn->prepare("
            INSERT INTO inventory (variation_id, store_id, quantity)
            VALUES (?, 2, ?)
            ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)
        ")->execute([$varId, $correctShopee]);

        // Fix shopee_product_mappings.shopee_stock per-mapping
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
        ")->execute([$totalStock, $varId]);

        // Log a clean repair entry
        $capitalStmt = $conn->prepare("SELECT price_capital FROM product_variations WHERE variation_id = ?");
        $capitalStmt->execute([$varId]);
        $capital = (float)($capitalStmt->fetchColumn() ?? 0);

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

        echo "Fixed #{$varId}: Physical {$currentPhysical}→{$correctPhysicalStore} | Shopee {$currentShopee}→{$correctShopee} | Last valid: '{$lastValid['created_at']}' (balance was {$correctPhysical})\n";
        $fixedCount++;
    }

    $conn->commit();

    echo "\n=== DONE ===\n";
    echo "Fixed:   {$fixedCount} products\n";
    echo "Skipped: {$skippedCount}\n";
    echo "\nAll affected products restored to their last real stock movement balance.\n";
    echo "Shopee allocation recalculated from saved ratios.\n";

} catch (Exception $e) {
    $conn->rollBack();
    echo "\nERROR: " . $e->getMessage() . "\n";
    echo "All changes ROLLED BACK. Nothing changed.\n";
}

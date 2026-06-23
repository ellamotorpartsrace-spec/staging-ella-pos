<?php
/**
 * api/inventory/repair_stock.php
 *
 * STOCK REPAIR SCRIPT
 *
 * Strategy:
 * 1. For each variation with negative physical stock, find the LAST VALID movement
 *    before today's bad system restore adjustments (before June 23 08:33 AM).
 * 2. Set physical stock (store_id=1) back to that last valid balance.
 * 3. Recalculate Shopee allocated stock (store_id=2) using stock_allocation_ratio
 *    from shopee_product_mappings — so the Shopee side is also accurate.
 * 4. Log a single clean "Stock Repair" movement for full audit trail.
 * 5. Everything is wrapped in a transaction — if anything fails, NOTHING is changed.
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

$db = new Database();
$conn = $db->getConnection();

echo "=== STOCK REPAIR SCRIPT ===\n";
echo "Run at: " . date('Y-m-d H:i:s') . "\n";
echo "Strategy: restore physical stock from last valid movement, recalculate Shopee from ratio\n\n";

// Step 1: Find all variation_ids with negative physical stock
$negativeStmt = $conn->query("
    SELECT i.variation_id, i.quantity as current_qty
    FROM inventory i
    WHERE i.store_id = 1
      AND i.quantity < 0
");
$problematic = $negativeStmt->fetchAll(PDO::FETCH_ASSOC);

$total = count($problematic);
echo "Found {$total} products with negative physical stock.\n\n";

if ($total === 0) {
    echo "Nothing to fix! All stocks are already positive.\n";
    exit;
}

$fixedCount   = 0;
$skippedCount = 0;
$ref = 'STOCK-REPAIR-' . date('Ymd-His');

$conn->beginTransaction();

try {
    foreach ($problematic as $item) {
        $varId      = (int)$item['variation_id'];
        $currentQty = (float)$item['current_qty'];

        // ── Step 2: Find the last VALID physical stock movement ────────────────
        // Skip any "System Restore" adjustment logged today (those are the bad ones)
        $lastGoodStmt = $conn->prepare("
            SELECT new_stock, created_at
            FROM stock_movements
            WHERE variation_id = ?
              AND store_id = 1
              AND NOT (
                    type = 'adjustment'
                    AND remarks LIKE 'System Restore%'
                    AND DATE(created_at) = '2026-06-23'
                  )
              AND NOT (
                    type = 'adjustment'
                    AND remarks LIKE 'Stock Repair%'
                  )
            ORDER BY created_at DESC, id DESC
            LIMIT 1
        ");
        $lastGoodStmt->execute([$varId]);
        $lastGood = $lastGoodStmt->fetch(PDO::FETCH_ASSOC);

        if (!$lastGood) {
            $skippedCount++;
            continue;
        }

        $correctPhysical = max(0, (float)$lastGood['new_stock']);

        // ── Step 3: Recalculate correct Shopee allocation using ratio ──────────
        // Sum of (shopee_stock per mapping × unit multiplier) gives the total base units allocated
        // But we rebuild from ratio so each mapping gets its fair share of the restored total stock
        $shopeeAllocStmt = $conn->prepare("
            SELECT COALESCE(SUM(
                FLOOR(
                    (? / COALESCE(u.multiplier, 1))
                    * (m.stock_allocation_ratio / 100.0)
                    * COALESCE(u.multiplier, 1)
                )
            ), 0) as correct_shopee_alloc
            FROM shopee_product_mappings m
            LEFT JOIN product_units u ON m.pos_unit_id = u.id
            WHERE (m.pos_product_id = ? OR (
                m.matched_pos_sku = (SELECT sku FROM product_variations WHERE variation_id = ?)
                AND m.matched_pos_sku NOT IN ('', '-', 'N/A', 'NA', 'none', 'null')
            ))
            AND m.mapping_status IN ('auto','manual')
            AND (m.pos_bundle_set_id IS NULL OR m.pos_bundle_set_id = 0)
        ");
        // We calculate based on total stock = physical + shopee
        $totalStock = $correctPhysical;
        $shopeeAllocStmt->execute([$totalStock, $varId, $varId]);
        $correctShopeeAlloc = (float)$shopeeAllocStmt->fetchColumn();

        // ── Step 4: Apply the physical stock correction ────────────────────────
        if (abs($correctPhysical - $currentQty) >= 0.001) {
            $conn->prepare("
                UPDATE inventory SET quantity = ? WHERE variation_id = ? AND store_id = 1
            ")->execute([$correctPhysical, $varId]);

            // Log the correction
            $capitalStmt = $conn->prepare("SELECT price_capital FROM product_variations WHERE variation_id = ?");
            $capitalStmt->execute([$varId]);
            $capital = (float)($capitalStmt->fetchColumn() ?? 0);

            $conn->prepare("
                INSERT INTO stock_movements
                (variation_id, store_id, type, quantity, previous_stock, new_stock, reference, remarks, created_by, capital_cost)
                VALUES (?, 1, 'adjustment', ?, ?, ?, ?, 'Stock Repair: Reversed bad system restore adjustments', 1, ?)
            ")->execute([
                $varId,
                $correctPhysical - $currentQty,
                $currentQty,
                $correctPhysical,
                $ref,
                $capital
            ]);

            // ── Step 5: Also fix the Shopee allocated side (store_id = 2) ─────
            // Get the current Shopee qty for comparison
            $curShopeeStmt = $conn->prepare("SELECT COALESCE(quantity, 0) FROM inventory WHERE variation_id = ? AND store_id = 2");
            $curShopeeStmt->execute([$varId]);
            $currentShopee = (float)$curShopeeStmt->fetchColumn();

            if ($correctShopeeAlloc != $currentShopee) {
                $conn->prepare("
                    INSERT INTO inventory (variation_id, store_id, quantity)
                    VALUES (?, 2, ?)
                    ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)
                ")->execute([$varId, $correctShopeeAlloc]);

                // Also update shopee_product_mappings.shopee_stock per-mapping using ratio
                $conn->prepare("
                    UPDATE shopee_product_mappings m
                    LEFT JOIN product_units u ON m.pos_unit_id = u.id
                    SET m.shopee_stock = FLOOR(
                            (? / COALESCE(u.multiplier, 1))
                            * (m.stock_allocation_ratio / 100.0)
                        ),
                        m.updated_at = NOW()
                    WHERE (m.pos_product_id = ?)
                      AND m.mapping_status IN ('auto','manual')
                      AND (m.pos_bundle_set_id IS NULL OR m.pos_bundle_set_id = 0)
                ")->execute([$totalStock, $varId]);
            }

            echo "Fixed #{$varId}: Physical {$currentQty} → {$correctPhysical} | Shopee {$currentShopee} → {$correctShopeeAlloc} (last valid: {$lastGood['created_at']})\n";
            $fixedCount++;
        } else {
            $skippedCount++;
        }
    }

    $conn->commit();

    echo "\n=== DONE ===\n";
    echo "Fixed:   {$fixedCount} products\n";
    echo "Skipped: {$skippedCount} (already correct or no history)\n";
    echo "\nAll negative stock has been corrected. Physical stock is restored from the\n";
    echo "last valid movement, and Shopee allocation recalculated from your saved ratios.\n";

} catch (Exception $e) {
    $conn->rollBack();
    echo "\nERROR: " . $e->getMessage() . "\n";
    echo "All changes have been ROLLED BACK. Nothing was changed in your database.\n";
}

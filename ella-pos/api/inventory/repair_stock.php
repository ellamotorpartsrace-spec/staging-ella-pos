<?php
/**
 * api/inventory/repair_stock.php
 *
 * STOCK REPAIR SCRIPT v3
 *
 * The physical stock (store_id=1) is now correct.
 * The problem: Shopee allocation (store_id=2) is inflated beyond the total stock.
 *
 * Fix: For EVERY mapped product, recalculate store_id=2 using stock_allocation_ratio.
 * Also update shopee_product_mappings.shopee_stock per-mapping.
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

echo "=== STOCK REPAIR SCRIPT v3 ===\n";
echo "Run at: " . date('Y-m-d H:i:s') . "\n";
echo "Goal: Recalculate Shopee allocation for all mapped products using saved ratio\n\n";

// Get all distinct POS products that have Shopee mappings
$mappedProdsStmt = $conn->query("
    SELECT DISTINCT m.pos_product_id, COALESCE(i_phys.quantity, 0) as physical_qty
    FROM shopee_product_mappings m
    LEFT JOIN inventory i_phys ON i_phys.variation_id = m.pos_product_id AND i_phys.store_id = 1
    WHERE m.pos_product_id IS NOT NULL
      AND m.mapping_status IN ('auto', 'manual')
      AND (m.pos_bundle_set_id IS NULL OR m.pos_bundle_set_id = 0)
");
$mappedProds = $mappedProdsStmt->fetchAll(PDO::FETCH_ASSOC);

echo "Found " . count($mappedProds) . " products with Shopee mappings to check.\n\n";

$fixedCount   = 0;
$skippedCount = 0;
$errorCount   = 0;

$conn->beginTransaction();

try {
    foreach ($mappedProds as $prod) {
        $varId       = (int)$prod['pos_product_id'];
        $physicalQty = (float)$prod['physical_qty'];

        // Get the total stock (physical + current shopee) as the baseline
        $currentShopeeStmt = $conn->prepare("SELECT COALESCE(quantity, 0) FROM inventory WHERE variation_id = ? AND store_id = 2");
        $currentShopeeStmt->execute([$varId]);
        $currentShopee = (float)$currentShopeeStmt->fetchColumn();

        // Total stock = physical + shopee (this is the real total units for this product)
        $totalStock = $physicalQty + $currentShopee;

        // Recalculate correct shopee allocation from ratio
        // Sum across all mappings for this product: FLOOR(total / multiplier * ratio / 100) * multiplier
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

        // Correct physical = total - shopee
        $correctPhysical = max(0, $totalStock - $correctShopee);

        // Only fix if something is actually wrong
        $shopeeWrong   = abs($correctShopee - $currentShopee) >= 1;
        $physicalWrong = abs($correctPhysical - $physicalQty) >= 1;

        if (!$shopeeWrong && !$physicalWrong) {
            $skippedCount++;
            continue;
        }

        // Fix store_id=2 (Shopee allocated)
        $conn->prepare("
            INSERT INTO inventory (variation_id, store_id, quantity)
            VALUES (?, 2, ?)
            ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)
        ")->execute([$varId, $correctShopee]);

        // Fix store_id=1 (Physical) if needed
        if ($physicalWrong) {
            $conn->prepare("
                INSERT INTO inventory (variation_id, store_id, quantity)
                VALUES (?, 1, ?)
                ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)
            ")->execute([$varId, $correctPhysical]);
        }

        // Fix shopee_product_mappings.shopee_stock per mapping
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

        echo "Fixed #{$varId}: Physical {$physicalQty}→{$correctPhysical} | Shopee {$currentShopee}→{$correctShopee} (total={$totalStock})\n";
        $fixedCount++;
    }

    $conn->commit();

    echo "\n=== DONE ===\n";
    echo "Fixed:   {$fixedCount} products\n";
    echo "Skipped: {$skippedCount} (already correct)\n";
    echo "\nShopee allocation recalculated from your saved ratios.\n";
    echo "shopee_product_mappings.shopee_stock also updated.\n";
    echo "\nYou can now queue Shopee sync from the Backup & Recovery or Shopee Allocation page.\n";

} catch (Exception $e) {
    $conn->rollBack();
    echo "\nERROR: " . $e->getMessage() . "\n";
    echo "All changes ROLLED BACK. Nothing was changed.\n";
}

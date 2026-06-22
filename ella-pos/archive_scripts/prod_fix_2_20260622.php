<?php
/**
 * PRODUCTION FIX SCRIPT PHASE 2
 * =============================
 * Rebuilds the true chronological running stock chains for ALL stores.
 * Updates inventory balances.
 * Syncs Lazada/Shopee mapping caches.
 */
define('FIX_KEY', 'ELLA2026FIX');

if (($_GET['key'] ?? '') !== FIX_KEY && php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('<h1>403 Forbidden</h1><p>Provide ?key= to run this script.</p>');
}

$root = __DIR__;
require_once $root . '/config/config.php';
require_once $root . '/config/database.php';

$db   = new Database();
$conn = $db->getConnection();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function logLine($msg, $type = 'info') {
    echo $msg . "\n";
}

echo "<h2>🔧 Running Final Stock Chain Reconstruction</h2><pre>\n";

try {
    $conn->beginTransaction();

    // 1. Get all variation IDs
    $varStmt = $conn->query("SELECT DISTINCT variation_id FROM stock_movements WHERE status = 'active'");
    $varIds = $varStmt->fetchAll(PDO::FETCH_COLUMN);

    $updMov = $conn->prepare("UPDATE stock_movements SET previous_stock = ?, new_stock = ? WHERE movement_id = ?");

    $totalFixedMovements = 0;
    $totalFixedInventory = 0;
    $totalProcessed = 0;

    $finalBalances = [];

    foreach ($varIds as $varId) {
        $finalBalances[$varId] = [1 => 0.0, 2 => 0.0, 3 => 0.0];

        foreach ([1, 2, 3] as $storeId) {
            $movStmt = $conn->prepare("
                SELECT movement_id, type, quantity, previous_stock, new_stock
                FROM stock_movements
                WHERE variation_id = ? AND store_id = ? AND status = 'active'
                ORDER BY created_at ASC, movement_id ASC
            ");
            $movStmt->execute([$varId, $storeId]);
            $movements = $movStmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($movements)) {
                continue;
            }

            // Start from the first previous_stock
            $runningBalance = max(0.0, (float)$movements[0]['previous_stock']);

            foreach ($movements as $m) {
                $movId = $m['movement_id'];
                $qty = (float)$m['quantity'];
                $type = $m['type'];

                if ($type === 'adjustment' || $type === 'online_adjustment') {
                    // Exact change. For adjustments, we just use the quantity exactly as it is (+ or -)
                    $change = $qty;
                } elseif (in_array($type, ['allocation_to_online', 'allocation_to_physical'])) {
                    // For allocation syncs, the previous fixes saved it as - or + correctly
                    $change = $qty;
                } elseif (in_array($type, ['sales', 'stock_out', 'online_sale'])) {
                    $change = -abs($qty);
                } elseif (in_array($type, ['stock_in', 'return'])) {
                    $change = abs($qty);
                } else {
                    $change = $qty;
                }

                $prevStock = $runningBalance;
                $newStock = $runningBalance + $change;
                if ($newStock < 0) {
                    $newStock = 0.0;
                }

                if (abs((float)$m['previous_stock'] - $prevStock) > 0.01 || abs((float)$m['new_stock'] - $newStock) > 0.01) {
                    $updMov->execute([$prevStock, $newStock, $movId]);
                    $totalFixedMovements++;
                }

                $runningBalance = $newStock;
            }

            $finalBalances[$varId][$storeId] = $runningBalance;
        }
        $totalProcessed++;
    }

    logLine("Successfully rebuilt {$totalProcessed} variations' stock movement chains ✓");
    logLine("Fixed {$totalFixedMovements} movement records ✓");

    // 2. Reconcile inventory table
    logLine("Reconciling inventory tables and mapping stock caches...");

    $updInv = $conn->prepare("
        INSERT INTO inventory (variation_id, store_id, quantity) 
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)
    ");

    foreach ($finalBalances as $varId => $bal) {
        foreach ([1, 2, 3] as $storeId) {
            $qty = $bal[$storeId];
            $current = $conn->query("SELECT quantity FROM inventory WHERE variation_id = {$varId} AND store_id = {$storeId}")->fetchColumn();
            if ($current === false || abs((float)$current - $qty) > 0.01) {
                $updInv->execute([$varId, $storeId, $qty]);
                $totalFixedInventory++;
            }
        }
    }
    
    // Check if stock_allocation_ratio column exists in shopee_product_mappings
    $shopeeHasRatio = false;
    try {
        $q = $conn->query("SHOW COLUMNS FROM shopee_product_mappings LIKE 'stock_allocation_ratio'");
        $shopeeHasRatio = ($q && $q->rowCount() > 0);
    } catch (Exception $e) {}

    $lazadaHasRatio = false;
    try {
        $q = $conn->query("SHOW COLUMNS FROM lazada_product_mappings LIKE 'stock_allocation_ratio'");
        $lazadaHasRatio = ($q && $q->rowCount() > 0);
    } catch (Exception $e) {}

    $shopeeRatioCol = $shopeeHasRatio ? "COALESCE(stock_allocation_ratio, 100)" : "100";
    $conn->exec("
        UPDATE shopee_product_mappings m
        LEFT JOIN (
            SELECT i1.variation_id, i1.quantity as pos_physical_qty, COALESCE(i2.quantity, 0) as pos_shopee_qty
            FROM inventory i1
            LEFT JOIN inventory i2 ON i1.variation_id = i2.variation_id AND i2.store_id = 2
            WHERE i1.store_id = 1
        ) inv ON m.pos_product_id = inv.variation_id
        SET m.shopee_stock = FLOOR((COALESCE(inv.pos_physical_qty, 0) + COALESCE(inv.pos_shopee_qty, 0)) * ($shopeeRatioCol / 100)), 
            m.updated_at = NOW()
        WHERE m.mapping_status IN ('auto', 'manual')
          AND (m.pos_bundle_set_id IS NULL OR m.pos_bundle_set_id = 0)
    ");

    $lazadaRatioCol = $lazadaHasRatio ? "COALESCE(stock_allocation_ratio, 100)" : "100";
    $conn->exec("
        UPDATE lazada_product_mappings m
        LEFT JOIN (
            SELECT i1.variation_id, i1.quantity as pos_physical_qty, COALESCE(i3.quantity, 0) as pos_lazada_qty
            FROM inventory i1
            LEFT JOIN inventory i3 ON i1.variation_id = i3.variation_id AND i3.store_id = 3
            WHERE i1.store_id = 1
        ) inv ON m.pos_product_id = inv.variation_id
        SET m.lazada_stock = FLOOR((COALESCE(inv.pos_physical_qty, 0) + COALESCE(inv.pos_lazada_qty, 0)) * ($lazadaRatioCol / 100)), 
            m.updated_at = NOW()
        WHERE m.mapping_status IN ('auto', 'manual')
          AND (m.pos_bundle_set_id IS NULL OR m.pos_bundle_set_id = 0)
    ");

    $conn->commit();
    logLine("Successfully reconciled {$totalFixedInventory} inventory records ✓");

} catch (Exception $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    logLine("ERROR: " . $e->getMessage(), 'err');
}

echo "</pre>";

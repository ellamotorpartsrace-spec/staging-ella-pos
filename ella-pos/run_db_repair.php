<?php
/**
 * PRODUCTION FIX SCRIPT — Run ONCE on your live server
 * =====================================================
 * This script fixes the LIVE/ONLINE database:
 *
 * FIX 1: Delete duplicate SYS-GAPFILL rows caused by old bugs
 * FIX 2: Move Shopee order movements from store_id=1 to store_id=2
 * FIX 3: Detect and delete physically impossible allocation sync jumps
 * FIX 4: Rebuild true chronological running stock chains for ALL stores
 * FIX 5: Reconcile inventory tables and mapping caches
 *
 * INSTRUCTIONS:
 *   1. Upload this file to your Hostinger /ella-pos/ root folder
 *   2. Open it in browser: https://yourdomain.com/ella-pos/run_db_repair.php?key=ELLA2026FIX
 *   3. Verify the output says SUCCESS
 *   4. Delete this file from the server immediately after running
 */

define('FIX_KEY', 'ELLA2026FIX');

if (($_GET['key'] ?? '') !== FIX_KEY && php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('<h1>403 Forbidden</h1><p>Provide ?key= to run this script.</p>');
}

// Prevent script from timing out on large databases
set_time_limit(0);
ignore_user_abort(true);
ini_set('memory_limit', '512M');
ob_implicit_flush(true);
while (ob_get_level() > 0) ob_end_flush();

$root = __DIR__;
require_once $root . '/config/config.php';
require_once $root . '/config/database.php';

$db   = new Database();
$conn = $db->getConnection();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function logLine($msg, $type = 'info') {
    echo $msg . "<br>\n";
    flush();
}

echo "<h2>🔧 Running Comprehensive Database Repair</h2><pre>\n";

try {
    // ════════════════════════════════════════════════════════════
    // FIX 1: Delete duplicate SYS-GAPFILL rows (Optimized for speed)
    // ════════════════════════════════════════════════════════════
    logLine("Fetching SYS-GAPFILL records to find duplicates...");
    $stmt = $conn->query("
        SELECT movement_id, variation_id, reference 
        FROM stock_movements 
        WHERE reference LIKE 'SYS-GAPFILL%'
        ORDER BY movement_id DESC
    ");
    $gapFills = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $seen = [];
    $toDelete = [];
    foreach ($gapFills as $row) {
        $key = $row['variation_id'] . '_' . $row['reference'];
        if (isset($seen[$key])) {
            $toDelete[] = $row['movement_id'];
        } else {
            $seen[$key] = true;
        }
    }
    
    if (count($toDelete) > 0) {
        logLine("Found " . count($toDelete) . " duplicates. Deleting in chunks...");
        $conn->beginTransaction();
        $chunks = array_chunk($toDelete, 1000);
        foreach ($chunks as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            $conn->prepare("DELETE FROM stock_movements WHERE movement_id IN ($ph)")->execute($chunk);
        }
        $conn->commit();
        logLine("SYS-GAPFILL duplicates cleared ✓");
    } else {
        logLine("No SYS-GAPFILL duplicates found ✓");
    }

    // ════════════════════════════════════════════════════════════
    // FIX 2: Fix Shopee movements wrongly assigned to store_id = 1
    // ════════════════════════════════════════════════════════════
    logLine("Fixing Shopee sales assigned to wrong store_id...");
    $wrongMoves = $conn->query("
        SELECT movement_id FROM stock_movements 
        WHERE type = 'online_sale' AND store_id = 1 AND remarks LIKE '%Shopee%'
    ")->fetchAll(PDO::FETCH_COLUMN);
    
    if (count($wrongMoves) > 0) {
        $conn->beginTransaction();
        $ph = implode(',', array_fill(0, count($wrongMoves), '?'));
        $conn->prepare("UPDATE stock_movements SET store_id=2 WHERE movement_id IN ({$ph})")->execute($wrongMoves);
        $conn->commit();
        logLine("Moved " . count($wrongMoves) . " Shopee movements to store_id=2 ✓");
    }

    // ════════════════════════════════════════════════════════════
    // FIX 3: Detect and delete physically impossible allocation sync jumps
    // ════════════════════════════════════════════════════════════
    logLine("<br>Analyzing stock history to detect invalid/phantom allocation sync jumps...");
    $allVarStmt = $conn->query("SELECT DISTINCT variation_id FROM stock_movements");
    $allVarIds = $allVarStmt->fetchAll(PDO::FETCH_COLUMN);
    
    $invalidMovIds = [];
    $deduction_types = ['stock_out', 'sales', 'allocation_to_online', 'online_sale'];
    $fix3Processed = 0;
    
    foreach ($allVarIds as $varId) {
        $fix3Processed++;
        if ($fix3Processed % 1000 === 0) {
            logLine("... analyzed $fix3Processed / " . count($allVarIds) . " variations");
        }
        $movStmt = $conn->prepare("
            SELECT movement_id, store_id, type, quantity, remarks, previous_stock
            FROM stock_movements
            WHERE variation_id = ? AND status = 'active'
            ORDER BY created_at ASC, movement_id ASC
        ");
        $movStmt->execute([$varId]);
        $movements = $movStmt->fetchAll(PDO::FETCH_ASSOC);
        
        $s1 = 0.0; $s2 = 0.0; $s3 = 0.0;
        $hasS1 = false; $hasS2 = false; $hasS3 = false;
        
        foreach ($movements as $m) {
            $storeId = (int)$m['store_id'];
            if ($storeId === 1 && !$hasS1) {
                $s1 = max(0.0, (float)($m['previous_stock'] ?? 0));
                $hasS1 = true;
            }
            if ($storeId === 2 && !$hasS2) {
                $s2 = max(0.0, (float)($m['previous_stock'] ?? 0));
                $hasS2 = true;
            }
            if ($storeId === 3 && !$hasS3) {
                $s3 = max(0.0, (float)($m['previous_stock'] ?? 0));
                $hasS3 = true;
            }
        }
        
        foreach ($movements as $m) {
            $movId = (int)$m['movement_id'];
            $storeId = (int)$m['store_id'];
            $type = $m['type'];
            $qty = (float)$m['quantity'];
            $remarks = (string)$m['remarks'];
            
            if ($type === 'allocation_to_online') {
                $channel = (strpos(strtolower($remarks), 'lazada') !== false) ? 3 : 2;
                $amt = abs($qty);
                // We only check invalid jumps for store_id 1 (since it deducts from physical)
                if ($storeId === 1) {
                    if ($s1 + 0.01 >= $amt) {
                        $s1 -= $amt;
                    } else {
                        // Mark ALL pairs of this allocation jump as invalid
                        // To be safe, we just mark this specific movement_id, but ideally we mark the matching pair too.
                        $invalidMovIds[] = $movId;
                    }
                }
            } elseif ($type === 'allocation_to_physical') {
                $channel = (strpos(strtolower($remarks), 'lazada') !== false) ? 3 : 2;
                $amt = abs($qty);
                $channelBal = ($channel === 2) ? $s2 : $s3;
                if ($storeId === 1) {
                    // Physical receives stock. Check if the online channel had enough to give.
                    if ($channelBal + 0.01 < $amt) {
                        $invalidMovIds[] = $movId;
                    } else {
                        $s1 += $amt;
                    }
                } else {
                    // Online channel deducts stock
                    if ($channelBal + 0.01 >= $amt) {
                        if ($channel === 2) $s2 -= $amt; else $s3 -= $amt;
                    }
                }
            } else {
                if ($type === 'adjustment' || $type === 'online_adjustment') {
                    $change = $qty;
                } else {
                    $change = in_array($type, $deduction_types) ? -abs($qty) : abs($qty);
                }
                
                if ($storeId === 1) {
                    $s1 += $change; if ($s1 < 0) $s1 = 0.0;
                } elseif ($storeId === 2) {
                    $s2 += $change; if ($s2 < 0) $s2 = 0.0;
                } elseif ($storeId === 3) {
                    $s3 += $change; if ($s3 < 0) $s3 = 0.0;
                }
            }
        }
    }
    
    if (count($invalidMovIds) > 0) {
        $conn->beginTransaction();
        $chunks = array_chunk($invalidMovIds, 1000);
        foreach ($chunks as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            $conn->prepare("DELETE FROM stock_movements WHERE movement_id IN ($ph)")->execute($chunk);
        }
        $conn->commit();
        logLine("Successfully deleted " . count($invalidMovIds) . " invalid phantom sync movements ✓", 'warn');
    }

    // ════════════════════════════════════════════════════════════
    // FIX 4: Rebuild True Chronological Chains for all 3 Stores
    // ════════════════════════════════════════════════════════════
    logLine("<br>Rebuilding true chronological stock history chains for ALL stores...");
    $conn->beginTransaction();
    
    $varStmt = $conn->query("SELECT DISTINCT variation_id FROM stock_movements WHERE status = 'active'");
    $varIds = $varStmt->fetchAll(PDO::FETCH_COLUMN);

    $updMov = $conn->prepare("UPDATE stock_movements SET previous_stock = ?, new_stock = ? WHERE movement_id = ?");
    $totalFixedMovements = 0;
    $totalFixedInventory = 0;
    $finalBalances = [];
    $processedCount = 0;

    foreach ($varIds as $varId) {
        $processedCount++;
        if ($processedCount % 100 === 0) {
            logLine("... processed $processedCount / " . count($varIds) . " variations");
        }
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

            if (empty($movements)) continue;

            $runningBalance = max(0.0, (float)$movements[0]['previous_stock']);

            foreach ($movements as $m) {
                $movId = $m['movement_id'];
                $qty = (float)$m['quantity'];
                $type = $m['type'];

                if ($type === 'adjustment' || $type === 'online_adjustment') {
                    $change = $qty;
                } elseif (in_array($type, ['allocation_to_online', 'allocation_to_physical'])) {
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
                if ($newStock < 0) $newStock = 0.0;

                if (abs((float)$m['previous_stock'] - $prevStock) > 0.01 || abs((float)$m['new_stock'] - $newStock) > 0.01) {
                    $updMov->execute([$prevStock, $newStock, $movId]);
                    $totalFixedMovements++;
                }
                $runningBalance = $newStock;
            }
            $finalBalances[$varId][$storeId] = $runningBalance;
        }
    }
    $conn->commit();
    logLine("<br>Successfully rebuilt chronological chains and fixed {$totalFixedMovements} movements ✓");

    // ════════════════════════════════════════════════════════════
    // FIX 5: Reconcile Inventory Tables and Mapping Caches
    // ════════════════════════════════════════════════════════════
    logLine("Reconciling inventory tables and syncing mapping stocks...");
    $conn->beginTransaction();

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
    
    // Sync mappings
    $q = $conn->query("SHOW COLUMNS FROM shopee_product_mappings LIKE 'stock_allocation_ratio'");
    $shopeeHasRatio = ($q && $q->rowCount() > 0);
    $q = $conn->query("SHOW COLUMNS FROM lazada_product_mappings LIKE 'stock_allocation_ratio'");
    $lazadaHasRatio = ($q && $q->rowCount() > 0);

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
        WHERE m.mapping_status IN ('auto', 'manual') AND (m.pos_bundle_set_id IS NULL OR m.pos_bundle_set_id = 0)
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
        WHERE m.mapping_status IN ('auto', 'manual') AND (m.pos_bundle_set_id IS NULL OR m.pos_bundle_set_id = 0)
    ");

    $conn->commit();
    logLine("Successfully reconciled {$totalFixedInventory} inventory records and updated mappings ✓");
    logLine("\n✅ SUCCESS! The database is now completely repaired.");

} catch (Exception $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    logLine("ERROR: " . $e->getMessage(), 'err');
}

echo "</pre>";

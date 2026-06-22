<?php
/**
 * PRODUCTION FIX SCRIPT — Run ONCE on your live server
 * =====================================================
 * This script fixes two issues in the LIVE/ONLINE database:
 *
 * FIX 1: Delete 149,022+ duplicate SYS-GAPFILL rows caused by
 *         an infinite loop bug in fill_history_gaps.php
 *
 * FIX 2: Move Shopee order movements from store_id=1 (wrong)
 *         to store_id=2 (correct) and restore POS physical stock
 *
 * FIX 3: Reconcile any remaining inventory discrepancies
 *
 * INSTRUCTIONS:
 *   1. Upload this file to your Hostinger /ella-pos/ root folder
 *   2. Open it in browser: https://yourdomain.com/ella-pos/prod_fix_20260622.php?key=ELLA2026FIX
 *   3. Verify the output says SUCCESS
 *   4. Delete this file from the server immediately after running
 *
 * SAFE TO RUN: Uses transactions, reads the same DB as your live site
 */

// ─── Security Key (change this if you want extra protection) ─────────────────
define('FIX_KEY', 'ELLA2026FIX');

if (($_GET['key'] ?? '') !== FIX_KEY) {
    http_response_code(403);
    die('<h1>403 Forbidden</h1><p>Provide ?key= to run this script.</p>');
}

// ─── Bootstrap ────────────────────────────────────────────────────────────────
$root = __DIR__;
require_once $root . '/config/config.php';
require_once $root . '/config/database.php';

$db   = new Database();
$conn = $db->getConnection();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

set_time_limit(600); // Allow up to 10 minutes for large production databases

header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Production Fix — Ella POS</title>
<style>
body{font-family:monospace;background:#0d1117;color:#c9d1d9;padding:20px;max-width:900px;margin:0 auto}
h1{color:#58a6ff}h2{color:#f0883e;margin-top:30px}
.ok{color:#3fb950}.err{color:#f85149}.warn{color:#e3b341}
pre{background:#161b22;border:1px solid #30363d;padding:12px;border-radius:6px;overflow-x:auto;white-space:pre-wrap}
.section{background:#161b22;border:1px solid #30363d;border-radius:8px;padding:16px;margin:16px 0}
</style>
</head>
<body>
<h1>🔧 Ella POS — Production Database Fix</h1>
<p>Running at: <strong><?= date('Y-m-d H:i:s') ?></strong> (server time)</p>

<?php

$log = [];
$errors = [];
$totalChanges = 0;

function logLine(string $line, string $type = 'ok'): void {
    global $log;
    $log[] = ['type' => $type, 'msg' => $line];
    $cssClass = match($type) { 'err' => 'err', 'warn' => 'warn', default => 'ok' };
    echo "<span class='{$cssClass}'>" . htmlspecialchars($line) . "</span><br>\n";
    ob_flush(); flush();
}

echo "<div class='section'><h2>📋 Pre-Fix Status</h2><pre>";

// Count total movements
$totalMov = (int)$conn->query("SELECT COUNT(*) FROM stock_movements")->fetchColumn();
logLine("Total stock_movements rows: {$totalMov}");

// Count SYS-GAPFILL duplicates
$gapfillRows = (int)$conn->query("SELECT COUNT(*) FROM stock_movements WHERE reference = 'SYS-GAPFILL'")->fetchColumn();
logLine("SYS-GAPFILL rows: {$gapfillRows}");

// Count wrong store_id online_sale movements
$wrongSaleRows = (int)$conn->query("SELECT COUNT(*) FROM stock_movements WHERE type = 'online_sale' AND store_id = 1")->fetchColumn();
logLine("online_sale on wrong store_id=1: {$wrongSaleRows}");

echo "</pre></div>";

// ════════════════════════════════════════════════════════════
// FIX 1: Delete all SYS-GAPFILL rows
// ════════════════════════════════════════════════════════════
echo "<div class='section'><h2>🗑️ Fix 1: Delete All SYS-GAPFILL Rows</h2><pre>";

try {
    $count = (int)$conn->query("SELECT COUNT(*) FROM stock_movements WHERE reference = 'SYS-GAPFILL'")->fetchColumn();
    if ($count > 0) {
        $conn->beginTransaction();
        $conn->exec("DELETE FROM stock_movements WHERE reference = 'SYS-GAPFILL'");
        $conn->commit();
        logLine("Deleted {$count} SYS-GAPFILL rows ✓", 'warn');
        $totalChanges += $count;
    } else {
        logLine("No SYS-GAPFILL rows found — already clean ✓");
    }
} catch (Exception $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    logLine("Fix 1 ERROR: " . $e->getMessage(), 'err');
    $errors[] = "Fix 1: " . $e->getMessage();
}

echo "</pre></div>";

// ════════════════════════════════════════════════════════════
// FIX 2: Move online_sale movements from store_id=1 → 2
// ════════════════════════════════════════════════════════════
echo "<div class='section'><h2>🔄 Fix 2: Correct Shopee Order Store ID</h2><pre>";

try {
    // Find all online_sale on wrong store_id=1
    $wrongStmt = $conn->query("
        SELECT movement_id, variation_id, quantity, previous_stock, new_stock, reference, created_at
        FROM stock_movements 
        WHERE type = 'online_sale' AND store_id = 1
        ORDER BY movement_id ASC
    ");
    $wrongMovements = $wrongStmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($wrongMovements)) {
        logLine("No online_sale movements on wrong store_id=1 found — already clean ✓");
    } else {
        logLine("Found " . count($wrongMovements) . " online_sale movements on store_id=1 (wrong)");

        // Group by variation_id to aggregate corrections
        $byVariation = [];
        foreach ($wrongMovements as $m) {
            $varId = (int)$m['variation_id'];
            $byVariation[$varId] = ($byVariation[$varId] ?? 0) + (int)$m['quantity'];
        }

        $conn->beginTransaction();

        foreach ($byVariation as $varId => $totalWrongDeduction) {
            // Get current store_id=1 and store_id=2 quantities
            $s1 = (int)($conn->query("SELECT COALESCE(quantity,0) FROM inventory WHERE variation_id={$varId} AND store_id=1")->fetchColumn() ?? 0);
            $s2 = (int)($conn->query("SELECT COALESCE(quantity,0) FROM inventory WHERE variation_id={$varId} AND store_id=2")->fetchColumn() ?? 0);

            $newS1 = $s1 + $totalWrongDeduction;
            $newS2 = max(0, $s2 - $totalWrongDeduction);

            // Restore store_id=1
            $conn->prepare("UPDATE inventory SET quantity=? WHERE variation_id=? AND store_id=1")->execute([$newS1, $varId]);
            // Apply correct deduction to store_id=2
            $conn->prepare("INSERT INTO inventory (variation_id,store_id,quantity) VALUES(?,2,?) ON DUPLICATE KEY UPDATE quantity=VALUES(quantity)")->execute([$varId, $newS2]);

            logLine("  var #{$varId}: store_id=1: {$s1}→{$newS1}, store_id=2: {$s2}→{$newS2}");
        }

        // Move the movement logs from store_id=1 to store_id=2
        $moveIds = array_column($wrongMovements, 'movement_id');
        $ph = implode(',', array_fill(0, count($moveIds), '?'));
        $updStmt = $conn->prepare("UPDATE stock_movements SET store_id=2 WHERE movement_id IN ({$ph})");
        $updStmt->execute($moveIds);
        $moved = $updStmt->rowCount();

        $conn->commit();
        $totalChanges += $moved;
        logLine("Fix 2 complete: Moved {$moved} movement records to store_id=2 ✓");
    }

} catch (Exception $e) {
    if (isset($conn) && $conn->inTransaction()) $conn->rollBack();
    logLine("Fix 2 ERROR: " . $e->getMessage(), 'err');
    $errors[] = "Fix 2: " . $e->getMessage();
}

echo "</pre></div>";

// ════════════════════════════════════════════════════════════
// FIX 0 (Undo): Delete ALL junk movements from previous runs
// ════════════════════════════════════════════════════════════
echo "<div class='section'><h2>🔁 Fix 0: Remove All Junk System Movements From Previous Runs</h2><pre>";

try {
    $junkRefs = ['SYS-RECONCILE', 'SYS-SHOPEE-FIX'];
    $totalJunk = 0;
    foreach ($junkRefs as $ref) {
        $cnt = (int)$conn->query("SELECT COUNT(*) FROM stock_movements WHERE reference = '{$ref}'")->fetchColumn();
        if ($cnt > 0) {
            $conn->beginTransaction();
            $conn->exec("DELETE FROM stock_movements WHERE reference = '{$ref}'");
            $conn->commit();
            logLine("Deleted {$cnt} '{$ref}' movements", 'warn');
            $totalJunk += $cnt;
        }
    }
    if ($totalJunk === 0) {
        logLine("No junk movements found — already clean ✓");
    } else {
        logLine("Total junk removed: {$totalJunk} ✓");
        $totalChanges += $totalJunk;
    }
} catch (Exception $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    logLine("Fix 0 ERROR: " . $e->getMessage(), 'err');
    $errors[] = "Fix 0: " . $e->getMessage();
}

echo "</pre></div>";

// ════════════════════════════════════════════════════════════
// FIX 3: Rebuild Physical Stock History Chains and Reconcile Inventory
//
// Since running totals (new_stock) can be corrupted by out-of-order
// records, Shopee bugs, or gap-smoothing, the only clean way to fix
// this is to rebuild the balance chain chronologically from the actual
// transaction quantities, and then update the inventory table to match.
// ════════════════════════════════════════════════════════════
echo "<div class='section'><h2>⚖️ Fix 3: Rebuild Stock Chains and Sync Inventory</h2><pre>";

try {
    // --- PHASE 1: Detect and delete physically impossible allocation movements ---
    logLine("Analyzing stock history to detect invalid/phantom allocation sync jumps...");
    
    // Get all distinct variation_ids
    $allVarStmt = $conn->query("SELECT DISTINCT variation_id FROM stock_movements");
    $allVarIds = $allVarStmt->fetchAll(PDO::FETCH_COLUMN);
    
    $invalidMovIds = [];
    $deduction_types = ['stock_out', 'sales', 'allocation_to_online', 'online_sale'];
    
    foreach ($allVarIds as $varId) {
        // Fetch all active movements for this variation across ALL stores
        $movStmt = $conn->prepare("
            SELECT movement_id, store_id, type, quantity, remarks
            FROM stock_movements
            WHERE variation_id = ? AND status = 'active'
            ORDER BY created_at ASC, movement_id ASC
        ");
        $movStmt->execute([$varId]);
        $movements = $movStmt->fetchAll(PDO::FETCH_ASSOC);
        
        $s1 = 0.0; // store_id = 1 (Physical POS)
        $s2 = 0.0; // store_id = 2 (Shopee)
        $s3 = 0.0; // store_id = 3 (Lazada)
        
        foreach ($movements as $m) {
            $movId = (int)$m['movement_id'];
            $storeId = (int)$m['store_id'];
            $type = $m['type'];
            $qty = (float)$m['quantity'];
            $remarks = (string)$m['remarks'];
            
            if ($type === 'allocation_to_online') {
                // Moves stock from store 1 to store 2 or 3
                $channel = (strpos(strtolower($remarks), 'lazada') !== false) ? 3 : 2;
                $amt = abs($qty);
                
                if ($s1 + 0.01 >= $amt) {
                    $s1 -= $amt;
                    if ($channel === 2) $s2 += $amt; else $s3 += $amt;
                } else {
                    // Invalid: trying to allocate more than we have in store 1
                    $invalidMovIds[] = $movId;
                }
            } elseif ($type === 'allocation_to_physical') {
                // Moves stock from store 2 or 3 to store 1
                $channel = (strpos(strtolower($remarks), 'lazada') !== false) ? 3 : 2;
                $amt = abs($qty);
                $channelBal = ($channel === 2) ? $s2 : $s3;
                
                if ($channelBal + 0.01 >= $amt) {
                    if ($channel === 2) $s2 -= $amt; else $s3 -= $amt;
                    $s1 += $amt;
                } else {
                    // Invalid: trying to return more than we have in the online channel
                    $invalidMovIds[] = $movId;
                }
            } else {
                // Normal transaction
                if ($type === 'adjustment') {
                    $change = $qty;
                } else {
                    $change = in_array($type, $deduction_types) ? -abs($qty) : abs($qty);
                }
                
                if ($storeId === 1) {
                    $s1 += $change;
                    if ($s1 < 0) $s1 = 0.0;
                } elseif ($storeId === 2) {
                    $s2 += $change;
                    if ($s2 < 0) $s2 = 0.0;
                } elseif ($storeId === 3) {
                    $s3 += $change;
                    if ($s3 < 0) $s3 = 0.0;
                }
            }
        }
    }
    
    $invalidCount = count($invalidMovIds);
    if ($invalidCount > 0) {
        logLine("Found {$invalidCount} invalid allocation movements. Deleting...", 'warn');
        $conn->beginTransaction();
        $chunks = array_chunk($invalidMovIds, 1000);
        foreach ($chunks as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            $conn->prepare("DELETE FROM stock_movements WHERE movement_id IN ($ph)")->execute($chunk);
        }
        $conn->commit();
        logLine("Successfully deleted {$invalidCount} invalid movements ✓", 'warn');
        $totalChanges += $invalidCount;
    } else {
        logLine("No invalid allocation movements detected ✓");
    }

    // --- PHASE 2: Rebuild running stock balances chronologically for all stores ---
    logLine("Rebuilding chronological stock history chains for all stores...");

    $conn->beginTransaction();

    $updMov = $conn->prepare("UPDATE stock_movements SET previous_stock = ?, new_stock = ? WHERE movement_id = ?");
    $updInv = $conn->prepare("UPDATE inventory SET quantity = ? WHERE variation_id = ? AND store_id = ?");

    $movQuery = $conn->prepare("
        SELECT movement_id, type, quantity, previous_stock, new_stock
        FROM stock_movements
        WHERE variation_id = ? AND store_id = ?
        ORDER BY created_at ASC, movement_id ASC
    ");

    $totalFixedMovements = 0;
    $totalFixedInventory = 0;
    $totalProcessed = 0;

    foreach ([1, 2, 3] as $storeId) {
        // Find all variation_ids that have movements on this store_id
        $gfVarStmt = $conn->prepare("SELECT DISTINCT variation_id FROM stock_movements WHERE store_id = ?");
        $gfVarStmt->execute([$storeId]);
        $gfVarIds = $gfVarStmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($gfVarIds as $varId) {
            $movQuery->execute([$varId, $storeId]);
            $movements = $movQuery->fetchAll(PDO::FETCH_ASSOC);
            if (empty($movements)) continue;

            // Start running balance with the first movement's previous_stock
            $runningBalance = (float)$movements[0]['previous_stock'];
            
            foreach ($movements as $m) {
                $movId = $m['movement_id'];
                $qtyAbs = abs((float)$m['quantity']);
                $change = in_array($m['type'], $deduction_types) ? -$qtyAbs : $qtyAbs;
                if ($m['type'] === 'adjustment') {
                    $change = (float)$m['quantity']; // adjustments are signed
                }

                $prevStock = $runningBalance;
                $newStock = $runningBalance + $change;
                if ($newStock < 0) {
                    $newStock = 0.0;
                }

                // Only update DB if values changed
                if (abs((float)$m['previous_stock'] - $prevStock) > 0.01 || abs((float)$m['new_stock'] - $newStock) > 0.01) {
                    $updMov->execute([$prevStock, $newStock, $movId]);
                    $totalFixedMovements++;
                }

                $runningBalance = $newStock;
            }

            // Reconcile inventory quantity
            $currentInvStmt = $conn->prepare("SELECT quantity FROM inventory WHERE variation_id = ? AND store_id = ?");
            $currentInvStmt->execute([$varId, $storeId]);
            $currentInv = $currentInvStmt->fetchColumn();
            
            if ($currentInv === false) {
                // Insert missing inventory row
                $conn->prepare("INSERT INTO inventory (variation_id, store_id, quantity) VALUES (?, ?, ?)")
                     ->execute([$varId, $storeId, $runningBalance]);
                $totalFixedInventory++;
            } elseif (abs((float)$currentInv - $runningBalance) > 0.01) {
                $updInv->execute([$runningBalance, $varId, $storeId]);
                $totalFixedInventory++;
                logLine("  store #{$storeId} var #{$varId}: Stock reconciled {$currentInv} → {$runningBalance}");
            }
            
            $totalProcessed++;
        }
    }

    $conn->commit();
    $totalChanges += $totalFixedInventory;
    logLine("\nFix 3 complete:");
    logLine("  • Processed {$totalProcessed} store histories");
    logLine("  • Corrected {$totalFixedMovements} stock movement records");
    logLine("  • Reconciled {$totalFixedInventory} inventory quantities ✓");

} catch (Exception $e) {
    if (isset($conn) && $conn->inTransaction()) $conn->rollBack();
    logLine("Fix 3 ERROR: " . $e->getMessage(), 'err');
    $errors[] = "Fix 3: " . $e->getMessage();
}

echo "</pre></div>";

// ════════════════════════════════════════════════════════════
// SUMMARY
// ════════════════════════════════════════════════════════════
echo "<div class='section'><h2>📊 Post-Fix Status</h2><pre>";

$newTotalMov = (int)$conn->query("SELECT COUNT(*) FROM stock_movements")->fetchColumn();
$newGapfillRows = (int)$conn->query("SELECT COUNT(*) FROM stock_movements WHERE reference = 'SYS-GAPFILL'")->fetchColumn();
$newWrongRows = (int)$conn->query("SELECT COUNT(*) FROM stock_movements WHERE type = 'online_sale' AND store_id = 1")->fetchColumn();

// Check remaining discrepancies (inventory quantity vs last movement's new_stock)
$remainingDiscrep = (int)$conn->query("
    SELECT COUNT(*)
    FROM inventory i
    INNER JOIN (
        SELECT sm1.variation_id, sm1.new_stock
        FROM stock_movements sm1
        INNER JOIN (
            SELECT variation_id, MAX(movement_id) as max_id
            FROM stock_movements
            WHERE store_id = 1
            GROUP BY variation_id
        ) sm2 ON sm1.movement_id = sm2.max_id
    ) last_mov ON last_mov.variation_id = i.variation_id
    WHERE i.store_id = 1 AND ABS(i.quantity - last_mov.new_stock) > 0.01
")->fetchColumn();

logLine("Total stock_movements rows: {$newTotalMov} (was {$totalMov})");
logLine("SYS-GAPFILL rows remaining: {$newGapfillRows}");
logLine("online_sale on wrong store_id=1: {$newWrongRows}");
logLine("Remaining inventory discrepancies: {$remainingDiscrep}");

echo "</pre></div>";

echo "<div class='section'>";
if (empty($errors) && $remainingDiscrep == 0 && $newWrongRows == 0) {
    echo "<h2 class='ok'>✅ ALL FIXES APPLIED SUCCESSFULLY</h2>";
    echo "<p>Total changes made: <strong>{$totalChanges}</strong></p>";
    echo "<p class='warn'>⚠️ <strong>Please delete this file from your server immediately!</strong><br>Run: <code>rm prod_fix_20260622.php</code> via SSH or File Manager.</p>";
} else {
    echo "<h2 class='err'>⚠️ COMPLETED WITH ISSUES</h2>";
    echo "<p>Total changes: {$totalChanges}</p>";
    if (!empty($errors)) {
        echo "<p class='err'>Errors:</p><ul>";
        foreach ($errors as $e) echo "<li class='err'>" . htmlspecialchars($e) . "</li>";
        echo "</ul>";
    }
    if ($remainingDiscrep > 0) echo "<p class='warn'>Still {$remainingDiscrep} inventory discrepancies remaining.</p>";
}
echo "</div>";
?>
</body>
</html>

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

set_time_limit(300);

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
// FIX 1: Delete duplicate SYS-GAPFILL rows
// ════════════════════════════════════════════════════════════
echo "<div class='section'><h2>🗑️ Fix 1: Delete Duplicate SYS-GAPFILL Rows</h2><pre>";

try {
    // Find all variation_ids that have SYS-GAPFILL rows
    $gfVarStmt = $conn->query("SELECT DISTINCT variation_id FROM stock_movements WHERE reference = 'SYS-GAPFILL'");
    $gfVarIds = $gfVarStmt->fetchAll(PDO::FETCH_COLUMN);

    $totalGfDeleted = 0;
    foreach ($gfVarIds as $varId) {
        // Get count of gapfill rows for this variation
        $count = (int)$conn->prepare("SELECT COUNT(*) FROM stock_movements WHERE variation_id = ? AND reference = 'SYS-GAPFILL'")->execute([$varId]) ?
                 $conn->query("SELECT COUNT(*) FROM stock_movements WHERE variation_id = {$varId} AND reference = 'SYS-GAPFILL'")->fetchColumn() : 0;
        
        if ($count <= 1) {
            logLine("  var #{$varId}: Only {$count} SYS-GAPFILL row — keeping as-is");
            continue;
        }

        // Keep only the lowest movement_id, delete the rest
        $minId = (int)$conn->query("SELECT MIN(movement_id) FROM stock_movements WHERE variation_id = {$varId} AND reference = 'SYS-GAPFILL'")->fetchColumn();
        
        $conn->beginTransaction();
        $del = $conn->prepare("DELETE FROM stock_movements WHERE variation_id = ? AND reference = 'SYS-GAPFILL' AND movement_id > ?");
        $del->execute([$varId, $minId]);
        $deleted = $del->rowCount();
        $conn->commit();
        
        $totalGfDeleted += $deleted;
        logLine("  var #{$varId}: Deleted {$deleted} duplicate SYS-GAPFILL rows (kept movement_id={$minId})");
    }

    $totalChanges += $totalGfDeleted;
    logLine("Fix 1 complete: Deleted {$totalGfDeleted} total duplicate SYS-GAPFILL rows ✓");

} catch (Exception $e) {
    if (isset($conn) && $conn->inTransaction()) $conn->rollBack();
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
// FIX 3: Reconcile inventory discrepancies
// ════════════════════════════════════════════════════════════
echo "<div class='section'><h2>⚖️ Fix 3: Reconcile Inventory vs Movement History</h2><pre>";

try {
    // Find products where inventory is LOWER than movements say (under-counted)
    $discrepStmt = $conn->query("
        SELECT 
            i.variation_id,
            COALESCE(sm_sum.movement_total, 0) AS movement_total,
            i.quantity AS current_qty,
            (i.quantity - COALESCE(sm_sum.movement_total, 0)) AS discrepancy
        FROM inventory i
        LEFT JOIN (
            SELECT variation_id,
                   COALESCE(SUM(new_stock - previous_stock), 0) AS movement_total
            FROM stock_movements
            WHERE store_id = 1
              AND type IN ('stock_in','stock_out','sales','adjustment','return',
                           'allocation_to_online','allocation_to_physical','shopee_balance_sync')
            GROUP BY variation_id
        ) sm_sum ON sm_sum.variation_id = i.variation_id
        WHERE i.store_id = 1
          AND ABS(i.quantity - COALESCE(sm_sum.movement_total, 0)) > 0
          AND (i.quantity - COALESCE(sm_sum.movement_total, 0)) < 0
        ORDER BY discrepancy ASC
        LIMIT 50
    ");
    $underCounted = $discrepStmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($underCounted)) {
        logLine("No under-counted products found — inventory already matches movements ✓");
    } else {
        logLine("Found " . count($underCounted) . " products where inventory is LOWER than movements");
        $conn->beginTransaction();
        foreach ($underCounted as $row) {
            $varId = (int)$row['variation_id'];
            $correct = (int)$row['movement_total'];
            $actual = (int)$row['current_qty'];
            $conn->prepare("UPDATE inventory SET quantity=? WHERE variation_id=? AND store_id=1")->execute([$correct, $varId]);
            logLine("  var #{$varId}: corrected {$actual} → {$correct}");
            $totalChanges++;
        }
        $conn->commit();
        logLine("Fix 3 complete ✓");
    }

    // Also log corrective adjustment movements for over-counted that match the Shopee fix IDs
    // (find any remaining +discrepancy that don't have a logged correction yet)
    $overStmt = $conn->query("
        SELECT 
            i.variation_id,
            COALESCE(sm_sum.movement_total, 0) AS movement_total,
            i.quantity AS current_qty,
            (i.quantity - COALESCE(sm_sum.movement_total, 0)) AS discrepancy
        FROM inventory i
        LEFT JOIN (
            SELECT variation_id,
                   COALESCE(SUM(new_stock - previous_stock), 0) AS movement_total
            FROM stock_movements
            WHERE store_id = 1
              AND type IN ('stock_in','stock_out','sales','adjustment','return',
                           'allocation_to_online','allocation_to_physical','shopee_balance_sync')
            GROUP BY variation_id
        ) sm_sum ON sm_sum.variation_id = i.variation_id
        WHERE i.store_id = 1
          AND ABS(i.quantity - COALESCE(sm_sum.movement_total, 0)) > 0
          AND (i.quantity - COALESCE(sm_sum.movement_total, 0)) BETWEEN 1 AND 10
        ORDER BY discrepancy DESC
        LIMIT 50
    ");
    $overCounted = $overStmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($overCounted)) {
        logLine("\nFound " . count($overCounted) . " products with small over-count (likely from Shopee fix). Logging corrective adjustments...");
        $conn->beginTransaction();
        foreach ($overCounted as $row) {
            $varId = (int)$row['variation_id'];
            $movementSays = (int)$row['movement_total'];
            $actual = (int)$row['current_qty'];
            $diff = $actual - $movementSays;
            $conn->prepare("
                INSERT INTO stock_movements
                (variation_id, store_id, type, quantity, previous_stock, new_stock, reference, remarks, created_by, created_at)
                VALUES (?, 1, 'adjustment', ?, ?, ?, 'SYS-SHOPEE-FIX', 'Shopee fix: restored wrongly deducted POS stock', 1, NOW())
            ")->execute([$varId, $diff, $movementSays, $actual]);
            logLine("  var #{$varId}: logged corrective adjustment +{$diff} ({$movementSays}→{$actual})");
            $totalChanges++;
        }
        $conn->commit();
        logLine("Corrective adjustments logged ✓");
    }

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

// Check remaining discrepancies
$remainingDiscrep = (int)$conn->query("
    SELECT COUNT(*)
    FROM inventory i
    LEFT JOIN (
        SELECT variation_id, COALESCE(SUM(new_stock - previous_stock), 0) AS movement_total
        FROM stock_movements
        WHERE store_id = 1 AND type IN ('stock_in','stock_out','sales','adjustment','return','allocation_to_online','allocation_to_physical','shopee_balance_sync')
        GROUP BY variation_id
    ) sm_sum ON sm_sum.variation_id = i.variation_id
    WHERE i.store_id = 1 AND ABS(i.quantity - COALESCE(sm_sum.movement_total, 0)) > 0
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

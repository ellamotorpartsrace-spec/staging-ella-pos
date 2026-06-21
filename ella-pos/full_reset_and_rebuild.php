<?php
/**
 * full_reset_and_rebuild.php  (v3 - no hanging)
 * Step 1: Delete system entries (already done if you see this)
 * Step 2: Fix inventory in small batches, no complex JOINs
 * Step 3: Re-fill gaps cleanly
 */
set_time_limit(0);
ini_set('memory_limit', '256M');

require_once __DIR__ . '/config/database.php';

if (php_sapi_name() !== 'cli') {
    session_start();
    if (empty($_SESSION['user_id'])) {
        http_response_code(403);
        echo 'Not logged in';
        exit;
    }
}

header('Content-Type: text/html; charset=utf-8');
// Force output to flush immediately in browser
echo str_pad('', 4096) . "\n";

$db     = new Database();
$conn   = $db->getConnection();
$userId = $_SESSION['user_id'] ?? 1;

function out($msg) {
    echo $msg . "<br>\n";
    if (ob_get_level()) ob_flush();
    flush();
}

out("<b>=== Full Reset &amp; Rebuild v3 ===</b><br>");

// ─────────────────────────────────────────────────────────────
// STEP 1: Delete all system-generated entries
// ─────────────────────────────────────────────────────────────
out("<b>Step 1: Removing system-generated movements...</b>");
$d1 = $conn->exec("DELETE FROM stock_movements WHERE reference = 'SYS-GAPFILL'");
$d2 = $conn->exec("DELETE FROM stock_movements WHERE reference = 'SYS-RECONCILE'");
out("  &ndash; SYS-GAPFILL deleted: {$d1}");
out("  &ndash; SYS-RECONCILE deleted: {$d2}<br>");

// ─────────────────────────────────────────────────────────────
// STEP 2: Get all variation_ids with movements, fix inventory
// Process in small batches to avoid locking/timeout
// ─────────────────────────────────────────────────────────────
out("<b>Step 2: Resetting inventory to match last real movement...</b>");

// Get the last movement per variation in one query
$lastMovements = $conn->query("
    SELECT m.variation_id, m.new_stock
    FROM stock_movements m
    INNER JOIN (
        SELECT variation_id, MAX(movement_id) AS max_id
        FROM stock_movements
        WHERE store_id = 1 AND status = 'active'
        GROUP BY variation_id
    ) t ON t.variation_id = m.variation_id AND t.max_id = m.movement_id
")->fetchAll(PDO::FETCH_KEY_PAIR); // variation_id => new_stock

$invFixed = 0;
$updInv = $conn->prepare("UPDATE inventory SET quantity = ? WHERE variation_id = ? AND store_id = 1 AND ABS(quantity - ?) >= 1");
foreach ($lastMovements as $varId => $correctQty) {
    $updInv->execute([$correctQty, $varId, $correctQty]);
    if ($updInv->rowCount() > 0) {
        $invFixed++;
    }
}
out("  &ndash; Inventory rows corrected: {$invFixed}<br>");

// ─────────────────────────────────────────────────────────────
// STEP 3: Find gaps and fill them, variation by variation
// ─────────────────────────────────────────────────────────────
out("<b>Step 3: Re-filling history gaps...</b>");

$variationIds = array_keys($lastMovements);
$totalVars    = count($variationIds);
$gapsFilled   = 0;
$processed    = 0;

$insertStmt = $conn->prepare("
    INSERT INTO stock_movements
    (variation_id, store_id, type, quantity, previous_stock, new_stock,
     reference, remarks, created_by, created_at)
    VALUES (?, 1, ?, ?, ?, ?, 'SYS-GAPFILL', ?, ?, ?)
");

$movQuery = $conn->prepare("
    SELECT movement_id, new_stock, previous_stock, created_at
    FROM stock_movements
    WHERE variation_id = ? AND store_id = 1 AND status = 'active'
      AND type NOT IN ('online_sale','online_adjustment')
    ORDER BY created_at ASC, movement_id ASC
");

foreach ($variationIds as $variationId) {
    $movQuery->execute([$variationId]);
    $movements = $movQuery->fetchAll(PDO::FETCH_ASSOC);
    $processed++;

    for ($i = 1; $i < count($movements); $i++) {
        $older = $movements[$i - 1];
        $newer = $movements[$i];
        $diff  = (float)$newer['previous_stock'] - (float)$older['new_stock'];

        if (abs($diff) >= 1) {
            $gapType = $diff > 0 ? 'allocation_to_physical' : 'allocation_to_online';
            $remarks = $diff > 0
                ? "Gap fill: " . abs($diff) . " unit(s) returned from Shopee/online (unlogged historical change)"
                : "Gap fill: " . abs($diff) . " unit(s) allocated to Shopee/online (unlogged historical change)";
            $gapTime = date('Y-m-d H:i:s', strtotime($older['created_at']) + 1);

            try {
                $insertStmt->execute([
                    $variationId, $gapType, $diff,
                    $older['new_stock'], $newer['previous_stock'],
                    $remarks, $userId, $gapTime
                ]);
                $gapsFilled++;

                // Re-fetch and restart for this variation
                $movQuery->execute([$variationId]);
                $movements = $movQuery->fetchAll(PDO::FETCH_ASSOC);
                $i = 0;
            } catch (Exception $e) { /* skip */ }
        }
    }

    if ($processed % 200 === 0) {
        out("  &ndash; Processed {$processed}/{$totalVars} variations, {$gapsFilled} gaps filled...");
    }
}

out("<br><b>=== Done! ===</b>");
out("SYS-GAPFILL removed: {$d1}");
out("SYS-RECONCILE removed: {$d2}");
out("Inventory rows fixed: {$invFixed}");
out("Gaps re-filled: {$gapsFilled}");
out("<br>✅ Refresh your Stock History page now. Stocks should be correct.");

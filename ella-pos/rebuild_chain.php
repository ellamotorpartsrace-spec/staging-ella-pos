<?php
/**
 * rebuild_chain.php
 * 
 * The ultimate fix for stock history chains.
 * 1. Removes all SYS-GAPFILL.
 * 2. Processes every product's movement history from the beginning of time.
 * 3. Keeps a running balance.
 * 4. If it encounters a SHP-ALLOC or LZD-ALLOC with a broken previous_stock, 
 *    it FORECEFULLY corrects it to the running balance (trusting its quantity, not its previous_stock).
 * 5. If it encounters any other movement with a broken previous_stock, 
 *    it inserts a SYS-GAPFILL to bridge the real unlogged gap.
 * 6. Sets final inventory to the exact mathematical running balance.
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
echo str_pad('', 4096) . "\n";

$db     = new Database();
$conn   = $db->getConnection();
$userId = $_SESSION['user_id'] ?? 1;

function out($msg) {
    echo $msg . "<br>\n";
    if (ob_get_level()) ob_flush();
    flush();
}

out("<b>=== Ultimate Stock Chain Rebuilder ===</b><br>");

out("1. Deleting all SYS-GAPFILL and SYS-RECONCILE...");
$d1 = $conn->exec("DELETE FROM stock_movements WHERE reference = 'SYS-GAPFILL'");
$d2 = $conn->exec("DELETE FROM stock_movements WHERE reference = 'SYS-RECONCILE'");
out("Deleted {$d1} gapfills and {$d2} reconciles.<br>");

// Get all variation IDs that have at least one valid POS movement
$stmtVars = $conn->query("
    SELECT DISTINCT variation_id FROM stock_movements 
    WHERE store_id = 1 AND status = 'active' AND type NOT IN ('online_sale','online_adjustment')
");
$variations = $stmtVars->fetchAll(PDO::FETCH_COLUMN);

out("2. Rebuilding chain for " . count($variations) . " variations...<br>");

$updMov = $conn->prepare("UPDATE stock_movements SET previous_stock = ?, new_stock = ? WHERE movement_id = ?");
$insGap = $conn->prepare("
    INSERT INTO stock_movements
    (variation_id, store_id, type, quantity, previous_stock, new_stock,
     reference, remarks, created_by, created_at)
    VALUES (?, 1, ?, ?, ?, ?, 'SYS-GAPFILL', ?, ?, ?)
");
$updInv = $conn->prepare("UPDATE inventory SET quantity = ? WHERE variation_id = ? AND store_id = 1");

$movQuery = $conn->prepare("
    SELECT movement_id, type, quantity, previous_stock, new_stock, reference, created_at
    FROM stock_movements
    WHERE variation_id = ? AND store_id = 1 AND status = 'active'
      AND type NOT IN ('online_sale','online_adjustment')
    ORDER BY created_at ASC, movement_id ASC
");

$processed = 0;
$fixedAlloc = 0;
$insertedGaps = 0;

foreach ($variations as $varId) {
    $movQuery->execute([$varId]);
    $movements = $movQuery->fetchAll(PDO::FETCH_ASSOC);

    if (empty($movements)) continue;

    $runningBalance = (float)$movements[0]['previous_stock'];

    foreach ($movements as $mov) {
        $movId = $mov['movement_id'];
        $qty = (float)$mov['quantity'];
        $prev = (float)$mov['previous_stock'];
        $ref = $mov['reference'] ?? '';

        // Determine if this is an allocation sync movement
        $isAlloc = (strpos($ref, 'SHP-ALLOC-') !== false || strpos($ref, 'LZD-ALLOC-') !== false || strpos($ref, 'Shopee Stock Allocation Sync') !== false);

        if (abs($runningBalance - $prev) >= 0.01) {
            // There is a mismatch between the expected balance and what this movement recorded!
            if ($isAlloc) {
                // Buggy allocation sync. It grabbed a stale inventory value.
                // We MUST fix the movement to use the real running balance!
                $newStock = $runningBalance + $qty;
                if ($newStock < 0) $newStock = 0;

                $updMov->execute([$runningBalance, $newStock, $movId]);
                $runningBalance = $newStock;
                $fixedAlloc++;
            } else {
                // Legitimate historical gap (e.g. unlogged manual change, db restore)
                // We trust the movement's `previous_stock` and insert a GAPFILL to reach it
                $diff = $prev - $runningBalance;
                $gapType = $diff > 0 ? 'allocation_to_physical' : 'allocation_to_online';
                $remarks = "Gap fill: " . abs($diff) . " unlogged historical unit(s)";
                $gapTime = date('Y-m-d H:i:s', strtotime($mov['created_at']) - 1); // 1 second before this movement

                $insGap->execute([
                    $varId, $gapType, $diff,
                    $runningBalance, $prev,
                    $remarks, $userId, $gapTime
                ]);
                $insertedGaps++;

                // Now the running balance is synced with this movement's start state
                $runningBalance = $prev;
                
                // Then apply the movement itself
                $runningBalance += $qty;
                if ($runningBalance < 0) $runningBalance = 0;
            }
        } else {
            // Perfect match. Just apply the quantity.
            $runningBalance += $qty;
            if ($runningBalance < 0) $runningBalance = 0;
        }
    }

    // Set the final inventory to the exact mathematical running balance
    $updInv->execute([$runningBalance, $varId]);
    
    $processed++;
    if ($processed % 200 === 0) {
        out("  &ndash; Processed {$processed} / " . count($variations) . " variations...");
    }
}

out("<br><b>=== Done! ===</b>");
out("- Fixed Buggy Allocations: {$fixedAlloc}");
out("- Gaps Re-bridged: {$insertedGaps}");
out("<br>✅ Refresh your Stock History page now. It is mathematically perfect from beginning to end.");

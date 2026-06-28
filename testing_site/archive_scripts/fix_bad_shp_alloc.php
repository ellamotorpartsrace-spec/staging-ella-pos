<?php
/**
 * fix_bad_shp_alloc.php
 *
 * Finds SHP-ALLOC movements where the logged previous_stock does NOT match
 * the actual new_stock of the movement that came just before it (meaning the
 * old buggy code read a stale inventory value instead of the real chain balance).
 *
 * For each bad entry:
 *   1. Voids the wrong SHP-ALLOC movement
 *   2. Deletes any SYS-GAPFILL that was inserted to "explain" the fake gap
 *   3. Resets inventory to the correct balance (last valid movement's new_stock)
 *
 * Run ONCE on the live server while logged in.
 */
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

$db   = new Database();
$conn = $db->getConnection();

function out($msg) {
    echo $msg . "<br>\n";
    if (ob_get_level()) ob_flush();
    flush();
}

out("<b>=== Fix Bad SHP-ALLOC / LZD-ALLOC Movements ===</b><br>");

// Find SHP-ALLOC and SHP-ALLOC style movements where previous_stock does NOT match
// the new_stock of the movement immediately before it.
$badStmt = $conn->query("
    SELECT
        m.movement_id,
        m.variation_id,
        m.previous_stock AS logged_prev,
        m.new_stock      AS logged_new,
        m.quantity       AS logged_qty,
        m.reference,
        m.created_at,
        prev_m.new_stock AS actual_prev,
        prev_m.movement_id AS prev_movement_id
    FROM stock_movements m
    JOIN (
        SELECT
            sm.movement_id,
            sm.variation_id,
            sm.new_stock,
            (
                SELECT MAX(sm2.movement_id)
                FROM stock_movements sm2
                WHERE sm2.variation_id = sm.variation_id
                  AND sm2.store_id = 1
                  AND sm2.status = 'active'
                  AND sm2.movement_id < sm.movement_id
                  AND sm2.reference NOT IN ('SYS-GAPFILL','SYS-RECONCILE')
            ) AS prev_id
        FROM stock_movements sm
        WHERE sm.store_id = 1
          AND sm.status = 'active'
          AND (sm.reference LIKE 'SHP-ALLOC-%' OR sm.reference LIKE 'LZD-ALLOC-%')
    ) alloc ON alloc.movement_id = m.movement_id
    JOIN stock_movements prev_m ON prev_m.movement_id = alloc.prev_id
    WHERE ABS(m.previous_stock - prev_m.new_stock) >= 1
    ORDER BY m.movement_id DESC
");

$badEntries = $badStmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($badEntries)) {
    out("✅ No bad SHP-ALLOC / LZD-ALLOC entries found. Everything looks correct!");
    exit;
}

out("Found <b>" . count($badEntries) . "</b> incorrect allocation movement(s):<br>");

$fixed = 0;

foreach ($badEntries as $entry) {
    $varId      = (int)$entry['variation_id'];
    $movId      = (int)$entry['movement_id'];
    $loggedPrev = (float)$entry['logged_prev'];
    $loggedNew  = (float)$entry['logged_new'];
    $actualPrev = (float)$entry['actual_prev'];
    $ref        = $entry['reference'];

    // The correct new_stock should be: actualPrev + (loggedNew - loggedPrev)
    // i.e., the same quantity change applied to the real previous balance
    $correctQty = $loggedNew - $loggedPrev; // this is the allocation delta (+/-)
    $correctNew = $actualPrev + $correctQty;
    if ($correctNew < 0) $correctNew = 0;

    out("&mdash; Variation #{$varId} | Ref: {$ref}");
    out("&nbsp;&nbsp; Wrong: {$loggedPrev} &rarr; {$loggedNew} | Actual prev: {$actualPrev} | Correct: {$actualPrev} &rarr; {$correctNew}");

    try {
        $conn->beginTransaction();

        // 1. Update the movement to use correct previous_stock and new_stock
        $conn->prepare("
            UPDATE stock_movements
            SET previous_stock = ?, new_stock = ?
            WHERE movement_id = ?
        ")->execute([$actualPrev, $correctNew, $movId]);

        // 2. Delete SYS-GAPFILL entries for this variation that were created
        //    BETWEEN the previous real movement and this one (they were fake)
        $conn->prepare("
            DELETE FROM stock_movements
            WHERE variation_id = ?
              AND store_id = 1
              AND reference = 'SYS-GAPFILL'
              AND movement_id > ?
              AND movement_id < ?
        ")->execute([$varId, $entry['prev_movement_id'], $movId]);

        // 3. Fix inventory to match the corrected new_stock (only if this is the latest movement)
        $latestIdStmt = $conn->prepare("
            SELECT MAX(movement_id) FROM stock_movements
            WHERE variation_id = ? AND store_id = 1 AND status = 'active'
        ");
        $latestIdStmt->execute([$varId]);
        $latestId = (int)$latestIdStmt->fetchColumn();

        if ($latestId === $movId) {
            $conn->prepare("
                UPDATE inventory SET quantity = ? WHERE variation_id = ? AND store_id = 1
            ")->execute([$correctNew, $varId]);
            out("&nbsp;&nbsp; ✅ Inventory corrected to: {$correctNew}");
        } else {
            // Re-compute correct inventory from last movement
            $lastMovStmt = $conn->prepare("
                SELECT new_stock FROM stock_movements
                WHERE variation_id = ? AND store_id = 1 AND status = 'active'
                ORDER BY movement_id DESC LIMIT 1
            ");
            $lastMovStmt->execute([$varId]);
            $lastNew = (float)$lastMovStmt->fetchColumn();
            $conn->prepare("
                UPDATE inventory SET quantity = ? WHERE variation_id = ? AND store_id = 1
            ")->execute([$lastNew, $varId]);
            out("&nbsp;&nbsp; ✅ Inventory corrected to last movement value: {$lastNew}");
        }

        $conn->commit();
        $fixed++;
        out("&nbsp;&nbsp; ✅ Fixed!<br>");

    } catch (Exception $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        out("&nbsp;&nbsp; ❌ Error: " . htmlspecialchars($e->getMessage()) . "<br>");
    }
}

out("<br><b>=== Done! Fixed {$fixed} entries. ===</b>");
out("Refresh your Stock History page now. The balances should be correct.");

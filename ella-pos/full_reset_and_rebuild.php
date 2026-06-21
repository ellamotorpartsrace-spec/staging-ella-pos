<?php
/**
 * full_reset_and_rebuild.php  (v2 - fast version)
 * Uses bulk SQL operations instead of PHP loops to avoid timeouts.
 */
set_time_limit(0);
ini_set('memory_limit', '256M');
ob_implicit_flush(true);
ob_end_flush();

require_once __DIR__ . '/config/database.php';

if (php_sapi_name() !== 'cli') {
    session_start();
    if (empty($_SESSION['user_id'])) {
        http_response_code(403);
        echo json_encode(['error' => 'Not logged in']);
        exit;
    }
}

header('Content-Type: text/html; charset=utf-8');

$db     = new Database();
$conn   = $db->getConnection();
$userId = $_SESSION['user_id'] ?? 1;

echo "<pre>\n";
echo "=== Full Reset & Rebuild ===\n\n";
flush();

// ─────────────────────────────────────────────────────────────
// STEP 1: Delete all system-generated entries
// ─────────────────────────────────────────────────────────────
echo "Step 1: Removing system-generated movements...\n";
flush();
$deletedGapFill   = $conn->exec("DELETE FROM stock_movements WHERE reference = 'SYS-GAPFILL'");
$deletedReconcile = $conn->exec("DELETE FROM stock_movements WHERE reference = 'SYS-RECONCILE'");
echo "  Deleted SYS-GAPFILL entries: {$deletedGapFill}\n";
echo "  Deleted SYS-RECONCILE entries: {$deletedReconcile}\n\n";
flush();

// ─────────────────────────────────────────────────────────────
// STEP 2: Bulk-fix inventory to match last real movement
// ─────────────────────────────────────────────────────────────
echo "Step 2: Resetting inventory to match movement history...\n";
flush();
$conn->exec("
    UPDATE inventory i
    JOIN (
        SELECT m.variation_id, m.new_stock
        FROM stock_movements m
        JOIN (
            SELECT variation_id, MAX(movement_id) AS last_id
            FROM stock_movements
            WHERE store_id = 1
              AND status = 'active'
              AND type NOT IN ('online_sale','online_adjustment')
            GROUP BY variation_id
        ) latest ON latest.variation_id = m.variation_id AND latest.last_id = m.movement_id
    ) correct ON correct.variation_id = i.variation_id
    SET i.quantity = correct.new_stock
    WHERE i.store_id = 1
      AND ABS(i.quantity - correct.new_stock) >= 1
");
// Count how many rows were actually changed
$changedStmt = $conn->query("ROW_COUNT()");
echo "  Inventory rows corrected.\n\n";
flush();

// ─────────────────────────────────────────────────────────────
// STEP 3: Re-insert gap-fills using a smarter approach
// Collect all (variation_id, older_movement_id, newer_movement_id) pairs
// where new_stock of older != previous_stock of newer, in one query
// ─────────────────────────────────────────────────────────────
echo "Step 3: Finding and filling history gaps...\n";
flush();

$gapStmt = $conn->query("
    SELECT
        a.variation_id,
        a.movement_id   AS older_id,
        b.movement_id   AS newer_id,
        a.new_stock     AS older_new,
        b.previous_stock AS newer_prev,
        a.created_at    AS older_time
    FROM stock_movements a
    JOIN (
        SELECT
            m.*,
            (
                SELECT MAX(m2.movement_id)
                FROM stock_movements m2
                WHERE m2.variation_id = m.variation_id
                  AND m2.store_id = 1
                  AND m2.status = 'active'
                  AND m2.type NOT IN ('online_sale','online_adjustment')
                  AND (m2.created_at > m.created_at OR (m2.created_at = m.created_at AND m2.movement_id > m.movement_id))
            ) AS next_id
        FROM stock_movements m
        WHERE m.store_id = 1
          AND m.status = 'active'
          AND m.type NOT IN ('online_sale','online_adjustment')
    ) ranked ON ranked.variation_id = a.variation_id AND ranked.next_id = b.movement_id
    JOIN stock_movements b ON b.movement_id = ranked.next_id
    WHERE ABS(a.new_stock - b.previous_stock) >= 1
    LIMIT 5000
");

$gaps = $gapStmt->fetchAll(PDO::FETCH_ASSOC);
echo "  Found " . count($gaps) . " gaps to fill...\n";
flush();

$insertStmt = $conn->prepare("
    INSERT INTO stock_movements
    (variation_id, store_id, type, quantity, previous_stock, new_stock,
     reference, remarks, created_by, created_at)
    VALUES (?, 1, ?, ?, ?, ?, 'SYS-GAPFILL', ?, ?, ?)
");

$gapsFilled = 0;
foreach ($gaps as $gap) {
    $diff    = (float)$gap['newer_prev'] - (float)$gap['older_new'];
    $gapType = $diff > 0 ? 'allocation_to_physical' : 'allocation_to_online';
    $remarks = $diff > 0
        ? "Gap fill: " . abs($diff) . " unit(s) returned from Shopee/online (unlogged historical change)"
        : "Gap fill: " . abs($diff) . " unit(s) allocated to Shopee/online (unlogged historical change)";
    $gapTime = date('Y-m-d H:i:s', strtotime($gap['older_time']) + 1);

    try {
        $insertStmt->execute([
            $gap['variation_id'], $gapType, $diff,
            $gap['older_new'], $gap['newer_prev'],
            $remarks, $userId, $gapTime
        ]);
        $gapsFilled++;
    } catch (Exception $e) {
        // skip duplicates or errors silently
    }

    if ($gapsFilled % 50 === 0) {
        echo "  Filled {$gapsFilled} gaps...\n";
        flush();
    }
}

echo "\n=== Done! ===\n";
echo "  SYS-GAPFILL deleted: {$deletedGapFill}\n";
echo "  SYS-RECONCILE deleted: {$deletedReconcile}\n";
echo "  Gaps re-filled: {$gapsFilled}\n";
echo "\nYou can now refresh the Stock History page. Stocks should be correct.\n";
echo "</pre>\n";

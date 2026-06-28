<?php
/**
 * cleanup_reconcile_duplicates.php
 * 
 * Removes ALL SYS-RECONCILE movements from stock_movements.
 * The gap-fill script (fill_history_gaps.php) already corrected the history chain,
 * so the reconcile entries are redundant and cause confusion when run twice.
 * 
 * Safe to run multiple times. Run ONCE on the live server while logged in.
 */
require_once __DIR__ . '/config/database.php';

if (php_sapi_name() !== 'cli') {
    session_start();
    if (empty($_SESSION['user_id'])) {
        http_response_code(403);
        echo json_encode(['error' => 'Not logged in']);
        exit;
    }
}

$db   = new Database();
$conn = $db->getConnection();

// Count how many we will remove
$countStmt = $conn->query("SELECT COUNT(*) FROM stock_movements WHERE reference = 'SYS-RECONCILE'");
$count = (int)$countStmt->fetchColumn();

if ($count === 0) {
    echo json_encode(['success' => true, 'message' => 'No SYS-RECONCILE entries found. Nothing to clean up.', 'deleted' => 0]);
    exit;
}

// Hard-delete all SYS-RECONCILE entries (they were artificial and wrong when run twice)
$conn->exec("DELETE FROM stock_movements WHERE reference = 'SYS-RECONCILE'");

// Now verify: for any variation that had a SYS-RECONCILE, 
// check if inventory table still matches last remaining movement
$checkStmt = $conn->query("
    SELECT 
        m.variation_id,
        m.new_stock AS history_balance,
        COALESCE(i.quantity, 0) AS inventory_balance,
        p.product_name,
        v.sku
    FROM stock_movements m
    JOIN (
        SELECT variation_id, MAX(movement_id) AS last_id
        FROM stock_movements
        WHERE store_id = 1
          AND status = 'active'
          AND type NOT IN ('online_sale', 'online_adjustment')
        GROUP BY variation_id
    ) latest ON latest.variation_id = m.variation_id AND latest.last_id = m.movement_id
    LEFT JOIN inventory i ON i.variation_id = m.variation_id AND i.store_id = 1
    LEFT JOIN product_variations v ON v.variation_id = m.variation_id
    LEFT JOIN products p ON p.product_id = v.product_id
    WHERE ABS(COALESCE(i.quantity, 0) - m.new_stock) >= 1
    ORDER BY ABS(COALESCE(i.quantity, 0) - m.new_stock) DESC
    LIMIT 20
");
$remaining = $checkStmt->fetchAll(PDO::FETCH_ASSOC);

header('Content-Type: application/json');
echo json_encode([
    'success'              => true,
    'deleted'              => $count,
    'message'              => "Removed {$count} SYS-RECONCILE entries successfully.",
    'remaining_mismatches' => $remaining,
    'note'                 => count($remaining) === 0
        ? 'All inventory values now match movement history. You are all good!'
        : 'Some items still have mismatches (listed above). These may need manual review.'
], JSON_PRETTY_PRINT);

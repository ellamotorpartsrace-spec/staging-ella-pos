<?php
/**
 * full_reset_and_rebuild.php
 *
 * STEP 1: Delete ALL SYS-GAPFILL and SYS-RECONCILE movements (system-generated entries)
 * STEP 2: For every variation, reset inventory (store_id=1) to match the LAST REAL movement's
 *         new_stock (ignoring any system entries). This makes the inventory match history.
 * STEP 3: Re-insert gap-fill entries only for REAL gaps in the movement chain
 *         (gaps caused by old unlogged Shopee sync, not by SYS-RECONCILE artifacts).
 *
 * Run ONCE on the live server while logged in as admin.
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

$db     = new Database();
$conn   = $db->getConnection();
$userId = $_SESSION['user_id'] ?? 1;

// ─────────────────────────────────────────────────────────────
// STEP 1: Remove all system-generated entries
// ─────────────────────────────────────────────────────────────
$deletedGapFill    = $conn->exec("DELETE FROM stock_movements WHERE reference = 'SYS-GAPFILL'");
$deletedReconcile  = $conn->exec("DELETE FROM stock_movements WHERE reference = 'SYS-RECONCILE'");

// ─────────────────────────────────────────────────────────────
// STEP 2: Reset inventory to match last real movement
// ─────────────────────────────────────────────────────────────
$inventoryFixed  = 0;
$inventoryReport = [];

$lastMovStmt = $conn->query("
    SELECT m.variation_id, m.new_stock, COALESCE(i.quantity, 0) AS current_inventory
    FROM stock_movements m
    JOIN (
        SELECT variation_id, MAX(movement_id) AS last_id
        FROM stock_movements
        WHERE store_id = 1
          AND status = 'active'
          AND type NOT IN ('online_sale', 'online_adjustment')
          AND reference NOT IN ('SYS-GAPFILL', 'SYS-RECONCILE')
        GROUP BY variation_id
    ) latest ON latest.variation_id = m.variation_id AND latest.last_id = m.movement_id
    LEFT JOIN inventory i ON i.variation_id = m.variation_id AND i.store_id = 1
    WHERE ABS(COALESCE(i.quantity, 0) - m.new_stock) >= 1
");
$toFix = $lastMovStmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($toFix as $row) {
    $conn->prepare("
        INSERT INTO inventory (variation_id, store_id, quantity)
        VALUES (?, 1, ?)
        ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)
    ")->execute([$row['variation_id'], $row['new_stock']]);
    $inventoryFixed++;
    $inventoryReport[] = [
        'variation_id' => $row['variation_id'],
        'was'          => $row['current_inventory'],
        'corrected_to' => $row['new_stock'],
    ];
}

// ─────────────────────────────────────────────────────────────
// STEP 3: Re-run gap-fill on CLEAN data
// ─────────────────────────────────────────────────────────────
$varStmt = $conn->query("
    SELECT DISTINCT variation_id FROM stock_movements
    WHERE store_id = 1
      AND status = 'active'
      AND type NOT IN ('online_sale', 'online_adjustment')
      AND reference NOT IN ('SYS-GAPFILL', 'SYS-RECONCILE')
");
$variationIds = array_column($varStmt->fetchAll(PDO::FETCH_ASSOC), 'variation_id');

$gapsFilled = 0;
$gapReport  = [];

foreach ($variationIds as $variationId) {
    $movStmt = $conn->prepare("
        SELECT movement_id, type, quantity, previous_stock, new_stock, created_at
        FROM stock_movements
        WHERE variation_id = ?
          AND store_id = 1
          AND status = 'active'
          AND type NOT IN ('online_sale', 'online_adjustment')
          AND reference NOT IN ('SYS-GAPFILL', 'SYS-RECONCILE')
        ORDER BY created_at ASC, movement_id ASC
    ");
    $movStmt->execute([$variationId]);
    $movements = $movStmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($movements) < 2) continue;

    for ($i = 1; $i < count($movements); $i++) {
        $older = $movements[$i - 1];
        $newer = $movements[$i];

        $expectedPrev = (float)$older['new_stock'];
        $actualPrev   = (float)$newer['previous_stock'];

        if (abs($expectedPrev - $actualPrev) >= 1) {
            $gap     = $actualPrev - $expectedPrev;
            $gapType = $gap > 0 ? 'allocation_to_physical' : 'allocation_to_online';
            $remarks = $gap > 0
                ? "Gap fill: " . abs($gap) . " unit(s) returned from Shopee/online allocation (unlogged historical change)"
                : "Gap fill: " . abs($gap) . " unit(s) allocated to Shopee/online (unlogged historical change)";

            $gapTime = date('Y-m-d H:i:s', strtotime($older['created_at']) + 1);

            try {
                $conn->prepare("
                    INSERT INTO stock_movements
                    (variation_id, store_id, type, quantity, previous_stock, new_stock,
                     reference, remarks, created_by, created_at)
                    VALUES (?, 1, ?, ?, ?, ?, 'SYS-GAPFILL', ?, ?, ?)
                ")->execute([
                    $variationId, $gapType, $gap,
                    $expectedPrev, $actualPrev,
                    $remarks, $userId, $gapTime
                ]);

                $gapsFilled++;
                $gapReport[] = [
                    'variation_id' => $variationId,
                    'gap'          => ($gap > 0 ? '+' : '') . $gap,
                    'from'         => $expectedPrev,
                    'to'           => $actualPrev,
                    'at'           => $gapTime,
                ];

                // Re-fetch and restart scan for this variation
                $movStmt->execute([$variationId]);
                $movements = $movStmt->fetchAll(PDO::FETCH_ASSOC);
                $i = 0;

            } catch (Exception $e) {}
        }
    }
}

header('Content-Type: application/json');
echo json_encode([
    'success'              => true,
    'step1_deleted_gapfill'   => $deletedGapFill,
    'step1_deleted_reconcile' => $deletedReconcile,
    'step2_inventory_fixed'   => $inventoryFixed,
    'step2_inventory_report'  => $inventoryReport,
    'step3_gaps_filled'       => $gapsFilled,
    'step3_gap_report'        => $gapReport,
], JSON_PRETTY_PRINT);

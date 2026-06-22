<?php
/**
 * fill_history_gaps.php
 *
 * Detects breaks in the stock movement chain where the balance jumps without
 * an explanation (e.g. previous_stock of a newer entry doesn't match new_stock
 * of the immediately older entry). Inserts a descriptive gap-fill movement so
 * the history reads correctly.
 *
 * Run ONCE on the live server from the browser (must be logged in as admin).
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

// Get all variation IDs that have at least 2 movements in store_id = 1
$varStmt = $conn->query("
    SELECT DISTINCT variation_id
    FROM stock_movements
    WHERE store_id = 1
      AND status = 'active'
      AND type NOT IN ('online_sale', 'online_adjustment')
");
$variationIds = $varStmt->fetchColumn() !== false
    ? array_column($varStmt->fetchAll(PDO::FETCH_ASSOC), 'variation_id')
    : [];

// Re-fetch properly
$varStmt2 = $conn->query("
    SELECT DISTINCT variation_id
    FROM stock_movements
    WHERE store_id = 1
      AND status = 'active'
      AND type NOT IN ('online_sale', 'online_adjustment')
");
$variationIds = array_column($varStmt2->fetchAll(PDO::FETCH_ASSOC), 'variation_id');

$totalFixed = 0;
$report     = [];
$errors     = [];
$maxFixes   = 500;

foreach ($variationIds as $variationId) {
    if ($totalFixed >= $maxFixes) break;

    // Fetch movements for this variation ordered oldest→newest
    $movStmt = $conn->prepare("
        SELECT movement_id, type, quantity, previous_stock, new_stock, created_at
        FROM stock_movements
        WHERE variation_id = ?
          AND store_id = 1
          AND status = 'active'
          AND type NOT IN ('online_sale', 'online_adjustment')
          AND reference != 'SYS-GAPFILL'
        ORDER BY created_at ASC, movement_id ASC
    ");
    $movStmt->execute([$variationId]);
    $movements = $movStmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($movements) < 2) continue;

    for ($i = 1; $i < count($movements); $i++) {
        if ($totalFixed >= $maxFixes) break;
        $older  = $movements[$i - 1]; // earlier movement
        $newer  = $movements[$i];     // later movement

        $expectedPrev = (float)$older['new_stock'];
        $actualPrev   = (float)$newer['previous_stock'];

        // If the chain is broken (gap >= 1 unit)
        if (abs($expectedPrev - $actualPrev) >= 1) {
            $gap     = $actualPrev - $expectedPrev; // how much the stock jumped without a log
            $gapAbs  = abs($gap);

            // Determine gap type
            if ($gap > 0) {
                $gapType = 'allocation_to_physical'; // stock appeared = Shopee returned to POS
                $remarks = "Gap fill: {$gapAbs} unit(s) returned from Shopee/online allocation (unlogged historical change)";
            } else {
                $gapType = 'allocation_to_online'; // stock disappeared = allocated to Shopee
                $remarks = "Gap fill: {$gapAbs} unit(s) allocated to Shopee/online (unlogged historical change)";
            }

            // Place the gap-fill movement 1 second after the older movement
            $gapTime = date('Y-m-d H:i:s', strtotime($older['created_at']) + 1);

            try {
                $conn->prepare("
                    INSERT INTO stock_movements
                    (variation_id, store_id, type, quantity, previous_stock, new_stock,
                     reference, remarks, created_by, created_at)
                    VALUES (?, 1, ?, ?, ?, ?, 'SYS-GAPFILL', ?, ?, ?)
                ")->execute([
                    $variationId,
                    $gapType,
                    $gap,
                    $expectedPrev,
                    $actualPrev,
                    $remarks,
                    $userId,
                    $gapTime
                ]);

                $totalFixed++;
                $report[] = [
                    'variation_id' => $variationId,
                    'gap_type'     => $gapType,
                    'from'         => $expectedPrev,
                    'to'           => $actualPrev,
                    'diff'         => ($gap > 0 ? '+' : '') . $gap,
                    'inserted_at'  => $gapTime,
                    'older_mov'    => $older['movement_id'],
                    'newer_mov'    => $newer['movement_id'],
                ];
            } catch (Exception $e) {
                $errors[] = "variation_id {$variationId}: " . $e->getMessage();
            }
        }
    }
}

header('Content-Type: application/json');
echo json_encode([
    'success'     => true,
    'gaps_filled' => $totalFixed,
    'errors'      => $errors,
    'details'     => $report,
], JSON_PRETTY_PRINT);

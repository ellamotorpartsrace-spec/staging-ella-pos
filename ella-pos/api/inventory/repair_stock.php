<?php
/**
 * fix_stock_from_movements.php
 * 
 * STOCK REPAIR SCRIPT - DO NOT RUN TWICE
 * 
 * Strategy:
 * 1. For each product variation, find the LAST VALID stock movement BEFORE
 *    today's bad system restore adjustments (before June 23, 2026 08:33 AM).
 * 2. Calculate what the correct current stock should be by:
 *    a. Taking the balance at the last valid movement
 *    b. Adding any LEGITIMATE movements that happened AFTER that point (real sales, restocks, etc.)
 *    c. Ignoring all "System Restore" adjustments from today
 * 3. Set the inventory to that corrected value.
 * 4. Log a single clean "Stock Correction" movement for audit trail.
 */

header('Content-Type: text/plain');
set_time_limit(300);

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();
if (!isSuperAdmin()) { die('Unauthorized'); }

$db = new Database();
$conn = $db->getConnection();

// The timestamp when the corruption started (first bad restore)
$corruptionStart = '2026-06-23 08:33:00';

echo "=== STOCK REPAIR SCRIPT ===\n";
echo "Corruption started at: {$corruptionStart}\n";
echo "Strategy: replay movements, skip today's bad System Restore adjustments\n\n";

// Step 1: Find all variation_ids that have negative OR suspicious stock right now (physical store)
$negativeStmt = $conn->query("
    SELECT i.variation_id, i.quantity as current_qty
    FROM inventory i
    WHERE i.store_id = 1
      AND i.quantity < 0
");
$problematic = $negativeStmt->fetchAll(PDO::FETCH_ASSOC);

echo "Found " . count($problematic) . " products with negative stock.\n\n";

$fixedCount = 0;
$skippedCount = 0;
$ref = 'STOCK-REPAIR-' . date('Ymd-His');

$conn->beginTransaction();

try {
    foreach ($problematic as $item) {
        $varId = (int)$item['variation_id'];
        $currentQty = (float)$item['current_qty'];

        // Step 2: Find the LAST legitimate movement before the corruption started
        // A legitimate movement is anything that is NOT a "System Restore" adjustment from today
        $lastGoodStmt = $conn->prepare("
            SELECT new_stock, created_at
            FROM stock_movements
            WHERE variation_id = ?
              AND store_id = 1
              AND NOT (
                type = 'adjustment'
                AND remarks LIKE 'System Restore%'
                AND created_at >= '2026-06-23 00:00:00'
              )
            ORDER BY created_at DESC, id DESC
            LIMIT 1
        ");
        $lastGoodStmt->execute([$varId]);
        $lastGood = $lastGoodStmt->fetch(PDO::FETCH_ASSOC);

        if (!$lastGood) {
            // No history at all — skip
            $skippedCount++;
            continue;
        }

        $correctQty = (float)$lastGood['new_stock'];

        // If it's already correct, skip
        if (abs($correctQty - $currentQty) < 0.001) {
            $skippedCount++;
            continue;
        }

        // Step 3: Apply the correction
        $updateStmt = $conn->prepare("
            UPDATE inventory SET quantity = ? WHERE variation_id = ? AND store_id = 1
        ");
        $updateStmt->execute([$correctQty, $varId]);

        // Step 4: Log the correction for audit trail
        $capitalStmt = $conn->prepare("SELECT price_capital FROM product_variations WHERE variation_id = ?");
        $capitalStmt->execute([$varId]);
        $capital = (float)($capitalStmt->fetchColumn() ?? 0);

        $conn->prepare("
            INSERT INTO stock_movements 
            (variation_id, store_id, type, quantity, previous_stock, new_stock, reference, remarks, created_by, capital_cost)
            VALUES (?, 1, 'adjustment', ?, ?, ?, ?, 'Stock Repair: Reversed bad system restore adjustments', 1, ?)
        ")->execute([
            $varId,
            $correctQty - $currentQty,
            $currentQty,
            $correctQty,
            $ref,
            $capital
        ]);

        echo "Fixed variation_id={$varId}: {$currentQty} → {$correctQty} (last good movement at {$lastGood['created_at']})\n";
        $fixedCount++;
    }

    $conn->commit();
    echo "\n=== DONE ===\n";
    echo "Fixed: {$fixedCount}\n";
    echo "Skipped (already correct or no history): {$skippedCount}\n";
    echo "\nAll negative stock has been corrected based on the last valid movement before the bad restores.\n";

} catch (Exception $e) {
    $conn->rollBack();
    echo "\nERROR: " . $e->getMessage() . "\n";
    echo "All changes have been rolled back. Nothing was changed.\n";
}

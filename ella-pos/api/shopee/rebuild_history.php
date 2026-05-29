<?php
require_once '../../config/database.php';
$db = new Database();
$conn = $db->getConnection();

echo "<pre>";
echo "Starting Gap-Smoothing History Rebuild on " . ($_SERVER['HTTP_HOST'] ?? 'localhost') . "...\n";

// 1. Find all variation/store pairs
$stmt = $conn->query("SELECT DISTINCT variation_id, store_id FROM stock_movements");
$pairs = $stmt->fetchAll(PDO::FETCH_ASSOC);

$conn->beginTransaction();

try {
    $totalFixedPairs = 0;
    $totalFixedLogs = 0;

    foreach($pairs as $pair) {
        $varId = $pair['variation_id'];
        $storeId = $pair['store_id'];

        $movements = $conn->query("SELECT * FROM stock_movements WHERE variation_id = $varId AND store_id = $storeId ORDER BY created_at ASC, movement_id ASC")->fetchAll(PDO::FETCH_ASSOC);

        if (count($movements) === 0) continue;

        $runningGap = 0;
        $unfixedPrevNew = null;
        $pairFixed = false;
        $finalNewStock = 0;

        foreach($movements as $m) {
            $actualPrev = (int)$m['previous_stock'];
            $quantity = (int)$m['quantity'];
            $actualNew = (int)$m['new_stock'];

            // Detect gaps using the UNFIXED (database) values
            if ($unfixedPrevNew !== null && $actualPrev !== $unfixedPrevNew) {
                // There is a gap between the last row's new_stock and this row's previous_stock
                $gap = $actualPrev - $unfixedPrevNew;
                $runningGap += $gap;
            }

            if ($runningGap !== 0) {
                // Correct this log by removing the accumulated running gap
                $fixedPrev = $actualPrev - $runningGap;
                $fixedNew = $actualNew - $runningGap;

                $conn->prepare("UPDATE stock_movements SET previous_stock = ?, new_stock = ? WHERE movement_id = ?")
                     ->execute([$fixedPrev, $fixedNew, $m['movement_id']]);
                
                $totalFixedLogs++;
                $pairFixed = true;
                $finalNewStock = $fixedNew;
            } else {
                $finalNewStock = $actualNew;
            }

            // Save the unfixed actualNew for the next row's gap detection
            $unfixedPrevNew = $actualNew;
        }

        if ($pairFixed) {
            $totalFixedPairs++;
            
            // Sync the inventory table to match the final mathematically correct stock
            $conn->prepare("UPDATE inventory SET quantity = ? WHERE variation_id = ? AND store_id = ?")
                 ->execute([$finalNewStock, $varId, $storeId]);
                 
            echo "VarID: $varId, Store: $storeId | Smoothed gaps. Final stock set to $finalNewStock\n";
        }
    }

    $conn->commit();
    echo "\nGap-Smoothing Rebuild completed successfully!\n";
    echo "Fixed history gaps for $totalFixedPairs products.\n";
    echo "Corrected balances for $totalFixedLogs individual logs.\n";

} catch (Exception $e) {
    $conn->rollBack();
    echo "Error: " . $e->getMessage();
}
echo "</pre>";

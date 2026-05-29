<?php
require_once '../../config/database.php';
$db = new Database();
$conn = $db->getConnection();

echo "<pre>";
echo "Starting perfect history rebuild on " . $_SERVER['HTTP_HOST'] . "...\n";

$badTypes = "'shopee_sale', 'allocation_adjustment', 'shopee_sync'";

// Find all pairs that have bad logs
$stmt = $conn->query("
    SELECT DISTINCT variation_id, store_id 
    FROM stock_movements 
    WHERE type IN ($badTypes)
");
$pairs = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Found " . count($pairs) . " variation/store pairs with corrupted history.\n";

$conn->beginTransaction();

try {
    $totalFixedLogs = 0;
    $totalDeletedLogs = 0;

    foreach($pairs as $pair) {
        $varId = $pair['variation_id'];
        $storeId = $pair['store_id'];

        $movements = $conn->query("SELECT * FROM stock_movements WHERE variation_id = $varId AND store_id = $storeId ORDER BY created_at ASC, movement_id ASC")->fetchAll(PDO::FETCH_ASSOC);

        $runningBadEffect = 0;
        $badIds = [];

        foreach($movements as $m) {
            if (in_array($m['type'], ['shopee_sale', 'allocation_adjustment', 'shopee_sync'])) {
                $runningBadEffect += (int)$m['quantity'];
                $badIds[] = $m['movement_id'];
            } else {
                if ($runningBadEffect != 0) {
                    $fixedPrev = (int)$m['previous_stock'] - $runningBadEffect;
                    $fixedNew = (int)$m['new_stock'] - $runningBadEffect;

                    $conn->prepare("UPDATE stock_movements SET previous_stock = ?, new_stock = ? WHERE movement_id = ?")
                         ->execute([$fixedPrev, $fixedNew, $m['movement_id']]);
                    $totalFixedLogs++;
                }
            }
        }

        if ($runningBadEffect != 0) {
            $conn->prepare("UPDATE inventory SET quantity = quantity - ? WHERE variation_id = ? AND store_id = ?")
                 ->execute([$runningBadEffect, $varId, $storeId]);
            echo "VarID: $varId, Store: $storeId | Reversed $runningBadEffect from inventory.\n";
        }

        if (!empty($badIds)) {
            $placeholders = str_repeat('?,', count($badIds) - 1) . '?';
            $conn->prepare("DELETE FROM stock_movements WHERE movement_id IN ($placeholders)")->execute($badIds);
            $totalDeletedLogs += count($badIds);
        }
    }

    $conn->commit();
    echo "\nPerfect rebuild completed successfully!\n";
    echo "Deleted $totalDeletedLogs corrupted logs.\n";
    echo "Fixed balances for $totalFixedLogs valid logs.\n";

} catch (Exception $e) {
    $conn->rollBack();
    echo "Error: " . $e->getMessage();
}
echo "</pre>";

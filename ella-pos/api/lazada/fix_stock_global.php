<?php
require_once '../../config/database.php';
$db = new Database();
$conn = $db->getConnection();

echo "<pre>";
echo "Starting Global Stock Fix...\n";

// 1. Find all bad movements
$badTypes = "'lazada_sale', 'allocation_adjustment', 'lazada_sync'";
$stmt = $conn->query("
    SELECT variation_id, store_id, SUM(quantity) as net_effect, COUNT(*) as num_logs
    FROM stock_movements 
    WHERE type IN ($badTypes)
    GROUP BY variation_id, store_id
");

$adjustments = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "Found " . count($adjustments) . " variations/stores with corrupted stock.\n";

$conn->beginTransaction();

try {
    foreach($adjustments as $adj) {
        $varId = $adj['variation_id'];
        $storeId = $adj['store_id'];
        $netEffect = (int)$adj['net_effect'];
        $numLogs = $adj['num_logs'];

        // Get current inventory
        $invStmt = $conn->prepare("SELECT quantity FROM inventory WHERE variation_id = ? AND store_id = ?");
        $invStmt->execute([$varId, $storeId]);
        $currentInv = $invStmt->fetchColumn();

        if ($currentInv !== false) {
            $newInv = (int)$currentInv - $netEffect;
            
            // Update inventory
            $upd = $conn->prepare("UPDATE inventory SET quantity = ? WHERE variation_id = ? AND store_id = ?");
            $upd->execute([$newInv, $varId, $storeId]);
            
            echo "VarID: $varId, Store: $storeId | Removed $numLogs bad logs | Effect: $netEffect | Stock: $currentInv -> $newInv\n";
        }
    }

    // 2. Delete all bad movements
    $del = $conn->exec("DELETE FROM stock_movements WHERE type IN ($badTypes)");
    echo "\nDeleted $del bad log rows from stock_movements.\n";

    $conn->commit();
    echo "Fix applied successfully.\n";

} catch (Exception $e) {
    $conn->rollBack();
    echo "Error: " . $e->getMessage();
}
echo "</pre>";

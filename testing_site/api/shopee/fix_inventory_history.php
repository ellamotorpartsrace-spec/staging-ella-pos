<?php
require_once '../../config/config.php';
require_once '../../includes/auth.php';
requirePermission('admin'); // Ensure only admins can run this

$db = new Database();
$conn = $db->getConnection();

echo "<pre>";
echo "Starting Inventory Sync to History...\n";

$stmt = $conn->query('SELECT variation_id, store_id FROM inventory');
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
$fixed = 0;

$conn->beginTransaction();
try {
    foreach($rows as $row) {
        $varId = $row['variation_id'];
        $storeId = $row['store_id'];
        
        $stmt2 = $conn->query("SELECT new_stock FROM stock_movements WHERE variation_id = $varId AND store_id = $storeId ORDER BY created_at DESC, movement_id DESC LIMIT 1");
        $lastLog = $stmt2->fetch(PDO::FETCH_ASSOC);
        
        if ($lastLog !== false) {
            $stmtCheck = $conn->prepare("SELECT quantity FROM inventory WHERE variation_id = ? AND store_id = ?");
            $stmtCheck->execute([$varId, $storeId]);
            $current = $stmtCheck->fetchColumn();
            
            if ($current != $lastLog['new_stock']) {
                $conn->prepare('UPDATE inventory SET quantity = ? WHERE variation_id = ? AND store_id = ?')
                     ->execute([$lastLog['new_stock'], $varId, $storeId]);
                echo "Updated Variation ID $varId (Store $storeId) from $current to {$lastLog['new_stock']}\n";
                $fixed++;
            }
        }
    }
    $conn->commit();
    echo "\nDone. Fixed $fixed records.\n";
} catch (Exception $e) {
    $conn->rollBack();
    echo "Error: " . $e->getMessage() . "\n";
}
echo "</pre>";

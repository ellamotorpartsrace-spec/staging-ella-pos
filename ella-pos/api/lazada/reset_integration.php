<?php
// api/lazada/reset_integration.php — Securely wipe all imported Lazada data and start fresh
header('Content-Type: application/json');
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';

// Secure: check login and role
requireLogin();

if (!hasPermission('lazada_sync')) {
    echo json_encode(['success' => false, 'error' => 'Permission denied. Please request access from admin Les.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request method.']);
    exit;
}

try {
    $db = new Database();
    $conn = $db->getConnection();
    
    // Disable foreign key checks for clean truncation
    $conn->exec("SET FOREIGN_KEY_CHECKS = 0");
    
    // 1. Revert all online allocations (store_id = 3) back to physical stock (store_id = 1)
    $stmt = $conn->query("
        SELECT i.variation_id, i.quantity AS online_qty,
               COALESCE(i_phys.quantity, 0) AS physical_qty
        FROM inventory i
        LEFT JOIN inventory i_phys ON i_phys.variation_id = i.variation_id AND i_phys.store_id = 1
        WHERE i.store_id = 3 AND i.quantity > 0
    ");
    $orphans = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($orphans as $row) {
        $varId     = (int)$row['variation_id'];
        $onlineQty = (float) $row['online_qty'];
        $physQty   = (float) $row['physical_qty'];

        // Zero out online inventory
        $conn->prepare("UPDATE inventory SET quantity = 0 WHERE variation_id = ? AND store_id = 3")
             ->execute([$varId]);

        // Restore physical stock
        $newPhysQty = $physQty + $onlineQty;
        $conn->prepare("
            INSERT INTO inventory (variation_id, store_id, quantity)
            VALUES (?, 1, ?)
            ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)
        ")->execute([$varId, $newPhysQty]);

        // Log the cleanup movement
        $conn->prepare("
            INSERT INTO stock_movements
            (variation_id, store_id, type, quantity, previous_stock, new_stock, reference, remarks, created_by, capital_cost)
            VALUES (?, 3, 'allocation_to_physical', ?, ?, 0, ?, 'System Reset: restored online allocation back to physical store', ?, 0)
        ")->execute([
            $varId,
            -$onlineQty,
            $onlineQty,
            'LAZ-RESET-' . date('YmdHis') . '-' . $varId,
            $_SESSION['user_id'] ?? null
        ]);
    }
    
    // Wipe core Lazada related tables safely
    $tables = [
        'lazada_product_mappings',
        'lazada_error_logs',
        'lazada_sync_queues',
        'lazada_sync_logs',
        'lazada_audit_logs',
        'lazada_saved_filters',
        'lazada_reserved_stock',
        'lazada_duplicate_whitelist',
        'lazada_alerts'
    ];
    
    foreach ($tables as $table) {
        try {
            // Check if table exists first to avoid exception abortion
            $result = $conn->query("SHOW TABLES LIKE '$table'");
            if ($result && $result->rowCount() > 0) {
                $conn->exec("TRUNCATE TABLE `$table`");
            }
        } catch (Exception $ex) {
            // Silently ignore if table doesn't exist
        }
    }
    
    // Re-enable foreign key checks
    $conn->exec("SET FOREIGN_KEY_CHECKS = 1");
    
    // 5. Add a fresh audit log entry to record the system wipe
    $stmt = $conn->prepare("
        INSERT INTO lazada_sync_logs (event_type, source, status, new_value, created_by, created_at)
        VALUES ('system_reset', 'Database Administrator', 'success', 'All Lazada sync data including products, mappings, conflict logs, allocations, and queues were wiped.', ?, NOW())
    ");
    $stmt->execute([$_SESSION['user_id'] ?? null]);
    
    echo json_encode(['success' => true, 'message' => 'Integration data successfully wiped. You can now perform a fresh sync!']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Wipe failed: ' . $e->getMessage()]);
}

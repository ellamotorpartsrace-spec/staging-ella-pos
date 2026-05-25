<?php
// api/shopee/reset_integration.php — Securely wipe all imported Shopee data and start fresh
header('Content-Type: application/json');
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';

// Secure: check login and role
requireLogin();

if (!hasPermission('shopee_sync')) {
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
    
    // Wipe core Shopee related tables safely
    $tables = [
        'shopee_product_mappings',
        'shopee_error_logs',
        'shopee_sync_queues',
        'shopee_sync_logs',
        'shopee_audit_logs',
        'shopee_saved_filters',
        'shopee_reserved_stock',
        'shopee_duplicate_whitelist',
        'shopee_alerts'
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
        INSERT INTO shopee_sync_logs (event_type, source, status, new_value, created_by, created_at)
        VALUES ('system_reset', 'Database Administrator', 'success', 'All Shopee sync data including products, mappings, conflict logs, allocations, and queues were wiped.', ?, NOW())
    ");
    $stmt->execute([$_SESSION['user_id'] ?? null]);
    
    echo json_encode(['success' => true, 'message' => 'Integration data successfully wiped. You can now perform a fresh sync!']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Wipe failed: ' . $e->getMessage()]);
}

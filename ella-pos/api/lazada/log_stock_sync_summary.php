<?php
/**
 * api/lazada/log_stock_sync_summary.php — Record a single consolidated manual sync summary in lazada_sync_logs
 */
header("Content-Type: application/json");
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';

requireLogin();

if (!hasPermission('lazada_sync')) {
    echo json_encode(['success' => false, 'error' => 'Permission denied.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$successCount = isset($input['success_count']) ? (int)$input['success_count'] : 0;
$changedCount = isset($input['changed_count']) ? (int)$input['changed_count'] : 0;
$failCount = isset($input['fail_count']) ? (int)$input['fail_count'] : 0;
$total = $successCount + $failCount;

if ($total === 0) {
    echo json_encode(['success' => false, 'error' => 'No item count provided']);
    exit;
}

try {
    $db = new Database();
    $conn = $db->getConnection();

    $status = ($successCount > 0) ? 'success' : 'failed';
    
    $summary = "Checked {$successCount} of {$total} mapped items. {$changedCount} had stock updates.";
    if ($failCount > 0) {
        $summary .= " {$failCount} item(s) failed to sync.";
    }

    $stmt = $conn->prepare("
        INSERT INTO lazada_sync_logs 
            (event_type, product_name, sku, old_value, new_value, source, status, created_by, created_at)
        VALUES 
            ('stock_update', 'Lazada Stock Allocation Sync', 'All Mapped Items', '—', ?, 'Live Sync Manual Fetch', ?, ?, NOW())
    ");
    
    $stmt->execute([
        $summary,
        $status,
        $_SESSION['user_id'] ?? null
    ]);

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

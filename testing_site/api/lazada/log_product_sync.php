<?php
/**
 * api/lazada/log_product_sync.php — Record a product sync summary in lazada_sync_logs
 */
header("Content-Type: application/json");
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';

requireLogin();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$platform = $_SESSION['lazada_active_platform'] ?? 'lazada_main';

$input   = json_decode(file_get_contents('php://input'), true);
$status  = $input['status']  ?? 'success';   // 'success' | 'failed'
$summary = $input['summary'] ?? '';           // e.g. "50 Synced (12 New, 38 Updated)"
$error   = $input['error']   ?? '';           // populated on failure

if (!$summary && !$error) {
    echo json_encode(['success' => false, 'error' => 'No summary provided']);
    exit;
}

try {
    $db   = new Database();
    $conn = $db->getConnection();

    if ($status === 'failed') {
        $conn->prepare("
            INSERT INTO lazada_sync_logs
                (platform_name, event_type, product_name, source, status, error_message, created_by, created_at)
            VALUES
                (?, 'product_import', 'Product Sync (Products Page)', 'Manual Sync (Products Page)', 'failed', ?, ?, NOW())
        ")->execute([$platform, $error ?: 'Unknown error', $_SESSION['user_id'] ?? null]);
    } else {
        $conn->prepare("
            INSERT INTO lazada_sync_logs
                (platform_name, event_type, product_name, new_value, source, status, created_by, created_at)
            VALUES
                (?, 'product_import', 'Product Sync (Products Page)', ?, 'Manual Sync (Products Page)', 'success', ?, NOW())
        ")->execute([$platform, $summary, $_SESSION['user_id'] ?? null]);
    }

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

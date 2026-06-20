<?php
/**
 * api/lazada/run_order_watcher.php
 * DISABLED — Order watcher is no longer needed.
 *
 * Lazada manages its own stock natively (orders deduct, cancellations restore).
 * The lazada_reserved_stock system has been removed to prevent stock fluctuation.
 */
header('Content-Type: application/json');
echo json_encode([
    'success' => false,
    'error'   => 'Order watcher is disabled. Lazada manages stock natively — reserved stock tracking is no longer needed.'
]);


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

    // Determine CLI path for PHP
    $phpPath = 'C:\\xampp\\php\\php.exe';
    $scriptPath = __DIR__ . '/../../scripts/lazada_order_watcher.php';
    
    if (!file_exists($scriptPath)) {
        throw new Exception("Order watcher script not found.");
    }

    $cmd = escapeshellcmd("$phpPath $scriptPath");
    $output = shell_exec($cmd);

    // Log the successful manual sync
    $logStmt = $conn->prepare("INSERT INTO lazada_sync_logs (event_type, source, status, created_by, created_at) VALUES ('order_sync', 'Manual Sync (Orders)', 'success', ?, NOW())");
    $logStmt->execute([$_SESSION['user_id'] ?? null]);

    echo json_encode([
        'success' => true,
        'message' => 'Lazada orders successfully synced!',
        'log' => $output
    ]);
} catch (Exception $e) {
    // Log the failed sync if database is available
    try {
        $db = new Database();
        $conn = $db->getConnection();
        $logStmt = $conn->prepare("INSERT INTO lazada_sync_logs (event_type, source, status, error_message, created_by, created_at) VALUES ('order_sync', 'Manual Sync (Orders)', 'failed', ?, ?, NOW())");
        $logStmt->execute([$e->getMessage(), $_SESSION['user_id'] ?? null]);
    } catch (Exception $dbEx) {
        // Ignore DB log failures to avoid masking main exception
    }

    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

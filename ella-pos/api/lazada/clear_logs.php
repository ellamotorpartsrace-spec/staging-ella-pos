<?php
// api/lazada/clear_logs.php — Securely delete all sync logs
header('Content-Type: application/json');
require_once '../../config/config.php';
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
    
    // Clear all logs in lazada_sync_logs
    $stmt = $conn->prepare("DELETE FROM lazada_sync_logs");
    $stmt->execute();
    
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

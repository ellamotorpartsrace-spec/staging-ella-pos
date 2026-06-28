<?php
/**
 * api/shopee/mark_alert_read.php
 * Mark an alert as read so it doesn't pop up again.
 */
header("Content-Type: application/json");
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';

requireLogin();

$input = json_decode(file_get_contents('php://input'), true);
$alertIds = $input['alert_ids'] ?? [];

if (empty($alertIds) || !is_array($alertIds)) {
    echo json_encode(['success' => false, 'error' => 'No alert IDs provided']);
    exit;
}

try {
    $db = new Database();
    $conn = $db->getConnection();

    $placeholders = implode(',', array_fill(0, count($alertIds), '?'));
    $stmt = $conn->prepare("UPDATE shopee_alerts SET is_read = 1 WHERE id IN ($placeholders)");
    $stmt->execute($alertIds);

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

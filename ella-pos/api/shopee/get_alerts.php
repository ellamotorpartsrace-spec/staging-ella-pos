<?php
/**
 * api/shopee/get_alerts.php
 * Fetch unread Shopee alerts for the frontend UI.
 */
header("Content-Type: application/json");
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';

requireLogin();

try {
    $db = new Database();
    $conn = $db->getConnection();

    $stmt = $conn->prepare("SELECT id, message, alert_type, created_at FROM shopee_alerts WHERE is_read = 0 ORDER BY created_at ASC");
    $stmt->execute();
    $alerts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'alerts' => $alerts]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

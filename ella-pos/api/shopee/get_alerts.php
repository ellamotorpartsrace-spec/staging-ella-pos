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

    // Check if token is expired
    $tokenExpired = false;
    $cfgStmt = $conn->query("SELECT token_expires_at FROM shopee_config WHERE is_active=1 LIMIT 1");
    $cfg = $cfgStmt->fetch(PDO::FETCH_ASSOC);
    if ($cfg && !empty($cfg['token_expires_at']) && strtotime($cfg['token_expires_at']) < time()) {
        $tokenExpired = true;
    }

    echo json_encode(['success' => true, 'alerts' => $alerts, 'token_expired' => $tokenExpired]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

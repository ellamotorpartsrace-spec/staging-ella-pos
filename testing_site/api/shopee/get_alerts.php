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

    // Dynamically create the table if it's missing on Hostinger
    $conn->exec("
        CREATE TABLE IF NOT EXISTS shopee_alerts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            mapping_id INT NULL,
            platform_name VARCHAR(50) DEFAULT 'shopee_main',
            message TEXT NOT NULL,
            alert_type VARCHAR(50) DEFAULT 'warning',
            is_read TINYINT(1) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $platform = $_SESSION['shopee_active_platform'] ?? 'shopee_main';

    $stmt = $conn->prepare("SELECT id, message, alert_type, created_at FROM shopee_alerts WHERE platform_name = ? AND is_read = 0 ORDER BY created_at ASC");
    $stmt->execute([$platform]);
    $alerts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Check if token is expired
    $tokenExpired = false;
    $cfgStmt = $conn->prepare("SELECT token_expires_at FROM shopee_config WHERE platform_name=? LIMIT 1");
    $cfgStmt->execute([$platform]);
    $cfg = $cfgStmt->fetch(PDO::FETCH_ASSOC);
    if ($cfg && !empty($cfg['token_expires_at']) && strtotime($cfg['token_expires_at']) < time()) {
        $tokenExpired = true;
    }

    echo json_encode(['success' => true, 'alerts' => $alerts, 'token_expired' => $tokenExpired]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

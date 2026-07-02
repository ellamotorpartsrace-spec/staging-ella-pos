<?php
/**
 * api/lazada/refresh_token.php
 */
header("Content-Type: application/json");
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../classes/LazadaAPI.php';
require_once '../../includes/auth.php';

requireLogin();
requireRole(['admin', 'super_admin']);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$platform = $_SESSION['lazada_active_platform'] ?? 'lazada_main';

try {
    $db = new Database();
    $conn = $db->getConnection();

    $stmt = $conn->prepare("SELECT * FROM lazada_config WHERE platform_name = ? LIMIT 1");
    $stmt->execute([$platform]);
    $config = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$config || empty($config['refresh_token'])) {
        echo json_encode(['success' => false, 'error' => 'No refresh token available. Please authorize your shop first.']);
        exit;
    }

    $api = new LazadaAPI($config['app_key'], $config['app_secret'], $config['country_code'], $config['environment'] === 'sandbox');
    
    $response = $api->refreshAccessToken($config['refresh_token']);

    if (isset($response['code']) && $response['code'] === '0' && isset($response['access_token'])) {
        $access_token = $response['access_token'];
        $refresh_token = $response['refresh_token'];
        $expires_in = $response['expires_in'];
        $refresh_expires_in = $response['refresh_expires_in'];
        
        $expires_at = date('Y-m-d H:i:s', time() + $expires_in);
        $refresh_expires_at = date('Y-m-d H:i:s', time() + $refresh_expires_in);

        $update = $conn->prepare("UPDATE lazada_config SET 
            access_token = ?, 
            refresh_token = ?, 
            token_expires_at = ?, 
            refresh_expires_at = ?,
            updated_at = NOW() 
            WHERE id = ?");
        $update->execute([$access_token, $refresh_token, $expires_at, $refresh_expires_at, $config['id']]);

        $logSync = $conn->prepare("INSERT INTO lazada_sync_logs (platform_name, event_type, source, status, created_by, created_at) VALUES (?, 'token_refresh', 'Manual Refresh', 'success', ?, NOW())");
        $logSync->execute([$platform, $_SESSION['user_id'] ?? null]);

        echo json_encode(['success' => true, 'message' => 'Tokens refreshed successfully.', 'expires_at' => $expires_at]);
    } else {
        $errMsg = $response['message'] ?? 'Unknown API Error';
        
        $logSync = $conn->prepare("INSERT INTO lazada_sync_logs (platform_name, event_type, source, status, error_message, created_by, created_at) VALUES (?, 'token_refresh', 'Manual Refresh', 'failed', ?, ?, NOW())");
        $logSync->execute([$platform, $errMsg, $_SESSION['user_id'] ?? null]);
        
        echo json_encode(['success' => false, 'error' => $errMsg]);
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}

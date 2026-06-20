<?php
/**
 * api/lazada/auth.php — Generate Lazada OAuth authorization URL
 */
header("Content-Type: application/json");
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../classes/LazadaAPI.php';

requireLogin();
if (!hasPermission('lazada_sync')) {
    echo json_encode(['success' => false, 'error' => 'Permission denied. Please request access from admin Les.']);
    exit;
}


try {
    $db = new Database();
    $conn = $db->getConnection();

    // Load active config
    $stmt = $conn->prepare("SELECT * FROM lazada_config WHERE is_active = 1 LIMIT 1");
    $stmt->execute();
    $config = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$config) {
        echo json_encode(['success' => false, 'error' => 'No Lazada credentials configured. Please save your Partner ID and Key first.']);
        exit;
    }

    $isTest = $config['environment'] === 'test';
    $lazada = new LazadaAPI($config['partner_id'], $config['partner_key'], $isTest);

    // Detect protocol (handle ngrok forwarding)
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || 
               (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') ||
               (strpos($_SERVER['HTTP_HOST'] ?? '', 'ngrok') !== false);
    
    $protocol = $isHttps ? "https://" : "http://";
    
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    
    // We construct the absolute URL to the callback handler
    // We construct the absolute URL to the callback handler
    // BASE_URL already contains the full absolute path (e.g. https://domain.com/)
    $callbackUrl = rtrim(BASE_URL, '/') . '/api/lazada/callback.php';

    $authUrl = $lazada->getAuthUrl($callbackUrl);

    echo json_encode([
        'success'     => true,
        'auth_url'    => $authUrl,
        'environment' => $config['environment'],
        'callback'    => $callbackUrl,
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

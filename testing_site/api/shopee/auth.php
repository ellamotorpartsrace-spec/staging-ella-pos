<?php
/**
 * api/shopee/auth.php — Generate Shopee OAuth authorization URL
 */
header("Content-Type: application/json");
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../classes/ShopeeAPI.php';

requireLogin();
if (!hasPermission('shopee_sync')) {
    echo json_encode(['success' => false, 'error' => 'Permission denied. Please request access from admin Les.']);
    exit;
}


try {
    $db = new Database();
    $conn = $db->getConnection();

    $platform = $_SESSION['shopee_active_platform'] ?? 'shopee_main';

    // Load active config
    $stmt = $conn->prepare("SELECT * FROM shopee_config WHERE platform_name = ? LIMIT 1");
    $stmt->execute([$platform]);
    $config = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$config) {
        echo json_encode(['success' => false, 'error' => 'No Shopee credentials configured. Please save your Partner ID and Key first.']);
        exit;
    }

    $isTest = $config['environment'] === 'test';
    $shopee = new ShopeeAPI($config['partner_id'], $config['partner_key'], $isTest);

    // Detect protocol (handle ngrok forwarding)
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || 
               (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') ||
               (strpos($_SERVER['HTTP_HOST'] ?? '', 'ngrok') !== false);
    
    $protocol = $isHttps ? "https://" : "http://";
    
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    
    // We construct the absolute URL to the callback handler
    // We construct the absolute URL to the callback handler
    // BASE_URL already contains the full absolute path (e.g. https://domain.com/)
    // We pass the platform in the state or as a GET param in callbackUrl
    $callbackUrl = rtrim(BASE_URL, '/') . '/api/shopee/callback.php?platform=' . urlencode($platform);

    $authUrl = $shopee->getAuthUrl($callbackUrl);

    echo json_encode([
        'success'     => true,
        'auth_url'    => $authUrl,
        'environment' => $config['environment'],
        'callback'    => $callbackUrl,
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

<?php
/**
 * api/lazada/auth.php - Redirect to Lazada OAuth authorization URL
 */
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../classes/LazadaAPI.php';

requireLogin();
requireRole(['admin', 'super_admin']);

try {
    $db = new Database();
    $conn = $db->getConnection();

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    $platform = $_SESSION['lazada_active_platform'] ?? 'lazada_main';

    $stmt = $conn->prepare("SELECT app_key, app_secret, country_code, environment FROM lazada_config WHERE platform_name = ?");
    $stmt->execute([$platform]);
    $config = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$config || empty($config['app_key']) || empty($config['app_secret'])) {
        die("Lazada App Key and Secret are not configured for " . htmlspecialchars($platform) . ". Please save them first.");
    }

    $api = new LazadaAPI($config['app_key'], $config['app_secret'], $config['country_code'], $config['environment'] === 'sandbox');
    
    // Build redirect URI to oauth_callback.php using BASE_URL
    $redirect_url = BASE_URL . 'api/lazada/oauth_callback.php';

    $authUrl = $api->getAuthUrl($redirect_url);
    
    header("Location: " . $authUrl);
    exit;

} catch (Exception $e) {
    die("Error initializing Lazada Auth: " . $e->getMessage());
}

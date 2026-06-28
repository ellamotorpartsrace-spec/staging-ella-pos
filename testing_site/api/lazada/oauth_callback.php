<?php
/**
 * api/lazada/oauth_callback.php — Handle Lazada OAuth Redirect
 */
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../classes/LazadaAPI.php';

// Get auth code from URL
$code = $_GET['code'] ?? '';
$error = $_GET['error'] ?? '';

if (empty($code)) {
    die("Authorization failed or no code provided. " . htmlspecialchars($error));
}

try {
    $db = new Database();
    $conn = $db->getConnection();

    // Get config
    $stmt = $conn->query("SELECT * FROM lazada_config WHERE is_active = 1 LIMIT 1");
    $config = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$config || empty($config['app_key']) || empty($config['app_secret'])) {
        die("Lazada App Key and Secret are not configured in Settings.");
    }

    $api = new LazadaAPI($config['app_key'], $config['app_secret'], $config['country_code'], $config['environment'] === 'sandbox');

    // Exchange auth code for access token
    $response = $api->call('/auth/token/create', [
        'code' => $code
    ], 'POST');

    if (isset($response['code']) && $response['code'] === '0' && isset($response['access_token'])) {
        // Success
        $access_token = $response['access_token'];
        $refresh_token = $response['refresh_token'];
        $expires_in = $response['expires_in']; // typically 604800 (7 days)
        $account_id = $response['account_id'] ?? '';

        $expires_at = date('Y-m-d H:i:s', time() + $expires_in);

        $update = $conn->prepare("UPDATE lazada_config SET 
            access_token = ?, 
            refresh_token = ?, 
            token_expires_at = ?, 
            seller_id = ?,
            updated_at = NOW() 
            WHERE id = ?");
            
        $update->execute([
            $access_token, 
            $refresh_token, 
            $expires_at, 
            $account_id,
            $config['id']
        ]);

        // Redirect back to settings with success
        header("Location: " . BASE_URL . "views/lazada/settings.php?auth=success&seller_id=" . urlencode($account_id));
        exit;
    } else {
        $errMsg = $response['message'] ?? json_encode($response);
        die("Lazada API Error: " . htmlspecialchars($errMsg));
    }

} catch (Exception $e) {
    die("Database error: " . $e->getMessage());
}

<?php
declare(strict_types=1);

require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../classes/LazadaAPI.php';

requireLogin();
if (!in_array($_SESSION['role'], ['admin', 'super_admin'])) {
    http_response_code(403);
    die("Permission Denied");
}

try {
    $db = new Database();
    $conn = $db->getConnection();

    $stmt = $conn->query("SELECT partner_id, partner_key, is_test, is_active FROM api_platforms WHERE platform_name = 'lazada'");
    $lazada = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$lazada || empty($lazada['partner_id']) || empty($lazada['partner_key'])) {
        die("Lazada Partner ID and Key not configured. Please save them first.");
    }

    $is_test = (bool)$lazada['is_test'];
    $api = new LazadaAPI($lazada['partner_id'], $lazada['partner_key'], $is_test);
    
    // Webhook/Callback URL points back to our own system
    // Using a dynamic or static base path depending on the host
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    $redirect_url = $protocol . '://' . $host . rtrim(dirname(dirname($_SERVER['PHP_SELF'])), '/\\') . '/system/lazada_auth_callback.php';

    $authUrl = $api->getAuthUrl($redirect_url);
    
    header("Location: " . $authUrl);
    exit;

} catch (Exception $e) {
    die("Error initializing Lazada Auth: " . $e->getMessage());
}

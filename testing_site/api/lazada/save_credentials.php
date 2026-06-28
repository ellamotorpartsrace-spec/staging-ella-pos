<?php
/**
 * api/lazada/save_credentials.php — Save Lazada API credentials
 */
header("Content-Type: application/json");
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';

requireLogin();
requireRole(['admin', 'super_admin']);

try {
    $db = new Database();
    $conn = $db->getConnection();

    $data = json_decode(file_get_contents('php://input'), true);

    $appKey     = trim($data['app_key'] ?? '');
    $appSecret  = trim($data['app_secret'] ?? '');
    $country    = trim($data['country'] ?? 'PH');
    $env        = trim($data['environment'] ?? 'sandbox');

    if (empty($appKey) || empty($appSecret)) {
        echo json_encode(['success' => false, 'error' => 'App Key and App Secret are required.']);
        exit;
    }

    // Check if record exists
    $stmt = $conn->query("SELECT id FROM lazada_config LIMIT 1");
    $exists = $stmt->fetchColumn();

    if ($exists) {
        $update = $conn->prepare("UPDATE lazada_config SET app_key = ?, app_secret = ?, country_code = ?, environment = ?, updated_at = NOW() WHERE id = ?");
        $update->execute([$appKey, $appSecret, $country, $env, $exists]);
    } else {
        $insert = $conn->prepare("INSERT INTO lazada_config (app_key, app_secret, country_code, environment) VALUES (?, ?, ?, ?)");
        $insert->execute([$appKey, $appSecret, $country, $env]);
    }

    echo json_encode(['success' => true, 'message' => 'Credentials saved successfully.']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}

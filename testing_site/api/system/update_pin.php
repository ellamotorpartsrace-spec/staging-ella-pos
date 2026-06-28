<?php
// api/system/update_pin.php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';

requireLogin();
requirePermission('manage_settings');

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method Not Allowed']);
    exit;
}

$data = json_decode(file_get_contents("php://input"), true);
$old_pin = trim($data['old_pin'] ?? '');
$new_pin = trim($data['new_pin'] ?? '');

if (empty($new_pin)) {
    echo json_encode(['success' => false, 'error' => 'New PIN cannot be empty']);
    exit;
}

try {
    $db = new Database();
    $conn = $db->getConnection();

    // Check old PIN
    $stmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'master_pin'");
    $stmt->execute();
    $current_pin = $stmt->fetchColumn();

    if ($current_pin && $current_pin !== $old_pin) {
        echo json_encode(['success' => false, 'error' => 'Incorrect old PIN']);
        exit;
    }

    // Update or Insert new PIN
    if ($current_pin !== false) {
        $updateStmt = $conn->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = 'master_pin'");
        $updateStmt->execute([$new_pin]);
    } else {
        $insertStmt = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('master_pin', ?)");
        $insertStmt->execute([$new_pin]);
    }

    echo json_encode(['success' => true, 'message' => 'PIN updated successfully']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}

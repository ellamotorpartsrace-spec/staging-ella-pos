<?php
header("Content-Type: application/json");
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';

requireLogin();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$platform = $_SESSION['lazada_active_platform'] ?? 'lazada_main';

try {
    $db = new Database();
    $conn = $db->getConnection();

    $input = json_decode(file_get_contents('php://input'), true);
    if (!isset($input['id'])) {
        echo json_encode(['success' => false, 'error' => 'Missing ID']);
        exit;
    }

    $stmt = $conn->prepare("
        UPDATE lazada_product_mappings 
        SET pos_product_id = NULL, 
            pos_unit_id = NULL, 
            pos_bundle_set_id = NULL,
            mapping_status = 'unmapped',
            matched_pos_sku = NULL
        WHERE id = ? AND platform_name = ?
    ");

    $stmt->execute([(int)$input['id'], $platform]);

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

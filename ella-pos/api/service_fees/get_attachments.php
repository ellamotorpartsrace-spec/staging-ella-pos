<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if (!hasPermission('manage_service_fees')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Forbidden']);
    exit;
}

try {
    $feeId = isset($_GET['fee_id']) ? intval($_GET['fee_id']) : 0;
    if ($feeId <= 0) {
        throw new Exception('Invalid fee_id');
    }

    $db = new Database();
    $conn = $db->getConnection();

    $stmt = $conn->prepare("
        SELECT attachment_id, fee_id, history_id, image_path, original_filename, uploaded_at
        FROM service_fee_attachments
        WHERE fee_id = ?
        ORDER BY attachment_id DESC
    ");
    $stmt->execute([$feeId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'attachments' => $rows]);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}


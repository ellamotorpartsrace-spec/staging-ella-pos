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
    $payload = json_decode(file_get_contents('php://input'), true);
    $attachmentId = isset($payload['attachment_id']) ? intval($payload['attachment_id']) : 0;

    if ($attachmentId <= 0) {
        throw new Exception('Invalid attachment_id');
    }

    $db = new Database();
    $conn = $db->getConnection();

    $stmt = $conn->prepare("SELECT image_path FROM service_fee_attachments WHERE attachment_id = ?");
    $stmt->execute([$attachmentId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        throw new Exception('Attachment not found');
    }

    $conn->beginTransaction();
    $del = $conn->prepare("DELETE FROM service_fee_attachments WHERE attachment_id = ?");
    $del->execute([$attachmentId]);
    $conn->commit();

    if (!empty($row['image_path'])) {
        $path = '../../' . ltrim($row['image_path'], '/');
        if (file_exists($path)) {
            @unlink($path);
        }
    }

    echo json_encode(['success' => true, 'message' => 'Picture removed']);
} catch (Exception $e) {
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}


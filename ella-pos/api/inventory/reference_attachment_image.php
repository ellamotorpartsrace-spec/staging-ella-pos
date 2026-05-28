<?php
declare(strict_types=1);

require_once '../../config/config.php';
require_once '../../includes/auth.php';
require_once '../../config/database.php';

requireLogin();

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(404);
    exit;
}

try {
    $db = new Database();
    $conn = $db->getConnection();

    $stmt = $conn->prepare("SELECT image_data, mime_type FROM reference_attachments WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $attachment = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$attachment || empty($attachment['image_data'])) {
        http_response_code(404);
        exit;
    }

    header('Content-Type: ' . ($attachment['mime_type'] ?: 'application/octet-stream'));
    header('Cache-Control: private, max-age=86400');
    echo $attachment['image_data'];
} catch (Exception $e) {
    http_response_code(500);
}

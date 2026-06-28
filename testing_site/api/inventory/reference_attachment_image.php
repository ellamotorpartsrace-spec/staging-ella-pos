<?php
declare(strict_types=1);

require_once '../../config/config.php';
require_once '../../includes/auth.php';
require_once '../../config/database.php';
require_once '../../includes/reference_attachment_storage.php';

requireLogin();

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(404);
    exit;
}

try {
    $db = new Database();
    $conn = $db->getConnection();
    ensureReferenceAttachmentBackupColumns($conn);

    $stmt = $conn->prepare("SELECT id, image_path, image_data, mime_type FROM reference_attachments WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $attachment = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$attachment) {
        http_response_code(404);
        exit;
    }

    $path = trim((string) ($attachment['image_path'] ?? ''));
    if ($path !== '') {
        $fullPath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, ltrim($path, '/\\'));
        if (is_file($fullPath)) {
            header('Location: ' . BASE_URL . $path);
            exit;
        }
    }

    if (empty($attachment['image_data'])) {
        http_response_code(404);
        exit;
    }

    header('Content-Type: ' . ($attachment['mime_type'] ?: 'application/octet-stream'));
    header('Cache-Control: private, max-age=86400');
    echo $attachment['image_data'];
} catch (Exception $e) {
    http_response_code(500);
}

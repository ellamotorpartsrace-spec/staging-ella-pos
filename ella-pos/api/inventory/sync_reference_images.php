<?php
// api/inventory/sync_reference_images.php
declare(strict_types=1);

require_once '../../config/config.php';
require_once '../../includes/auth.php';
require_once '../../config/database.php';
require_once '../../includes/reference_attachment_storage.php';

header('Content-Type: application/json');

requireLogin();

if (!in_array($_SESSION['role'] ?? '', ['admin', 'super_admin'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized. Admin access required.']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? 'scan';
if (!in_array($action, ['scan', 'sync'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid sync action.']);
    exit;
}

function referenceSyncProjectRoot(): string
{
    return dirname(__DIR__, 2);
}

function referenceSyncFilePath(?string $imagePath): ?string
{
    $cleanPath = trim((string) $imagePath);
    if ($cleanPath === '') {
        return null;
    }

    $cleanPath = ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $cleanPath), DIRECTORY_SEPARATOR);
    return referenceSyncProjectRoot() . DIRECTORY_SEPARATOR . $cleanPath;
}

function referenceSyncMimeType(string $filePath): string
{
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $mimeType = finfo_file($finfo, $filePath);
            finfo_close($finfo);
            if (is_string($mimeType) && $mimeType !== '') {
                return $mimeType;
            }
        }
    }

    $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    $mimeMap = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'bmp' => 'image/bmp',
    ];

    return $mimeMap[$extension] ?? 'application/octet-stream';
}

function referenceSyncStatus(array $row): array
{
    $hasPath = trim((string) ($row['image_path'] ?? '')) !== '';
    $hasBackup = !empty($row['has_backup']);
    $filePath = referenceSyncFilePath($row['image_path'] ?? null);
    $fileExists = $filePath !== null && is_file($filePath);
    $fileReadable = $fileExists && is_readable($filePath);

    if (!$hasPath) {
        return ['status' => 'missing_path', 'message' => 'No local image path saved.'];
    }

    if ($hasBackup) {
        return ['status' => 'already_backed_up', 'message' => 'Database backup already exists.'];
    }

    if (!$fileExists) {
        return ['status' => 'missing_file', 'message' => 'Local file was not found.'];
    }

    if (!$fileReadable) {
        return ['status' => 'unreadable_file', 'message' => 'Local file exists but cannot be read.'];
    }

    return ['status' => 'ready', 'message' => 'Ready to copy into database backup.'];
}

try {
    $db = new Database();
    $conn = $db->getConnection();
    ensureReferenceAttachmentBackupColumns($conn);

    $stmt = $conn->query("
        SELECT
            id,
            reference_number,
            image_path,
            original_filename,
            CASE WHEN image_data IS NULL OR OCTET_LENGTH(image_data) = 0 THEN 0 ELSE 1 END AS has_backup
        FROM reference_attachments
        ORDER BY id DESC
    ");
    $attachments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $summary = [
        'total' => 0,
        'missing_backup' => 0,
        'missing_file' => 0,
        'already_backed_up' => 0,
        'backed_up' => 0,
        'failed' => 0,
        'skipped' => 0,
    ];
    $results = [];

    foreach ($attachments as $row) {
        $summary['total']++;

        $status = referenceSyncStatus($row);
        $result = [
            'id' => (int) $row['id'],
            'reference_number' => $row['reference_number'],
            'image_path' => $row['image_path'],
            'status' => $status['status'],
            'message' => $status['message'],
        ];

        if ($status['status'] === 'ready' && $action === 'scan') {
            $summary['missing_backup']++;
        } elseif ($status['status'] === 'missing_file' || $status['status'] === 'unreadable_file') {
            $summary['missing_file']++;
        } elseif ($status['status'] === 'already_backed_up') {
            $summary['already_backed_up']++;
        } else {
            $summary['skipped']++;
        }

        if ($action === 'sync' && $status['status'] === 'ready') {
            $filePath = referenceSyncFilePath($row['image_path']);
            $imageData = $filePath ? file_get_contents($filePath) : false;

            if ($imageData === false || $imageData === '') {
                $summary['failed']++;
                $summary['missing_backup']++;
                $result['status'] = 'failed';
                $result['message'] = 'Failed to read local file.';
            } else {
                $mimeType = referenceSyncMimeType($filePath);
                $originalFilename = $row['original_filename'] ?: basename((string) $row['image_path']);

                $update = $conn->prepare("
                    UPDATE reference_attachments
                    SET image_data = ?,
                        mime_type = ?,
                        original_filename = COALESCE(NULLIF(original_filename, ''), ?),
                        local_saved = 1
                    WHERE id = ?
                      AND (image_data IS NULL OR OCTET_LENGTH(image_data) = 0)
                ");
                $update->bindValue(1, $imageData, PDO::PARAM_LOB);
                $update->bindValue(2, $mimeType);
                $update->bindValue(3, $originalFilename);
                $update->bindValue(4, (int) $row['id'], PDO::PARAM_INT);

                if ($update->execute() && $update->rowCount() > 0) {
                    $summary['backed_up']++;
                    $summary['already_backed_up']++;
                    $result['status'] = 'backed_up';
                    $result['message'] = 'Copied local image into database backup.';
                } else {
                    $summary['failed']++;
                    $summary['missing_backup']++;
                    $result['status'] = 'failed';
                    $result['message'] = 'Database row was not updated.';
                }
            }
        }

        $results[] = $result;
    }

    echo json_encode([
        'success' => true,
        'action' => $action,
        'summary' => $summary,
        'results' => $results,
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
}

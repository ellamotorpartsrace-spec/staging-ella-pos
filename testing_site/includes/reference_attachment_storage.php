<?php
declare(strict_types=1);

function ensureReferenceAttachmentBackupColumns(PDO $conn): void
{
    static $checked = false;
    if ($checked) {
        return;
    }

    $columns = [];
    $stmt = $conn->query("SHOW COLUMNS FROM reference_attachments");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $column) {
        $columns[$column['Field']] = true;
    }

    $alters = [];
    if (!isset($columns['image_data'])) {
        $alters[] = "ADD COLUMN image_data LONGBLOB NULL AFTER image_path";
    }
    if (!isset($columns['mime_type'])) {
        $alters[] = "ADD COLUMN mime_type VARCHAR(100) NULL AFTER image_data";
    }
    if (!isset($columns['original_filename'])) {
        $alters[] = "ADD COLUMN original_filename VARCHAR(255) NULL AFTER mime_type";
    }
    if (!isset($columns['local_saved'])) {
        $alters[] = "ADD COLUMN local_saved TINYINT(1) NOT NULL DEFAULT 1 AFTER original_filename";
    }

    if ($alters) {
        $conn->exec("ALTER TABLE reference_attachments " . implode(", ", $alters));
    }

    $checked = true;
}

function saveReferenceAttachment(PDO $conn, string $referenceNumber, string $tmpPath, string $originalName, ?string $mimeType, string $prefix): bool
{
    ensureReferenceAttachmentBackupColumns($conn);

    if (!is_uploaded_file($tmpPath) && !is_file($tmpPath)) {
        return false;
    }

    $imageData = file_get_contents($tmpPath);
    if ($imageData === false || $imageData === '') {
        return false;
    }

    $uploadDir = '../../assets/uploads/references/';
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0777, true);
    }

    $safeOriginal = preg_replace('/[^a-zA-Z0-9._-]/', '', $originalName) ?: 'reference-image';
    $newFileName = $prefix . '_' . time() . '_' . bin2hex(random_bytes(4)) . '_' . $safeOriginal;
    $destPath = $uploadDir . $newFileName;
    $dbPath = null;
    $localSaved = 0;

    if (is_dir($uploadDir) && is_writable($uploadDir) && @move_uploaded_file($tmpPath, $destPath)) {
        $dbPath = 'assets/uploads/references/' . $newFileName;
        $localSaved = 1;
    }

    $stmt = $conn->prepare("
        INSERT INTO reference_attachments
            (reference_number, image_path, image_data, mime_type, original_filename, local_saved)
        VALUES (?, ?, ?, ?, ?, ?)
    ");

    $stmt->bindValue(1, $referenceNumber);
    $stmt->bindValue(2, $dbPath);
    $stmt->bindValue(3, $imageData, PDO::PARAM_LOB);
    $stmt->bindValue(4, $mimeType ?: 'application/octet-stream');
    $stmt->bindValue(5, $originalName);
    $stmt->bindValue(6, $localSaved, PDO::PARAM_INT);
    return $stmt->execute();
}

function referenceAttachmentPublicPath(array $attachment): string
{
    $path = trim((string) ($attachment['image_path'] ?? ''));
    if ($path !== '') {
        $fullPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, ltrim($path, '/\\'));
        if (is_file($fullPath)) {
            return BASE_URL . $path;
        }
    }

    return BASE_URL . 'api/inventory/reference_attachment_image.php?id=' . urlencode((string) $attachment['id']);
}

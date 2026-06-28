<?php
// api/expenses/update_receipt.php

header("Content-Type: application/json");
require_once '../../config/config.php';
require_once '../../includes/auth.php';
require_once '../../config/database.php';
require_once '../../includes/logger.php';

requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
if ($id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid expense ID']);
    exit;
}

if (!isset($_FILES['receipt_image']) || $_FILES['receipt_image']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'message' => 'No valid file uploaded']);
    exit;
}

$uploadDir = '../../assets/uploads/receipts/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

$fileInfo = pathinfo($_FILES['receipt_image']['name']);
$ext = strtolower($fileInfo['extension']);
$allowed = ['jpg', 'jpeg', 'png', 'pdf'];

if (!in_array($ext, $allowed)) {
    echo json_encode(['success' => false, 'message' => 'Invalid file type. Only JPG, PNG, and PDF are allowed.']);
    exit;
}

$newFile = uniqid('rec_') . '.' . $ext;
$targetPath = $uploadDir . $newFile;

if (move_uploaded_file($_FILES['receipt_image']['tmp_name'], $targetPath)) {
    try {
        $db = new Database();
        $conn = $db->getConnection();

        $stmt = $conn->prepare("SELECT receipt_image FROM expenses WHERE id = ?");
        $stmt->execute([$id]);
        $oldFile = $stmt->fetchColumn();

        $updateStmt = $conn->prepare("UPDATE expenses SET receipt_image = ? WHERE id = ?");
        $result = $updateStmt->execute([$newFile, $id]);

        if ($result) {
            if ($oldFile && file_exists($uploadDir . $oldFile)) {
                unlink($uploadDir . $oldFile);
            }
            logActivity($conn, $_SESSION['user_id'], 'EXPENSE_UPDATED', 'EXPENSE', "Updated receipt image for expense #$id", $id);
            echo json_encode(['success' => true, 'message' => 'Picture updated successfully', 'receipt_image' => $newFile]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to update database']);
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to move uploaded file.']);
}

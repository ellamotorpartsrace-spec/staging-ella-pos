<?php
// api/expenses/remove_receipt.php

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

$data = json_decode(file_get_contents('php://input'), true);
$id = isset($data['id']) ? (int)$data['id'] : 0;

if ($id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid expense ID']);
    exit;
}

try {
    $db = new Database();
    $conn = $db->getConnection();

    $stmt = $conn->prepare("SELECT receipt_image FROM expenses WHERE id = ?");
    $stmt->execute([$id]);
    $oldFile = $stmt->fetchColumn();

    $updateStmt = $conn->prepare("UPDATE expenses SET receipt_image = NULL WHERE id = ?");
    $result = $updateStmt->execute([$id]);

    if ($result) {
        if ($oldFile) {
            $uploadDir = '../../assets/uploads/receipts/';
            if (file_exists($uploadDir . $oldFile)) {
                unlink($uploadDir . $oldFile);
            }
        }
        logActivity($conn, $_SESSION['user_id'], 'EXPENSE_UPDATED', 'EXPENSE', "Removed receipt image from expense #$id", $id);
        echo json_encode(['success' => true, 'message' => 'Picture removed successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to update database']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}

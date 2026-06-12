<?php
// api/inventory/reject_restock.php
header("Content-Type: application/json");
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/logger.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

requireLogin();

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Only admins can reject restocks.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$request_id = $input['request_id'] ?? null;
$batch_id = $input['batch_id'] ?? null;

if (!$request_id && !$batch_id) {
    echo json_encode(['success' => false, 'error' => 'Missing ID']);
    exit;
}

$password = $input['password'] ?? '';

if (empty($password)) {
    echo json_encode(['success' => false, 'error' => 'Password is required.']);
    exit;
}

try {
    $db = new Database();
    $conn = $db->getConnection();

    // Verify Password
    $stmtAuth = $conn->prepare("SELECT password FROM users WHERE id = ?");
    $stmtAuth->execute([$_SESSION['user_id']]);
    $user = $stmtAuth->fetch(PDO::FETCH_ASSOC);

    if (!$user || !password_verify($password, $user['password'])) {
        echo json_encode(['success' => false, 'error' => 'Incorrect password.']);
        exit;
    }
    
    $user_id = $_SESSION['user_id'] ?? 1;

    if ($batch_id) {
        $stmt = $conn->prepare("UPDATE restock_requests SET status = 'rejected', approved_by = ?, updated_at = NOW() WHERE batch_id = ? AND status = 'pending'");
        $stmt->execute([$user_id, $batch_id]);
    } else {
        $stmt = $conn->prepare("UPDATE restock_requests SET status = 'rejected', approved_by = ?, updated_at = NOW() WHERE request_id = ? AND status = 'pending'");
        $stmt->execute([$user_id, $request_id]);
    }

    $affected = $stmt->rowCount();

    if ($affected > 0) {
        logActivity($conn, $user_id, 'RESTOCK_REJECT', 'Inventory', "Rejected $affected pending restock items.");
    }

    echo json_encode(['success' => true, 'message' => "Successfully rejected items"]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

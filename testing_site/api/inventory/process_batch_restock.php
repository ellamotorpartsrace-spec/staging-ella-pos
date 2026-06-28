<?php
// api/inventory/process_batch_restock.php - Process batch restock
header("Content-Type: application/json");
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/reference_attachment_storage.php';

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

requireLogin();

$input = $_POST;

$supplier_id = $input['supplier_id'] ?? null;
$supplier_name = $input['supplier_name'] ?? '';
$reference = $input['reference'] ?? '';
// Since we used FormData and JSON.stringify for items
$items = isset($input['items']) ? json_decode($input['items'], true) : [];

if (empty($items)) {
    echo json_encode(['success' => false, 'error' => 'No items provided']);
    exit;
}

try {
    $db = new Database();
    $conn = $db->getConnection();
    ensureReferenceAttachmentBackupColumns($conn);

    // ALL restocks go to pending queue
    $payment_status = trim($input['payment_status'] ?? 'paid');
    $credit_terms = isset($input['credit_terms']) ? $input['credit_terms'] : null;
    $batch_id = 'BATCH-' . date('YmdHis') . '-' . mt_rand(1000, 9999);
    $finalReference = !empty($reference) ? $reference : $batch_id;

    $stmtReq = $conn->prepare("
        INSERT INTO restock_requests 
        (batch_id, variation_id, quantity, cost, supplier_id, supplier_name, reference, payment_status, credit_terms, status, requested_by) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?)
    ");

    $processed = 0;
    foreach ($items as $item) {
        if ((float) $item['quantity'] <= 0) continue;
        $stmtReq->execute([
            $batch_id, $item['variation_id'], $item['quantity'], $item['cost'], $supplier_id, $supplier_name, $finalReference, $payment_status, $credit_terms, $_SESSION['user_id']
        ]);
        $processed++;
    }

    // Handle File Uploads
    if (!empty($_FILES['reference_images']['name'][0])) {
        $files = $_FILES['reference_images'];
        $fileCount = count($files['name']);
        for ($i = 0; $i < $fileCount; $i++) {
            if ($files['error'][$i] === UPLOAD_ERR_OK) {
                $fileTmpPath = $files['tmp_name'][$i];
                $fileName = $files['name'][$i];
                $mimeType = $files['type'][$i] ?? null;
                saveReferenceAttachment($conn, $finalReference, $fileTmpPath, $fileName, $mimeType, 'batch');
            }
        }
    }

    echo json_encode([
        'success' => true,
        'message' => "Successfully requested $processed items for approval",
        'processed' => $processed,
        'pending' => true
    ]);
    exit;

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

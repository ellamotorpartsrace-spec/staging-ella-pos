<?php
// api/inventory/process_restock.php
declare(strict_types=1);

require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/logger.php';
require_once '../../includes/reference_attachment_storage.php';

// 1. Security
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../../views/inventory/restock.php");
    exit;
}
requireLogin();

// 2. Get Data
$var_id = $_POST['variation_id'];
$qty_added = (int) $_POST['quantity_added'];
$current_qty = (int) $_POST['current_stock'];
$new_capital = $_POST['new_capital'];
$supplier = trim($_POST['supplier']);
$ref = trim($_POST['reference']);

// Validate
if ($qty_added <= 0) {
    header("Location: " . BASE_URL . "views/inventory/restock.php?id=$var_id&error=Invalid Quantity");
    exit;
}

try {
    $db = new Database();
    $conn = $db->getConnection();
    ensureReferenceAttachmentBackupColumns($conn);

    // ALL restocks go to pending queue now
    $payment_status = trim($_POST['payment_status'] ?? 'paid');
    $due_date = $_POST['due_date'] ?? null;
    
    $stmtSupp = $conn->prepare("SELECT supplier_id FROM suppliers WHERE supplier_name = ? LIMIT 1");
    $stmtSupp->execute([$supplier]);
    $suppData = $stmtSupp->fetch(PDO::FETCH_ASSOC);
    $supplier_id = $suppData ? $suppData['supplier_id'] : null;

    $stmtReq = $conn->prepare("
        INSERT INTO restock_requests 
        (variation_id, quantity, cost, supplier_id, supplier_name, reference, payment_status, due_date, status, requested_by) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?)
    ");
    $stmtReq->execute([
        $var_id, $qty_added, $new_capital, $supplier_id, $supplier, $ref, $payment_status, $due_date, $_SESSION['user_id']
    ]);
    $request_id = $conn->lastInsertId();

    // If no reference was provided, update the inserted row with the auto-generated SINGLE- reference
    if (empty($ref)) {
        $finalRef = 'SINGLE-' . date('YmdHis') . '-' . $request_id;
        $conn->prepare("UPDATE restock_requests SET reference = ? WHERE request_id = ?")->execute([$finalRef, $request_id]);
    } else {
        $finalRef = $ref;
    }

    // Handle Reference Image Upload (Multiple) for the pending request
    if (!empty($_FILES['reference_images']['name'][0])) {
        $files = $_FILES['reference_images'];
        $fileCount = count($files['name']);
        $finalRef = $ref ?: 'SINGLE-' . date('YmdHis') . '-' . $request_id;

        for ($i = 0; $i < $fileCount; $i++) {
            if ($files['error'][$i] === UPLOAD_ERR_OK) {
                $fileTmpPath = $files['tmp_name'][$i];
                $fileName = $files['name'][$i];
                $mimeType = $files['type'][$i] ?? null;
                saveReferenceAttachment($conn, $finalRef, $fileTmpPath, $fileName, $mimeType, 'ref');
            }
        }
    }

    logActivity($conn, $_SESSION['user_id'], 'RESTOCK_REQUEST', 'Inventory', "Requested +$qty_added units for variation ID: $var_id", $var_id);

    header("Location: " . BASE_URL . "views/inventory/restock.php?success=pending");
    exit;

} catch (Exception $e) {
    header("Location: " . BASE_URL . "views/inventory/restock.php?id=$var_id&error=" . urlencode($e->getMessage()));
}

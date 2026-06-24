<?php
// api/inventory/get_restock_details.php
header("Content-Type: application/json");
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/reference_attachment_storage.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

requireLogin();

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'super_admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access denied.']);
    exit;
}

$batch_id = $_GET['batch_id'] ?? null;
$request_id = $_GET['request_id'] ?? null;

if (!$batch_id && !$request_id) {
    echo json_encode(['success' => false, 'error' => 'Missing ID']);
    exit;
}

try {
    $db = new Database();
    $conn = $db->getConnection();

    $sql = "
        SELECT 
            rr.*, 
            p.product_name, 
            p.brand_name,
            pv.variation_name,
            pv.sku
        FROM restock_requests rr
        JOIN product_variations pv ON rr.variation_id = pv.variation_id
        JOIN products p ON pv.product_id = p.product_id
        WHERE rr.status = 'pending'
    ";

    $params = [];
    if ($batch_id) {
        $sql .= " AND rr.batch_id = ?";
        $params[] = $batch_id;
    } else {
        $sql .= " AND rr.request_id = ?";
        $params[] = $request_id;
    }

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($items)) {
        echo json_encode(['success' => false, 'error' => 'No pending items found']);
        exit;
    }

    // Get the reference string (should be identical for all items in the batch/request)
    $reference = $items[0]['reference'];

    // Fetch attachments for this reference
    $attachments = [];
    if ($reference) {
        $stmtAtt = $conn->prepare("SELECT id, image_path, original_filename FROM reference_attachments WHERE reference_number = ?");
        $stmtAtt->execute([$reference]);
        $attachments = $stmtAtt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($attachments as &$att) {
            $att['public_url'] = referenceAttachmentPublicPath($att);
        }
    }

    $grand_total = 0;
    foreach ($items as $item) {
        $grand_total += (float)$item['quantity'] * (float)$item['cost'];
    }

    echo json_encode([
        'success' => true,
        'items' => $items,
        'attachments' => $attachments,
        'grand_total' => $grand_total
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

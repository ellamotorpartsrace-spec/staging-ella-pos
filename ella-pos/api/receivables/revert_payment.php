<?php
// api/receivables/revert_payment.php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method Not Allowed']);
    exit;
}

$data = json_decode(file_get_contents("php://input"), true);
if (!isset($data['sale_id'])) {
    echo json_encode(['success' => false, 'error' => 'Sale ID is required']);
    exit;
}

$sale_id = intval($data['sale_id']);

try {
    $db = new Database();
    $conn = $db->getConnection();

    $conn->beginTransaction();

    // Find the latest payment history for this sale's pay_later/credit terms
    $stmt = $conn->prepare("
        SELECT h.history_id, h.payment_id, h.amount 
        FROM payment_history h
        JOIN pos_sale_payments p ON h.payment_id = p.payment_id
        WHERE p.sale_id = ? AND p.payment_type IN ('pay_later', 'credit')
        ORDER BY h.paid_at DESC LIMIT 1
    ");
    $stmt->execute([$sale_id]);
    $latest_payment = $stmt->fetch();

    if (!$latest_payment) {
        throw new Exception("No payment history found to revert for this transaction.");
    }

    $history_id = $latest_payment['history_id'];
    $payment_id = $latest_payment['payment_id'];
    $amount_to_revert = floatval($latest_payment['amount']);

    // 1. Delete the payment history record
    $delStmt = $conn->prepare("DELETE FROM payment_history WHERE history_id = ?");
    $delStmt->execute([$history_id]);

    // 2. Update pos_sale_payments
    $updatePayment = $conn->prepare("
        UPDATE pos_sale_payments 
        SET paid_amount = GREATEST(0, paid_amount - ?),
            payment_status = CASE 
                WHEN GREATEST(0, paid_amount - ?) >= amount - 0.01 THEN 'paid'
                WHEN GREATEST(0, paid_amount - ?) > 0 THEN 'partial'
                ELSE 'unpaid'
            END
        WHERE payment_id = ?
    ");
    $updatePayment->execute([$amount_to_revert, $amount_to_revert, $amount_to_revert, $payment_id]);

    // 3. Update overall sale status
    // We determine if there are any remaining payments made on this sale
    $checkSale = $conn->prepare("
        SELECT SUM(paid_amount) as total_paid, SUM(amount) as grand_total 
        FROM pos_sale_payments 
        WHERE sale_id = ?
    ");
    $checkSale->execute([$sale_id]);
    $saleStats = $checkSale->fetch();

    $new_sale_payment_status = 'unpaid';
    $new_sale_status = 'pending';

    if ($saleStats) {
        if ($saleStats['total_paid'] >= $saleStats['grand_total'] - 0.01) {
            $new_sale_payment_status = 'paid';
            $new_sale_status = 'completed';
        } elseif ($saleStats['total_paid'] > 0) {
            $new_sale_payment_status = 'partial';
            $new_sale_status = 'pending';
        }
    }

    $updateSale = $conn->prepare("
        UPDATE pos_sales 
        SET payment_status = ?, status = ?
        WHERE sale_id = ?
    ");
    $updateSale->execute([$new_sale_payment_status, $new_sale_status, $sale_id]);

    $conn->commit();
    echo json_encode(['success' => true, 'message' => 'Latest payment reverted successfully']);
} catch (Exception $e) {
    if (isset($conn)) $conn->rollBack();
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

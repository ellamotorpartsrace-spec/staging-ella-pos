<?php
/**
 * api/shopee/get_order_fees.php
 */
header("Content-Type: application/json");
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
requireLogin();

try {
    if (empty($_GET['order_sn'])) {
        throw new Exception("Missing order_sn parameter");
    }
    
    $order_sn = $_GET['order_sn'];
    
    $db = new Database();
    $conn = $db->getConnection();
    
    $sql = "
        SELECT 
            f.*,
            o.total_amount as original_total
        FROM shopee_financial_transactions f
        JOIN shopee_orders o ON f.order_sn = o.order_sn
        WHERE f.order_sn = ?
    ";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute([$order_sn]);
    $fees = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$fees) {
        echo json_encode(['success' => false, 'error' => 'No financial data found for this order. It may not be released yet.']);
        exit;
    }
    
    echo json_encode([
        'success' => true,
        'fees' => $fees
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

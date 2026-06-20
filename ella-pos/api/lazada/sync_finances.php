<?php
/**
 * api/lazada/sync_finances.php — Fetch financial (escrow) details for completed orders
 */
header("Content-Type: application/json");

register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'PHP Fatal Error: ' . $error['message'] . ' in ' . basename($error['file']) . ':' . $error['line']]);
    }
});

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/LazadaAPI.php';

try {
    $db = new Database();
    $conn = $db->getConnection();

    // Load active Lazada config
    $stmt = $conn->prepare("SELECT * FROM lazada_config WHERE is_active = 1 LIMIT 1");
    $stmt->execute();
    $config = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$config || empty($config['access_token'])) {
        echo json_encode(['success' => false, 'error' => 'Lazada not authorized.']);
        exit;
    }

    $isTest = $config['environment'] === 'test';
    $lazada = new LazadaAPI($config['partner_id'], $config['partner_key'], $isTest);
    $accessToken = $config['access_token'];
    $shopId      = $config['shop_id'];

    if (strtotime($config['token_expires_at']) - time() < 600) {
        $refreshResult = $lazada->refreshToken($config['refresh_token'], $shopId);
        if (isset($refreshResult['access_token'])) {
            $accessToken = $refreshResult['access_token'];
            $expiresAt = date('Y-m-d H:i:s', time() + ($refreshResult['expire_in'] ?? 14400));
            $conn->prepare("UPDATE lazada_config SET access_token=?, refresh_token=?, token_expires_at=?, updated_at=NOW() WHERE is_active=1")
                ->execute([$accessToken, $refreshResult['refresh_token'], $expiresAt]);
        }
    }

    set_time_limit(0);

    // Fetch orders that are COMPLETED but don't have financial records, or are recently completed
    // We also check for 'RELEASED' escrow status if possible
    $stmtOrders = $conn->query("
        SELECT o.order_sn 
        FROM lazada_orders o
        LEFT JOIN lazada_financial_transactions f ON o.order_sn = f.order_sn
        WHERE o.order_status = 'COMPLETED' 
          AND (f.id IS NULL OR f.escrow_release_time IS NULL)
        ORDER BY o.create_time DESC
        LIMIT 50
    ");

    $orderSns = $stmtOrders->fetchAll(PDO::FETCH_COLUMN);

    if (empty($orderSns)) {
        echo json_encode(['success' => true, 'message' => 'No orders pending financial sync.', 'synced_count' => 0]);
        exit;
    }

    $insertedFinances = 0;
    
    $conn->beginTransaction();

    $stmtFin = $conn->prepare("
        INSERT INTO lazada_financial_transactions (
            order_sn, payout_amount, escrow_tax, buyer_total_amount, 
            shipping_fee_paid_by_buyer, shipping_fee_paid_by_seller, 
            commission_fee, transaction_fee, service_fee, marketing_fee, 
            seller_voucher, lazada_voucher, escrow_release_time
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            payout_amount = VALUES(payout_amount),
            escrow_tax = VALUES(escrow_tax),
            shipping_fee_paid_by_seller = VALUES(shipping_fee_paid_by_seller),
            commission_fee = VALUES(commission_fee),
            transaction_fee = VALUES(transaction_fee),
            service_fee = VALUES(service_fee),
            escrow_release_time = VALUES(escrow_release_time)
    ");

    $stmtUpdateOrder = $conn->prepare("
        UPDATE lazada_orders 
        SET financial_status = 'RELEASED', escrow_amount = ? 
        WHERE order_sn = ?
    ");

    foreach ($orderSns as $order_sn) {
        $escrowResult = $lazada->get('/api/v2/payment/get_escrow_detail', [
            'order_sn' => $order_sn
        ], $accessToken, $shopId);

        if (isset($escrowResult['response']['order_income'])) {
            $income = $escrowResult['response']['order_income'];
            
            $payout_amount = $income['escrow_amount'] ?? 0.0;
            $escrow_tax = $income['escrow_tax'] ?? 0.0;
            $buyer_total_amount = $income['buyer_total_amount'] ?? 0.0;
            
            $shipping_fee_paid_by_buyer = $income['buyer_transaction_fee'] ?? 0.0; // Approximation if missing
            $shipping_fee_paid_by_seller = $income['actual_shipping_fee'] ?? 0.0;
            
            $commission_fee = $income['commission_fee'] ?? 0.0;
            $transaction_fee = $income['seller_transaction_fee'] ?? 0.0;
            $service_fee = $income['service_fee'] ?? 0.0;
            $marketing_fee = $income['drip_campaign_fee'] ?? 0.0;
            
            $seller_voucher = $income['seller_voucher_code'] ?? 0.0;
            $lazada_voucher = $income['lazada_voucher_code'] ?? 0.0;

            // Optional: Handle array format if returned by API
            if (is_array($income['income_details'] ?? null)) {
                $details = $income['income_details'];
                $commission_fee = $details['commission_fee'] ?? $commission_fee;
                $transaction_fee = $details['seller_transaction_fee'] ?? $transaction_fee;
                $service_fee = $details['service_fee'] ?? $service_fee;
                $shipping_fee_paid_by_seller = $details['actual_shipping_fee'] ?? $shipping_fee_paid_by_seller;
            }

            $releaseTime = null;
            if (isset($escrowResult['response']['escrow_release_time']) && $escrowResult['response']['escrow_release_time'] > 0) {
                $releaseTime = date('Y-m-d H:i:s', $escrowResult['response']['escrow_release_time']);
            }

            $stmtFin->execute([
                $order_sn,
                $payout_amount,
                $escrow_tax,
                $buyer_total_amount,
                $shipping_fee_paid_by_buyer,
                $shipping_fee_paid_by_seller,
                $commission_fee,
                $transaction_fee,
                $service_fee,
                $marketing_fee,
                $seller_voucher,
                $lazada_voucher,
                $releaseTime
            ]);

            // Update order status
            $stmtUpdateOrder->execute([$payout_amount, $order_sn]);

            $insertedFinances++;
        }
        
        // Rate Limiter: pause for 200ms
        usleep(200000);
    }

    $conn->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Finances synced successfully',
        'synced_count' => count($orderSns),
        'inserted_or_updated' => $insertedFinances
    ]);

} catch (Exception $e) {
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

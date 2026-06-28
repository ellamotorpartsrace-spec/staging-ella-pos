<?php
/**
 * scripts/shopee_fast_backfill.php
 * DISABLED — shopee_reserved_stock system has been retired.
 * Shopee manages stock natively (orders deduct, cancellations restore).
 */
echo "[Fast Backfill] Disabled — shopee_reserved_stock tracking is no longer needed.\n";
exit(0);

// ── ORIGINAL CODE BELOW (kept for reference) ──────────────────────────────────

$db = new Database();
$conn = $db->getConnection();

$stmt = $conn->query("SELECT * FROM shopee_config WHERE is_active = 1 LIMIT 1");
$config = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$config) {
    die("Shopee API not configured.\n");
}

$shopee = new ShopeeAPI($config['partner_id'], $config['partner_key'], $config['environment'] === 'test');
$accessToken = $config['access_token'];
$shopId = $config['shop_id'];

// Check token expiry
if (strtotime($config['token_expires_at']) <= time() + 300) {
    echo "Token expired, refreshing...\n";
    $result = $shopee->refreshToken($config['refresh_token'], $shopId);
    if (!isset($result['error']) && isset($result['refresh_token'])) {
        $accessToken = $result['access_token'];
        $newRefreshToken = $result['refresh_token'];
        $expireIn = $result['expire_in'] ?? 14400;
        $newExpiresAt = date('Y-m-d H:i:s', time() + $expireIn);
        $conn->prepare("UPDATE shopee_config SET access_token = ?, refresh_token = ?, token_expires_at = ?, updated_at = NOW() WHERE is_active = 1")->execute([$accessToken, $newRefreshToken, $newExpiresAt]);
    }
}

echo "[Fast Backfill] Starting 180-day targeted backfill for active orders...\n";

$days_to_fetch = 180;
$chunk_days = 15;
$now = time();

$statuses = ['SHIPPED', 'TO_RECEIVE', 'TO_CONFIRM_RECEIVE'];

foreach ($statuses as $status) {
    echo "[Fast Backfill] Fetching status: $status...\n";
    
    for ($i = $days_to_fetch; $i > 0; $i -= $chunk_days) {
        $timeFrom = $now - ($i * 24 * 3600);
        $timeTo = $now - (($i - $chunk_days) * 24 * 3600);
        if ($timeTo > $now) $timeTo = $now;

        $cursor = '';
        do {
            $params = [
                'time_range_field' => 'create_time', // Use create_time for 180-day search
                'time_from' => $timeFrom,
                'time_to' => $timeTo,
                'page_size' => 100,
                'order_status' => $status
            ];
            if ($cursor !== '') {
                $params['cursor'] = $cursor;
            }

            $res = $shopee->get('/api/v2/order/get_order_list', $params, $accessToken, $shopId);

            if (isset($res['error']) && !empty($res['error'])) {
                break;
            }

            $orders = $res['response']['order_list'] ?? [];
            if (!empty($orders)) {
                $orderSns = array_column($orders, 'order_sn');
                
                $chunks = array_chunk($orderSns, 50);
                foreach ($chunks as $chunk) {
                    $detailRes = $shopee->get('/api/v2/order/get_order_detail', [
                        'order_sn_list' => implode(',', $chunk),
                        'response_optional_fields' => 'item_list,buyer_username'
                    ], $accessToken, $shopId);

                    if (!isset($detailRes['error']) || empty($detailRes['error'])) {
                        $orderList = $detailRes['response']['order_list'] ?? [];
                        foreach ($orderList as $order) {
                            $orderSn = $order['order_sn'];
                            $orderStatus = $order['order_status'];
                            $buyerUsername = $order['buyer_username'] ?? '';
                            $itemList = $order['item_list'] ?? [];

                            foreach ($itemList as $item) {
                                $itemId = $item['item_id'];
                                $modelId = $item['model_id'] ?? null;
                                $sku = $item['model_sku'] ?: $item['item_sku'] ?: '';
                                $qty = (int)($item['model_quantity_purchased'] ?? $item['quantity_purchased'] ?? 1);

                                $checkStmt = $conn->prepare("
                                    SELECT id FROM shopee_reserved_stock 
                                    WHERE order_sn = ? AND shopee_item_id = ? AND (shopee_model_id = ? OR (shopee_model_id IS NULL AND ? IS NULL))
                                ");
                                $checkStmt->execute([$orderSn, $itemId, $modelId ?: null, $modelId ?: null]);
                                $existingId = $checkStmt->fetchColumn();

                                if ($existingId) {
                                    $stmt = $conn->prepare("
                                        UPDATE shopee_reserved_stock 
                                        SET quantity = ?, order_status = ?, buyer_username = ?, is_active = 1
                                        WHERE id = ?
                                    ");
                                    $stmt->execute([$qty, $orderStatus, $buyerUsername, $existingId]);
                                } else {
                                    $stmt = $conn->prepare("
                                        INSERT INTO shopee_reserved_stock 
                                        (order_sn, shopee_item_id, shopee_model_id, sku, quantity, order_status, buyer_username, is_active, reserved_at)
                                        VALUES (?, ?, ?, ?, ?, ?, ?, 1, NOW())
                                    ");
                                    $stmt->execute([$orderSn, $itemId, $modelId ?: null, $sku, $qty, $orderStatus, $buyerUsername]);
                                }
                            }
                        }
                    }
                    usleep(50000); // 50ms
                }
            }

            $cursor = $res['response']['next_cursor'] ?? '';
            if ($cursor !== '') {
                usleep(50000); // 50ms
            }

        } while ($cursor !== '');
    }
}
echo "[Fast Backfill] Completed!\n";

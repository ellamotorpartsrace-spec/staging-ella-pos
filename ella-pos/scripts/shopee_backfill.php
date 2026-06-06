<?php
/**
 * scripts/shopee_backfill.php
 * DISABLED — shopee_reserved_stock system has been retired.
 * Shopee manages stock natively (orders deduct, cancellations restore).
 */
echo "[Backfill] Disabled — shopee_reserved_stock tracking is no longer needed.\n";
exit(0);

// ── ORIGINAL CODE BELOW (kept for reference) ──────────────────────────────────

    $db = new Database();
    $conn = $db->getConnection();

    $stmt = $conn->prepare("SELECT * FROM shopee_config WHERE is_active = 1 LIMIT 1");
    $stmt->execute();
    $config = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$config) {
        die("[Backfill] No active Shopee configuration found.\n");
    }

    $isTest = $config['environment'] === 'test';
    $shopee = new ShopeeAPI($config['partner_id'], $config['partner_key'], $isTest);
    $accessToken = $config['access_token'];
    $shopId = $config['shop_id'];

    $expiresAt = strtotime($config['token_expires_at']);
    $timeRemaining = $expiresAt - time();

    if ($timeRemaining <= 15 * 60) {
        $result = $shopee->refreshToken($config['refresh_token'], $shopId);
        if (isset($result['access_token'])) {
            $accessToken = $result['access_token'];
            $newRefreshToken = $result['refresh_token'];
            $expireIn = $result['expire_in'] ?? 14400;
            $newExpiresAt = date('Y-m-d H:i:s', time() + $expireIn);
            $conn->prepare("UPDATE shopee_config SET access_token = ?, refresh_token = ?, token_expires_at = ?, updated_at = NOW() WHERE is_active = 1")->execute([$accessToken, $newRefreshToken, $newExpiresAt]);
        }
    }

    echo "[Backfill] Starting 90-day backfill for missing orders...\n";

    // 90 days back, chunked by 15 days (max allowed by Shopee API)
    $days_to_fetch = 90;
    $chunk_days = 15;
    $now = time();

    for ($i = $days_to_fetch; $i > 0; $i -= $chunk_days) {
        $timeFrom = $now - ($i * 24 * 3600);
        $timeTo = $now - (($i - $chunk_days) * 24 * 3600);
        if ($timeTo > $now) $timeTo = $now;

        echo "[Backfill] Fetching " . date('Y-m-d', $timeFrom) . " to " . date('Y-m-d', $timeTo) . "...\n";

        $cursor = '';
        do {
            $params = [
                'time_range_field' => 'update_time',
                'time_from' => $timeFrom,
                'time_to' => $timeTo,
                'page_size' => 100 // max page size
            ];
            if ($cursor !== '') {
                $params['cursor'] = $cursor;
            }

            $res = $shopee->get('/api/v2/order/get_order_list', $params, $accessToken, $shopId);

            if (isset($res['error']) && !empty($res['error'])) {
                echo "[Backfill API Error] " . json_encode($res) . "\n";
                break;
            }

            $orders = $res['response']['order_list'] ?? [];
            if (!empty($orders)) {
                $orderSns = array_column($orders, 'order_sn');
                
                // Fetch in chunks of 50
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

                            if (in_array($orderStatus, ['SHIPPED', 'TO_RECEIVE', 'TO_CONFIRM_RECEIVE'])) {
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
                            } elseif (in_array($orderStatus, ['READY_TO_SHIP', 'PROCESSED', 'COMPLETED', 'CANCELLED', 'IN_CANCEL'])) {
                                $conn->prepare("UPDATE shopee_reserved_stock SET is_active = 0, order_status = ?, released_at = NOW() WHERE order_sn = ?")->execute([$orderStatus, $orderSn]);
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

    echo "[Backfill] Completed!\n";

} catch (Exception $e) {
    echo "[Backfill Exception] " . $e->getMessage() . "\n";
}

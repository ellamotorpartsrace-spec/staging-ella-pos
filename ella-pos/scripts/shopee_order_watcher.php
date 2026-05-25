<?php
/**
 * scripts/shopee_order_watcher.php
 * Automated background script to watch Shopee orders and manage reserved stock.
 * Runs via cron job every 5–10 minutes.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

try {
    $db = new Database();
    $conn = $db->getConnection();

    // 1. Fetch active Shopee configuration
    $cfgStmt = $conn->prepare("SELECT * FROM shopee_config WHERE is_active = 1 LIMIT 1");
    $cfgStmt->execute();
    $config = $cfgStmt->fetch(PDO::FETCH_ASSOC);

    if (!$config || empty($config['access_token']) || empty($config['refresh_token'])) {
        echo "[Order Watcher] Active Shopee configuration or tokens not found. Exiting.\n";
        exit;
    }

    require_once __DIR__ . '/../classes/ShopeeApi.php';
    $isTest = $config['environment'] === 'test';
    $shopee = new ShopeeAPI($config['partner_id'], $config['partner_key'], $isTest);
    $accessToken = $config['access_token'];
    $shopId = $config['shop_id'];

    // 2. Auto-Refresh Access Token (if < 15 mins remaining)
    $expiresAt = strtotime($config['token_expires_at']);
    $timeRemaining = $expiresAt - time();

    if ($timeRemaining <= 15 * 60) {
        echo "[Order Watcher] Token expires in less than 15 minutes. Auto-refreshing...\n";
        $result = $shopee->refreshToken($config['refresh_token'], $shopId);

        if (isset($result['access_token'])) {
            $accessToken = $result['access_token'];
            $newRefreshToken = $result['refresh_token'];
            $expireIn = $result['expire_in'] ?? 14400;
            $newExpiresAt = date('Y-m-d H:i:s', time() + $expireIn);

            $updStmt = $conn->prepare("UPDATE shopee_config SET access_token = ?, refresh_token = ?, token_expires_at = ?, updated_at = NOW() WHERE is_active = 1");
            $updStmt->execute([$accessToken, $newRefreshToken, $newExpiresAt]);
            echo "[Order Watcher] Token successfully refreshed! New expiry: {$newExpiresAt}\n";
        } else {
            echo "[Order Watcher Warning] Failed to refresh token: " . json_encode($result) . "\n";
        }
    }

    // 3. Poll Shopee orders updated in the last 24 hours
    echo "[Order Watcher] Polling orders updated in the last 24 hours...\n";
    $timeFrom = time() - 24 * 3600;
    $timeTo = time();
    $cursor = '';
    $orderSns = [];

    do {
        $params = [
            'time_range_field' => 'update_time',
            'time_from' => $timeFrom,
            'time_to' => $timeTo,
            'page_size' => 50
        ];
        if ($cursor !== '') {
            $params['cursor'] = $cursor;
        }

        $res = $shopee->get('/api/v2/order/get_order_list', $params, $accessToken, $shopId);
        
        if (isset($res['error']) && !empty($res['error'])) {
            throw new Exception("Get order list failed: " . ($res['message'] ?? json_encode($res['error'])));
        }

        if (isset($res['response']['order_list'])) {
            foreach ($res['response']['order_list'] as $o) {
                if (isset($o['order_sn'])) {
                    $orderSns[] = $o['order_sn'];
                }
            }
        }

        $cursor = $res['response']['next_cursor'] ?? '';
    } while ($cursor !== '');

    $totalFound = count($orderSns);
    echo "[Order Watcher] Found {$totalFound} updated orders.\n";

    if (empty($orderSns)) {
        echo "[Order Watcher] No updated orders. Exiting.\n";
        exit;
    }

    // 4. Fetch details in batches of 50 to get items and status
    $chunks = array_chunk($orderSns, 50);
    $changedItems = []; // key: item_id-model_id, val: ['item_id' => x, 'model_id' => y]

    foreach ($chunks as $chunkIndex => $chunk) {
        $batchNum = $chunkIndex + 1;
        echo "[Order Watcher] Fetching details for batch {$batchNum} (" . count($chunk) . " orders)...\n";

        $detailRes = $shopee->get('/api/v2/order/get_order_detail', [
            'order_sn_list' => implode(',', $chunk),
            'response_optional_fields' => 'item_list,buyer_username'
        ], $accessToken, $shopId);

        if (isset($detailRes['error']) && !empty($detailRes['error'])) {
            echo "[Order Watcher Warning] Failed to fetch order details for batch {$batchNum}: " . ($detailRes['message'] ?? json_encode($detailRes['error'])) . "\n";
            continue;
        }

        $orderList = $detailRes['response']['order_list'] ?? [];
        foreach ($orderList as $order) {
            $orderSn = $order['order_sn'];
            $orderStatus = $order['order_status'];
            $buyerUsername = $order['buyer_username'] ?? '';
            $itemList = $order['item_list'] ?? [];

            // Status category: active reservation
            if (in_array($orderStatus, ['READY_TO_SHIP', 'PROCESSED', 'SHIPPED', 'TO_RECEIVE'])) {
                foreach ($itemList as $item) {
                    $itemId = $item['item_id'];
                    $modelId = $item['model_id'] ?? null;
                    $sku = $item['model_sku'] ?: $item['item_sku'] ?: '';
                    $qty = (int)($item['model_quantity_purchased'] ?? $item['quantity_purchased'] ?? 1);

                    // Insert or update reservation row
                    $stmt = $conn->prepare("
                        INSERT INTO shopee_reserved_stock 
                        (order_sn, shopee_item_id, shopee_model_id, sku, quantity, order_status, buyer_username, is_active, reserved_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, 1, NOW())
                        ON DUPLICATE KEY UPDATE 
                        quantity = VALUES(quantity), order_status = VALUES(order_status), buyer_username = VALUES(buyer_username), is_active = 1
                    ");
                    $stmt->execute([$orderSn, $itemId, $modelId ?: null, $sku, $qty, $orderStatus, $buyerUsername]);

                    $key = "{$itemId}-" . ($modelId ?: '0');
                    $changedItems[$key] = ['item_id' => $itemId, 'model_id' => $modelId];
                }
            } 
            // Status category: released/completed/cancelled
            elseif (in_array($orderStatus, ['COMPLETED', 'CANCELLED', 'IN_CANCEL'])) {
                // Get associated items for this active reservation before we release it
                $getStmt = $conn->prepare("SELECT shopee_item_id, shopee_model_id FROM shopee_reserved_stock WHERE order_sn = ? AND is_active = 1");
                $getStmt->execute([$orderSn]);
                $reservedItems = $getStmt->fetchAll(PDO::FETCH_ASSOC);

                if (!empty($reservedItems)) {
                    $updStmt = $conn->prepare("UPDATE shopee_reserved_stock SET is_active = 0, order_status = ?, released_at = NOW() WHERE order_sn = ?");
                    $updStmt->execute([$orderStatus, $orderSn]);

                    foreach ($reservedItems as $ri) {
                        $itemId = $ri['shopee_item_id'];
                        $modelId = $ri['shopee_model_id'];
                        $key = "{$itemId}-" . ($modelId ?: '0');
                        $changedItems[$key] = ['item_id' => $itemId, 'model_id' => $modelId];
                    }
                    echo "[Order Watcher] Released reservation for order {$orderSn} (Status: {$orderStatus})\n";
                }
            }
        }

        // Slight sleep between batches
        usleep(300000);
    }

    // 5. Stock sync based on reservations is disabled
    // In Transit/Reserved stock is purely for monitoring in the POS UI now.
    // Stock deduction happens natively when POS orders sync or when allocations change.

    echo "[Order Watcher] Completed successfully!\n";

} catch (Exception $e) {
    echo "[Order Watcher Error] " . $e->getMessage() . "\n";
}

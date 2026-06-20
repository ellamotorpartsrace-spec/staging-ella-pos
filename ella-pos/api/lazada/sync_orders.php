<?php
/**
 * api/lazada/sync_orders.php — Fetch recent orders from Lazada & save to DB
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
        } else {
            throw new Exception("Lazada token expired and auto-refresh failed.");
        }
    }

    set_time_limit(0);
    $pageSize = 50;
    
    $days_to_sync = isset($_GET['days']) ? (int)$_GET['days'] : 90; // Default to 90 days for better historical data
    
    // Lazada only allows max 15 days per request, so we chunk it
    $chunks = [];
    $remainingDays = $days_to_sync;
    $endTime = time();
    
    while ($remainingDays > 0) {
        $chunkDays = min(15, $remainingDays);
        $startTime = $endTime - ($chunkDays * 86400);
        $chunks[] = ['from' => $startTime, 'to' => $endTime];
        $endTime = $startTime;
        $remainingDays -= $chunkDays;
    }

    $allOrderSns = [];

    // 1. Fetch Order SN List
    foreach ($chunks as $chunk) {
        $cursor = "";
        do {
            $apiParams = [
                'time_range_field' => 'create_time', // Change to create_time for historical fetching
                'time_from'        => $chunk['from'],
                'time_to'          => $chunk['to'],
                'page_size'        => $pageSize,
            ];
            if (!empty($cursor)) {
                $apiParams['cursor'] = $cursor;
            }

            $listResult = $lazada->get('/api/v2/order/get_order_list', $apiParams, $accessToken, $shopId);

            if (isset($listResult['error']) && $listResult['error'] !== '' && $listResult['error'] !== 0) {
                // If there's an error on an older chunk, just break the inner loop and continue to the next chunk
                break;
            }

            $orders = $listResult['response']['order_list'] ?? [];
            foreach ($orders as $o) {
                if (!in_array($o['order_sn'], $allOrderSns)) {
                    $allOrderSns[] = $o['order_sn'];
                }
            }

            $cursor = $listResult['response']['next_cursor'] ?? "";
            $hasNextPage = $listResult['response']['more'] ?? false;
            
            if (!$hasNextPage || empty($cursor) || (isset($prevCursor) && $cursor === $prevCursor)) {
                break;
            }
            $prevCursor = $cursor;
            
            // Rate Limiter: pause for 200ms
            usleep(200000);
        } while (true);
    }

    if (empty($allOrderSns)) {
        echo json_encode(['success' => true, 'message' => 'No orders found in the given timeframe.', 'synced_count' => 0]);
        exit;
    }

    // 2. Fetch Order Details (max 50 per request)
    $orderSnChunks = array_chunk($allOrderSns, 50);
    $orderDetails = [];

    foreach ($orderSnChunks as $chunk) {
        $detailResult = $lazada->get('/api/v2/order/get_order_detail', [
            'order_sn_list' => implode(',', $chunk),
            'response_optional_fields' => 'buyer_username,estimated_shipping_fee,item_list,pay_time,cancel_reason,shipping_carrier,payment_method,total_amount'
        ], $accessToken, $shopId);

        if (isset($detailResult['response']['order_list'])) {
            $orderDetails = array_merge($orderDetails, $detailResult['response']['order_list']);
        }
        
        // Rate Limiter: pause for 200ms
        usleep(200000);
    }

    $insertedOrders = 0;
    $updatedOrders = 0;

    // Pre-load mappings to get pos_unit_id and capital_cost
    $mappingsStmt = $conn->query("
        SELECT m.lazada_item_id, m.lazada_model_id, m.pos_product_id, m.pos_unit_id, u.price_capital 
        FROM lazada_product_mappings m
        LEFT JOIN product_units u ON m.pos_unit_id = u.id
        WHERE m.mapping_status IN ('auto','manual') AND m.pos_unit_id IS NOT NULL
    ");
    $mappingDict = [];
    while ($row = $mappingsStmt->fetch(PDO::FETCH_ASSOC)) {
        $key = $row['lazada_item_id'] . '_' . ($row['lazada_model_id'] ?? 0);
        $mappingDict[$key] = $row;
    }

    // 3. Save to Database
    $conn->beginTransaction();

    $stmtOrder = $conn->prepare("
        INSERT INTO lazada_orders (
            order_sn, order_status, create_time, update_time, buyer_username, 
            total_amount, estimated_shipping_fee, payment_method, shipping_carrier, cancel_reason
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE 
            order_status = VALUES(order_status),
            update_time = VALUES(update_time),
            total_amount = VALUES(total_amount),
            cancel_reason = VALUES(cancel_reason)
    ");

    $stmtItem = $conn->prepare("
        INSERT INTO lazada_order_items (
            order_sn, item_id, model_id, item_name, item_sku, model_name, model_sku,
            original_price, discounted_price, quantity_purchased, pos_unit_id, capital_cost
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            item_name = VALUES(item_name),
            discounted_price = VALUES(discounted_price),
            pos_unit_id = VALUES(pos_unit_id),
            capital_cost = VALUES(capital_cost)
    ");

    foreach ($orderDetails as $order) {
        $createTime = date('Y-m-d H:i:s', $order['create_time']);
        $updateTime = isset($order['update_time']) ? date('Y-m-d H:i:s', $order['update_time']) : $createTime;
        
        $stmtOrder->execute([
            $order['order_sn'],
            $order['order_status'],
            $createTime,
            $updateTime,
            $order['buyer_username'] ?? '',
            $order['total_amount'] ?? 0.0,
            $order['estimated_shipping_fee'] ?? 0.0,
            $order['payment_method'] ?? '',
            $order['shipping_carrier'] ?? '',
            $order['cancel_reason'] ?? ''
        ]);

        if ($stmtOrder->rowCount() > 0) {
            $insertedOrders++;
        }

        if (isset($order['item_list'])) {
            foreach ($order['item_list'] as $item) {
                $itemId = $item['item_id'];
                $modelId = $item['model_id'] ?? 0;
                $mapKey = $itemId . '_' . $modelId;
                
                $posUnitId = null;
                $capitalCost = 0.0;
                
                if (isset($mappingDict[$mapKey])) {
                    $posUnitId = $mappingDict[$mapKey]['pos_unit_id'];
                    $capitalCost = $mappingDict[$mapKey]['price_capital'] ?? 0.0;
                }

                $stmtItem->execute([
                    $order['order_sn'],
                    $itemId,
                    $modelId,
                    $item['item_name'] ?? '',
                    $item['item_sku'] ?? '',
                    $item['model_name'] ?? '',
                    $item['model_sku'] ?? '',
                    $item['item_price'] ?? 0.0, // Original price (or maybe current)
                    $item['model_discounted_price'] ?? ($item['item_price'] ?? 0.0),
                    $item['model_quantity_purchased'] ?? 1,
                    $posUnitId,
                    $capitalCost
                ]);
            }
        }
    }

    $conn->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Orders synced successfully',
        'synced_count' => count($orderDetails),
        'inserted_or_updated' => $insertedOrders
    ]);

} catch (Exception $e) {
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

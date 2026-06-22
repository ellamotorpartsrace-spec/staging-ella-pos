<?php
/**
 * api/shopee/sync_orders.php — Fetch recent orders from Shopee & save to DB
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
require_once __DIR__ . '/../../classes/ShopeeAPI.php';

try {
    $db = new Database();
    $conn = $db->getConnection();

    // Load active Shopee config
    $stmt = $conn->prepare("SELECT * FROM shopee_config WHERE is_active = 1 LIMIT 1");
    $stmt->execute();
    $config = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$config || empty($config['access_token'])) {
        echo json_encode(['success' => false, 'error' => 'Shopee not authorized.']);
        exit;
    }

    $isTest = $config['environment'] === 'test';
    $shopee = new ShopeeAPI($config['partner_id'], $config['partner_key'], $isTest);
    $accessToken = $config['access_token'];
    $shopId      = $config['shop_id'];

    if (strtotime($config['token_expires_at']) - time() < 600) {
        $refreshResult = $shopee->refreshToken($config['refresh_token'], $shopId);
        if (isset($refreshResult['access_token'])) {
            $accessToken = $refreshResult['access_token'];
            $expiresAt = date('Y-m-d H:i:s', time() + ($refreshResult['expire_in'] ?? 14400));
            $conn->prepare("UPDATE shopee_config SET access_token=?, refresh_token=?, token_expires_at=?, updated_at=NOW() WHERE is_active=1")
                ->execute([$accessToken, $refreshResult['refresh_token'], $expiresAt]);
        } else {
            throw new Exception("Shopee token expired and auto-refresh failed.");
        }
    }

    set_time_limit(0);
    $pageSize = 50;
    
    $days_to_sync = isset($_GET['days']) ? (int)$_GET['days'] : 90; // Default to 90 days for better historical data
    
    // Shopee only allows max 15 days per request, so we chunk it
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

            $listResult = $shopee->get('/api/v2/order/get_order_list', $apiParams, $accessToken, $shopId);

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
        $detailResult = $shopee->get('/api/v2/order/get_order_detail', [
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
        SELECT m.shopee_item_id, m.shopee_model_id, m.pos_product_id, m.pos_bundle_set_id, m.pos_unit_id, u.price_capital, u.multiplier 
        FROM shopee_product_mappings m
        LEFT JOIN product_units u ON m.pos_unit_id = u.id
        WHERE m.mapping_status IN ('auto','manual') AND (m.pos_product_id IS NOT NULL OR m.pos_bundle_set_id IS NOT NULL)
    ");
    $mappingDict = [];
    while ($row = $mappingsStmt->fetch(PDO::FETCH_ASSOC)) {
        $key = $row['shopee_item_id'] . '_' . ($row['shopee_model_id'] ?? 0);
        $mappingDict[$key] = $row;
    }

    // Pre-load existing orders to check inventory_deducted flag
    $existingOrders = [];
    $orderSnChunksForStatus = array_chunk($allOrderSns, 100);
    foreach ($orderSnChunksForStatus as $snChunk) {
        $placeholders = implode(',', array_fill(0, count($snChunk), '?'));
        $stmt = $conn->prepare("SELECT order_sn, order_status, inventory_deducted FROM shopee_orders WHERE order_sn IN ($placeholders)");
        $stmt->execute($snChunk);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $existingOrders[$row['order_sn']] = $row;
        }
    }

    // Pre-load bundle components
    $bundlesStmt = $conn->query("
        SELECT si.product_set_id, si.component_variation_id, si.component_qty, COALESCE(u.multiplier, 1) as multiplier, p.price_capital
        FROM product_unit_set_items si
        LEFT JOIN product_units u ON si.component_unit_id = u.id
        LEFT JOIN product_variations p ON si.component_variation_id = p.variation_id
    ");
    $bundleDict = [];
    while ($row = $bundlesStmt->fetch(PDO::FETCH_ASSOC)) {
        $bundleDict[$row['product_set_id']][] = $row;
    }

    // 3. Save to Database
    $conn->beginTransaction();

    $stmtOrder = $conn->prepare("
        INSERT INTO shopee_orders (
            order_sn, order_status, create_time, update_time, buyer_username, 
            total_amount, estimated_shipping_fee, payment_method, shipping_carrier, cancel_reason, inventory_deducted
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE 
            order_status = VALUES(order_status),
            update_time = VALUES(update_time),
            total_amount = VALUES(total_amount),
            cancel_reason = VALUES(cancel_reason),
            inventory_deducted = VALUES(inventory_deducted)
    ");

    $stmtItem = $conn->prepare("
        INSERT INTO shopee_order_items (
            order_sn, item_id, model_id, item_name, item_sku, model_name, model_sku,
            original_price, discounted_price, quantity_purchased, pos_unit_id, capital_cost
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            item_name = VALUES(item_name),
            discounted_price = VALUES(discounted_price),
            pos_unit_id = VALUES(pos_unit_id),
            capital_cost = VALUES(capital_cost)
    ");

    // IMPORTANT: Shopee orders must deduct from/restore to store_id = 2 (online/Shopee allocated stock).
    // Using store_id = 1 (physical POS) was the bug causing POS stock inaccuracies.
    $getInvStmt = $conn->prepare("SELECT quantity FROM inventory WHERE variation_id = ? AND store_id = 2 FOR UPDATE");
    $insertInvStmt = $conn->prepare("INSERT INTO inventory (variation_id, store_id, quantity) VALUES (?, 2, ?) ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)");
    $moveStmt = $conn->prepare("INSERT INTO stock_movements (store_id, variation_id, type, quantity, previous_stock, new_stock, reference, remarks, created_by, capital_cost) VALUES (2, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

    foreach ($orderDetails as $order) {
        $createTime = date('Y-m-d H:i:s', $order['create_time']);
        $updateTime = isset($order['update_time']) ? date('Y-m-d H:i:s', $order['update_time']) : $createTime;
        $orderSn = $order['order_sn'];
        $orderStatus = $order['order_status'];

        $existingOrder = $existingOrders[$orderSn] ?? null;
        $wasDeducted = $existingOrder ? (int)$existingOrder['inventory_deducted'] : 0;
        $isCancelled = in_array($orderStatus, ['CANCELLED', 'IN_CANCEL', 'TO_RETURN']);
        
        $shouldDeduct = (!$isCancelled && $wasDeducted === 0);
        $shouldRestock = ($isCancelled && $wasDeducted === 1);
        $newDeductedState = $wasDeducted;

        if ($shouldDeduct) {
            $newDeductedState = 1;
        } elseif ($shouldRestock) {
            $newDeductedState = 0;
        }
        
        $stmtOrder->execute([
            $orderSn,
            $orderStatus,
            $createTime,
            $updateTime,
            $order['buyer_username'] ?? '',
            $order['total_amount'] ?? 0.0,
            $order['estimated_shipping_fee'] ?? 0.0,
            $order['payment_method'] ?? '',
            $order['shipping_carrier'] ?? '',
            $order['cancel_reason'] ?? '',
            $newDeductedState
        ]);

        if ($stmtOrder->rowCount() > 0) {
            $insertedOrders++;
        }

        $variationsToUpdate = [];

        if (isset($order['item_list'])) {
            foreach ($order['item_list'] as $item) {
                $itemId = $item['item_id'];
                $modelId = $item['model_id'] ?? 0;
                $mapKey = $itemId . '_' . $modelId;
                
                $posUnitId = null;
                $capitalCost = 0.0;
                $purchasedQty = (int)($item['model_quantity_purchased'] ?? 1);
                
                if (isset($mappingDict[$mapKey])) {
                    $map = $mappingDict[$mapKey];
                    $posUnitId = $map['pos_unit_id'];
                    $capitalCost = (float)($map['price_capital'] ?? 0.0);

                    if ($shouldDeduct || $shouldRestock) {
                        if (!empty($map['pos_bundle_set_id'])) {
                            $setId = $map['pos_bundle_set_id'];
                            if (isset($bundleDict[$setId])) {
                                foreach ($bundleDict[$setId] as $comp) {
                                    $baseQty = $purchasedQty * (int)$comp['component_qty'] * (int)$comp['multiplier'];
                                    $varId = $comp['component_variation_id'];
                                    if (!isset($variationsToUpdate[$varId])) $variationsToUpdate[$varId] = ['qty' => 0, 'capital' => (float)$comp['price_capital']];
                                    $variationsToUpdate[$varId]['qty'] += $baseQty;
                                }
                            }
                        } elseif (!empty($map['pos_product_id'])) {
                            $varId = $map['pos_product_id'];
                            $baseQty = $purchasedQty * (int)($map['multiplier'] ?? 1);
                            if (!isset($variationsToUpdate[$varId])) $variationsToUpdate[$varId] = ['qty' => 0, 'capital' => $capitalCost];
                            $variationsToUpdate[$varId]['qty'] += $baseQty;
                        }
                    }
                }

                $stmtItem->execute([
                    $orderSn,
                    $itemId,
                    $modelId,
                    $item['item_name'] ?? '',
                    $item['item_sku'] ?? '',
                    $item['model_name'] ?? '',
                    $item['model_sku'] ?? '',
                    $item['item_price'] ?? 0.0,
                    $item['model_discounted_price'] ?? ($item['item_price'] ?? 0.0),
                    $purchasedQty,
                    $posUnitId,
                    $capitalCost
                ]);
            }
        }

        if (!empty($variationsToUpdate)) {
            $type = $shouldDeduct ? 'online_sale' : 'online_adjustment';
            $remarks = $shouldDeduct ? 'Shopee Order' : 'Shopee Order Cancelled/Returned';
            $qtyMultiplier = $shouldDeduct ? -1 : 1;
            $ref = 'SHP-' . $orderSn;
            $userId = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 1;
            
            foreach ($variationsToUpdate as $varId => $data) {
                $qtyChange = $data['qty'] * $qtyMultiplier;
                $absQty = $data['qty'];
                
                $getInvStmt->execute([$varId]);
                $currentStock = $getInvStmt->fetchColumn();
                if ($currentStock === false) {
                    $currentStock = 0;
                } else {
                    $currentStock = (int)$currentStock;
                }
                
                $newStock = $currentStock + $qtyChange;
                
                $insertInvStmt->execute([$varId, $newStock]);
                $moveStmt->execute([
                    $varId, $type, $absQty, $currentStock, $newStock, $ref, $remarks, $userId, $data['capital']
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

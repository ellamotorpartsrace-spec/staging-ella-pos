<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/ShopeeAPI.php';

try {
    $db = new Database();
    $conn = $db->getConnection();

    $stmt = $conn->prepare("SELECT * FROM shopee_config WHERE is_active = 1 LIMIT 1");
    $stmt->execute();
    $config = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$config) {
        die("[Fix] No active Shopee configuration found.\n");
    }

    $isTest = $config['environment'] === 'test';
    $shopee = new ShopeeAPI($config['partner_id'], $config['partner_key'], $isTest);
    $accessToken = $config['access_token'];
    $shopId = $config['shop_id'];

    $orderSns = ['260517KP2QNJ65', '260520VAPRQKUB'];
    
    echo "[Fix] Fetching details for specific missing orders: " . implode(', ', $orderSns) . "...\n";

    $detailRes = $shopee->get('/api/v2/order/get_order_detail', [
        'order_sn_list' => implode(',', $orderSns),
        'response_optional_fields' => 'item_list,buyer_username'
    ], $accessToken, $shopId);

    if (isset($detailRes['error']) && !empty($detailRes['error'])) {
        echo "[Fix API Error] " . json_encode($detailRes) . "\n";
        exit;
    }

    $orderList = $detailRes['response']['order_list'] ?? [];
    if (empty($orderList)) {
        echo "[Fix] No orders found!\n";
    }

    foreach ($orderList as $order) {
        $orderSn = $order['order_sn'];
        $orderStatus = $order['order_status'];
        $buyerUsername = $order['buyer_username'] ?? '';
        $itemList = $order['item_list'] ?? [];

        echo "[Fix] Order {$orderSn} has status: {$orderStatus}\n";

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
                echo "[Fix] Synced item {$itemId} x{$qty} for {$orderSn}\n";
            }
        } elseif (in_array($orderStatus, ['READY_TO_SHIP', 'PROCESSED', 'COMPLETED', 'CANCELLED', 'IN_CANCEL'])) {
            $conn->prepare("UPDATE shopee_reserved_stock SET is_active = 0, order_status = ?, released_at = NOW() WHERE order_sn = ?")->execute([$orderStatus, $orderSn]);
            echo "[Fix] Released {$orderSn} (status: {$orderStatus})\n";
        }
    }

    echo "[Fix] Done!\n";

} catch (Exception $e) {
    echo "[Fix Exception] " . $e->getMessage() . "\n";
}

<?php
/**
 * api/shopee/sync_individual.php — Sync a single Shopee item (pull info + push stock)
 */
header("Content-Type: application/json");
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../classes/ShopeeAPI.php';

requireLogin();
if (!hasPermission('shopee_sync')) {
    echo json_encode(['success' => false, 'error' => 'Permission denied. Please request access from admin Les.']);
    exit;
}


$id = $_GET['id'] ?? null;
if (!$id) {
    echo json_encode(['success' => false, 'error' => 'Missing mapping ID']);
    exit;
}

try {
    $db = new Database();
    $conn = $db->getConnection();

    // 1. Get current mapping and Shopee config with physical (1), Shopee (2) and Lazada (3) quantities
    $stmt = $conn->prepare("SELECT m.*, c.partner_id, c.partner_key, c.shop_id, c.access_token, c.environment, c.buffer_stock, c.out_of_stock_alerts,
        COALESCE(i1.quantity, 0) as pos_physical_qty,
        COALESCE(i2.quantity, 0) as pos_shopee_qty,
        COALESCE(i3.quantity, 0) as pos_lazada_qty
        FROM shopee_product_mappings m
        JOIN shopee_config c ON c.is_active = 1
        LEFT JOIN inventory i1 ON m.pos_product_id = i1.variation_id AND i1.store_id = 1
        LEFT JOIN inventory i2 ON m.pos_product_id = i2.variation_id AND i2.store_id = 2
        LEFT JOIN inventory i3 ON m.pos_product_id = i3.variation_id AND i3.store_id = 3
        WHERE m.id = ?");
    $stmt->execute([$id]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$item) {
        echo json_encode(['success' => false, 'error' => 'Mapping not found']);
        exit;
    }

    $isTest = $item['environment'] === 'test';
    $shopee = new ShopeeAPI($item['partner_id'], $item['partner_key'], $isTest);
    $accessToken = $item['access_token'];
    $shopId = $item['shop_id'];

    // 2. Refresh info from Shopee
    $infoResult = $shopee->get('/api/v2/product/get_item_base_info', [
        'item_id_list' => $item['shopee_item_id']
    ], $accessToken, $shopId);

    $shopeeItem = $infoResult['response']['item_list'][0] ?? null;
    if ($shopeeItem) {
        // Update price and generic info
        $price = $shopeeItem['price_info'][0]['current_price'] ?? $item['shopee_price'];
        $conn->prepare("UPDATE shopee_product_mappings SET shopee_price = ?, updated_at = NOW() WHERE id = ?")
            ->execute([$price, $id]);
    }

    // 3. If mapped, push stock (dynamically compute using stock_allocation_ratio)
    $posPhysicalQty = (float) $item['pos_physical_qty'];
    $posShopeeQty = (float) $item['pos_shopee_qty'];
    $posLazadaQty = (float) $item['pos_lazada_qty'];
    $totalQty = $posPhysicalQty + $posShopeeQty + $posLazadaQty;

    $ratio = isset($item['stock_allocation_ratio']) ? (int)$item['stock_allocation_ratio'] : 100;
    $computedStock = floor(($posPhysicalQty + $posShopeeQty) * ($ratio / 100));
    if ($computedStock < 0) $computedStock = 0;

    // Start transaction before updating POS inventory and pushing to Shopee
    $conn->beginTransaction();

    // Update local cached stock value for THIS mapping first
    $conn->prepare("UPDATE shopee_product_mappings SET shopee_stock = ?, updated_at = NOW() WHERE id = ?")
        ->execute([$computedStock, $id]);

    $newOnlineStock = $computedStock;
    if (!empty($item['pos_product_id'])) {
        $posSku = trim((string)($item['matched_pos_sku'] ?? ''));
        if (empty($posSku)) {
            $skuStmt = $conn->prepare("SELECT sku FROM product_variations WHERE variation_id = ?");
            $skuStmt->execute([$item['pos_product_id']]);
            $posSku = trim((string)$skuStmt->fetchColumn());
        }
        $sumStmt = $conn->prepare("
            SELECT COALESCE(SUM(m.shopee_stock * COALESCE(u.multiplier, 1)), 0) 
            FROM shopee_product_mappings m
            LEFT JOIN product_units u ON m.pos_unit_id = u.id
            WHERE (m.pos_product_id = ? OR (m.matched_pos_sku = ? AND m.matched_pos_sku NOT IN ('', '-', 'N/A', 'NA', 'none', 'null')))
              AND m.mapping_status IN ('auto','manual')
        ");
        $sumStmt->execute([$item['pos_product_id'], $posSku]);
        $newOnlineStock = (int)$sumStmt->fetchColumn();
    }

    // Apply delta to physical stock — this keeps the history chain accurate
    $allocDelta = $newOnlineStock - $posShopeeQty;
    $newPhysicalStock = $posPhysicalQty - $allocDelta;
    if ($newPhysicalStock < 0) $newPhysicalStock = 0;

    if (!empty($item['pos_product_id'])) {
        // Update or insert physical store (store_id = 1)
        $updStore1 = $conn->prepare("
            INSERT INTO inventory (variation_id, store_id, quantity) 
            VALUES (?, 1, ?)
            ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)
        ");
        $updStore1->execute([$item['pos_product_id'], $newPhysicalStock]);

        // Update or insert online shop (store_id = 2)
        $updStore2 = $conn->prepare("
            INSERT INTO inventory (variation_id, store_id, quantity) 
            VALUES (?, 2, ?)
            ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)
        ");
        $updStore2->execute([$item['pos_product_id'], $newOnlineStock]);

        // Log stock movements for audit trailing
        $stmtCap = $conn->prepare("SELECT price_capital FROM product_variations WHERE variation_id = ?");
        $stmtCap->execute([$item['pos_product_id']]);
        $capital_cost = (float)($stmtCap->fetchColumn() ?? 0);

        $movementStmt = $conn->prepare("
            INSERT INTO stock_movements 
            (variation_id, store_id, type, quantity, previous_stock, new_stock, reference, remarks, created_by, capital_cost)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'Shopee Stock Allocation Sync (Individual)', ?, ?)
        ");

        $ref = 'SHP-ALLOC-IND-' . date('YmdHis');
        $userId = $_SESSION['user_id'] ?? null;

        // Log Physical Store changes
        $physicalDiff = $newPhysicalStock - $posPhysicalQty;
        if ($physicalDiff != 0) {
            $physicalType = $physicalDiff < 0 ? 'allocation_to_online' : 'allocation_to_physical';
            $movementStmt->execute([
                $item['pos_product_id'],
                1,
                $physicalType,
                $physicalDiff,
                $posPhysicalQty,
                $newPhysicalStock,
                $ref,
                $userId,
                $capital_cost
            ]);
        }

        // Log Online Shop changes
        $onlineDiff = $newOnlineStock - $posShopeeQty;
        if ($onlineDiff != 0) {
            $onlineType = $onlineDiff > 0 ? 'allocation_to_online' : 'allocation_to_physical';
            $movementStmt->execute([
                $item['pos_product_id'],
                2,
                $onlineType,
                $onlineDiff,
                $posShopeeQty,
                $newOnlineStock,
                $ref,
                $userId,
                $capital_cost
            ]);
        }
    }

    $bufferStock = (int)($item['buffer_stock'] ?? 0);
    $pushedStock = $newOnlineStock - $bufferStock;
    if ($pushedStock < 0) $pushedStock = 0;

    // Create OOS alert if stock hit 0 and it wasn't 0 before
    if ($pushedStock == 0 && (int)$item['shopee_stock'] > 0 && !empty($item['out_of_stock_alerts'])) {
        $prodName = $item['shopee_product_name'];
        if (!empty($item['shopee_variation_name'])) {
            $prodName .= ' — ' . $item['shopee_variation_name'];
        }
        $alertMsg = "'{$prodName}' has run completely out of stock online! Please restock soon.";
        $conn->prepare("INSERT INTO shopee_alerts (mapping_id, message) VALUES (?, ?)")
             ->execute([$id, $alertMsg]);
    }

    $stockItem = [
        'seller_stock' => [
            [
                'stock' => (int)$pushedStock
            ]
        ]
    ];
    if ($item['shopee_model_id']) {
        $stockItem['model_id'] = (int)$item['shopee_model_id'];
    }

    $syncResult = $shopee->post('/api/v2/product/update_stock', [
        'item_id' => (int)$item['shopee_item_id'],
        'stock_list' => [$stockItem]
    ], $accessToken, $shopId);

    if (isset($syncResult['error']) && !empty($syncResult['error'])) {
        throw new Exception("Shopee Push Error: " . ($syncResult['message'] ?? json_encode($syncResult['error'])));
    }

    // Calculate difference for clearer logging
    $oldStock = (int)$item['shopee_stock'];
    $newStock = (int)$computedStock;
    $diff = $newStock - $oldStock;
    $diffText = ($diff >= 0) ? "+$diff" : (string)$diff;
    $label = ($diff > 0) ? "Added" : ($diff < 0 ? "Deducted" : "Updated");

    // 4. Log the success in shopee_sync_logs
    $conn->prepare("INSERT INTO shopee_sync_logs (event_type, shopee_item_id, product_name, sku, old_value, new_value, source, status, created_by, created_at) VALUES ('stock_update', ?, ?, ?, ?, ?, 'Manual Sync (Individual)', 'success', ?, NOW())")
        ->execute([
            $item['shopee_item_id'],
            $item['shopee_product_name'], 
            $item['matched_pos_sku'], 
            (string)$oldStock,
            "$newStock ($diffText $label)",
            $_SESSION['user_id'] ?? null
        ]);

    $conn->commit();

    echo json_encode(['success' => true, 'message' => 'Product synced successfully']);

} catch (Exception $e) {
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    try {
        if (isset($conn)) {
            $logStmt = $conn->prepare("INSERT INTO shopee_sync_logs (event_type, shopee_item_id, product_name, sku, status, error_message, created_by, created_at) VALUES ('stock_update', ?, ?, ?, 'failed', ?, ?, NOW())");
            $logStmt->execute([
                isset($item) ? $item['shopee_item_id'] : null,
                isset($item) ? $item['shopee_product_name'] : null,
                isset($item) ? $item['matched_pos_sku'] : null,
                $e->getMessage(),
                $_SESSION['user_id'] ?? null
            ]);
        }
    } catch (Exception $logEx) {}
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

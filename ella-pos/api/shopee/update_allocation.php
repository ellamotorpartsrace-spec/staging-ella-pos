<?php
/**
 * api/shopee/update_allocation.php — Update stock allocation and push to Shopee
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

$input = json_decode(file_get_contents('php://input'), true);
$id = $input['id'] ?? null;
$onlineStock = isset($input['online_stock']) ? (int)$input['online_stock'] : null;

if ($id === null || $onlineStock === null) {
    echo json_encode(['success' => false, 'error' => 'Missing ID or stock value']);
    exit;
}

try {
    $db = new Database();
    $conn = $db->getConnection();

    // 1. Get current mapping, Shopee config, POS stocks, and unit multiplier
    $stmt = $conn->prepare("SELECT m.*, c.partner_id, c.partner_key, c.shop_id, c.access_token, c.environment, c.buffer_stock, c.out_of_stock_alerts,
        COALESCE(i1.quantity, 0) as pos_physical_qty,
        COALESCE(i2.quantity, 0) as pos_online_qty,
        COALESCE(u.multiplier, 1) as unit_multiplier,
        u.unit_name
        FROM shopee_product_mappings m
        JOIN shopee_config c ON c.is_active = 1
        LEFT JOIN product_units u ON m.pos_unit_id = u.id
        LEFT JOIN inventory i1 ON m.pos_product_id = i1.variation_id AND i1.store_id = 1
        LEFT JOIN inventory i2 ON m.pos_product_id = i2.variation_id AND i2.store_id = 2
        WHERE m.id = ?");
    $stmt->execute([$id]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$item) {
        echo json_encode(['success' => false, 'error' => 'Product mapping or active config not found']);
        exit;
    }

    $posPhysicalQty = (int)$item['pos_physical_qty'];
    $posOnlineQty = (int)$item['pos_online_qty'];
    $totalQty = $posPhysicalQty + $posOnlineQty;
    $unitMultiplier = max(1, (int)$item['unit_multiplier']);
    $isBundleMapping = !empty($item['pos_bundle_set_id']);

    if ($isBundleMapping) {
        $bundleSetId = (int)$item['pos_bundle_set_id'];
        $bundleStmt = $conn->prepare("
            SELECT
                si.component_variation_id,
                si.component_qty,
                COALESCE(cu.multiplier, 1) AS component_unit_multiplier,
                (COALESCE(i1.quantity, 0) + COALESCE(i2.quantity, 0)) AS component_base_qty,
                COALESCE(res.reserved_base_qty, 0) AS reserved_base_qty
            FROM product_unit_set_items si
            LEFT JOIN product_units cu ON cu.id = si.component_unit_id
            LEFT JOIN inventory i1 ON i1.variation_id = si.component_variation_id AND i1.store_id = 1
            LEFT JOIN inventory i2 ON i2.variation_id = si.component_variation_id AND i2.store_id = 2
            LEFT JOIN (
                SELECT
                    m.pos_product_id,
                    SUM(m.shopee_stock * COALESCE(u.multiplier, 1)) AS reserved_base_qty
                FROM shopee_product_mappings m
                LEFT JOIN product_units u ON u.id = m.pos_unit_id
                WHERE m.mapping_status IN ('auto','manual')
                  AND m.pos_bundle_set_id IS NULL
                  AND m.pos_product_id IS NOT NULL
                GROUP BY m.pos_product_id
            ) res ON res.pos_product_id = si.component_variation_id
            WHERE si.product_set_id = ?
        ");
        $bundleStmt->execute([$bundleSetId]);
        $components = $bundleStmt->fetchAll(PDO::FETCH_ASSOC);

        $otherBundleReserveStmt = $conn->prepare("
            SELECT
                si.component_variation_id,
                SUM(m.shopee_stock * si.component_qty * COALESCE(cu.multiplier, 1)) AS reserved_base_qty
            FROM shopee_product_mappings m
            INNER JOIN product_unit_set_items si ON si.product_set_id = m.pos_bundle_set_id
            LEFT JOIN product_units cu ON cu.id = si.component_unit_id
            WHERE m.mapping_status IN ('auto','manual')
              AND m.pos_bundle_set_id IS NOT NULL
              AND m.id <> ?
            GROUP BY si.component_variation_id
        ");
        $otherBundleReserveStmt->execute([$id]);
        $otherBundleReserved = [];
        foreach ($otherBundleReserveStmt->fetchAll(PDO::FETCH_ASSOC) as $reserveRow) {
            $otherBundleReserved[(int)$reserveRow['component_variation_id']] = (float)$reserveRow['reserved_base_qty'];
        }

        $minPossibleSets = null;
        foreach ($components as $component) {
            $requiredBase = (float)$component['component_qty'] * max(1, (int)$component['component_unit_multiplier']);
            if ($requiredBase <= 0) {
                continue;
            }
            $componentVariationId = (int)$component['component_variation_id'];
            $reservedBase = (float)$component['reserved_base_qty'] + (float)($otherBundleReserved[$componentVariationId] ?? 0);
            $freeBase = max(0, (float)$component['component_base_qty'] - $reservedBase);
            $possibleSets = (int)floor($freeBase / $requiredBase);
            $minPossibleSets = $minPossibleSets === null ? $possibleSets : min($minPossibleSets, $possibleSets);
        }

        $totalQty = max(0, (int)($minPossibleSets ?? 0));
        $unitMultiplier = 1;
    }

    // Calculate unit-converted total (e.g., 120 pcs / 12 multiplier = 10 boxes)
    $unitTotal = floor($totalQty / $unitMultiplier);

    if ($onlineStock < 0) {
        $onlineStock = 0;
    }

    if ($onlineStock > $unitTotal) {
        $unitLabel = $isBundleMapping ? ' (Bundle Set)' : (!empty($item['unit_name']) ? " ({$item['unit_name']})" : '');
        echo json_encode(['success' => false, 'error' => "Allocated stock ({$onlineStock}) cannot exceed total POS available stock ({$unitTotal}{$unitLabel})"]);
        exit;
    }

    // Internally save the corresponding ratio percentage for dynamic syncing later
    $allocationRatio = $unitTotal > 0 ? (int)round(($onlineStock / $unitTotal) * 100) : 100;

    $isTest = $item['environment'] === 'test';
    $shopee = new ShopeeAPI($item['partner_id'], $item['partner_key'], $isTest);

    $conn->beginTransaction();

    // 2. Update the mapping's shopee_stock FIRST so the SUM query below includes the new value
    $updateStmt = $conn->prepare("UPDATE shopee_product_mappings SET stock_allocation_ratio = ?, shopee_stock = ?, updated_at = NOW() WHERE id = ?");
    $updateStmt->execute([$allocationRatio, $onlineStock, $id]);

    // 3. Compute POS online stock = SUM of all (shopee_stock * multiplier) sharing the same SKU (or same POS ID if SKU is empty)
    //    This converts all unit-based allocations back to base pieces for POS inventory deduction
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
            WHERE (m.pos_product_id = ? OR (m.matched_pos_sku = ? AND m.matched_pos_sku != ''))
              AND m.mapping_status IN ('auto','manual')
        ");
        $sumStmt->execute([$item['pos_product_id'], $posSku]);
        $newOnlineStock = (int)$sumStmt->fetchColumn();
        
        // Cap at total stock
        $newOnlineStock = min($newOnlineStock, $totalQty);
        $newPhysicalStock = $totalQty - $newOnlineStock;

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
            VALUES (?, ?, 'allocation_adjustment', ?, ?, ?, ?, 'Shopee Stock Allocation Sync', ?, ?)
        ");

        $ref = 'SHP-ALLOC-' . date('YmdHis');
        $userId = $_SESSION['user_id'] ?? null;

        // Log Physical Store changes
        $physicalDiff = $newPhysicalStock - $posPhysicalQty;
        if ($physicalDiff != 0) {
            $movementStmt->execute([
                $item['pos_product_id'],
                1,
                $physicalDiff,
                $posPhysicalQty,
                $newPhysicalStock,
                $ref,
                $userId,
                $capital_cost
            ]);
        }

        // Log Online Shop changes
        // Per user request, do not register 2 movements. We only log the Physical POS change.
        $onlineDiff = $newOnlineStock - $posOnlineQty;
        /*
        if ($onlineDiff != 0) {
            $movementStmt->execute([
                $item['pos_product_id'],
                2,
                $onlineDiff,
                $posOnlineQty,
                $newOnlineStock,
                $ref,
                $userId,
                $capital_cost
            ]);
        }
        */
    }
    
    // 4. Prepare Shopee API call — send the individual listing's stock (not the total) minus buffer
    $bufferStock = (int)($item['buffer_stock'] ?? 0);
    $pushedStock = $onlineStock - $bufferStock;
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

    $body = [
        'item_id' => (int)$item['shopee_item_id'],
        'stock_list' => [$stockItem]
    ];

    $response = $shopee->post('/api/v2/product/update_stock', $body, $item['access_token'], $item['shop_id']);

    if (isset($response['error']) && !empty($response['error'])) {
        $errorMsg = $response['message'] ?? json_encode($response['error']);
        throw new Exception("Shopee API Error: " . $errorMsg);
    }

    // Calculate difference for cleaner logging
    $oldStock = (int)$item['shopee_stock'];
    $newStock = (int)$onlineStock;
    $diff = $newStock - $oldStock;
    $diffText = ($diff >= 0) ? "+$diff" : (string)$diff;
    $label = ($diff > 0) ? "Added" : ($diff < 0 ? "Deducted" : "Updated");

    // 6. Log the success
    $productLogName = $item['shopee_product_name'];
    if (!empty($item['shopee_variation_name'])) {
        $productLogName .= ' — ' . $item['shopee_variation_name'];
    }

    $logStmt = $conn->prepare("INSERT INTO shopee_sync_logs (event_type, shopee_item_id, product_name, sku, old_value, new_value, source, status, created_by, created_at) 
        VALUES ('stock_update', ?, ?, ?, ?, ?, 'Manual Allocation (Ratio)', 'success', ?, NOW())");
    $logStmt->execute([
        $item['shopee_item_id'],
        $productLogName,
        $item['matched_pos_sku'],
        (string)$oldStock,
        "$newStock ($diffText $label)",
        $_SESSION['user_id'] ?? null
    ]);

    $conn->commit();
    echo json_encode(['success' => true, 'message' => 'Ratio updated and stock synced to Shopee']);

} catch (Exception $e) {
    try {
        if (isset($conn) && $conn->inTransaction()) {
            $conn->rollBack();
        }
    } catch (Exception $rollbackEx) {}
    // Log the failure
    try {
        if (isset($conn)) {
            $productLogName = isset($item) ? $item['shopee_product_name'] : null;
            if (isset($item) && !empty($item['shopee_variation_name'])) {
                $productLogName .= ' — ' . $item['shopee_variation_name'];
            }
            $logStmt = $conn->prepare("INSERT INTO shopee_sync_logs (event_type, shopee_item_id, product_name, sku, status, error_message, created_by, created_at) VALUES ('stock_update', ?, ?, ?, 'failed', ?, ?, NOW())");
            $logStmt->execute([
                isset($item) ? $item['shopee_item_id'] : null,
                $productLogName,
                isset($item) ? $item['matched_pos_sku'] : null,
                $e->getMessage(),
                $_SESSION['user_id'] ?? null
            ]);
        }
    } catch (Exception $ex) {}

    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

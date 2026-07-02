<?php
/**
 * api/lazada/update_allocation.php
 * Updates the stock allocation ratio and pushes to Lazada.
 * Multi-account aware.
 */
header('Content-Type: application/json');
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../classes/LazadaAPI.php';

requireLogin();

if (!hasPermission('lazada_sync')) {
    echo json_encode(['success' => false, 'error' => 'Permission denied. Please request access from admin.']);
    exit;
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$platform = $_SESSION['lazada_active_platform'] ?? 'lazada_main';

$input = json_decode(file_get_contents('php://input'), true);
$id = $input['id'] ?? null;
$onlineStock = isset($input['online_stock']) ? (int)$input['online_stock'] : null;

if ($id === null || $onlineStock === null) {
    echo json_encode(['success' => false, 'error' => 'Missing required fields.']);
    exit;
}

try {
    $db = new Database();
    $conn = $db->getConnection();

    // 1. Get current mapping, config, POS stocks, and unit multiplier
    $stmt = $conn->prepare("SELECT m.*, c.app_key, c.app_secret, c.country_code, c.environment, c.access_token,
        COALESCE(i1.quantity, 0) as pos_physical_qty,
        COALESCE(i2.quantity, 0) as pos_shopee_qty,
        COALESCE(i3.quantity, 0) as pos_lazada_qty,
        COALESCE(u.multiplier, 1) as unit_multiplier,
        u.unit_name
        FROM lazada_product_mappings m
        JOIN lazada_config c ON m.platform_name = c.platform_name
        LEFT JOIN product_units u ON m.pos_unit_id = u.id
        LEFT JOIN inventory i1 ON m.pos_product_id = i1.variation_id AND i1.store_id = 1
        LEFT JOIN inventory i2 ON m.pos_product_id = i2.variation_id AND i2.store_id = 2
        LEFT JOIN inventory i3 ON m.pos_product_id = i3.variation_id AND i3.store_id = 3
        WHERE m.id = ? AND m.platform_name = ?");
    $stmt->execute([$id, $platform]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$item) {
        echo json_encode(['success' => false, 'error' => 'Product mapping or active config not found']);
        exit;
    }

    $posPhysicalQty = (float) $item['pos_physical_qty'];
    $posShopeeQty = (float) $item['pos_shopee_qty'];
    $posLazadaQty = (float) $item['pos_lazada_qty'];
    $totalQty = $posPhysicalQty + $posShopeeQty + $posLazadaQty;
    $unitMultiplier = max(1, (int)$item['unit_multiplier']);
    $isBundleMapping = !empty($item['pos_bundle_set_id']);

    if ($isBundleMapping) {
        // Lazada bundle logic (same as Shopee)
        $bundleSetId = (int)$item['pos_bundle_set_id'];
        $bundleStmt = $conn->prepare("
            SELECT
                si.component_variation_id,
                si.component_qty,
                COALESCE(cu.multiplier, 1) AS component_unit_multiplier,
                (COALESCE(i1.quantity, 0) + COALESCE(i3.quantity, 0)) AS component_base_qty,
                COALESCE(res.reserved_base_qty, 0) AS reserved_base_qty
            FROM product_unit_set_items si
            LEFT JOIN product_units cu ON cu.id = si.component_unit_id
            LEFT JOIN inventory i1 ON i1.variation_id = si.component_variation_id AND i1.store_id = 1
            LEFT JOIN inventory i3 ON i3.variation_id = si.component_variation_id AND i3.store_id = 3
            LEFT JOIN (
                SELECT
                    m.pos_product_id,
                    SUM(m.lazada_stock * COALESCE(u.multiplier, 1)) AS reserved_base_qty
                FROM lazada_product_mappings m
                LEFT JOIN product_units u ON u.id = m.pos_unit_id
                WHERE m.mapping_status NOT IN ('auto','manual','mapped')
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
                SUM(m.lazada_stock * si.component_qty * COALESCE(cu.multiplier, 1)) AS reserved_base_qty
            FROM lazada_product_mappings m
            INNER JOIN product_unit_set_items si ON si.product_set_id = m.pos_bundle_set_id
            LEFT JOIN product_units cu ON cu.id = si.component_unit_id
            WHERE m.mapping_status NOT IN ('auto','manual','mapped')
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
            if ($requiredBase <= 0) continue;
            
            $componentVariationId = (int)$component['component_variation_id'];
            $reservedBase = (float)$component['reserved_base_qty'] + (float)($otherBundleReserved[$componentVariationId] ?? 0);
            $freeBase = max(0, (float)$component['component_base_qty'] - $reservedBase);
            $possibleSets = (int)floor($freeBase / $requiredBase);
            $minPossibleSets = $minPossibleSets === null ? $possibleSets : min($minPossibleSets, $possibleSets);
        }

        $totalQty = max(0, (int)($minPossibleSets ?? 0));
        $unitMultiplier = 1;
    }

    $unitTotal = floor($totalQty / $unitMultiplier);
    if ($onlineStock < 0) $onlineStock = 0;

    if ($onlineStock > $unitTotal) {
        $unitLabel = $isBundleMapping ? ' (Bundle Set)' : (!empty($item['unit_name']) ? " ({$item['unit_name']})" : '');
        echo json_encode(['success' => false, 'error' => "Allocated stock ({$onlineStock}) cannot exceed total POS available stock ({$unitTotal}{$unitLabel})"]);
        exit;
    }

    $allocationRatio = $unitTotal > 0 ? (int)round(($onlineStock / $unitTotal) * 100) : 100;

    // 2. Prepare Lazada API call
    $api = new LazadaAPI(
        $item['app_key'], 
        $item['app_secret'], 
        $item['country_code'], 
        $item['environment'] === 'sandbox'
    );
    
    $safetyFloor = (int)$item['safety_floor'];
    $finalStock = max(0, $onlineStock - $safetyFloor);

    $xmlPayload = "<?xml version=\"1.0\" encoding=\"utf-8\" ?>
<Request>
    <Product>
        <Skus>
            <Sku>
                <SkuId>{$item['lazada_sku_id']}</SkuId>
                <SellerSku>{$item['lazada_seller_sku']}</SellerSku>
                <Quantity>{$finalStock}</Quantity>
            </Sku>
        </Skus>
    </Product>
</Request>";

    $api->setAccessToken($item['access_token']);
    $response = $api->call('/product/price_quantity/update', ['payload' => $xmlPayload], 'POST');

    if (!isset($response['code']) || $response['code'] !== '0') {
        $err = $response['message'] ?? 'Unknown API Error';
        throw new Exception("Lazada API Error: $err");
    }

    // 3. Update the mapping's lazada_stock FIRST so the SUM query below includes the new value
    $updateStmt = $conn->prepare("UPDATE lazada_product_mappings SET stock_allocation_ratio = ?, lazada_stock = ?, updated_at = NOW() WHERE id = ?");
    $updateStmt->execute([$allocationRatio, $onlineStock, $id]);

    // 4. Compute new total Lazada allocation and apply the DIFF to physical store
    if (!empty($item['pos_product_id'])) {
        $posSku = trim((string)($item['sku'] ?? ''));
        if (empty($posSku)) {
            $skuStmt2 = $conn->prepare("SELECT sku FROM product_variations WHERE variation_id = ?");
            $skuStmt2->execute([$item['pos_product_id'] ?? 0]);
            $posSku = trim((string)$skuStmt2->fetchColumn());
        }
        
        $newSumStmt = $conn->prepare("
            SELECT COALESCE(SUM(m.lazada_stock * COALESCE(u.multiplier, 1)), 0)
            FROM lazada_product_mappings m
            LEFT JOIN product_units u ON m.pos_unit_id = u.id
            WHERE (m.pos_product_id = ? OR (m.sku = ? AND m.sku NOT IN ('', '-', 'N/A', 'NA', 'none', 'null')))
              AND m.mapping_status NOT IN ('auto','manual','mapped')
        ");
        $newSumStmt->execute([$item['pos_product_id'], $posSku]);
        $newOnlineStock = (int)$newSumStmt->fetchColumn();

        $allocDelta = $newOnlineStock - $posLazadaQty;
        $newPhysicalStock = $posPhysicalQty - $allocDelta;
        if ($newPhysicalStock < 0) $newPhysicalStock = 0;

        $conn->beginTransaction();

        $updStore1 = $conn->prepare("
            INSERT INTO inventory (variation_id, store_id, quantity) 
            VALUES (?, 1, ?)
            ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)
        ");
        $updStore1->execute([$item['pos_product_id'], $newPhysicalStock]);

        $updStore3 = $conn->prepare("
            INSERT INTO inventory (variation_id, store_id, quantity) 
            VALUES (?, 3, ?)
            ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)
        ");
        $updStore3->execute([$item['pos_product_id'], $newOnlineStock]);

        $stmtCap = $conn->prepare("SELECT price_capital FROM product_variations WHERE variation_id = ?");
        $stmtCap->execute([$item['pos_product_id']]);
        $capital_cost = (float)($stmtCap->fetchColumn() ?? 0);

        $movementStmt = $conn->prepare("
            INSERT INTO stock_movements 
            (variation_id, store_id, type, quantity, previous_stock, new_stock, reference, remarks, created_by, capital_cost)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'Lazada Stock Allocation Sync', ?, ?)
        ");

        $ref = 'LZD-ALLOC-' . date('YmdHis');
        $userId = $_SESSION['user_id'] ?? null;

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
        
        $conn->commit();
    }

    $oldStock = (int)$item['lazada_stock'];
    $newStock = (int)$onlineStock;
    $diff = $newStock - $oldStock;
    $diffText = ($diff >= 0) ? "+$diff" : (string)$diff;
    $label = ($diff > 0) ? "Added" : ($diff < 0 ? "Deducted" : "Updated");

    $productLogName = $item['lazada_product_name'];
    if (!empty($item['lazada_variation_name'])) {
        $productLogName .= ' - ' . $item['lazada_variation_name'];
    }

    $logStmt = $conn->prepare("INSERT INTO lazada_sync_logs (platform_name, event_type, lazada_item_id, lazada_sku_id, product_name, sku, old_value, new_value, status, source, created_by, created_at) VALUES (?, 'allocation_update', ?, ?, ?, ?, ?, ?, 'success', 'Update Allocation', ?, NOW())");
    $logStmt->execute([
        $platform, 
        $item['lazada_item_id'], 
        $item['lazada_sku_id'], 
        $productLogName, 
        $item['lazada_seller_sku'], 
        (string)$oldStock, 
        "$newStock ($diffText $label)", 
        $_SESSION['user_id'] ?? null
    ]);

    echo json_encode(['success' => true, 'message' => 'Allocation rules updated and pushed to Lazada.']);

} catch (Exception $e) {
    try {
        if (isset($conn) && $conn->inTransaction()) {
            $conn->rollBack();
        }
    } catch (Exception $rollbackEx) {}
    
    // Log error
    try {
        if (isset($conn)) {
            $productLogName = isset($item) ? $item['lazada_product_name'] : null;
            if (isset($item) && !empty($item['lazada_variation_name'])) {
                $productLogName .= ' - ' . $item['lazada_variation_name'];
            }
            $logStmt = $conn->prepare("INSERT INTO lazada_error_logs (platform_name, error_type, error_message, related_lazada_item_id) VALUES (?, ?, ?, ?)");
            $logStmt->execute([
                $platform,
                'sync_error',
                $e->getMessage(),
                isset($item) ? $item['lazada_item_id'] : null
            ]);
        }
    } catch (Exception $ex) {}
    
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

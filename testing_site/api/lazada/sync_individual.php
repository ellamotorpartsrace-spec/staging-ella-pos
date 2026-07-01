<?php
/**
 * api/lazada/sync_individual.php
 * Syncs a single mapped product's stock from POS to Lazada.
 * Multi-account aware.
 */
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../classes/LazadaAPI.php';

header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$platform = $_SESSION['lazada_active_platform'] ?? 'lazada_main';
$mapping_id = $_POST['mapping_id'] ?? null;

if (!$mapping_id) {
    echo json_encode(['success' => false, 'error' => 'No mapping ID provided.']);
    exit;
}

try {
    $db = new Database();
    $conn = $db->getConnection();

    // 1. Get mapping and ensure it belongs to active platform
    $stmt = $conn->prepare("
        SELECT m.*, c.app_key, c.app_secret, c.country_code, c.environment, c.access_token 
        FROM lazada_product_mappings m
        JOIN lazada_config c ON m.platform_name = c.platform_name
        WHERE m.id = ? AND m.platform_name = ?
    ");
    $stmt->execute([$mapping_id, $platform]);
    $mapping = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$mapping) {
        echo json_encode(['success' => false, 'error' => 'Mapping not found or does not belong to the active platform.']);
        exit;
    }

    if (empty($mapping['access_token'])) {
        echo json_encode(['success' => false, 'error' => 'Platform is not authorized. Please configure API tokens in settings.']);
        exit;
    }

    if (empty($mapping['pos_product_id'])) {
        echo json_encode(['success' => false, 'error' => 'This Lazada listing is not mapped to a POS product.']);
        exit;
    }

    // 2. Fetch POS stock
    // Since Lazada listings don't have SKUs, the mapping is directly to the product.
    $stockStmt = $conn->prepare("
        SELECT SUM(stock) as total_stock 
        FROM product_units 
        WHERE product_id = ? AND unit_id = ?
    ");
    $stockStmt->execute([$mapping['pos_product_id'], $mapping['pos_unit_id']]);
    $posStock = (int)$stockStmt->fetchColumn();

    // Calculate allocation
    $allocationRatio = (float)$mapping['stock_allocation_ratio'];
    $safetyFloor = (int)$mapping['safety_floor'];

    $allocatedStock = floor($posStock * ($allocationRatio / 100));
    $finalStock = max(0, $allocatedStock - $safetyFloor);

    // 3. Push to Lazada API
    $api = new LazadaAPI(
        $mapping['app_key'], 
        $mapping['app_secret'], 
        $mapping['country_code'], 
        $mapping['environment'] === 'sandbox'
    );

    $payload = [
        'product' => [
            'ItemId' => $mapping['lazada_item_id'],
            'Skus' => [
                'Sku' => [
                    [
                        'SkuId' => $mapping['lazada_sku_id'],
                        // Lazada requires SellerSku or SkuId for stock update.
                        'SellerSku' => $mapping['lazada_seller_sku'],
                        'quantity' => $finalStock
                    ]
                ]
            ]
        ]
    ];

    $xmlPayload = "<?xml version=\"1.0\" encoding=\"utf-8\" ?>
<Request>
    <Product>
        <Skus>
            <Sku>
                <SkuId>{$mapping['lazada_sku_id']}</SkuId>
                <Quantity>{$finalStock}</Quantity>
            </Sku>
        </Skus>
    </Product>
</Request>";

    $api->setAccessToken($mapping['access_token']);
    $response = $api->call('/product/price_quantity/update', [
        'payload' => $xmlPayload
    ], 'POST');

    if (!isset($response['code']) || $response['code'] !== '0') {
        $err = $response['message'] ?? 'Unknown API Error';
        
        // Log Error
        $logErr = $conn->prepare("INSERT INTO lazada_error_logs (platform_name, error_type, error_message, related_lazada_item_id) VALUES (?, ?, ?, ?)");
        $logErr->execute([$platform, 'sync_error', "Sync failed: $err", $mapping['lazada_item_id']]);

        echo json_encode(['success' => false, 'error' => "API Error: $err"]);
        exit;
    }

    // 4. Update local record
    $upd = $conn->prepare("
        UPDATE lazada_product_mappings 
        SET lazada_stock = ?, last_synced_at = NOW() 
        WHERE id = ?
    ");
    $upd->execute([$finalStock, $mapping_id]);

    // Log Sync Event
    $logSync = $conn->prepare("INSERT INTO lazada_sync_logs (platform_name, sync_type, pos_product_id, lazada_item_id, stock_pushed, status) VALUES (?, ?, ?, ?, ?, 'success')");
    $logSync->execute([$platform, 'manual', $mapping['pos_product_id'], $mapping['lazada_item_id'], $finalStock]);

    echo json_encode([
        'success' => true, 
        'message' => 'Stock synced successfully.', 
        'pushed_stock' => $finalStock
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

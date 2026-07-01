<?php
/**
 * api/lazada/fetch_mapped_live_stocks.php
 * Fetches live stock/price from Lazada on demand for selected mapping IDs and updates the DB.
 */
header("Content-Type: application/json");
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../classes/LazadaAPI.php';

requireLogin();

$input = json_decode(file_get_contents('php://input'), true);
$ids = $input['ids'] ?? [];
$skipLog = !empty($input['skip_log']);

if (empty($ids) || !is_array($ids)) {
    echo json_encode(['success' => false, 'error' => 'No mapping IDs provided']);
    exit;
}

$platform = $_SESSION['lazada_active_platform'] ?? 'lazada_main';

try {
    $db = new Database();
    $conn = $db->getConnection();

    $stmtConfig = $conn->prepare("SELECT * FROM lazada_config WHERE platform_name = ? LIMIT 1");
    $stmtConfig->execute([$platform]);
    $config = $stmtConfig->fetch(PDO::FETCH_ASSOC);

    if (!$config || empty($config['access_token'])) {
        echo json_encode(['success' => false, 'error' => 'Lazada platform is not authorized.']);
        exit;
    }

    $api = new LazadaAPI(
        $config['app_key'], 
        $config['app_secret'], 
        $config['country_code'], 
        $config['environment'] === 'sandbox'
    );
    $api->setAccessToken($config['access_token']);

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $conn->prepare("SELECT * FROM lazada_product_mappings WHERE id IN ($placeholders) AND platform_name = ?");
    $params = $ids;
    $params[] = $platform;
    $stmt->execute($params);
    $mappings = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($mappings)) {
        echo json_encode(['success' => true, 'updated' => [], 'message' => 'No matching mappings found in the database.']);
        exit;
    }

    // Group mappings by lazada_item_id so we only fetch each product once
    $itemsToFetch = [];
    $mappingBySkuId = [];
    foreach ($mappings as $map) {
        $itemsToFetch[$map['lazada_item_id']] = true;
        $mappingBySkuId[$map['lazada_sku_id']] = $map;
    }

    $updated = [];
    $errors = [];

    foreach (array_keys($itemsToFetch) as $itemId) {
        try {
            $response = $api->call('/product/item/get', ['item_id' => $itemId], 'GET');

            if (!isset($response['code']) || $response['code'] !== '0') {
                throw new Exception($response['message'] ?? 'Unknown API Error');
            }

            $product = $response['data'];
            $skus = $product['skus'] ?? [];

            foreach ($skus as $sku) {
                $skuId = $sku['SkuId'];
                
                if (isset($mappingBySkuId[$skuId])) {
                    $map = $mappingBySkuId[$skuId];
                    $mapId = $map['id'];
                    $oldStock = (int)$map['lazada_stock'];
                    $liveStock = (int)$sku['quantity'];
                    $livePrice = (float)$sku['price'];

                    $upd = $conn->prepare("UPDATE lazada_product_mappings SET lazada_stock = ?, lazada_price = ?, last_synced_at = NOW() WHERE id = ?");
                    $upd->execute([$liveStock, $livePrice, $mapId]);

                    $changed = ($oldStock !== $liveStock);

                    $updated[] = [
                        'id' => $mapId,
                        'changed' => $changed,
                        'lazada_stock' => $liveStock,
                        'lazada_price' => $livePrice
                    ];

                    if ($changed && !$skipLog) {
                        $diff = $liveStock - $oldStock;
                        $diffText = ($diff >= 0) ? "+$diff" : (string)$diff;
                        $label = ($diff > 0) ? "Added" : ($diff < 0 ? "Deducted" : "Updated");

                        $logStmt = $conn->prepare("
                            INSERT INTO lazada_sync_logs (event_type, lazada_item_id, lazada_sku_id, product_name, sku, old_value, new_value, status, source, created_by, created_at)
                            VALUES ('stock_update', ?, ?, ?, ?, ?, ?, 'success', 'Live Sync Manual Fetch', ?, NOW())
                        ");
                        $logStmt->execute([
                            $map['lazada_item_id'],
                            $map['lazada_sku_id'],
                            $map['lazada_product_name'],
                            $map['lazada_seller_sku'],
                            (string)$oldStock,
                            "$liveStock ($diffText $label)",
                            $_SESSION['user_id'] ?? null
                        ]);
                    }
                }
            }
        } catch (Exception $ex) {
            $errors[] = [
                'item_id' => $itemId,
                'error' => $ex->getMessage()
            ];
        }
    }

    echo json_encode([
        'success' => true,
        'updated' => $updated,
        'errors' => $errors,
        'message' => count($updated) . ' item(s) synced, ' . count($errors) . ' failed.'
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

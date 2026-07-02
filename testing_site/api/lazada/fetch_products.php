<?php
/**
 * api/lazada/fetch_products.php
 * Fetches products from Lazada and updates local mapping table.
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

try {
    $db = new Database();
    $conn = $db->getConnection();

    // 1. Get platform config
    $stmt = $conn->prepare("SELECT * FROM lazada_config WHERE platform_name = ? LIMIT 1");
    $stmt->execute([$platform]);
    $config = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$config || empty($config['access_token'])) {
        echo json_encode(['success' => false, 'error' => "Access token missing for $platform. Please authorize first."]);
        exit;
    }

    $api = new LazadaAPI(
        $config['app_key'], 
        $config['app_secret'], 
        $config['country_code'], 
        $config['environment'] === 'sandbox'
    );

    // 2. Fetch products from Lazada API
    // We will use /products/get endpoint.
    // Handling pagination for a large number of products
    $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
    $limit = 50;

    $api->setAccessToken($config['access_token']);

    $mode = isset($_GET['mode']) ? $_GET['mode'] : 'full';

    $params = [
        'offset' => $offset,
        'limit' => $limit
    ];

    if (in_array($mode, ['quick', 'stock', 'price'])) {
        // Look back 7 days like Shopee
        $fromTime = time() - 604800; // 7 days ago
        $params['update_after'] = date('Y-m-d\TH:i:sP', $fromTime);
    }
    $response = $api->call('/products/get', $params, 'GET');

    // Debug log to catch exactly what Lazada is returning behind the scenes
    file_put_contents(__DIR__ . '/lazada_sync_debug.log', date('Y-m-d H:i:s') . " | Offset $offset | Response: " . json_encode($response) . "\n", FILE_APPEND);

    if (!isset($response['code']) || $response['code'] !== '0' || !isset($response['data']['products'])) {
        $err = $response['message'] ?? 'Unknown API Error';
        echo json_encode(['success' => false, 'error' => "Failed to fetch products: $err"]);
        exit;
    }

    $products = $response['data']['products'];
    $hasMore = count($products) === $limit;
    $nextOffset = $offset + $limit;
    $allSkus = [];

    if (!empty($products)) {
        foreach ($products as $prod) {
            $itemId = $prod['item_id'];
            $itemName = $prod['attributes']['name'] ?? 'Unknown Product';
            $image = isset($prod['images']) && is_array($prod['images']) ? ($prod['images'][0] ?? null) : null;
            
            // Lazada API sometimes returns 'skus' or 'sku' or none
            $skus = $prod['skus'] ?? $prod['sku'] ?? [];
            
            if (!empty($skus) && is_array($skus)) {
                foreach ($skus as $sku) {
                        $varName = $sku['Variation'] ?? $sku['color_family'] ?? $sku['size'] ?? $sku['name'] ?? null;
                        
                        // Fallback 1: check saleProp object which Lazada often uses for custom variations
                        if (!$varName && !empty($sku['saleProp'])) {
                            $saleProps = is_string($sku['saleProp']) ? json_decode($sku['saleProp'], true) : $sku['saleProp'];
                            if (is_array($saleProps) && !empty($saleProps)) {
                                $varName = reset($saleProps);
                            }
                        }
                        
                        // Fallback 2: grab the first non-standard string attribute
                        if (!$varName) {
                            $standardKeys = ['Status', 'quantity', 'Available', 'price', 'SellerSku', 'ShopSku', 'SkuId', 'package_width', 'package_height', 'package_length', 'package_weight', 'special_price', 'special_from_date', 'special_from_time', 'special_to_date', 'special_to_time', 'Images', 'tax_class', 'barcode', 'color_thumbnail', 'fbl_warehouse', 'multiWarehouseInventories', 'saleProp', 'url', 'Url', 'name'];
                            foreach ($sku as $k => $v) {
                                if (!in_array($k, $standardKeys) && is_string($v) && !empty($v) && !is_numeric($v)) {
                                    $varName = $v;
                                    break;
                                }
                            }
                        }

                        $allSkus[] = [
                            'item_id' => $itemId,
                            'sku_id' => $sku['SkuId'] ?? $itemId,
                            'product_name' => $itemName,
                            'variation_name' => $varName,
                            'seller_sku' => $sku['SellerSku'] ?? null,
                        'stock' => $sku['quantity'] ?? $sku['Available'] ?? 0,
                        'price' => $sku['price'] ?? 0,
                        'image_url' => (isset($sku['Images']) && is_array($sku['Images'])) ? ($sku['Images'][0] ?? $image) : $image,
                        'status' => $sku['Status'] ?? 'active'
                    ];
                }
            } else {
                // Product exists but has no variations listed in the API
                $allSkus[] = [
                    'item_id' => $itemId,
                    'sku_id' => $itemId,
                    'product_name' => $itemName,
                    'variation_name' => null,
                    'seller_sku' => null,
                    'stock' => 0,
                    'price' => 0,
                    'image_url' => $image,
                    'status' => 'active'
                ];
            }
        }
    }

    if (empty($allSkus) && $offset === 0) {
        echo json_encode(['success' => true, 'message' => 'No active products found on Lazada.', 'has_more' => false]);
        exit;
    }

    // 3. Upsert into database
    $conn->beginTransaction();

    // Mark all existing as inactive first so we can sync deleted/hidden items
    $upd = $conn->prepare("UPDATE lazada_product_mappings SET sync_status = 'inactive' WHERE platform_name = ?");
    $upd->execute([$platform]);

    $updateClause = "
            lazada_product_name = VALUES(lazada_product_name),
            lazada_variation_name = VALUES(lazada_variation_name),
            lazada_seller_sku = VALUES(lazada_seller_sku),
            lazada_stock = VALUES(lazada_stock),
            lazada_price = VALUES(lazada_price),
            lazada_image_url = VALUES(lazada_image_url),
            sync_status = 'active',
            last_synced_at = NOW()
    ";

    if ($mode === 'stock') {
        $updateClause = "
            lazada_stock = VALUES(lazada_stock),
            sync_status = 'active',
            last_synced_at = NOW()
        ";
    } elseif ($mode === 'price') {
        $updateClause = "
            lazada_price = VALUES(lazada_price),
            sync_status = 'active',
            last_synced_at = NOW()
        ";
    }

    $insertStmt = $conn->prepare("
        INSERT INTO lazada_product_mappings 
        (platform_name, lazada_item_id, lazada_sku_id, lazada_product_name, lazada_variation_name, 
         lazada_seller_sku, lazada_stock, lazada_price, lazada_image_url, sync_status, last_synced_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', NOW())
        ON DUPLICATE KEY UPDATE 
            $updateClause
    ");

    $newCount = 0;
    $updateCount = 0;

    foreach ($allSkus as $sku) {
        $insertStmt->execute([
            $platform,
            $sku['item_id'],
            $sku['sku_id'],
            $sku['product_name'],
            $sku['variation_name'],
            $sku['seller_sku'],
            $sku['stock'],
            $sku['price'],
            $sku['image_url']
        ]);
        if ($insertStmt->rowCount() === 1) {
            $newCount++;
        } else {
            $updateCount++;
        }
    }

    $conn->commit();

    echo json_encode([
        'success' => true,
        'message' => "Successfully fetched " . count($allSkus) . " SKUs on this page.",
        'stats' => [
            'total' => count($allSkus),
            'new' => $newCount,
            'updated' => $updateCount
        ],
        'has_more' => $hasMore,
        'next_offset' => $nextOffset
    ]);

} catch (Exception $e) {
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

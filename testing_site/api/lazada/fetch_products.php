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
    $allSkus = [];
    $offset = 0;
    $limit = 50;
    $hasMore = true;

    while ($hasMore) {
        $response = $api->call('/products/get', [
            'filter' => 'live',
            'offset' => $offset,
            'limit' => $limit
        ], 'GET', $config['access_token']);

        if (!isset($response['code']) || $response['code'] !== '0' || !isset($response['data']['products'])) {
            $err = $response['message'] ?? 'Unknown API Error';
            echo json_encode(['success' => false, 'error' => "Failed to fetch products: $err"]);
            exit;
        }

        $products = $response['data']['products'];
        if (empty($products)) {
            $hasMore = false;
            break;
        }

        foreach ($products as $prod) {
            $itemId = $prod['item_id'];
            $itemName = $prod['attributes']['name'] ?? 'Unknown Product';
            $image = $prod['images'][0] ?? null;

            if (isset($prod['skus']) && is_array($prod['skus'])) {
                foreach ($prod['skus'] as $sku) {
                    $allSkus[] = [
                        'item_id' => $itemId,
                        'sku_id' => $sku['SkuId'],
                        'product_name' => $itemName,
                        'variation_name' => $sku['Variation'] ?? null,
                        'seller_sku' => $sku['SellerSku'] ?? null,
                        'stock' => $sku['quantity'] ?? 0,
                        'price' => $sku['price'] ?? 0,
                        'image_url' => $sku['Images'][0] ?? $image,
                        'status' => $sku['Status'] ?? 'active'
                    ];
                }
            }
        }

        $offset += $limit;
        // If we received fewer items than the limit, we're at the end.
        if (count($products) < $limit) {
            $hasMore = false;
        }
    }

    if (empty($allSkus)) {
        echo json_encode(['success' => true, 'message' => 'No active products found on Lazada.']);
        exit;
    }

    // 3. Upsert into database
    $conn->beginTransaction();

    // Mark all existing as inactive first so we can sync deleted/hidden items
    $upd = $conn->prepare("UPDATE lazada_product_mappings SET sync_status = 'inactive' WHERE platform_name = ?");
    $upd->execute([$platform]);

    $insertStmt = $conn->prepare("
        INSERT INTO lazada_product_mappings 
        (platform_name, lazada_item_id, lazada_sku_id, lazada_product_name, lazada_variation_name, 
         lazada_seller_sku, lazada_stock, lazada_price, lazada_image_url, sync_status, last_synced_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', NOW())
        ON DUPLICATE KEY UPDATE 
            lazada_product_name = VALUES(lazada_product_name),
            lazada_variation_name = VALUES(lazada_variation_name),
            lazada_seller_sku = VALUES(lazada_seller_sku),
            lazada_stock = VALUES(lazada_stock),
            lazada_price = VALUES(lazada_price),
            lazada_image_url = VALUES(lazada_image_url),
            sync_status = 'active',
            last_synced_at = NOW()
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
        'message' => "Successfully fetched " . count($allSkus) . " SKUs.",
        'stats' => [
            'total' => count($allSkus),
            'new' => $newCount,
            'updated' => $updateCount
        ]
    ]);

} catch (Exception $e) {
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

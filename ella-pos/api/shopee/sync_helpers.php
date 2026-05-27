<?php
/**
 * api/shopee/sync_helpers.php — Centralized propagation functions Shopee -> POS
 */

// Dynamically ensure shopee_alerts table exists (fixes missing table error on Hostinger)
try {
    global $conn;
    if (isset($conn) && $conn instanceof PDO) {
        $conn->exec("
            CREATE TABLE IF NOT EXISTS shopee_alerts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                mapping_id INT NULL,
                message TEXT NOT NULL,
                alert_type VARCHAR(50) DEFAULT 'warning',
                is_read TINYINT(1) DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");
    }
} catch (Exception $e) {
    // Ignore
}

if (!function_exists('propagateStockToPos')) {
    /**
     * Propagate stock updates from Shopee to POS online inventory (store_id = 2)
     */
    function propagateStockToPos($conn, $posProductId, $shopeeStock, $productName, $sku, $userId, $mapId = null) {
        if (empty($posProductId)) return;

        try {
            $shopeeStock = (int)$shopeeStock;

            // 0. Update the mapping's shopee_stock FIRST so the SUM below is accurate.
            // Only update by specific mapId — never update by pos_product_id, which could
            // corrupt other mappings still legitimately linked to that same POS product.
            if (!empty($mapId)) {
                $conn->prepare("UPDATE shopee_product_mappings SET shopee_stock = ?, updated_at = NOW() WHERE id = ?")
                    ->execute([$shopeeStock, $mapId]);
            }
            // If mapId is null (e.g. cleanup of old product after a re-link), skip cache update.
            // The mapping row has already been updated to the new pos_product_id, so
            // the SUM query below will naturally exclude it from the old product's total.

            // Resolve the POS product's SKU to use for SKU-based stock aggregation
            $skuStmt = $conn->prepare("SELECT sku FROM product_variations WHERE variation_id = ?");
            $skuStmt->execute([$posProductId]);
            $posSku = $skuStmt->fetchColumn();
            
            if (!empty(trim((string)$posSku))) {
                $stmtSum = $conn->prepare("
                    SELECT COALESCE(SUM(m.shopee_stock * COALESCE(u.multiplier, 1)), 0) 
                    FROM shopee_product_mappings m
                    LEFT JOIN product_units u ON m.pos_unit_id = u.id
                    WHERE m.matched_pos_sku = ? AND m.mapping_status IN ('auto','manual')
                ");
                $stmtSum->execute([$posSku]);
            } else {
                $stmtSum = $conn->prepare("
                    SELECT COALESCE(SUM(m.shopee_stock * COALESCE(u.multiplier, 1)), 0) 
                    FROM shopee_product_mappings m
                    LEFT JOIN product_units u ON m.pos_unit_id = u.id
                    WHERE m.pos_product_id = ? AND (m.matched_pos_sku IS NULL OR m.matched_pos_sku = '') AND m.mapping_status IN ('auto','manual')
                ");
                $stmtSum->execute([$posProductId]);
            }
            $totalShopeeStock = (int)$stmtSum->fetchColumn();

            // 1. Get previous stock in POS online store (store_id = 2)
            $stmt = $conn->prepare("SELECT quantity FROM inventory WHERE variation_id = ? AND store_id = 2");
            $stmt->execute([$posProductId]);
            $prevStockRow = $stmt->fetch();
            $prevStock = $prevStockRow !== false ? (int)$prevStockRow['quantity'] : 0;

            if ($prevStockRow === false || $prevStock !== $totalShopeeStock) {
                // USER REQUEST:
                // 1. Update POS Online Shop (store_id = 2) to reflect Shopee stock.
                // 2. Deduct from Physical Store (store_id = 1) so total POS stock stays exactly the same.
                // 3. Do NOT log any stock movements (no 'shopee_sale' or 'allocation_adjustment' from background syncs).

                $updStore = $conn->prepare("
                    INSERT INTO inventory (variation_id, store_id, quantity) 
                    VALUES (?, 2, ?)
                    ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)
                ");
                $updStore->execute([$posProductId, $totalShopeeStock]);

                // Fetch physical store stock (store_id = 1) to calculate total and deduct
                $physStmt = $conn->prepare("SELECT quantity FROM inventory WHERE variation_id = ? AND store_id = 1");
                $physStmt->execute([$posProductId]);
                $physQty = (int)($physStmt->fetchColumn() ?? 0);
                
                $totalQty = $physQty + $prevStock;
                
                // Deduct from physical store to maintain total POS stock
                $newPhysQty = $totalQty - $totalShopeeStock;
                $updPhys = $conn->prepare("
                    INSERT INTO inventory (variation_id, store_id, quantity) 
                    VALUES (?, 1, ?)
                    ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)
                ");
                $updPhys->execute([$posProductId, $newPhysQty]);

                $newRatio = $totalQty > 0 ? (int)round(($totalShopeeStock / $totalQty) * 100) : 100;

                // Update the stock allocation ratio in mappings
                if (!empty($mapId)) {
                    $conn->prepare("UPDATE shopee_product_mappings SET stock_allocation_ratio = ? WHERE id = ?")
                        ->execute([$newRatio, $mapId]);
                } else {
                    $conn->prepare("UPDATE shopee_product_mappings SET stock_allocation_ratio = ? WHERE pos_product_id = ?")
                        ->execute([$newRatio, $posProductId]);
                }
            }
        } catch (Exception $e) {
            // Suppress error to avoid interrupting batch sync
        }
    }
}


if (!function_exists('fetchLiveShopeeStockAndPrice')) {
    /**
     * Fetch the live stock and price for a specific Shopee item (and model variation if applicable)
     */
    function fetchLiveShopeeStockAndPrice($conn, $itemId, $modelId = null) {
        require_once __DIR__ . '/../../classes/ShopeeAPI.php';
        global $shopeeApiCache;
        if (!is_array($shopeeApiCache)) $shopeeApiCache = [];

        $cfgStmt = $conn->prepare("SELECT * FROM shopee_config WHERE is_active = 1 LIMIT 1");
        $cfgStmt->execute();
        $config = $cfgStmt->fetch(PDO::FETCH_ASSOC);

        if (!$config || empty($config['access_token'])) {
            throw new Exception("Shopee not authorized or missing tokens.");
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
            }
        }

        $cacheKey = (string)$itemId;

        if (!isset($shopeeApiCache[$cacheKey])) {
            $shopeeApiCache[$cacheKey] = ['models' => [], 'base' => null];

            // 1. Always attempt to fetch variations first (Shopee handles item_id regardless of has_model)
            $modelResult = $shopee->get('/api/v2/product/get_model_list', [
                'item_id' => (int)$itemId,
            ], $accessToken, $shopId);

            if (isset($modelResult['response']['model']) && !empty($modelResult['response']['model'])) {
                $shopeeApiCache[$cacheKey]['models'] = $modelResult['response']['model'];
            } else {
                // 2. Fallback to base item info if it's a simple product with no variations
                $infoResult = $shopee->get('/api/v2/product/get_item_base_info', [
                    'item_id_list' => (string)$itemId,
                ], $accessToken, $shopId);

                if (isset($infoResult['response']['item_list']) && !empty($infoResult['response']['item_list'])) {
                    $shopeeApiCache[$cacheKey]['base'] = $infoResult['response']['item_list'][0];
                } else {
                    throw new Exception("Item ID {$itemId} not found or inaccessible on Shopee");
                }
            }
        }

        $liveStock = 0;
        $livePrice = 0.0;

        if (!empty($modelId)) {
            $models = $shopeeApiCache[$cacheKey]['models'];
            $found = false;
            foreach ($models as $model) {
                if ((string)$model['model_id'] === (string)$modelId) {
                    if (isset($model['stock_info_v2']['summary_info']['total_available_stock'])) {
                        $liveStock = (int) $model['stock_info_v2']['summary_info']['total_available_stock'];
                    } elseif (isset($model['stock_info'][0]['current_stock'])) {
                        $liveStock = (int) $model['stock_info'][0]['current_stock'];
                    }

                    if (isset($model['price_info'][0]['current_price'])) {
                        $livePrice = (float) $model['price_info'][0]['current_price'];
                    }
                    $found = true;
                    break;
                }
            }

            if (!$found) {
                throw new Exception("Model ID {$modelId} not found on Shopee for Item ID {$itemId}");
            }
        } else {
            $item = $shopeeApiCache[$cacheKey]['base'];
            if (empty($item)) {
                throw new Exception("Item ID {$itemId} not found on Shopee");
            }
            if (isset($item['stock_info_v2']['summary_info']['total_available_stock'])) {
                $liveStock = (int) $item['stock_info_v2']['summary_info']['total_available_stock'];
            } elseif (isset($item['stock_info'][0]['current_stock'])) {
                $liveStock = (int) $item['stock_info'][0]['current_stock'];
            }

            $livePrice = isset($item['price_info'][0]['current_price'])
                ? (float) $item['price_info'][0]['current_price'] : 0.0;
        }

        return ['stock' => $liveStock, 'price' => $livePrice];
    }
}

if (!function_exists('prewarmShopeeApiCache')) {
    function prewarmShopeeApiCache($conn, $itemIds) {
        require_once __DIR__ . '/../../classes/ShopeeAPI.php';
        global $shopeeApiCache;
        if (!is_array($shopeeApiCache)) $shopeeApiCache = [];

        if (empty($itemIds)) return;

        $cfgStmt = $conn->prepare("SELECT * FROM shopee_config WHERE is_active = 1 LIMIT 1");
        $cfgStmt->execute();
        $config = $cfgStmt->fetch(PDO::FETCH_ASSOC);

        if (!$config || empty($config['access_token'])) return;

        $isTest = $config['environment'] === 'test';
        $shopee = new ShopeeAPI($config['partner_id'], $config['partner_key'], $isTest);
        $accessToken = $config['access_token'];
        $shopId = $config['shop_id'];

        if (strtotime($config['token_expires_at']) - time() < 600) {
            $refreshResult = $shopee->refreshToken($config['refresh_token'], $shopId);
            if (isset($refreshResult['access_token'])) {
                $accessToken = $refreshResult['access_token'];
                $expiresAt = date('Y-m-d H:i:s', time() + ($refreshResult['expire_in'] ?? 14400));
                $conn->prepare("UPDATE shopee_config SET access_token=?, refresh_token=?, token_expires_at=?, updated_at=NOW() WHERE is_active=1")
                     ->execute([$accessToken, $refreshResult['refresh_token'], $expiresAt]);
            }
        }

        // Filter out item IDs we already have cached
        $neededItems = [];
        foreach ($itemIds as $iid) {
            if (!isset($shopeeApiCache[(string)$iid])) {
                $neededItems[] = $iid;
            }
        }
        if (empty($neededItems)) return;

        // Fetch all model_lists concurrently (chunked to 50 max to respect rate limits)
        $queryParamsList = [];
        foreach ($neededItems as $iid) {
            $queryParamsList[] = ['item_id' => (int)$iid];
        }

        $results = [];
        $queryChunks = array_chunk($queryParamsList, 50);
        foreach ($queryChunks as $qChunk) {
            $chunkResults = $shopee->getMulti('/api/v2/product/get_model_list', $qChunk, $accessToken, $shopId);
            $results = array_merge($results, $chunkResults);
        }

        $missingBaseItemIds = [];

        foreach ($neededItems as $index => $iid) {
            $cacheKey = (string)$iid;
            $shopeeApiCache[$cacheKey] = ['models' => [], 'base' => null];
            $modelResult = $results[$index] ?? [];

            if (isset($modelResult['response']['model']) && !empty($modelResult['response']['model'])) {
                $shopeeApiCache[$cacheKey]['models'] = $modelResult['response']['model'];
            } else {
                // If it has no models, we will need to fetch base info
                $missingBaseItemIds[] = $iid;
            }
        }

        // For items that don't have models (simple items), we can batch fetch their base info
        // /api/v2/product/get_item_base_info accepts item_id_list up to 50 items
        if (!empty($missingBaseItemIds)) {
            $chunks = array_chunk($missingBaseItemIds, 50);
            $baseQueries = [];
            foreach ($chunks as $chunk) {
                $baseQueries[] = ['item_id_list' => implode(',', $chunk)];
            }
            
            $baseResults = $shopee->getMulti('/api/v2/product/get_item_base_info', $baseQueries, $accessToken, $shopId);
            
            foreach ($baseResults as $infoResult) {
                if (isset($infoResult['response']['item_list']) && !empty($infoResult['response']['item_list'])) {
                    foreach ($infoResult['response']['item_list'] as $item) {
                        $cacheKey = (string)$item['item_id'];
                        if (isset($shopeeApiCache[$cacheKey])) {
                            $shopeeApiCache[$cacheKey]['base'] = $item;
                        }
                    }
                }
            }
        }
    }
}

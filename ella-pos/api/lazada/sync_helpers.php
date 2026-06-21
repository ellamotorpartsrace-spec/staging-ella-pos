<?php
/**
 * api/lazada/sync_helpers.php — Centralized propagation functions Lazada -> POS
 */

// Dynamically ensure lazada_alerts table exists (fixes missing table error on Hostinger)
try {
    global $conn;
    if (isset($conn) && $conn instanceof PDO) {
        $conn->exec("
            CREATE TABLE IF NOT EXISTS lazada_alerts (
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
     * Propagate stock updates from Lazada to POS online inventory (store_id = 2)
     */
    function propagateStockToPos($conn, $posProductId, $lazadaStock, $productName, $sku, $userId, $mapId = null) {
        if (empty($posProductId)) return;

        try {
            $lazadaStock = (int)$lazadaStock;

            // 0. Update the mapping's lazada_stock FIRST so the SUM below is accurate.
            // Only update by specific mapId — never update by pos_product_id, which could
            // corrupt other mappings still legitimately linked to that same POS product.
            if (!empty($mapId)) {
                $conn->prepare("UPDATE lazada_product_mappings SET lazada_stock = ?, updated_at = NOW() WHERE id = ?")
                    ->execute([$lazadaStock, $mapId]);
            }
            // If mapId is null (e.g. cleanup of old product after a re-link), skip cache update.
            // The mapping row has already been updated to the new pos_product_id, so
            // the SUM query below will naturally exclude it from the old product's total.

            // Resolve the POS product's SKU to use for SKU-based stock aggregation
            $skuStmt = $conn->prepare("SELECT sku FROM product_variations WHERE variation_id = ?");
            $skuStmt->execute([$posProductId]);
            $posSku = $skuStmt->fetchColumn();
            
            if (empty($posSku)) {
                $posSku = '';
            }
            
            $stmtSum = $conn->prepare("
                SELECT COALESCE(SUM(m.lazada_stock * COALESCE(u.multiplier, 1)), 0) 
                FROM lazada_product_mappings m
                LEFT JOIN product_units u ON m.pos_unit_id = u.id
                WHERE (m.pos_product_id = ? OR (m.matched_pos_sku = ? AND m.matched_pos_sku NOT IN ('', '-', 'N/A', 'NA', 'none', 'null')))
                  AND m.mapping_status IN ('auto','manual')
            ");
            $stmtSum->execute([$posProductId, $posSku]);
            $totalLazadaStock = (int)$stmtSum->fetchColumn();

            // 1. Get previous stock in POS online store (store_id = 3)
            $stmt = $conn->prepare("SELECT quantity FROM inventory WHERE variation_id = ? AND store_id = 3");
            $stmt->execute([$posProductId]);
            $prevStockRow = $stmt->fetch();
            $prevStock = $prevStockRow !== false ? (float) $prevStockRow['quantity'] : 0;

            if ($prevStockRow === false || $prevStock !== $totalLazadaStock) {
                // USER REQUEST:
                // 1. Update POS Online Shop (store_id = 3) to reflect Lazada stock.
                // 2. Deduct from Physical Store (store_id = 1) so total POS stock stays exactly the same.
                // 3. Do NOT log any stock movements (no 'lazada_sale' or 'allocation_adjustment' from background syncs).

                $updStore = $conn->prepare("
                    INSERT INTO inventory (variation_id, store_id, quantity) 
                    VALUES (?, 3, ?)
                    ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)
                ");
                $updStore->execute([$posProductId, $totalLazadaStock]);

                // Fetch physical store stock (store_id = 1) to calculate total and deduct
                $physStmt = $conn->prepare("SELECT quantity FROM inventory WHERE variation_id = ? AND store_id = 1");
                $physStmt->execute([$posProductId]);
                $physQty = (int)($physStmt->fetchColumn() ?? 0);
                
                $totalQty = $physQty + $prevStock;
                
                // Deduct from physical store to maintain total POS stock
                $newPhysQty = $totalQty - $totalLazadaStock;
                $updPhys = $conn->prepare("
                    INSERT INTO inventory (variation_id, store_id, quantity) 
                    VALUES (?, 1, ?)
                    ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)
                ");
                $updPhys->execute([$posProductId, $newPhysQty]);

                $newRatio = $totalQty > 0 ? (int)round(($totalLazadaStock / $totalQty) * 100) : 100;

                // Update the stock allocation ratio in mappings
                if (!empty($mapId)) {
                    $conn->prepare("UPDATE lazada_product_mappings SET stock_allocation_ratio = ? WHERE id = ?")
                        ->execute([$newRatio, $mapId]);
                } else {
                    $conn->prepare("UPDATE lazada_product_mappings SET stock_allocation_ratio = ? WHERE pos_product_id = ?")
                        ->execute([$newRatio, $posProductId]);
                }
            }
        } catch (Exception $e) {
            // Suppress error to avoid interrupting batch sync
        }
    }
}


if (!function_exists('fetchLiveLazadaStockAndPrice')) {
    /**
     * Fetch the live stock and price for a specific Lazada item (and model variation if applicable)
     */
    function fetchLiveLazadaStockAndPrice($conn, $itemId, $modelId = null) {
        require_once __DIR__ . '/../../classes/LazadaAPI.php';
        global $lazadaApiCache;
        if (!is_array($lazadaApiCache)) $lazadaApiCache = [];

        $cfgStmt = $conn->prepare("SELECT * FROM lazada_config WHERE is_active = 1 LIMIT 1");
        $cfgStmt->execute();
        $config = $cfgStmt->fetch(PDO::FETCH_ASSOC);

        if (!$config || empty($config['access_token'])) {
            throw new Exception("Lazada not authorized or missing tokens.");
        }

        $isTest = $config['environment'] === 'test';
        $lazada = new LazadaAPI($config['partner_id'], $config['partner_key'], $isTest);
        $accessToken = $config['access_token'];
        $shopId      = $config['shop_id'];

        if (strtotime($config['token_expires_at']) - time() < 600) {
            $refreshResult = $lazada->refreshToken($config['refresh_token'], $shopId);
            if (isset($refreshResult['access_token'])) {
                $accessToken = $refreshResult['access_token'];
                $expiresAt = date('Y-m-d H:i:s', time() + ($refreshResult['expire_in'] ?? 14400));
                $conn->prepare("UPDATE lazada_config SET access_token=?, refresh_token=?, token_expires_at=?, updated_at=NOW() WHERE is_active=1")
                     ->execute([$accessToken, $refreshResult['refresh_token'], $expiresAt]);
            }
        }

        $cacheKey = (string)$itemId;

        if (!isset($lazadaApiCache[$cacheKey])) {
            $lazadaApiCache[$cacheKey] = ['models' => [], 'base' => null];

            // 1. Always attempt to fetch variations first (Lazada handles item_id regardless of has_model)
            $modelResult = $lazada->get('/api/v2/product/get_model_list', [
                'item_id' => (int)$itemId,
            ], $accessToken, $shopId);

            if (isset($modelResult['response']['model']) && !empty($modelResult['response']['model'])) {
                $lazadaApiCache[$cacheKey]['models'] = $modelResult['response']['model'];
            } else {
                // 2. Fallback to base item info if it's a simple product with no variations
                $infoResult = $lazada->get('/api/v2/product/get_item_base_info', [
                    'item_id_list' => (string)$itemId,
                ], $accessToken, $shopId);

                if (isset($infoResult['response']['item_list']) && !empty($infoResult['response']['item_list'])) {
                    $lazadaApiCache[$cacheKey]['base'] = $infoResult['response']['item_list'][0];
                } else {
                    throw new Exception("Item ID {$itemId} not found or inaccessible on Lazada");
                }
            }
        }

        $liveStock = 0;
        $livePrice = 0.0;

        if (!empty($modelId)) {
            $models = $lazadaApiCache[$cacheKey]['models'];
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
                throw new Exception("Model ID {$modelId} not found on Lazada for Item ID {$itemId}");
            }
        } else {
            $models = $lazadaApiCache[$cacheKey]['models'] ?? [];
            if (!empty($models)) {
                // Self-healing fallback: mapping has empty modelId, but Lazada has variations.
                // Try to find a variation that matches the mapping's SKU if possible.
                $matchedModel = null;
                
                // Get the mapping's SKU to match
                $skuStmt = $conn->prepare("SELECT COALESCE(lazada_variation_sku, lazada_parent_sku, matched_pos_sku) FROM lazada_product_mappings WHERE lazada_item_id = ? AND (lazada_model_id IS NULL OR lazada_model_id = 0) LIMIT 1");
                $skuStmt->execute([$itemId]);
                $mapSku = trim((string)$skuStmt->fetchColumn());
                
                if ($mapSku !== '') {
                    foreach ($models as $model) {
                        $modelSku = trim((string)($model['model_sku'] ?? ''));
                        if ($modelSku !== '' && strcasecmp($modelSku, $mapSku) === 0) {
                            $matchedModel = $model;
                            break;
                        }
                    }
                }
                
                // If no SKU match, fall back to the first variation
                if (!$matchedModel) {
                    $matchedModel = $models[0];
                }
                
                if (isset($matchedModel['stock_info_v2']['summary_info']['total_available_stock'])) {
                    $liveStock = (int)$matchedModel['stock_info_v2']['summary_info']['total_available_stock'];
                } elseif (isset($matchedModel['stock_info'][0]['current_stock'])) {
                    $liveStock = (int)$matchedModel['stock_info'][0]['current_stock'];
                }

                if (isset($matchedModel['price_info'][0]['current_price'])) {
                    $livePrice = (float)$matchedModel['price_info'][0]['current_price'];
                }
                
                // Smart auto-correction: update the database mapping row with the correct model ID and has_variation!
                $conn->prepare("UPDATE lazada_product_mappings SET lazada_model_id = ?, has_variation = 1, updated_at = NOW() WHERE lazada_item_id = ? AND (lazada_model_id IS NULL OR lazada_model_id = 0)")
                     ->execute([$matchedModel['model_id'], $itemId]);
                     
            } else {
                $item = $lazadaApiCache[$cacheKey]['base'];
                if (empty($item)) {
                    // Try to fetch base item if not prewarmed
                    $infoResult = $lazada->get('/api/v2/product/get_item_base_info', [
                        'item_id_list' => (string)$itemId,
                    ], $accessToken, $shopId);

                    if (isset($infoResult['response']['item_list']) && !empty($infoResult['response']['item_list'])) {
                        $lazadaApiCache[$cacheKey]['base'] = $infoResult['response']['item_list'][0];
                        $item = $lazadaApiCache[$cacheKey]['base'];
                    } else {
                        throw new Exception("Item ID {$itemId} not found on Lazada");
                    }
                }
                
                if (isset($item['stock_info_v2']['summary_info']['total_available_stock'])) {
                    $liveStock = (int)$item['stock_info_v2']['summary_info']['total_available_stock'];
                } elseif (isset($item['stock_info'][0]['current_stock'])) {
                    $liveStock = (int)$item['stock_info'][0]['current_stock'];
                }

                $livePrice = isset($item['price_info'][0]['current_price'])
                    ? (float)$item['price_info'][0]['current_price'] : 0.0;
            }
        }

        return ['stock' => $liveStock, 'price' => $livePrice];
    }
}

if (!function_exists('prewarmLazadaApiCache')) {
    function prewarmLazadaApiCache($conn, $itemIds) {
        require_once __DIR__ . '/../../classes/LazadaAPI.php';
        global $lazadaApiCache;
        if (!is_array($lazadaApiCache)) $lazadaApiCache = [];

        if (empty($itemIds)) return;

        $cfgStmt = $conn->prepare("SELECT * FROM lazada_config WHERE is_active = 1 LIMIT 1");
        $cfgStmt->execute();
        $config = $cfgStmt->fetch(PDO::FETCH_ASSOC);

        if (!$config || empty($config['access_token'])) return;

        $isTest = $config['environment'] === 'test';
        $lazada = new LazadaAPI($config['partner_id'], $config['partner_key'], $isTest);
        $accessToken = $config['access_token'];
        $shopId = $config['shop_id'];

        if (strtotime($config['token_expires_at']) - time() < 600) {
            $refreshResult = $lazada->refreshToken($config['refresh_token'], $shopId);
            if (isset($refreshResult['access_token'])) {
                $accessToken = $refreshResult['access_token'];
                $expiresAt = date('Y-m-d H:i:s', time() + ($refreshResult['expire_in'] ?? 14400));
                $conn->prepare("UPDATE lazada_config SET access_token=?, refresh_token=?, token_expires_at=?, updated_at=NOW() WHERE is_active=1")
                     ->execute([$accessToken, $refreshResult['refresh_token'], $expiresAt]);
            }
        }

        // Filter out item IDs we already have cached
        $neededItems = [];
        foreach ($itemIds as $iid) {
            if (!isset($lazadaApiCache[(string)$iid])) {
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
            $chunkResults = $lazada->getMulti('/api/v2/product/get_model_list', $qChunk, $accessToken, $shopId);
            $results = array_merge($results, $chunkResults);
        }

        $missingBaseItemIds = [];

        foreach ($neededItems as $index => $iid) {
            $cacheKey = (string)$iid;
            $lazadaApiCache[$cacheKey] = ['models' => [], 'base' => null];
            $modelResult = $results[$index] ?? [];

            if (isset($modelResult['response']['model']) && !empty($modelResult['response']['model'])) {
                $lazadaApiCache[$cacheKey]['models'] = $modelResult['response']['model'];
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
            
            $baseResults = $lazada->getMulti('/api/v2/product/get_item_base_info', $baseQueries, $accessToken, $shopId);
            
            foreach ($baseResults as $infoResult) {
                if (isset($infoResult['response']['item_list']) && !empty($infoResult['response']['item_list'])) {
                    foreach ($infoResult['response']['item_list'] as $item) {
                        $cacheKey = (string)$item['item_id'];
                        if (isset($lazadaApiCache[$cacheKey])) {
                            $lazadaApiCache[$cacheKey]['base'] = $item;
                        }
                    }
                }
            }
        }
    }
}

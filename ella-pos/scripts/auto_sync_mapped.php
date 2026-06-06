<?php
/**
 * scripts/auto_sync_mapped.php
 * Automated background script to fetch all mapped Shopee items and sync their live stock and prices to POS.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../api/shopee/sync_helpers.php';

try {
    $db = new Database();
    $conn = $db->getConnection();

    // 1. Load Shopee config ONCE and initialize ShopeeAPI ONCE.
    //    fetchLiveShopeeStockAndPrice() uses $shopeeApiCache + $shopeeSharedConfig globals
    //    so it does NOT re-query shopee_config on every single mapping row.
    $cfgStmt = $conn->prepare("SELECT * FROM shopee_config WHERE is_active = 1 LIMIT 1");
    $cfgStmt->execute();
    $config = $cfgStmt->fetch(PDO::FETCH_ASSOC);

    if (!$config || empty($config['access_token'])) {
        echo "[Auto Sync] No active Shopee config or missing access token. Exiting.\n";
        exit;
    }

    // 2. Auto-Refresh Access Token once if expiring soon
    require_once __DIR__ . '/../classes/ShopeeAPI.php';
    $isTest = $config['environment'] === 'test';
    $shopeeShared = new ShopeeAPI($config['partner_id'], $config['partner_key'], $isTest);
    $accessTokenShared = $config['access_token'];
    $shopIdShared = $config['shop_id'];

    $timeRemaining = strtotime($config['token_expires_at']) - time();
    if ($timeRemaining <= 15 * 60) {
        echo "[Auto Sync] Token expires in less than 15 minutes. Auto-refreshing...\n";
        $result = $shopeeShared->refreshToken($config['refresh_token'], $shopIdShared);
        if (isset($result['access_token'])) {
            $accessTokenShared = $result['access_token'];
            $newRefreshToken = $result['refresh_token'];
            $expireIn = $result['expire_in'] ?? 14400;
            $newExpiresAt = date('Y-m-d H:i:s', time() + $expireIn);
            $conn->prepare("UPDATE shopee_config SET access_token = ?, refresh_token = ?, token_expires_at = ?, updated_at = NOW() WHERE is_active = 1")
                 ->execute([$accessTokenShared, $newRefreshToken, $newExpiresAt]);
            $config['access_token'] = $accessTokenShared;
            echo "[Auto Sync] Token successfully refreshed! New expiry: {$newExpiresAt}\n";
        } else {
            echo "[Auto Sync Warning] Failed to refresh token: " . json_encode($result) . "\n";
        }
    }

    // Share config globally so fetchLiveShopeeStockAndPrice() reuses it without DB hits
    global $shopeeSharedConfig, $shopeeSharedApi, $shopeeSharedToken, $shopeeSharedShopId;
    $shopeeSharedConfig  = $config;
    $shopeeSharedApi     = $shopeeShared;
    $shopeeSharedToken   = $accessTokenShared;
    $shopeeSharedShopId  = $shopIdShared;

    // 3. Fetch ALL mapped IDs at once
    $stmt = $conn->prepare("SELECT id FROM shopee_product_mappings WHERE mapping_status IN ('mapped', 'auto', 'manual')");
    $stmt->execute();
    $mapped = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($mapped)) {
        echo "[Auto Sync] No mapped products found. Exiting.\n";
        exit;
    }

    $ids = array_column($mapped, 'id');
    echo "[Auto Sync] Starting background sync for " . count($ids) . " mapped items...\n";

    // 4. Process in batches of 50 (larger chunks = fewer DB round-trips per loop)
    $chunkSize = 50;
    $chunks = array_chunk($ids, $chunkSize);
    
    $totalSuccess = 0;
    $totalFailed = 0;

    foreach ($chunks as $idx => $chunk) {
        $placeholders = implode(',', array_fill(0, count($chunk), '?'));
        $mapStmt = $conn->prepare("SELECT id, shopee_item_id, shopee_model_id, shopee_product_name, shopee_variation_name, shopee_variation_sku, shopee_parent_sku, matched_pos_sku, pos_product_id, mapping_status, stock_allocation_ratio, shopee_stock, shopee_price FROM shopee_product_mappings WHERE id IN ($placeholders)");
        $mapStmt->execute($chunk);
        $mappings = $mapStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($mappings as $map) {
            $mapId = (int)$map['id'];
            $itemId = $map['shopee_item_id'];
            $modelId = $map['shopee_model_id'];
            $posProductId = $map['pos_product_id'];
            
            $prodName = $map['shopee_product_name'];
            if (!empty($map['shopee_variation_name'])) {
                $prodName .= ' — ' . $map['shopee_variation_name'];
            }
            $skuVal = $map['shopee_variation_sku'] ?: $map['shopee_parent_sku'] ?: '—';

            try {
                $liveData = fetchLiveShopeeStockAndPrice($conn, $itemId, $modelId);
                $liveStock = (int)$liveData['stock'];
                $livePrice = (float)$liveData['price'];

                // Update shopee_stock with the live Shopee value.
                // This reflects actual stock on Shopee (e.g., allocation was 50, buyer
                // ordered 5, Shopee now reports 45 → shopee_stock becomes 45).
                // We do NOT add reservedQty here — that was causing inflation (e.g., 97+704=801).
                // We do NOT call propagateStockToPos — POS inventory only changes on user Save.
                $updCache = $conn->prepare("UPDATE shopee_product_mappings SET shopee_stock = ?, shopee_price = ?, last_synced_at = NOW(), updated_at = NOW() WHERE id = ?");
                $updCache->execute([$liveStock, $livePrice, $mapId]);

                // Create OOS alert if live stock just hit 0 and it wasn't 0 before
                if ($liveStock == 0 && (int)$map['shopee_stock'] > 0 && isset($config['out_of_stock_alerts']) && (int)$config['out_of_stock_alerts'] === 1) {
                    $alertMsg = "'{$prodName}' has run completely out of stock online! Please restock soon.";
                    $conn->prepare("INSERT INTO shopee_alerts (mapping_id, message) VALUES (?, ?)")
                         ->execute([$mapId, $alertMsg]);
                }

                // NOTE: Do NOT call propagateStockToPos here.
                // POS inventory (store_id 1 & 2) must only change when the user
                // explicitly clicks "Save & Sync" in the allocation edit modal.
                
                $totalSuccess++;
            } catch (Exception $e) {
                $totalFailed++;
                echo " - [Error] Map ID {$mapId} | Item ID {$itemId} | SKU '{$skuVal}': " . $e->getMessage() . "\n";
            }
        }
        
        // Short sleep to respect Shopee API rate limits (item_id results are cached,
        // so actual API calls = unique item_ids per batch, not all 50 rows)
        usleep(100000); // 0.1s between batches (was 0.5s — 5x faster)
    }

    echo "[Auto Sync] Completed! Success: {$totalSuccess}, Failed: {$totalFailed}\n";

} catch (Exception $e) {
    echo "[Auto Sync Error] " . $e->getMessage() . "\n";
}

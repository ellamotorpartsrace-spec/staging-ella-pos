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

    // 1. Fetch all active platforms
    $cfgStmt = $conn->prepare("SELECT * FROM shopee_config WHERE is_active = 1");
    $cfgStmt->execute();
    $configs = $cfgStmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($configs)) {
        echo "[Auto Sync] No active Shopee config found. Exiting.\n";
        exit;
    }

    foreach ($configs as $config) {
        $platform = $config['platform_name'];
        echo "[Auto Sync] Processing platform: {$platform}...\n";

        if (empty($config['access_token'])) {
            echo "[Auto Sync] Missing access token for {$platform}. Skipping.\n";
            continue;
        }

        // 2. Auto-Refresh Access Token once if expiring soon
        require_once __DIR__ . '/../classes/ShopeeAPI.php';
        $isTest = $config['environment'] === 'test';
        $shopeeShared = new ShopeeAPI($config['partner_id'], $config['partner_key'], $isTest);
        $accessTokenShared = $config['access_token'];
        $shopIdShared = $config['shop_id'];

        $timeRemaining = strtotime($config['token_expires_at']) - time();
        if ($timeRemaining <= 15 * 60) {
            echo "[Auto Sync] Token expires in less than 15 minutes for {$platform}. Auto-refreshing...\n";
            $result = $shopeeShared->refreshToken($config['refresh_token'], $shopIdShared);
            if (isset($result['access_token'])) {
                $accessTokenShared = $result['access_token'];
                $newRefreshToken = $result['refresh_token'];
                $expireIn = $result['expire_in'] ?? 14400;
                $newExpiresAt = date('Y-m-d H:i:s', time() + $expireIn);
                $conn->prepare("UPDATE shopee_config SET access_token = ?, refresh_token = ?, token_expires_at = ?, updated_at = NOW() WHERE platform_name = ?")
                     ->execute([$accessTokenShared, $newRefreshToken, $newExpiresAt, $platform]);
                $config['access_token'] = $accessTokenShared;
                echo "[Auto Sync] Token successfully refreshed for {$platform}! New expiry: {$newExpiresAt}\n";
            } else {
                echo "[Auto Sync Warning] Failed to refresh token for {$platform}: " . json_encode($result) . "\n";
            }
        }

        // Share config globally so fetchLiveShopeeStockAndPrice() reuses it without DB hits
        global $shopeeSharedConfig, $shopeeSharedApi, $shopeeSharedToken, $shopeeSharedShopId;
        $shopeeSharedConfig  = $config;
        $shopeeSharedApi     = $shopeeShared;
        $shopeeSharedToken   = $accessTokenShared;
        $shopeeSharedShopId  = $shopIdShared;

        // 3. Fetch ALL mapped IDs at once for this platform
        $stmt = $conn->prepare("SELECT id FROM shopee_product_mappings WHERE platform_name = ? AND mapping_status IN ('mapped', 'auto', 'manual')");
        $stmt->execute([$platform]);
        $mapped = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($mapped)) {
            echo "[Auto Sync] No mapped products found for {$platform}. Skipping.\n";
            continue;
        }

        $ids = array_column($mapped, 'id');
        echo "[Auto Sync] Starting background sync for " . count($ids) . " mapped items on {$platform}...\n";

        // 4. Process in batches of 50
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
                    $liveData = fetchLiveShopeeStockAndPrice($conn, $itemId, $modelId, $platform);
                    $liveStock = (int)$liveData['stock'];
                    $livePrice = (float)$liveData['price'];

                    $updCache = $conn->prepare("UPDATE shopee_product_mappings SET shopee_price = ?, last_synced_at = NOW(), updated_at = NOW() WHERE id = ?");
                    $updCache->execute([$livePrice, $mapId]);

                    if ($liveStock == 0 && (int)$map['shopee_stock'] > 0 && isset($config['out_of_stock_alerts']) && (int)$config['out_of_stock_alerts'] === 1) {
                        $alertMsg = "'{$prodName}' has run completely out of stock online! Please restock soon.";
                        $conn->prepare("INSERT INTO shopee_alerts (platform_name, mapping_id, message) VALUES (?, ?, ?)")
                             ->execute([$platform, $mapId, $alertMsg]);
                    }
                    
                    $totalSuccess++;
                } catch (Exception $e) {
                    $totalFailed++;
                    echo " - [Error] Map ID {$mapId} | Item ID {$itemId} | SKU '{$skuVal}': " . $e->getMessage() . "\n";
                }
            }
            usleep(100000); 
        }
        echo "[Auto Sync] Completed for {$platform}! Success: {$totalSuccess}, Failed: {$totalFailed}\n";
    }

} catch (Exception $e) {
    echo "[Auto Sync Error] " . $e->getMessage() . "\n";
}

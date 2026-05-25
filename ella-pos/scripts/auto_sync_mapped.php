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

    // 1. Check and Auto-Refresh Shopee Access Token (if < 15 mins remaining)
    $cfgStmt = $conn->prepare("SELECT * FROM shopee_config WHERE is_active = 1 LIMIT 1");
    $cfgStmt->execute();
    $config = $cfgStmt->fetch(PDO::FETCH_ASSOC);

    if ($config && !empty($config['refresh_token'])) {
        $expiresAt = strtotime($config['token_expires_at']);
        $timeRemaining = $expiresAt - time();

        if ($timeRemaining <= 15 * 60) {
            echo "[Auto Sync] Token expires in less than 15 minutes. Auto-refreshing...\n";
            require_once __DIR__ . '/../classes/ShopeeApi.php';
            $isTest = $config['environment'] === 'test';
            $shopee = new ShopeeAPI($config['partner_id'], $config['partner_key'], $isTest);
            $result = $shopee->refreshToken($config['refresh_token'], $config['shop_id']);

            if (isset($result['access_token'])) {
                $newAccessToken = $result['access_token'];
                $newRefreshToken = $result['refresh_token'];
                $expireIn = $result['expire_in'] ?? 14400;
                $newExpiresAt = date('Y-m-d H:i:s', time() + $expireIn);

                $updStmt = $conn->prepare("UPDATE shopee_config SET access_token = ?, refresh_token = ?, token_expires_at = ?, updated_at = NOW() WHERE is_active = 1");
                $updStmt->execute([$newAccessToken, $newRefreshToken, $newExpiresAt]);
                echo "[Auto Sync] Token successfully refreshed! New expiry: {$newExpiresAt}\n";
                
                // Token refresh success log removed to prevent background log clutter
            } else {
                echo "[Auto Sync Warning] Failed to refresh token: " . json_encode($result) . "\n";
            }
        }
    }

    // 2. Fetch all mapped IDs
    $stmt = $conn->prepare("SELECT id FROM shopee_product_mappings WHERE mapping_status IN ('mapped', 'auto', 'manual')");
    $stmt->execute();
    $mapped = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($mapped)) {
        echo "[Auto Sync] No mapped products found. Exiting.\n";
        exit;
    }

    $ids = array_column($mapped, 'id');
    echo "[Auto Sync] Starting background sync for " . count($ids) . " mapped items...\n";

    // 2. We can utilize the existing fetch_mapped_live_stocks.php logic by making a local HTTP request or including the logic.
    // Instead of HTTP, we will batch them directly here using the sync_helpers functions.
    $chunkSize = 15;
    $chunks = array_chunk($ids, $chunkSize);
    
    $totalSuccess = 0;
    $totalFailed = 0;

    foreach ($chunks as $idx => $chunk) {
        $chunkNum = $idx + 1;
        echo "[Batch {$chunkNum}] Processing " . count($chunk) . " items...\n";
        
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

                // Query active reservation sum to calculate the true Allocated stock
                $resStmt = $conn->prepare("
                    SELECT COALESCE(SUM(quantity), 0)
                    FROM shopee_reserved_stock
                    WHERE shopee_item_id = ? 
                      AND (
                          (? IS NULL AND shopee_model_id IS NULL)
                          OR (? = shopee_model_id)
                      )
                      AND is_active = 1
                ");
                $resStmt->execute([
                    $itemId,
                    $modelId ?: null,
                    $modelId ?: null
                ]);
                $reservedQty = (int)$resStmt->fetchColumn();
                $allocatedStock = $liveStock + $reservedQty;

                // Update mapping cache (use Allocated stock)
                $updCache = $conn->prepare("UPDATE shopee_product_mappings SET shopee_stock = ?, shopee_price = ?, last_synced_at = NOW(), updated_at = NOW() WHERE id = ?");
                $updCache->execute([$allocatedStock, $livePrice, $mapId]);

                // Propagate to POS
                if (!empty($posProductId)) {
                    propagateStockToPos($conn, (int)$posProductId, $allocatedStock, $prodName, $skuVal, 1, $mapId);
                }
                
                $totalSuccess++;
                echo " - [Success] ID {$mapId}: Allocated -> {$allocatedStock} (Live: {$liveStock}, Reserved: {$reservedQty})\n";
            } catch (Exception $e) {
                $totalFailed++;
                echo " - [Error] ID {$mapId}: " . $e->getMessage() . "\n";
            }
        }
        
        // Sleep slightly to respect Shopee API limits
        usleep(500000); // 0.5s
    }

    echo "[Auto Sync] Completed! Success: {$totalSuccess}, Failed: {$totalFailed}\n";

} catch (Exception $e) {
    echo "[Auto Sync Error] " . $e->getMessage() . "\n";
}

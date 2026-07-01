<?php
/**
 * api/lazada/cron_sync.php
 * Background cron job to sync POS inventory to Lazada for all configured accounts.
 */
set_time_limit(0);
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/LazadaAPI.php';

try {
    $db = new Database();
    $conn = $db->getConnection();

    // 1. Get all active configs where sync is enabled
    $stmt = $conn->query("SELECT * FROM lazada_config WHERE enable_stock_sync = 1 AND is_active = 1 AND access_token IS NOT NULL");
    $configs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$configs) {
        die("No active Lazada integrations found with sync enabled.\n");
    }

    foreach ($configs as $config) {
        $platform = $config['platform_name'];
        echo "Processing $platform...\n";
        
        $api = new LazadaAPI($config['app_key'], $config['app_secret'], $config['country_code'], $config['environment'] === 'sandbox');
        $api->setAccessToken($config['access_token']);

        // Check if token needs refresh (if within 24 hours of expiry)
        $expires_at = strtotime($config['token_expires_at']);
        if ($expires_at && ($expires_at - time() < 86400) && !empty($config['refresh_token'])) {
            echo "Refreshing token for $platform...\n";
            $res = $api->refreshAccessToken($config['refresh_token']);
            if (isset($res['access_token'])) {
                $api->setAccessToken($res['access_token']);
                $conn->prepare("UPDATE lazada_config SET access_token = ?, refresh_token = ?, token_expires_at = ?, refresh_expires_at = ? WHERE id = ?")
                     ->execute([
                         $res['access_token'], 
                         $res['refresh_token'], 
                         date('Y-m-d H:i:s', time() + $res['expires_in']), 
                         date('Y-m-d H:i:s', time() + $res['refresh_expires_in']), 
                         $config['id']
                     ]);
            }
        }

        // Fetch all active mapped products for this platform
        $mapStmt = $conn->prepare("SELECT * FROM lazada_product_mappings WHERE platform_name = ? AND mapping_status IN ('mapped', 'auto', 'manual') AND sync_status = 'active'");
        $mapStmt->execute([$platform]);
        $mappings = $mapStmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($mappings)) {
            echo "No active mappings for $platform.\n";
            continue;
        }

        $xmlSkus = "";
        $updateCount = 0;

        foreach ($mappings as $map) {
            if (empty($map['pos_product_id']) || empty($map['lazada_seller_sku'])) continue;

            // Get POS stock
            $stockStmt = $conn->prepare("SELECT SUM(stock) FROM product_units WHERE product_id = ? AND unit_id = ?");
            $stockStmt->execute([$map['pos_product_id'], $map['pos_unit_id']]);
            $posStock = (int)$stockStmt->fetchColumn();

            // Calculate Allocation
            $allocRatio = $config['respect_allocation'] ? (float)$map['stock_allocation_ratio'] : 100.0;
            $floor = (int)$config['buffer_stock']; // Global floor
            if (isset($map['safety_floor']) && $map['safety_floor'] > 0) { 
                 $floor = (int)$map['safety_floor'];
            }

            $allocatedStock = floor($posStock * ($allocRatio / 100));
            $finalStock = max(0, $allocatedStock - $floor);

            // Only update if stock changed
            if ($finalStock !== (int)$map['lazada_stock']) {
                $skuIdStr = $map['lazada_sku_id'] ? "<SkuId>{$map['lazada_sku_id']}</SkuId>" : "";
                $xmlSkus .= "
                <Sku>
                    {$skuIdStr}
                    <SellerSku><![CDATA[{$map['lazada_seller_sku']}]]></SellerSku>
                    <Quantity>{$finalStock}</Quantity>
                </Sku>";
                
                // Update local DB to reflect pushed stock
                $conn->prepare("UPDATE lazada_product_mappings SET lazada_stock = ?, last_synced_at = NOW() WHERE id = ?")
                     ->execute([$finalStock, $map['id']]);
                
                $updateCount++;
            }
        }

        if ($updateCount > 0) {
            $xmlPayload = "<?xml version=\"1.0\" encoding=\"utf-8\" ?>\n<Request>\n    <Product>\n        <Skus>{$xmlSkus}\n        </Skus>\n    </Product>\n</Request>";
            
            $response = $api->call('/product/price_quantity/update', ['payload' => $xmlPayload], 'POST');
            
            if (isset($response['code']) && $response['code'] === '0') {
                echo "Successfully synced $updateCount items for $platform.\n";
            } else {
                echo "Error syncing $platform: " . json_encode($response) . "\n";
            }
        } else {
            echo "No stock changes needed for $platform.\n";
        }
    }
    
    echo "Cron sync completed.\n";

} catch (Exception $e) {
    die("Cron Error: " . $e->getMessage() . "\n");
}

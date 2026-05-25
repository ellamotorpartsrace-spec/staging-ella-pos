<?php
/**
 * scripts/shopee_stock_watcher.php
 * Automated background script to pull live stock for all mapped items.
 * Intended to be run via Windows Task Scheduler or cron job every 5-10 minutes.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../api/shopee/sync_helpers.php';

try {
    $db = new Database();
    $conn = $db->getConnection();

    // 1. Fetch active Shopee configuration
    $cfgStmt = $conn->prepare("SELECT * FROM shopee_config WHERE is_active = 1 LIMIT 1");
    $cfgStmt->execute();
    $config = $cfgStmt->fetch(PDO::FETCH_ASSOC);

    if (!$config || empty($config['access_token'])) {
        echo "[Stock Watcher] Shopee not authorized or missing tokens.\n";
        exit;
    }

    echo "[Stock Watcher] Starting background sync for all mapped products...\n";

    // 2. Fetch all active mappings
    $stmt = $conn->prepare("SELECT id, shopee_item_id, shopee_model_id, shopee_product_name, shopee_variation_name, shopee_variation_sku, shopee_parent_sku, pos_product_id, mapping_status, shopee_stock FROM shopee_product_mappings WHERE mapping_status IN ('auto', 'manual')");
    $stmt->execute();
    $mappings = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($mappings)) {
        echo "[Stock Watcher] No mapped items found.\n";
        exit;
    }

    $successCount = 0;
    $failCount = 0;

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
            $allocatedStock = $liveData['stock'];
            $livePrice = $liveData['price'];

            // Update mapping cache
            $updCache = $conn->prepare("UPDATE shopee_product_mappings SET shopee_stock = ?, shopee_price = ?, last_synced_at = NOW(), updated_at = NOW() WHERE id = ?");
            $updCache->execute([$allocatedStock, $livePrice, $mapId]);

            // Create OOS alert if stock hit 0 and it wasn't 0 before
            if ($allocatedStock == 0 && (int)$map['shopee_stock'] > 0 && !empty($config['out_of_stock_alerts'])) {
                $alertMsg = "'{$prodName}' has run completely out of stock online! Please restock soon.";
                $conn->prepare("INSERT INTO shopee_alerts (mapping_id, message) VALUES (?, ?)")
                     ->execute([$mapId, $alertMsg]);
            }

            // Propagate to POS
            if (!empty($posProductId)) {
                propagateStockToPos($conn, (int)$posProductId, $allocatedStock, $prodName, $skuVal, null, $mapId);
            }

            $successCount++;
        } catch (Exception $ex) {
            echo "[Stock Watcher Error] Failed to sync Item {$itemId}: " . $ex->getMessage() . "\n";
            $failCount++;
        }
        
        // Small delay to respect Shopee API rate limits
        usleep(100000); // 100ms
    }

    echo "[Stock Watcher] Sync complete. Success: {$successCount}, Failed: {$failCount}\n";

} catch (Exception $e) {
    echo "[Stock Watcher Fatal Error] " . $e->getMessage() . "\n";
}

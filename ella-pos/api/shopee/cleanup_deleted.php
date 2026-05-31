<?php
/**
 * api/shopee/cleanup_deleted.php
 * Cross-references local database with Shopee API to detect and remove deleted products/variations.
 */
header('Content-Type: application/json');
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../classes/ShopeeAPI.php';

requirePermission('shopee_sync');

try {
    $db = new Database();
    $conn = $db->getConnection();

    // 1. Get API Credentials
    $cfgStmt = $conn->prepare("SELECT * FROM shopee_config WHERE is_active = 1 LIMIT 1");
    $cfgStmt->execute();
    $config = $cfgStmt->fetch(PDO::FETCH_ASSOC);

    if (!$config || empty($config['access_token'])) {
        throw new Exception("Shopee not authorized or missing tokens.");
    }

    $isTest = $config['environment'] === 'test';
    $shopee = new ShopeeAPI($config['partner_id'], $config['partner_key'], $isTest);
    $accessToken = $config['access_token'];
    $shopId = $config['shop_id'];

    // 2. Refresh Token if needed
    if (strtotime($config['token_expires_at']) - time() < 600) {
        $refreshResult = $shopee->refreshToken($config['refresh_token'], $shopId);
        if (isset($refreshResult['access_token'])) {
            $accessToken = $refreshResult['access_token'];
            $expiresAt = date('Y-m-d H:i:s', time() + ($refreshResult['expire_in'] ?? 14400));
            $conn->prepare("UPDATE shopee_config SET access_token=?, refresh_token=?, token_expires_at=?, updated_at=NOW() WHERE is_active=1")
                 ->execute([$accessToken, $refreshResult['refresh_token'], $expiresAt]);
        }
    }

    // 3. Get all distinct Shopee Item IDs in our database
    $stmt = $conn->prepare("SELECT DISTINCT shopee_item_id FROM shopee_product_mappings");
    $stmt->execute();
    $localItemIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (empty($localItemIds)) {
        echo json_encode(['success' => true, 'message' => 'No products in database to clean up.']);
        exit;
    }

    $deletedItemsCount = 0;
    $deletedVariationsCount = 0;

    // Batch item base info requests in chunks of 50
    $chunks = array_chunk($localItemIds, 50);
    
    foreach ($chunks as $chunk) {
        $itemIdStr = implode(',', $chunk);
        
        $baseInfoResult = $shopee->get('/api/v2/product/get_item_base_info', [
            'item_id_list' => $itemIdStr
        ], $accessToken, $shopId);

        $activeItems = [];
        if (isset($baseInfoResult['response']['item_list'])) {
            foreach ($baseInfoResult['response']['item_list'] as $item) {
                // Status "NORMAL" means active, "UNLIST" means inactive but still exists.
                // We keep it unless it is completely missing.
                $activeItems[(string)$item['item_id']] = $item;
            }
        }

        // Cross-reference Items
        $itemsWithModels = [];
        
        foreach ($chunk as $itemId) {
            $itemIdStr = (string)$itemId;
            
            if (!isset($activeItems[$itemIdStr])) {
                // Item has been completely deleted from Shopee!
                // Fetch affected POS products to restore their stock
                $stmtGetAffected = $conn->prepare("SELECT DISTINCT pos_product_id FROM shopee_product_mappings WHERE shopee_item_id = ? AND pos_product_id IS NOT NULL AND mapping_status IN ('auto', 'manual')");
                $stmtGetAffected->execute([$itemIdStr]);
                $affectedPosIds = $stmtGetAffected->fetchAll(PDO::FETCH_COLUMN);

                $conn->prepare("DELETE FROM shopee_product_mappings WHERE shopee_item_id = ?")->execute([$itemIdStr]);
                
                require_once __DIR__ . '/sync_helpers.php';
                foreach ($affectedPosIds as $posId) {
                    propagateStockToPos($conn, $posId, 0, 'Ghost Cleanup', '', $_SESSION['user_id'] ?? null, null);
                }

                $deletedItemsCount++;
            } else {
                // Item exists. Check if it has models (variations)
                if ($activeItems[$itemIdStr]['has_model']) {
                    $itemsWithModels[] = $itemId;
                    
                    // Cleanup orphaned "Main Item" rows for products that now have variations
                    $stmtGetAffected = $conn->prepare("SELECT DISTINCT pos_product_id FROM shopee_product_mappings WHERE shopee_item_id = ? AND (shopee_model_id IS NULL OR shopee_model_id = 0) AND pos_product_id IS NOT NULL AND mapping_status IN ('auto', 'manual')");
                    $stmtGetAffected->execute([$itemIdStr]);
                    $affectedPosIds = $stmtGetAffected->fetchAll(PDO::FETCH_COLUMN);

                    $delStmt = $conn->prepare("DELETE FROM shopee_product_mappings WHERE shopee_item_id = ? AND (shopee_model_id IS NULL OR shopee_model_id = 0)");
                    $delStmt->execute([$itemIdStr]);
                    
                    if ($delStmt->rowCount() > 0) {
                        require_once __DIR__ . '/sync_helpers.php';
                        foreach ($affectedPosIds as $posId) {
                            propagateStockToPos($conn, $posId, 0, 'Ghost Cleanup', '', $_SESSION['user_id'] ?? null, null);
                        }
                        $deletedVariationsCount += $delStmt->rowCount();
                    }
                }
            }
        }

        // Cross-reference Variations for items that have models
        if (!empty($itemsWithModels)) {
            $modelQueries = [];
            foreach ($itemsWithModels as $iid) {
                $modelQueries[] = ['item_id' => (int)$iid];
            }
            
            // Multi-get model lists
            $modelResults = $shopee->getMulti('/api/v2/product/get_model_list', $modelQueries, $accessToken, $shopId);
            
            foreach ($itemsWithModels as $index => $iid) {
                $mResult = $modelResults[$index] ?? [];
                
                $activeModelIds = [];
                if (isset($mResult['response']['model']) && is_array($mResult['response']['model'])) {
                    foreach ($mResult['response']['model'] as $model) {
                        $activeModelIds[] = (string)$model['model_id'];
                    }
                }
                
                if (empty($activeModelIds)) {
                    continue; // Skip if API didn't return models for some reason to prevent accidental deletion
                }
                
                // Fetch local models for this item
                $varStmt = $conn->prepare("SELECT shopee_model_id, pos_product_id, mapping_status FROM shopee_product_mappings WHERE shopee_item_id = ? AND shopee_model_id > 0");
                $varStmt->execute([$iid]);
                $localModels = $varStmt->fetchAll(PDO::FETCH_ASSOC);
                
                foreach ($localModels as $localModel) {
                    $localModelIdStr = (string)$localModel['shopee_model_id'];
                    if (!in_array($localModelIdStr, $activeModelIds)) {
                        // Variation was deleted on Shopee!
                        $conn->prepare("DELETE FROM shopee_product_mappings WHERE shopee_model_id = ?")->execute([$localModelIdStr]);
                        
                        if (!empty($localModel['pos_product_id']) && in_array($localModel['mapping_status'], ['auto', 'manual'])) {
                            require_once __DIR__ . '/sync_helpers.php';
                            propagateStockToPos($conn, $localModel['pos_product_id'], 0, 'Ghost Cleanup', '', $_SESSION['user_id'] ?? null, null);
                        }
                        
                        $deletedVariationsCount++;
                    }
                }
            }
        }
    }

    $msg = "Cleanup finished. ";
    if ($deletedItemsCount > 0 || $deletedVariationsCount > 0) {
        $msg .= "Removed {$deletedItemsCount} ghost products and {$deletedVariationsCount} ghost variations.";
    } else {
        $msg .= "No ghost products found. Database is perfectly synced!";
    }

    echo json_encode([
        'success' => true, 
        'message' => $msg,
        'details' => [
            'totalChecked' => count($localItemIds),
            'deletedItems' => $deletedItemsCount,
            'deletedVariations' => $deletedVariationsCount
        ]
    ]);

    // Insert Log Record
    $userId = $_SESSION['user_id'] ?? 0;
    $logValue = "Checked: " . count($localItemIds) . " items. Removed: {$deletedItemsCount} ghost items, {$deletedVariationsCount} ghost variations.";
    $logStmt = $conn->prepare("INSERT INTO shopee_sync_logs (event_type, product_name, source, status, new_value, created_by, created_at) VALUES ('product_import', 'Ghost Product Cleanup', 'Manual Cleanup', 'success', ?, ?, NOW())");
    $logStmt->execute([$logValue, $userId]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

<?php
/**
 * api/shopee/fetch_mapped_live_stocks.php — Fetch live stock/price from Shopee on demand and sync
 */
header("Content-Type: application/json");
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once __DIR__ . '/sync_helpers.php';

requireLogin();

if (!hasPermission('shopee_sync')) {
    echo json_encode(['success' => false, 'error' => 'Permission denied. Please request access from admin Les.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$ids = $input['ids'] ?? [];
$skipLog = !empty($input['skip_log']);

if (empty($ids) || !is_array($ids)) {
    echo json_encode(['success' => false, 'error' => 'No mapping IDs provided']);
    exit;
}

try {
    $db = new Database();
    $conn = $db->getConnection();

    // Prepare statement to fetch mappings
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $conn->prepare("SELECT id, shopee_item_id, shopee_model_id, shopee_product_name, shopee_variation_name, shopee_variation_sku, shopee_parent_sku, matched_pos_sku, pos_product_id, mapping_status, stock_allocation_ratio, shopee_stock, shopee_price FROM shopee_product_mappings WHERE id IN ($placeholders)");
    $stmt->execute($ids);
    $mappings = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($mappings)) {
        echo json_encode(['success' => true, 'updated' => [], 'message' => 'No matching mappings found in the database.']);
        exit;
    }

    $cfgStmt = $conn->prepare("SELECT out_of_stock_alerts FROM shopee_config WHERE is_active = 1 LIMIT 1");
    $cfgStmt->execute();
    $config = $cfgStmt->fetch(PDO::FETCH_ASSOC);

    $updated = [];
    $errors = [];

    // Pre-warm the Shopee API cache using concurrent requests
    if (function_exists('prewarmShopeeApiCache')) {
        $uniqueItemIds = array_unique(array_column($mappings, 'shopee_item_id'));
        prewarmShopeeApiCache($conn, $uniqueItemIds);
    }

    // Loop through each mapping and sync live values
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
            // Fetch true live stock and price from Shopee API
            $liveData = fetchLiveShopeeStockAndPrice($conn, $itemId, $modelId);
            $liveStock = $liveData['stock'];
            $livePrice = $liveData['price'];

            $allocatedStock = $liveStock;

            // Update mapping table with fresh values (use Allocated stock)
            $updCache = $conn->prepare("UPDATE shopee_product_mappings SET shopee_stock = ?, shopee_price = ?, last_synced_at = NOW(), updated_at = NOW() WHERE id = ?");
            $updCache->execute([$allocatedStock, $livePrice, $mapId]);

            // Create OOS alert if stock hit 0 and it wasn't 0 before
            if ($allocatedStock == 0 && (int)$map['shopee_stock'] > 0 && !empty($config['out_of_stock_alerts'])) {
                $alertMsg = "'{$prodName}' has run completely out of stock online! Please restock soon.";
                $conn->prepare("INSERT INTO shopee_alerts (mapping_id, message) VALUES (?, ?)")
                     ->execute([$mapId, $alertMsg]);
            }

            // If mapped to POS, propagate stock
            if (($map['mapping_status'] === 'manual' || $map['mapping_status'] === 'auto') && !empty($posProductId)) {
                propagateStockToPos($conn, (int)$posProductId, $allocatedStock, $prodName, $skuVal, $_SESSION['user_id'] ?? null, $mapId);
            }

            // Fetch the updated mapping row to return the fresh calculated ratio and stocks
            $freshStmt = $conn->prepare("SELECT shopee_stock, shopee_price, stock_allocation_ratio, mapping_status FROM shopee_product_mappings WHERE id = ?");
            $freshStmt->execute([$mapId]);
            $fresh = $freshStmt->fetch(PDO::FETCH_ASSOC);

            // Fetch POS physical stock and POS online stock for UI update
            $posPhysStock = 0;
            $posOnlineStock = 0;
            if (!empty($posProductId)) {
                $invStmt = $conn->prepare("SELECT store_id, quantity FROM inventory WHERE variation_id = ?");
                $invStmt->execute([$posProductId]);
                $invRows = $invStmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($invRows as $row) {
                    $rowId = isset($row['row_id']) ? (int)$row['row_id'] : 0;
                    if ($rowId === 1 || (int)$row['store_id'] === 1) {
                        $posPhysStock = (int)$row['quantity'];
                    } elseif ($rowId === 2 || (int)$row['store_id'] === 2) {
                        $posOnlineStock = (int)$row['quantity'];
                    }
                }
            }

            $oldStock = (int)$map['shopee_stock'];
            $newStock = (int)$allocatedStock;
            
            $updated[] = [
                'id' => $mapId,
                'shopee_stock' => $allocatedStock,
                'shopee_price' => $livePrice,
                'stock_allocation_ratio' => (int)($fresh['stock_allocation_ratio'] ?? 0),
                'pos_physical_stock' => $posPhysStock,
                'pos_online_stock' => $posOnlineStock,
                'success' => true,
                'changed' => ($oldStock !== $newStock)
            ];

            if (!$skipLog) {
                // Calculate difference for clearer logging
                $diff = $newStock - $oldStock;
                $diffText = ($diff >= 0) ? "+$diff" : (string)$diff;
                $label = ($diff > 0) ? "Added" : ($diff < 0 ? "Deducted" : "Updated");

                // Log successful sync as a stock_update event
                $logStmt = $conn->prepare("
                    INSERT INTO shopee_sync_logs (event_type, shopee_item_id, product_name, sku, old_value, new_value, status, source, created_by, created_at)
                    VALUES ('stock_update', ?, ?, ?, ?, ?, 'success', 'Live Sync Manual Fetch', ?, NOW())
                ");
                $logStmt->execute([
                    $itemId,
                    $prodName,
                    $skuVal,
                    (string)$oldStock,
                    "$newStock ($diffText $label)",
                    $_SESSION['user_id'] ?? null
                ]);
            }

        } catch (Exception $ex) {
            $errors[] = [
                'id' => $mapId,
                'product' => $prodName,
                'error' => $ex->getMessage()
            ];

            if (!$skipLog) {
                // Log sync failure
                $logStmt = $conn->prepare("
                    INSERT INTO shopee_sync_logs (event_type, shopee_item_id, product_name, sku, status, error_message, source, created_by, created_at)
                    VALUES ('stock_update', ?, ?, ?, 'failed', ?, 'Live Sync Manual Fetch', ?, NOW())
                ");
                $logStmt->execute([
                    $itemId,
                    $prodName,
                    $skuVal,
                    $ex->getMessage(),
                    $_SESSION['user_id'] ?? null
                ]);
            }
        }
    }

    echo json_encode([
        'success' => true,
        'updated' => $updated,
        'errors' => $errors,
        'message' => count($updated) . ' item(s) synced, ' . count($errors) . ' failed.'
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

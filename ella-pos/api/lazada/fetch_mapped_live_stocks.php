<?php
/**
 * api/lazada/fetch_mapped_live_stocks.php — Fetch live stock/price from Lazada on demand and sync
 */
header("Content-Type: application/json");
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once __DIR__ . '/sync_helpers.php';

requireLogin();

if (!hasPermission('lazada_sync')) {
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
    $stmt = $conn->prepare("SELECT id, lazada_item_id, lazada_model_id, lazada_product_name, lazada_variation_name, lazada_variation_sku, lazada_parent_sku, matched_pos_sku, pos_product_id, pos_bundle_set_id, mapping_status, stock_allocation_ratio, lazada_stock, lazada_price FROM lazada_product_mappings WHERE id IN ($placeholders)");
    $stmt->execute($ids);
    $mappings = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($mappings)) {
        echo json_encode(['success' => true, 'updated' => [], 'message' => 'No matching mappings found in the database.']);
        exit;
    }

    $cfgStmt = $conn->prepare("SELECT out_of_stock_alerts FROM lazada_config WHERE is_active = 1 LIMIT 1");
    $cfgStmt->execute();
    $config = $cfgStmt->fetch(PDO::FETCH_ASSOC);

    $updated = [];
    $errors = [];

    // Pre-warm the Lazada API cache using concurrent requests
    if (function_exists('prewarmLazadaApiCache')) {
        $uniqueItemIds = array_unique(array_column($mappings, 'lazada_item_id'));
        prewarmLazadaApiCache($conn, $uniqueItemIds);
    }

    // Loop through each mapping and sync live values
    foreach ($mappings as $map) {
        $mapId = (int)$map['id'];
        $itemId = $map['lazada_item_id'];
        $modelId = $map['lazada_model_id'];
        $posProductId = $map['pos_product_id'];
        $prodName = $map['lazada_product_name'];
        if (!empty($map['lazada_variation_name'])) {
            $prodName .= ' — ' . $map['lazada_variation_name'];
        }
        $skuVal = $map['lazada_variation_sku'] ?: $map['lazada_parent_sku'] ?: '—';

        try {
            // Fetch true live stock and price from Lazada API
            $liveData = fetchLiveLazadaStockAndPrice($conn, $itemId, $modelId);
            $liveStock = $liveData['stock'];
            $livePrice = $liveData['price'];

            $allocatedStock = $liveStock;

            // Update lazada_stock with the live Lazada value.
            // This reflects actual stock on Lazada (e.g., allocation was 50, buyer
            // ordered 5, Lazada now reports 45 → lazada_stock becomes 45 here).
            // We do NOT add reservedQty — that caused inflation (e.g., 97+704=801).
            // We do NOT call propagateStockToPos — POS inventory only changes on user Save.
            $updCache = $conn->prepare("UPDATE lazada_product_mappings SET lazada_stock = ?, lazada_price = ?, last_synced_at = NOW(), updated_at = NOW() WHERE id = ?");
            $updCache->execute([$allocatedStock, $livePrice, $mapId]);

            // Create OOS alert if stock hit 0 and it wasn't 0 before
            if ($allocatedStock == 0 && (int)$map['lazada_stock'] > 0 && !empty($config['out_of_stock_alerts'])) {
                $alertMsg = "'{$prodName}' has run completely out of stock online! Please restock soon.";
                $conn->prepare("INSERT INTO lazada_alerts (mapping_id, message) VALUES (?, ?)")
                     ->execute([$mapId, $alertMsg]);
            }

            // NOTE: Do NOT call propagateStockToPos here.
            // Live sync should only refresh the Lazada stock/price display.
            // POS inventory (store_id 1 & 2) should only change when the user
            // explicitly clicks "Save & Sync" in the allocation edit modal.
            // Calling it here caused the overallocated filter to show random
            // products because it rebalanced POS inventory on every page load.

            // Fetch the updated mapping row to return the fresh calculated ratio and stocks
            $freshStmt = $conn->prepare("SELECT lazada_stock, lazada_price, stock_allocation_ratio, mapping_status FROM lazada_product_mappings WHERE id = ?");
            $freshStmt->execute([$mapId]);
            $fresh = $freshStmt->fetch(PDO::FETCH_ASSOC);

            // Fetch POS physical stock and POS online stock for UI update
            $posPhysStock = 0;
            $posOnlineStock = 0;
            $bundleTotalSets = null;
            if (!empty($posProductId)) {
                $invStmt = $conn->prepare("SELECT store_id, quantity FROM inventory WHERE variation_id = ?");
                $invStmt->execute([$posProductId]);
                $invRows = $invStmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($invRows as $row) {
                    $rowId = isset($row['row_id']) ? (int)$row['row_id'] : 0;
                    if ($rowId === 1 || (int)$row['store_id'] === 1) {
                        $posPhysStock = (float) $row['quantity'];
                    } elseif ($rowId === 2 || (int)$row['store_id'] === 2) {
                        $posOnlineStock = (float) $row['quantity'];
                    }
                }
            } elseif (!empty($map['pos_bundle_set_id'])) {
                $bundleSetId = (int)$map['pos_bundle_set_id'];
                $compStmt = $conn->prepare("
                    SELECT
                        si.component_variation_id,
                        si.component_qty,
                        COALESCE(cu.multiplier, 1) AS component_unit_multiplier,
                        (COALESCE(i1.quantity, 0) + COALESCE(i2.quantity, 0)) AS component_base_qty,
                        COALESCE(res.reserved_base_qty, 0) AS reserved_base_qty
                    FROM product_unit_set_items si
                    LEFT JOIN product_units cu ON cu.id = si.component_unit_id
                    LEFT JOIN inventory i1 ON i1.variation_id = si.component_variation_id AND i1.store_id = 1
                    LEFT JOIN inventory i2 ON i2.variation_id = si.component_variation_id AND i2.store_id = 2
                    LEFT JOIN (
                        SELECT
                            m.pos_product_id,
                            SUM(m.lazada_stock * COALESCE(u.multiplier, 1)) AS reserved_base_qty
                        FROM lazada_product_mappings m
                        LEFT JOIN product_units u ON u.id = m.pos_unit_id
                        WHERE m.mapping_status IN ('auto','manual')
                          AND m.pos_bundle_set_id IS NULL
                          AND m.pos_product_id IS NOT NULL
                        GROUP BY m.pos_product_id
                    ) res ON res.pos_product_id = si.component_variation_id
                    WHERE si.product_set_id = ?
                ");
                $compStmt->execute([$bundleSetId]);
                $compRows = $compStmt->fetchAll(PDO::FETCH_ASSOC);

                $otherBundleReserveStmt = $conn->prepare("
                    SELECT
                        si.component_variation_id,
                        SUM(m.lazada_stock * si.component_qty * COALESCE(cu.multiplier, 1)) AS reserved_base_qty
                    FROM lazada_product_mappings m
                    INNER JOIN product_unit_set_items si ON si.product_set_id = m.pos_bundle_set_id
                    LEFT JOIN product_units cu ON cu.id = si.component_unit_id
                    WHERE m.mapping_status IN ('auto','manual')
                      AND m.pos_bundle_set_id IS NOT NULL
                      AND m.id <> ?
                    GROUP BY si.component_variation_id
                ");
                $otherBundleReserveStmt->execute([$mapId]);
                $otherBundleReserved = [];
                foreach ($otherBundleReserveStmt->fetchAll(PDO::FETCH_ASSOC) as $reserveRow) {
                    $otherBundleReserved[(int)$reserveRow['component_variation_id']] = (float)$reserveRow['reserved_base_qty'];
                }

                $minSets = null;
                foreach ($compRows as $c) {
                    $requiredBase = (float)$c['component_qty'] * max(1, (int)$c['component_unit_multiplier']);
                    if ($requiredBase <= 0) {
                        continue;
                    }
                    $componentVariationId = (int)$c['component_variation_id'];
                    $reservedBase = (float)$c['reserved_base_qty'] + (float)($otherBundleReserved[$componentVariationId] ?? 0);
                    $freeBase = max(0, (float)$c['component_base_qty'] - $reservedBase);
                    $possible = (int)floor($freeBase / $requiredBase);
                    $minSets = $minSets === null ? $possible : min($minSets, $possible);
                }
                $bundleTotalSets = max(0, (int)($minSets ?? 0));
                $posPhysStock = $bundleTotalSets;
                $posOnlineStock = 0;
            }

            $dbAllocation = (int)($fresh['lazada_stock'] ?? $map['lazada_stock']);
            $oldStock = (int)$map['lazada_stock'];
            $newStock = (int)$allocatedStock; // live stock (same as what we just wrote)
            
            // --- NEW AUTO-DEDUCT LOGIC (Bypass sync_orders.php) ---
            if ($newStock < $oldStock) {
                $qtySold = $oldStock - $newStock;
                $userId = $_SESSION['user_id'] ?? 1;
                
                if (!empty($posProductId)) {
                    // Deduct single product
                    $conn->prepare("UPDATE inventory SET quantity = quantity - ? WHERE variation_id = ? AND store_id = 1")->execute([$qtySold, $posProductId]);
                    
                    // Log movement
                    $getInv = $conn->prepare("SELECT quantity FROM inventory WHERE variation_id = ? AND store_id = 1");
                    $getInv->execute([$posProductId]);
                    $currStock = (int)$getInv->fetchColumn();
                    $prevStock = $currStock + $qtySold;
                    
                    $conn->prepare("INSERT INTO stock_movements (store_id, variation_id, type, quantity, previous_stock, new_stock, reference, remarks, created_by) VALUES (1, ?, 'online_sale', ?, ?, ?, 'Lazada Live Drop', 'Auto-deducted from Lazada API Sync', ?)")
                         ->execute([$posProductId, $qtySold, $prevStock, $currStock, $userId]);
                         
                    $posPhysStock = max(0, $posPhysStock - $qtySold);
                    
                } elseif (!empty($map['pos_bundle_set_id'])) {
                    // Deduct bundle components
                    $bundleSetId = (int)$map['pos_bundle_set_id'];
                    $compStmt2 = $conn->prepare("SELECT component_variation_id, component_qty FROM product_unit_set_items WHERE product_set_id = ?");
                    $compStmt2->execute([$bundleSetId]);
                    
                    foreach ($compStmt2->fetchAll(PDO::FETCH_ASSOC) as $comp) {
                        $compBaseQty = $qtySold * (int)$comp['component_qty'];
                        $compVarId = $comp['component_variation_id'];
                        
                        $conn->prepare("UPDATE inventory SET quantity = quantity - ? WHERE variation_id = ? AND store_id = 1")->execute([$compBaseQty, $compVarId]);
                        
                        $getInv = $conn->prepare("SELECT quantity FROM inventory WHERE variation_id = ? AND store_id = 1");
                        $getInv->execute([$compVarId]);
                        $currStock = (int)$getInv->fetchColumn();
                        $prevStock = $currStock + $compBaseQty;
                        
                        $conn->prepare("INSERT INTO stock_movements (store_id, variation_id, type, quantity, previous_stock, new_stock, reference, remarks, created_by) VALUES (1, ?, 'online_sale', ?, ?, ?, 'Lazada Live Drop', 'Auto-deducted from Lazada API Sync (Bundle)', ?)")
                             ->execute([$compVarId, $compBaseQty, $prevStock, $currStock, $userId]);
                    }
                    
                    $posPhysStock = max(0, $posPhysStock - $qtySold);
                }
            }
            // ------------------------------------------------------
            
            $updated[] = [
                'id' => $mapId,
                'lazada_stock' => $dbAllocation,        // updated live value from DB
                'lazada_live_stock' => $allocatedStock, // same live stock (informational)
                'lazada_price' => $livePrice,
                'stock_allocation_ratio' => (int)($fresh['stock_allocation_ratio'] ?? 0),
                'pos_physical_stock' => $posPhysStock,
                'pos_online_stock' => $posOnlineStock,
                'bundle_total_sets' => $bundleTotalSets,
                'success' => true,
                'changed' => ($oldStock !== $newStock)  // true when Lazada stock changed (e.g. sale happened)
            ];

            if (!$skipLog) {
                // Calculate difference for clearer logging
                $diff = $newStock - $oldStock;
                $diffText = ($diff >= 0) ? "+$diff" : (string)$diff;
                $label = ($diff > 0) ? "Added" : ($diff < 0 ? "Deducted" : "Updated");

                // Log successful sync as a stock_update event
                $logStmt = $conn->prepare("
                    INSERT INTO lazada_sync_logs (event_type, lazada_item_id, product_name, sku, old_value, new_value, status, source, created_by, created_at)
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
                    INSERT INTO lazada_sync_logs (event_type, lazada_item_id, product_name, sku, status, error_message, source, created_by, created_at)
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

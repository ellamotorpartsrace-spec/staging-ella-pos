<?php
/**
 * api/lazada/fetch_mapped_live_stocks.php
 * Fetches live stock/price from Lazada on demand for selected mapping IDs and updates the DB.
 */
header("Content-Type: application/json");
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../classes/LazadaAPI.php';

requireLogin();

$input = json_decode(file_get_contents('php://input'), true);
$ids = $input['ids'] ?? [];
$skipLog = !empty($input['skip_log']);

if (empty($ids) || !is_array($ids)) {
    echo json_encode(['success' => false, 'error' => 'No mapping IDs provided']);
    exit;
}

$platform = $_SESSION['lazada_active_platform'] ?? 'lazada_main';

try {
    $db = new Database();
    $conn = $db->getConnection();

    $stmtConfig = $conn->prepare("SELECT * FROM lazada_config WHERE platform_name = ? LIMIT 1");
    $stmtConfig->execute([$platform]);
    $config = $stmtConfig->fetch(PDO::FETCH_ASSOC);

    if (!$config || empty($config['access_token'])) {
        echo json_encode(['success' => false, 'error' => 'Lazada platform is not authorized.']);
        exit;
    }

    $api = new LazadaAPI(
        $config['app_key'], 
        $config['app_secret'], 
        $config['country_code'], 
        $config['environment'] === 'sandbox'
    );
    $api->setAccessToken($config['access_token']);

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $conn->prepare("SELECT * FROM lazada_product_mappings WHERE id IN ($placeholders) AND platform_name = ?");
    $params = $ids;
    $params[] = $platform;
    $stmt->execute($params);
    $mappings = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($mappings)) {
        echo json_encode(['success' => true, 'updated' => [], 'message' => 'No matching mappings found in the database.']);
        exit;
    }

    // Group mappings by lazada_item_id so we only fetch each product once
    $itemsToFetch = [];
    $mappingBySkuId = [];
    foreach ($mappings as $map) {
        $itemsToFetch[$map['lazada_item_id']] = true;
        $mappingBySkuId[$map['lazada_sku_id']] = $map;
    }

    $updated = [];
    $errors = [];

    foreach (array_keys($itemsToFetch) as $itemId) {
        try {
            $response = $api->call('/product/item/get', ['item_id' => $itemId], 'GET');

            if (!isset($response['code']) || $response['code'] !== '0') {
                throw new Exception($response['message'] ?? 'Unknown API Error');
            }

            $product = $response['data'];
            $skus = $product['skus'] ?? [];

            foreach ($skus as $sku) {
                $skuId = $sku['SkuId'];
                
                if (isset($mappingBySkuId[$skuId])) {
                    $map = $mappingBySkuId[$skuId];
                    $mapId = $map['id'];
                    $oldStock = (int)$map['lazada_stock'];
                    $liveStock = (int)$sku['quantity'];
                    $livePrice = (float)$sku['price'];

                    $upd = $conn->prepare("UPDATE lazada_product_mappings SET lazada_stock = ?, lazada_price = ?, last_synced_at = NOW() WHERE id = ?");
                    $upd->execute([$liveStock, $livePrice, $mapId]);

                    $changed = ($oldStock !== $liveStock);

                    // Fetch POS physical stock and POS online stock for UI update
                    $posPhysStock = 0;
                    $posOnlineStock = 0;
                    $bundleTotalSets = null;
                    $posProductId = $map['pos_product_id'] ?? null;
                    
                    if (!empty($posProductId)) {
                        $invStmt = $conn->prepare("SELECT store_id, quantity FROM inventory WHERE variation_id = ?");
                        $invStmt->execute([$posProductId]);
                        $invRows = $invStmt->fetchAll(PDO::FETCH_ASSOC);
                        foreach ($invRows as $row) {
                            if ((int)$row['store_id'] === 1) {
                                $posPhysStock = (float) $row['quantity'];
                            } elseif ((int)$row['store_id'] === 3) {
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
                                (COALESCE(i1.quantity, 0) + COALESCE(i3.quantity, 0)) AS component_base_qty,
                                COALESCE(res.reserved_base_qty, 0) AS reserved_base_qty
                            FROM product_unit_set_items si
                            LEFT JOIN product_units cu ON cu.id = si.component_unit_id
                            LEFT JOIN inventory i1 ON i1.variation_id = si.component_variation_id AND i1.store_id = 1
                            LEFT JOIN inventory i3 ON i3.variation_id = si.component_variation_id AND i3.store_id = 3
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
                            JOIN product_unit_set_items si ON si.product_set_id = m.pos_bundle_set_id
                            LEFT JOIN product_units cu ON cu.id = si.component_unit_id
                            WHERE m.mapping_status IN ('auto','manual')
                              AND m.id != ?
                            GROUP BY si.component_variation_id
                        ");
                        $otherBundleReserveStmt->execute([$mapId]);
                        $otherBundleReserves = [];
                        foreach ($otherBundleReserveStmt->fetchAll(PDO::FETCH_ASSOC) as $obr) {
                            $otherBundleReserves[$obr['component_variation_id']] = (int)$obr['reserved_base_qty'];
                        }

                        $minSets = null;
                        foreach ($compRows as $comp) {
                            $baseQty = (int)$comp['component_base_qty'];
                            $reservedSimple = (int)$comp['reserved_base_qty'];
                            $reservedOtherBundles = $otherBundleReserves[$comp['component_variation_id']] ?? 0;
                            $requiredPerSet = (int)$comp['component_qty'] * (int)$comp['component_unit_multiplier'];
                            
                            $pairableBase = max(0, $baseQty - $reservedSimple - $reservedOtherBundles);
                            $possibleSets = ($requiredPerSet > 0) ? floor($pairableBase / $requiredPerSet) : 0;
                            
                            if ($minSets === null || $possibleSets < $minSets) {
                                $minSets = $possibleSets;
                            }
                        }
                        $bundleTotalSets = $minSets !== null ? $minSets : 0;
                    }

                    $updated[] = [
                        'id' => $mapId,
                        'changed' => $changed,
                        'lazada_stock' => $liveStock,
                        'lazada_price' => $livePrice,
                        'stock_allocation_ratio' => (int)($map['stock_allocation_ratio'] ?? 0),
                        'pos_physical_stock' => $posPhysStock,
                        'pos_online_stock' => $posOnlineStock,
                        'bundle_total_sets' => $bundleTotalSets
                    ];

                    if ($changed && !$skipLog) {
                        $diff = $liveStock - $oldStock;
                        $diffText = ($diff >= 0) ? "+$diff" : (string)$diff;
                        $label = ($diff > 0) ? "Added" : ($diff < 0 ? "Deducted" : "Updated");

                        $logStmt = $conn->prepare("
                            INSERT INTO lazada_sync_logs (event_type, lazada_item_id, lazada_sku_id, product_name, sku, old_value, new_value, status, source, created_by, created_at)
                            VALUES ('stock_update', ?, ?, ?, ?, ?, ?, 'success', 'Live Sync Manual Fetch', ?, NOW())
                        ");
                        $logStmt->execute([
                            $map['lazada_item_id'],
                            $map['lazada_sku_id'],
                            $map['lazada_product_name'],
                            $map['lazada_seller_sku'],
                            (string)$oldStock,
                            "$liveStock ($diffText $label)",
                            $_SESSION['user_id'] ?? null
                        ]);
                    }

                    // --- NEW AUTO-DEDUCT LOGIC (Bypass sync_orders.php) ---
                    // If the live stock is LOWER than the database stock, it means an order
                    // happened on Lazada. We must immediately deduct the physical POS stock.
                    $newStock = $liveStock;
                    $posProductId = $map['pos_product_id'] ?? null;
                    if ($newStock < $oldStock) {
                        $qtySold = $oldStock - $newStock;
                        $userId = $_SESSION['user_id'] ?? 1;
                        
                        if (!empty($posProductId)) {
                            // Deduct single product
                            // Split deduction between store_id=3 (online) and store_id=1 (physical)
                            $stmtCheck2 = $conn->prepare("SELECT quantity FROM inventory WHERE variation_id = ? AND store_id = 3");
                            $stmtCheck2->execute([$posProductId]);
                            $onlineQty = (int)($stmtCheck2->fetchColumn() ?: 0);
                            
                            $deductOnline = min($qtySold, max(0, $onlineQty));
                            $deductPhysical = $qtySold - $deductOnline;
                            
                            if ($deductOnline > 0) {
                                $conn->prepare("UPDATE inventory SET quantity = quantity - ? WHERE variation_id = ? AND store_id = 3")->execute([$deductOnline, $posProductId]);
                                $conn->prepare("INSERT INTO stock_movements (store_id, variation_id, type, quantity, previous_stock, new_stock, reference, remarks, created_by) VALUES (3, ?, 'online_sale', ?, ?, ?, 'Lazada Live Drop', 'Auto-deducted from Lazada API Sync (Online)', ?)")
                                     ->execute([$posProductId, $deductOnline, $onlineQty, $onlineQty - $deductOnline, $userId]);
                            }
                            
                            if ($deductPhysical > 0) {
                                $stmtCheck1 = $conn->prepare("SELECT quantity FROM inventory WHERE variation_id = ? AND store_id = 1");
                                $stmtCheck1->execute([$posProductId]);
                                $physQty = (int)($stmtCheck1->fetchColumn() ?: 0);
                                
                                $conn->prepare("UPDATE inventory SET quantity = quantity - ? WHERE variation_id = ? AND store_id = 1")->execute([$deductPhysical, $posProductId]);
                                $conn->prepare("INSERT INTO stock_movements (store_id, variation_id, type, quantity, previous_stock, new_stock, reference, remarks, created_by) VALUES (1, ?, 'online_sale', ?, ?, ?, 'Lazada Live Drop', 'Auto-deducted from Lazada API Sync (Physical)', ?)")
                                     ->execute([$posProductId, $deductPhysical, $physQty, $physQty - $deductPhysical, $userId]);
                            }
                        } elseif (!empty($map['pos_bundle_set_id'])) {
                            // Deduct bundle components
                            $bundleSetId = (int)$map['pos_bundle_set_id'];
                            $compStmt2 = $conn->prepare("SELECT component_variation_id, component_qty FROM product_unit_set_items WHERE product_set_id = ?");
                            $compStmt2->execute([$bundleSetId]);
                            
                            foreach ($compStmt2->fetchAll(PDO::FETCH_ASSOC) as $comp) {
                                $compBaseQty = $qtySold * (int)$comp['component_qty'];
                                $compVarId = $comp['component_variation_id'];
                                
                                // Split deduction between store_id=3 (online) and store_id=1 (physical)
                                $stmtCheck2 = $conn->prepare("SELECT quantity FROM inventory WHERE variation_id = ? AND store_id = 3");
                                $stmtCheck2->execute([$compVarId]);
                                $onlineQty = (int)($stmtCheck2->fetchColumn() ?: 0);
                                
                                $deductOnline = min($compBaseQty, max(0, $onlineQty));
                                $deductPhysical = $compBaseQty - $deductOnline;
                                
                                if ($deductOnline > 0) {
                                    $conn->prepare("UPDATE inventory SET quantity = quantity - ? WHERE variation_id = ? AND store_id = 3")->execute([$deductOnline, $compVarId]);
                                    $conn->prepare("INSERT INTO stock_movements (store_id, variation_id, type, quantity, previous_stock, new_stock, reference, remarks, created_by) VALUES (3, ?, 'online_sale', ?, ?, ?, 'Lazada Live Drop', 'Auto-deducted from Lazada API Sync (Bundle Online)', ?)")
                                         ->execute([$compVarId, $deductOnline, $onlineQty, $onlineQty - $deductOnline, $userId]);
                                }
                                
                                if ($deductPhysical > 0) {
                                    $stmtCheck1 = $conn->prepare("SELECT quantity FROM inventory WHERE variation_id = ? AND store_id = 1");
                                    $stmtCheck1->execute([$compVarId]);
                                    $physQty = (int)($stmtCheck1->fetchColumn() ?: 0);
                                    
                                    $conn->prepare("UPDATE inventory SET quantity = quantity - ? WHERE variation_id = ? AND store_id = 1")->execute([$deductPhysical, $compVarId]);
                                    $conn->prepare("INSERT INTO stock_movements (store_id, variation_id, type, quantity, previous_stock, new_stock, reference, remarks, created_by) VALUES (1, ?, 'online_sale', ?, ?, ?, 'Lazada Live Drop', 'Auto-deducted from Lazada API Sync (Bundle Physical)', ?)")
                                         ->execute([$compVarId, $deductPhysical, $physQty, $physQty - $deductPhysical, $userId]);
                                }
                            }
                        }
                    } 
                    // If the live stock is HIGHER than the database stock, it means an order
                    // was CANCELLED on Lazada. We must immediately RESTOCK the physical POS stock.
                    elseif ($newStock > $oldStock) {
                        $qtyRestocked = $newStock - $oldStock;
                        $userId = $_SESSION['user_id'] ?? 1;
                        
                        if (!empty($posProductId)) {
                            // Restock single product
                            $conn->prepare("UPDATE inventory SET quantity = quantity + ? WHERE variation_id = ? AND store_id = 3")->execute([$qtyRestocked, $posProductId]);
                            
                            $stmtCheck2 = $conn->prepare("SELECT quantity FROM inventory WHERE variation_id = ? AND store_id = 3");
                            $stmtCheck2->execute([$posProductId]);
                            $currStock = (int)($stmtCheck2->fetchColumn() ?: 0);
                            $prevStock = $currStock - $qtyRestocked;
                            
                            $conn->prepare("INSERT INTO stock_movements (store_id, variation_id, type, quantity, previous_stock, new_stock, reference, remarks, created_by) VALUES (3, ?, 'online_adjustment', ?, ?, ?, 'Lazada Live Drop', 'Auto-restocked (Lazada Order Cancelled)', ?)")
                                 ->execute([$posProductId, $qtyRestocked, $prevStock, $currStock, $userId]);
                                 
                        } elseif (!empty($map['pos_bundle_set_id'])) {
                            // Restock bundle components
                            $bundleSetId = (int)$map['pos_bundle_set_id'];
                            $compStmt2 = $conn->prepare("SELECT component_variation_id, component_qty FROM product_unit_set_items WHERE product_set_id = ?");
                            $compStmt2->execute([$bundleSetId]);
                            
                            foreach ($compStmt2->fetchAll(PDO::FETCH_ASSOC) as $comp) {
                                $compBaseQty = $qtyRestocked * (int)$comp['component_qty'];
                                $compVarId = $comp['component_variation_id'];
                                
                                $conn->prepare("UPDATE inventory SET quantity = quantity + ? WHERE variation_id = ? AND store_id = 3")->execute([$compBaseQty, $compVarId]);
                                
                                $stmtCheck2 = $conn->prepare("SELECT quantity FROM inventory WHERE variation_id = ? AND store_id = 3");
                                $stmtCheck2->execute([$compVarId]);
                                $currStock = (int)($stmtCheck2->fetchColumn() ?: 0);
                                $prevStock = $currStock - $compBaseQty;
                                
                                $conn->prepare("INSERT INTO stock_movements (store_id, variation_id, type, quantity, previous_stock, new_stock, reference, remarks, created_by) VALUES (3, ?, 'online_adjustment', ?, ?, ?, 'Lazada Live Drop', 'Auto-restocked (Lazada Cancelled Bundle)', ?)")
                                     ->execute([$compVarId, $compBaseQty, $prevStock, $currStock, $userId]);
                            }
                        }
                    }
                    // ------------------------------------------------------
                }
            }
        } catch (Exception $ex) {
            $errors[] = [
                'item_id' => $itemId,
                'error' => $ex->getMessage()
            ];
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

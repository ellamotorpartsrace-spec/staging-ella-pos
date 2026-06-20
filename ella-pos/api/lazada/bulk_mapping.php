<?php
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


$data = json_decode(file_get_contents('php://input'), true);
$action = $data['action'] ?? '';
$mappingIds = $data['mapping_ids'] ?? [];
$posId = $data['pos_id'] ?? null;

if (empty($mappingIds) || !is_array($mappingIds)) {
    echo json_encode(['success' => false, 'error' => 'No items selected']);
    exit;
}

try {
    $db = new Database();
    $conn = $db->getConnection();
    $conn->beginTransaction();

    $userId = $_SESSION['user_id'] ?? null;
    $time = date('Y-m-d H:i:s');

    if ($action === 'link') {
        if (!$posId) throw new Exception("POS ID is required for linking");

        // Fetch POS SKU from product_variations
        $stmt = $conn->prepare("SELECT sku FROM product_variations WHERE variation_id = ?");
        $stmt->execute([$posId]);
        $posSku = $stmt->fetchColumn();
        if (!$posSku) throw new Exception("Invalid POS product (sku empty or invalid variation)");

        // Prepare update
        $fetchStmt = $conn->prepare("SELECT lazada_item_id, lazada_model_id, lazada_product_name, lazada_variation_name, lazada_variation_sku, lazada_parent_sku, matched_pos_sku, lazada_stock FROM lazada_product_mappings WHERE id = ?");
        $updateStmt = $conn->prepare("UPDATE lazada_product_mappings SET pos_product_id = ?, matched_pos_sku = ?, mapping_status = 'manual', updated_at = ? WHERE id = ?");
        $auditStmt = $conn->prepare("INSERT INTO lazada_audit_logs (user_id, action_type, target_type, target_id, new_value, created_at) VALUES (?, 'bulk_link', 'mapping', ?, ?, ?)");

        foreach ($mappingIds as $id) {
            $fetchStmt->execute([$id]);
            $oldMap = $fetchStmt->fetch(PDO::FETCH_ASSOC);
            
            $updateStmt->execute([$posId, $posSku, $time, $id]);
            $auditStmt->execute([$userId, $id, json_encode(['pos_id' => $posId]), $time]);

            if ($oldMap) {
                $prodName = $oldMap['lazada_product_name'];
                if (!empty($oldMap['lazada_variation_name'])) {
                    $prodName .= ' — ' . $oldMap['lazada_variation_name'];
                }
                
                $logStmt = $conn->prepare("
                    INSERT INTO lazada_sync_logs (event_type, lazada_item_id, product_name, sku, old_value, new_value, source, status, created_by, created_at)
                    VALUES ('mapping', ?, ?, ?, ?, ?, 'Bulk Link', 'success', ?, NOW())
                ");
                $lazadaSku = $oldMap['lazada_variation_sku'] ?: $oldMap['lazada_parent_sku'] ?: '—';
                $logStmt->execute([
                    $oldMap['lazada_item_id'],
                    $prodName,
                    $lazadaSku,
                    $oldMap['matched_pos_sku'] ?: 'Unmapped',
                    $posSku,
                    $userId
                ]);

                // Fetch live Lazada stock immediately and propagate to POS
                $liveStock = (int)$oldMap['lazada_stock'];
                
                try {
                    $liveData = fetchLiveLazadaStockAndPrice($conn, $oldMap['lazada_item_id'], $oldMap['lazada_model_id']);
                    $liveStock = $liveData['stock'];
                    
                    // Update cache in DB
                    $conn->prepare("UPDATE lazada_product_mappings SET lazada_stock = ?, last_synced_at = NOW(), updated_at = NOW() WHERE id = ?")
                         ->execute([$liveStock, $id]);
                } catch (Exception $ex) {
                    // Log fetch failure as warning
                    $warnStmt = $conn->prepare("
                        INSERT INTO lazada_sync_logs (event_type, lazada_item_id, product_name, sku, status, error_message, source, created_by, created_at)
                        VALUES ('mapping', ?, ?, ?, 'failed', ?, 'Bulk Live Lazada Fetch Fallback', ?, NOW())
                    ");
                    $warnStmt->execute([
                        $oldMap['lazada_item_id'],
                        $prodName,
                        $lazadaSku,
                        'Failed to fetch live Lazada stock: ' . $ex->getMessage(),
                        $userId
                    ]);
                }

                // Check if this SKU is a shared listing:
                // Either it's already in the whitelist, OR it has an open duplicate_sku error.
                // Bulk-linking a duplicate-SKU item is an implicit "Allow as Shared Listing".
                $isSharedListing = false;
                $checkSkuForWhitelist = $lazadaSku !== '—' ? $lazadaSku : null;
                if (!empty($checkSkuForWhitelist)) {
                    // Check whitelist first
                    $wlStmt = $conn->prepare("SELECT 1 FROM lazada_duplicate_whitelist WHERE sku = ? LIMIT 1");
                    $wlStmt->execute([$checkSkuForWhitelist]);
                    $isSharedListing = (bool)$wlStmt->fetchColumn();

                    if (!$isSharedListing) {
                        // Not yet whitelisted — check if there's an open duplicate_sku error
                        $dupErrStmt = $conn->prepare("SELECT 1 FROM lazada_error_logs WHERE error_type = 'duplicate_sku' AND sku = ? AND status = 'open' LIMIT 1");
                        $dupErrStmt->execute([$checkSkuForWhitelist]);
                        $hasDuplicateError = (bool)$dupErrStmt->fetchColumn();

                        if ($hasDuplicateError) {
                            // Auto-whitelist and resolve the error
                            $conn->exec("CREATE TABLE IF NOT EXISTS lazada_duplicate_whitelist (sku VARCHAR(255) PRIMARY KEY, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
                            $conn->prepare("INSERT IGNORE INTO lazada_duplicate_whitelist (sku) VALUES (?)")
                                 ->execute([$checkSkuForWhitelist]);
                            $conn->prepare("UPDATE lazada_error_logs SET status = 'resolved', resolved_at = NOW() WHERE error_type = 'duplicate_sku' AND sku = ? AND status = 'open'")
                                 ->execute([$checkSkuForWhitelist]);
                            $isSharedListing = true;
                        }
                    }
                }

                if ($isSharedListing) {
                    // Shared listing: preserve the real Lazada stock for display.
                    // DO NOT push to POS inventory — stays at 0 until manually set.
                } else {
                    propagateStockToPos($conn, (int)$posId, $liveStock, $prodName, $lazadaSku, $userId, (int)$id);
                }
            }

        }

    } elseif ($action === 'unlink') {
        // We must fetch the old pos_id before updating so we can Undo it
        $fetchStmt = $conn->prepare("SELECT lazada_item_id, lazada_product_name, lazada_variation_name, lazada_variation_sku, lazada_parent_sku, matched_pos_sku FROM lazada_product_mappings WHERE id = ?");
        $stmtOld = $conn->prepare("SELECT pos_product_id FROM lazada_product_mappings WHERE id = ?");
        
        $updateStmt = $conn->prepare("UPDATE lazada_product_mappings SET pos_product_id = NULL, matched_pos_sku = NULL, mapping_status = 'unmapped', updated_at = ? WHERE id = ?");
        $auditStmt = $conn->prepare("INSERT INTO lazada_audit_logs (user_id, action_type, target_type, target_id, old_value, created_at) VALUES (?, 'bulk_unlink', 'mapping', ?, ?, ?)");

        foreach ($mappingIds as $id) {
            $fetchStmt->execute([$id]);
            $oldMap = $fetchStmt->fetch(PDO::FETCH_ASSOC);

            $stmtOld->execute([$id]);
            $oldPosId = $stmtOld->fetchColumn();
            
            $updateStmt->execute([$time, $id]);
            $auditStmt->execute([$userId, $id, json_encode(['pos_id' => $oldPosId]), $time]);

            if ($oldMap) {
                $prodName = $oldMap['lazada_product_name'];
                if (!empty($oldMap['lazada_variation_name'])) {
                    $prodName .= ' — ' . $oldMap['lazada_variation_name'];
                }
                
                $logStmt = $conn->prepare("
                    INSERT INTO lazada_sync_logs (event_type, lazada_item_id, product_name, sku, old_value, new_value, source, status, created_by, created_at)
                    VALUES ('mapping', ?, ?, ?, ?, 'Unmapped', 'Bulk Unlink', 'success', ?, NOW())
                ");
                $lazadaSku = $oldMap['lazada_variation_sku'] ?: $oldMap['lazada_parent_sku'] ?: '—';
                $logStmt->execute([
                    $oldMap['lazada_item_id'],
                    $prodName,
                    $lazadaSku,
                    $oldMap['matched_pos_sku'] ?: '—',
                    $oldMap['matched_pos_sku'] ?: 'Unmapped',
                    $userId
                ]);

                // Zero out the online inventory allocation and restore physical stock for the unlinked POS product
                if (!empty($oldPosId)) {
                    propagateStockToPos($conn, (int)$oldPosId, 0, $prodName, $lazadaSku, $userId, (int)$id);
                }
            }
        }
    } else {
        throw new Exception("Invalid action");
    }

    $conn->commit();

    // Re-run conflict detection to dynamically update lazada_error_logs in real-time
    require_once __DIR__ . '/detect_conflicts.php';
    runConflictDetection($conn);

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    if (isset($conn)) $conn->rollBack();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

<?php
/**
 * api/shopee/save_mappings.php — Save Shopee product mappings to DB
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
$mappings = $input['mappings'] ?? [];
$trigger = $input['trigger'] ?? 'bulk_save';

if (empty($mappings)) {
    echo json_encode(['success' => false, 'error' => 'No mappings provided']);
    exit;
}

$logSource = 'Bulk Save';
if ($trigger === 'auto_match') {
    $logSource = 'Auto-Match';
} elseif ($trigger === 're_run_auto_match') {
    $logSource = 'Re-run Auto-Match';
} elseif ($trigger === 'manual_link') {
    $logSource = 'Manual Link';
} elseif ($trigger === 'unlink') {
    $logSource = 'Manual Unlink';
}

try {
    $db = new Database();
    $conn = $db->getConnection();
    
    // Ensure table exists before beginning transaction to avoid implicit commits
    $conn->exec("CREATE TABLE IF NOT EXISTS shopee_duplicate_whitelist (sku VARCHAR(255) PRIMARY KEY, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
    
    $conn->beginTransaction();

    $oldStmt = $conn->prepare("SELECT shopee_item_id, shopee_model_id, shopee_product_name, shopee_variation_name, shopee_variation_sku, shopee_parent_sku, matched_pos_sku, pos_product_id, mapping_status, shopee_stock FROM shopee_product_mappings WHERE id = ?");

    $autoMatchedCount = 0; // Counter for items auto-matched in this operation
    $stmt = $conn->prepare("UPDATE shopee_product_mappings SET 
        matched_pos_sku = ?, 
        pos_product_id = ?, 
        mapping_status = ?, 
        updated_at = NOW() 
        WHERE id = ?");

    foreach ($mappings as $map) {
        $oldStmt->execute([$map['id']]);
        $oldMap = $oldStmt->fetch(PDO::FETCH_ASSOC);

        $hasChanged = false;
        if ($oldMap) {
            $oldSku = $oldMap['matched_pos_sku'];
            $oldPosId = $oldMap['pos_product_id'];
            $oldStatus = $oldMap['mapping_status'];

            $newSku = $map['posSku'] ?? null;
            $newPosId = $map['posId'] ?? null;
            $newStatus = $map['status'] ?? null;

            $oldSkuNorm = $oldSku !== null ? trim((string)$oldSku) : '';
            $newSkuNorm = $newSku !== null ? trim((string)$newSku) : '';
            $oldPosIdNorm = $oldPosId ? (int)$oldPosId : 0;
            $newPosIdNorm = $newPosId ? (int)$newPosId : 0;

            if ($oldSkuNorm !== $newSkuNorm || $oldPosIdNorm !== $newPosIdNorm || $oldStatus !== $newStatus) {
                $hasChanged = true;
            }
        }

        $stmt->execute([
            $map['posSku'],
            $map['posId'],
            $map['status'],
            $map['id']
        ]);

        if ($hasChanged && $oldMap) {
            $prodName = $oldMap['shopee_product_name'];
            if (!empty($oldMap['shopee_variation_name'])) {
                $prodName .= ' — ' . $oldMap['shopee_variation_name'];
            }
            $skuVal = $oldMap['shopee_variation_sku'] ?: $oldMap['shopee_parent_sku'] ?: '—';
            $oldValLog = $oldMap['matched_pos_sku'] ?: 'Unmapped';
            $newValLog = $map['posSku'] ?: 'Unmapped';
            $newStatus = $map['status'] ?? null;
            $newPosId = $map['posId'] ?? null;

            // Determine if this is an auto-match operation
            $isAutoMatchOperation = ($trigger === 'auto_match' || $trigger === 're_run_auto_match');

            if ($isAutoMatchOperation && $newStatus === 'auto') {
                $autoMatchedCount++;
                // Skip individual logging for auto-match here; a summary will be logged later
            } else {
                // Log individual mapping changes for other triggers or non-auto-matched items within bulk_save
                $currentMapSource = $logSource; // Use the general source for this mapping
                if ($trigger === 'bulk_save') { // Refine source for individual items within a bulk_save
                    if ($map['status'] === 'unmapped') {
                        $currentMapSource = 'Manual Unlink (Bulk)';
                    } elseif ($map['status'] === 'manual') {
                        $currentMapSource = 'Manual Link (Bulk)';
                    }
                }

                $logStmt = $conn->prepare("
                    INSERT INTO shopee_sync_logs (event_type, shopee_item_id, product_name, sku, old_value, new_value, source, status, created_by, created_at)
                    VALUES ('mapping', ?, ?, ?, ?, ?, ?, 'success', ?, NOW())
                ");
                $logStmt->execute([
                    $oldMap['shopee_item_id'],
                    $prodName,
                    $skuVal,
                    $oldValLog,
                    $newValLog,
                    $currentMapSource,
                    $_SESSION['user_id'] ?? null
                ]);
            }

            // Propagate live Shopee stock and price back into local POS tables immediately
            if (($newStatus === 'manual' || $newStatus === 'auto') && !empty($newPosId)) {
                if (!empty($oldPosId) && $oldPosId != $newPosId) {
                    // Item was switched to a new POS product. Zero out online stock on the OLD product first.
                    propagateStockToPos($conn, (int)$oldPosId, 0, $prodName, $skuVal, $_SESSION['user_id'] ?? null, null);
                }

                $liveStock = (int)$oldMap['shopee_stock'];
                
                // PERFORMANCE OPTIMIZATION: 
                // Only fetch live stock from Shopee API if it's a single manual link.
                // For bulk operations (auto-match), use cached DB values to prevent timeouts and API rate limits.
                $shouldFetchLive = !in_array($trigger, ['bulk_save', 'auto_match', 're_run_auto_match']);

                if ($shouldFetchLive) {
                    try {
                        // Instantly query Shopee API for the true live stock
                        $liveData = fetchLiveShopeeStockAndPrice($conn, $oldMap['shopee_item_id'], $oldMap['shopee_model_id']);
                        $liveStock = $liveData['stock'];
                        
                        // Update DB mapping cache with the live values we just fetched
                        $updCache = $conn->prepare("UPDATE shopee_product_mappings SET shopee_stock = ?, last_synced_at = NOW(), updated_at = NOW() WHERE id = ?");
                        $updCache->execute([$liveStock, $map['id']]);
                    } catch (Exception $liveEx) {
                        // Log the fetch failure as a warning but continue with cached values so the mapping itself is not blocked
                        $warnStmt = $conn->prepare("
                            INSERT INTO shopee_sync_logs (event_type, shopee_item_id, product_name, sku, status, error_message, source, created_by, created_at)
                            VALUES ('mapping', ?, ?, ?, 'failed', ?, 'Live Shopee Fetch Fallback', ?, NOW())
                        ");
                        $warnStmt->execute([
                            $oldMap['shopee_item_id'],
                            $prodName,
                            $skuVal,
                            'Failed to fetch live Shopee stock: ' . $liveEx->getMessage(),
                            $_SESSION['user_id'] ?? null
                        ]);
                    }
                }

                // Check if this SKU is a shared listing:
                // Either it's already in the whitelist, OR it has an open duplicate_sku error.
                // If a user manually maps a duplicate-SKU item, we treat it the same as
                // "Allow as Shared Listing" — auto-whitelist it and resolve the error.
                $isSharedListing = false;
                $checkSkuForWhitelist = $oldMap['shopee_variation_sku'] ?: $oldMap['shopee_parent_sku'] ?: null;
                if (!empty($checkSkuForWhitelist)) {
                    // Check whitelist first
                    $wlStmt = $conn->prepare("SELECT 1 FROM shopee_duplicate_whitelist WHERE sku = ? LIMIT 1");
                    $wlStmt->execute([$checkSkuForWhitelist]);
                    $isSharedListing = (bool)$wlStmt->fetchColumn();

                    if (!$isSharedListing) {
                        // Not yet whitelisted — check if there's an open duplicate_sku error for this SKU
                        $dupErrStmt = $conn->prepare("SELECT 1 FROM shopee_error_logs WHERE error_type = 'duplicate_sku' AND sku = ? AND status = 'open' LIMIT 1");
                        $dupErrStmt->execute([$checkSkuForWhitelist]);
                        $hasDuplicateError = (bool)$dupErrStmt->fetchColumn();

                        if ($hasDuplicateError && $newStatus === 'manual') {
                            // Auto-whitelist it — manual mapping of a duplicate SKU is an implicit "Allow as Shared Listing"
                            $conn->prepare("INSERT IGNORE INTO shopee_duplicate_whitelist (sku) VALUES (?)")
                                 ->execute([$checkSkuForWhitelist]);

                            // Resolve the open error so it disappears from the Resolution Center
                            $conn->prepare("UPDATE shopee_error_logs SET status = 'resolved', resolved_at = NOW() WHERE error_type = 'duplicate_sku' AND sku = ? AND status = 'open'")
                                 ->execute([$checkSkuForWhitelist]);

                            $isSharedListing = true;
                        }
                    }
                }

                if ($isSharedListing) {
                    // Shared listing: preserve the real Shopee stock value for display
                    // on the Allocation page. DO NOT push it to POS inventory —
                    // the allocation must stay at 0 until the user sets it manually.
                } else {
                    propagateStockToPos($conn, (int)$newPosId, $liveStock, $prodName, $skuVal, $_SESSION['user_id'] ?? null, (int)$map['id']);
                }
            } elseif ($newStatus === 'unmapped' && !empty($oldPosId)) {
                // Zero out the online allocation for the old POS product and restore physical stock to total.
                // Pass stock=0 so propagateStockToPos knows there is no longer any online allocation for this mapping.
                propagateStockToPos($conn, (int)$oldPosId, 0, $prodName, $skuVal, $_SESSION['user_id'] ?? null, (int)$map['id']);
            }
        }

    }

    $conn->commit();

    // Consolidated logging for auto-match operations
    if (($trigger === 'auto_match' || $trigger === 're_run_auto_match') && $autoMatchedCount > 0) {
        $summaryMessage = "Automatically linked {$autoMatchedCount} items to POS products based on matching SKUs.";
        $logStmt = $conn->prepare("
            INSERT INTO shopee_sync_logs (event_type, shopee_item_id, product_name, sku, old_value, new_value, source, status, created_by, created_at)
            VALUES ('mapping', NULL, ?, ?, ?, ?, ?, 'success', ?, NOW())
        ");
        $logStmt->execute([
            'Bulk Auto-Match', // product_name
            '—', // sku
            'Unmapped items', // old_value
            $summaryMessage, // new_value
            $logSource, // source (e.g., 'Auto-Match' or 'Re-run Auto-Match')
            $_SESSION['user_id'] ?? null
        ]);
    }

    // Re-run conflict detection to dynamically update shopee_error_logs in real-time
    require_once __DIR__ . '/detect_conflicts.php';
    runConflictDetection($conn);

    echo json_encode(['success' => true, 'message' => count($mappings) . ' mappings saved successfully']);

} catch (Exception $e) {
    if (isset($conn)) $conn->rollBack();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

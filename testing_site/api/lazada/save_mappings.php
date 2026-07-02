<?php
header("Content-Type: application/json");
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';

requireLogin();
require_once __DIR__ . '/sync_helpers.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$platform = $_SESSION['lazada_active_platform'] ?? 'lazada_main';

try {
    $db = new Database();
    $conn = $db->getConnection();

    $input = json_decode(file_get_contents('php://input'), true);
    if (!isset($input['mappings']) || !is_array($input['mappings'])) {
        echo json_encode(['success' => false, 'error' => 'Invalid data']);
        exit;
    }

    $trigger = $input['trigger'] ?? 'manual_link';
    $logSource = 'Manual Mapping';
    if ($trigger === 'auto_match') {
        $logSource = 'Auto-Match';
    } elseif ($trigger === 're_run_auto_match') {
        $logSource = 'Re-run Auto-Match';
    } elseif ($trigger === 'manual_link') {
        $logSource = 'Manual Link';
    } elseif ($trigger === 'unlink') {
        $logSource = 'Manual Unlink';
    }

    $conn->beginTransaction();

    $stmt = $conn->prepare("
        UPDATE lazada_product_mappings 
        SET pos_product_id = ?, 
            pos_unit_id = ?, 
            pos_bundle_set_id = ?,
            mapping_status = ?,
            matched_pos_sku = ?,
            stock_allocation_ratio = ?
        WHERE id = ? AND platform_name = ?
    ");

    $count = 0;
    foreach ($input['mappings'] as $m) {
        $id = (int)$m['id'];
        $posId = isset($m['posId']) && $m['posId'] !== '' ? (int)$m['posId'] : null;
        $posUnitId = isset($m['posUnitId']) && $m['posUnitId'] !== '' ? (int)$m['posUnitId'] : null;
        $posBundleSetId = isset($m['posBundleSetId']) && $m['posBundleSetId'] !== '' ? (int)$m['posBundleSetId'] : null;
        $status = $m['status'] ?? 'manual';
        $posSku = $m['posSku'] ?? null;

        // Fetch current lazada stock and pos stock to calculate the ratio so we preserve the old stock
        $fetchMap = $conn->prepare("SELECT lazada_stock, pos_product_id, lazada_item_id, lazada_sku_id, lazada_product_name, lazada_seller_sku, matched_pos_sku FROM lazada_product_mappings WHERE id = ?");
        $fetchMap->execute([$id]);
        $oldMap = $fetchMap->fetch(PDO::FETCH_ASSOC);
        $lazStock = (int)($oldMap['lazada_stock'] ?? 0);
        $oldPosId = (int)($oldMap['pos_product_id'] ?? 0);

        $posStock = 0;
        if ($posBundleSetId) {
            // Bundle stock calculation is complex, default to 100% or calculate base stock
            $ratio = 100.00; 
        } else if ($posId) {
            $invStmt = $conn->prepare("SELECT SUM(quantity) FROM inventory WHERE variation_id = ?");
            $invStmt->execute([$posId]);
            $posStock = (float)$invStmt->fetchColumn();

            // Calculate multiplier
            $multiplier = 1;
            if ($posUnitId) {
                $unitStmt = $conn->prepare("SELECT multiplier FROM product_units WHERE id = ?");
                $unitStmt->execute([$posUnitId]);
                $multiplier = (int)$unitStmt->fetchColumn() ?: 1;
            }

            $totalBaseStock = $posStock;
            $totalUnitStock = floor($totalBaseStock / $multiplier);

            if ($totalUnitStock > 0) {
                $ratio = ($lazStock / $totalUnitStock) * 100;
                if ($ratio > 100) $ratio = 100;
                if ($ratio < 0) $ratio = 0;
            } else {
                $ratio = 100.00;
            }
        } else {
            $ratio = 100.00;
        }

        $stmt->execute([
            $posId,
            $posUnitId,
            $posBundleSetId,
            $status,
            $posSku,
            round($ratio, 2),
            $id,
            $platform
        ]);
        
        if ($status === 'unmapped') {
            if ($oldPosId) {
                propagateStockToPos($conn, $oldPosId, 0, $id);
            }
        } else {
            if ($posId) {
                if ($oldPosId && $oldPosId !== (int)$posId) {
                    propagateStockToPos($conn, $oldPosId, 0, null);
                }
                propagateStockToPos($conn, $posId, $lazStock, $id);
            }
        }
        
        $oldValLog = $oldMap['matched_pos_sku'] ?: 'Unmapped';
        $newValLog = $posSku ?: 'Unmapped';
        
        $currentMapSource = $logSource;
        if ($trigger === 'bulk_save') {
            if ($status === 'unmapped') {
                $currentMapSource = 'Manual Unlink (Bulk)';
            } elseif ($status === 'manual') {
                $currentMapSource = 'Manual Link (Bulk)';
            }
        }

        $logSync = $conn->prepare("INSERT INTO lazada_sync_logs (platform_name, event_type, lazada_item_id, lazada_sku_id, product_name, sku, old_value, new_value, status, source, created_by, created_at) VALUES (?, 'mapping', ?, ?, ?, ?, ?, ?, 'success', ?, ?, NOW())");
        $logSync->execute([
            $platform, 
            $oldMap['lazada_item_id'], 
            $oldMap['lazada_sku_id'], 
            $oldMap['lazada_product_name'], 
            $oldMap['lazada_seller_sku'], 
            $oldValLog,
            $newValLog,
            $currentMapSource,
            $_SESSION['user_id'] ?? null
        ]);
        
        $count++;
    }

    $conn->commit();
    echo json_encode(['success' => true, 'count' => $count]);

} catch (Exception $e) {
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

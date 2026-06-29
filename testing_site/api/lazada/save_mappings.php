<?php
header("Content-Type: application/json");
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';

requireLogin();

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
        $fetchMap = $conn->prepare("SELECT lazada_stock FROM lazada_product_mappings WHERE id = ?");
        $fetchMap->execute([$id]);
        $lazStock = (int)$fetchMap->fetchColumn();

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

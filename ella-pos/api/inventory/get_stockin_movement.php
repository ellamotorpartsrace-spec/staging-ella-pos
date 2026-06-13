<?php
// api/inventory/get_stockin_movement.php - Get a single stock-in movement for editing
header("Content-Type: application/json");
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/stockin_adjustment_log.php';

requireLogin();

// Admin only
if (!in_array($_SESSION['role'], ['admin', 'super_admin'])) {
    echo json_encode(['success' => false, 'error' => 'Permission denied']);
    exit;
}

$movement_id = $_GET['movement_id'] ?? null;

if (!$movement_id) {
    echo json_encode(['success' => false, 'error' => 'No movement ID provided']);
    exit;
}

try {
    $db = new Database();
    $conn = $db->getConnection();
    ensureStockinAdjustmentLogTable($conn);

    $stmt = $conn->prepare("
        SELECT 
            sm.movement_id,
            sm.variation_id,
            sm.type,
            sm.quantity,
            sm.previous_stock,
            sm.new_stock,
            sm.reference,
            sm.remarks,
            sm.created_at,
            COALESCE(sm.capital_cost, pv.price_capital) as capital_cost,
            pv.variation_name,
            pv.sku,
            pv.barcode,
            pv.unit_type,
            p.product_name,
            p.brand_name,
            p.image_path,
            u.full_name as created_by_name,
            COALESCE(i.quantity, 0) as current_inventory_stock
        FROM stock_movements sm
        JOIN product_variations pv ON sm.variation_id = pv.variation_id
        JOIN products p ON pv.product_id = p.product_id
        LEFT JOIN users u ON sm.created_by = u.id
        LEFT JOIN inventory i ON sm.variation_id = i.variation_id AND i.store_id = 1
        WHERE sm.movement_id = ? AND sm.type = 'stock_in'
    ");
    $stmt->execute([$movement_id]);
    $movement = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$movement) {
        echo json_encode(['success' => false, 'error' => 'Stock-in record not found']);
        exit;
    }

    // Get any previous adjustments for this movement
    $stmtAdj = $conn->prepare("
        SELECT sal.*, u.full_name as adjusted_by_name
        FROM stockin_adjustment_log sal
        LEFT JOIN users u ON sal.adjusted_by = u.id
        WHERE sal.movement_id = ?
        ORDER BY sal.adjusted_at DESC
    ");
    $stmtAdj->execute([$movement_id]);
    $adjustments = $stmtAdj->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'movement' => $movement,
        'adjustments' => $adjustments
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

<?php
/**
 * api/lazada/get_lazada_items_by_sku.php — Fetch Lazada items sharing a specific SKU
 */
header("Content-Type: application/json");
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';

requireLogin();

if (!hasPermission('lazada_sync')) {
    echo json_encode(['success' => false, 'error' => 'Permission denied. Please request access from admin Les.']);
    exit;
}

$sku = $_GET['sku'] ?? '';
if (empty($sku)) {
    echo json_encode(['success' => false, 'error' => 'Missing SKU']);
    exit;
}

try {
    $db = new Database();
    $conn = $db->getConnection();
    
    $stmt = $conn->prepare("
        SELECT id, lazada_item_id, lazada_model_id, lazada_product_name, lazada_variation_name, 
               lazada_parent_sku, lazada_variation_sku, has_variation, lazada_stock, lazada_price
        FROM lazada_product_mappings
        WHERE (has_variation = 0 AND lazada_parent_sku = ?)
           OR (has_variation = 1 AND lazada_variation_sku = ?)
    ");
    $stmt->execute([$sku, $sku]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['success' => true, 'items' => $items]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

<?php
/**
 * api/lazada/get_dashboard_stats.php — Returns Lazada stats for the dashboard UI
 */
header("Content-Type: application/json");
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';

requireLogin();

try {
    $db = new Database();
    $conn = $db->getConnection();

    // 1. Config Check
    $stmtConfig = $conn->query("SELECT * FROM lazada_config WHERE is_active = 1 LIMIT 1");
    $config = $stmtConfig->fetch(PDO::FETCH_ASSOC);

    // 2. Mappings & Products Stats
    $stmtStats = $conn->query("SELECT 
        COUNT(DISTINCT lazada_item_id) as total_products,
        COUNT(id) as total_variations,
        SUM(CASE WHEN mapping_status IN ('auto', 'manual') AND pos_product_id IS NOT NULL THEN 1 ELSE 0 END) as mapped_items,
        SUM(CASE WHEN mapping_status = 'unmapped' OR pos_product_id IS NULL THEN 1 ELSE 0 END) as unmapped_items,
        SUM(CASE WHEN stock_allocation_ratio > 0 THEN 1 ELSE 0 END) as allocated_items,
        SUM(CASE WHEN lazada_stock = 0 THEN 1 ELSE 0 END) as out_of_stock,
        SUM(CASE WHEN lazada_stock > 0 AND lazada_stock <= safety_floor THEN 1 ELSE 0 END) as low_stock
    FROM lazada_product_mappings");
    
    $stats = $stmtStats->fetch(PDO::FETCH_ASSOC);

    // If table is empty, stats will be null, so default to 0
    foreach ($stats as $key => $val) {
        $stats[$key] = (int)$val;
    }

    echo json_encode([
        'success' => true,
        'config' => [
            'is_configured' => !empty($config['app_key']) && !empty($config['app_secret']),
            'has_token' => !empty($config['access_token']),
            'token_expires_at' => $config['token_expires_at'] ?? null,
            'last_sync' => 'Never' // TODO: add sync logs table integration
        ],
        'stats' => $stats
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}

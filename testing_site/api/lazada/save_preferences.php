<?php
/**
 * api/lazada/save_preferences.php — Save Lazada sync preferences
 */
header("Content-Type: application/json");
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';

requireLogin();
requireRole(['admin', 'super_admin']);

try {
    $db = new Database();
    $conn = $db->getConnection();

    $data = json_decode(file_get_contents('php://input'), true);

    $enableSync = isset($data['enable_stock_sync']) ? (int)$data['enable_stock_sync'] : 0;
    $respectAlloc = isset($data['respect_allocation']) ? (int)$data['respect_allocation'] : 1;
    $lowStockAlerts = isset($data['low_stock_alerts']) ? (int)$data['low_stock_alerts'] : 1;
    $syncInterval = isset($data['sync_interval_mins']) ? (int)$data['sync_interval_mins'] : 15;
    $lowStockThreshold = isset($data['low_stock_threshold']) ? (int)$data['low_stock_threshold'] : 5;

    $bufferStock = isset($data['buffer_stock']) ? (int)$data['buffer_stock'] : 0;
    
    // Pricing Rules
    $syncPrices = isset($data['sync_prices']) ? (int)$data['sync_prices'] : 0;
    $priceMarkupPercent = isset($data['price_markup_percent']) ? (float)$data['price_markup_percent'] : 0.00;

    $stmt = $conn->query("SELECT id FROM lazada_config LIMIT 1");
    $exists = $stmt->fetchColumn();

    if ($exists) {
        $update = $conn->prepare("UPDATE lazada_config SET 
            enable_stock_sync = ?, 
            respect_allocation = ?, 
            low_stock_alerts = ?, 
            sync_interval_mins = ?, 
            low_stock_threshold = ?, 
            buffer_stock = ?,
            sync_prices = ?,
            price_markup_percent = ?,
            updated_at = NOW() 
            WHERE id = ?");
        $update->execute([$enableSync, $respectAlloc, $lowStockAlerts, $syncInterval, $lowStockThreshold, $bufferStock, $syncPrices, $priceMarkupPercent, $exists]);
    } else {
        $insert = $conn->prepare("INSERT INTO lazada_config 
            (app_key, app_secret, enable_stock_sync, respect_allocation, low_stock_alerts, sync_interval_mins, low_stock_threshold, buffer_stock, sync_prices, price_markup_percent) 
            VALUES ('', '', ?, ?, ?, ?, ?, ?, ?, ?)");
        $insert->execute([$enableSync, $respectAlloc, $lowStockAlerts, $syncInterval, $lowStockThreshold, $bufferStock, $syncPrices, $priceMarkupPercent]);
    }

    echo json_encode(['success' => true, 'message' => 'Preferences saved successfully.']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}

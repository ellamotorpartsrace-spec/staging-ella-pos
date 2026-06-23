<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
require 'config/database.php';

$db = new Database();
$conn = $db->getConnection();
$varId = 6192;

$out = [];
$out['time_php'] = date('Y-m-d H:i:s');
$out['time_mysql'] = $conn->query("SELECT NOW()")->fetchColumn();

// 1. All movements for 6192
$stmt = $conn->query("SELECT movement_id, created_at, type, quantity, previous_stock, new_stock, remarks, reference FROM stock_movements WHERE variation_id = $varId ORDER BY created_at DESC LIMIT 10");
$out['movements'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 2. Affected check
$stmt = $conn->query("
    SELECT COUNT(*) FROM stock_movements
    WHERE variation_id = $varId
      AND store_id = 1
      AND type = 'adjustment'
      AND remarks LIKE 'System Restore%'
      AND DATE(created_at) = DATE(NOW())
");
$out['is_affected'] = $stmt->fetchColumn();

// 3. Last valid check
$stmt = $conn->query("
    SELECT new_stock, created_at, movement_id, type, remarks
    FROM stock_movements
    WHERE variation_id = $varId
      AND store_id = 1
      AND NOT (
            type = 'adjustment'
            AND DATE(created_at) = DATE(NOW())
            AND (remarks LIKE 'System Restore%' OR remarks LIKE 'Stock Repair%')
          )
    ORDER BY created_at DESC
    LIMIT 1
");
$out['last_valid'] = $stmt->fetch(PDO::FETCH_ASSOC);

// 4. Mappings
$stmt = $conn->query("SELECT id, shopee_item_id, shopee_stock, mapping_status, pos_product_id, matched_pos_sku, stock_allocation_ratio FROM shopee_product_mappings WHERE pos_product_id = $varId OR matched_pos_sku = (SELECT sku FROM product_variations WHERE variation_id = $varId)");
$out['mappings'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 5. Inventory
$stmt = $conn->query("SELECT store_id, quantity FROM inventory WHERE variation_id = $varId");
$out['inventory'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

header('Content-Type: application/json');
echo json_encode($out, JSON_PRETTY_PRINT);

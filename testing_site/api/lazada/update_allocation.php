<?php
/**
 * api/lazada/update_allocation.php
 * Updates the stock allocation ratio and safety floor for a mapped product.
 * Multi-account aware.
 */
require_once '../../config/config.php';
require_once '../../config/database.php';

header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$platform = $_SESSION['lazada_active_platform'] ?? 'lazada_main';

$mapping_id = $_POST['mapping_id'] ?? null;
$ratio = $_POST['ratio'] ?? null;
$floor = $_POST['floor'] ?? null;

if (!$mapping_id || $ratio === null || $floor === null) {
    echo json_encode(['success' => false, 'error' => 'Missing required fields.']);
    exit;
}

try {
    $db = new Database();
    $conn = $db->getConnection();

    // Verify ownership
    $check = $conn->prepare("SELECT id FROM lazada_product_mappings WHERE id = ? AND platform_name = ?");
    $check->execute([$mapping_id, $platform]);
    if (!$check->fetch()) {
        echo json_encode(['success' => false, 'error' => 'Mapping not found for the active platform.']);
        exit;
    }

    $fetch = $conn->prepare("SELECT stock_allocation_ratio, safety_floor, lazada_item_id, lazada_sku_id, lazada_product_name, lazada_seller_sku FROM lazada_product_mappings WHERE id = ?");
    $fetch->execute([$mapping_id]);
    $old = $fetch->fetch(PDO::FETCH_ASSOC);

    $upd = $conn->prepare("
        UPDATE lazada_product_mappings 
        SET stock_allocation_ratio = ?, safety_floor = ? 
        WHERE id = ?
    ");
    $upd->execute([$ratio, $floor, $mapping_id]);

    if ($old) {
        $oldVal = "Ratio: {$old['stock_allocation_ratio']}%, Floor: {$old['safety_floor']}";
        $newVal = "Ratio: {$ratio}%, Floor: {$floor}";
        
        $logSync = $conn->prepare("INSERT INTO lazada_sync_logs (platform_name, event_type, lazada_item_id, lazada_sku_id, product_name, sku, old_value, new_value, status, source, created_by, created_at) VALUES (?, 'allocation_update', ?, ?, ?, ?, ?, ?, 'success', 'Update Allocation', ?, NOW())");
        $logSync->execute([$platform, $old['lazada_item_id'], $old['lazada_sku_id'], $old['lazada_product_name'], $old['lazada_seller_sku'], $oldVal, $newVal, $_SESSION['user_id'] ?? null]);
    }

    echo json_encode(['success' => true, 'message' => 'Allocation rules updated.']);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

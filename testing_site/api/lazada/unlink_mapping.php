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
    if (!isset($input['id'])) {
        echo json_encode(['success' => false, 'error' => 'Missing ID']);
        exit;
    }

    $stmt = $conn->prepare("
        UPDATE lazada_product_mappings 
        SET pos_product_id = NULL, 
            pos_unit_id = NULL, 
            pos_bundle_set_id = NULL,
            mapping_status = 'unmapped',
            matched_pos_sku = NULL
        WHERE id = ? AND platform_name = ?
    ");

    $fetch = $conn->prepare("SELECT lazada_item_id, lazada_sku_id, lazada_product_name, lazada_seller_sku FROM lazada_product_mappings WHERE id = ?");
    $fetch->execute([(int)$input['id']]);
    $old = $fetch->fetch(PDO::FETCH_ASSOC);

    $stmt->execute([(int)$input['id'], $platform]);

    if ($old) {
        $logSync = $conn->prepare("INSERT INTO lazada_sync_logs (platform_name, event_type, lazada_item_id, lazada_sku_id, product_name, sku, old_value, new_value, status, source, created_by, created_at) VALUES (?, 'mapping', ?, ?, ?, ?, 'Mapped', 'Unmapped', 'success', 'Manual Unlink', ?, NOW())");
        $logSync->execute([$platform, $old['lazada_item_id'], $old['lazada_sku_id'], $old['lazada_product_name'], $old['lazada_seller_sku'], $_SESSION['user_id'] ?? null]);
    }

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

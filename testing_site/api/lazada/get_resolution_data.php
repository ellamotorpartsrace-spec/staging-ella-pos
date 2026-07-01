<?php
/**
 * api/lazada/get_resolution_data.php — Returns unmapped Lazada items for the Resolution Center
 */
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

    // Fetch unmapped items
    $stmt = $conn->prepare("
        SELECT 
            id,
            lazada_item_id,
            lazada_sku_id,
            lazada_product_name,
            lazada_variation_name,
            lazada_seller_sku,
            lazada_price,
            lazada_image_url
        FROM lazada_product_mappings
        WHERE platform_name = ? 
          AND mapping_status = 'unmapped'
        ORDER BY created_at DESC
    ");
    $stmt->execute([$platform]);
    $missing_skus = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'missing_skus' => $missing_skus
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}

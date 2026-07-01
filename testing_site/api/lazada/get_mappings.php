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

    $stmt = $conn->prepare("
        SELECT
            m.*,
            u.unit_name as pos_unit_name,
            u.multiplier as pos_unit_multiplier,
            s.set_name as pos_bundle_name,
            s.set_sku as pos_bundle_sku
        FROM lazada_product_mappings m
        LEFT JOIN product_units u ON m.pos_unit_id = u.id
        LEFT JOIN product_unit_sets s ON m.pos_bundle_set_id = s.id
        WHERE m.platform_name = ?
        ORDER BY m.lazada_product_name ASC, m.lazada_variation_name ASC
    ");
    $stmt->execute([$platform]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $groups = [];
    foreach ($rows as $r) {
        $iid = $r['lazada_item_id'];
        if (!isset($groups[$iid])) {
            $groups[$iid] = [
                'itemId' => $iid,
                'name' => $r['lazada_product_name'],
                'parentSku' => $r['lazada_seller_sku'] ?? '',
                'imageUrl' => $r['lazada_image_url'] ?? '',
                'variations' => []
            ];
        }
        $groups[$iid]['variations'][] = [
            'id' => (int)$r['id'],
            'varName' => $r['lazada_variation_name'] ?? '',
            'parentSku' => $r['lazada_seller_sku'] ?? '',
            'variationSku' => $r['lazada_seller_sku'] ?? '', // Lazada uses SellerSku
            'hasVariation' => !empty($r['lazada_variation_name']),
            'mapped' => in_array($r['mapping_status'], ['auto', 'manual']),
            'posId' => $r['pos_product_id'] ? (int)$r['pos_product_id'] : null,
            'posUnitId' => $r['pos_unit_id'] ? (int)$r['pos_unit_id'] : null,
            'posBundleSetId' => $r['pos_bundle_set_id'] ? (int)$r['pos_bundle_set_id'] : null,
            'posBundleName' => $r['pos_bundle_name'] ?? '',
            'posBundleSku' => $r['pos_bundle_sku'] ?? '',
            'posUnitName' => $r['pos_unit_name'] ?? '',
            'posUnitMultiplier' => $r['pos_unit_multiplier'] ? (int)$r['pos_unit_multiplier'] : 1,
            'mapStatus' => $r['mapping_status'],
            'matchedPosSku' => $r['matched_pos_sku'] ?? null,
            'online' => (int)$r['lazada_stock'],
        ];
    }

    $json = json_encode([
        'groups' => array_values($groups),
        'whitelist' => [] // No whitelist feature for Lazada yet
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

    if ($json === false) {
        echo json_encode(['error' => 'Failed to encode JSON: ' . json_last_error_msg()]);
    } else {
        echo $json;
    }

} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}

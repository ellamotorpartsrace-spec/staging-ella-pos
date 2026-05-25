<?php
header("Content-Type: application/json");
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';

requireLogin();

if (!hasPermission('shopee_sync')) {
    echo json_encode(['success' => false]);
    exit;
}

try {
    $db = new Database();
    $conn = $db->getConnection();

    $rows = $conn->query("SELECT * FROM shopee_product_mappings ORDER BY shopee_product_name ASC, shopee_variation_name ASC")->fetchAll(PDO::FETCH_ASSOC);

    $groups = [];
    foreach ($rows as $r) {
        $iid = $r['shopee_item_id'];
        if (!isset($groups[$iid])) {
            $groups[$iid] = [
                'itemId' => $iid,
                'name' => $r['shopee_product_name'],
                'parentSku' => $r['shopee_parent_sku'] ?? '',
                'imageUrl' => $r['shopee_image_url'] ?? '',
                'variations' => []
            ];
        }
        $groups[$iid]['variations'][] = [
            'id' => (int)$r['id'],
            'varName' => $r['shopee_variation_name'] ?? '',
            'parentSku' => $r['shopee_parent_sku'] ?? '',
            'variationSku' => $r['shopee_variation_sku'] ?? '',
            'hasVariation' => (bool)$r['has_variation'],
            'mapped' => in_array($r['mapping_status'], ['auto', 'manual']),
            'posId' => $r['pos_product_id'] ? (int)$r['pos_product_id'] : null,
            'mapStatus' => $r['mapping_status'],
            'matchedPosSku' => $r['matched_pos_sku'] ?? null,
        ];
    }

    // Fetch duplicate whitelist
    $wlStmt = $conn->query("SELECT sku FROM shopee_duplicate_whitelist");
    $whitelist = $wlStmt->fetchAll(PDO::FETCH_COLUMN);

    echo json_encode([
        'groups' => array_values($groups),
        'whitelist' => $whitelist
    ]);

} catch (Exception $e) {
    echo json_encode([]);
}

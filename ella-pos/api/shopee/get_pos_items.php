<?php
header("Content-Type: application/json");
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';

requireLogin();

if (!hasPermission('shopee_sync')) {
    echo json_encode([]);
    exit;
}

try {
    $db = new Database();
    $conn = $db->getConnection();

    // Query mapping `mapping.php` logic but also checking used IDs to match `mapping.php` implementation
    $posRows = $conn->query("
        SELECT v.variation_id as id,
            p.product_name,
            v.variation_name,
            v.sku,
            COALESCE(p.brand_name, '') as brand,
            COALESCE(v.barcode, '') as barcode
        FROM product_variations v JOIN products p ON v.product_id = p.product_id
        WHERE v.status='active' ORDER BY p.product_name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Get used IDs
    $usedStmt = $conn->query("SELECT pos_product_id FROM shopee_product_mappings WHERE pos_product_id IS NOT NULL");
    $usedIds = $usedStmt->fetchAll(PDO::FETCH_COLUMN);
    $usedIdsHash = array_flip($usedIds);

    $posJson = [];
    foreach ($posRows as $p) {
        $posJson[] = [
            'id' => (int)$p['id'],
            'product_name' => $p['product_name'],
            'variation_name' => $p['variation_name'] ?? '',
            'name' => $p['product_name'] . ($p['variation_name'] ? ' (' . $p['variation_name'] . ')' : ''),
            'sku' => $p['sku'],
            'brand' => $p['brand'],
            'barcode' => $p['barcode'],
            'used' => isset($usedIdsHash[$p['id']])
        ];
    }

    echo json_encode($posJson);

} catch (Exception $e) {
    echo json_encode([]);
}

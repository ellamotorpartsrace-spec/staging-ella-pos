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

    // Query base products and custom units
    $posRows = $conn->query("
        SELECT v.variation_id as id,
            p.product_name,
            v.variation_name,
            v.sku,
            COALESCE(p.brand_name, '') as brand,
            COALESCE(v.barcode, '') as barcode,
            'base' as item_type,
            NULL as unit_id,
            1 as multiplier
        FROM product_variations v JOIN products p ON v.product_id = p.product_id
        WHERE v.status='active'

        UNION ALL

        SELECT v.variation_id as id,
            p.product_name,
            u.unit_name as variation_name,
            v.sku,
            COALESCE(p.brand_name, '') as brand,
            COALESCE(u.barcode, v.barcode, '') as barcode,
            'unit' as item_type,
            u.id as unit_id,
            u.multiplier as multiplier
        FROM product_units u
        JOIN product_variations v ON u.variation_id = v.variation_id
        JOIN products p ON v.product_id = p.product_id
        WHERE v.status='active'

        ORDER BY product_name ASC, item_type ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Get used mappings
    $usedStmt = $conn->query("SELECT pos_product_id, pos_unit_id FROM shopee_product_mappings WHERE pos_product_id IS NOT NULL");
    $usedMappings = $usedStmt->fetchAll(PDO::FETCH_ASSOC);
    
    $usedBase = [];
    $usedUnits = [];
    foreach ($usedMappings as $m) {
        if ($m['pos_unit_id']) {
            $usedUnits[$m['pos_unit_id']] = true;
        } else {
            $usedBase[$m['pos_product_id']] = true;
        }
    }

    $posJson = [];
    foreach ($posRows as $p) {
        $isUsed = ($p['item_type'] === 'unit') ? isset($usedUnits[$p['unit_id']]) : isset($usedBase[$p['id']]);
        $displayName = $p['product_name'] . ($p['variation_name'] ? ' (' . $p['variation_name'] . ')' : '');
        
        $posJson[] = [
            'id' => (int)$p['id'],
            'unit_id' => $p['unit_id'] ? (int)$p['unit_id'] : null,
            'item_type' => $p['item_type'],
            'multiplier' => (int)$p['multiplier'],
            'product_name' => $p['product_name'],
            'variation_name' => $p['variation_name'] ?? '',
            'name' => $displayName,
            'sku' => $p['sku'],
            'brand' => $p['brand'],
            'barcode' => $p['barcode'],
            'used' => $isUsed
        ];
    }

    echo json_encode($posJson);

} catch (Exception $e) {
    echo json_encode([]);
}

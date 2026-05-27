<?php
header("Content-Type: application/json");
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once __DIR__ . '/unit_mapping_helpers.php';

requireLogin();

if (!hasPermission('shopee_sync')) {
    echo json_encode([]);
    exit;
}

try {
    $db = new Database();
    $conn = $db->getConnection();
    ensureShopeeUnitMappingColumn($conn);

    // Query base products and custom units
    $posRows = $conn->query("
        SELECT v.variation_id as id,
            p.product_name,
            v.variation_name,
            NULL as unit_name,
            v.sku,
            COALESCE(p.brand_name, '') as brand,
            COALESCE(v.barcode, '') as barcode,
            v.unit_type as base_unit_type,
            'base' as item_type,
            NULL as unit_id,
            1 as multiplier,
            NULL as unit_description
        FROM product_variations v JOIN products p ON v.product_id = p.product_id
        WHERE v.status='active'

        UNION ALL

        SELECT v.variation_id as id,
            p.product_name,
            v.variation_name,
            u.unit_name,
            v.sku,
            COALESCE(p.brand_name, '') as brand,
            COALESCE(u.barcode, v.barcode, '') as barcode,
            v.unit_type as base_unit_type,
            'unit' as item_type,
            u.id as unit_id,
            u.multiplier as multiplier,
            COALESCE(u.description, '') as unit_description
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
        $baseVariation = trim((string)($p['variation_name'] ?? ''));
        $unitName = trim((string)($p['unit_name'] ?? ''));
        $displayName = $p['product_name'];
        if ($baseVariation !== '') {
            $displayName .= ' (' . $baseVariation . ')';
        }
        if ($p['item_type'] === 'unit' && $unitName !== '') {
            $displayName .= ' - ' . $unitName . ' x' . (int)$p['multiplier'];
        }
        
        $posJson[] = [
            'id' => (int)$p['id'],
            'unit_id' => $p['unit_id'] ? (int)$p['unit_id'] : null,
            'item_type' => $p['item_type'],
            'multiplier' => (int)$p['multiplier'],
            'product_name' => $p['product_name'],
            'variation_name' => $p['variation_name'] ?? '',
            'unit_name' => $p['unit_name'] ?? '',
            'base_unit_type' => $p['base_unit_type'] ?? 'pc',
            'unit_description' => $p['unit_description'] ?? '',
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

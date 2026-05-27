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
    ensureShopeeBundleMappingColumn($conn);

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

    $bundleRows = [];
    try {
        $bundleRows = $conn->query("
            SELECT
                s.id,
                s.set_name AS product_name,
                '' AS variation_name,
                NULL AS unit_name,
                COALESCE(s.set_sku, '') AS sku,
                'Bundle' AS brand,
                COALESCE(s.set_sku, '') AS barcode,
                'set' AS base_unit_type,
                'bundle' AS item_type,
                NULL AS unit_id,
                1 AS multiplier,
                COALESCE(s.description, '') AS unit_description,
                s.id AS bundle_set_id,
                COALESCE(sc.component_count, 0) AS component_count
            FROM product_unit_sets s
            LEFT JOIN (
                SELECT product_set_id, COUNT(*) AS component_count
                FROM product_unit_set_items
                GROUP BY product_set_id
            ) sc ON sc.product_set_id = s.id
            WHERE s.status = 'active'
            ORDER BY s.set_name ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $ignored) {
        $bundleRows = [];
    }

    // Get used mappings
    $usedStmt = $conn->query("SELECT pos_product_id, pos_unit_id, pos_bundle_set_id FROM shopee_product_mappings WHERE pos_product_id IS NOT NULL OR pos_bundle_set_id IS NOT NULL");
    $usedMappings = $usedStmt->fetchAll(PDO::FETCH_ASSOC);
    
    $usedBase = [];
    $usedUnits = [];
    $usedBundles = [];
    foreach ($usedMappings as $m) {
        if (!empty($m['pos_bundle_set_id'])) {
            $usedBundles[(int)$m['pos_bundle_set_id']] = true;
        } elseif ($m['pos_unit_id']) {
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

    foreach ($bundleRows as $p) {
        $bundleSetId = (int)$p['bundle_set_id'];
        $componentCount = (int)($p['component_count'] ?? 0);
        $displayName = $p['product_name'];
        if ($componentCount > 0) {
            $displayName .= ' - Bundle Set (' . $componentCount . ' items)';
        }

        $posJson[] = [
            'id' => $bundleSetId,
            'unit_id' => null,
            'bundle_set_id' => $bundleSetId,
            'item_type' => 'bundle',
            'multiplier' => 1,
            'component_count' => $componentCount,
            'product_name' => $p['product_name'],
            'variation_name' => '',
            'unit_name' => 'Bundle Set',
            'base_unit_type' => 'set',
            'unit_description' => $p['unit_description'] ?? '',
            'name' => $displayName,
            'sku' => $p['sku'],
            'brand' => $p['brand'],
            'barcode' => $p['barcode'],
            'used' => isset($usedBundles[$bundleSetId])
        ];
    }

    echo json_encode($posJson);

} catch (Exception $e) {
    echo json_encode([]);
}

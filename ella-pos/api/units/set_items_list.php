<?php
declare(strict_types=1);

require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method Not Allowed']);
    exit;
}

requireLogin();
if (!in_array($_SESSION['role'], ['admin', 'super_admin']) && !hasPermission('adjust_prices') && !in_array($_SESSION['role'], ['manager', 'stockman'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Forbidden']);
    exit;
}

$productSetId = isset($_GET['product_set_id']) ? (int) $_GET['product_set_id'] : 0;
if ($productSetId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid set ID']);
    exit;
}

try {
    $db = new Database();
    $conn = $db->getConnection();

    $stmtSet = $conn->prepare("
        SELECT id, set_name, set_sku, description, price_retail, price_wholesale, price_dealer, status
        FROM product_unit_sets
        WHERE id = ?
        LIMIT 1
    ");
    $stmtSet->execute([$productSetId]);
    $set = $stmtSet->fetch(PDO::FETCH_ASSOC);

    if (!$set) {
        echo json_encode(['success' => false, 'message' => 'Bundle set not found']);
        exit;
    }

    $stmtItems = $conn->prepare("
        SELECT
            si.id,
            si.component_variation_id,
            si.component_unit_id,
            si.component_qty,
            v.sku,
            v.variation_name,
            v.unit_type AS base_unit_type,
            p.product_name,
            COALESCE(p.brand_name, '') AS brand_name,
            pu.unit_name AS component_unit_name,
            pu.multiplier AS component_unit_multiplier
        FROM product_unit_set_items si
        INNER JOIN product_variations v ON v.variation_id = si.component_variation_id
        INNER JOIN products p ON p.product_id = v.product_id
        LEFT JOIN product_units pu ON pu.id = si.component_unit_id
        WHERE si.product_set_id = ?
        ORDER BY p.product_name ASC, v.variation_name ASC
    ");
    $stmtItems->execute([$productSetId]);
    $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'data' => [
            'set' => [
                'id' => (int) $set['id'],
                'set_name' => $set['set_name'],
                'set_sku' => $set['set_sku'],
                'description' => $set['description'],
                'price_retail' => (float) $set['price_retail'],
                'price_wholesale' => (float) $set['price_wholesale'],
                'price_dealer' => (float) $set['price_dealer'],
                'status' => $set['status'],
            ],
            'items' => array_map(static function (array $row): array {
                return [
                    'id' => (int) $row['id'],
                    'component_variation_id' => (int) $row['component_variation_id'],
                    'component_unit_id' => $row['component_unit_id'] !== null ? (int) $row['component_unit_id'] : null,
                    'component_qty' => (float) $row['component_qty'],
                    'sku' => $row['sku'] ?? '',
                    'product_name' => $row['product_name'] ?? '',
                    'brand_name' => $row['brand_name'] ?? '',
                    'variation_name' => $row['variation_name'] ?? '',
                    'base_unit_type' => $row['base_unit_type'] ?? 'pc',
                    'component_unit_name' => $row['component_unit_name'] ?? null,
                    'component_unit_multiplier' => $row['component_unit_multiplier'] !== null ? (int) $row['component_unit_multiplier'] : 1,
                    'item_type' => $row['component_unit_id'] !== null ? 'unit' : 'base',
                ];
            }, $items),
        ],
    ]);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

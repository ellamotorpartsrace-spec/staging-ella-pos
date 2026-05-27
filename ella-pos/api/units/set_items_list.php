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
if ($_SESSION['role'] !== 'admin' && !hasPermission('adjust_prices') && !in_array($_SESSION['role'], ['manager', 'stockman'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Forbidden']);
    exit;
}

$productUnitId = isset($_GET['product_unit_id']) ? (int) $_GET['product_unit_id'] : 0;
if ($productUnitId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid unit ID']);
    exit;
}

try {
    $db = new Database();
    $conn = $db->getConnection();

    $stmtUnit = $conn->prepare("
        SELECT 
            u.id AS unit_id,
            u.variation_id,
            u.unit_name,
            u.multiplier,
            v.sku,
            v.variation_name,
            p.product_name
        FROM product_units u
        INNER JOIN product_variations v ON v.variation_id = u.variation_id
        INNER JOIN products p ON p.product_id = v.product_id
        WHERE u.id = ?
        LIMIT 1
    ");
    $stmtUnit->execute([$productUnitId]);
    $unit = $stmtUnit->fetch(PDO::FETCH_ASSOC);

    if (!$unit) {
        echo json_encode(['success' => false, 'message' => 'Unit not found']);
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
            pu.unit_name AS component_unit_name,
            pu.multiplier AS component_unit_multiplier
        FROM product_unit_set_items si
        INNER JOIN product_variations v ON v.variation_id = si.component_variation_id
        INNER JOIN products p ON p.product_id = v.product_id
        LEFT JOIN product_units pu ON pu.id = si.component_unit_id
        WHERE si.product_unit_id = ?
        ORDER BY p.product_name ASC, v.variation_name ASC
    ");
    $stmtItems->execute([$productUnitId]);
    $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

    $normalized = array_map(static function (array $row): array {
        return [
            'id' => (int) $row['id'],
            'component_variation_id' => (int) $row['component_variation_id'],
            'component_unit_id' => $row['component_unit_id'] !== null ? (int) $row['component_unit_id'] : null,
            'component_qty' => (float) $row['component_qty'],
            'sku' => $row['sku'] ?? '',
            'product_name' => $row['product_name'] ?? '',
            'variation_name' => $row['variation_name'] ?? '',
            'base_unit_type' => $row['base_unit_type'] ?? 'pc',
            'component_unit_name' => $row['component_unit_name'] ?? null,
            'component_unit_multiplier' => $row['component_unit_multiplier'] !== null ? (int) $row['component_unit_multiplier'] : 1,
            'item_type' => $row['component_unit_id'] !== null ? 'unit' : 'base',
        ];
    }, $items);

    echo json_encode([
        'success' => true,
        'data' => [
            'unit' => [
                'unit_id' => (int) $unit['unit_id'],
                'variation_id' => (int) $unit['variation_id'],
                'unit_name' => $unit['unit_name'],
                'multiplier' => (int) $unit['multiplier'],
                'sku' => $unit['sku'],
                'variation_name' => $unit['variation_name'],
                'product_name' => $unit['product_name'],
            ],
            'items' => $normalized,
        ],
    ]);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}


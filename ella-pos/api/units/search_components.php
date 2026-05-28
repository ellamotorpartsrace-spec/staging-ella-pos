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

$mode = strtolower(trim((string) ($_GET['mode'] ?? 'base')));
$search = trim((string) ($_GET['q'] ?? ''));
$excludeUnitId = isset($_GET['exclude_unit_id']) ? (int) $_GET['exclude_unit_id'] : 0;

if (!in_array($mode, ['base', 'unit'], true)) {
    $mode = 'base';
}

try {
    $db = new Database();
    $conn = $db->getConnection();

    $params = [];
    $where = ["v.status = 'active'"];

    if ($search !== '') {
        $words = preg_split('/\s+/', $search) ?: [];
        $words = array_values(array_filter(array_map('trim', $words), static fn($w) => $w !== ''));
        if (!empty($words)) {
            $wordGroups = [];
            foreach ($words as $idx => $word) {
                $key = ":term{$idx}";
                $params[$key] = '%' . $word . '%';
                $wordGroups[] = "(
                    p.product_name LIKE {$key}
                    OR p.brand_name LIKE {$key}
                    OR v.variation_name LIKE {$key}
                    OR v.sku LIKE {$key}
                    OR v.barcode LIKE {$key}
                    " . ($mode === 'unit' ? " OR u.unit_name LIKE {$key} OR u.barcode LIKE {$key}" : '') . "
                )";
            }
            $where[] = '(' . implode(' AND ', $wordGroups) . ')';
        }
    }

    if ($mode === 'unit') {
        $sql = "
            SELECT
                v.variation_id,
                u.id AS unit_id,
                p.product_name,
                COALESCE(p.brand_name, '') AS brand_name,
                COALESCE(p.image_path, '') AS image_path,
                v.variation_name,
                v.sku,
                v.unit_type AS base_unit_type,
                u.unit_name,
                u.multiplier
            FROM product_units u
            INNER JOIN product_variations v ON v.variation_id = u.variation_id
            INNER JOIN products p ON p.product_id = v.product_id
            WHERE " . implode(' AND ', $where);

        if ($excludeUnitId > 0) {
            $sql .= " AND u.id <> :exclude_unit_id";
            $params[':exclude_unit_id'] = $excludeUnitId;
        }

        $sql .= " ORDER BY p.product_name ASC, v.variation_name ASC, u.unit_name ASC LIMIT 50";
    } else {
        $sql = "
            SELECT
                v.variation_id,
                NULL AS unit_id,
                p.product_name,
                COALESCE(p.brand_name, '') AS brand_name,
                COALESCE(p.image_path, '') AS image_path,
                v.variation_name,
                v.sku,
                v.unit_type AS base_unit_type,
                NULL AS unit_name,
                1 AS multiplier
            FROM product_variations v
            INNER JOIN products p ON p.product_id = v.product_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY p.product_name ASC, v.variation_name ASC
            LIMIT 50
        ";
    }

    $stmt = $conn->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $data = array_map(static function (array $row): array {
        $variationName = trim((string) ($row['variation_name'] ?? ''));
        $unitName = trim((string) ($row['unit_name'] ?? ''));
        $name = $row['product_name'];
        if ($variationName !== '') {
            $name .= ' (' . $variationName . ')';
        }
        if ($unitName !== '') {
            $name .= ' - ' . $unitName . ' x' . (int) $row['multiplier'];
        }

        return [
            'variation_id' => (int) $row['variation_id'],
            'unit_id' => $row['unit_id'] !== null ? (int) $row['unit_id'] : null,
            'item_type' => $row['unit_id'] !== null ? 'unit' : 'base',
            'product_name' => $row['product_name'] ?? '',
            'brand_name' => $row['brand_name'] ?? '',
            'image_path' => $row['image_path'] ?? '',
            'variation_name' => $row['variation_name'] ?? '',
            'sku' => $row['sku'] ?? '',
            'base_unit_type' => $row['base_unit_type'] ?? 'pc',
            'unit_name' => $row['unit_name'] ?? null,
            'multiplier' => (int) ($row['multiplier'] ?? 1),
            'name' => $name,
        ];
    }, $rows);

    echo json_encode(['success' => true, 'data' => $data]);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}


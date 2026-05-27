<?php
declare(strict_types=1);

require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/logger.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
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

$payload = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($payload)) {
    echo json_encode(['success' => false, 'message' => 'Invalid payload']);
    exit;
}

$productUnitId = isset($payload['product_unit_id']) ? (int) $payload['product_unit_id'] : 0;
$items = $payload['items'] ?? [];

if ($productUnitId <= 0 || !is_array($items)) {
    echo json_encode(['success' => false, 'message' => 'Invalid set data']);
    exit;
}

try {
    $db = new Database();
    $conn = $db->getConnection();
    $conn->beginTransaction();

    $stmtUnit = $conn->prepare("SELECT id, variation_id, unit_name FROM product_units WHERE id = ? LIMIT 1");
    $stmtUnit->execute([$productUnitId]);
    $unit = $stmtUnit->fetch(PDO::FETCH_ASSOC);
    if (!$unit) {
        throw new RuntimeException('Target unit does not exist.');
    }

    // Normalize duplicate entries by key (variation + unit).
    $normalized = [];
    foreach ($items as $row) {
        if (!is_array($row)) {
            continue;
        }
        $variationId = isset($row['component_variation_id']) ? (int) $row['component_variation_id'] : 0;
        $componentUnitId = isset($row['component_unit_id']) && $row['component_unit_id'] !== null ? (int) $row['component_unit_id'] : null;
        $qty = isset($row['component_qty']) ? (float) $row['component_qty'] : 0.0;

        if ($variationId <= 0 || $qty <= 0) {
            continue;
        }

        $key = $variationId . '|' . ($componentUnitId ?? 0);
        if (!isset($normalized[$key])) {
            $normalized[$key] = [
                'component_variation_id' => $variationId,
                'component_unit_id' => $componentUnitId,
                'component_qty' => 0.0,
            ];
        }
        $normalized[$key]['component_qty'] += $qty;
    }

    $deleteStmt = $conn->prepare("DELETE FROM product_unit_set_items WHERE product_unit_id = ?");
    $deleteStmt->execute([$productUnitId]);

    if (!empty($normalized)) {
        $verifyVariationStmt = $conn->prepare("SELECT variation_id FROM product_variations WHERE variation_id = ? LIMIT 1");
        $verifyUnitStmt = $conn->prepare("SELECT id FROM product_units WHERE id = ? AND variation_id = ? LIMIT 1");
        $insertStmt = $conn->prepare("
            INSERT INTO product_unit_set_items (
                product_unit_id,
                component_variation_id,
                component_unit_id,
                component_qty
            ) VALUES (?, ?, ?, ?)
        ");

        foreach ($normalized as $row) {
            $variationId = (int) $row['component_variation_id'];
            $componentUnitId = $row['component_unit_id'] !== null ? (int) $row['component_unit_id'] : null;
            $qty = round((float) $row['component_qty'], 4);

            $verifyVariationStmt->execute([$variationId]);
            if (!$verifyVariationStmt->fetch(PDO::FETCH_ASSOC)) {
                throw new RuntimeException('Invalid component variation detected.');
            }

            if ($componentUnitId !== null) {
                $verifyUnitStmt->execute([$componentUnitId, $variationId]);
                if (!$verifyUnitStmt->fetch(PDO::FETCH_ASSOC)) {
                    throw new RuntimeException('Component unit does not match selected product.');
                }
            }

            $insertStmt->execute([$productUnitId, $variationId, $componentUnitId, $qty]);
        }
    }

    $conn->commit();

    if (function_exists('logActivity')) {
        logActivity(
            $conn,
            $_SESSION['user_id'],
            'SAVE_UNIT_SET_ITEMS',
            'Inventory',
            'Updated set recipe for unit ID ' . $productUnitId . ' (' . ($unit['unit_name'] ?? 'Unit') . ')'
        );
    }

    echo json_encode([
        'success' => true,
        'message' => 'Set recipe saved successfully.',
        'component_count' => count($normalized),
    ]);
} catch (Throwable $e) {
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}


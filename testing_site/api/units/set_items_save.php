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
if (!in_array($_SESSION['role'], ['admin', 'super_admin']) && !hasPermission('adjust_prices') && !in_array($_SESSION['role'], ['manager', 'stockman'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Forbidden']);
    exit;
}

$payload = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($payload)) {
    echo json_encode(['success' => false, 'message' => 'Invalid payload']);
    exit;
}

$productSetId = isset($payload['product_set_id']) ? (int) $payload['product_set_id'] : 0;
$setName = trim((string) ($payload['set_name'] ?? ''));
$setSku = trim((string) ($payload['set_sku'] ?? ''));
$description = trim((string) ($payload['description'] ?? ''));
$priceRetail = isset($payload['price_retail']) ? (float) $payload['price_retail'] : 0.0;
$priceWholesale = isset($payload['price_wholesale']) ? (float) $payload['price_wholesale'] : 0.0;
$priceDealer = isset($payload['price_dealer']) ? (float) $payload['price_dealer'] : 0.0;
$status = in_array(($payload['status'] ?? 'active'), ['active', 'inactive'], true) ? $payload['status'] : 'active';
$items = $payload['items'] ?? [];

if ($setName === '' || !is_array($items)) {
    echo json_encode(['success' => false, 'message' => 'Set name and component list are required.']);
    exit;
}

try {
    $db = new Database();
    $conn = $db->getConnection();
    $conn->beginTransaction();

    if ($setSku !== '') {
        $skuStmt = $conn->prepare("SELECT id FROM product_unit_sets WHERE set_sku = ? AND id <> ? LIMIT 1");
        $skuStmt->execute([$setSku, $productSetId]);
        if ($skuStmt->fetch(PDO::FETCH_ASSOC)) {
            throw new RuntimeException('Bundle SKU already exists.');
        }
    }

    if ($productSetId > 0) {
        $updateStmt = $conn->prepare("
            UPDATE product_unit_sets
            SET set_name = ?, set_sku = ?, description = ?, price_retail = ?, price_wholesale = ?, price_dealer = ?, status = ?
            WHERE id = ?
        ");
        $updateStmt->execute([
            $setName,
            $setSku !== '' ? $setSku : null,
            $description !== '' ? $description : null,
            max(0, $priceRetail),
            max(0, $priceWholesale),
            max(0, $priceDealer),
            $status,
            $productSetId,
        ]);

        if ($updateStmt->rowCount() === 0) {
            $existsStmt = $conn->prepare("SELECT id FROM product_unit_sets WHERE id = ? LIMIT 1");
            $existsStmt->execute([$productSetId]);
            if (!$existsStmt->fetch(PDO::FETCH_ASSOC)) {
                throw new RuntimeException('Bundle set does not exist.');
            }
        }
    } else {
        $insertStmt = $conn->prepare("
            INSERT INTO product_unit_sets (set_name, set_sku, description, price_retail, price_wholesale, price_dealer, status)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $insertStmt->execute([
            $setName,
            $setSku !== '' ? $setSku : null,
            $description !== '' ? $description : null,
            max(0, $priceRetail),
            max(0, $priceWholesale),
            max(0, $priceDealer),
            $status,
        ]);
        $productSetId = (int) $conn->lastInsertId();
    }

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

    $deleteStmt = $conn->prepare("DELETE FROM product_unit_set_items WHERE product_set_id = ?");
    $deleteStmt->execute([$productSetId]);

    if (!empty($normalized)) {
        $verifyVariationStmt = $conn->prepare("SELECT variation_id FROM product_variations WHERE variation_id = ? LIMIT 1");
        $verifyUnitStmt = $conn->prepare("SELECT id FROM product_units WHERE id = ? AND variation_id = ? LIMIT 1");
        $insertItemStmt = $conn->prepare("
            INSERT INTO product_unit_set_items (
                product_set_id,
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

            $insertItemStmt->execute([$productSetId, $variationId, $componentUnitId, $qty]);
        }
    }

    $conn->commit();

    if (function_exists('logActivity')) {
        logActivity(
            $conn,
            $_SESSION['user_id'],
            'SAVE_BUNDLE_SET',
            'Inventory',
            'Saved bundle set ID ' . $productSetId . ' (' . $setName . ')'
        );
    }

    echo json_encode([
        'success' => true,
        'message' => 'Bundle set saved successfully.',
        'product_set_id' => $productSetId,
        'component_count' => count($normalized),
    ]);
} catch (Throwable $e) {
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}


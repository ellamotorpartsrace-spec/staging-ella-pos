<?php
/**
 * reconcile_inventory.php
 * 
 * This script finds all products where the inventory table value (store_id=1)
 * does NOT match the last recorded movement's new_stock, then corrects them
 * by writing a corrective adjustment and fixing the inventory table.
 * 
 * Run ONCE on the live server from the browser or CLI.
 */
require_once __DIR__ . '/config/database.php';

// Only allow admins or CLI
if (php_sapi_name() !== 'cli') {
    session_start();
    if (empty($_SESSION['user_id'])) {
        http_response_code(403);
        echo json_encode(['error' => 'Not logged in']);
        exit;
    }
}

$db = new Database();
$conn = $db->getConnection();

// Find all variation_ids that have stock movements for store_id=1
// Get their last logged new_stock (= correct balance per history)
// Compare with inventory.quantity (= current value in DB)
$stmt = $conn->query("
    SELECT 
        m.variation_id,
        m.new_stock AS history_balance,
        COALESCE(i.quantity, 0) AS inventory_balance,
        m.created_at AS last_movement_at,
        p.product_name,
        v.sku
    FROM stock_movements m
    JOIN (
        SELECT variation_id, MAX(movement_id) AS last_id
        FROM stock_movements
        WHERE store_id = 1
          AND status = 'active'
          AND type NOT IN ('online_sale', 'online_adjustment')
        GROUP BY variation_id
    ) latest ON latest.variation_id = m.variation_id AND latest.last_id = m.movement_id
    LEFT JOIN inventory i ON i.variation_id = m.variation_id AND i.store_id = 1
    LEFT JOIN product_variations v ON v.variation_id = m.variation_id
    LEFT JOIN products p ON p.product_id = v.product_id
    WHERE ABS(COALESCE(i.quantity, 0) - m.new_stock) >= 1
    ORDER BY ABS(COALESCE(i.quantity, 0) - m.new_stock) DESC
");

$discrepancies = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($discrepancies)) {
    echo json_encode([
        'success' => true,
        'message' => 'All inventory values match movement history. No corrections needed.',
        'fixed' => 0
    ]);
    exit;
}

$fixed = 0;
$errors = [];
$report = [];

$userId = $_SESSION['user_id'] ?? 1; // Use admin user ID for CLI

foreach ($discrepancies as $row) {
    $variationId = (int)$row['variation_id'];
    $historyBalance = (float)$row['history_balance'];
    $inventoryBalance = (float)$row['inventory_balance'];
    $diff = $historyBalance - $inventoryBalance;

    try {
        $conn->beginTransaction();

        // Fix inventory to match history
        $conn->prepare("
            INSERT INTO inventory (variation_id, store_id, quantity)
            VALUES (?, 1, ?)
            ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)
        ")->execute([$variationId, $historyBalance]);

        // Log corrective adjustment so history chain stays clean
        $conn->prepare("
            INSERT INTO stock_movements
            (variation_id, store_id, type, quantity, previous_stock, new_stock, reference, remarks, created_by)
            VALUES (?, 1, 'adjustment', ?, ?, ?, 'SYS-RECONCILE', 'Inventory reconciliation: corrected discrepancy between inventory table and movement history', ?)
        ")->execute([
            $variationId,
            $diff,
            $inventoryBalance,
            $historyBalance,
            $userId
        ]);

        $conn->commit();
        $fixed++;

        $report[] = [
            'sku'       => $row['sku'],
            'product'   => substr($row['product_name'], 0, 50),
            'was'       => $inventoryBalance,
            'corrected' => $historyBalance,
            'diff'      => ($diff >= 0 ? '+' : '') . $diff,
        ];

    } catch (Exception $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        $errors[] = "SKU {$row['sku']}: " . $e->getMessage();
    }
}

header('Content-Type: application/json');
echo json_encode([
    'success'        => true,
    'fixed'          => $fixed,
    'errors'         => $errors,
    'discrepancies'  => $report
], JSON_PRETTY_PRINT);

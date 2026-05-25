<?php
/**
 * api/shopee/cleanup_orphan_online_stock.php
 *
 * One-time cleanup: finds POS products that have online stock (store_id=2)
 * but NO active Shopee mapping (status='auto' or 'manual'), then zeroes out
 * their online allocation and restores the physical stock to the full total.
 *
 * Safe to run multiple times (idempotent).
 */
header("Content-Type: application/json");
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once __DIR__ . '/sync_helpers.php';

requireLogin();
if (!hasPermission('shopee_sync')) {
    echo json_encode(['success' => false, 'error' => 'Permission denied.']);
    exit;
}

try {
    $db = new Database();
    $conn = $db->getConnection();

    // Find all variation_ids that have online inventory (store_id=2, quantity > 0)
    // but are NOT currently linked to any active Shopee mapping.
    $stmt = $conn->query("
        SELECT i.variation_id, i.quantity AS online_qty,
               COALESCE(i_phys.quantity, 0) AS physical_qty
        FROM inventory i
        LEFT JOIN inventory i_phys ON i_phys.variation_id = i.variation_id AND i_phys.store_id = 1
        WHERE i.store_id = 2
          AND i.quantity > 0
          AND i.variation_id NOT IN (
              SELECT pos_product_id
              FROM shopee_product_mappings
              WHERE mapping_status IN ('auto', 'manual')
                AND pos_product_id IS NOT NULL
          )
    ");
    $orphans = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $fixed = 0;
    foreach ($orphans as $row) {
        $varId     = (int)$row['variation_id'];
        $onlineQty = (int)$row['online_qty'];
        $physQty   = (int)$row['physical_qty'];

        // Zero out online inventory
        $conn->prepare("UPDATE inventory SET quantity = 0 WHERE variation_id = ? AND store_id = 2")
             ->execute([$varId]);

        // Restore physical stock to previous total (phys + old online)
        $newPhysQty = $physQty + $onlineQty;
        $conn->prepare("
            INSERT INTO inventory (variation_id, store_id, quantity)
            VALUES (?, 1, ?)
            ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)
        ")->execute([$varId, $newPhysQty]);

        // Log the cleanup movement
        $conn->prepare("
            INSERT INTO stock_movements
            (variation_id, store_id, type, quantity, previous_stock, new_stock, reference, remarks, created_by, capital_cost)
            VALUES (?, 2, 'allocation_adjustment', ?, ?, 0, ?, 'Cleanup: removed orphan online allocation (no active Shopee link)', ?, 0)
        ")->execute([
            $varId,
            -$onlineQty,
            $onlineQty,
            'SHP-CLEANUP-' . date('YmdHis') . '-' . $varId,
            $_SESSION['user_id'] ?? null
        ]);

        $fixed++;
    }

    echo json_encode([
        'success' => true,
        'message' => "Cleaned up {$fixed} orphan online stock record(s). Online stock set to 0 and physical stock restored.",
        'fixed'   => $fixed,
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

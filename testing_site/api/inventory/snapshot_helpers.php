<?php
/**
 * api/inventory/snapshot_helpers.php
 * Shared helper functions for the Inventory Snapshot system.
 * Safe to include from both the API (web) and the CLI cron script.
 * Contains NO HTTP headers, NO session checks, NO router code.
 */

/**
 * Create a snapshot of all active product stock.
 * Uses a single bulk INSERT...SELECT for performance on large SKU counts.
 *
 * @param  PDO    $conn        Active database connection
 * @param  string $name        Snapshot name
 * @param  string $notes       Optional notes
 * @param  string $triggerType 'manual' | 'auto' | 'pre_restore'
 * @param  int    $userId      User ID (0 for system/cron)
 * @param  string $userName    User display name
 * @return int    New snapshot ID
 * @throws Exception on DB failure
 */
function createSnapshotInternal(
    PDO    $conn,
    string $name,
    string $notes,
    string $triggerType,
    int    $userId,
    string $userName
): int {
    // 1. Insert the snapshot header
    $hdr = $conn->prepare("
        INSERT INTO inventory_snapshots
            (snapshot_name, notes, total_products, total_stock_qty, trigger_type, created_by, created_by_name, created_at)
        VALUES (?, ?, 0, 0, ?, ?, ?, NOW())
    ");
    $hdr->execute([
        $name,
        $notes ?: null,
        $triggerType,
        $userId ?: null,
        $userName,
    ]);
    $snapshotId = (int)$conn->lastInsertId();

    // 2. Bulk-capture current stock for ALL active product variations
    //
    //    Inventory table layout:
    //      store_id = 1  →  Physical POS stock
    //      store_id = 2  →  Shopee Allocated stock
    //
    //    total_stock      = store_id=1 qty + store_id=2 qty
    //    shopee_allocated = store_id=2 qty
    //    current_pos_stock= store_id=1 qty  ← DISPLAY ONLY; derived on restore as (total - shopee)
    $ins = $conn->prepare("
        INSERT INTO inventory_snapshot_items
            (snapshot_id, variation_id, sku, product_name,
             total_stock, shopee_allocated, current_pos_stock)
        SELECT
            :snap_id,
            v.variation_id,
            v.sku,
            CONCAT(
                p.product_name,
                IF(v.variation_name IS NOT NULL AND TRIM(v.variation_name) <> '',
                   CONCAT(' \xe2\x80\x94 ', TRIM(v.variation_name)), '')
            ),
            COALESCE(i1.quantity, 0) + COALESCE(i2.quantity, 0),
            COALESCE(i2.quantity, 0),
            COALESCE(i1.quantity, 0)
        FROM product_variations v
        JOIN  products p  ON p.product_id  = v.product_id
        LEFT JOIN inventory i1 ON i1.variation_id = v.variation_id AND i1.store_id = 1
        LEFT JOIN inventory i2 ON i2.variation_id = v.variation_id AND i2.store_id = 2
        WHERE v.status = 'active'
    ");
    $ins->execute([':snap_id' => $snapshotId]);

    // 3. Write the accurate product count and stock sum back to the header
    $stats = $conn->query("
        SELECT COUNT(*) as cnt, COALESCE(SUM(total_stock), 0) as sum_qty
        FROM inventory_snapshot_items 
        WHERE snapshot_id = {$snapshotId}
    ")->fetch(PDO::FETCH_ASSOC);

    $conn->prepare("UPDATE inventory_snapshots SET total_products = ?, total_stock_qty = ? WHERE id = ?")
         ->execute([(int)$stats['cnt'], (int)$stats['sum_qty'], $snapshotId]);

    return $snapshotId;
}

/**
 * Write an entry to the audit log.
 *
 * @param PDO         $conn
 * @param string      $actionType  e.g. 'RESTORE', 'DELETE_SNAPSHOT'
 * @param int|null    $snapshotId
 * @param string|null $snapshotName
 * @param int|null    $preRestoreId   ID of auto-created pre-restore backup (if any)
 * @param int         $productsAffected
 * @param string|null $notes
 * @param int         $userId
 * @param string      $userName
 * @param string      $ipAddress
 * @param int         $totalStockQty
 */
function logSnapshotAudit(
    PDO     $conn,
    string  $actionType,
    ?int    $snapshotId,
    ?string $snapshotName,
    ?int    $preRestoreId,
    int     $productsAffected,
    ?string $notes,
    int     $userId,
    string  $userName,
    string  $ipAddress,
    int     $totalStockQty = 0
): void {
    $conn->prepare("
        INSERT INTO inventory_snapshot_audit
            (action_type, snapshot_id, snapshot_name, pre_restore_snapshot_id,
             user_id, user_name, ip_address, products_affected, total_stock_qty, notes, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ")->execute([
        $actionType,
        $snapshotId,
        $snapshotName,
        $preRestoreId,
        $userId ?: null,
        $userName,
        $ipAddress,
        $productsAffected,
        $totalStockQty,
        $notes,
    ]);
}

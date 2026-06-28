<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';

try {
    $db = new Database();
    $conn = $db->getConnection();
    
    // Add total_stock_qty to inventory_snapshots
    $conn->exec("ALTER TABLE inventory_snapshots ADD COLUMN IF NOT EXISTS total_stock_qty INT NOT NULL DEFAULT 0 AFTER total_products;");
    
    // Add total_stock_qty to inventory_snapshot_audit
    $conn->exec("ALTER TABLE inventory_snapshot_audit ADD COLUMN IF NOT EXISTS total_stock_qty INT NOT NULL DEFAULT 0 AFTER products_affected;");
    
    // Populate existing snapshots
    $conn->exec("
        UPDATE inventory_snapshots s
        JOIN (
            SELECT snapshot_id, SUM(total_stock) as sum_qty
            FROM inventory_snapshot_items
            GROUP BY snapshot_id
        ) sums ON s.id = sums.snapshot_id
        SET s.total_stock_qty = sums.sum_qty
    ");

    echo "OK";
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage();
}

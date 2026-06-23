<?php
require 'config/database.php';
$db = new Database();
$conn = $db->getConnection();

// Check how many items have NEGATIVE stock today (store_id = 1, physical)
$s = $conn->query("
    SELECT COUNT(*) as negative_count
    FROM inventory
    WHERE quantity < 0 AND store_id = 1
");
echo "Negative stock items (Physical POS): " . $s->fetchColumn() . "\n";

// Check the bad RESTORE movements made today
$s = $conn->query("
    SELECT COUNT(*) as total_bad_restores
    FROM stock_movements
    WHERE DATE(created_at) = '2026-06-23'
      AND type = 'adjustment'
      AND remarks LIKE 'System Restore%'
");
echo "Bad restore movements today: " . $s->fetchColumn() . "\n";

// Look at the distinct snapshots referenced
$s = $conn->query("
    SELECT reference, remarks, COUNT(*) as cnt, SUM(quantity) as total_qty
    FROM stock_movements
    WHERE DATE(created_at) = '2026-06-23'
      AND type = 'adjustment'
      AND remarks LIKE 'System Restore%'
    GROUP BY reference, remarks
    ORDER BY reference
");
print_r($s->fetchAll(PDO::FETCH_ASSOC));

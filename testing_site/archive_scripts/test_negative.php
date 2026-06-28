<?php
require 'config/database.php';
$db = new Database(); $conn = $db->getConnection();
$stmt = $conn->query("SELECT * FROM stock_movements WHERE variation_id = 19653 OR variation_id = 702148 OR variation_id = 3142 ORDER BY movement_id DESC LIMIT 20");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    echo "ID:{$row['movement_id']} Var:{$row['variation_id']} Store:{$row['store_id']} Type:{$row['type']} Qty:{$row['quantity']} Prev:{$row['previous_stock']} New:{$row['new_stock']} Ref:{$row['reference']}\n";
}

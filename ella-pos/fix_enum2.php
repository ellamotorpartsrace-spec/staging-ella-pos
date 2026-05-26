<?php
require 'config/database.php';
$db = new Database();
$conn = $db->getConnection();

$stmt = $conn->query("SHOW COLUMNS FROM stock_movements WHERE Field = 'type'");
$col = $stmt->fetch(PDO::FETCH_ASSOC);

if ($col) {
    $enumStr = $col['Type']; // e.g. enum('stock_in','stock_out','sales','adjustment','return','allocation_adjustment')
    if (strpos($enumStr, "'shopee_sale'") === false) {
        $newEnum = str_replace("enum(", "enum('shopee_sale',", $enumStr);
        $conn->query("ALTER TABLE stock_movements MODIFY COLUMN type $newEnum NOT NULL");
        
        // Let's update any existing SHP-SYNC- rows to be shopee_sale so they reflect correctly!
        $conn->query("UPDATE stock_movements SET type = 'shopee_sale' WHERE reference LIKE 'SHP-SYNC-%'");
        
        echo "Successfully added shopee_sale and updated old SHP-SYNC rows!";
    } else {
        $conn->query("UPDATE stock_movements SET type = 'shopee_sale' WHERE reference LIKE 'SHP-SYNC-%'");
        echo "shopee_sale already exists. Updated old rows.";
    }
} else {
    echo "Column type not found.";
}

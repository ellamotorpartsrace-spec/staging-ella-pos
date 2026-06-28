<?php
require 'config/database.php';
$db = new Database();
$conn = $db->getConnection();

$stmt = $conn->query("SHOW COLUMNS FROM stock_movements WHERE Field = 'type'");
$col = $stmt->fetch(PDO::FETCH_ASSOC);

if ($col) {
    $enumStr = $col['Type']; // e.g. enum('stock_in','stock_out','sales','adjustment','return')
    if (strpos($enumStr, "'allocation_adjustment'") === false) {
        $newEnum = str_replace("enum(", "enum('allocation_adjustment',", $enumStr);
        $conn->query("ALTER TABLE stock_movements MODIFY COLUMN type $newEnum NOT NULL");
        
        // Also fix the empty string rows!
        $conn->query("UPDATE stock_movements SET type = 'allocation_adjustment' WHERE type = '' AND reference LIKE 'SHP-%'");
        echo "Successfully added allocation_adjustment and fixed old rows!";
    } else {
        // Just fix the empty string rows just in case!
        $conn->query("UPDATE stock_movements SET type = 'allocation_adjustment' WHERE type = '' AND reference LIKE 'SHP-%'");
        echo "allocation_adjustment already exists in ENUM. Fixed empty rows.";
    }
} else {
    echo "Column type not found.";
}

<?php
require_once 'config/config.php';
require_once 'config/database.php';

try {
    $db = new Database();
    $conn = $db->getConnection();
    $stmt = $conn->query("UPDATE stock_movements SET type = 'adjustment' WHERE type = 'stock_in' AND (remarks LIKE 'Quick adjustment from edit page%' OR remarks LIKE 'Mass Update: Stock adjustment via CSV%')");
    echo "<h1>Online Database Fixed Successfully!</h1>";
    echo "<p>Updated " . $stmt->rowCount() . " historical records from 'stock_in' to 'adjustment'.</p>";
    echo "<p>You can now delete this file.</p>";
} catch (Exception $e) {
    echo "<h1>Error</h1>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
}

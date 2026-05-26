<?php
require_once 'config/config.php';
require_once 'config/database.php';
require_once 'api/shopee/sync_helpers.php';
$db = new Database();
$conn = $db->getConnection();
try {
    propagateStockToPos($conn, 1119, 5, 'Test', 'MB-535', 1, 76);
    echo 'SUCCESS';
} catch (Throwable $e) {
    echo 'ERROR: ' . $e->getMessage();
}

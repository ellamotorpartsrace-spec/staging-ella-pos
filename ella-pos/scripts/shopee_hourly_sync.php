<?php
/**
 * scripts/shopee_hourly_sync.php
 * A unified CLI script designed to be run via Hostinger Cron Job every hour.
 * It will automatically sync both Shopee Orders and Shopee Finances.
 */

// Only allow CLI execution to prevent web-based DOS
if (php_sapi_name() !== 'cli') {
    die("This script can only be run from the command line.");
}

echo "Starting Shopee Auto-Sync [".date('Y-m-d H:i:s')."]\n";
echo "--------------------------------------------------\n";

// Define base path to ensure relative requires work correctly
$baseDir = dirname(__DIR__);

// 1. Sync Orders
echo "[1/2] Syncing Shopee Orders (Last 15 days)...\n";
ob_start(); // Capture output to prevent HTML/JSON spilling to cron logs unless there's an error
$_GET['days'] = 15; // Set parameter for sync_orders.php
try {
    require $baseDir . '/api/shopee/sync_orders.php';
} catch (Exception $e) {
    echo "Error syncing orders: " . $e->getMessage() . "\n";
}
$ordersOutput = ob_get_clean();
$ordersResult = json_decode($ordersOutput, true);
if (isset($ordersResult['success']) && $ordersResult['success']) {
    echo "Success! Orders Synced: " . ($ordersResult['inserted_or_updated'] ?? 0) . "\n";
} else {
    echo "Failed! Response: " . $ordersOutput . "\n";
}

echo "\n";

// 2. Sync Finances
echo "[2/2] Syncing Shopee Finances (Pending Escrows)...\n";
ob_start();
try {
    require $baseDir . '/api/shopee/sync_finances.php';
} catch (Exception $e) {
    echo "Error syncing finances: " . $e->getMessage() . "\n";
}
$financesOutput = ob_get_clean();
$financesResult = json_decode($financesOutput, true);
if (isset($financesResult['success']) && $financesResult['success']) {
    echo "Success! Finances Synced: " . ($financesResult['inserted_or_updated'] ?? 0) . "\n";
} else {
    echo "Failed! Response: " . $financesOutput . "\n";
}

echo "--------------------------------------------------\n";
echo "Shopee Auto-Sync Completed [".date('Y-m-d H:i:s')."]\n";

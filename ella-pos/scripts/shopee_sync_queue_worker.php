<?php
/**
 * scripts/shopee_sync_queue_worker.php
 * A CLI script designed to be run via Hostinger Cron Job every 5 minutes.
 * Processes pending Shopee stock updates from the shopee_sync_queue table.
 */

if (php_sapi_name() !== 'cli') {
    die("This script can only be run from the command line.");
}

$baseDir = dirname(__DIR__);
require_once $baseDir . '/config/config.php';
require_once $baseDir . '/config/database.php';
require_once $baseDir . '/classes/ShopeeAPI.php';

echo "Starting Shopee Sync Queue Worker [" . date('Y-m-d H:i:s') . "]\n";
echo "--------------------------------------------------\n";



try {
    $db = new Database();
    $conn = $db->getConnection();
} catch (Exception $e) {
    die("Database connection failed: " . $e->getMessage() . "\n");
}

// 1. Check if Shopee is configured and active
$shopeeCfg = $conn->query("SELECT * FROM shopee_config WHERE is_active = 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$shopeeCfg || empty($shopeeCfg['access_token'])) {
    die("Shopee is not active or missing access token.\n");
}

$isTest = $shopeeCfg['environment'] === 'test';
$api = new ShopeeAPI($shopeeCfg['partner_id'], $shopeeCfg['partner_key'], $isTest);

// 2. Fetch up to 50 pending items
$stmt = $conn->query("
    SELECT id, shopee_item_id, shopee_model_id, target_stock, attempts
    FROM shopee_sync_queue
    WHERE status = 'pending' AND attempts < 3
    ORDER BY created_at ASC
    LIMIT 50
");
$pendingItems = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($pendingItems)) {
    echo "No pending items in the queue.\n";
    exit;
}

echo "Found " . count($pendingItems) . " items to sync.\n";

$successCount = 0;
$failCount = 0;

$updateStatusStmt = $conn->prepare("
    UPDATE shopee_sync_queue 
    SET status = ?, attempts = attempts + 1, error_message = ?, processed_at = NOW()
    WHERE id = ?
");

foreach ($pendingItems as $item) {
    $stockItem = [ 'seller_stock' => [ [ 'stock' => (int)$item['target_stock'] ] ] ];
    if (!empty($item['shopee_model_id'])) {
        $stockItem['model_id'] = (int)$item['shopee_model_id'];
    }

    $body = [
        'item_id' => (int)$item['shopee_item_id'],
        'stock_list' => [$stockItem]
    ];

    try {
        $api->post('/api/v2/product/update_stock', $body, $shopeeCfg['access_token'], $shopeeCfg['shop_id']);
        
        // Success
        $updateStatusStmt->execute(['success', null, $item['id']]);
        $successCount++;
    } catch (Exception $e) {
        $errorMsg = $e->getMessage();
        // If it's failed but we still have attempts left, leave it pending. Else mark failed.
        $newStatus = ($item['attempts'] + 1 >= 3) ? 'failed' : 'pending';
        $updateStatusStmt->execute([$newStatus, $errorMsg, $item['id']]);
        $failCount++;
    }
}

echo "--------------------------------------------------\n";
echo "Worker Completed [" . date('Y-m-d H:i:s') . "]\n";
echo "Successfully synced: $successCount\n";
echo "Failed / Retried: $failCount\n";

<?php
// api/inventory/auto_snapshot.php
// CLI cron script — creates scheduled inventory snapshots and purges old ones.
//
// SETUP: Add to Hostinger Cron Jobs:
//   Daily  (2am):      0 2 */1 * *  php /home/USER/public_html/ella-pos/api/inventory/auto_snapshot.php
//   Every 6 hours:     0 0-23/6 * * * php /home/USER/public_html/ella-pos/api/inventory/auto_snapshot.php
//   Every 12 hours:    0 0,12 * * *   php /home/USER/public_html/ella-pos/api/inventory/auto_snapshot.php
//   Weekly (Sunday):   0 2 * * 0      php /home/USER/public_html/ella-pos/api/inventory/auto_snapshot.php

// ── CLI guard — blocks browser access ────────────────────────────────────────
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('This script can only be executed from the command line.');
}

// ── Bootstrap ─────────────────────────────────────────────────────────────────
define('ROOT_PATH', dirname(__DIR__, 2) . '/');
require_once ROOT_PATH . 'config/config.php';
require_once ROOT_PATH . 'config/database.php';
require_once __DIR__ . '/snapshot_helpers.php'; // createSnapshotInternal(), logSnapshotAudit()

// ── Logger ────────────────────────────────────────────────────────────────────
function logMsg(string $level, string $msg): void
{
    $ts = date('Y-m-d H:i:s');
    echo "[{$ts}] [{$level}] {$msg}" . PHP_EOL;
}

// ── Advisory lock — prevents overlapping runs ─────────────────────────────────
$lockDir = ROOT_PATH . 'tmp';
if (!is_dir($lockDir)) {
    @mkdir($lockDir, 0755, true);
}
$lockFile = $lockDir . '/auto_snapshot.lock';
$lock = @fopen($lockFile, 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    logMsg('WARN', 'Another instance is already running. Exiting.');
    exit(0);
}

// ── Main ──────────────────────────────────────────────────────────────────────
$exitCode = 0;
try {
    $db   = new Database();
    $conn = $db->getConnection();

    // 1. Load settings
    $settings = $conn->query(
        "SELECT setting_key, setting_value FROM inventory_snapshot_settings"
    )->fetchAll(PDO::FETCH_KEY_PAIR);

    $enabled   = ($settings['auto_snapshot_enabled']   ?? '0') === '1';
    $frequency = $settings['auto_snapshot_frequency']  ?? 'daily';
    $retention = max(1, (int)($settings['auto_snapshot_retention'] ?? 30));
    $lastRan   = $settings['last_auto_snapshot_at']    ?? null;

    if (!$enabled) {
        logMsg('INFO', 'Auto-snapshots are disabled in settings. Exiting.');
        exit(0);
    }

    // 2. Check if the configured interval has elapsed
    $intervalMap = [
        'every_6h'  =>  6 * 3600,
        'every_12h' => 12 * 3600,
        'daily'     => 24 * 3600,
        'weekly'    =>  7 * 24 * 3600,
    ];
    $interval = $intervalMap[$frequency] ?? (24 * 3600);

    if ($lastRan !== null && $lastRan !== 'NULL') {
        $elapsed = time() - strtotime($lastRan);
        if ($elapsed < $interval) {
            $remainMins = (int)ceil(($interval - $elapsed) / 60);
            logMsg('INFO', "Not due yet. Next auto-snapshot in ~{$remainMins} minutes.");
            exit(0);
        }
    }

    logMsg('INFO', "Starting auto-snapshot. Frequency: {$frequency}, Retention: {$retention}.");

    // 2.5 Check Threshold
    $threshold = max(0, (int)($settings['auto_snapshot_threshold'] ?? 0));
    if ($threshold > 0) {
        $lastSnapId = (int)$conn->query("SELECT id FROM inventory_snapshots ORDER BY created_at DESC LIMIT 1")->fetchColumn();
        if ($lastSnapId > 0) {
            $changes = (int)$conn->query("
                SELECT SUM(ABS(
                    (COALESCE(i1.quantity, 0) + COALESCE(i2.quantity, 0)) - COALESCE(si.total_stock, 0)
                ))
                FROM product_variations v
                LEFT JOIN inventory i1 ON i1.variation_id = v.variation_id AND i1.store_id = 1
                LEFT JOIN inventory i2 ON i2.variation_id = v.variation_id AND i2.store_id = 2
                LEFT JOIN inventory_snapshot_items si ON si.variation_id = v.variation_id AND si.snapshot_id = {$lastSnapId}
                WHERE v.status = 'active'
            ")->fetchColumn();

            if ($changes < $threshold) {
                logMsg('INFO', "Skipping auto-snapshot. Total stock changes ({$changes}) are below the threshold ({$threshold}).");
                
                // Update last-ran timestamp so we don't try again until the next interval
                $conn->prepare(
                    "INSERT INTO inventory_snapshot_settings (setting_key, setting_value)
                     VALUES ('last_auto_snapshot_at', ?)
                     ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
                )->execute([date('Y-m-d H:i:s')]);
                
                exit(0);
            }
        }
    }

    // 3. Create the snapshot
    $conn->beginTransaction();
    $name       = 'Auto Snapshot — ' . date('Y-m-d H:i:s');
    $snapshotId = createSnapshotInternal($conn, $name, 'Scheduled automatic snapshot.', 'auto', 0, 'System (Cron)');
    $count      = (int)$conn->query(
        "SELECT total_products FROM inventory_snapshots WHERE id = {$snapshotId}"
    )->fetchColumn();

    // 4. Update last-ran timestamp
    $conn->prepare(
        "INSERT INTO inventory_snapshot_settings (setting_key, setting_value)
         VALUES ('last_auto_snapshot_at', ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
    )->execute([date('Y-m-d H:i:s')]);

    // 5. Audit log
    logSnapshotAudit(
        $conn, 'AUTO_SNAPSHOT', $snapshotId, $name, null,
        $count, 'Scheduled auto-snapshot.', 0, 'System (Cron)', '127.0.0.1'
    );

    $conn->commit();
    logMsg('OK', "Snapshot created — ID: {$snapshotId}, Products: {$count}.");

    // 6. Purge old snapshots beyond retention limit (applies to all types)
    $allIds = $conn->query(
        "SELECT id FROM inventory_snapshots ORDER BY created_at DESC"
    )->fetchAll(PDO::FETCH_COLUMN);

    $toDelete = array_slice($allIds, $retention);
    if (!empty($toDelete)) {
        $ph = implode(',', array_fill(0, count($toDelete), '?'));
        $conn->prepare("DELETE FROM inventory_snapshots WHERE id IN ({$ph})")->execute($toDelete);
        logMsg('INFO', 'Purged ' . count($toDelete) . " old snapshot(s). Global retention limit: {$retention}.");
    }

    logMsg('OK', 'Auto-snapshot complete.');

} catch (Exception $e) {
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    logMsg('ERROR', 'Fatal: ' . $e->getMessage());
    $exitCode = 1;
} finally {
    if ($lock) {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

exit($exitCode);

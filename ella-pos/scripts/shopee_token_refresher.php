<?php
/**
 * scripts/shopee_token_refresher.php
 * ─────────────────────────────────────────────────────────────────────────────
 * Dedicated background script to keep the Shopee access token alive.
 * Checks if the token is expiring within 15 minutes and refreshes it proactively.
 *
 * ✅ Runs INDEPENDENTLY — does NOT require any browser/user session.
 * ✅ Designed to run every 10 minutes via Windows Task Scheduler or Linux cron.
 * ✅ Logs all activity (success + failure) to shopee_sync_logs.
 * ✅ Uses an advisory lock to prevent concurrent executions.
 *
 * Recommended Schedule:
 *   Windows Task Scheduler : Every 10 minutes
 *   Linux cron             : `*\/10 * * * * php /path/to/scripts/shopee_token_refresher.php`
 *
 * See docs/CRON_SETUP.md for full setup instructions.
 */

// ── CLI Guard ─────────────────────────────────────────────────────────────────
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('This script can only be run from the command line.');
}

// ── Bootstrap ─────────────────────────────────────────────────────────────────
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/ShopeeApi.php';

// ── Helpers ───────────────────────────────────────────────────────────────────
function logMsg(string $msg): void {
    $timestamp = date('[Y-m-d H:i:s]');
    echo "{$timestamp} [Token Refresher] {$msg}" . PHP_EOL;
}

// ── Lock File (prevent overlapping runs) ──────────────────────────────────────
$lockFile = __DIR__ . '/../tmp/shopee_token_refresher.lock';
$lock = @fopen($lockFile, 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    logMsg('Another instance is already running. Exiting.');
    exit(0);
}

// ── Main Logic ────────────────────────────────────────────────────────────────
$exitCode = 0;

try {
    $db   = new Database();
    $conn = $db->getConnection();

    // 1. Load active Shopee config
    $stmt = $conn->prepare("SELECT * FROM shopee_config WHERE is_active = 1 LIMIT 1");
    $stmt->execute();
    $config = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$config) {
        logMsg('No active Shopee configuration found. Nothing to do.');
        exit(0);
    }

    if (empty($config['refresh_token'])) {
        logMsg('No refresh token in config. Shop may not be authorized yet. Skipping.');
        exit(0);
    }

    // 2. Check how much time is left on the access token
    $expiresAt     = strtotime($config['token_expires_at'] ?? '');
    $timeRemaining = $expiresAt ? ($expiresAt - time()) : 0;
    $minsRemaining = round($timeRemaining / 60, 1);

    if ($timeRemaining <= 0) {
        logMsg("Access token has ALREADY EXPIRED. Attempting emergency refresh...");
    } elseif ($timeRemaining <= 15 * 60) {
        logMsg("Access token expires in {$minsRemaining} minute(s). Refreshing now...");
    } else {
        logMsg("Access token is healthy — {$minsRemaining} minute(s) remaining. No action needed.");
        exit(0);
    }

    // 3. Perform the refresh via Shopee API
    $isTest = $config['environment'] === 'test';
    $shopee = new ShopeeAPI($config['partner_id'], $config['partner_key'], $isTest);
    $result = $shopee->refreshToken($config['refresh_token'], $config['shop_id']);

    // 4. Handle the Shopee API response
    if (isset($result['error']) && !empty($result['error'])) {
        $errorMsg = $result['message'] ?? (is_string($result['error']) ? $result['error'] : json_encode($result['error']));

        // Log failure to DB
        $conn->prepare("
            INSERT INTO shopee_sync_logs
                (event_type, source, status, error_message, created_by, created_at)
            VALUES
                ('token_refresh', 'Auto Refresher (Cron)', 'failed', ?, NULL, NOW())
        ")->execute([$errorMsg]);

        logMsg("ERROR: Shopee API returned an error — {$errorMsg}");
        $exitCode = 1;

    } elseif (!isset($result['access_token'])) {
        $raw = json_encode($result);

        $conn->prepare("
            INSERT INTO shopee_sync_logs
                (event_type, source, status, error_message, created_by, created_at)
            VALUES
                ('token_refresh', 'Auto Refresher (Cron)', 'failed', ?, NULL, NOW())
        ")->execute(["Unexpected API response: {$raw}"]);

        logMsg("ERROR: Unexpected response from Shopee API — {$raw}");
        $exitCode = 1;

    } else {
        // 5. Persist the fresh tokens to the database
        $newAccessToken  = $result['access_token'];
        $newRefreshToken = $result['refresh_token'];
        $expireIn        = $result['expire_in'] ?? 14400; // default 4 hours
        $newExpiresAt    = date('Y-m-d H:i:s', time() + $expireIn);

        $conn->prepare("
            UPDATE shopee_config
            SET access_token    = ?,
                refresh_token   = ?,
                token_expires_at = ?,
                updated_at      = NOW()
            WHERE is_active = 1
        ")->execute([$newAccessToken, $newRefreshToken, $newExpiresAt]);

        // Log success to DB
        $conn->prepare("
            INSERT INTO shopee_sync_logs
                (event_type, source, status, created_by, created_at)
            VALUES
                ('token_refresh', 'Auto Refresher (Cron)', 'success', NULL, NOW())
        ")->execute();

        $expiresInHours = round($expireIn / 3600, 1);
        logMsg("SUCCESS! Token refreshed. New expiry: {$newExpiresAt} (~{$expiresInHours}h from now).");
    }

} catch (Exception $e) {
    logMsg('FATAL: ' . $e->getMessage());
    $exitCode = 1;

} finally {
    // ── Release Lock ──────────────────────────────────────────────────────────
    if ($lock) {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

exit($exitCode);

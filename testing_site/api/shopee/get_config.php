<?php
/**
 * api/shopee/get_config.php — Get current Shopee configuration status
 */
header("Content-Type: application/json");
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';

requireLogin();
if (!hasPermission('shopee_sync')) {
    echo json_encode(['success' => false, 'error' => 'Permission denied. Please request access from admin Les.']);
    exit;
}


try {
    $db = new Database();
    $conn = $db->getConnection();

    $stmt = $conn->prepare("SELECT id, environment, partner_id, partner_key, shop_id, shop_region, token_expires_at, access_token, shop_name, is_active, low_stock_threshold, out_of_stock_alerts, buffer_stock,
        CASE WHEN access_token IS NOT NULL AND access_token != '' THEN 1 ELSE 0 END as has_token,
        CASE WHEN refresh_token IS NOT NULL AND refresh_token != '' THEN 1 ELSE 0 END as has_refresh,
        CASE WHEN partner_key IS NOT NULL AND partner_key != '' THEN 1 ELSE 0 END as has_key,
        created_at, updated_at
        FROM shopee_config WHERE is_active = 1 LIMIT 1");
    $stmt->execute();
    $config = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$config) {
        echo json_encode(['success' => true, 'configured' => false]);
        exit;
    }

    // Check token validity
    $tokenStatus = 'none';
    $tokenDaysLeft = 0;
    if ($config['has_token'] && $config['token_expires_at']) {
        $expiresAt = strtotime($config['token_expires_at']);
        $now = time();
        if ($expiresAt > $now) {
            $tokenStatus = 'valid';
            $tokenDaysLeft = round(($expiresAt - $now) / 86400, 1);
        } else {
            $tokenStatus = 'expired';
        }
    }

    // Count products
    $countStmt = $conn->query("SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN mapping_status IN ('auto','manual') THEN 1 ELSE 0 END) as mapped,
        SUM(CASE WHEN mapping_status = 'unmapped' THEN 1 ELSE 0 END) as unmapped
        FROM shopee_product_mappings");
    $counts = $countStmt->fetch(PDO::FETCH_ASSOC);

    $isLes = (isset($_SESSION['username']) && strtolower($_SESSION['username']) === 'les@ella') || (isset($_SESSION['user_id']) && $_SESSION['user_id'] == 12);
    $partnerId = $config['partner_id'];
    $partnerKey = $config['partner_key'];
    $accessToken = $config['access_token'] ?? '';

    if (!$isLes) {
        // Mask the sensitive values for non-Les users
        $partnerId = !empty($partnerId) ? substr($partnerId, 0, 3) . str_repeat('*', max(0, strlen($partnerId) - 3)) : '';
        $partnerKey = !empty($partnerKey) ? substr($partnerKey, 0, 6) . str_repeat('*', 32) : '';
        $accessToken = !empty($accessToken) ? substr($accessToken, 0, 10) . str_repeat('*', 32) : '';
    }

    echo json_encode([
        'success'        => true,
        'configured'     => true,
        'environment'    => $config['environment'],
        'partner_id'     => $partnerId,
        'partner_key'    => $partnerKey,
        'shop_id'        => $config['shop_id'],
        'shop_region'    => $config['shop_region'],
        'access_token'   => $accessToken,
        'has_key'        => (bool) $config['has_key'],
        'authorized'     => (bool) $config['has_token'],
        'token_status'   => $tokenStatus,
        'token_days_left' => $tokenDaysLeft,
        'token_expires'  => $config['token_expires_at'],
        'products_total' => (int) ($counts['total'] ?? 0),
        'products_mapped' => (int) ($counts['mapped'] ?? 0),
        'products_unmapped' => (int) ($counts['unmapped'] ?? 0),
        'low_stock_threshold' => (int) ($config['low_stock_threshold'] ?? 5),
        'out_of_stock_alerts' => (bool) ($config['out_of_stock_alerts'] ?? 1),
        'buffer_stock'        => (int) ($config['buffer_stock'] ?? 0),
        'updated_at'     => $config['updated_at'],
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

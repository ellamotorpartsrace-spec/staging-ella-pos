<?php
/**
 * api/shopee/get_sales_stats.php
 */
header("Content-Type: application/json");
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
requireLogin();

try {
    $db = new Database();
    $conn = $db->getConnection();

    // Date filters
    $dateFrom = isset($_GET['from']) ? $_GET['from'] . ' 00:00:00' : date('Y-m-d 00:00:00');
    $dateTo = isset($_GET['to']) ? $_GET['to'] . ' 23:59:59' : date('Y-m-d 23:59:59');

    // Monthly dates
    $monthStart = date('Y-m-01 00:00:00');
    $monthEnd = date('Y-m-t 23:59:59');

    // Today's Stats
    $stmtToday = $conn->prepare("
        SELECT 
            COUNT(o.id) as orders_count,
            SUM(o.total_amount) as gross_sales,
            SUM(COALESCE(f.payout_amount, 0)) as total_payout,
            SUM(
                (SELECT COALESCE(SUM(capital_cost * quantity_purchased), 0) FROM shopee_order_items WHERE order_sn = o.order_sn)
            ) as total_capital
        FROM shopee_orders o
        LEFT JOIN shopee_financial_transactions f ON o.order_sn = f.order_sn
        WHERE o.create_time BETWEEN ? AND ?
    ");
    $stmtToday->execute([date('Y-m-d 00:00:00'), date('Y-m-d 23:59:59')]);
    $today = $stmtToday->fetch(PDO::FETCH_ASSOC);

    // Month's Stats
    $stmtMonth = $conn->prepare("
        SELECT 
            COUNT(o.id) as orders_count,
            SUM(o.total_amount) as gross_sales,
            SUM(COALESCE(f.payout_amount, 0)) as total_payout,
            SUM(
                (SELECT COALESCE(SUM(capital_cost * quantity_purchased), 0) FROM shopee_order_items WHERE order_sn = o.order_sn)
            ) as total_capital
        FROM shopee_orders o
        LEFT JOIN shopee_financial_transactions f ON o.order_sn = f.order_sn
        WHERE o.create_time BETWEEN ? AND ?
    ");
    $stmtMonth->execute([$monthStart, $monthEnd]);
    $month = $stmtMonth->fetch(PDO::FETCH_ASSOC);

    // Status counts
    $stmtStatus = $conn->prepare("
        SELECT order_status, COUNT(*) as count 
        FROM shopee_orders 
        GROUP BY order_status
    ");
    $stmtStatus->execute();
    $statuses = $stmtStatus->fetchAll(PDO::FETCH_KEY_PAIR);

    $pending = ($statuses['READY_TO_SHIP'] ?? 0) + ($statuses['UNPAID'] ?? 0);
    $cancelled = $statuses['CANCELLED'] ?? 0;
    $returned = $statuses['RETURN_REFUND'] ?? 0;

    echo json_encode([
        'success' => true,
        'today' => [
            'gross' => (float)$today['gross_sales'],
            'orders' => (int)$today['orders_count'],
            'net_profit' => (float)$today['total_payout'] - (float)$today['total_capital']
        ],
        'month' => [
            'gross' => (float)$month['gross_sales'],
            'orders' => (int)$month['orders_count'],
            'net_profit' => (float)$month['total_payout'] - (float)$month['total_capital']
        ],
        'statuses' => [
            'pending' => $pending,
            'cancelled' => $cancelled,
            'returned' => $returned
        ]
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

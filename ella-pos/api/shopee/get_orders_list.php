<?php
/**
 * api/shopee/get_orders_list.php
 */
header("Content-Type: application/json");
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
requireLogin();

try {
    $db = new Database();
    $conn = $db->getConnection();

    $range = $_GET['range'] ?? 'this_month';
    $status = $_GET['status'] ?? 'all';
    $search = trim($_GET['search'] ?? '');

    $dateFrom = '1970-01-01 00:00:00';
    $dateTo = date('Y-m-d 23:59:59');

    if ($range === 'today') {
        $dateFrom = date('Y-m-d 00:00:00');
    } elseif ($range === 'this_week') {
        $dateFrom = date('Y-m-d 00:00:00', strtotime('monday this week'));
    } elseif ($range === 'this_month') {
        $dateFrom = date('Y-m-01 00:00:00');
    } elseif ($range === 'custom') {
        if (!empty($_GET['date_from'])) {
            $dateFrom = $_GET['date_from'] . ' 00:00:00';
        }
        if (!empty($_GET['date_to'])) {
            $dateTo = $_GET['date_to'] . ' 23:59:59';
        }
    }

    $params = [$dateFrom, $dateTo];
    $where = "WHERE o.create_time BETWEEN ? AND ?";

    if ($status !== 'all') {
        $where .= " AND o.order_status = ?";
        $params[] = $status;
    }

    if (!empty($search)) {
        $where .= " AND (o.order_sn LIKE ? OR o.buyer_username LIKE ? OR EXISTS (
            SELECT 1 FROM shopee_order_items i WHERE i.order_sn = o.order_sn AND (i.item_sku LIKE ? OR i.item_name LIKE ?)
        ))";
        $searchTerm = "%$search%";
        array_push($params, $searchTerm, $searchTerm, $searchTerm, $searchTerm);
    }

    $sql = "
        SELECT 
            o.*,
            f.payout_amount,
            f.escrow_release_time,
            (SELECT SUM(capital_cost * quantity_purchased) FROM shopee_order_items WHERE order_sn = o.order_sn) as total_capital,
            (SELECT GROUP_CONCAT(CONCAT(quantity_purchased, 'x ', item_name) SEPARATOR ', ') FROM shopee_order_items WHERE order_sn = o.order_sn) as items_summary
        FROM shopee_orders o
        LEFT JOIN shopee_financial_transactions f ON o.order_sn = f.order_sn
        $where
        ORDER BY o.create_time DESC
        LIMIT 1000
    ";

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'orders' => $orders
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

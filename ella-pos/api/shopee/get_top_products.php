<?php
/**
 * api/shopee/get_top_products.php
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

    $sql = "
        SELECT 
            i.item_sku,
            i.item_name,
            i.model_name,
            SUM(i.quantity_purchased) as total_sold,
            SUM(i.quantity_purchased * i.discounted_price) as total_revenue,
            SUM(i.quantity_purchased * i.capital_cost) as total_capital,
            (SUM(i.quantity_purchased * i.discounted_price) - SUM(i.quantity_purchased * i.capital_cost)) as estimated_profit
        FROM shopee_order_items i
        JOIN shopee_orders o ON i.order_sn = o.order_sn
        WHERE o.create_time BETWEEN ? AND ? 
          AND o.order_status NOT IN ('CANCELLED', 'RETURN_REFUND')
        GROUP BY i.item_id, i.model_id
        ORDER BY total_sold DESC
        LIMIT 50
    ";

    $stmt = $conn->prepare($sql);
    $stmt->execute([$dateFrom, $dateTo]);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'products' => $products
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

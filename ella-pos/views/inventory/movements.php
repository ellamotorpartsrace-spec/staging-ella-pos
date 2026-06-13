<?php
// views/inventory/movements.php - Stock Movements History
require_once '../../config/config.php';
require_once '../../includes/auth.php';

// Auth Check
requireLogin();
if ($_SESSION['role'] !== 'admin' && !hasPermission('adjust_prices') && !in_array($_SESSION['role'], ['manager', 'stockman'])) {
    denyAccess("You do not have permission to view stock movements.");
}

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
require_once '../../config/database.php';
require_once '../../includes/reference_attachment_storage.php';

$db = new Database();
$conn = $db->getConnection();
ensureReferenceAttachmentBackupColumns($conn);

// Filters
$search = $_GET['search'] ?? '';
$type_filter = $_GET['type'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

// Build Query
$sql = "
    SELECT 
        sm.movement_id,
        sm.variation_id,
        sm.store_id,
        sm.type,
        sm.quantity,
        sm.previous_stock,
        sm.new_stock,
        sm.reference,
        sm.remarks,
        sm.created_at,
        sm.status,
        pv.variation_name,
        pv.sku,
        pv.barcode,
        COALESCE(sm.capital_cost, pv.price_capital) as price_capital,
        pv.price_retail,
        p.product_name,
        p.brand_name,
        u.full_name as created_by_name,
        first_in.first_stock_in_id,
        ra.all_images_data,
        COALESCE(ra.attachment_count, 0) as attachment_count
    FROM stock_movements sm
    JOIN product_variations pv ON sm.variation_id = pv.variation_id
    JOIN products p ON pv.product_id = p.product_id
    LEFT JOIN users u ON sm.created_by = u.id
    LEFT JOIN (
        SELECT variation_id, COALESCE(store_id, 1) as store_id, MIN(movement_id) as first_stock_in_id
        FROM stock_movements
        WHERE type = 'stock_in'
        GROUP BY variation_id, COALESCE(store_id, 1)
    ) first_in ON first_in.variation_id = sm.variation_id AND first_in.store_id = COALESCE(sm.store_id, 1)
    LEFT JOIN (
        SELECT reference_number, 
               GROUP_CONCAT(CONCAT(
                   id,
                   ':',
                   CASE
                       WHEN image_data IS NOT NULL AND OCTET_LENGTH(image_data) > 0
                           THEN CONCAT('api/inventory/reference_attachment_image.php?id=', id)
                       ELSE NULLIF(image_path, '')
                   END
               ) ORDER BY id ASC) as all_images_data,
               COUNT(*) as attachment_count 
        FROM reference_attachments 
        GROUP BY reference_number
    ) ra ON sm.reference = ra.reference_number
    WHERE 1=1
";

$params = [];

if (!empty($search)) {
    $searchTokens = preg_split('/\s+/', trim($search), -1, PREG_SPLIT_NO_EMPTY);

    foreach ($searchTokens as $token) {
        $sql .= " AND (
            p.product_name LIKE ?
            OR p.brand_name LIKE ?
            OR pv.variation_name LIKE ?
            OR pv.sku LIKE ?
            OR pv.barcode LIKE ?
            OR sm.reference LIKE ?
        )";
        $term = "%{$token}%";
        $params = array_merge($params, [$term, $term, $term, $term, $term, $term]);
    }
}

if (!empty($type_filter)) {
    $sql .= " AND sm.type = ?";
    $params[] = $type_filter;
}

if (!empty($date_from)) {
    $sql .= " AND DATE(sm.created_at) >= ?";
    $params[] = $date_from;
}

if (!empty($date_to)) {
    $sql .= " AND DATE(sm.created_at) <= ?";
    $params[] = $date_to;
}

$sql .= " ORDER BY sm.created_at DESC LIMIT 500";

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$movements = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ==========================================
// DATA PROCESSING FOR ENHANCEMENTS
// ==========================================

$daily_trends = [];
$summary_data = [];

// Initialize types for chart
$chart_types = ['sales', 'stock_in', 'stock_out', 'return'];

foreach ($movements as $m) {
    // 1. Grouped Summary Data
    $vid = $m['variation_id'];
    if (!isset($summary_data[$vid])) {
        $summary_data[$vid] = [
            'product_name' => $m['product_name'],
            'brand_name' => $m['brand_name'],
            'variation_name' => $m['variation_name'],
            'sku' => $m['sku'],
            'total_sales_qty' => 0,
            'total_sales_revenue' => 0,
            'total_restock_qty' => 0,
            'total_restock_cost' => 0,
            'current_stock' => $m['new_stock'] // approx
        ];
    }
    
    $qty = abs((int)$m['quantity']);
    $capital = (float)($m['price_capital'] ?? 0);
    $retail = (float)($m['price_retail'] ?? 0);
    
    // Approximate revenue if it's a sale (since true sale price is in order_items)
    // We use price_retail as an estimate for revenue
    if ($m['type'] === 'sales' || $m['type'] === 'online_sale') {
        $summary_data[$vid]['total_sales_qty'] += $qty;
        $summary_data[$vid]['total_sales_revenue'] += ($qty * $retail);
    } elseif ($m['type'] === 'stock_in') {
        $summary_data[$vid]['total_restock_qty'] += $qty;
        $summary_data[$vid]['total_restock_cost'] += ($qty * $capital);
    }
    
    // Update current stock to the latest movement's new_stock (assuming ordered by created_at DESC)
    if (!isset($summary_data[$vid]['_latest_seen'])) {
        $summary_data[$vid]['current_stock'] = $m['new_stock'];
        $summary_data[$vid]['_latest_seen'] = true;
    }

    // 2. Daily Trends Data
    $date = date('Y-m-d', strtotime($m['created_at']));
    if (!isset($daily_trends[$date])) {
        $daily_trends[$date] = array_fill_keys($chart_types, 0);
    }
    if (in_array($m['type'], $chart_types)) {
        $daily_trends[$date][$m['type']] += $qty;
    }
}

// Sort daily trends by date ascending for Chart.js
ksort($daily_trends);

$chart_labels = json_encode(array_keys($daily_trends));
$chart_sales = json_encode(array_column($daily_trends, 'sales'));
$chart_stockin = json_encode(array_column($daily_trends, 'stock_in'));
$chart_stockout = json_encode(array_column($daily_trends, 'stock_out'));
$chart_returns = json_encode(array_column($daily_trends, 'return'));

// Type labels and colors
$type_config = [
    'stock_in' => ['label' => 'Stock In', 'icon' => 'fa-arrow-down', 'color' => 'success', 'bg' => 'bg-success'],
    'stock_out' => ['label' => 'Stock Out', 'icon' => 'fa-arrow-up', 'color' => 'danger', 'bg' => 'bg-danger'],
    'sales' => ['label' => 'Sales', 'icon' => 'fa-shopping-cart', 'color' => 'primary', 'bg' => 'bg-primary'],
    'adjustment' => ['label' => 'Adjustment', 'icon' => 'fa-sliders', 'color' => 'warning', 'bg' => 'bg-warning'],
    'return' => ['label' => 'Return', 'icon' => 'fa-rotate-left', 'color' => 'info', 'bg' => 'bg-info'],
    'allocation_to_online' => ['label' => 'Alloc to Online', 'icon' => 'fa-globe', 'color' => 'info', 'bg' => 'bg-info'],
    'allocation_to_physical' => ['label' => 'Alloc to Physical', 'icon' => 'fa-shop', 'color' => 'dark', 'bg' => 'bg-dark'],
    'online_sale' => ['label' => 'Online Sale', 'icon' => 'fa-cloud-arrow-down', 'color' => 'primary', 'bg' => 'bg-primary'],
    'online_adjustment' => ['label' => 'Online Adjust', 'icon' => 'fa-cloud', 'color' => 'warning', 'bg' => 'bg-warning'],
    'allocation_adjustment' => ['label' => 'Shopee Allocation', 'icon' => 'fa-shopping-bag', 'color' => 'shopee', 'bg' => 'bg-shopee']
];

function isFirstStockInMovement(array $row): bool
{
    return ($row['type'] ?? '') === 'stock_in'
        && !empty($row['first_stock_in_id'])
        && (int) $row['movement_id'] === (int) $row['first_stock_in_id'];
}

function movementStoreMeta($storeId): array
{
    $storeId = (int) ($storeId ?? 1);
    if ($storeId === 2) {
        return ['label' => 'Online Shop', 'icon' => 'fa-globe', 'class' => 'text-info'];
    }

    return ['label' => 'Physical Store', 'icon' => 'fa-store', 'class' => 'text-secondary'];
}

function hasNegativeStoreBalance(array $movements): bool
{
    foreach ($movements as $movement) {
        if ((int) ($movement['previous_stock'] ?? 0) < 0 || (int) ($movement['new_stock'] ?? 0) < 0) {
            return true;
        }
    }

    return false;
}

$hasNegativeStoreBalance = hasNegativeStoreBalance($movements);

function displayStoreStockValue($value): int
{
    return max(0, (int) $value);
}
?>

<style>
    /* Mobile-first responsive styles */
    .movement-card {
        transition: all 0.2s ease;
        border-left: 4px solid transparent;
    }

    .movement-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
    }

    .movement-card.type-stock_in {
        border-left-color: var(--bs-success);
    }

    .movement-card.type-stock_out {
        border-left-color: var(--bs-danger);
    }

    .movement-card.type-sales {
        border-left-color: var(--bs-primary);
    }

    .movement-card.type-adjustment {
        border-left-color: var(--bs-warning);
    }

    .movement-card.type-return {
        border-left-color: var(--bs-info);
    }

    .movement-card.type-allocation_to_online {
        border-left-color: var(--bs-info);
    }

    .movement-card.type-allocation_to_physical {
        border-left-color: var(--bs-dark);
    }

    .movement-card.type-online_sale {
        border-left-color: var(--bs-primary);
    }

    .movement-card.type-online_adjustment {
        border-left-color: var(--bs-warning);
    }

    .movement-card.type-allocation_adjustment {
        border-left-color: #ee4d2d; /* Shopee orange */
    }

    .qty-badge {
        font-size: 1.1rem;
        min-width: 60px;
    }

    .first-stock-badge {
        font-size: 0.68rem;
        vertical-align: middle;
        white-space: nowrap;
    }

    .first-stock-note {
        color: var(--bs-success);
        font-size: 0.74rem;
        font-weight: 700;
    }

    .store-stock-meta {
        font-size: 0.72rem;
        font-weight: 700;
        line-height: 1.1;
    }

    .negative-stock-note {
        color: var(--bs-danger);
        font-size: 0.72rem;
        font-weight: 700;
    }

    /* Desktop table view */
    @media (min-width: 992px) {
        .mobile-cards {
            display: none !important;
        }

        .desktop-table {
            display: block !important;
        }
    }

    /* Mobile card view */
    @media (max-width: 991.98px) {
        .mobile-cards {
            display: block !important;
        }

        .desktop-table {
            display: none !important;
        }

        .filter-row .col-md-2 {
            margin-bottom: 0.5rem;
        }
    }

    .stat-card {
        border-radius: 12px;
        transition: transform 0.2s;
    }

    .stat-card:hover {
        transform: scale(1.02);
    }
    /* Shopee Badge colors */
    .bg-shopee { background-color: rgba(238, 77, 45, 0.1) !important; }
    .text-shopee { color: #ee4d2d !important; }
</style>

<div class="container-fluid p-3 p-lg-4">

    <!-- Page Header -->
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-4">
        <div class="mb-3 mb-md-0">
            <h4 class="fw-bold text-dark mb-1">
                <i class="fa-solid fa-arrow-right-arrow-left text-primary me-2"></i>Stock Movements
            </h4>
            <p class="text-muted mb-0 small">Track all inventory changes and transactions</p>
        </div>
        <div class="d-flex gap-2">
            <div class="btn-group" role="group">
                <input type="radio" class="btn-check" name="view_mode" id="btn-timeline" autocomplete="off" checked onchange="toggleViewMode('timeline')">
                <label class="btn btn-outline-primary" for="btn-timeline"><i class="fa-solid fa-list me-1"></i>Timeline</label>

                <input type="radio" class="btn-check" name="view_mode" id="btn-summary" autocomplete="off" onchange="toggleViewMode('summary')">
                <label class="btn btn-outline-primary" for="btn-summary"><i class="fa-solid fa-table-cells-large me-1"></i>Summary</label>
            </div>
            <a href="restock.php" class="btn btn-success">
                <i class="fa-solid fa-plus me-1"></i><span class="d-none d-sm-inline">New Stock In</span>
            </a>
        </div>
    </div>

    <!-- Stats Cards -->
    <div class="row g-3 mb-4">
        <?php
        $stat_items = [
            ['type' => 'stock_in', 'label' => 'Stock In', 'icon' => 'fa-arrow-down', 'color' => 'success'],
            ['type' => 'sales', 'label' => 'Sales', 'icon' => 'fa-shopping-cart', 'color' => 'primary'],
            ['type' => 'stock_out', 'label' => 'Stock Out', 'icon' => 'fa-arrow-up', 'color' => 'danger'],
            ['type' => 'adjustment', 'label' => 'Adjustments', 'icon' => 'fa-sliders', 'color' => 'warning'],
        ];
        foreach ($stat_items as $stat):
            $count = 0;
            $total_qty = 0;
            $total_value = 0;
            // Count from our results
            foreach ($movements as $m) {
                if ($m['type'] === $stat['type']) {
                    $count++;
                    $qty = abs((int)$m['quantity']);
                    $total_qty += $qty;
                    if ($stat['type'] === 'sales') {
                        $total_value += ($qty * (float)($m['price_retail'] ?? 0));
                    } elseif ($stat['type'] === 'stock_in') {
                        $total_value += ($qty * (float)($m['price_capital'] ?? 0));
                    }
                }
            }
            ?>
            <div class="col-6 col-lg-3">
                <div class="card stat-card border-0 shadow-sm h-100">
                    <div class="card-body p-3">
                        <div class="d-flex align-items-center">
                            <div class="rounded-circle bg-<?= $stat['color'] ?> bg-opacity-10 p-2 me-3">
                                <i class="fa-solid <?= $stat['icon'] ?> text-<?= $stat['color'] ?> fa-lg"></i>
                            </div>
                            <div>
                                <div class="h5 fw-bold mb-0 text-<?= $stat['color'] ?>"><?= number_format($total_qty) ?> items</div>
                                <?php if ($stat['type'] === 'sales'): ?>
                                    <small class="text-primary fw-bold d-block" style="font-size: 0.75rem;">Est. Rev: ₱<?= number_format($total_value, 2) ?></small>
                                <?php elseif ($stat['type'] === 'stock_in'): ?>
                                    <small class="text-success fw-bold d-block" style="font-size: 0.75rem;">Est. Cost: ₱<?= number_format($total_value, 2) ?></small>
                                <?php else: ?>
                                    <small class="text-muted fw-bold d-block"><?= $stat['label'] ?></small>
                                <?php endif; ?>
                                <small class="text-muted" style="font-size: 0.7rem;"><?= number_format($count) ?> transactions</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Filters -->
    <div class="card shadow-sm border-0 mb-4">
        <div class="card-body p-3">
            <form method="GET" class="row g-2 filter-row align-items-end">
                <div class="col-12 col-md-3">
                    <label class="form-label small fw-bold text-muted mb-1">SEARCH</label>
                    <div class="input-group">
                        <span class="input-group-text bg-white"><i class="fa-solid fa-search text-muted"></i></span>
                        <input type="text" name="search" id="movements-search" class="form-control"
                            placeholder="Product, SKU, barcode, reference..." value="<?= htmlspecialchars($search) ?>"
                            autocomplete="off">
                        <span class="input-group-text d-none bg-white" id="movements-search-spinner">
                            <i class="fa-solid fa-spinner fa-spin text-primary"></i>
                        </span>
                    </div>
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label small fw-bold text-muted mb-1">TYPE</label>
                    <select name="type" class="form-select">
                        <option value="">All Types</option>
                        <?php foreach ($type_config as $key => $cfg): ?>
                            <option value="<?= $key ?>" <?= $type_filter === $key ? 'selected' : '' ?>>
                                <?= $cfg['label'] ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label small fw-bold text-muted mb-1">FROM</label>
                    <input type="date" name="date_from" class="form-control" value="<?= $date_from ?>">
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label small fw-bold text-muted mb-1">TO</label>
                    <input type="date" name="date_to" class="form-control" value="<?= $date_to ?>">
                </div>
                <div class="col-6 col-md-3 d-flex gap-2">
                    <button type="submit" class="btn btn-primary flex-grow-1">
                        <i class="fa-solid fa-filter me-1"></i>Filter
                    </button>
                    <?php if (!empty($search) || !empty($type_filter) || !empty($date_from) || !empty($date_to)): ?>
                        <a href="movements.php" class="btn btn-outline-secondary" title="Reset Filters">
                            <i class="fa-solid fa-xmark"></i>
                        </a>
                    <?php endif; ?>
                    <a href="../../api/inventory/export_movements.php?search=<?= urlencode($search) ?>&type=<?= urlencode($type_filter) ?>&date_from=<?= urlencode($date_from) ?>&date_to=<?= urlencode($date_to) ?>"
                        class="btn btn-success" title="Export to CSV">
                        <i class="fa-solid fa-file-export"></i>
                    </a>
                </div>
            </form>
        </div>
    </div>

    <?php if ($hasNegativeStoreBalance): ?>
        <div class="alert alert-warning border-0 shadow-sm d-flex align-items-start gap-2 mb-3">
            <i class="fa-solid fa-triangle-exclamation mt-1"></i>
            <div>
                <div class="fw-bold">Negative store stock found in the visible movements.</div>
                <div class="small mb-0">Movement History now floors negative displayed stock to 0. Use the sync fix to repair current Physical Store and Online Shop balances.</div>
                <a class="btn btn-sm btn-warning fw-bold mt-2" href="../../api/inventory/sync_inventory.php">
                    <i class="fa-solid fa-wrench me-1"></i>Open Inventory Sync Fix
                </a>
            </div>
        </div>
    <?php endif; ?>

    <!-- Daily Trends Chart -->
    <div class="card shadow-sm border-0 mb-4" id="chart-container">
        <div class="card-body p-3">
            <h6 class="fw-bold mb-3"><i class="fa-solid fa-chart-line text-primary me-2"></i>Daily Movement Trends</h6>
            <div style="height: 250px;">
                <canvas id="dailyTrendChart"></canvas>
            </div>
        </div>
    </div>

    <!-- Results -->
    <div class="card shadow-sm border-0" id="results-card">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
            <h6 class="mb-0 fw-bold">
                <i class="fa-solid fa-list text-primary me-2" id="results-icon"></i><span id="results-title">Movement History</span>
            </h6>
            <span class="badge bg-secondary" id="results-count"><?= count($movements) ?> records</span>
        </div>

        <!-- Summary Table View (Hidden by default) -->
        <div class="card-body p-0 view-summary" style="display: none;">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="bg-light">
                        <tr>
                            <th class="ps-4">Product</th>
                            <th class="text-center">Current Stock</th>
                            <th class="text-center">Total Sales Qty</th>
                            <th class="text-end">Est. Sales Rev</th>
                            <th class="text-center">Total Restock Qty</th>
                            <th class="text-end pe-4">Est. Restock Cost</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($summary_data) > 0): ?>
                            <?php foreach ($summary_data as $vid => $s): ?>
                                <tr>
                                    <td class="ps-4">
                                        <div class="fw-bold text-dark">
                                            <a href="history.php?id=<?= $vid ?>" class="text-dark text-decoration-none">
                                                <?= htmlspecialchars($s['product_name']) ?>
                                            </a>
                                        </div>
                                        <small class="text-muted">
                                            <?= htmlspecialchars($s['brand_name']) ?> | <?= htmlspecialchars($s['variation_name']) ?>
                                            <?php if ($s['sku']): ?> | SKU: <?= htmlspecialchars($s['sku']) ?><?php endif; ?>
                                        </small>
                                    </td>
                                    <td class="text-center fw-bold fs-5">
                                        <?= max(0, $s['current_stock']) ?>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-primary-subtle text-primary fs-6"><?= $s['total_sales_qty'] ?></span>
                                    </td>
                                    <td class="text-end fw-bold text-success">
                                        ₱<?= number_format($s['total_sales_revenue'], 2) ?>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-success-subtle text-success fs-6"><?= $s['total_restock_qty'] ?></span>
                                    </td>
                                    <td class="text-end fw-bold text-danger pe-4">
                                        ₱<?= number_format($s['total_restock_cost'], 2) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" class="text-center py-5">
                                    <i class="fa-solid fa-inbox fa-3x text-muted opacity-25 mb-3"></i>
                                    <h6 class="text-muted">No summary data</h6>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Desktop Table View -->
        <div class="card-body p-0 desktop-table" style="display: none;">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="bg-light">
                        <tr>
                            <th class="ps-4" style="width: 10%;">Date & Time</th>
                            <th style="width: 42%;">Product</th>
                            <th style="width: 11%;">Type</th>
                            <th class="text-center" style="width: 9%;">Qty Change</th>
                            <th class="text-center" style="width: 8%;">Store Stock</th>
                            <th style="width: 15%;">Reference</th>
                            <th style="width: 5%;">By</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($movements) > 0): ?>
                                <?php foreach ($movements as $row):
                                    $cfg = $type_config[$row['type']] ?? ['label' => $row['type'], 'icon' => 'fa-circle', 'color' => 'secondary', 'bg' => 'bg-secondary'];
                                    $isVoided = ($row['status'] ?? '') === 'voided';
                                    $isFirstStockIn = isFirstStockInMovement($row);
                                    $storeMeta = movementStoreMeta($row['store_id'] ?? 1);
                                    $hasNegativeBalance = (int) $row['previous_stock'] < 0 || (int) $row['new_stock'] < 0;
                                    $displayPreviousStock = displayStoreStockValue($row['previous_stock']);
                                    $displayNewStock = displayStoreStockValue($row['new_stock']);
                                    $skuText = trim((string) ($row['sku'] ?? ''));

                                    // Determine sign and color based on actual quantity value
                                    // Sales are always deductions but stored as positive numbers
                                    $is_positive = $row['quantity'] >= 0 && $row['type'] !== 'sales';
                                    $qty_sign = $is_positive ? '+' : '-';
                                    $qty_color = $is_positive ? 'success' : 'danger';
                                    
                                    if ($isVoided) {
                                        $qty_color = 'secondary';
                                        $qty_sign = '';
                                    }
                                    ?>
                                    <tr class="<?= $isVoided ? 'opacity-50 table-light' : '' ?>">
                                    <td class="ps-4">
                                        <div class="fw-bold small"><?= date('M d, Y', strtotime($row['created_at'])) ?></div>
                                        <small class="text-muted"><?= date('h:i A', strtotime($row['created_at'])) ?></small>
                                    </td>
                                    <td>
                                        <div class="fw-bold text-dark <?= $isVoided ? 'text-decoration-line-through' : '' ?>">
                                            <a href="history.php?id=<?= urlencode($row['variation_id']) ?>"
                                                class="text-dark text-decoration-none">
                                                <?= htmlspecialchars($row['product_name']) ?>
                                            </a>
                                            <?php if ($isVoided): ?>
                                                <span class="badge bg-danger small ms-1" style="font-size: 0.65rem;">VOIDED</span>
                                            <?php endif; ?>
                                            <?php if ($isFirstStockIn): ?>
                                                <span class="badge rounded-pill bg-success-subtle text-success border border-success-subtle first-stock-badge ms-1">
                                                    <i class="fa-solid fa-circle-plus me-1"></i>First Stock In
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        <small class="text-muted">
                                            <?= htmlspecialchars($row['brand_name']) ?> |
                                            <?= htmlspecialchars($row['variation_name']) ?>
                                            <?php if ($skuText !== ''): ?>
                                                | SKU: <span class="fw-semibold"><?= htmlspecialchars($skuText) ?></span>
                                            <?php endif; ?>
                                        </small>
                                    </td>
                                    <td>
                                        <span class="badge <?= $cfg['bg'] ?> bg-opacity-10 text-<?= $cfg['color'] ?>">
                                            <i class="<?= strpos($cfg['icon'], 'fa-') === 0 && strpos($cfg['icon'], ' ') === false ? 'fa-solid ' . $cfg['icon'] : $cfg['icon'] ?> me-1"></i><?= $cfg['label'] ?>
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-<?= $qty_color ?>-subtle text-<?= $qty_color ?> qty-badge">
                                            <?= $qty_sign ?>         <?= abs($row['quantity']) ?>
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <div class="store-stock-meta <?= $storeMeta['class'] ?> mb-1">
                                            <i class="fa-solid <?= $storeMeta['icon'] ?> me-1"></i><?= $storeMeta['label'] ?>
                                        </div>
                                        <div class="<?= $hasNegativeBalance ? 'text-danger' : '' ?>">
                                            <small class="text-muted"><?= $displayPreviousStock ?></small>
                                            <i class="fa-solid fa-arrow-right mx-1 text-muted"></i>
                                            <span class="fw-bold"><?= $displayNewStock ?></span>
                                        </div>
                                        <?php if ($hasNegativeBalance): ?>
                                            <div class="negative-stock-note mt-1">Negative raw balance displayed as 0</div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="align-middle text-break">
                                        <?php if ($row['reference']): ?>
                                            <a href="reference.php?ref=<?= urlencode($row['reference']) ?>&from=movements"
                                                class="text-decoration-none fw-bold">
                                                <code class="small text-primary" style="white-space: normal; word-break: break-all;"><?= htmlspecialchars($row['reference']) ?></code>
                                            </a>
                                            <?php if ($row['attachment_count'] > 0): ?>
                                                <a href="javascript:void(0)"
                                                    onclick="viewAttachment('<?= $row['all_images_data'] ?>', '<?= htmlspecialchars($row['reference'], ENT_QUOTES) ?>')"
                                                    class="text-primary text-decoration-none small d-block mt-1">
                                                    <i class="fa-solid fa-paperclip me-1"></i>View Receipt
                                                    <?php if ($row['attachment_count'] > 1): ?>
                                                        <span class="badge bg-secondary rounded-pill small ms-1"
                                                            style="font-size: 0.7em;">+<?= $row['attachment_count'] - 1 ?></span>
                                                    <?php endif; ?>
                                                </a>
                                            <?php else: ?>
                                                <button class="btn btn-sm btn-link p-0 text-decoration-none mt-1 d-block"
                                                    onclick="openRetroUpload(<?= $row['movement_id'] ?>, '<?= htmlspecialchars($row['reference'], ENT_QUOTES) ?>')">
                                                    <i class="fa-solid fa-cloud-arrow-up"></i> Add Photo
                                                </button>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                            <button class="btn btn-sm btn-link p-0 text-decoration-none ms-2"
                                                onclick="openRetroUpload(<?= $row['movement_id'] ?>, 'No Reference')">
                                                <i class="fa-solid fa-cloud-arrow-up"></i> Add Photo
                                            </button>
                                        <?php endif; ?>
                                        <?php if ($row['remarks']): ?>
                                            <div class="small text-muted text-truncate mt-1" style="max-width: 150px;"
                                                title="<?= htmlspecialchars($row['remarks']) ?>">
                                                <?= htmlspecialchars($row['remarks']) ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($isFirstStockIn): ?>
                                            <div class="first-stock-note mt-1">
                                                <i class="fa-solid fa-clock-rotate-left me-1"></i>First stock entry for this product
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <small class="text-muted">
                                            <?= htmlspecialchars($row['created_by_name'] ?? 'System') ?>
                                        </small>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="7" class="text-center py-5">
                                    <i class="fa-solid fa-inbox fa-3x text-muted opacity-25 mb-3"></i>
                                    <h6 class="text-muted">No movements found</h6>
                                    <p class="small text-muted mb-0">Try adjusting your filters</p>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Mobile Card View -->
        <div class="card-body p-3 mobile-cards" style="display: none;">
            <?php if (count($movements) > 0): ?>
                <div class="d-flex flex-column gap-3">
                    <?php foreach ($movements as $row):
                        $cfg = $type_config[$row['type']] ?? ['label' => $row['type'], 'icon' => 'fa-circle', 'color' => 'secondary', 'bg' => 'bg-secondary'];
                        $isVoided = ($row['status'] ?? '') === 'voided';
                        $isFirstStockIn = isFirstStockInMovement($row);
                        $storeMeta = movementStoreMeta($row['store_id'] ?? 1);
                        $hasNegativeBalance = (int) $row['previous_stock'] < 0 || (int) $row['new_stock'] < 0;
                        $displayPreviousStock = displayStoreStockValue($row['previous_stock']);
                        $displayNewStock = displayStoreStockValue($row['new_stock']);
                        $skuText = trim((string) ($row['sku'] ?? ''));

                        // Sales are always deductions but stored as positive numbers
                        $is_positive = $row['quantity'] >= 0 && $row['type'] !== 'sales';
                        $qty_sign = $is_positive ? '+' : '-';
                        $qty_color = $is_positive ? 'success' : 'danger';
                        
                        if ($isVoided) {
                            $qty_color = 'secondary';
                            $qty_sign = '';
                        }
                        ?>
                        <div class="card movement-card type-<?= $row['type'] ?> border-0 shadow-sm <?= $isVoided ? 'opacity-50 bg-light' : '' ?>">
                            <div class="card-body p-3">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <div class="flex-grow-1">
                                        <div class="fw-bold text-dark <?= $isVoided ? 'text-decoration-line-through' : '' ?>">
                                            <a href="history.php?id=<?= urlencode($row['variation_id']) ?>"
                                                class="text-dark text-decoration-none">
                                                <?= htmlspecialchars($row['product_name']) ?>
                                            </a>
                                            <?php if ($isVoided): ?>
                                                <span class="badge bg-danger ms-1" style="font-size: 0.6rem;">VOIDED</span>
                                            <?php endif; ?>
                                            <?php if ($isFirstStockIn): ?>
                                                <span class="badge rounded-pill bg-success-subtle text-success border border-success-subtle first-stock-badge ms-1">
                                                    <i class="fa-solid fa-circle-plus me-1"></i>First Stock In
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        <small class="text-muted">
                                            <?= htmlspecialchars($row['brand_name']) ?> |
                                            <?= htmlspecialchars($row['variation_name']) ?>
                                            <?php if ($skuText !== ''): ?>
                                                | SKU: <span class="fw-semibold"><?= htmlspecialchars($skuText) ?></span>
                                            <?php endif; ?>
                                        </small>
                                    </div>
                                    <span class="badge bg-<?= $qty_color ?>-subtle text-<?= $qty_color ?> qty-badge">
                                        <?= $qty_sign ?>         <?= abs($row['quantity']) ?>
                                    </span>
                                </div>

                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <span class="badge <?= $cfg['bg'] ?> bg-opacity-10 text-<?= $cfg['color'] ?> me-2">
                                            <i class="<?= strpos($cfg['icon'], 'fa-') === 0 && strpos($cfg['icon'], ' ') === false ? 'fa-solid ' . $cfg['icon'] : $cfg['icon'] ?> me-1"></i><?= $cfg['label'] ?>
                                        </span>
                                        <small class="d-block store-stock-meta <?= $storeMeta['class'] ?> mt-1">
                                            <i class="fa-solid <?= $storeMeta['icon'] ?> me-1"></i><?= $storeMeta['label'] ?>
                                        </small>
                                        <small class="<?= $hasNegativeBalance ? 'text-danger fw-bold' : 'text-muted' ?>">
                                            <?= $displayPreviousStock ?> &rarr; <strong><?= $displayNewStock ?></strong>
                                        </small>
                                        <?php if ($hasNegativeBalance): ?>
                                            <small class="negative-stock-note d-block">Negative raw balance displayed as 0</small>
                                        <?php endif; ?>
                                    </div>
                                    <small class="text-muted">
                                        <?= date('M d, h:i A', strtotime($row['created_at'])) ?>
                                    </small>
                                </div>

                                <?php if ($row['reference'] || $row['remarks'] || $row['attachment_count'] == 0 || $isFirstStockIn): ?>

                                    <div class="mt-2 pt-2 border-top">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div>
                                                <?php if ($row['reference']): ?>
                                                    <a href="reference.php?ref=<?= urlencode($row['reference']) ?>&from=movements"
                                                        class="text-decoration-none fw-bold">
                                                        <code
                                                            class="small text-primary"><?= htmlspecialchars($row['reference']) ?></code>
                                                    </a>
                                                    <?php if ($row['attachment_count'] == 0): ?>
                                                        <button class="btn btn-sm btn-link p-0 text-decoration-none ms-2"
                                                            onclick="openRetroUpload(<?= $row['movement_id'] ?>, '<?= htmlspecialchars($row['reference'], ENT_QUOTES) ?>')">
                                                            <i class="fa-solid fa-cloud-arrow-up"></i> Add Photo
                                                        </button>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <button class="btn btn-sm btn-link p-0 text-decoration-none"
                                                        onclick="openRetroUpload(<?= $row['movement_id'] ?>, 'No Reference')">
                                                        <i class="fa-solid fa-cloud-arrow-up"></i> Add Photo
                                                    </button>
                                                <?php endif; ?>
                                                <?php if ($row['remarks']): ?>
                                                    <small
                                                        class="text-muted d-block mt-1"><?= htmlspecialchars($row['remarks']) ?></small>
                                                <?php endif; ?>
                                                <?php if ($isFirstStockIn): ?>
                                                    <small class="first-stock-note d-block mt-1">
                                                        <i class="fa-solid fa-clock-rotate-left me-1"></i>First stock entry for this product
                                                    </small>
                                                <?php endif; ?>
                                            </div>
                                            <?php if ($row['attachment_count'] > 0): ?>
                                                <a href="javascript:void(0)"
                                                    onclick="viewAttachment('<?= $row['all_images_data'] ?>', '<?= htmlspecialchars($row['reference'], ENT_QUOTES) ?>')"
                                                    class="text-primary text-decoration-none small d-block mt-1">
                                                    <i class="fa-solid fa-paperclip me-1"></i>View Receipt
                                                    <?php if ($row['attachment_count'] > 1): ?>
                                                        <span class="badge bg-secondary rounded-pill small ms-1"
                                                            style="font-size: 0.7em;">+<?= $row['attachment_count'] - 1 ?></span>
                                                    <?php endif; ?>
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="text-center py-5">
                    <i class="fa-solid fa-inbox fa-3x text-muted opacity-25 mb-3"></i>
                    <h6 class="text-muted">No movements found</h6>
                    <p class="small text-muted mb-0">Try adjusting your filters</p>
                </div>
            <?php endif; ?>
        </div>

        <div class="card-footer bg-white py-3">
            <small class="text-muted">
                <i class="fa-solid fa-info-circle me-1"></i>
                Showing <?= count($movements) ?> most recent movements (max 500)
            </small>
        </div>
    </div>

</div>

<!-- Attachment Modal -->
<div class="modal fade" id="attachmentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header border-0 pb-0">
                <h6 class="modal-title fw-bold"><i class="fa-solid fa-paperclip me-2 text-primary"></i>Reference: <span
                        id="attachment-ref-title"></span></h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-0">
                <div id="attachmentCarousel" class="carousel slide" data-bs-ride="false">
                    <div class="carousel-inner" id="modal-carousel-inner">
                        <!-- Images will be injected here -->
                    </div>
                    <button class="carousel-control-prev d-none" type="button" data-bs-target="#attachmentCarousel"
                        data-bs-slide="prev" style="width: 10%;">
                        <span class="carousel-control-prev-icon bg-dark rounded-circle p-2" aria-hidden="true"></span>
                        <span class="visually-hidden">Previous</span>
                    </button>
                    <button class="carousel-control-next d-none" type="button" data-bs-target="#attachmentCarousel"
                        data-bs-slide="next" style="width: 10%;">
                        <span class="carousel-control-next-icon bg-dark rounded-circle p-2" aria-hidden="true"></span>
                        <span class="visually-hidden">Next</span>
                    </button>
                </div>
                <div class="text-center p-2 bg-light border-top d-none" id="carousel-indicator-text">
                    <small class="text-muted">Image <span id="current-img-idx">1</span> of <span
                            id="total-img-count">1</span></small>
                </div>
            </div>
            <div class="modal-footer border-0 pt-0 justify-content-between">
                <div>
                    <?php if (in_array($_SESSION['role'], ['admin', 'super_admin', 'manager'])): ?>
                        <button type="button" class="btn btn-sm btn-outline-danger" id="btn-delete-attachment"
                            onclick="deleteCurrentAttachment()">
                            <i class="fa-solid fa-trash me-1"></i>Delete Photo
                        </button>
                    <?php endif; ?>
                </div>
                <div class="d-flex gap-2">
                    <a id="attachment-download" href="" download class="btn btn-sm btn-outline-primary"
                        onclick="this.href=document.querySelector('#modal-carousel-inner .carousel-item.active img').src">
                        <i class="fa-solid fa-download me-1"></i>Download
                    </a>
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Upload Retroactive Modal -->
<div class="modal fade" id="uploadModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header">
                <h6 class="modal-title fw-bold"><i class="fa-solid fa-cloud-arrow-up me-2 text-primary"></i>Upload
                    Reference Photo</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="uploadForm">
                <div class="modal-body">
                    <input type="hidden" id="upload-movement-id" name="movement_id">
                    <p class="small text-muted mb-3">Uploading photo for Reference: <strong id="upload-ref-label"
                            class="text-dark"></strong></p>

                    <div class="mb-3">
                        <label class="form-label small fw-bold">Select Images (Max 8) <span
                                class="text-danger">*</span></label>
                        <input type="file" name="reference_images[]" id="upload-file" class="form-control"
                            accept="image/*" multiple required>
                        <div class="form-text small">You can select up to 8 images at once.</div>
                    </div>
                </div>
                <div class="modal-footer bg-light border-0">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm" id="btn-upload">
                        <i class="fa-solid fa-upload me-1"></i>Upload Photo
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

    <!-- Include Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            // View Mode Toggle
            window.toggleViewMode = function(mode) {
                const isMobile = window.innerWidth < 992;
                const desktopTimeline = document.querySelector('.desktop-table');
                const mobileTimeline = document.querySelector('.mobile-cards');
                const summaryView = document.querySelector('.view-summary');
                const title = document.getElementById('results-title');
                const icon = document.getElementById('results-icon');
                const countBadge = document.getElementById('results-count');

                if (mode === 'summary') {
                    desktopTimeline.style.display = 'none';
                    mobileTimeline.style.display = 'none';
                    summaryView.style.display = 'block';
                    title.textContent = 'Product Summary';
                    icon.className = 'fa-solid fa-table-cells-large text-primary me-2';
                    countBadge.textContent = '<?= count($summary_data) ?> products';
                } else {
                    summaryView.style.display = 'none';
                    desktopTimeline.style.display = isMobile ? 'none' : 'block';
                    mobileTimeline.style.display = isMobile ? 'block' : 'none';
                    title.textContent = 'Movement History';
                    icon.className = 'fa-solid fa-list text-primary me-2';
                    countBadge.textContent = '<?= count($movements) ?> records';
                }
            };

            // Responsive updates
            function updateView() {
                const isMobile = window.innerWidth < 992;
                const mode = document.getElementById('btn-summary').checked ? 'summary' : 'timeline';
                
                if (mode === 'timeline') {
                    document.querySelectorAll('.mobile-cards').forEach(el => {
                        el.style.display = isMobile ? 'block' : 'none';
                    });
                    document.querySelectorAll('.desktop-table').forEach(el => {
                        el.style.display = isMobile ? 'none' : 'block';
                    });
                }
            }

            updateView();
            window.addEventListener('resize', updateView);

            // Manual search submission (Enter key)
            const searchInput = document.getElementById('movements-search');
            const filterForm = searchInput?.closest('form');
            let lastSubmittedSearch = searchInput ? searchInput.value.trim() : '';

            if (searchInput && filterForm) {
                const submitSearch = () => {
                    const currentSearch = searchInput.value.trim();
                    if (currentSearch === lastSubmittedSearch) return;
                    lastSubmittedSearch = currentSearch;
                    filterForm.submit();
                };
                searchInput.addEventListener('keydown', (e) => {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        submitSearch();
                    }
                });
            }

            // Initialize Chart.js
            const ctx = document.getElementById('dailyTrendChart');
            if (ctx) {
                const labels = <?= $chart_labels ?>;
                if (labels.length > 0) {
                    new Chart(ctx, {
                        type: 'line',
                        data: {
                            labels: labels,
                            datasets: [
                                {
                                    label: 'Sales Qty',
                                    data: <?= $chart_sales ?>,
                                    borderColor: '#0d6efd',
                                    backgroundColor: 'rgba(13, 110, 253, 0.1)',
                                    tension: 0.3,
                                    fill: true
                                },
                                {
                                    label: 'Stock In Qty',
                                    data: <?= $chart_stockin ?>,
                                    borderColor: '#198754',
                                    backgroundColor: 'rgba(25, 135, 84, 0.1)',
                                    tension: 0.3,
                                    fill: true
                                }
                            ]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            interaction: {
                                mode: 'index',
                                intersect: false,
                            },
                            plugins: {
                                legend: { position: 'top' }
                            },
                            scales: {
                                y: { beginAtZero: true }
                            }
                        }
                    });
                } else {
                    ctx.parentElement.innerHTML = '<div class="h-100 d-flex align-items-center justify-content-center text-muted">No trend data available for this period.</div>';
                }
            }

        // Handle Retroactive Upload Form
        document.getElementById('uploadForm')?.addEventListener('submit', async function (e) {
            e.preventDefault();
            const btn = document.getElementById('btn-upload');
            const fileInput = document.getElementById('upload-file');

            if (!fileInput.files.length) {
                if (typeof EllaToast !== 'undefined') EllaToast.warning('Please select an image first.');
                else alert('Please select an image first.');
                return;
            }

            btn.disabled = true;
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i>Uploading...';

            const formData = new FormData(this);

            try {
                const res = await fetch('../../api/inventory/upload_retroactive_attachment.php', {
                    method: 'POST',
                    body: formData
                });

                const data = await res.json();

                if (data.success) {
                    if (typeof EllaToast !== 'undefined') EllaToast.success(data.message);
                    else alert(data.message);
                    setTimeout(() => window.location.reload(), 1000);
                } else {
                    if (typeof EllaToast !== 'undefined') EllaToast.error('Error: ' + data.error);
                    else alert('Error: ' + data.error);
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fa-solid fa-upload me-1"></i>Upload Photo';
                }
            } catch (err) {
                console.error(err);
                if (typeof EllaToast !== 'undefined') EllaToast.error('An error occurred during upload.');
                else alert('An error occurred during upload.');
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-upload me-1"></i>Upload Photo';
            }
        });
    });

    /**
     * View Attachment in Modal (Multiple Support)
     */
    function viewAttachment(imagesDataStr, ref) {
        const items = imagesDataStr.split(',');
        const container = document.getElementById('modal-carousel-inner');
        const prevBtn = document.querySelector('#attachmentCarousel .carousel-control-prev');
        const nextBtn = document.querySelector('#attachmentCarousel .carousel-control-next');
        const indicatorText = document.getElementById('carousel-indicator-text');

        container.innerHTML = '';
        document.getElementById('attachment-ref-title').textContent = ref;
        document.getElementById('total-img-count').textContent = items.length;
        document.getElementById('current-img-idx').textContent = '1';

        items.forEach((data, idx) => {
            const parts = data.split(':');
            const id = parts[0];
            const path = parts.slice(1).join(':'); // Handle if path has colons

            const item = document.createElement('div');
            item.className = `carousel-item ${idx === 0 ? 'active' : ''}`;
            item.innerHTML = `
                <div class="d-flex flex-column align-items-center justify-content-center p-4" style="min-height: 300px;">
                    <img src="<?= BASE_URL ?>${path}" class="img-fluid rounded shadow-sm" style="max-height: 70vh;" alt="Receipt">
                    <div class="mt-3" style="position: relative; z-index: 10;">
                        <a href="<?= BASE_URL ?>${path}" target="_blank" class="btn btn-sm btn-link text-decoration-none">
                            <i class="fa-solid fa-expand me-1"></i>View Full Size
                        </a>
                    </div>
                </div>
            `;
            // Store data for deletion
            item.dataset.id = id;
            item.dataset.path = path;
            container.appendChild(item);
        });

        if (items.length > 1) {
            prevBtn.classList.remove('d-none');
            nextBtn.classList.remove('d-none');
            indicatorText.classList.remove('d-none');
        } else {
            prevBtn.classList.add('d-none');
            nextBtn.classList.add('d-none');
            indicatorText.classList.add('d-none');
        }

        const modal = new bootstrap.Modal(document.getElementById('attachmentModal'));
        modal.show();

        // Handle index update
        const carouselEl = document.getElementById('attachmentCarousel');
        carouselEl.addEventListener('slide.bs.carousel', function (e) {
            document.getElementById('current-img-idx').textContent = e.to + 1;
        });
    }

    function deleteCurrentAttachment() {
        const activeItem = document.querySelector('#modal-carousel-inner .carousel-item.active');
        const id = activeItem ? activeItem.dataset.id : null;
        const path = activeItem ? activeItem.dataset.path : null;
        if (!path) return;

        if (!confirm('Are you sure you want to delete this specific photo? This action cannot be undone.')) return;

        const btn = document.getElementById('btn-delete-attachment');
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i>Deleting...';
        }

        const formData = new FormData();
        if (id) formData.append('id', id);
        formData.append('image_path', path);

        fetch('../../api/inventory/delete_attachment.php', {
            method: 'POST',
            body: formData
        })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    if (typeof EllaToast !== 'undefined') EllaToast.success(data.message);
                    else alert(data.message);
                    setTimeout(() => window.location.reload(), 1000);
                } else {
                    if (typeof EllaToast !== 'undefined') EllaToast.error('Error: ' + data.error);
                    else alert('Error: ' + data.error);
                    if (btn) {
                        btn.disabled = false;
                        btn.innerHTML = '<i class="fa-solid fa-trash me-1"></i>Delete Photo';
                    }
                }
            })
            .catch(err => {
                console.error(err);
                if (typeof EllaToast !== 'undefined') EllaToast.error('An error occurred during deletion.');
                else alert('An error occurred during deletion.');
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fa-solid fa-trash me-1"></i>Delete Photo';
                }
            });
    }

    function openRetroUpload(movementId, refLabel) {
        document.getElementById('upload-movement-id').value = movementId;
        document.getElementById('upload-ref-label').textContent = refLabel;
        document.getElementById('uploadForm').reset();
        const modal = new bootstrap.Modal(document.getElementById('uploadModal'));
        modal.show();
    }
</script>

<?php require_once '../../includes/footer.php'; ?>

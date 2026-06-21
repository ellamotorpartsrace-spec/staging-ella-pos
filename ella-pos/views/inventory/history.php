<?php
// views/inventory/history.php
require_once '../../config/config.php';
require_once '../../includes/auth.php';

// Auth Check
requireLogin();
if (!in_array($_SESSION['role'], ['admin', 'super_admin']) && !hasPermission('adjust_prices') && !in_array($_SESSION['role'], ['manager', 'stockman'])) {
    denyAccess("You do not have permission to view inventory history.");
}

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
require_once '../../config/database.php';

// 1. Get ID & Validate
if (!isset($_GET['id'])) {
    header("Location: index.php");
    exit;
}
$variation_id = $_GET['id'];

$db = new Database();
$conn = $db->getConnection();

// 2. Fetch Product Details (Header Info)
$sqlProd = "
    SELECT 
        p.product_name, p.brand_name, 
        v.variation_name, v.sku, v.barcode, v.unit_type,
        COALESCE(i_phys.quantity, 0) as physical_stock,
        COALESCE(i_online.quantity, 0) as online_stock,
        COALESCE(i_phys.quantity, 0) + COALESCE(i_online.quantity, 0) as total_stock,
        CAST((
            SELECT COALESCE(SUM(m.shopee_stock * COALESCE(u.multiplier, 1)), 0)
            FROM shopee_product_mappings m
            LEFT JOIN product_units u ON m.pos_unit_id = u.id
            WHERE (m.pos_product_id = v.variation_id OR (v.sku NOT IN ('', '-', 'N/A', 'NA', 'none', 'null') AND m.matched_pos_sku = v.sku COLLATE utf8mb4_unicode_ci))
              AND m.mapping_status IN ('auto','manual')
              AND (m.pos_bundle_set_id IS NULL OR m.pos_bundle_set_id = 0)
        ) AS SIGNED) as shopee_allocated
    FROM product_variations v
    JOIN products p ON v.product_id = p.product_id
    LEFT JOIN inventory i_phys ON v.variation_id = i_phys.variation_id AND i_phys.store_id = 1
    LEFT JOIN inventory i_online ON v.variation_id = i_online.variation_id AND i_online.store_id = 2
    WHERE v.variation_id = :id
";
$stmt = $conn->prepare($sqlProd);
$stmt->execute([':id' => $variation_id]);
$product = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$product) {
    echo "<div class='p-4'><h3>❌ Product not found</h3><a href='index.php'>Back</a></div>";
    require_once '../../includes/footer.php';
    exit;
}

$totalStock = (int)$product['total_stock'];
$shopeeAllocated = (int)$product['shopee_allocated'];
$physicalAvailable = max(0, $totalStock - $shopeeAllocated);

// 3. Fetch History (Stock Movements)
// Include BOTH store_id 1 (physical) movements
// ORDER BY movement_id DESC as secondary sort to fix same-second logs
$sqlHist = "
    SELECT m.*, u.username, u.full_name
    FROM stock_movements m
    LEFT JOIN users u ON m.created_by = u.id
    WHERE m.variation_id = :id AND m.store_id = 1 
    AND m.type NOT IN ('online_sale', 'online_adjustment')
    ORDER BY m.created_at DESC, m.movement_id DESC
    LIMIT 100
";
$stmtHist = $conn->prepare($sqlHist);
$stmtHist->execute([':id' => $variation_id]);
$history = $stmtHist->fetchAll();

// Movement type configuration
$type_config = [
    'stock_in'               => ['label' => 'Stock In',              'icon' => 'fa-solid fa-arrow-down',           'badge' => 'bg-success',              'desc' => 'New stock added to inventory'],
    'stock_out'              => ['label' => 'Stock Out',             'icon' => 'fa-solid fa-arrow-up',             'badge' => 'bg-warning text-dark',    'desc' => 'Stock removed from inventory'],
    'sales'                  => ['label' => 'POS Sale',              'icon' => 'fa-solid fa-cart-shopping',         'badge' => 'bg-primary',              'desc' => 'Sold to walk-in customer'],
    'adjustment'             => ['label' => 'Adjustment',            'icon' => 'fa-solid fa-wrench',               'badge' => 'bg-info text-dark',       'desc' => 'Manual stock correction'],
    'return'                 => ['label' => 'Return',                'icon' => 'fa-solid fa-rotate-left',          'badge' => 'bg-danger',               'desc' => 'Customer return'],
    'allocation_to_online'   => ['label' => 'Allocated to Shopee',   'icon' => 'fa-solid fa-globe',                'badge' => 'shopee-badge',            'desc' => 'Stock allocated to Shopee store'],
    'allocation_to_physical' => ['label' => 'Returned to POS',       'icon' => 'fa-solid fa-store',                'badge' => 'bg-dark',                 'desc' => 'Stock returned from Shopee to POS'],
    'shopee_balance_sync'    => ['label' => 'Shopee Sync Fix',       'icon' => 'fa-solid fa-rotate',               'badge' => 'shopee-badge',            'desc' => 'System background stock sync'],
    'lazada_balance_sync'    => ['label' => 'Lazada Sync Fix',       'icon' => 'fa-solid fa-rotate',               'badge' => 'bg-primary',              'desc' => 'System background stock sync'],
];
?>

<style>
    .shopee-badge {
        background: linear-gradient(135deg, #ee4d2d 0%, #ff6f47 100%) !important;
        color: #fff !important;
    }
    .history-card {
        transition: all 0.2s ease;
    }
    .history-card:hover {
        background-color: var(--bg-surface, #f8f9fa);
    }
    .movement-desc {
        font-size: 0.72rem;
        color: var(--text-secondary, #6c757d);
        margin-top: 2px;
    }
    .stock-flow {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        font-size: 0.82rem;
        font-weight: 600;
    }
    .stock-flow .prev { color: var(--text-secondary, #6c757d); }
    .stock-flow .arrow { color: var(--text-secondary, #adb5bd); font-size: 0.7rem; }
    .stock-flow .new { color: var(--text-primary, #212529); }
    .summary-stat {
        text-align: center;
        padding: 12px 8px;
    }
    .summary-stat .stat-value {
        font-size: 1.5rem;
        font-weight: 800;
        line-height: 1.2;
    }
    .summary-stat .stat-label {
        font-size: 0.68rem;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        font-weight: 700;
        color: var(--text-secondary, #6c757d);
        margin-top: 2px;
    }
</style>

<div class="container-fluid p-4">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-0 fw-bold"><i class="fa-solid fa-clock-rotate-left text-primary"></i> Stock History</h4>
            <div class="text-muted">
                <?= htmlspecialchars($product['product_name']) ?>
                <span class="badge bg-secondary ms-1"><?= htmlspecialchars($product['variation_name']) ?></span>
            </div>
        </div>
        <a href="<?= htmlspecialchars($_SERVER['HTTP_REFERER'] ?? 'index.php') ?>" class="btn btn-outline-secondary">
            <i class="fa-solid fa-arrow-left"></i> Cancel / Back
        </a>
    </div>

    <div class="row g-4">

        <div class="col-md-3">
            <!-- Stock Summary Cards -->
            <div class="card shadow-sm border-0 mb-3">
                <div class="card-body p-0">
                    <div class="row g-0">
                        <div class="col-4 summary-stat border-end">
                            <div class="stat-value text-primary"><?= $totalStock ?></div>
                            <div class="stat-label">Total</div>
                        </div>
                        <div class="col-4 summary-stat border-end">
                            <div class="stat-value text-success"><?= $physicalAvailable ?></div>
                            <div class="stat-label">POS</div>
                        </div>
                        <div class="col-4 summary-stat">
                            <div class="stat-value" style="color: #ee4d2d;"><?= $shopeeAllocated ?></div>
                            <div class="stat-label"><i class="fa-solid fa-globe" style="font-size: 0.6rem;"></i> Shopee</div>
                        </div>
                    </div>
                </div>
            </div>

            <?php if ($shopeeAllocated > 0): ?>
            <div class="card shadow-sm border-0 mb-3" style="border-left: 3px solid #ee4d2d !important;">
                <div class="card-body py-2 px-3">
                    <div class="d-flex align-items-center gap-2">
                        <i class="fa-solid fa-globe" style="color: #ee4d2d;"></i>
                        <div>
                            <div class="small fw-bold" style="color: #ee4d2d;">Shopee Allocated</div>
                            <div class="small text-muted">
                                <?= $shopeeAllocated ?> of <?= $totalStock ?> <?= htmlspecialchars($product['unit_type'] ?? 'units') ?> reserved for Shopee
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <div class="card shadow-sm border-0">
                <div class="card-body">
                    <h6 class="fw-bold border-bottom pb-2">Item Details</h6>
                    <ul class="list-group list-group-flush small">
                        <li class="list-group-item d-flex justify-content-between px-0">
                            <span>SKU</span>
                            <span class="font-monospace"><?= htmlspecialchars($product['sku'] ?? '-') ?></span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between px-0">
                            <span>Barcode</span>
                            <span class="font-monospace"><?= htmlspecialchars($product['barcode'] ?? '-') ?></span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between px-0">
                            <span>Brand</span>
                            <span class="fw-bold"><?= htmlspecialchars($product['brand_name']) ?></span>
                        </li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="col-md-9">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white py-3">
                    <h6 class="mb-0 fw-bold">Recent Movements (Last 100)</h6>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="bg-light">
                            <tr>
                                <th class="ps-4">Date & Time</th>
                                <th>Type</th>
                                <th class="text-center">Change</th>
                                <th class="text-center">Balance</th>
                                <th>Reference / Remarks</th>
                                <th class="text-end pe-4">User</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($history) > 0): ?>
                                <?php foreach ($history as $row): ?>
                                    <?php
                                    $type = $row['type'] ?? '';
                                    $cfg = $type_config[$type] ?? [
                                        'label' => ucwords(str_replace('_', ' ', $type ?: 'Unknown')),
                                        'icon'  => 'fa-solid fa-circle',
                                        'badge' => 'bg-secondary',
                                        'desc'  => ''
                                    ];

                                    // Determine the sign from the actual quantity value
                                    $qty = (int)$row['quantity'];
                                    
                                    // Some movement types are inherently deductions from the physical stock,
                                    // even if the database stores the absolute quantity.
                                    $deduction_types = ['stock_out', 'sales', 'allocation_to_online'];
                                    
                                    if (in_array($type, $deduction_types)) {
                                        $displayQty = -abs($qty);
                                    } else {
                                        $displayQty = $qty; // For adjustment, it could already be negative
                                    }

                                    $isPositive = $displayQty >= 0;
                                    $qtySign = $isPositive ? '+' : '';
                                    $qtyColor = $isPositive ? 'success' : 'danger';

                                    // Build a clear human-readable description
                                    $humanDesc = '';
                                    $absQty = abs($displayQty);
                                    switch ($type) {
                                        case 'allocation_to_online':
                                            $humanDesc = $absQty . ' moved to Shopee';
                                            break;
                                        case 'allocation_to_physical':
                                            $humanDesc = $absQty . ' returned from Shopee';
                                            break;
                                        case 'shopee_balance_sync':
                                        case 'lazada_balance_sync':
                                            $humanDesc = 'System balance sync correction';
                                            break;
                                        case 'sales':
                                            $humanDesc = $absQty . ' sold (walk-in)';
                                            break;
                                        case 'stock_in':
                                            $humanDesc = $absQty . ' added to stock';
                                            break;
                                        case 'stock_out':
                                            $humanDesc = $absQty . ' removed from stock';
                                            break;
                                        case 'return':
                                            $humanDesc = $absQty . ' returned';
                                            break;
                                        case 'adjustment':
                                            $humanDesc = 'Adjusted by ' . ($displayQty >= 0 ? '+' : '') . $displayQty;
                                            break;
                                        default:
                                            $humanDesc = $cfg['desc'] ?? '';
                                    }

                                    // Detect if this is a Shopee-related movement
                                    $isShopeeRelated = in_array($type, [
                                        'allocation_to_online', 'allocation_to_physical', 'shopee_balance_sync'
                                    ]) || strpos($row['reference'] ?? '', 'SHP-') === 0;

                                    $isLazadaRelated = in_array($type, [
                                        'lazada_balance_sync'
                                    ]) || strpos($row['reference'] ?? '', 'LZD-') === 0;

                                    $isGapFill = ($row['reference'] ?? '') === 'SYS-GAPFILL';
                                    $isReconcile = ($row['reference'] ?? '') === 'SYS-RECONCILE';
                                    ?>
                                    <tr class="history-card">
                                        <td class="ps-4 small text-secondary">
                                            <?= date('M d, Y h:i A', strtotime($row['created_at'])) ?>
                                        </td>

                                        <td>
                                            <span class="badge <?= $cfg['badge'] ?> rounded-pill">
                                                <i class="<?= $cfg['icon'] ?> me-1"></i>
                                                <?= $cfg['label'] ?>
                                            </span>
                                            <?php if ($humanDesc): ?>
                                                <div class="movement-desc"><?= htmlspecialchars($humanDesc) ?></div>
                                            <?php endif; ?>
                                        </td>

                                        <td class="text-center fw-bold">
                                            <span class="text-<?= $qtyColor ?>" style="font-size: 1rem;">
                                                <?= $displayQty >= 0 ? '+' . $absQty : '-' . $absQty ?>
                                            </span>
                                        </td>

                                        <td class="text-center">
                                            <div class="stock-flow">
                                                <span class="prev"><?= $row['previous_stock'] ?></span>
                                                <i class="fa-solid fa-arrow-right arrow"></i>
                                                <span class="new fw-bold"><?= $row['new_stock'] ?></span>
                                            </div>
                                        </td>

                                        <td>
                                            <?php if ($row['reference']): ?>
                                                <div class="badge bg-light text-dark border border-secondary mb-1">
                                                    Ref: <?= htmlspecialchars($row['reference']) ?>
                                                </div><br>
                                            <?php endif; ?>
                                            <small class="text-muted"><?= htmlspecialchars($row['remarks'] ?? '') ?></small>
                                        </td>

                                        <td class="text-end pe-4 small">
                                            <?php if ($isGapFill): ?>
                                                <div style="color: #888; font-size: 0.75rem; margin-bottom: 2px;"><i class="fa-solid fa-clock-rotate-left me-1"></i>Historical</div>
                                                <div class="text-muted"><i class="fa-solid fa-robot text-secondary"></i> System (Auto-fill)</div>
                                            <?php elseif ($isReconcile): ?>
                                                <div style="color: #888; font-size: 0.75rem; margin-bottom: 2px;"><i class="fa-solid fa-check-double me-1"></i>Reconciled</div>
                                                <div class="text-muted"><i class="fa-solid fa-robot text-secondary"></i> System (Auto-fix)</div>
                                            <?php elseif ($isShopeeRelated): ?>
                                                <div style="color: #ee4d2d; font-size: 0.75rem; margin-bottom: 2px;"><i class="fa-solid fa-shopping-bag me-1"></i>Shopee</div>
                                                <div><i class="fa-solid fa-user-circle text-secondary"></i> <?= htmlspecialchars($row['full_name'] ?? $row['username'] ?? 'System') ?></div>
                                            <?php elseif ($isLazadaRelated): ?>
                                                <div style="color: #0f146d; font-size: 0.75rem; margin-bottom: 2px;"><i class="fa-solid fa-heart me-1"></i>Lazada</div>
                                                <div><i class="fa-solid fa-user-circle text-secondary"></i> <?= htmlspecialchars($row['full_name'] ?? $row['username'] ?? 'System') ?></div>
                                            <?php else: ?>
                                                <div><i class="fa-solid fa-user-circle text-secondary"></i> <?= htmlspecialchars($row['full_name'] ?? $row['username'] ?? 'System') ?></div>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" class="text-center py-5 text-muted">
                                        No history found for this item yet.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>

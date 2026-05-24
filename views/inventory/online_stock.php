<?php
// views/inventory/online_stock.php
require_once '../../config/config.php';
require_once '../../includes/auth.php';

requireLogin();
if ($_SESSION['role'] !== 'admin' && !hasPermission('adjust_prices') && !in_array($_SESSION['role'], ['manager', 'stockman'])) {
    denyAccess("You do not have permission to access online stock management.");
}

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
require_once '../../config/database.php';

$db = new Database();
$conn = $db->getConnection();

// ---------- Summary Stats ----------
$statsStmt = $conn->query("
    SELECT
        COUNT(DISTINCT i.variation_id)                                              AS total_online_products,
        SUM(i.quantity)                                                             AS total_online_units,
        SUM(i.quantity * v.price_retail)                                            AS total_online_value,
        SUM(i.quantity * v.price_capital)                                           AS total_online_cost
    FROM inventory i
    JOIN product_variations v ON i.variation_id = v.variation_id
    WHERE i.store_id = 2 AND i.quantity > 0 AND v.status = 'active'
");
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

// ---------- Brands for filter dropdown ----------
$brandsStmt = $conn->query("
    SELECT DISTINCT p.brand_name
    FROM products p
    JOIN product_variations v ON v.product_id = p.product_id
    JOIN inventory i ON i.variation_id = v.variation_id AND i.store_id = 2
    WHERE i.quantity > 0 AND v.status = 'active'
    ORDER BY p.brand_name ASC
");
$onlineBrands = $brandsStmt->fetchAll(PDO::FETCH_COLUMN);

$activeTab = $_GET['tab'] ?? 'overview';
?>

<style>
    .online-tab-btn {
        border: none;
        background: none;
        padding: 10px 20px;
        font-weight: 600;
        color: var(--text-secondary);
        border-bottom: 3px solid transparent;
        cursor: pointer;
        transition: all 0.2s;
    }

    .online-tab-btn.active {
        color: #0d6efd;
        border-bottom-color: #0d6efd;
    }

    .online-tab-btn:hover:not(.active) {
        color: var(--text-primary);
        background: var(--bg-surface);
    }

    .tab-pane-custom {
        display: none;
    }

    .tab-pane-custom.active {
        display: block;
    }

    .stock-pill {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        padding: 3px 10px;
        border-radius: 20px;
        font-size: 0.82rem;
        font-weight: 600;
    }

    /* Inline edit input */
    .inline-qty-wrapper {
        display: flex;
        align-items: center;
        gap: 6px;
    }

    .inline-qty-input {
        width: 80px;
        text-align: center;
        font-weight: 700;
        border: 2px solid #0d6efd;
        border-radius: 6px;
        padding: 3px 6px;
    }

    .platform-badge {
        font-size: 0.75rem;
        padding: 3px 8px;
        border-radius: 12px;
        font-weight: 600;
    }

    .platform-shopee {
        background: #ff5722;
        color: #fff;
    }

    .platform-lazada {
        background: #862fe0;
        color: #fff;
    }

    .platform-facebook {
        background: #1877f2;
        color: #fff;
    }

    .platform-tiktok {
        background: #000;
        color: #fff;
    }

    .platform-other {
        background: #6c757d;
        color: #fff;
    }

    .search-dropdown-sm {
        position: absolute;
        z-index: 1060;
        width: 100%;
        max-height: 320px;
        overflow-y: auto;
        background: #fff;
        border: 1px solid #dee2e6;
        border-top: none;
        border-radius: 0 0 8px 8px;
        box-shadow: 0 8px 24px rgba(0, 0, 0, 0.12);
    }

    .product-option {
        padding: 10px 14px;
        cursor: pointer;
        border-bottom: 1px solid #f1f3f5;
        transition: background 0.15s;
    }

    .product-option:hover {
        background: #f8f9fa;
    }

    .product-option:last-child {
        border-bottom: none;
    }

    @keyframes fadeIn {
        from {
            opacity: 0;
            transform: translateY(4px);
        }

        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .row-animated {
        animation: fadeIn 0.25s ease;
    }
</style>

<div class="container-fluid p-4">

    <!-- Page Header -->
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-4">
        <div class="mb-3 mb-md-0">
            <h4 class="fw-bold mb-1" style="color: var(--text-primary);">
                <i class="fa-solid fa-globe text-info me-2"></i>Online Stock Management
            </h4>
            <p class="text-muted mb-0 small">Manage stock allocated for online channels (Shopee, Lazada, Facebook, etc.)
            </p>
        </div>
        <a href="index.php" class="btn btn-outline-secondary btn-sm">
            <i class="fa-solid fa-arrow-left me-1"></i>Back to Inventory
        </a>
    </div>

    <!-- Summary Cards -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card shadow-sm border-start border-4 border-info h-100">
                <div class="card-body py-3">
                    <div class="text-secondary small text-uppercase fw-bold">Products Online</div>
                    <div class="h3 mb-0 fw-bold text-info">
                        <?= number_format($stats['total_online_products']) ?>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card shadow-sm border-start border-4 border-primary h-100">
                <div class="card-body py-3">
                    <div class="text-secondary small text-uppercase fw-bold">Total Units Online</div>
                    <div class="h3 mb-0 fw-bold text-primary">
                        <?= number_format($stats['total_online_units'] ?? 0) ?>
                    </div>
                </div>
            </div>
        </div>
        <?php if ($_SESSION['role'] === 'admin'): ?>
            <div class="col-6 col-md-3">
                <div class="card shadow-sm border-start border-4 border-success h-100">
                    <div class="card-body py-3">
                        <div class="text-secondary small text-uppercase fw-bold">Online Stock Value (Cost)</div>
                        <div class="h5 mb-0 fw-bold text-success">₱
                            <?= number_format($stats['total_online_cost'] ?? 0, 2) ?>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card shadow-sm border-start border-4 border-warning h-100">
                    <div class="card-body py-3">
                        <div class="text-secondary small text-uppercase fw-bold">Online Stock Value (SRP)</div>
                        <div class="h5 mb-0 fw-bold text-warning">₱
                            <?= number_format($stats['total_online_value'] ?? 0, 2) ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Tab Navigation -->
    <div class="card shadow-sm border-0 mb-0">
        <div class="card-header p-0" style="background: var(--card-bg); border-bottom: 1px solid var(--border-color);">
            <div class="d-flex">
                <button class="online-tab-btn <?= $activeTab === 'overview' ? 'active' : '' ?>"
                    onclick="switchTab('overview', this)">
                    <i class="fa-solid fa-table-list me-1"></i>Online Inventory
                </button>
                <button class="online-tab-btn <?= $activeTab === 'record_sale' ? 'active' : '' ?>"
                    onclick="switchTab('record_sale', this)">
                    <i class="fa-solid fa-cart-shopping me-1"></i>Record Online Sale
                </button>
                <button class="online-tab-btn <?= $activeTab === 'history' ? 'active' : '' ?>"
                    onclick="switchTab('history', this)">
                    <i class="fa-solid fa-clock-rotate-left me-1"></i>Sales History
                </button>
                <button class="online-tab-btn <?= $activeTab === 'online_products' ? 'active' : '' ?>"
                    onclick="switchTab('online_products', this)">
                    <i class="fa-solid fa-list-check me-1"></i>Online Products
                    <span class="badge bg-info text-white ms-1" style="font-size:0.7rem;vertical-align:middle;"
                        id="op-tab-count"></span>
                </button>
                <button class="online-tab-btn <?= $activeTab === 'platform_links' ? 'active' : '' ?>"
                    onclick="switchTab('platform_links', this)">
                    <i class="fa-solid fa-link me-1"></i>Platform Links
                </button>
                <button class="online-tab-btn <?= $activeTab === 'bulk_transfer' ? 'active' : '' ?>"
                    onclick="switchTab('bulk_transfer', this)">
                    <i class="fa-solid fa-truck-fast me-1"></i>Bulk Transfer
                </button>
                <button class="online-tab-btn <?= $activeTab === 'sales_sync' ? 'active' : '' ?>"
                    onclick="switchTab('sales_sync', this)">
                    <i class="fa-solid fa-rotate me-1"></i>Sales Sync
                </button>
            </div>
        </div>

        <!-- ================================================================
             TAB 1: ONLINE INVENTORY OVERVIEW
             ================================================================ -->
        <div id="tab-overview" class="tab-pane-custom <?= $activeTab === 'overview' ? 'active' : '' ?>">
            <div class="card-body pb-2">
                <div class="d-flex gap-2 align-items-center">
                    <div class="input-group" style="max-width:380px;">
                        <span class="input-group-text"
                            style="background:var(--bg-surface);border-color:var(--border-color);">
                            <i class="fa-solid fa-magnifying-glass text-secondary"></i>
                        </span>
                        <input type="text" id="overview-search" class="form-control"
                            placeholder="Search product, brand, SKU…" autofocus>
                        <span class="input-group-text d-none" id="overview-spinner"
                            style="background:var(--bg-surface);border-color:var(--border-color);">
                            <i class="fa-solid fa-spinner fa-spin text-primary"></i>
                        </span>
                    </div>
                    <small class="text-muted" id="overview-count"></small>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" id="overview-table">
                    <thead style="background:var(--bg-surface); border-bottom:2px solid var(--border-color);">
                        <tr>
                            <th class="ps-4" style="color:var(--text-primary);">Product</th>
                            <th style="color:var(--text-primary);">Physical Stock</th>
                            <th style="color:var(--text-primary);">Online Stock</th>
                            <th class="text-end pe-4" style="color:var(--text-primary);">Action</th>
                        </tr>
                    </thead>
                    <tbody id="overview-tbody">
                        <tr>
                            <td colspan="4" class="text-center py-5 text-muted"><i
                                    class="fa-solid fa-spinner fa-spin me-2"></i>Loading…</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="card-footer" style="background:var(--card-bg);border-top:1px solid var(--border-color);">
                <div class="d-flex justify-content-between align-items-center gap-3 flex-wrap">
                    <small class="text-muted" id="overview-showing"></small>
                    <div class="d-flex align-items-center gap-2">
                        <label class="text-muted small fw-bold text-nowrap" for="overview-page-select">Page:</label>
                        <select id="overview-page-select" class="form-select form-select-sm"
                            style="width:auto; min-width:70px;"></select>
                        <span class="text-muted small text-nowrap">of <span id="overview-total-pages">1</span></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- ================================================================
             TAB 2: RECORD ONLINE SALE
             ================================================================ -->
        <div id="tab-record_sale" class="tab-pane-custom <?= $activeTab === 'record_sale' ? 'active' : '' ?>">
            <div class="card-body">
                <div class="row g-4 justify-content-center">
                    <div class="col-lg-7">
                        <div class="card shadow-sm border-0">
                            <div class="card-header fw-bold"
                                style="background:var(--card-bg);border-bottom:1px solid var(--border-color);">
                                <i class="fa-solid fa-cart-plus text-info me-2"></i>Record an Online Sale
                            </div>
                            <div class="card-body p-4">

                                <!-- Product Search -->
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Product <span class="text-danger">*</span></label>
                                    <div class="position-relative">
                                        <div class="input-group">
                                            <span class="input-group-text"><i class="fa-solid fa-barcode"></i></span>
                                            <input type="text" id="sale-product-search" class="form-control"
                                                placeholder="Search by name, SKU or barcode…" autocomplete="off">
                                        </div>
                                        <div id="sale-search-dropdown" class="search-dropdown-sm d-none"></div>
                                    </div>
                                    <!-- Selected product card -->
                                    <div id="sale-selected-product" class="d-none mt-2 p-3 rounded border"
                                        style="background:var(--bg-surface);">
                                        <div class="d-flex align-items-center gap-3">
                                            <div id="sprd-img"
                                                style="width:50px;height:50px;border-radius:8px;overflow:hidden;background:#eee;display:flex;align-items:center;justify-content:center;">
                                                <i class="fa-solid fa-cube text-secondary"></i>
                                            </div>
                                            <div class="flex-grow-1">
                                                <div class="fw-bold" id="sprd-name">—</div>
                                                <div class="small text-muted" id="sprd-meta">—</div>
                                            </div>
                                            <div class="text-end">
                                                <span class="badge bg-info-subtle text-info border border-info"
                                                    id="sprd-stock">— online</span>
                                            </div>
                                            <button type="button" class="btn-close"
                                                onclick="clearSaleProduct()"></button>
                                        </div>
                                        <input type="hidden" id="sale-variation-id">
                                        <input type="hidden" id="sale-online-stock-available">
                                    </div>
                                </div>

                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold">Platform <span
                                                class="text-danger">*</span></label>
                                        <select id="sale-platform" class="form-select">
                                            <option value="Shopee">🛒 Shopee</option>
                                            <option value="Lazada">🟣 Lazada</option>
                                            <option value="Facebook">📘 Facebook</option>
                                            <option value="TikTok">🎵 TikTok</option>
                                            <option value="Other">📦 Other</option>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold">Quantity Sold <span
                                                class="text-danger">*</span></label>
                                        <input type="number" id="sale-qty" class="form-control" min="1" value="1"
                                            placeholder="e.g. 2">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold">Selling Price (per unit)</label>
                                        <div class="input-group">
                                            <span class="input-group-text">₱</span>
                                            <input type="number" step="0.01" id="sale-price" class="form-control"
                                                placeholder="0.00">
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold">Order / Tracking Reference</label>
                                        <input type="text" id="sale-reference" class="form-control"
                                            placeholder="e.g. SHP-12345678">
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label fw-bold">Notes</label>
                                        <textarea id="sale-notes" class="form-control" rows="2"
                                            placeholder="Optional notes about this order…"></textarea>
                                    </div>
                                </div>

                                <!-- Total preview -->
                                <div class="mt-3 p-3 rounded border bg-success-subtle" id="sale-total-preview"
                                    style="display:none;">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <span class="fw-bold text-success">📦 Total Sale Amount</span>
                                        <span class="h5 mb-0 fw-bold text-success" id="sale-total-display">₱0.00</span>
                                    </div>
                                </div>

                                <div class="mt-4">
                                    <button class="btn btn-info btn-lg w-100 fw-bold text-white shadow-sm"
                                        id="btn-record-sale" onclick="recordOnlineSale()">
                                        <i class="fa-solid fa-check-circle me-2"></i>Record Online Sale
                                    </button>
                                </div>

                            </div>
                        </div>
                    </div>

                    <!-- Right: Recent online sales quick view -->
                    <div class="col-lg-5">
                        <div class="card shadow-sm border-0 h-100">
                            <div class="card-header fw-bold"
                                style="background:var(--card-bg);border-bottom:1px solid var(--border-color);">
                                <i class="fa-solid fa-list text-secondary me-2"></i>Recent Online Sales
                            </div>
                            <div class="card-body p-0">
                                <div id="recent-sales-list" style="max-height:420px;overflow-y:auto;">
                                    <div class="text-center py-5 text-muted small">
                                        <i class="fa-solid fa-spinner fa-spin me-1"></i> Loading…
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ================================================================
             TAB 3: SALES HISTORY
             ================================================================ -->
        <div id="tab-history" class="tab-pane-custom <?= $activeTab === 'history' ? 'active' : '' ?>">
            <div class="card-body pb-2">
                <div class="d-flex gap-2 align-items-center flex-wrap">
                    <div class="input-group" style="max-width:380px;">
                        <span class="input-group-text"
                            style="background:var(--bg-surface);border-color:var(--border-color);">
                            <i class="fa-solid fa-magnifying-glass text-secondary"></i>
                        </span>
                        <input type="text" id="history-search" class="form-control"
                            placeholder="Search product, reference…">
                        <span class="input-group-text d-none" id="history-spinner"
                            style="background:var(--bg-surface);border-color:var(--border-color);">
                            <i class="fa-solid fa-spinner fa-spin text-primary"></i>
                        </span>
                    </div>
                    <small class="text-muted" id="history-count"></small>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead style="background:var(--bg-surface);border-bottom:2px solid var(--border-color);">
                        <tr>
                            <th class="ps-4" style="color:var(--text-primary);">Date</th>
                            <th style="color:var(--text-primary);">Product</th>
                            <th style="color:var(--text-primary);">Type</th>
                            <th style="color:var(--text-primary);">Qty Change</th>
                            <th style="color:var(--text-primary);">Reference</th>
                            <th style="color:var(--text-primary);">Remarks</th>
                            <th style="color:var(--text-primary);">By</th>
                        </tr>
                    </thead>
                    <tbody id="history-tbody">
                        <tr>
                            <td colspan="7" class="text-center py-5 text-muted"><i
                                    class="fa-solid fa-spinner fa-spin me-2"></i>Loading…</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="card-footer" style="background:var(--card-bg);border-top:1px solid var(--border-color);">
                <div class="d-flex justify-content-between align-items-center gap-3 flex-wrap">
                    <small class="text-muted" id="history-showing"></small>
                    <div class="d-flex align-items-center gap-2">
                        <label class="text-muted small fw-bold text-nowrap" for="history-page-select">Page:</label>
                        <select id="history-page-select" class="form-select form-select-sm"
                            style="width:auto; min-width:70px;"></select>
                        <span class="text-muted small text-nowrap">of <span id="history-total-pages">1</span></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- ================================================================
             TAB 4: ONLINE PRODUCTS LIST
             ================================================================ -->
        <div id="tab-online_products" class="tab-pane-custom <?= $activeTab === 'online_products' ? 'active' : '' ?>">
            <!-- Filter Bar -->
            <div class="card-body pb-2">
                <div class="row g-2 align-items-center">
                    <div class="col-12 col-md-auto">
                        <div class="input-group" style="min-width:260px;max-width:360px;">
                            <span class="input-group-text"
                                style="background:var(--bg-surface);border-color:var(--border-color);">
                                <i class="fa-solid fa-magnifying-glass text-secondary"></i>
                            </span>
                            <input type="text" id="op-search" class="form-control"
                                placeholder="Search name, brand, SKU…">
                            <span class="input-group-text d-none" id="op-spinner"
                                style="background:var(--bg-surface);border-color:var(--border-color);">
                                <i class="fa-solid fa-spinner fa-spin text-primary"></i>
                            </span>
                        </div>
                    </div>
                    <div class="col-6 col-md-auto">
                        <select id="op-brand" class="form-select" style="min-width:160px;">
                            <option value="">All Brands</option>
                            <?php foreach ($onlineBrands as $brand): ?>
                                <option value="<?= htmlspecialchars($brand) ?>"><?= htmlspecialchars($brand) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6 col-md-auto">
                        <select id="op-stock-filter" class="form-select" style="min-width:160px;">
                            <option value="">All Online Stock Levels</option>
                            <option value="critical">Critical (&lt; 5 units)</option>
                            <option value="low">Low (5 – 20 units)</option>
                            <option value="ok">OK (&gt; 20 units)</option>
                        </select>
                    </div>
                    <div class="col-auto ms-auto">
                        <small class="text-muted" id="op-count"></small>
                    </div>
                </div>
            </div>

            <!-- Desktop Table -->
            <div class="table-responsive desktop-table">
                <table class="table table-hover align-middle mb-0" id="op-table">
                    <thead style="background:var(--bg-surface); border-bottom:2px solid var(--border-color);">
                        <tr>
                            <th class="ps-4" style="color:var(--text-primary);">Product</th>
                            <th style="color:var(--text-primary);">Brand</th>
                            <th style="color:var(--text-primary);">Physical Stock</th>
                            <th style="color:var(--text-primary);">Online Stock</th>
                            <?php if ($_SESSION['role'] === 'admin'): ?>
                                <th style="color:var(--text-primary);">SRP</th>
                            <?php endif; ?>
                            <th class="text-end pe-4" style="color:var(--text-primary);">Action</th>
                        </tr>
                    </thead>
                    <tbody id="op-tbody">
                        <tr>
                            <td colspan="<?= $_SESSION['role'] === 'admin' ? 6 : 5 ?>"
                                class="text-center py-5 text-muted">
                                <i class="fa-solid fa-spinner fa-spin me-2"></i>Loading…
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Mobile Cards -->
            <div class="mobile-cards px-2 pb-2" id="op-mobile-cards" style="display:none;"></div>

            <!-- Pagination Footer -->
            <div class="card-footer" style="background:var(--card-bg);border-top:1px solid var(--border-color);">
                <div class="d-flex justify-content-between align-items-center gap-3 flex-wrap">
                    <small class="text-muted" id="op-showing"></small>
                    <div class="d-flex align-items-center gap-2">
                        <label class="text-muted small fw-bold text-nowrap" for="op-page-select">Page:</label>
                        <select id="op-page-select" class="form-select form-select-sm"
                            style="width:auto; min-width:70px;"></select>
                        <span class="text-muted small text-nowrap">of <span id="op-total-pages">1</span></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- ================================================================
             TAB 5: PLATFORM LINKS
             ================================================================ -->
        <div id="tab-platform_links" class="tab-pane-custom <?= $activeTab === 'platform_links' ? 'active' : '' ?>">
            <div class="card-body">
                <div class="row g-4 pt-2">
                    <!-- Left: Form -->
                    <div class="col-lg-4">
                        <div class="card border border-primary shadow-sm h-100">
                            <div class="card-header bg-primary text-white fw-bold">
                                <i class="fa-solid fa-link"></i> Create Platform Link
                            </div>
                            <div class="card-body bg-light">
                                <form id="form-platform-link" onsubmit="submitPlatformLink(event)">
                                    <div class="mb-3">
                                        <label class="form-label fw-bold">Local Product Variation <span
                                                class="text-danger">*</span></label>
                                        <div class="position-relative">
                                            <div class="input-group">
                                                <span class="input-group-text"><i class="fa-solid fa-search"></i></span>
                                                <input type="text" id="link-product-search" class="form-control"
                                                    placeholder="Search product to link..." autocomplete="off">
                                            </div>
                                            <div id="link-search-dropdown" class="search-dropdown-sm d-none"></div>
                                        </div>
                                        <input type="hidden" id="link-variation-id">
                                        <div id="link-selected-product" class="mt-2 text-primary fw-bold"
                                            style="font-size: 0.85rem;"></div>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label fw-bold">Platform <span
                                                class="text-danger">*</span></label>
                                        <select id="link-platform" class="form-select" required>
                                            <option value="Shopee">🛒 Shopee</option>
                                            <option value="Lazada">🟣 Lazada</option>
                                            <option value="TikTok">🎵 TikTok</option>
                                            <option value="Facebook">📘 Facebook</option>
                                            <option value="Other">📦 Other</option>
                                        </select>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label fw-bold">Platform Variation ID <span
                                                class="text-danger">*</span></label>
                                        <input type="text" id="link-online-variation-id" class="form-control" required
                                            placeholder="e.g. 1294109403">
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label fw-bold">Platform Product ID (Optional)</label>
                                        <input type="text" id="link-online-product-id" class="form-control"
                                            placeholder="e.g. 88301920">
                                    </div>
                                    <div class="mb-4">
                                        <label class="form-label fw-bold">Platform SKU (Optional)</label>
                                        <input type="text" id="link-platform-sku" class="form-control"
                                            placeholder="e.g. SHP-SKU-123">
                                    </div>
                                    <button type="submit" class="btn btn-primary w-100 fw-bold" id="btn-save-link">
                                        <i class="fa-solid fa-save"></i> Save Link
                                    </button>
                                </form>
                            </div>
                        </div>

                        <!-- Bulk Map Importer Card -->
                        <div class="card border border-success shadow-sm mt-4">
                            <div
                                class="card-header bg-success text-white fw-bold d-flex justify-content-between align-items-center">
                                <span><i class="fa-solid fa-file-csv"></i> Bulk Import Links</span>
                                <a href="../../api/inventory/download_bulk_link_template.php"
                                    class="btn btn-sm btn-light text-success fw-bold p-1 px-2"
                                    style="font-size: 0.75rem;">
                                    <i class="fa-solid fa-download"></i> Template
                                </a>
                            </div>
                            <div class="card-body bg-light">
                                <form id="form-bulk-links" onsubmit="uploadBulkLinks(event)">
                                    <div class="mb-3">
                                        <label class="form-label fw-bold text-muted small">Upload Filled Template
                                            (CSV)</label>
                                        <input class="form-control" type="file" id="bulk-links-file" accept=".csv"
                                            required>
                                    </div>
                                    <button type="submit" class="btn btn-success w-100 fw-bold"
                                        id="btn-upload-bulk-links">
                                        <i class="fa-solid fa-upload"></i> Process File
                                    </button>
                                </form>
                                <div id="bulk-links-results" class="mt-3 d-none"
                                    style="max-height: 200px; overflow-y: auto;">
                                    <!-- Results appended here via JS -->
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Right: List -->
                    <div class="col-lg-8">
                        <div class="card border-0 shadow-sm h-100">
                            <div class="card-header bg-white pb-0 border-0">
                                <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
                                    <div class="fw-bold fs-6">Existing Links</div>
                                    <div class="d-flex gap-2 align-items-center">
                                        <button class="btn btn-sm btn-outline-primary fw-bold"
                                            onclick="forcePlatformSync()">
                                            <i class="fa-solid fa-rotate me-1"></i> Sync All Stock
                                        </button>
                                        <div class="input-group" style="width: 200px;">
                                            <span class="input-group-text bg-white"><i
                                                    class="fa-solid fa-search"></i></span>
                                            <input type="text" id="links-search" class="form-control form-control-sm"
                                                placeholder="Search links...">
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0" style="font-size: 0.9rem;">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Local Product</th>
                                            <th>Platform</th>
                                            <th>Platform IDs</th>
                                            <th>Linked By</th>
                                            <th class="text-end">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody id="links-tbody">
                                        <tr>
                                            <td colspan="5" class="text-center py-4 text-muted"><i
                                                    class="fa-solid fa-spinner fa-spin me-2"></i>Loading...</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <div
                                class="card-footer bg-white border-0 py-2 d-flex justify-content-between align-items-center">
                                <small class="text-muted" id="links-showing"></small>
                                <select id="links-page-select" class="form-select form-select-sm w-auto"></select>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ================================================================
             TAB 6: BULK TRANSFER
             ================================================================ -->
        <div id="tab-bulk_transfer" class="tab-pane-custom <?= $activeTab === 'bulk_transfer' ? 'active' : '' ?>">
            <div class="card-body">
                <div class="alert alert-info border-0 rounded-3 mb-4 d-flex gap-3 align-items-center shadow-sm">
                    <i class="fa-solid fa-truck-fast text-info fs-1"></i>
                    <div>
                        <h6 class="fw-bold mb-1">Bulk Stock Transfer from Store to Online</h6>
                        <p class="mb-0 small mb-2">Transfer stock quickly by simply pasting a list of <strong>Platform
                                Variation IDs</strong> and quantities, or by uploading an Excel/CSV file.</p>
                        <a href="../../api/inventory/download_platform_transfer_template.php"
                            class="btn btn-sm btn-info text-white fw-bold shadow-sm">
                            <i class="fa-solid fa-download me-1"></i>Download Transfer Template
                        </a>
                    </div>
                </div>

                <div class="row g-4">
                    <div class="col-lg-5">
                        <div class="card shadow-sm border-0 h-100">
                            <div class="card-header bg-dark text-white fw-bold">
                                1. Input Transfer Data
                            </div>
                            <div class="card-body">
                                <ul class="nav nav-tabs nav-fill mb-3" role="tablist">
                                    <li class="nav-item" role="presentation">
                                        <button class="nav-link active fw-bold" id="paste-tab" data-bs-toggle="tab"
                                            data-bs-target="#paste-pane" type="button" role="tab">
                                            <i class="fa-solid fa-clipboard me-1"></i> Paste Data
                                        </button>
                                    </li>
                                    <li class="nav-item" role="presentation">
                                        <button class="nav-link fw-bold" id="upload-tab" data-bs-toggle="tab"
                                            data-bs-target="#upload-pane" type="button" role="tab">
                                            <i class="fa-solid fa-file-excel me-1"></i> Upload File
                                        </button>
                                    </li>
                                </ul>

                                <div class="tab-content" id="transferInputTabs">
                                    <div class="tab-pane fade show active" id="paste-pane" role="tabpanel" tabindex="0">
                                        <label class="form-label fw-bold small text-muted">Format: <code
                                                class="bg-light px-1">VariationID, Qty</code></label>
                                        <textarea id="bulk-transfer-input" class="form-control text-monospace" rows="12"
                                            placeholder="Example&#10;1294109403, 5&#10;901824109, 10"
                                            style="font-family: monospace; font-size: 0.9rem; background: #f8f9fa;"></textarea>
                                        <div class="form-text mt-3 p-3 bg-light rounded-3 border"
                                            style="font-size:0.82rem; color: #555;">
                                            <div class="fw-bold mb-1 text-primary"><i
                                                    class="fa-solid fa-circle-info me-1"></i> Format Instructions:</div>
                                            <p class="mb-2">Paste your data in columns or upload an export file. The
                                                system will automatically detect the following columns:</p>
                                            <ul class="mb-2 ps-3">
                                                <li><strong>Platform Product ID</strong> (Optional, for reference)</li>
                                                <li><strong>Platform Variation ID</strong> (or SKU ID / ID)</li>
                                                <li><strong>Quantity</strong> (or Stock / Current Stock)</li>
                                            </ul>
                                            <div class="small fw-bold text-muted mt-2">Example Paste Format (Product ID,
                                                Variation ID, Qty):</div>
                                            <code>244077212565, 54200831219, 10</code><br>
                                            <code>244077212565, 54200831220, 5</code>
                                            <div class="mt-2 text-muted x-small">Note: Multiple variations for the same
                                                product are handled independently to ensure accurate stock tracking.
                                            </div>
                                        </div>
                                        <div class="form-text small mt-2">
                                            <i class="fa-solid fa-circle-info text-primary"></i> Paste directly from
                                            Excel or enter manually. Ensure your products are linked in the
                                            <strong>Platform Links</strong> tab!
                                        </div>
                                        <button class="btn btn-primary w-100 fw-bold mt-4" id="btn-process-bulk"
                                            onclick="processBulkTransfer()">
                                            <i class="fa-solid fa-rocket me-1"></i> Process Transfers
                                        </button>
                                    </div>
                                    <div class="tab-pane fade" id="upload-pane" role="tabpanel" tabindex="0">
                                        <form id="bulk-transfer-upload-form" onsubmit="processBulkTransferFile(event)">
                                            <div class="mb-4">
                                                <label class="form-label fw-bold">Select Excel/CSV File</label>
                                                <input class="form-control" type="file" id="bulk-transfer-file"
                                                    accept=".csv, .xlsx, .xls" required>
                                                <div class="form-text small mt-2">
                                                    Ensure headers are <code>Platform Variation ID</code> and
                                                    <code>Quantity</code>.
                                                </div>
                                            </div>
                                            <button type="submit" class="btn btn-success w-100 fw-bold mt-4"
                                                id="btn-upload-bulk">
                                                <i class="fa-solid fa-upload me-1"></i> Upload & Process Transfers
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-7">
                        <div class="card shadow-sm border-0 h-100">
                            <div class="card-header bg-white fw-bold border-bottom">
                                2. Processing Results
                            </div>
                            <div class="card-body p-0">
                                <div
                                    class="p-3 bg-light border-bottom d-flex justify-content-between align-items-center">
                                    <div class="small fw-bold text-muted text-uppercase">Result Log</div>
                                    <div class="small" id="bulk-result-stats"></div>
                                </div>
                                <div id="bulk-results-container" class="p-0"
                                    style="max-height: 500px; overflow-y: auto;">
                                    <div class="text-center py-5 text-muted small">
                                        <i class="fa-solid fa-check-double fa-2x mb-2" style="opacity:0.3"></i>
                                        <div>Results will appear here</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ================================================================
             TAB 7: SALES SYNC
             ================================================================ -->
        <div id="tab-sales_sync" class="tab-pane-custom <?= $activeTab === 'sales_sync' ? 'active' : '' ?>">
            <div class="card-body">
                <div class="alert border-0 rounded-3 mb-4 d-flex gap-3 align-items-center shadow-sm"
                    style="background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%); color: #e0e0e0;">
                    <i class="fa-solid fa-rotate fa-2x" style="color:#00d4ff;"></i>
                    <div>
                        <h6 class="fw-bold mb-1" style="color:#fff;">Bulk Sales Sync — Auto-detect Sales from Platform
                            Exports</h6>
                        <p class="mb-0 small" style="color:#adb5bd;">Upload your Shopee / Lazada stock export file. The
                            system will compare the platform's remaining stock against Ella POS and automatically
                            calculate and record what was sold.</p>
                    </div>
                </div>

                <div class="row g-4">
                    <!-- Left: Upload Form -->
                    <div class="col-lg-4">
                        <div class="card border-0 shadow-sm h-100">
                            <div class="card-header text-white fw-bold" style="background: #16213e;">
                                <i class="fa-solid fa-file-arrow-up me-1"></i> 1. Upload Platform Export
                            </div>
                            <div class="card-body">
                                <form id="form-sales-sync" onsubmit="previewSalesSync(event)">
                                    <div class="mb-3">
                                        <label class="form-label fw-bold">Platform</label>
                                        <select id="sync-platform" class="form-select" required>
                                            <option value="Shopee">🛒 Shopee</option>
                                            <option value="Lazada">🟣 Lazada</option>
                                            <option value="TikTok">🎵 TikTok</option>
                                            <option value="Facebook">📘 Facebook</option>
                                            <option value="Other">📦 Other</option>
                                        </select>
                                    </div>
                                    <div class="mb-4">
                                        <label class="form-label fw-bold">Excel / CSV File <span
                                                class="text-danger">*</span></label>
                                        <input type="file" id="sync-file" class="form-control" accept=".csv,.xlsx,.xls"
                                            required>
                                        <div class="form-text small mt-2">
                                            <i class="fa-solid fa-circle-info text-primary"></i>
                                            The system automatically detects <strong>Variation ID</strong> and
                                            <strong>Stock</strong> columns from your export.
                                            Multiple variations per product are supported and summed independently.
                                        </div>
                                    </div>
                                    <button type="submit" class="btn w-100 fw-bold" id="btn-sync-preview"
                                        style="background:#16213e; color:#fff;">
                                        <i class="fa-solid fa-eye me-1"></i> Preview Sales to Record
                                    </button>
                                </form>

                                <div id="sync-how-it-works" class="mt-4">
                                    <div class="fw-bold text-muted small text-uppercase mb-2">How it works</div>
                                    <div class="d-flex gap-2 align-items-start mb-2 small">
                                        <span class="badge rounded-pill bg-primary">1</span>
                                        <span>Platform says <strong>44 left</strong></span>
                                    </div>
                                    <div class="d-flex gap-2 align-items-start mb-2 small">
                                        <span class="badge rounded-pill bg-primary">2</span>
                                        <span>Ella POS shows <strong>50 online</strong></span>
                                    </div>
                                    <div class="d-flex gap-2 align-items-start mb-2 small">
                                        <span class="badge rounded-pill bg-success">3</span>
                                        <span>System records <strong>6 units sold</strong> 🎉</span>
                                    </div>
                                    <div class="d-flex gap-2 align-items-start small">
                                        <span class="badge rounded-pill" style="background:#16213e;">4</span>
                                        <span>Stock is deducted and audit log is created</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Right: Results -->
                    <div class="col-lg-8">
                        <div class="card border-0 shadow-sm h-100">
                            <div
                                class="card-header bg-white fw-bold border-bottom d-flex justify-content-between align-items-center">
                                <div>2. Review &amp; Confirm</div>
                                <div class="small" id="sync-result-stats"></div>
                            </div>
                            <div class="card-body p-0">
                                <div id="sync-results-container" style="max-height:520px; overflow-y:auto;">
                                    <div class="text-center py-5 text-muted small">
                                        <i class="fa-solid fa-rotate fa-2x mb-2" style="opacity:0.2;"></i>
                                        <div>Upload a file to preview detected sales</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div><!-- /card -->

</div><!-- /container-fluid -->

<!-- ================================================================
     EDIT ONLINE STOCK MODAL
     ================================================================ -->
<div class="modal fade" id="editOnlineStockModal" tabindex="-1">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title fw-bold"><i class="fa-solid fa-pencil text-info me-2"></i>Edit Online Stock</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3 p-2 rounded bg-light">
                    <div class="fw-bold" id="edit-modal-product-name">—</div>
                    <small class="text-muted" id="edit-modal-product-meta">—</small>
                </div>
                <label class="form-label fw-bold">New Online Stock Quantity</label>
                <input type="number" id="edit-modal-qty" class="form-control form-control-lg text-center fw-bold"
                    min="0" value="0">
                <div class="mt-2">
                    <label class="form-label small">Reason (optional)</label>
                    <input type="text" id="edit-modal-reason" class="form-control form-control-sm"
                        placeholder="e.g. Return from courier, correction…">
                </div>
                <input type="hidden" id="edit-modal-variation-id">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-info btn-sm text-white fw-bold" onclick="saveOnlineStock()">
                    <i class="fa-solid fa-check me-1"></i>Save
                </button>
            </div>
        </div>
    </div>
</div>

<script>
    const BASE_URL = '<?= BASE_URL ?>';
    const IS_ADMIN = <?= json_encode($_SESSION['role'] === 'admin') ?>;

    // =====================================================================
    //  TAB SWITCHING
    // =====================================================================
    function switchTab(tabId, btnEl) {
        document.querySelectorAll('.tab-pane-custom').forEach(p => p.classList.remove('active'));
        document.querySelectorAll('.online-tab-btn').forEach(b => b.classList.remove('active'));
        document.getElementById('tab-' + tabId).classList.add('active');
        btnEl.classList.add('active');

        // Lazy-load content on first open
        if (tabId === 'overview' && !OverviewTab.loaded) OverviewTab.load();
        if (tabId === 'record_sale' && !SaleTab.loaded) SaleTab.load();
        if (tabId === 'history' && !HistoryTab.loaded) HistoryTab.load();
        if (tabId === 'online_products' && !OnlineProductsTab.loaded) OnlineProductsTab.load();
        if (tabId === 'platform_links' && !PlatformLinksTab.loaded) PlatformLinksTab.load();
    }

    // =====================================================================
    //  HELPERS
    // =====================================================================
    function escH(str) {
        const d = document.createElement('div');
        d.textContent = str || '';
        return d.innerHTML;
    }

    function fmtNum(n, dec = 0) {
        return parseFloat(n || 0).toLocaleString('en-PH', { minimumFractionDigits: dec, maximumFractionDigits: dec });
    }

    function highlightQuery(text, query) {
        if (!text) return '';
        let hlText = escH(text);
        query = query ? query.trim() : '';
        if (!query) return hlText;
        const safeQuery = query.replace(/[.*+?^${}()|[\\]\\\\]/g, '\\\\$&').split(/\\s+/).filter(Boolean);
        if (safeQuery.length === 0) return hlText;
        safeQuery.forEach(q => {
            const regex = new RegExp(`(${q})`, 'gi');
            hlText = hlText.replace(regex, '<mark class="bg-warning bg-opacity-50 p-0 rounded text-dark">$1</mark>');
        });
        return hlText;
    }

    function platformBadge(remarks) {
        const platforms = { Shopee: 'shopee', Lazada: 'lazada', Facebook: 'facebook', TikTok: 'tiktok' };
        for (const [name, cls] of Object.entries(platforms)) {
            if (remarks && remarks.toLowerCase().includes(name.toLowerCase())) {
                return `<span class="platform-badge platform-${cls}">${name}</span>`;
            }
        }
        return `<span class="platform-badge platform-other">Other</span>`;
    }

    function showToast(msg, type = 'success') {
        const wrap = document.createElement('div');
        wrap.className = `alert alert-${type} alert-dismissible position-fixed shadow-lg`;
        wrap.style.cssText = 'bottom:24px;right:24px;z-index:9999;min-width:280px;animation:fadeIn 0.3s ease;';
        wrap.innerHTML = `${msg}<button type="button" class="btn-close" onclick="this.parentElement.remove()"></button>`;
        document.body.appendChild(wrap);
        setTimeout(() => wrap.remove(), 4000);
    }

    // =====================================================================
    //  TAB 1: OVERVIEW
    // =====================================================================
    const OverviewTab = {
        loaded: false,
        page: 1,
        totalPages: 1,
        query: '',
        timer: null,

        load() {
            this.loaded = true;
            this.fetch();

            const inp = document.getElementById('overview-search');
            inp.addEventListener('input', e => {
                clearTimeout(this.timer);
                this.timer = setTimeout(() => { this.query = e.target.value.trim(); this.page = 1; this.fetch(); }, 300);
            });

            document.getElementById('overview-page-select').addEventListener('change', e => {
                this.page = parseInt(e.target.value);
                this.fetch();
            });
        },

        fetch() {
            const spinner = document.getElementById('overview-spinner');
            spinner?.classList.remove('d-none');

            const url = `${BASE_URL}api/inventory/search_products.php?q=${encodeURIComponent(this.query)}&page=${this.page}&limit=50&status=active`;

            fetch(url)
                .then(r => r.json())
                .then(data => {
                    this.render(data.products || []);
                    this.updatePagination(data.pagination || {});
                })
                .catch(() => {
                    document.getElementById('overview-tbody').innerHTML =
                        '<tr><td colspan="4" class="text-center text-danger py-4">Failed to load inventory</td></tr>';
                })
                .finally(() => spinner?.classList.add('d-none'));
        },

        render(products) {
            const tbody = document.getElementById('overview-tbody');
            if (!products.length) {
                tbody.innerHTML = '<tr><td colspan="4" class="text-center py-5 text-muted">No products found</td></tr>';
                return;
            }

            tbody.innerHTML = products.map(p => {
                const phys = parseInt(p.physical_stock) || 0;
                const online = parseInt(p.online_stock) || 0;
                const img = p.image_path
                    ? `<img src="${BASE_URL}${escH(p.image_path)}" style="width:36px;height:36px;object-fit:cover;border-radius:6px;border:1px solid #eee;" alt="">`
                    : `<div style="width:36px;height:36px;border-radius:6px;background:#f3f4f6;display:flex;align-items:center;justify-content:center;"><i class="fa-solid fa-cube text-secondary fa-sm"></i></div>`;

                const physBadge = phys === 0
                    ? `<span class="stock-pill bg-danger-subtle text-danger"><i class="fa-solid fa-circle-xmark fa-xs"></i>0</span>`
                    : `<span class="stock-pill bg-success-subtle text-success"><i class="fa-solid fa-store fa-xs"></i>${fmtNum(phys)}</span>`;

                const onlineBadge = online === 0
                    ? `<span class="stock-pill bg-secondary-subtle text-secondary"><i class="fa-solid fa-globe fa-xs"></i>0</span>`
                    : `<span class="stock-pill bg-info-subtle text-info"><i class="fa-solid fa-globe fa-xs"></i>${fmtNum(online)}</span>`;

                return `<tr class="row-animated">
                <td class="ps-4">
                    <div class="d-flex align-items-center gap-2">
                        ${img}
                        <div>
                            <div class="fw-bold small" style="color:var(--text-primary);">${highlightQuery(p.product_name, this.query)}</div>
                            <div class="text-muted" style="font-size:0.75rem;">${highlightQuery(p.brand_name, this.query)} · <span class="text-primary">${highlightQuery(p.variation_name, this.query)}</span></div>
                            ${p.sku ? `<span class="badge border text-muted" style="font-size:0.68rem;background:var(--bg-surface);">${highlightQuery(p.sku, this.query)}</span>` : ''}
                        </div>
                    </div>
                </td>
                <td>${physBadge}</td>
                <td>${onlineBadge}</td>
                <td class="text-end pe-4">
                    <button class="btn btn-sm btn-outline-info"
                        onclick="openEditModal(${p.variation_id}, '${escH(p.product_name).replace(/'/g, "\\'")}', '${escH(p.brand_name)} · ${escH(p.variation_name)}', ${online})"
                        title="Edit Online Stock">
                        <i class="fa-solid fa-pencil"></i> Edit
                    </button>
                </td>
            </tr>`;
            }).join('');
        },

        updatePagination(pag) {
            const total = pag.total_items || 0;
            const current = pag.current_page || 1;
            this.totalPages = pag.total_pages || 1;
            this.page = current;

            document.getElementById('overview-showing').textContent =
                `Showing ${Math.min((current - 1) * 50 + 1, total)}–${Math.min(current * 50, total)} of ${total} products`;
            document.getElementById('overview-total-pages').textContent = this.totalPages;
            document.getElementById('overview-count').textContent = `(${total} total)`;

            const sel = document.getElementById('overview-page-select');
            sel.innerHTML = '';
            for (let i = 1; i <= this.totalPages; i++) {
                const opt = document.createElement('option');
                opt.value = i; opt.textContent = i;
                if (i === current) opt.selected = true;
                sel.appendChild(opt);
            }
        }
    };

    // =====================================================================
    //  EDIT ONLINE STOCK MODAL
    // =====================================================================
    let editModal = null;

    function openEditModal(varId, name, meta, currentOnline) {
        document.getElementById('edit-modal-variation-id').value = varId;
        document.getElementById('edit-modal-product-name').textContent = name;
        document.getElementById('edit-modal-product-meta').textContent = meta;
        document.getElementById('edit-modal-qty').value = currentOnline;
        document.getElementById('edit-modal-reason').value = '';

        if (!editModal) editModal = new bootstrap.Modal(document.getElementById('editOnlineStockModal'));
        editModal.show();
    }

    async function saveOnlineStock() {
        const varId = parseInt(document.getElementById('edit-modal-variation-id').value);
        const qty = parseInt(document.getElementById('edit-modal-qty').value);
        const reason = document.getElementById('edit-modal-reason').value.trim();

        if (isNaN(qty) || qty < 0) { showToast('Please enter a valid quantity.', 'warning'); return; }

        const btn = document.querySelector('#editOnlineStockModal .btn-info');
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i>Saving…';

        try {
            const res = await fetch(`${BASE_URL}api/inventory/update_online_stock.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ variation_id: varId, new_quantity: qty, reason: reason || 'Manual adjustment' })
            });
            const data = await res.json();

            if (data.success) {
                editModal.hide();
                showToast(`✅ Online stock updated successfully. New stock: <strong>${qty}</strong>`);
                OverviewTab.fetch(); // Refresh table
            } else {
                showToast(`❌ ${data.message}`, 'danger');
            }
        } catch (e) {
            showToast('❌ Network error. Please try again.', 'danger');
        } finally {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-check me-1"></i>Save';
        }
    }

    // =====================================================================
    //  TAB 2: RECORD ONLINE SALE
    // =====================================================================
    const SaleTab = {
        loaded: false,
        selectedProduct: null,
        searchTimer: null,

        load() {
            this.loaded = true;
            this.loadRecentSales();
            this.initSearch();
            this.initTotalPreview();
        },

        initSearch() {
            const inp = document.getElementById('sale-product-search');
            const dd = document.getElementById('sale-search-dropdown');

            inp.addEventListener('input', e => {
                clearTimeout(this.searchTimer);
                const q = e.target.value.trim();
                if (q.length < 1) { dd.classList.add('d-none'); return; }
                this.searchTimer = setTimeout(() => this.searchProducts(q), 300);
            });

            document.addEventListener('click', e => {
                if (!e.target.closest('#sale-product-search') && !e.target.closest('#sale-search-dropdown')) {
                    dd.classList.add('d-none');
                }
            });
        },

        async searchProducts(q) {
            const dd = document.getElementById('sale-search-dropdown');
            dd.innerHTML = '<div class="product-option text-muted small"><i class="fa-solid fa-spinner fa-spin me-1"></i>Searching…</div>';
            dd.classList.remove('d-none');

            try {
                const res = await fetch(`${BASE_URL}api/inventory/search_products.php?q=${encodeURIComponent(q)}&limit=10&status=active`);
                const data = await res.json();
                const products = data.products || [];

                if (!products.length) {
                    dd.innerHTML = '<div class="product-option text-muted small text-center">No products found</div>';
                    return;
                }

                dd.innerHTML = products.slice(0, 10).map(p => {
                    const online = parseInt(p.online_stock) || 0;
                    const img = p.image_path
                        ? `<img src="${BASE_URL}${escH(p.image_path)}" style="width:36px;height:36px;object-fit:cover;border-radius:6px;" alt="">`
                        : `<div style="width:36px;height:36px;border-radius:6px;background:#f3f4f6;display:flex;align-items:center;justify-content:center;"><i class="fa-solid fa-cube text-secondary fa-sm"></i></div>`;

                    return `<div class="product-option d-flex align-items-center gap-2"
                    onclick="SaleTab.selectProduct(${p.variation_id}, '${escH(p.product_name).replace(/'/g, "\\'")}', '${escH(p.brand_name).replace(/'/g, "\\'")} · ${escH(p.variation_name).replace(/'/g, "\\'")}', ${online}, '${escH(p.image_path || '')}', ${parseFloat(p.price_retail || 0)})">
                    ${img}
                    <div class="flex-grow-1">
                        <div class="fw-bold small">${highlightQuery(p.product_name, q)}</div>
                        <div class="text-muted" style="font-size:0.75rem;">${highlightQuery(p.brand_name, q)} · ${highlightQuery(p.variation_name, q)}</div>
                    </div>
                    <span class="badge ${online > 0 ? 'bg-info-subtle text-info border border-info' : 'bg-secondary-subtle text-secondary'} small">
                        <i class="fa-solid fa-globe fa-xs me-1"></i>${online} online
                    </span>
                </div>`;
                }).join('');

            } catch (e) {
                dd.innerHTML = '<div class="product-option text-danger small">Search failed</div>';
            }
        },

        selectProduct(varId, name, meta, onlineStock, imgPath, srp) {
            this.selectedProduct = { varId, name, meta, onlineStock, srp };

            document.getElementById('sale-variation-id').value = varId;
            document.getElementById('sale-online-stock-available').value = onlineStock;
            document.getElementById('sprd-name').textContent = name;
            document.getElementById('sprd-meta').textContent = meta;
            document.getElementById('sprd-stock').textContent = `${onlineStock} online`;
            document.getElementById('sprd-stock').className = `badge ${onlineStock > 0 ? 'bg-info-subtle text-info border border-info' : 'bg-danger-subtle text-danger border border-danger'}`;

            const imgEl = document.getElementById('sprd-img');
            if (imgPath) {
                imgEl.innerHTML = `<img src="${BASE_URL}${escH(imgPath)}" style="width:50px;height:50px;object-fit:cover;border-radius:8px;" alt="">`;
            } else {
                imgEl.innerHTML = '<i class="fa-solid fa-cube text-secondary"></i>';
            }

            // Pre-fill SRP
            if (srp > 0) document.getElementById('sale-price').value = srp.toFixed(2);

            document.getElementById('sale-product-search').value = '';
            document.getElementById('sale-search-dropdown').classList.add('d-none');
            document.getElementById('sale-selected-product').classList.remove('d-none');
            this.updateTotal();
        },

        initTotalPreview() {
            ['sale-qty', 'sale-price'].forEach(id => {
                document.getElementById(id)?.addEventListener('input', () => this.updateTotal());
            });
        },

        updateTotal() {
            const qty = parseFloat(document.getElementById('sale-qty').value) || 0;
            const price = parseFloat(document.getElementById('sale-price').value) || 0;
            const total = qty * price;
            const preview = document.getElementById('sale-total-preview');
            if (price > 0 && qty > 0) {
                document.getElementById('sale-total-display').textContent = '₱' + fmtNum(total, 2);
                preview.style.display = '';
            } else {
                preview.style.display = 'none';
            }
        },

        async loadRecentSales() {
            try {
                const res = await fetch(`${BASE_URL}api/inventory/get_online_sales.php?limit=8`);
                const data = await res.json();
                const list = document.getElementById('recent-sales-list');

                if (!data.records || !data.records.length) {
                    list.innerHTML = '<div class="text-center py-4 text-muted small">No recent online sales</div>';
                    return;
                }

                list.innerHTML = data.records.map(r => {
                    const isAdj = r.type === 'online_adjustment';
                    const qty = parseInt(r.quantity);
                    const sign = qty >= 0 ? '+' : '';
                    const color = qty >= 0 ? 'text-success' : 'text-danger';
                    const date = new Date(r.created_at).toLocaleDateString('en-PH', { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });

                    return `<div class="px-3 py-2 border-bottom" style="font-size:0.82rem;">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="fw-bold">${escH(r.product_name)}</div>
                            <div class="text-muted" style="font-size:0.75rem;">${escH(r.brand_name)} · ${escH(r.variation_name)}</div>
                            <div class="text-muted" style="font-size:0.72rem;">${escH(r.reference || '—')}</div>
                        </div>
                        <div class="text-end">
                            <div class="${color} fw-bold">${sign}${qty}</div>
                            <div class="text-muted" style="font-size:0.7rem;">${date}</div>
                            ${isAdj ? '<span class="badge bg-warning-subtle text-dark" style="font-size:0.65rem;">Adj</span>' : platformBadge(r.remarks)}
                        </div>
                    </div>
                </div>`;
                }).join('');

            } catch (e) {
                document.getElementById('recent-sales-list').innerHTML = '<div class="text-center text-muted small py-3">Failed to load</div>';
            }
        }
    };

    function clearSaleProduct() {
        SaleTab.selectedProduct = null;
        document.getElementById('sale-variation-id').value = '';
        document.getElementById('sale-online-stock-available').value = '';
        document.getElementById('sale-selected-product').classList.add('d-none');
        document.getElementById('sale-product-search').value = '';
        document.getElementById('sale-total-preview').style.display = 'none';
    }

    async function recordOnlineSale() {
        const varId = parseInt(document.getElementById('sale-variation-id').value);
        const qty = parseInt(document.getElementById('sale-qty').value);
        const platform = document.getElementById('sale-platform').value;
        const price = parseFloat(document.getElementById('sale-price').value) || 0;
        const reference = document.getElementById('sale-reference').value.trim();
        const notes = document.getElementById('sale-notes').value.trim();
        const available = parseInt(document.getElementById('sale-online-stock-available').value) || 0;

        if (!varId) { showToast('⚠️ Please select a product.', 'warning'); return; }
        if (!qty || qty < 1) { showToast('⚠️ Please enter a valid quantity.', 'warning'); return; }
        if (qty > available) {
            showToast(`⚠️ Only <strong>${available}</strong> units available in online stock.`, 'warning');
            return;
        }

        const btn = document.getElementById('btn-record-sale');
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-2"></i>Processing…';

        try {
            const res = await fetch(`${BASE_URL}api/inventory/process_online_sale.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ variation_id: varId, quantity: qty, platform, price, reference, notes })
            });
            const data = await res.json();

            if (data.success) {
                showToast(`✅ Sale recorded! ${qty} unit(s) deducted from online stock.`);
                clearSaleProduct();
                document.getElementById('sale-qty').value = 1;
                document.getElementById('sale-price').value = '';
                document.getElementById('sale-reference').value = '';
                document.getElementById('sale-notes').value = '';
                document.getElementById('sale-total-preview').style.display = 'none';

                // Refresh side panels
                SaleTab.loadRecentSales();
                if (OverviewTab.loaded) OverviewTab.fetch();
                if (HistoryTab.loaded) HistoryTab.fetch();
            } else {
                showToast(`❌ ${data.message}`, 'danger');
            }
        } catch (e) {
            showToast('❌ Network error. Please try again.', 'danger');
        } finally {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-check-circle me-2"></i>Record Online Sale';
        }
    }

    // =====================================================================
    //  TAB 3: HISTORY
    // =====================================================================
    const HistoryTab = {
        loaded: false,
        page: 1,
        totalPages: 1,
        query: '',
        timer: null,

        load() {
            this.loaded = true;
            this.fetch();

            document.getElementById('history-search').addEventListener('input', e => {
                clearTimeout(this.timer);
                this.timer = setTimeout(() => { this.query = e.target.value.trim(); this.page = 1; this.fetch(); }, 300);
            });

            document.getElementById('history-page-select').addEventListener('change', e => {
                this.page = parseInt(e.target.value);
                this.fetch();
            });
        },

        fetch() {
            const spinner = document.getElementById('history-spinner');
            spinner?.classList.remove('d-none');

            const url = `${BASE_URL}api/inventory/get_online_sales.php?q=${encodeURIComponent(this.query)}&page=${this.page}&limit=50`;

            fetch(url)
                .then(r => r.json())
                .then(data => {
                    this.render(data.records || []);
                    this.updatePagination(data.pagination || {});
                })
                .catch(() => {
                    document.getElementById('history-tbody').innerHTML =
                        '<tr><td colspan="7" class="text-center text-danger py-4">Failed to load history</td></tr>';
                })
                .finally(() => spinner?.classList.add('d-none'));
        },

        render(records) {
            const tbody = document.getElementById('history-tbody');
            if (!records.length) {
                tbody.innerHTML = '<tr><td colspan="7" class="text-center py-5 text-muted">No records found</td></tr>';
                return;
            }

            tbody.innerHTML = records.map(r => {
                const qty = parseInt(r.quantity);
                const sign = qty >= 0 ? '+' : '';
                const color = qty >= 0 ? 'text-success' : 'text-danger';
                const isAdj = r.type === 'online_adjustment';
                const date = new Date(r.created_at).toLocaleDateString('en-PH', {
                    month: 'short', day: 'numeric', year: 'numeric',
                    hour: '2-digit', minute: '2-digit'
                });

                const typeBadge = isAdj
                    ? '<span class="badge bg-warning-subtle text-dark border border-warning">Adjustment</span>'
                    : `<span class="badge bg-info-subtle text-info border border-info">${platformBadge(r.remarks)}</span>`;

                return `<tr class="row-animated">
                <td class="ps-4 small text-muted text-nowrap">${escH(date)}</td>
                <td>
                    <div class="fw-bold small" style="color:var(--text-primary);">${highlightQuery(r.product_name, this.query)}</div>
                    <div class="text-muted" style="font-size:0.74rem;">${highlightQuery(r.brand_name, this.query)} · ${highlightQuery(r.variation_name, this.query)}</div>
                    ${r.sku ? `<span class="badge border text-muted" style="font-size:0.67rem;background:var(--bg-surface);">${highlightQuery(r.sku, this.query)}</span>` : ''}
                </td>
                <td>${isAdj ? '<span class="badge bg-warning-subtle text-dark border border-warning">Adjustment</span>' : platformBadge(r.remarks)}</td>
                <td>
                    <span class="${color} fw-bold">${sign}${fmtNum(Math.abs(qty))}</span>
                    <div class="text-muted" style="font-size:0.74rem;">${escH(r.new_stock)} remaining</div>
                </td>
                <td class="small"><code>${escH(r.reference || '—')}</code></td>
                <td class="small text-muted" style="max-width:180px;word-break:break-word;">${escH(r.remarks || '—')}</td>
                <td class="small text-muted">${escH(r.processed_by || '—')}</td>
            </tr>`;
            }).join('');
        },

        updatePagination(pag) {
            const total = pag.total_items || 0;
            const current = pag.current_page || 1;
            this.totalPages = pag.total_pages || 1;
            this.page = current;

            document.getElementById('history-showing').textContent =
                `Showing ${Math.min((current - 1) * 50 + 1, total)}–${Math.min(current * 50, total)} of ${total} records`;
            document.getElementById('history-total-pages').textContent = this.totalPages;
            document.getElementById('history-count').textContent = `(${total} records)`;

            const sel = document.getElementById('history-page-select');
            sel.innerHTML = '';
            for (let i = 1; i <= this.totalPages; i++) {
                const opt = document.createElement('option');
                opt.value = i; opt.textContent = i;
                if (i === current) opt.selected = true;
                sel.appendChild(opt);
            }
        }
    };

    // =====================================================================
    //  TAB 4: ONLINE PRODUCTS LIST
    // =====================================================================
    const IS_ADMIN_OP = <?= json_encode($_SESSION['role'] === 'admin') ?>;

    const OnlineProductsTab = {
        loaded: false,
        page: 1,
        totalPages: 1,
        query: '',
        brand: '',
        stockLevel: '',
        timer: null,

        load() {
            this.loaded = true;
            this.fetch();

            document.getElementById('op-search').addEventListener('input', e => {
                clearTimeout(this.timer);
                this.timer = setTimeout(() => { this.query = e.target.value.trim(); this.page = 1; this.fetch(); }, 300);
            });

            document.getElementById('op-brand').addEventListener('change', e => {
                this.brand = e.target.value; this.page = 1; this.fetch();
            });

            document.getElementById('op-stock-filter').addEventListener('change', e => {
                this.stockLevel = e.target.value; this.page = 1; this.fetch();
            });

            document.getElementById('op-page-select').addEventListener('change', e => {
                this.page = parseInt(e.target.value); this.fetch();
            });

            this._updateResponsive();
            window.addEventListener('resize', () => this._updateResponsive());
        },

        _updateResponsive() {
            const isDesktop = window.innerWidth >= 992;
            const tbl = document.getElementById('op-table');
            if (tbl) tbl.closest('.table-responsive').style.display = isDesktop ? '' : 'none';
            const cards = document.getElementById('op-mobile-cards');
            if (cards) cards.style.display = isDesktop ? 'none' : '';
        },

        fetch() {
            const spinner = document.getElementById('op-spinner');
            spinner?.classList.remove('d-none');

            let url = `${BASE_URL}api/inventory/search_products.php?online_only=1&status=active&page=${this.page}&limit=50`;
            if (this.query) url += `&q=${encodeURIComponent(this.query)}`;
            if (this.brand) url += `&category=${encodeURIComponent(this.brand)}`;

            fetch(url)
                .then(r => r.json())
                .then(data => {
                    let products = data.products || [];

                    // Client-side stock-level filter
                    if (this.stockLevel === 'critical') {
                        products = products.filter(p => parseInt(p.online_stock) < 5);
                    } else if (this.stockLevel === 'low') {
                        products = products.filter(p => { const s = parseInt(p.online_stock); return s >= 5 && s <= 20; });
                    } else if (this.stockLevel === 'ok') {
                        products = products.filter(p => parseInt(p.online_stock) > 20);
                    }

                    this.render(products);
                    this.updatePagination(data.pagination || {}, products.length);
                })
                .catch(() => {
                    document.getElementById('op-tbody').innerHTML =
                        '<tr><td colspan="6" class="text-center text-danger py-4">Failed to load</td></tr>';
                })
                .finally(() => spinner?.classList.add('d-none'));
        },

        stockBadge(qty) {
            qty = parseInt(qty) || 0;
            if (qty === 0) return `<span class="stock-pill bg-danger-subtle text-danger"><i class="fa-solid fa-circle-xmark fa-xs"></i> 0</span>`;
            if (qty < 5) return `<span class="stock-pill bg-danger-subtle text-danger"><i class="fa-solid fa-triangle-exclamation fa-xs"></i> ${fmtNum(qty)}</span>`;
            if (qty <= 20) return `<span class="stock-pill bg-warning-subtle text-warning"><i class="fa-solid fa-globe fa-xs"></i> ${fmtNum(qty)}</span>`;
            return `<span class="stock-pill bg-info-subtle text-info"><i class="fa-solid fa-globe fa-xs"></i> ${fmtNum(qty)}</span>`;
        },

        render(products) {
            const colspan = IS_ADMIN_OP ? 6 : 5;
            const tbody = document.getElementById('op-tbody');
            const mCards = document.getElementById('op-mobile-cards');

            if (!products.length) {
                const empty = `<div class="text-center py-5 text-muted">
                    <i class="fa-solid fa-box-open fa-3x mb-3" style="color:#ccc;"></i>
                    <div class="fw-bold">No products with online stock</div>
                    <div class="small mt-1">Try adjusting your filters</div>
                </div>`;
                tbody.innerHTML = `<tr><td colspan="${colspan}" class="p-0">${empty}</td></tr>`;
                mCards.innerHTML = empty;
                return;
            }

            tbody.innerHTML = products.map(p => {
                const phys = parseInt(p.physical_stock) || 0;
                const online = parseInt(p.online_stock) || 0;
                const img = p.image_path
                    ? `<img src="${BASE_URL}${escH(p.image_path)}" style="width:36px;height:36px;object-fit:cover;border-radius:6px;border:1px solid #eee;" alt="">`
                    : `<div style="width:36px;height:36px;border-radius:6px;background:#f3f4f6;display:flex;align-items:center;justify-content:center;"><i class="fa-solid fa-cube text-secondary fa-sm"></i></div>`;
                const physBadge = phys === 0
                    ? `<span class="stock-pill bg-danger-subtle text-danger"><i class="fa-solid fa-circle-xmark fa-xs"></i> 0</span>`
                    : `<span class="stock-pill bg-success-subtle text-success"><i class="fa-solid fa-store fa-xs"></i> ${fmtNum(phys)}</span>`;
                const srpCol = IS_ADMIN_OP
                    ? `<td class="small">&#8369;${fmtNum(parseFloat(p.price_retail || 0), 2)}</td>` : '';

                return `<tr class="row-animated">
                    <td class="ps-4">
                        <div class="d-flex align-items-center gap-2">
                            ${img}
                            <div>
                                <div class="fw-bold small" style="color:var(--text-primary);">${highlightQuery(p.product_name, this.query)}</div>
                                <div class="text-muted" style="font-size:0.75rem;">${highlightQuery(p.brand_name, this.query)} · ${highlightQuery(p.variation_name, this.query)}</div>
                                ${p.sku ? `<span class="badge border text-muted" style="font-size:0.68rem;background:var(--bg-surface);">${highlightQuery(p.sku, this.query)}</span>` : ''}
                            </div>
                        </div>
                    </td>
                    <td>${physBadge}</td>
                    <td>${this.stockBadge(online)}</td>
                    ${srpCol}
                    <td class="text-end pe-4">
                        <button class="btn btn-sm btn-outline-info"
                            onclick="openEditModal(${p.variation_id}, '${escH(p.product_name).replace(/'/g, "\\'")}', '${escH(p.brand_name)} · ${escH(p.variation_name)}', ${online})"
                            title="Edit Online Stock">
                            <i class="fa-solid fa-pencil"></i> Edit
                        </button>
                    </td>
                </tr>`;
            }).join('');

            // Mobile cards
            mCards.innerHTML = products.map(p => {
                const online = parseInt(p.online_stock) || 0;
                const phys = parseInt(p.physical_stock) || 0;
                const img = p.image_path
                    ? `<img src="${BASE_URL}${escH(p.image_path)}" style="width:44px;height:44px;object-fit:cover;border-radius:8px;" alt="">`
                    : `<div style="width:44px;height:44px;border-radius:8px;background:#f3f4f6;display:flex;align-items:center;justify-content:center;"><i class="fa-solid fa-cube text-secondary"></i></div>`;
                return `<div class="card border-0 shadow-sm mb-2 row-animated" style="border-radius:10px;">
                    <div class="card-body py-2 px-3">
                        <div class="d-flex align-items-center gap-2">
                            ${img}
                            <div class="flex-grow-1" style="min-width:0;">
                                <div class="fw-bold small text-truncate">${highlightQuery(p.product_name, this.query)}</div>
                                <div class="text-muted" style="font-size:0.73rem;">${highlightQuery(p.brand_name, this.query)} · ${highlightQuery(p.variation_name, this.query)}</div>
                            </div>
                            <div class="text-end">
                                ${this.stockBadge(online)}
                                <div class="text-muted" style="font-size:0.7rem;">phys: ${fmtNum(phys)}</div>
                            </div>
                        </div>
                        <div class="mt-2 text-end">
                            <button class="btn btn-sm btn-outline-info py-0"
                                onclick="openEditModal(${p.variation_id}, '${escH(p.product_name).replace(/'/g, "\\'")}', '${escH(p.brand_name)} · ${escH(p.variation_name)}', ${online})">
                                <i class="fa-solid fa-pencil fa-xs"></i> Edit Stock
                            </button>
                        </div>
                    </div>
                </div>`;
            }).join('');
        },

        updatePagination(pag, filteredCount) {
            const total = pag.total_items || 0;
            const current = pag.current_page || 1;
            this.totalPages = pag.total_pages || 1;
            this.page = current;

            document.getElementById('op-showing').textContent = this.stockLevel
                ? `Showing ${filteredCount} filtered results`
                : `Showing ${Math.min((current - 1) * 50 + 1, total)}–${Math.min(current * 50, total)} of ${total} products`;
            document.getElementById('op-total-pages').textContent = this.totalPages;
            document.getElementById('op-count').textContent = `(${this.stockLevel ? filteredCount : total} products)`;

            // Update badge on tab button
            const badge = document.getElementById('op-tab-count');
            if (badge) badge.textContent = total || '';

            const sel = document.getElementById('op-page-select');
            sel.innerHTML = '';
            for (let i = 1; i <= this.totalPages; i++) {
                const opt = document.createElement('option');
                opt.value = i; opt.textContent = i;
                if (i === current) opt.selected = true;
                sel.appendChild(opt);
            }
        }
    };

    // =====================================================================
    //  TAB 5: PLATFORM LINKS
    // =====================================================================
    const PlatformLinksTab = {
        loaded: false,
        page: 1,
        query: '',
        timer: null,

        load() {
            this.loaded = true;
            this.initSearch();
            this.fetchLinks();

            document.getElementById('links-search').addEventListener('input', e => {
                clearTimeout(this.timer);
                this.timer = setTimeout(() => { this.query = e.target.value.trim(); this.page = 1; this.fetchLinks(); }, 300);
            });

            document.getElementById('links-page-select').addEventListener('change', e => {
                this.page = parseInt(e.target.value); this.fetchLinks();
            });
        },

        initSearch() {
            const inp = document.getElementById('link-product-search');
            const dd = document.getElementById('link-search-dropdown');

            inp.addEventListener('input', e => {
                clearTimeout(this.searchTimer);
                const q = e.target.value.trim();
                if (q.length < 1) { dd.classList.add('d-none'); return; }
                this.searchTimer = setTimeout(() => this.searchProducts(q), 300);
            });

            document.addEventListener('click', e => {
                if (!e.target.closest('#link-product-search') && !e.target.closest('#link-search-dropdown')) {
                    dd.classList.add('d-none');
                }
            });
        },

        async searchProducts(q) {
            const dd = document.getElementById('link-search-dropdown');
            dd.innerHTML = '<div class="product-option text-muted small"><i class="fa-solid fa-spinner fa-spin me-1"></i>Searching...</div>';
            dd.classList.remove('d-none');

            try {
                const res = await fetch(`${BASE_URL}api/inventory/search_products.php?q=${encodeURIComponent(q)}&limit=10`);
                const data = await res.json();
                const products = data.products || [];

                if (!products.length) {
                    dd.innerHTML = '<div class="product-option text-muted small text-center">No products found</div>';
                    return;
                }

                dd.innerHTML = products.map(p => {
                    return `<div class="product-option" onclick="PlatformLinksTab.selectProduct(${p.variation_id}, '${escH(p.product_name).replace(/'/g, "\\'")}', '${escH(p.variation_name).replace(/'/g, "\\'")}', '${escH(p.sku || '').replace(/'/g, "\\'")}')">
                        <div class="fw-bold small">${highlightQuery(p.product_name, q)}</div>
                        <div class="text-muted" style="font-size:0.75rem;">${highlightQuery(p.brand_name, q)} · ${highlightQuery(p.variation_name, q)}</div>
                    </div>`;
                }).join('');
            } catch (e) {
                dd.innerHTML = '<div class="product-option text-danger small">Search failed</div>';
            }
        },

        selectProduct(varId, name, varName, sku) {
            document.getElementById('link-variation-id').value = varId;
            document.getElementById('link-product-search').value = '';
            document.getElementById('link-search-dropdown').classList.add('d-none');

            document.getElementById('link-selected-product').innerHTML = `
                <i class="fa-solid fa-check-circle text-success me-1"></i>
                Selected: ${name} (${varName})
                <a href="#" onclick="PlatformLinksTab.clearProduct(event)" class="text-danger ms-2" style="font-size:0.75rem; clear:both"><i class="fa-solid fa-times"></i> clear</a>
            `;
        },

        clearProduct(e) {
            if (e) e.preventDefault();
            document.getElementById('link-variation-id').value = '';
            document.getElementById('link-selected-product').innerHTML = '';
        },

        async fetchLinks() {
            const tbody = document.getElementById('links-tbody');
            tbody.innerHTML = '<tr><td colspan="5" class="text-center py-4 text-muted"><i class="fa-solid fa-spinner fa-spin me-2"></i>Loading...</td></tr>';

            try {
                const res = await fetch(`${BASE_URL}api/inventory/link_online_product.php?action=list&q=${encodeURIComponent(this.query)}&page=${this.page}&limit=20`);
                const data = await res.json();

                if (data.success) {
                    this.renderLinks(data.links);
                    this.updatePagination(data.pagination);
                } else {
                    tbody.innerHTML = `<tr><td colspan="5" class="text-center text-danger py-4">${data.message}</td></tr>`;
                }
            } catch (e) {
                tbody.innerHTML = '<tr><td colspan="5" class="text-center text-danger py-4">Failed to load platform links</td></tr>';
            }
        },

        renderLinks(links) {
            const tbody = document.getElementById('links-tbody');
            if (!links.length) {
                tbody.innerHTML = '<tr><td colspan="5" class="text-center py-5 text-muted">No platform links found</td></tr>';
                return;
            }

            tbody.innerHTML = links.map(l => {
                return `<tr>
                    <td>
                        <div class="fw-bold">${escH(l.product_name)}</div>
                        <div class="text-muted" style="font-size:0.75rem;">${escH(l.brand_name)} · ${escH(l.variation_name)}</div>
                        ${l.internal_sku ? `<span class="badge bg-light text-dark border">${escH(l.internal_sku)}</span>` : ''}
                    </td>
                    <td>${platformBadge(l.platform)}</td>
                    <td>
                        <div style="font-size:0.8rem;">
                            <strong>Var ID:</strong> <span class="text-primary">${escH(l.online_variation_id)}</span><br>
                            ${l.online_product_id ? `<strong>Prod ID:</strong> ${escH(l.online_product_id)}<br>` : ''}
                            ${l.platform_sku ? `<strong>SKU:</strong> ${escH(l.platform_sku)}` : ''}
                        </div>
                    </td>
                    <td class="text-muted small">${escH(l.linked_by_name)}<br>${new Date(l.linked_at).toLocaleDateString()}</td>
                    <td class="text-end">
                        <button class="btn btn-sm btn-outline-danger" onclick="PlatformLinksTab.unlink(${l.link_id})" title="Remove Link">
                            <i class="fa-solid fa-unlink"></i>
                        </button>
                    </td>
                </tr>`;
            }).join('');
        },

        updatePagination(pag) {
            const total = pag.total_items || 0;
            const current = pag.current_page || 1;
            const totalPages = pag.total_pages || 1;

            document.getElementById('links-showing').textContent = `Showing ${total} links`;

            const sel = document.getElementById('links-page-select');
            sel.innerHTML = '';
            for (let i = 1; i <= totalPages; i++) {
                const opt = document.createElement('option'); opt.value = i; opt.textContent = `Page ${i}`;
                if (i === current) opt.selected = true;
                sel.appendChild(opt);
            }
        },

        async unlink(linkId) {
            if (!confirm('Are you sure you want to remove this platform link? Stock transfers will no longer work for this item until linked again.')) return;

            try {
                const res = await fetch(`${BASE_URL}api/inventory/link_online_product.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'unlink', link_id: linkId })
                });
                const data = await res.json();
                if (data.success) {
                    showToast('Link removed successfully');
                    this.fetchLinks();
                } else {
                    showToast(data.message, 'danger');
                }
            } catch (e) {
                showToast('Network error', 'danger');
            }
        }
    };

    async function submitPlatformLink(e) {
        e.preventDefault();
        const varId = document.getElementById('link-variation-id').value;
        if (!varId) {
            showToast('Please select a local product variation first.', 'warning');
            return;
        }

        const payload = {
            action: 'link',
            variation_id: varId,
            platform: document.getElementById('link-platform').value,
            online_variation_id: document.getElementById('link-online-variation-id').value.trim(),
            online_product_id: document.getElementById('link-online-product-id').value.trim(),
            platform_sku: document.getElementById('link-platform-sku').value.trim()
        };

        const btn = document.getElementById('btn-save-link');
        btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin pt-1 pb-1"></i>';

        try {
            const res = await fetch(`${BASE_URL}api/inventory/link_online_product.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const data = await res.json();

            if (data.success) {
                showToast(data.message);
                document.getElementById('form-platform-link').reset();
                PlatformLinksTab.clearProduct();
                PlatformLinksTab.fetchLinks();
            } else {
                showToast(data.message, 'danger');
            }
        } catch (err) {
            showToast('Network error', 'danger');
        } finally {
            btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-save"></i> Save Link';
        }
    }


    // =====================================================================
    //  TAB 6: BULK TRANSFER
    // =====================================================================

    async function submitBulkRequest(payload, isFormData = false) {
        let options = { method: 'POST' };
        let url = `${BASE_URL}api/inventory/process_platform_transfer.php`;

        if (isFormData) {
            // For file uploads, backend checks $_GET['action']
            url += '?action=preview';
            options.body = payload;
        } else {
            options.headers = { 'Content-Type': 'application/json' };
            options.body = JSON.stringify(payload);
        }

        try {
            const res = await fetch(url, options);
            const data = await res.json();
            renderBulkResults(data);
        } catch (e) {
            renderBulkResults({ success: false, message: 'Network error. Could not process request.' });
        }
    }

    async function processBulkTransfer() {
        const text = document.getElementById('bulk-transfer-input').value.trim();
        if (!text) {
            showToast('Please paste or type transfer data first.', 'warning');
            return;
        }

        const btn = document.getElementById('btn-process-bulk');
        const origText = btn.innerHTML;
        btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Processing...';

        const lines = text.split('\n');
        const transfers = [];

        for (const line of lines) {
            if (!line.trim()) continue;
            // Support comma or tab separated
            const parts = line.includes('\t') ? line.split('\t') : line.split(',');
            if (parts.length >= 2) {
                transfers.push({
                    online_variation_id: parts[0].trim(),
                    quantity: parseInt(parts[1].trim(), 10)
                });
            }
        }

        if (transfers.length === 0) {
            showToast('Could not parse any valid data. Ensure format is ID, QTY.', 'warning');
            btn.disabled = false; btn.innerHTML = origText;
            return;
        }

        showBulkLoading('Previewing Transfers...');
        await submitBulkRequest({ action: 'preview', transfers: transfers });

        btn.disabled = false; btn.innerHTML = origText;
    }

    async function processBulkTransferFile(e) {
        e.preventDefault();
        const fileInput = document.getElementById('bulk-transfer-file');
        if (!fileInput.files.length) return;

        const btn = document.getElementById('btn-upload-bulk');
        const origText = btn.innerHTML;
        btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Uploading...';

        showBulkLoading('Analyzing File...');

        const fd = new FormData();
        fd.append('pt_file', fileInput.files[0]);

        await submitBulkRequest(fd, true);

        btn.disabled = false; btn.innerHTML = origText;
        fileInput.value = ''; // reset
    }

    function showBulkLoading(text = 'Processing Transfers...') {
        document.getElementById('bulk-result-stats').innerHTML = '';
        document.getElementById('bulk-results-container').innerHTML = `
            <div class="text-center py-5">
                <i class="fa-solid fa-spinner fa-spin fa-3x text-primary mb-3"></i>
                <div class="text-muted fw-bold">${text}</div>
                <div class="small text-muted">This may take a moment. Do not close the page.</div>
            </div>`;
    }

    let currentValidTransfers = [];

    async function confirmValidTransfers() {
        if (!currentValidTransfers.length) return;
        showBulkLoading('Executing Stock Transfers...');
        await submitBulkRequest({ action: 'commit', transfers: currentValidTransfers });
    }

    function renderBulkResults(data) {
        const container = document.getElementById('bulk-results-container');
        const stats = document.getElementById('bulk-result-stats');

        if (!data.success) {
            stats.innerHTML = '';
            container.innerHTML = `
                <div class="p-4 text-center">
                    <i class="fa-solid fa-triangle-exclamation fa-3x text-danger mb-3"></i>
                    <h5 class="text-danger fw-bold">Error</h5>
                    <p class="text-muted">${escH(data.message)}</p>
                </div>
            `;
            return;
        }

        const sum = data.summary;

        if (!data.results || data.results.length === 0) {
            stats.innerHTML = '';
            container.innerHTML = '<div class="p-3 text-muted">No valid rows found to process.</div>';
            return;
        }

        if (data.mode === 'preview') {
            currentValidTransfers = [];
            stats.innerHTML = `
                <span class="badge bg-primary me-1"><i class="fa-solid fa-eye"></i> Preview Mode</span>
                <span class="badge bg-success me-1"><i class="fa-solid fa-check"></i> ${sum.processed} Ready</span>
                ${sum.errors > 0 ? `<span class="badge bg-danger"><i class="fa-solid fa-times"></i> ${sum.errors} Skipped</span>` : ''}
            `;

            let html = '<div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0" style="font-size:0.85rem;">';
            html += '<thead class="table-light"><tr><th>Platform ID</th><th>Product</th><th>Qty</th><th>Status</th></tr></thead><tbody>';

            data.results.forEach(r => {
                if (r.status === 'success') {
                    currentValidTransfers.push({ online_variation_id: r.online_variation_id, quantity: r.quantity });
                    html += `<tr>
                        <td><code>${escH(r.online_variation_id)}</code></td>
                        <td><div class="fw-bold">${escH(r.product_name)}</div><div class="text-muted" style="font-size:0.7rem;">${escH(r.sku || '')}</div></td>
                        <td class="fw-bold text-success">${r.quantity}</td>
                        <td><span class="badge bg-success-subtle text-success"><i class="fa-solid fa-check-circle"></i> Ready</span></td>
                    </tr>`;
                } else {
                    const dispName = r.product_name ? `<div class="fw-bold text-muted">${escH(r.product_name)}</div><div class="text-muted" style="font-size:0.7rem;">${escH(r.sku || '')}</div>` : '<span class="text-muted">Unknown / Unlinked</span>';
                    html += `<tr class="bg-light">
                        <td><code>${escH(r.online_variation_id)}</code></td>
                        <td>${dispName}</td>
                        <td class="text-muted">${r.quantity}</td>
                        <td><span class="text-danger small"><i class="fa-solid fa-times-circle"></i> ${escH(r.message)}</span></td>
                    </tr>`;
                }
            });
            html += '</tbody></table></div>';

            if (currentValidTransfers.length > 0) {
                html += `
                <div class="p-3 bg-white border-top text-end sticky-bottom shadow-sm">
                    <button class="btn btn-success fw-bold px-4" onclick="confirmValidTransfers()">
                        <i class="fa-solid fa-check-double me-1"></i> Confirm & Transfer ${currentValidTransfers.length} Valid Items
                    </button>
                </div>
                `;
            } else {
                html += '<div class="p-3 text-center text-danger fw-bold bg-danger-subtle">No valid items to transfer. Please fix the errors above.</div>';
            }
            container.innerHTML = html;

        } else {
            // COMMIT MODE
            stats.innerHTML = `
                <span class="badge bg-success me-1"><i class="fa-solid fa-check-double"></i> ${sum.processed} Transferred</span>
                ${sum.errors > 0 ? `<span class="badge bg-danger"><i class="fa-solid fa-times"></i> ${sum.errors} Failed</span>` : ''}
            `;

            container.innerHTML = data.results.map(r => {
                if (r.status === 'success') {
                    return `
                    <div class="p-3 border-bottom border-start border-4 border-success bg-white">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <div class="fw-bold text-success"><i class="fa-solid fa-check-circle me-1"></i> Transferred ${r.moved} units</div>
                                <div class="small fw-bold mt-1">${escH(r.product_name)}</div>
                                <div class="text-muted" style="font-size: 0.75rem;">Platform ID: ${escH(r.online_variation_id)} | SKU: ${r.sku ? escH(r.sku) : 'N/A'}</div>
                            </div>
                            <div class="text-end">
                                <span class="badge bg-info-subtle text-info border border-info">New Online Stock: ${r.new_online_stock}</span>
                            </div>
                        </div>
                    </div>`;
                } else {
                    return `
                    <div class="p-3 border-bottom border-start border-4 border-danger bg-light">
                        <div class="fw-bold text-danger"><i class="fa-solid fa-times-circle me-1"></i> Failed: ID ${escH(r.online_variation_id)}</div>
                        <div class="text-muted mt-1 small">${escH(r.message)}</div>
                    </div>`;
                }
            }).join('');

            // Refresh existing tabs behind the scenes
            if (OverviewTab.loaded) OverviewTab.fetch();
            if (OnlineProductsTab.loaded) OnlineProductsTab.fetch();
            document.getElementById('bulk-transfer-input').value = '';
            document.getElementById('bulk-transfer-file').value = '';
        }
    }

    // =====================================================================
    //  TAB 7: SALES SYNC
    // =====================================================================
    let currentSyncRows = []; // Valid rows to commit

    async function previewSalesSync(e) {
        e.preventDefault();
        const fileInput = document.getElementById('sync-file');
        const platform = document.getElementById('sync-platform').value;

        if (!fileInput.files.length) {
            showToast('Please select a file first.', 'warning');
            return;
        }

        const btn = document.getElementById('btn-sync-preview');
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Analyzing...';

        showSyncLoading('Comparing platform stock with Ella POS...');

        const fd = new FormData();
        fd.append('sync_file', fileInput.files[0]);
        fd.append('platform', platform);

        try {
            const res = await fetch(`${BASE_URL}api/inventory/process_sales_sync.php?action=preview`, { method: 'POST', body: fd });
            const data = await res.json();
            renderSyncResults(data);
        } catch (err) {
            renderSyncResults({ success: false, message: 'Network error. Could not connect.' });
        } finally {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-eye me-1"></i> Preview Sales to Record';
        }
    }

    async function commitSalesSync() {
        if (!currentSyncRows.length) return;

        const platform = document.getElementById('sync-platform').value;
        showSyncLoading('Recording sales...');

        try {
            const res = await fetch(`${BASE_URL}api/inventory/process_sales_sync.php?action=commit`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'commit', platform: platform, rows: currentSyncRows })
            });
            const data = await res.json();
            renderSyncResults(data);
        } catch (err) {
            renderSyncResults({ success: false, message: 'Network error. Could not commit.' });
        }
    }

    function showSyncLoading(text = 'Processing...') {
        document.getElementById('sync-result-stats').innerHTML = '';
        document.getElementById('sync-results-container').innerHTML = `
            <div class="text-center py-5">
                <i class="fa-solid fa-spinner fa-spin fa-3x mb-3" style="color:#00d4ff;"></i>
                <div class="fw-bold text-muted">${text}</div>
                <div class="small text-muted mt-1">Please wait.</div>
            </div>`;
    }

    function renderSyncResults(data) {
        const container = document.getElementById('sync-results-container');
        const stats = document.getElementById('sync-result-stats');

        if (!data.success) {
            stats.innerHTML = '';
            container.innerHTML = `
                <div class="p-4 text-center">
                    <i class="fa-solid fa-triangle-exclamation fa-3x text-danger mb-3"></i>
                    <h5 class="fw-bold text-danger">Error</h5>
                    <p class="text-muted">${escH(data.message)}</p>
                </div>`;
            return;
        }

        const sum = data.summary;

        if (data.mode === 'preview') {
            currentSyncRows = [];

            const willSync = (data.results || []).filter(r => r.status === 'will_sync');
            const noChange = (data.results || []).filter(r => r.status === 'no_change');
            const skipped = (data.results || []).filter(r => r.status === 'skip' || r.status === 'error');

            stats.innerHTML = `
                <span class="badge me-1" style="background:#16213e;"><i class="fa-solid fa-eye"></i> Preview</span>
                ${willSync.length ? `<span class="badge bg-success me-1"><i class="fa-solid fa-cart-shopping"></i> ${willSync.length} Sales Detected</span>` : ''}
                ${noChange.length ? `<span class="badge bg-secondary me-1">${noChange.length} No Change</span>` : ''}
                ${skipped.length ? `<span class="badge bg-danger">${skipped.length} Skipped</span>` : ''}
            `;

            let html = '<div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0" style="font-size:0.83rem;">';
            html += `<thead class="table-light"><tr>
                <th>Platform ID</th><th>Product</th>
                <th class="text-center">Ella POS</th>
                <th class="text-center">Platform</th>
                <th class="text-center fw-bold">Sold</th>
                <th>Status</th>
            </tr></thead><tbody>`;

            (data.results || []).forEach(r => {
                if (r.status === 'will_sync') {
                    currentSyncRows.push({ online_variation_id: r.online_variation_id, platform_stock: r.platform_stock });
                    html += `<tr>
                        <td><code>${escH(r.online_variation_id)}</code></td>
                        <td><div class="fw-bold">${escH(r.product_name)}</div><div class="text-muted" style="font-size:0.7rem;">${escH(r.sku || '')}</div></td>
                        <td class="text-center">${r.ella_stock}</td>
                        <td class="text-center">${r.platform_stock}</td>
                        <td class="text-center fw-bold text-danger">-${r.sold}</td>
                        <td><span class="badge bg-warning-subtle text-warning border border-warning"><i class="fa-solid fa-cart-shopping"></i> Will Record</span></td>
                    </tr>`;
                } else if (r.status === 'no_change') {
                    const diff = (r.platform_stock || 0) - (r.ella_stock || 0);
                    html += `<tr class="table-light">
                        <td><code>${escH(r.online_variation_id)}</code></td>
                        <td><div class="fw-bold">${escH(r.product_name || '—')}</div><div class="text-muted" style="font-size:0.7rem;">${escH(r.sku || '')}</div></td>
                        <td class="text-center">${r.ella_stock ?? '—'}</td>
                        <td class="text-center">${r.platform_stock ?? '—'}</td>
                        <td class="text-center text-muted">0</td>
                        <td><span class="badge bg-secondary-subtle text-secondary">${diff > 0 ? '↑ Restock?' : '✓ Match'}</span></td>
                    </tr>`;
                } else {
                    html += `<tr class="table-light">
                        <td><code>${escH(r.online_variation_id)}</code></td>
                        <td colspan="4" class="text-muted small">${escH(r.message || 'Skipped')}</td>
                        <td><span class="badge bg-danger-subtle text-danger">Skipped</span></td>
                    </tr>`;
                }
            });

            html += '</tbody></table></div>';

            if (currentSyncRows.length > 0) {
                html += `
                <div class="p-3 bg-white border-top text-end shadow-sm sticky-bottom">
                    <div class="small text-muted mb-2">
                        <i class="fa-solid fa-circle-info text-primary"></i>
                        ${currentSyncRows.length} sale record(s) will be committed to the database and deducted from online stock.
                    </div>
                    <button class="btn fw-bold px-4 text-white" style="background:#16213e;" onclick="commitSalesSync()">
                        <i class="fa-solid fa-check-double me-1"></i> Confirm &amp; Record ${currentSyncRows.length} Sales
                    </button>
                </div>`;
            } else {
                html += `<div class="p-3 text-center text-success fw-bold bg-success-subtle">
                    <i class="fa-solid fa-check-circle me-2"></i> All stocks match! No sales to record.
                </div>`;
            }

            container.innerHTML = html;

        } else {
            // COMMIT result
            stats.innerHTML = `
                <span class="badge bg-success me-1"><i class="fa-solid fa-check-double"></i> ${sum.synced} Recorded</span>
                ${sum.skipped > 0 ? `<span class="badge bg-secondary">${sum.skipped} Skipped</span>` : ''}
            `;
            container.innerHTML = (data.results || []).map(r => {
                if (r.status === 'synced') {
                    return `
                    <div class="p-3 border-bottom border-start border-4 border-success bg-white">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <div class="fw-bold text-success"><i class="fa-solid fa-cart-shopping me-1"></i> ${r.sold} units sold recorded</div>
                                <div class="fw-bold small mt-1">${escH(r.product_name)}</div>
                                <div class="text-muted" style="font-size:0.73rem;">Platform ID: ${escH(r.online_variation_id)} | Ref: ${escH(r.reference || '—')}</div>
                            </div>
                            <div class="text-end small">
                                <div class="text-muted">${r.ella_stock} → <strong class="text-danger">${r.new_ella_stock}</strong></div>
                                <span class="badge bg-info-subtle text-info border border-info">Platform: ${r.platform_stock}</span>
                            </div>
                        </div>
                    </div>`;
                } else if (r.status === 'no_change') {
                    return '';
                } else {
                    return `
                    <div class="p-3 border-bottom border-start border-4 border-secondary bg-light">
                        <div class="text-muted small"><i class="fa-solid fa-minus-circle me-1"></i> Skipped: ${escH(r.online_variation_id)} — ${escH(r.message || '')}</div>
                    </div>`;
                }
            }).join('');

            // Refresh inventory display
            if (OverviewTab.loaded) OverviewTab.fetch();
            if (OnlineProductsTab.loaded) OnlineProductsTab.fetch();
            if (HistoryTab.loaded) HistoryTab.fetch();
            document.getElementById('sync-file').value = '';
            currentSyncRows = [];
        }
    }

    // =====================================================================
    //  INIT
    // =====================================================================
    document.addEventListener('DOMContentLoaded', function () {
        const initialTab = '<?= $activeTab ?>';
        if (initialTab === 'overview') OverviewTab.load();
        else if (initialTab === 'record_sale') SaleTab.load();
        else if (initialTab === 'history') HistoryTab.load();
        else if (initialTab === 'online_products') OnlineProductsTab.load();
        else if (initialTab === 'platform_links') PlatformLinksTab.load();

        // Always seed overview in background so tab switch is instant
        if (initialTab !== 'overview') OverviewTab.load();
    });
    async function forcePlatformSync() {
        if (!confirm('This will queue ALL linked products for a stock update on their respective platforms. Proceed?')) return;

        try {
            const res = await fetch(`${BASE_URL}api/system/force_platform_sync.php`);
            const data = await res.json();
            if (data.success) {
                showToast(data.message, 'success');
            } else {
                showToast(data.message, 'danger');
            }
        } catch (err) {
            showToast('Failed to trigger sync. Check connection.', 'danger');
        }
    }

    async function uploadBulkLinks(e) {
        e.preventDefault();
        const fileInput = document.getElementById('bulk-links-file');
        if (!fileInput.files.length) return;

        const btn = document.getElementById('btn-upload-bulk-links');
        const ogText = btn.innerHTML;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Processing...';
        btn.disabled = true;

        const formData = new FormData();
        formData.append('file', fileInput.files[0]);

        const resultsDiv = document.getElementById('bulk-links-results');
        resultsDiv.classList.add('d-none');
        resultsDiv.innerHTML = '';

        try {
            const res = await fetch(`${BASE_URL}api/inventory/process_bulk_platform_links.php`, {
                method: 'POST',
                body: formData
            });
            const data = await res.json();

            resultsDiv.classList.remove('d-none');

            if (data.success) {
                let html = `<div class="alert alert-success py-2 mb-2 small fw-bold"><i class="fa-solid fa-check-circle me-1"></i> ${data.message}</div>`;
                if (data.details.length > 0) {
                    html += `<ul class="list-group list-group-flush small" style="font-size: 0.8rem;">`;
                    data.details.forEach(i => {
                        if (i.status === 'failed') {
                            html += `<li class="list-group-item text-danger border-danger border-opacity-25 px-2 py-1"><i class="fa-solid fa-xmark me-1"></i> <strong>${i.sku}</strong>: ${i.reason}</li>`;
                        } else {
                            html += `<li class="list-group-item text-success border-success border-opacity-25 px-2 py-1"><i class="fa-solid fa-check me-1"></i> <strong>${i.sku}</strong>: ${i.reason}</li>`;
                        }
                    });
                    html += `</ul>`;
                }
                resultsDiv.innerHTML = html;
                PlatformLinksTab.load(); // Refresh table
                fileInput.value = ''; // Clear file
            } else {
                resultsDiv.innerHTML = `<div class="alert alert-danger py-2 small fw-bold"><i class="fa-solid fa-triangle-exclamation me-1"></i> ${data.message}</div>`;
            }
        } catch (err) {
            resultsDiv.classList.remove('d-none');
            resultsDiv.innerHTML = `<div class="alert alert-danger py-2 small fw-bold"><i class="fa-solid fa-triangle-exclamation me-1"></i> Upload failed. File may be too large or malformed.</div>`;
        } finally {
            btn.innerHTML = ogText;
            btn.disabled = false;
        }
    }
</script>


<?php require_once '../../includes/footer.php'; ?>
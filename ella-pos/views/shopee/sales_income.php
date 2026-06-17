<?php
// views/shopee/sales_income.php
$page_title = 'Shopee Store — Sales & Income';
require_once '../../config/config.php';
require_once '../../includes/auth.php';
requireLogin();
require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/shopee-sync.css?v=<?= filemtime(__DIR__.'/../../assets/css/shopee-sync.css') ?>">
<style>
/* Minor tab styling that complements the existing CSS */
.sp-sales-tabs {
    display: flex;
    gap: 2rem;
    border-bottom: 1px solid var(--border-color);
    margin-bottom: 1.5rem;
    overflow-x: auto;
}
.sp-sales-tab {
    padding: 0.85rem 0;
    font-weight: 600;
    font-size: 0.9rem;
    color: var(--text-secondary);
    cursor: pointer;
    border-bottom: 3px solid transparent;
    margin-bottom: -1px;
    white-space: nowrap;
    transition: all 0.2s ease;
}
.sp-sales-tab:hover {
    color: var(--shopee-primary);
}
.sp-sales-tab.active {
    color: var(--shopee-primary);
    border-bottom-color: var(--shopee-primary);
}
.sp-tab-content {
    display: none;
}
.sp-tab-content.active {
    display: block;
    animation: spFadeInUp 0.4s ease forwards;
}
@keyframes spFadeInUp {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
}

/* Premium KPI Cards */
.sp-kpi-widget {
    background: var(--bg-surface);
    border: 1px solid var(--border-color);
    border-radius: var(--sp-radius-xl);
    padding: 1.5rem;
    position: relative;
    overflow: hidden;
    box-shadow: 0 4px 15px rgba(0,0,0,0.03);
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    height: 100%;
    z-index: 1;
}
.sp-kpi-widget:hover {
    transform: translateY(-5px);
    box-shadow: 0 12px 25px rgba(238, 77, 45, 0.08);
    border-color: rgba(238, 77, 45, 0.3);
}
.sp-kpi-widget-bg-icon {
    position: absolute;
    right: -10%;
    bottom: -15%;
    font-size: 5rem;
    opacity: 0.04;
    z-index: -1;
    transform: rotate(-10deg);
    transition: all 0.4s ease;
}
.sp-kpi-widget:hover .sp-kpi-widget-bg-icon {
    transform: rotate(0deg) scale(1.1);
    opacity: 0.08;
    color: var(--shopee-primary);
}
.sp-kpi-label {
    font-size: 0.75rem;
    font-weight: 700;
    text-transform: uppercase;
    color: var(--text-secondary);
    letter-spacing: 0.05em;
    margin-bottom: 0.2rem;
    display: flex;
    align-items: center;
    gap: 0.4rem;
}
.sp-kpi-value {
    font-size: 1.5rem;
    font-weight: 800;
    color: var(--text-primary);
    font-family: 'SFMono-Regular', Consolas, monospace;
    letter-spacing: -0.5px;
    line-height: 1.2;
}

/* Custom Minimal Accent Cards */
.sp-kpi-widget.accent-shopee { border-bottom: 3px solid var(--shopee-primary); }
.sp-kpi-widget.accent-info { border-bottom: 3px solid #3b82f6; }
.sp-kpi-widget.accent-success { border-bottom: 3px solid #10b981; }
.sp-kpi-widget.accent-warning { border-bottom: 3px solid #f59e0b; }

.sp-kpi-icon-bubble {
    width: 54px;
    height: 54px;
    border-radius: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    flex-shrink: 0;
    transition: all 0.3s ease;
}
.sp-kpi-widget:hover .sp-kpi-icon-bubble {
    transform: scale(1.1);
}
.bubble-shopee { background: rgba(238, 77, 45, 0.12); color: var(--shopee-primary); }
.bubble-info { background: rgba(59, 130, 246, 0.12); color: #3b82f6; }
.bubble-success { background: rgba(16, 185, 129, 0.12); color: #10b981; }
.bubble-warning { background: rgba(245, 158, 11, 0.12); color: #f59e0b; }

/* Adjust background icon colors on hover based on accent */
.sp-kpi-widget.accent-shopee:hover .sp-kpi-widget-bg-icon { color: var(--shopee-primary); opacity: 0.06; }
.sp-kpi-widget.accent-info:hover .sp-kpi-widget-bg-icon { color: #3b82f6; opacity: 0.06; }
.sp-kpi-widget.accent-success:hover .sp-kpi-widget-bg-icon { color: #10b981; opacity: 0.06; }
.sp-kpi-widget.accent-warning:hover .sp-kpi-widget-bg-icon { color: #f59e0b; opacity: 0.06; }

.sp-kpi-widget.grad-warning {
    background: var(--sp-grad-warning);
    border: none;
    color: #fff;
}
.sp-kpi-widget.grad-warning .sp-kpi-label,
.sp-kpi-widget.grad-warning .sp-kpi-value {
    color: #fff;
}
.sp-kpi-widget.grad-warning .sp-kpi-widget-bg-icon {
    color: #fff;
    opacity: 0.2;
}

/* Toolbar styling */
.sp-filter-bar {
    background: #ffffff;
    border: 1px solid var(--border-color);
    border-radius: var(--sp-radius-lg);
    box-shadow: 0 8px 30px rgba(0,0,0,0.03);
    padding: 1.5rem;
    position: relative;
    z-index: 10;
}
.sp-filter-label {
    font-size: 0.75rem;
    font-weight: 700;
    text-transform: uppercase;
    color: var(--text-secondary);
    margin-bottom: 0.5rem;
    display: block;
    letter-spacing: 0.05em;
}
.sp-filter-input {
    background-color: #f8f9fa;
    border: 1px solid var(--border-color);
    border-radius: 10px;
    height: 45px;
    font-size: 0.95rem;
    color: var(--text-primary);
    font-weight: 500;
    box-shadow: none !important;
    transition: all 0.2s ease;
}
.sp-filter-input:focus {
    background-color: #ffffff;
    border-color: var(--shopee-primary);
    box-shadow: 0 0 0 4px rgba(238, 77, 45, 0.1) !important;
}
.sp-filter-btn {
    height: 45px;
    border-radius: 10px;
    font-weight: 600;
    font-size: 0.95rem;
    transition: all 0.3s ease;
}
.sp-filter-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 15px rgba(238, 77, 45, 0.2);
}
.sp-search-wrapper {
    position: relative;
}
.sp-search-wrapper i {
    position: absolute;
    left: 15px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--text-secondary);
    font-size: 1.1rem;
}
.sp-search-wrapper .sp-filter-input {
    padding-left: 42px;
}
.sp-pagination-wrapper {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 1rem 1.5rem;
    font-size: 0.85rem;
}

/* Premium Pill Tabs */
.sp-pill-tabs {
    display: flex;
    gap: 0.5rem;
    background: var(--bg-surface);
    padding: 0.5rem;
    border-radius: 12px;
    border: 1px solid var(--border-color);
    margin-bottom: 1.5rem;
    overflow-x: auto;
}
.sp-pill-tab {
    padding: 0.6rem 1.2rem;
    border-radius: 8px;
    font-weight: 600;
    font-size: 0.9rem;
    color: var(--text-secondary);
    text-decoration: none;
    transition: all 0.2s ease;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    border: 1px solid transparent;
    white-space: nowrap;
}
.sp-pill-tab:hover {
    background: var(--sp-neutral-bg);
    color: var(--text-primary);
}
.sp-pill-tab.active {
    background: var(--sp-grad-primary);
    color: #fff;
    box-shadow: 0 4px 10px rgba(238, 77, 45, 0.2);
}
.sp-pill-tab i {
    font-size: 1.1em;
}
</style>

<div class="sp-page sp-animate">
    <?php require_once __DIR__ . '/shopee_token_warning.php'; ?>
    
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1 fw-bold text-dark">Shopee Sales & Income</h4>
            <p class="text-secondary mb-0">Monitor your orders, fees, and true net profit</p>
        </div>
        <div class="d-flex gap-2">
            <button class="btn btn-outline-shopee" onclick="syncOrders()">
                <i class="fa-solid fa-rotate me-1"></i> Sync Orders
            </button>
            <button class="btn btn-shopee" onclick="syncFinances()">
                <i class="fa-solid fa-file-invoice-dollar me-1"></i> Sync Finances
            </button>
        </div>
    </div>

    <!-- KPI Cards -->
    <div class="row g-3 mb-4">
        <!-- Today Sales -->
        <div class="col-6 col-md-4 col-lg-2">
            <div class="sp-kpi-widget accent-shopee d-flex align-items-center">
                <div class="sp-kpi-icon-bubble bubble-shopee me-3"><i class="fa-solid fa-bolt"></i></div>
                <div>
                    <div class="sp-kpi-label">Today's Sales</div>
                    <div class="sp-kpi-value" id="kpiTodaySales">₱0.00</div>
                </div>
                <i class="fa-solid fa-arrow-trend-up sp-kpi-widget-bg-icon"></i>
            </div>
        </div>
        <!-- Today Orders -->
        <div class="col-6 col-md-4 col-lg-2">
            <div class="sp-kpi-widget accent-info d-flex align-items-center">
                <div class="sp-kpi-icon-bubble bubble-info me-3"><i class="fa-solid fa-box"></i></div>
                <div>
                    <div class="sp-kpi-label">Today's Orders</div>
                    <div class="sp-kpi-value" id="kpiTodayOrders">0</div>
                </div>
                <i class="fa-solid fa-boxes-stacked sp-kpi-widget-bg-icon"></i>
            </div>
        </div>
        <!-- Today True Net -->
        <div class="col-6 col-md-4 col-lg-2">
            <div class="sp-kpi-widget accent-success d-flex align-items-center">
                <div class="sp-kpi-icon-bubble bubble-success me-3"><i class="fa-solid fa-money-bill-wave"></i></div>
                <div>
                    <div class="sp-kpi-label">Today's True Net</div>
                    <div class="sp-kpi-value" id="kpiTodayNet">₱0.00</div>
                </div>
                <i class="fa-solid fa-sack-dollar sp-kpi-widget-bg-icon"></i>
            </div>
        </div>
        <!-- Month Sales -->
        <div class="col-6 col-md-4 col-lg-2">
            <div class="sp-kpi-widget accent-shopee d-flex align-items-center">
                <div class="sp-kpi-icon-bubble bubble-shopee me-3"><i class="fa-regular fa-calendar-check"></i></div>
                <div>
                    <div class="sp-kpi-label">Month's Sales</div>
                    <div class="sp-kpi-value" id="kpiMonthSales">₱0.00</div>
                </div>
                <i class="fa-solid fa-calendar-days sp-kpi-widget-bg-icon"></i>
            </div>
        </div>
        <!-- Month True Net -->
        <div class="col-6 col-md-4 col-lg-2">
            <div class="sp-kpi-widget accent-success d-flex align-items-center">
                <div class="sp-kpi-icon-bubble bubble-success me-3"><i class="fa-solid fa-piggy-bank"></i></div>
                <div>
                    <div class="sp-kpi-label">Month's True Net</div>
                    <div class="sp-kpi-value" id="kpiMonthNet">₱0.00</div>
                </div>
                <i class="fa-solid fa-piggy-bank sp-kpi-widget-bg-icon"></i>
            </div>
        </div>
        <!-- Pending to Ship -->
        <div class="col-6 col-md-4 col-lg-2">
            <div class="sp-kpi-widget accent-warning d-flex align-items-center">
                <div class="sp-kpi-icon-bubble bubble-warning me-3"><i class="fa-solid fa-clock-rotate-left"></i></div>
                <div>
                    <div class="sp-kpi-label">Pending to Ship</div>
                    <div class="sp-kpi-value" id="kpiPending">0</div>
                </div>
                <i class="fa-solid fa-truck-fast sp-kpi-widget-bg-icon"></i>
            </div>
        </div>
    </div>

    <!-- Toolbar Filters inside a Shopee Card -->
    <div class="sp-filter-bar mb-4">
        <div class="row g-3 align-items-end">
            <div class="col-sm-6 col-md-3 col-lg-2">
                <label class="sp-filter-label"><i class="fa-solid fa-calendar-day me-1 text-shopee"></i> Date Range</label>
                <select id="filterDateRange" class="form-select sp-filter-input" onchange="applyFilters()">
                    <option value="today">Today</option>
                    <option value="this_week">This Week</option>
                    <option value="this_month" selected>This Month</option>
                    <option value="custom">Custom Range...</option>
                    <option value="all_time">All Time</option>
                </select>
            </div>
            
            <div class="col-sm-12 col-md-4 col-lg-3" id="customDateContainer" style="display: none;">
                <div class="d-flex gap-2">
                    <div class="flex-fill">
                        <label class="sp-filter-label text-muted">From</label>
                        <input type="date" id="customFrom" class="form-control sp-filter-input">
                    </div>
                    <div class="flex-fill">
                        <label class="sp-filter-label text-muted">To</label>
                        <input type="date" id="customTo" class="form-control sp-filter-input">
                    </div>
                </div>
            </div>

            <div class="col-sm-6 col-md-3 col-lg-2">
                <label class="sp-filter-label"><i class="fa-solid fa-filter me-1 text-shopee"></i> Order Status</label>
                <select id="filterStatus" class="form-select sp-filter-input" onchange="applyFilters()">
                    <option value="all">All Statuses</option>
                    <option value="COMPLETED">Completed</option>
                    <option value="READY_TO_SHIP">To Ship</option>
                    <option value="CANCELLED">Cancelled</option>
                    <option value="RETURN_REFUND">Returned</option>
                </select>
            </div>

            <div class="col-sm-12 col-md-4 col-lg-3 flex-grow-1">
                <label class="sp-filter-label"><i class="fa-solid fa-magnifying-glass me-1 text-shopee"></i> Search Orders</label>
                <div class="sp-search-wrapper">
                    <i class="fa-solid fa-search"></i>
                    <input type="text" id="filterSearch" class="form-control sp-filter-input" placeholder="Order ID, SKU, or Product Name..." onkeyup="if(event.key==='Enter') applyFilters()">
                </div>
            </div>

            <div class="col-sm-12 col-md-auto">
                <button class="btn btn-shopee w-100 sp-filter-btn px-4" onclick="applyFilters()">
                    Search Data <i class="fa-solid fa-arrow-right ms-2"></i>
                </button>
            </div>
        </div>
    </div>

    <!-- Premium Tabs -->
    <div class="sp-pill-tabs">
        <a class="sp-pill-tab active" data-tab="tab-orders" href="#" onclick="switchTab(event, 'tab-orders')">
            <i class="fa-solid fa-list-ul"></i> Order List
        </a>
        <a class="sp-pill-tab" data-tab="tab-profit" href="#" onclick="switchTab(event, 'tab-profit')">
            <i class="fa-solid fa-chart-pie"></i> True Net Profit Analysis
        </a>
        <a class="sp-pill-tab" data-tab="tab-top" href="#" onclick="switchTab(event, 'tab-top')">
            <i class="fa-solid fa-crown"></i> Top Selling Products
        </a>
        <a class="sp-pill-tab" data-tab="tab-recon" href="#" onclick="switchTab(event, 'tab-recon')">
            <i class="fa-solid fa-scale-balanced"></i> Weekly Reconciliation
        </a>
    </div>

    <!-- Tab: Orders -->
    <div id="tab-orders" class="sp-tab-content active">
        <div class="sp-card">
            <div class="sp-table-wrap">
                <table class="sp-table" id="ordersTable">
                    <thead>
                        <tr>
                            <th>Order SN</th>
                            <th>Create Time</th>
                            <th>Buyer</th>
                            <th>Status</th>
                            <th class="text-end">Total Amount</th>
                            <th class="text-end">Payout Amount</th>
                            <th class="text-center">Fin. Status</th>
                        </tr>
                    </thead>
                    <tbody id="ordersTbody">
                        <tr><td colspan="7" class="text-center py-5 text-muted">Loading orders...</td></tr>
                    </tbody>
                </table>
            </div>
            <div class="sp-card-footer sp-pagination-wrapper" id="ordersPagination"></div>
        </div>
    </div>

    <!-- Tab: Profit -->
    <div id="tab-profit" class="sp-tab-content">
        <div class="sp-card mb-4" style="border: 1px solid var(--sp-info); background: var(--sp-info-bg);">
            <div class="sp-card-body d-flex align-items-center gap-3 py-3 px-4">
                <div style="background: white; width: 40px; height: 40px; border-radius: 50%; display: flex; align-items: center; justify-content: center; box-shadow: 0 2px 5px rgba(0,0,0,0.1);">
                    <i class="fa-solid fa-lightbulb" style="color: var(--sp-info); font-size: 1.2rem;"></i>
                </div>
                <div>
                    <strong style="color: var(--sp-info); font-size: 0.95rem;">How True Net Profit Works</strong><br>
                    <span class="text-secondary small">This calculation takes the final Payout Amount from Shopee (Gross Sales minus all Shopee Fees) and subtracts your ERP's Capital Cost for the items sold.</span>
                </div>
            </div>
        </div>
        <div class="sp-card">
            <div class="sp-table-wrap">
                <table class="sp-table" id="profitTable">
                    <thead>
                        <tr>
                            <th>Order SN</th>
                            <th>Items Sold</th>
                            <th class="text-end">Shopee Payout (Net)</th>
                            <th class="text-end text-danger">ERP Capital Cost</th>
                            <th class="text-end text-success">True Net Profit</th>
                            <th class="text-end">Margin %</th>
                        </tr>
                    </thead>
                    <tbody id="profitTbody">
                        <tr><td colspan="6" class="text-center py-5 text-muted">Loading profit data...</td></tr>
                    </tbody>
                </table>
            </div>
            <div class="sp-card-footer sp-pagination-wrapper" id="profitPagination"></div>
        </div>
    </div>

    <!-- Tab: Top Selling -->
    <div id="tab-top" class="sp-tab-content">
        <div class="sp-card">
            <div class="sp-table-wrap">
                <table class="sp-table" id="topTable">
                    <thead>
                        <tr>
                            <th>SKU</th>
                            <th>Product Name</th>
                            <th class="text-center">Units Sold</th>
                            <th class="text-end">Total Revenue</th>
                            <th class="text-end text-success">Est. Gross Profit</th>
                        </tr>
                    </thead>
                    <tbody id="topTbody">
                        <tr><td colspan="5" class="text-center py-5 text-muted">Loading top products...</td></tr>
                    </tbody>
                </table>
            </div>
            <div class="sp-card-footer sp-pagination-wrapper" id="topPagination"></div>
        </div>
    </div>

    <!-- Tab: Reconciliation -->
    <div id="tab-recon" class="sp-tab-content">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <h5 class="mb-1 fw-bold" style="font-size: 1.1rem;"><i class="fa-solid fa-clipboard-check text-shopee me-2"></i>Weekly Audit & Reconciliation</h5>
                <p class="text-secondary small mb-0">Complete breakdown of all orders, cancellations, returns, and missing payouts in the selected timeframe.</p>
            </div>
            <button class="btn btn-outline-shopee" style="color: var(--sp-success); border-color: var(--sp-success);" onclick="exportReconciliation()">
                <i class="fa-solid fa-file-excel me-1"></i> Export to Excel
            </button>
        </div>
        
        <!-- Summary Stats for Audit -->
        <div class="d-flex gap-3 mb-3" id="reconSummaryStats" style="font-size: 0.85rem; font-weight: 600;">
            <!-- Injected via JS -->
        </div>

        <div class="sp-card">
            <div class="sp-table-wrap">
                <table class="sp-table" id="reconTable">
                    <thead>
                        <tr>
                            <th>Order SN</th>
                            <th>Order Date</th>
                            <th>Physical Status</th>
                            <th>Financial Status</th>
                            <th class="text-end">Expected Payout</th>
                            <th>Alert</th>
                        </tr>
                    </thead>
                    <tbody id="reconTbody">
                        <tr><td colspan="6" class="text-center py-5 text-muted">Loading reconciliation...</td></tr>
                    </tbody>
                </table>
            </div>
            <div class="sp-card-footer sp-pagination-wrapper" id="reconPagination"></div>
        </div>
    </div>

</div>

<!-- Fee Breakdown Modal -->
<div class="modal fade" id="feeBreakdownModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius: 16px; border: none; box-shadow: 0 10px 40px rgba(0,0,0,0.1);">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold">Fee Breakdown</h5>
                <button type="button" class="btn-close shadow-none" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="d-flex align-items-center gap-2 mb-4">
                    <span class="sp-badge sp-badge-neutral" id="feeModalOrderSn">...</span>
                    <span class="text-success fw-bold ms-auto" id="feeModalStatus"><i class="fa-solid fa-check"></i> Released</span>
                </div>
                
                <!-- Breakdown List -->
                <div class="d-flex flex-column gap-2" style="font-size: 0.9rem;">
                    <div class="d-flex justify-content-between text-secondary">
                        <span>Original Product Price</span>
                        <span id="feeOriginalPrice">₱0.00</span>
                    </div>
                    <div class="d-flex justify-content-between text-secondary">
                        <span>Buyer Shipping Fee</span>
                        <span id="feeBuyerShipping">₱0.00</span>
                    </div>
                    <div class="d-flex justify-content-between fw-bold pt-2 border-top">
                        <span>Buyer Paid Amount</span>
                        <span id="feeBuyerTotal">₱0.00</span>
                    </div>

                    <div class="mt-3 mb-1 text-danger fw-bold" style="font-size: 0.75rem; text-transform: uppercase;">Shopee Deductions</div>
                    <div class="d-flex justify-content-between text-secondary">
                        <span>Commission Fee</span>
                        <span class="text-danger" id="feeCommission">-₱0.00</span>
                    </div>
                    <div class="d-flex justify-content-between text-secondary">
                        <span>Transaction Fee</span>
                        <span class="text-danger" id="feeTransaction">-₱0.00</span>
                    </div>
                    <div class="d-flex justify-content-between text-secondary">
                        <span>Service Fee</span>
                        <span class="text-danger" id="feeService">-₱0.00</span>
                    </div>
                    <div class="d-flex justify-content-between text-secondary">
                        <span>Actual Shipping Fee (Seller)</span>
                        <span class="text-danger" id="feeSellerShipping">-₱0.00</span>
                    </div>
                    <div class="d-flex justify-content-between text-secondary">
                        <span>Escrow Tax</span>
                        <span class="text-danger" id="feeTax">-₱0.00</span>
                    </div>

                    <div class="mt-3 mb-1 text-success fw-bold" style="font-size: 0.75rem; text-transform: uppercase;">Rebates & Vouchers</div>
                    <div class="d-flex justify-content-between text-secondary">
                        <span>Seller Voucher</span>
                        <span class="text-success" id="feeSellerVoucher">+₱0.00</span>
                    </div>
                    <div class="d-flex justify-content-between text-secondary">
                        <span>Shopee Voucher</span>
                        <span class="text-success" id="feeShopeeVoucher">+₱0.00</span>
                    </div>

                    <div class="d-flex justify-content-between fw-bold pt-3 mt-2 border-top fs-5" style="color: var(--shopee-primary);">
                        <span>Final Payout</span>
                        <span id="feeFinalPayout">₱0.00</span>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-0 pt-0">
                <button type="button" class="btn btn-shopee w-100" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Enhanced Loading overlay -->
<div id="loadingOverlay" style="display:none; position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(255,255,255,0.85); z-index:9999; align-items:center; justify-content:center; flex-direction:column; backdrop-filter: blur(3px);">
    <div style="background: white; padding: 2.5rem; border-radius: 16px; box-shadow: 0 15px 35px rgba(0,0,0,0.1); text-align: center; max-width: 400px; width: 90%;">
        <div class="spinner-border text-shopee mb-3" role="status" style="width: 3rem; height: 3rem;">
            <span class="visually-hidden">Loading...</span>
        </div>
        <h4 class="fw-bold mb-2 text-dark" id="loadingTitle">Syncing Orders</h4>
        <p class="text-secondary small mb-0" id="loadingDesc">Fetching the latest orders and financial data from Shopee.<br><strong>Please do not close this page.</strong> This may take up to 60 seconds depending on your order volume.</p>
    </div>
</div>

<script>
// Format currency
const formatCurrency = (val) => {
    return '₱' + parseFloat(val || 0).toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2});
};

function switchTab(event, tabId) {
    event.preventDefault();
    document.querySelectorAll('.sp-pill-tab').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.sp-tab-content').forEach(c => c.classList.remove('active'));
    event.currentTarget.classList.add('active');
    document.getElementById(tabId).classList.add('active');
}

async function loadKPIs() {
    try {
        const res = await fetch(`${window.BASE_URL}api/shopee/get_sales_stats.php`);
        const data = await res.json();
        if(data.success) {
            document.getElementById('kpiTodaySales').textContent = formatCurrency(data.today.gross);
            document.getElementById('kpiTodayOrders').textContent = data.today.orders;
            document.getElementById('kpiTodayProfit').textContent = formatCurrency(data.today.net_profit);
            
            document.getElementById('kpiMonthSales').textContent = formatCurrency(data.month.gross);
            document.getElementById('kpiMonthProfit').textContent = formatCurrency(data.month.net_profit);
            
            document.getElementById('kpiPending').textContent = data.statuses.pending || 0;
        }
    } catch(e) {
        console.error("Failed to load KPIs", e);
    }
}

// Global Pagination State
let allOrders = [];
let allProfit = [];
let allTop = [];
let allRecon = [];
let itemsPerPage = 25;
let currentPage = { orders: 1, profit: 1, top: 1, recon: 1 };

async function applyFilters() {
    const range = document.getElementById('filterDateRange').value;
    const status = document.getElementById('filterStatus').value;
    const search = document.getElementById('filterSearch').value;
    
    // Toggle custom date inputs
    const customContainer = document.getElementById('customDateContainer');
    if (range === 'custom') {
        customContainer.style.display = 'block';
    } else {
        customContainer.style.display = 'none';
    }

    const customFrom = document.getElementById('customFrom').value;
    const customTo = document.getElementById('customTo').value;
    
    // Require custom dates if selected
    if (range === 'custom' && (!customFrom || !customTo)) {
        return; // Don't fetch yet if dates are incomplete
    }
    
    document.getElementById('ordersTbody').innerHTML = `<tr><td colspan="7" class="text-center py-5"><div class="spinner-border spinner-border-sm text-secondary"></div></td></tr>`;
    document.getElementById('profitTbody').innerHTML = `<tr><td colspan="6" class="text-center py-5"><div class="spinner-border spinner-border-sm text-secondary"></div></td></tr>`;
    document.getElementById('reconTbody').innerHTML = `<tr><td colspan="6" class="text-center py-5"><div class="spinner-border spinner-border-sm text-secondary"></div></td></tr>`;
    document.getElementById('topTbody').innerHTML = `<tr><td colspan="5" class="text-center py-5"><div class="spinner-border spinner-border-sm text-secondary"></div></td></tr>`;
    
    try {
        const queryParams = {range, status, search};
        if (range === 'custom') {
            queryParams.date_from = customFrom;
            queryParams.date_to = customTo;
        }
        
        const query = new URLSearchParams(queryParams);
        const [resOrders, resTop] = await Promise.all([
            fetch(`${window.BASE_URL}api/shopee/get_orders_list.php?${query.toString()}`),
            fetch(`${window.BASE_URL}api/shopee/get_top_products.php?${query.toString()}`)
        ]);
        
        const data = await resOrders.json();
        const topData = await resTop.json();
        
        if(!data.success) throw new Error(data.error);
        
        // Reset states
        currentPage = { orders: 1, profit: 1, top: 1, recon: 1 };
        allOrders = data.orders;
        allProfit = data.orders.filter(o => o.order_status === 'COMPLETED' && o.payout_amount !== null);
        allRecon = data.orders;
        allTop = topData.success ? topData.products : [];

        // Build summary
        let stats = { completed: 0, cancelled: 0, returned: 0, missing_payout: 0 };
        allOrders.forEach(o => {
            if (o.order_status === 'COMPLETED') stats.completed++;
            if (o.order_status === 'CANCELLED') stats.cancelled++;
            if (o.order_status === 'RETURN_REFUND') stats.returned++;
            if ((o.order_status === 'COMPLETED' || o.order_status === 'TO_CONFIRM_RECEIVE') && o.financial_status !== 'RELEASED') {
                stats.missing_payout++;
            }
        });

        document.getElementById('reconSummaryStats').innerHTML = `
            <span class="px-3 py-2 rounded" style="background: var(--sp-neutral-bg); border: 1px solid var(--border-color);"><i class="fa-solid fa-box text-secondary me-1"></i> Total Orders: ${allOrders.length}</span>
            <span class="px-3 py-2 rounded" style="background: var(--sp-success-bg); color: var(--sp-success); border: 1px solid rgba(16, 185, 129, 0.3);"><i class="fa-solid fa-check-circle me-1"></i> Completed: ${stats.completed}</span>
            <span class="px-3 py-2 rounded" style="background: var(--sp-danger-bg); color: var(--sp-danger); border: 1px solid rgba(239, 68, 68, 0.3);"><i class="fa-solid fa-triangle-exclamation me-1"></i> Missing Payouts: ${stats.missing_payout}</span>
            <span class="px-3 py-2 rounded" style="background: var(--sp-warning-bg); color: var(--sp-warning); border: 1px solid rgba(245, 158, 11, 0.3);"><i class="fa-solid fa-rotate-left me-1"></i> Returned: ${stats.returned}</span>
            <span class="px-3 py-2 rounded" style="background: var(--bg-surface); color: var(--text-secondary); border: 1px solid var(--border-color);"><i class="fa-solid fa-xmark me-1"></i> Cancelled/RTS: ${stats.cancelled}</span>
        `;
        
        renderTop();
        renderOrders();
        renderProfit();
        renderRecon();

    } catch(e) {
        console.error(e);
        document.getElementById('ordersTbody').innerHTML = `<tr><td colspan="7" class="text-center py-5 text-danger">Error loading data.</td></tr>`;
    }
}

function buildPagination(type, totalItems) {
    const totalPages = Math.ceil(totalItems / itemsPerPage) || 1;
    let html = `
        <div class="d-flex align-items-center">
            <span class="text-secondary me-2">Rows per page:</span>
            <select class="form-select form-select-sm" style="width: auto;" onchange="changeItemsPerPage(this.value)">
                <option value="10" ${itemsPerPage==10?'selected':''}>10</option>
                <option value="25" ${itemsPerPage==25?'selected':''}>25</option>
                <option value="50" ${itemsPerPage==50?'selected':''}>50</option>
                <option value="100" ${itemsPerPage==100?'selected':''}>100</option>
            </select>
        </div>
        <div>
            <span class="text-secondary me-3">Page ${currentPage[type]} of ${totalPages}</span>
            <button class="btn btn-sm btn-outline-shopee-secondary px-3" ${currentPage[type] === 1 ? 'disabled' : ''} onclick="changePage('${type}', -1)"><i class="fa-solid fa-chevron-left"></i></button>
            <button class="btn btn-sm btn-outline-shopee-secondary px-3 ms-1" ${currentPage[type] === totalPages ? 'disabled' : ''} onclick="changePage('${type}', 1)"><i class="fa-solid fa-chevron-right"></i></button>
        </div>
    `;
    document.getElementById(type + 'Pagination').innerHTML = html;
}

function changeItemsPerPage(val) {
    itemsPerPage = parseInt(val);
    currentPage = { orders: 1, profit: 1, top: 1, recon: 1 };
    renderTop();
    renderOrders();
    renderProfit();
    renderRecon();
}

function changePage(type, dir) {
    currentPage[type] += dir;
    if(type === 'orders') renderOrders();
    if(type === 'profit') renderProfit();
    if(type === 'top') renderTop();
    if(type === 'recon') renderRecon();
}

function renderOrders() {
    if(allOrders.length === 0) {
        document.getElementById('ordersTbody').innerHTML = `<tr><td colspan="7" class="text-center py-5 text-muted">No orders found.</td></tr>`;
        buildPagination('orders', 0);
        return;
    }
    let html = '';
    const start = (currentPage.orders - 1) * itemsPerPage;
    const end = start + itemsPerPage;
    const slice = allOrders.slice(start, end);
    
    slice.forEach(o => {
        let finBadge = o.financial_status === 'RELEASED' ? '<span class="sp-badge sp-badge-success">Released</span>' : '<span class="sp-badge sp-badge-warning">Pending</span>';
        if(o.financial_status === 'REFUNDED') finBadge = '<span class="sp-badge sp-badge-danger">Refunded</span>';
        
        let payoutHtml = '-';
        if (o.payout_amount) {
            payoutHtml = `<a href="javascript:void(0)" onclick="viewFeeBreakdown('${o.order_sn}')" class="text-success text-decoration-underline" title="View Fee Breakdown">${formatCurrency(o.payout_amount)}</a>`;
        }

        html += `
            <tr>
                <td class="fw-bold"><span class="text-primary">${o.order_sn}</span></td>
                <td>${o.create_time}</td>
                <td>${o.buyer_username}</td>
                <td><span class="sp-badge sp-badge-neutral">${o.order_status}</span></td>
                <td class="text-end">${formatCurrency(o.total_amount)}</td>
                <td class="text-end fw-bold">${payoutHtml}</td>
                <td class="text-center">${finBadge}</td>
            </tr>
        `;
    });
    document.getElementById('ordersTbody').innerHTML = html;
    buildPagination('orders', allOrders.length);
}

function renderProfit() {
    if(allProfit.length === 0) {
        document.getElementById('profitTbody').innerHTML = `<tr><td colspan="6" class="text-center py-5 text-muted">No completed orders with payout data.</td></tr>`;
        buildPagination('profit', 0);
        return;
    }
    let html = '';
    const start = (currentPage.profit - 1) * itemsPerPage;
    const end = start + itemsPerPage;
    const slice = allProfit.slice(start, end);
    
    slice.forEach(o => {
        let payout = parseFloat(o.payout_amount);
        let cost = parseFloat(o.total_capital || 0);
        let profit = payout - cost;
        let margin = payout > 0 ? ((profit / payout) * 100).toFixed(1) : 0;
        let profitClass = profit > 0 ? 'text-success' : (profit < 0 ? 'text-danger' : '');
        
        let profitPayoutHtml = `<a href="javascript:void(0)" onclick="viewFeeBreakdown('${o.order_sn}')" class="text-dark text-decoration-underline" title="View Fee Breakdown">${formatCurrency(payout)}</a>`;
        
        html += `
            <tr>
                <td class="fw-bold"><span class="text-primary">${o.order_sn}</span></td>
                <td class="small text-muted">${o.items_summary}</td>
                <td class="text-end fw-bold">${profitPayoutHtml}</td>
                <td class="text-end text-danger">${formatCurrency(cost)}</td>
                <td class="text-end fw-bold ${profitClass}">${formatCurrency(profit)}</td>
                <td class="text-end"><span class="sp-badge sp-badge-neutral border">${margin}%</span></td>
            </tr>
        `;
    });
    document.getElementById('profitTbody').innerHTML = html;
    buildPagination('profit', allProfit.length);
}

function renderRecon() {
    if(allRecon.length === 0) {
        document.getElementById('reconTbody').innerHTML = `<tr><td colspan="6" class="text-center py-5 text-muted">No reconciliation data found.</td></tr>`;
        buildPagination('recon', 0);
        return;
    }
    let html = '';
    const start = (currentPage.recon - 1) * itemsPerPage;
    const end = start + itemsPerPage;
    const slice = allRecon.slice(start, end);
    
    slice.forEach(o => {
        let expectedPayout = parseFloat(o.total_amount) * 0.9;
        if(o.payout_amount) expectedPayout = parseFloat(o.payout_amount);

        let alertHtml = '';
        if((o.order_status === 'COMPLETED' || o.order_status === 'TO_CONFIRM_RECEIVE') && o.financial_status !== 'RELEASED') {
            alertHtml = '<span class="sp-badge sp-badge-danger"><i class="fa-solid fa-triangle-exclamation"></i> Missing Payout</span>';
        } else if (o.order_status === 'CANCELLED') {
            alertHtml = '<span class="sp-badge sp-badge-neutral">Cancelled (Failed/RTS)</span>';
        } else if (o.order_status === 'RETURN_REFUND') {
            alertHtml = '<span class="sp-badge sp-badge-warning">Return / Refund</span>';
        } else if (o.financial_status === 'RELEASED') {
            alertHtml = '<span class="sp-badge sp-badge-success"><i class="fa-solid fa-check"></i> Released to Bank</span>';
        } else {
            alertHtml = '<span class="text-muted small">In Progress...</span>';
        }

        html += `
            <tr>
                <td class="fw-bold"><span class="text-primary">${o.order_sn}</span></td>
                <td>${o.create_time}</td>
                <td><span class="sp-badge sp-badge-neutral">${o.order_status}</span></td>
                <td>${o.financial_status ? `<span class="sp-badge ${o.financial_status === 'RELEASED' ? 'sp-badge-success' : 'sp-badge-warning'}">${o.financial_status}</span>` : '<span class="sp-badge sp-badge-neutral">NOT AVAILABLE</span>'}</td>
                <td class="text-end fw-bold">${formatCurrency(expectedPayout)}</td>
                <td>${alertHtml}</td>
            </tr>
        `;
    });
    document.getElementById('reconTbody').innerHTML = html;
    buildPagination('recon', allRecon.length);
}

function renderTop() {
    if(allTop.length === 0) {
        document.getElementById('topTbody').innerHTML = `<tr><td colspan="5" class="text-center py-5 text-muted">No top products found.</td></tr>`;
        buildPagination('top', 0);
        return;
    }
    let html = '';
    const start = (currentPage.top - 1) * itemsPerPage;
    const end = start + itemsPerPage;
    const slice = allTop.slice(start, end);
    
    slice.forEach(p => {
        let pClass = p.estimated_profit > 0 ? 'text-success' : (p.estimated_profit < 0 ? 'text-danger' : '');
        let variation = p.model_name ? `<br><small class="text-muted">Variation: ${p.model_name}</small>` : '';
        html += `
            <tr>
                <td class="fw-bold"><span class="sp-sku-code">${p.item_sku || '-'}</span></td>
                <td>${p.item_name}${variation}</td>
                <td class="text-center fw-bold fs-6">${p.total_sold}</td>
                <td class="text-end">${formatCurrency(p.total_revenue)}</td>
                <td class="text-end fw-bold ${pClass}">${formatCurrency(p.estimated_profit)}</td>
            </tr>
        `;
    });
    document.getElementById('topTbody').innerHTML = html;
    buildPagination('top', allTop.length);
}

async function syncOrders() {
    document.getElementById('loadingTitle').textContent = 'Syncing Orders';
    document.getElementById('loadingOverlay').style.display = 'flex';
    try {
        console.log("Starting Historical Sync (90 days)...");
        const res = await fetch(`${window.BASE_URL}api/shopee/sync_orders.php?days=90`);
        const data = await res.json();
        if(data.success) {
            alert(`Successfully synced ${data.inserted_or_updated} orders.`);
            loadKPIs();
            applyFilters();
        } else {
            alert('Sync failed: ' + data.error);
        }
    } catch(e) {
        alert('Sync error: ' + e.message);
    } finally {
        document.getElementById('loadingOverlay').style.display = 'none';
    }
}

async function syncFinances() {
    document.getElementById('loadingTitle').textContent = 'Syncing Finances';
    document.getElementById('loadingOverlay').style.display = 'flex';
    try {
        const res = await fetch(`${window.BASE_URL}api/shopee/sync_finances.php`);
        const data = await res.json();
        if(data.success) {
            alert(`Successfully synced ${data.inserted_or_updated} financial records.`);
            loadKPIs();
            applyFilters();
        } else {
            alert('Sync failed: ' + data.error);
        }
    } catch(e) {
        alert('Sync error: ' + e.message);
    } finally {
        document.getElementById('loadingOverlay').style.display = 'none';
    }
}

async function viewFeeBreakdown(orderSn) {
    document.getElementById('loadingOverlay').style.display = 'flex';
    try {
        const res = await fetch(`${window.BASE_URL}api/shopee/get_order_fees.php?order_sn=${orderSn}`);
        const data = await res.json();
        if(data.success) {
            const f = data.fees;
            document.getElementById('feeModalOrderSn').textContent = orderSn;
            
            document.getElementById('feeOriginalPrice').textContent = formatCurrency(f.original_total);
            document.getElementById('feeBuyerShipping').textContent = formatCurrency(f.shipping_fee_paid_by_buyer);
            document.getElementById('feeBuyerTotal').textContent = formatCurrency(f.buyer_total_amount);
            
            document.getElementById('feeCommission').textContent = '-' + formatCurrency(f.commission_fee);
            document.getElementById('feeTransaction').textContent = '-' + formatCurrency(f.transaction_fee);
            document.getElementById('feeService').textContent = '-' + formatCurrency(f.service_fee);
            document.getElementById('feeSellerShipping').textContent = '-' + formatCurrency(f.shipping_fee_paid_by_seller);
            document.getElementById('feeTax').textContent = '-' + formatCurrency(f.escrow_tax);
            
            document.getElementById('feeSellerVoucher').textContent = '+' + formatCurrency(f.seller_voucher);
            document.getElementById('feeShopeeVoucher').textContent = '+' + formatCurrency(f.shopee_voucher);
            
            document.getElementById('feeFinalPayout').textContent = formatCurrency(f.payout_amount);
            
            new bootstrap.Modal(document.getElementById('feeBreakdownModal')).show();
        } else {
            alert(data.error);
        }
    } catch(e) {
        alert('Error fetching fee breakdown.');
    } finally {
        document.getElementById('loadingOverlay').style.display = 'none';
    }
}

document.addEventListener('DOMContentLoaded', () => {
    loadKPIs();
    applyFilters();
});
</script>

<?php require_once '../../includes/footer.php'; ?>

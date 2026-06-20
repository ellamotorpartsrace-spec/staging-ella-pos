<?php
// includes/sidebar.php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/settings_helper.php';

$role = $_SESSION['role'] ?? 'cashier';
$current_page = basename($_SERVER['PHP_SELF']);
$canManageInventory = in_array($role, ['admin', 'super_admin']) || hasPermission('adjust_prices') || in_array($role, ['manager', 'stockman']);
$canViewInventory = $canManageInventory || hasPermission('view_product_history');
$canViewShopee = ($role !== 'stockman'); // Open for all logged in users except stockman
$canViewLazada = ($role !== 'stockman'); // Open for all logged in users except stockman
$canViewSales = hasPermission('make_sales') || hasPermission('view_sales');
$canViewCustomers = hasPermission('view_buyers') || hasPermission('view_wallet_ledger') || hasPermission('view_receivables');
$canViewFinance = hasPermission('view_finance') || hasPermission('view_payables') || hasPermission('view_expenses') || hasPermission('manage_service_fees');
$canViewAdmin = in_array($role, ['admin', 'super_admin']) || hasPermission('manage_users') || hasPermission('manage_settings');

// Load store name from settings
try {
    $_sdb = new Database();
    $_sconn = $_sdb->getConnection();
    $_store_name = getSetting($_sconn, 'store_name', 'ELLA MOTOR PARTS');

    $_pending_approvals_count = 0;
    if (isset($role) && $role === 'super_admin') {
        $_pa_stmt = $_sconn->prepare("SELECT COUNT(DISTINCT COALESCE(batch_id, CONCAT('REQ-', request_id))) as count FROM restock_requests WHERE status = 'pending'");
        $_pa_stmt->execute();
        $_pa_row = $_pa_stmt->fetch(PDO::FETCH_ASSOC);
        $_pending_approvals_count = $_pa_row['count'] ?? 0;
    }
} catch (Exception $e) {
    $_store_name = 'ELLA MOTOR PARTS';
    $_pending_approvals_count = 0;
}
?>

<!-- Sidebar Overlay -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<style>
    #wrapper.toggled .sidebar-mini-badge {
        display: flex !important;
    }
</style>

<div id="sidebar">
    <div class="sidebar-header">
        <div class="text-center">
            <img src="<?= BASE_URL ?>assets/img/logo.png" alt="Ella POS" class="img-fluid" width="80" height="80"
                fetchpriority="high" style="max-height: 80px;">
            <h5 class="fw-bold mt-2 mb-0 text-white" style="letter-spacing: 0.5px;" id="sidebar-store-name">
                <?= htmlspecialchars($_store_name) ?>
            </h5>
            <small class="text-white-50" style="font-size: 0.75rem;">Developed by Benedict Ramirez</small>
        </div>
        <!-- <small class="text-white-50">v2.0 | <?= ucfirst($role) ?></small> -->
    </div>

    <!-- Quick Search Filter -->
    <div class="px-3 pb-3">
        <div class="input-group input-group-sm">
            <span class="input-group-text bg-transparent border-secondary text-secondary">
                <i class="fa-solid fa-magnifying-glass"></i>
            </span>
            <input type="text" id="sidebarSearch" class="form-control bg-transparent border-secondary text-white"
                placeholder="Quick search..." style="border-left: none; font-size: 0.85rem;">
        </div>
    </div>


    <ul class="list-unstyled components">

        <!-- MAIN -->
        <?php if ($_SESSION['role'] !== 'stockman'): ?>
        <div class="sidebar-heading text-uppercase text-white-50 small fw-bold px-3 mt-3 mb-1"><span
                class="nav-text">Main</span></div>
        <li>
            <a data-bs-toggle="tooltip" data-sidebar-tooltip="Dashboard" href="<?= BASE_URL ?>views/dashboard/index.php"
                class="<?= $current_page === 'index.php' && strpos($_SERVER['REQUEST_URI'], 'dashboard') !== false ? 'active' : '' ?>">
                <i class="fa-solid fa-chart-line"></i> <span class="nav-text">Dashboard</span>
            </a>
        </li>
        <?php endif; ?>

        <?php if (in_array($role, ['admin', 'super_admin']) || hasPermission('view_profit') || in_array($role, ['manager'])): ?>
            <li>
                <a data-bs-toggle="tooltip" data-sidebar-tooltip="Statistics" href="<?= BASE_URL ?>views/dashboard/statistics.php"
                    class="<?= $current_page === 'statistics.php' ? 'active' : '' ?>">
                    <i class="fa-solid fa-chart-pie"></i> <span class="nav-text">Statistics</span>
                </a>
            </li>
        <?php endif; ?>

        <!-- SALES -->
        <?php if ($canViewSales): ?>
            <div class="sidebar-heading text-uppercase text-white-50 small fw-bold px-3 mt-3 mb-1 d-flex justify-content-between align-items-center"
                style="cursor: pointer;" data-bs-toggle="collapse" data-bs-target="#salesCollapse">
                <span class="nav-text">Sales</span>
                <i class="fa-solid fa-chevron-down small transition-transform" id="salesChevron"></i>
            </div>
            <div class="collapse show" id="salesCollapse">
                <?php if (hasPermission('make_sales')): ?>
                    <li>
                        <a data-bs-toggle="tooltip" data-sidebar-tooltip="POS Terminal" href="<?= BASE_URL ?>views/pos/simple_checkout.php"
                            class="<?= $current_page === 'simple_checkout.php' ? 'active' : '' ?>">
                            <i class="fa-solid fa-cash-register"></i> <span class="nav-text">POS Terminal</span>
                        </a>
                    </li>
                <?php endif; ?>

                <?php if (hasPermission('view_sales')): ?>
                    <li>
                        <a data-bs-toggle="tooltip" data-sidebar-tooltip="Sales History" href="<?= BASE_URL ?>views/pos/receipts.php"
                            class="<?= $current_page === 'receipts.php' ? 'active' : '' ?>">
                            <i class="fa-solid fa-receipt"></i> <span class="nav-text">Sales History</span>
                        </a>
                    </li>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- INVENTORY -->
        <?php if ($canViewInventory): ?>
            <div class="sidebar-heading text-uppercase text-white-50 small fw-bold px-3 mt-3 mb-1 d-flex justify-content-between align-items-center"
                style="cursor: pointer;" data-bs-toggle="collapse" data-bs-target="#inventoryCollapse">
                <span class="nav-text">Inventory</span>
                <i class="fa-solid fa-chevron-down small transition-transform" id="inventoryChevron"></i>
            </div>
            <div class="collapse show" id="inventoryCollapse">
                <?php if ($canManageInventory): ?>
                    <?php if ($_SESSION['role'] !== 'stockman'): ?>
                    <li>
                        <a data-bs-toggle="tooltip" data-sidebar-tooltip="Inventory Dashboard" href="<?= BASE_URL ?>views/inventory/index.php"
                            class="<?= strpos($_SERVER['REQUEST_URI'], 'inventory') !== false && $current_page === 'index.php' ? 'active' : '' ?>">
                            <i class="fa-solid fa-boxes-stacked"></i> <span class="nav-text">Inventory</span>
                        </a>
                    </li>
                    <?php endif; ?>
                    <li>
                        <a data-bs-toggle="tooltip" data-sidebar-tooltip="Stock Checker" href="<?= BASE_URL ?>views/inventory/stock_checker.php"
                            class="<?= $current_page === 'stock_checker.php' ? 'active' : '' ?>">
                            <i class="fa-solid fa-magnifying-glass-chart"></i> <span class="nav-text">Stock Checker</span>
                        </a>
                    </li>
                    <?php if ($_SESSION['role'] !== 'stockman'): ?>
                    <li>
                        <a data-bs-toggle="tooltip" data-sidebar-tooltip="Categories" href="<?= BASE_URL ?>views/categories/index.php"
                            class="<?= strpos($_SERVER['REQUEST_URI'], 'categories') !== false ? 'active' : '' ?>">
                            <i class="fa-solid fa-tags"></i> <span class="nav-text">Categories</span>
                        </a>
                    </li>
                    <li>
                        <a data-bs-toggle="tooltip" data-sidebar-tooltip="Unit Types" href="<?= BASE_URL ?>views/inventory/unit_types.php"
                            class="<?= $current_page === 'unit_types.php' ? 'active' : '' ?>">
                            <i class="fa-solid fa-boxes-packing"></i> <span class="nav-text">Unit Types</span>
                        </a>
                    </li>
                    <li>
                        <a data-bs-toggle="tooltip" data-sidebar-tooltip="Stocks" href="<?= BASE_URL ?>views/inventory/restock.php"
                            class="<?= $current_page === 'restock.php' ? 'active' : '' ?>">
                            <i class="fa-solid fa-truck-ramp-box"></i> <span class="nav-text">Stocks</span>
                        </a>
                    </li>
                    <?php endif; ?>
                    <?php if (in_array($_SESSION['role'], ['admin', 'super_admin'])): ?>
                    <li>
                        <a data-bs-toggle="tooltip" data-sidebar-tooltip="Pending Approvals" href="<?= BASE_URL ?>views/inventory/pending_approvals.php"
                            class="<?= $current_page === 'pending_approvals.php' ? 'active' : '' ?> position-relative">
                            <i class="fa-solid fa-clipboard-check"></i> 
                            
                            <?php if (!empty($_pending_approvals_count) && $_pending_approvals_count > 0): ?>
                                <span class="badge bg-danger rounded-pill position-absolute sidebar-mini-badge align-items-center justify-content-center shadow-sm" style="display: none; top: 4px; right: 4px; font-size: 0.65rem; padding: 0.2em 0.4em; min-width: 18px; height: 18px;"><?= $_pending_approvals_count ?></span>
                            <?php endif; ?>

                            <span class="nav-text">Pending Approvals
                                <?php if (!empty($_pending_approvals_count) && $_pending_approvals_count > 0): ?>
                                    <span class="badge bg-danger rounded-pill ms-1"><?= $_pending_approvals_count ?></span>
                                <?php endif; ?>
                            </span>
                        </a>
                    </li>
                    <?php endif; ?>
                    <?php if ($_SESSION['role'] !== 'stockman'): ?>
                    <li>
                        <a data-bs-toggle="tooltip" data-sidebar-tooltip="Adjustment" href="<?= BASE_URL ?>views/inventory/adjustment.php"
                            class="<?= $current_page === 'adjustment.php' ? 'active' : '' ?>">
                            <i class="fa-solid fa-sliders"></i> <span class="nav-text">Adjustment</span>
                        </a>
                    </li>
                    <li>
                        <a data-bs-toggle="tooltip" data-sidebar-tooltip="Stock Movements" href="<?= BASE_URL ?>views/inventory/movements.php"
                            class="<?= $current_page === 'movements.php' ? 'active' : '' ?>">
                            <i class="fa-solid fa-arrow-right-arrow-left"></i> <span class="nav-text">Stock Movements</span>
                        </a>
                    </li>
                    <li>
                        <a data-bs-toggle="tooltip" data-sidebar-tooltip="Stock-In Records" href="<?= BASE_URL ?>views/inventory/stockin_records.php"
                            class="<?= $current_page === 'stockin_records.php' ? 'active' : '' ?>">
                            <i class="fa-solid fa-file-invoice"></i> <span class="nav-text">Stock-In Records</span>
                        </a>
                    </li>
                    <?php endif; ?>
                    <?php if (in_array($_SESSION['role'], ['admin', 'super_admin'])): ?>
                        <li>
                            <a data-bs-toggle="tooltip" data-sidebar-tooltip="Image DB Sync" href="<?= BASE_URL ?>views/inventory/reference_image_sync.php"
                                class="<?= $current_page === 'reference_image_sync.php' ? 'active' : '' ?>">
                                <i class="fa-solid fa-database"></i> <span class="nav-text">Image DB Sync</span>
                            </a>
                        </li>
                    <?php endif; ?>
                    <?php if ($_SESSION['role'] !== 'stockman'): ?>
                    <li>
                        <a data-bs-toggle="tooltip" data-sidebar-tooltip="Price History" href="<?= BASE_URL ?>views/inventory/price_history_records.php"
                            class="<?= $current_page === 'price_history_records.php' ? 'active' : '' ?>">
                            <i class="fa-solid fa-chart-line"></i> <span class="nav-text">Price History</span>
                        </a>
                    </li>
                    <li>
                        <a data-bs-toggle="tooltip" data-sidebar-tooltip="Suppliers" href="<?= BASE_URL ?>views/suppliers/index.php"
                            class="<?= strpos($_SERVER['REQUEST_URI'], 'suppliers') !== false ? 'active' : '' ?>">
                            <i class="fa-solid fa-person"></i> <span class="nav-text">Suppliers</span>
                        </a>
                    </li>
                    <?php endif; ?>
                <?php endif; ?>
                <?php if (hasPermission('view_product_history')): ?>
                    <li>
                        <a data-bs-toggle="tooltip" data-sidebar-tooltip="Product History" href="<?= BASE_URL ?>views/inventory/product_history.php"
                            class="<?= $current_page === 'product_history.php' ? 'active' : '' ?>">
                            <i class="fa-solid fa-clock-rotate-left"></i> <span class="nav-text">Product History</span>
                        </a>
                    </li>
                <?php endif; ?>
                <?php if (in_array($_SESSION['role'], ['admin', 'super_admin'])): ?>
                    <li>
                        <a data-bs-toggle="tooltip" data-sidebar-tooltip="Backup &amp;amp; Recovery" href="<?= BASE_URL ?>views/inventory/backup_recovery.php"
                            class="<?= $current_page === 'backup_recovery.php' ? 'active' : '' ?>">
                            <i class="fa-solid fa-shield-halved"></i> <span class="nav-text">Backup &amp; Recovery</span>
                        </a>
                    </li>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- ONLINE STORE -->
        <?php if ($canViewShopee): ?>
            <div class="sidebar-heading text-uppercase text-white-50 small fw-bold px-3 mt-3 mb-1 d-flex justify-content-between align-items-center"
                style="cursor: pointer;" data-bs-toggle="collapse" data-bs-target="#onlineStoreCollapse">
                <span class="nav-text">Shopee Store</span>
                <i class="fa-solid fa-chevron-down small transition-transform" id="onlineStoreChevron"></i>
            </div>
            <div class="collapse <?= strpos($_SERVER['REQUEST_URI'], 'shopee') !== false ? 'show' : '' ?>"
                id="onlineStoreCollapse">


                <?php if ($canViewShopee): ?>
                    <li>
                        <a data-bs-toggle="tooltip" data-sidebar-tooltip="Shopee Dashboard" href="<?= BASE_URL ?>views/shopee/index.php"
                            class="<?= strpos($_SERVER['REQUEST_URI'], 'shopee') !== false && $current_page === 'index.php' ? 'active' : '' ?>">
                            <i class="fa-solid fa-gauge-high"></i> <span class="nav-text">Shopee Dashboard</span>
                        </a>
                    </li>
                    <li>
                        <a data-bs-toggle="tooltip" data-sidebar-tooltip="Sales &amp; Income" href="<?= BASE_URL ?>views/shopee/sales_income.php"
                            class="<?= $current_page === 'sales_income.php' && strpos($_SERVER['REQUEST_URI'], 'shopee') !== false ? 'active' : '' ?>">
                            <i class="fa-solid fa-money-bill-trend-up"></i> <span class="nav-text">Sales & Income</span>
                        </a>
                    </li>
                    <li>
                        <a data-bs-toggle="tooltip" data-sidebar-tooltip="Shopee Products" href="<?= BASE_URL ?>views/shopee/products.php"
                            class="<?= $current_page === 'products.php' && strpos($_SERVER['REQUEST_URI'], 'shopee') !== false ? 'active' : '' ?>">
                            <i class="fa-solid fa-bag-shopping"></i> <span class="nav-text">Shopee Products</span>
                        </a>
                    </li>
                    <?php if (hasPermission('shopee_mapping')): ?>
                    <li>
                        <a data-bs-toggle="tooltip" data-sidebar-tooltip="Product Mapping" href="<?= BASE_URL ?>views/shopee/mapping.php"
                            class="<?= $current_page === 'mapping.php' && strpos($_SERVER['REQUEST_URI'], 'shopee') !== false ? 'active' : '' ?>">
                            <i class="fa-solid fa-link"></i> <span class="nav-text">Product Mapping</span>
                        </a>
                    </li>
                    <?php endif; ?>
                    <?php if (hasPermission('shopee_allocation')): ?>
                    <li>
                        <a data-bs-toggle="tooltip" data-sidebar-tooltip="Stock Allocation" href="<?= BASE_URL ?>views/shopee/allocation.php"
                            class="<?= $current_page === 'allocation.php' && strpos($_SERVER['REQUEST_URI'], 'shopee') !== false ? 'active' : '' ?>">
                            <i class="fa-solid fa-sliders"></i> <span class="nav-text">Stock Allocation</span>
                        </a>
                    </li>
                    <?php endif; ?>
                    <?php if (in_array($role, ['admin', 'super_admin'])): ?>
                    <li>
                        <a data-bs-toggle="tooltip" data-sidebar-tooltip="Sync Logs" href="<?= BASE_URL ?>views/shopee/logs.php"
                            class="<?= $current_page === 'logs.php' && strpos($_SERVER['REQUEST_URI'], 'shopee') !== false ? 'active' : '' ?>">
                            <i class="fa-solid fa-clock-rotate-left"></i> <span class="nav-text">Sync Logs</span>
                        </a>
                    </li>
                    <?php endif; ?>
                    <?php if (in_array($role, ['admin', 'super_admin'])): ?>
                    <li>
                        <a data-bs-toggle="tooltip" data-sidebar-tooltip="Shopee Settings" href="<?= BASE_URL ?>views/shopee/settings.php"
                            class="<?= $current_page === 'settings.php' && strpos($_SERVER['REQUEST_URI'], 'shopee') !== false ? 'active' : '' ?>">
                            <i class="fa-solid fa-gear"></i> <span class="nav-text">Shopee Settings</span>
                        </a>
                    </li>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- LAZADA STORE -->
        <?php if ($canViewLazada): ?>
            <div class="sidebar-heading text-uppercase text-white-50 small fw-bold px-3 mt-3 mb-1 d-flex justify-content-between align-items-center"
                style="cursor: pointer;" data-bs-toggle="collapse" data-bs-target="#lazadaStoreCollapse">
                <span class="nav-text">Lazada Store</span>
                <i class="fa-solid fa-chevron-down small transition-transform" id="lazadaStoreChevron"></i>
            </div>
            <div class="collapse <?= strpos($_SERVER['REQUEST_URI'], 'lazada') !== false ? 'show' : '' ?>"
                id="lazadaStoreCollapse">

                <?php if ($canViewLazada): ?>
                    <li>
                        <a data-bs-toggle="tooltip" data-sidebar-tooltip="Lazada Dashboard" href="<?= BASE_URL ?>views/lazada/laz_index.php"
                            class="<?= strpos($_SERVER['REQUEST_URI'], 'lazada') !== false && $current_page === 'laz_index.php' ? 'active' : '' ?>">
                            <i class="fa-solid fa-gauge-high"></i> <span class="nav-text">Lazada Dashboard</span>
                        </a>
                    </li>
                    <li>
                        <a data-bs-toggle="tooltip" data-sidebar-tooltip="Laz Sales&Income" href="<?= BASE_URL ?>views/lazada/laz_sales_income.php"
                            class="<?= $current_page === 'laz_sales_income.php' && strpos($_SERVER['REQUEST_URI'], 'lazada') !== false ? 'active' : '' ?>">
                            <i class="fa-solid fa-money-bill-trend-up"></i> <span class="nav-text">Laz Sales&Income</span>
                        </a>
                    </li>
                    <li>
                        <a data-bs-toggle="tooltip" data-sidebar-tooltip="Lazada Products" href="<?= BASE_URL ?>views/lazada/laz_products.php"
                            class="<?= $current_page === 'laz_products.php' && strpos($_SERVER['REQUEST_URI'], 'lazada') !== false ? 'active' : '' ?>">
                            <i class="fa-solid fa-bag-shopping"></i> <span class="nav-text">Lazada Products</span>
                        </a>
                    </li>
                    <?php if (hasPermission('shopee_mapping')): ?>
                    <li>
                        <a data-bs-toggle="tooltip" data-sidebar-tooltip="Lazada Mapping" href="<?= BASE_URL ?>views/lazada/laz_mapping.php"
                            class="<?= $current_page === 'laz_mapping.php' && strpos($_SERVER['REQUEST_URI'], 'lazada') !== false ? 'active' : '' ?>">
                            <i class="fa-solid fa-link"></i> <span class="nav-text">Lazada Mapping</span>
                        </a>
                    </li>
                    <?php endif; ?>
                    <?php if (hasPermission('shopee_allocation')): ?>
                    <li>
                        <a data-bs-toggle="tooltip" data-sidebar-tooltip="Lazada Allocation" href="<?= BASE_URL ?>views/lazada/laz_allocation.php"
                            class="<?= $current_page === 'laz_allocation.php' && strpos($_SERVER['REQUEST_URI'], 'lazada') !== false ? 'active' : '' ?>">
                            <i class="fa-solid fa-sliders"></i> <span class="nav-text">Lazada Allocation</span>
                        </a>
                    </li>
                    <?php endif; ?>
                    <?php if (in_array($role, ['admin', 'super_admin'])): ?>
                    <li>
                        <a data-bs-toggle="tooltip" data-sidebar-tooltip="Lazada Sync Logs" href="<?= BASE_URL ?>views/lazada/laz_logs.php"
                            class="<?= $current_page === 'laz_logs.php' && strpos($_SERVER['REQUEST_URI'], 'lazada') !== false ? 'active' : '' ?>">
                            <i class="fa-solid fa-clock-rotate-left"></i> <span class="nav-text">Lazada Sync Logs</span>
                        </a>
                    </li>
                    <?php endif; ?>
                    <?php if (in_array($role, ['admin', 'super_admin'])): ?>
                    <li>
                        <a data-bs-toggle="tooltip" data-sidebar-tooltip="Lazada Settings" href="<?= BASE_URL ?>views/lazada/laz_settings.php"
                            class="<?= $current_page === 'laz_settings.php' && strpos($_SERVER['REQUEST_URI'], 'lazada') !== false ? 'active' : '' ?>">
                            <i class="fa-solid fa-gear"></i> <span class="nav-text">Lazada Settings</span>
                        </a>
                    </li>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- CUSTOMERS -->
        <?php if ($canViewCustomers): ?>
            <div class="sidebar-heading text-uppercase text-white-50 small fw-bold px-3 mt-3 mb-1 d-flex justify-content-between align-items-center"
                style="cursor: pointer;" data-bs-toggle="collapse" data-bs-target="#customersCollapse">
                <span class="nav-text">Customers</span>
                <i class="fa-solid fa-chevron-down small transition-transform" id="customersChevron"></i>
            </div>
            <div class="collapse show" id="customersCollapse">
                <?php if (hasPermission('view_buyers')): ?>
                    <li>
                        <a data-bs-toggle="tooltip" data-sidebar-tooltip="Buyers" href="<?= BASE_URL ?>views/buyers/index.php"
                            class="<?= strpos($_SERVER['REQUEST_URI'], 'buyers') !== false && $current_page !== 'wallet_ledger.php' ? 'active' : '' ?>">
                            <i class="fa-solid fa-users"></i> <span class="nav-text">Buyers</span>
                        </a>
                    </li>
                <?php endif; ?>

                <?php if (hasPermission('view_receivables')): ?>
                    <li>
                        <a data-bs-toggle="tooltip" data-sidebar-tooltip="Receivables Dashboard" href="<?= BASE_URL ?>views/receivables/index.php"
                            class="<?= strpos($_SERVER['REQUEST_URI'], 'receivables') !== false && $current_page === 'index.php' ? 'active' : '' ?>">
                            <i class="fa-solid fa-file-invoice-dollar"></i> <span class="nav-text">Receivables</span>
                        </a>
                    </li>
                <?php endif; ?>

                <?php if (hasPermission('view_receivables')): ?>
                    <li>
                        <a data-bs-toggle="tooltip" data-sidebar-tooltip="Buyer Ledger" href="<?= BASE_URL ?>views/receivables/ledger.php"
                            class="<?= strpos($_SERVER['REQUEST_URI'], 'receivables/ledger.php') !== false ? 'active' : '' ?>">
                            <i class="fa-solid fa-book-bookmark"></i> <span class="nav-text">Buyer Ledger</span>
                        </a>
                    </li>
                <?php endif; ?>

                <?php if (hasPermission('view_wallet_ledger')): ?>
                    <li>
                        <a data-bs-toggle="tooltip" data-sidebar-tooltip="Wallet Ledger" href="<?= BASE_URL ?>views/buyers/wallet_ledger.php"
                            class="<?= $current_page === 'wallet_ledger.php' ? 'active' : '' ?>">
                            <i class="fa-solid fa-wallet"></i> <span class="nav-text">Wallet Ledger</span>
                        </a>
                    </li>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- FINANCE -->
        <?php if ($canViewFinance): ?>
            <div class="sidebar-heading text-uppercase text-white-50 small fw-bold px-3 mt-3 mb-1 d-flex justify-content-between align-items-center"
                style="cursor: pointer;" data-bs-toggle="collapse" data-bs-target="#financeCollapse">
                <span class="nav-text">Finance</span>
                <i class="fa-solid fa-chevron-down small transition-transform" id="financeChevron"></i>
            </div>
            <div class="collapse show" id="financeCollapse">
                <?php if (hasPermission('view_finance')): ?>
                    <li>
                        <a data-bs-toggle="tooltip" data-sidebar-tooltip="Financing" href="<?= BASE_URL ?>views/financing/index.php"
                            class="<?= strpos($_SERVER['REQUEST_URI'], 'financing') !== false || strpos($_SERVER['REQUEST_URI'], 'home_credit') !== false ? 'active' : '' ?>">
                            <i class="fa-solid fa-building-columns"></i> <span class="nav-text">Financing</span>
                        </a>
                    </li>
                <?php endif; ?>

                <?php if (hasPermission('view_payables')): ?>
                    <li>
                        <a data-bs-toggle="tooltip" data-sidebar-tooltip="Payables" href="<?= BASE_URL ?>views/payables/index.php"
                            class="<?= strpos($_SERVER['REQUEST_URI'], 'payables') !== false ? 'active' : '' ?>">
                            <i class="fa-solid fa-money-bill-transfer"></i> <span class="nav-text">Payables</span>
                        </a>
                    </li>
                <?php endif; ?>

                <?php if (hasPermission('view_expenses')): ?>
                    <li>
                        <a data-bs-toggle="tooltip" data-sidebar-tooltip="Expenses" href="<?= BASE_URL ?>views/expenses/index.php"
                            class="<?= strpos($_SERVER['REQUEST_URI'], 'expenses') !== false ? 'active' : '' ?>">
                            <i class="fa-solid fa-money-check-dollar"></i> <span class="nav-text">Expenses</span>
                        </a>
                    </li>
                <?php endif; ?>

                <?php if (hasPermission('manage_service_fees')): ?>
                    <li>
                        <a data-bs-toggle="tooltip" data-sidebar-tooltip="Service Fees" href="<?= BASE_URL ?>views/service_fees/index.php"
                            class="<?= strpos($_SERVER['REQUEST_URI'], 'service_fees') !== false ? 'active' : '' ?>">
                            <i class="fa-solid fa-truck-fast"></i> <span class="nav-text">Service Fees</span>
                        </a>
                    </li>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- PREFERENCES -->
        <div class="sidebar-heading text-uppercase text-white-50 small fw-bold px-3 mt-3 mb-1"><span
                class="nav-text">Preferences</span></div>
        <li>
            <a data-bs-toggle="tooltip" data-sidebar-tooltip="My Profile" href="<?= BASE_URL ?>views/user/profile.php"
                class="<?= $current_page === 'profile.php' ? 'active' : '' ?>">
                <i class="fa-solid fa-user-gear"></i> <span class="nav-text">My Profile</span>
            </a>
        </li>

        <!-- ADMINISTRATION -->
        <?php if ($canViewAdmin): ?>
            <div class="sidebar-heading text-uppercase text-white-50 small fw-bold px-3 mt-3 mb-1 d-flex justify-content-between align-items-center"
                style="cursor: pointer;" data-bs-toggle="collapse" data-bs-target="#adminCollapse">
                <span class="nav-text">Administration</span>
                <i class="fa-solid fa-chevron-down small transition-transform" id="adminChevron"></i>
            </div>
            <div class="collapse show" id="adminCollapse">
                <?php if ($role === 'super_admin'): ?>
                    <li>
                        <a data-bs-toggle="tooltip" data-sidebar-tooltip="Users" href="<?= BASE_URL ?>views/users/index.php"
                            class="<?= strpos($_SERVER['REQUEST_URI'], 'users') !== false ? 'active' : '' ?>">
                            <i class="fa-solid fa-users-gear"></i> <span class="nav-text">Users</span>
                        </a>
                    </li>
                <?php endif; ?>

                <?php if (in_array($role, ['admin', 'super_admin'])): ?>
                    <li>
                        <a data-bs-toggle="tooltip" data-sidebar-tooltip="All User Drafts" href="<?= BASE_URL ?>views/pos/admin_drafts.php"
                            class="<?= $current_page === 'admin_drafts.php' ? 'active' : '' ?>">
                            <i class="fa-solid fa-folder-tree"></i> <span class="nav-text">All User Drafts</span>
                        </a>
                    </li>
                    <li>
                        <a data-bs-toggle="tooltip" data-sidebar-tooltip="Backup &amp; Recovery" href="<?= BASE_URL ?>views/system/backup.php"
                            class="<?= strpos($_SERVER['REQUEST_URI'], 'system/backup') !== false ? 'active' : '' ?>">
                            <i class="fa-solid fa-database"></i> <span class="nav-text">Backup & Recovery</span>
                        </a>
                    </li>
                    <li>
                        <a data-bs-toggle="tooltip" data-sidebar-tooltip="Roles &amp; Permissions" href="<?= BASE_URL ?>views/system/roles.php"
                            class="<?= $current_page === 'roles.php' ? 'active' : '' ?>">
                            <i class="fa-solid fa-user-shield"></i> <span class="nav-text">Roles & Permissions</span>
                        </a>
                    </li>
                <?php endif; ?>

                <?php if (in_array($role, ['admin', 'super_admin']) || hasPermission('manage_settings')): ?>
                    <li>
                        <a data-bs-toggle="tooltip" data-sidebar-tooltip="General Settings" href="<?= BASE_URL ?>views/system/settings.php"
                            class="<?= $current_page === 'settings.php' && strpos($_SERVER['REQUEST_URI'], 'system') !== false ? 'active' : '' ?>">
                            <i class="fa-solid fa-gear"></i> <span class="nav-text">System Settings</span>
                        </a>
                    </li>
                    <li>
                        <a data-bs-toggle="tooltip" data-sidebar-tooltip="Platform Integrations" href="<?= BASE_URL ?>views/system/integrations.php"
                            class="<?= $current_page === 'integrations.php' ? 'active' : '' ?>">
                            <i class="fa-solid fa-plug"></i> <span class="nav-text">Platform Integrations</span>
                        </a>
                    </li>
                    <li>
                        <a data-bs-toggle="tooltip" data-sidebar-tooltip="Scanner Devices" href="<?= BASE_URL ?>views/system/scanner_devices.php"
                            class="<?= $current_page === 'scanner_devices.php' ? 'active' : '' ?>">
                            <i class="fa-solid fa-mobile-screen"></i> <span class="nav-text">Scanner Devices</span>
                        </a>
                    </li>
                <?php endif; ?>

                <?php if (in_array($role, ['admin', 'super_admin'])): ?>
                    <li>
                        <a data-bs-toggle="tooltip" data-sidebar-tooltip="Receipt Templates" href="<?= BASE_URL ?>views/system/receipt_templates.php"
                            class="<?= $current_page === 'receipt_templates.php' ? 'active' : '' ?>">
                            <i class="fa-solid fa-receipt"></i> <span class="nav-text">Receipt Templates</span>
                        </a>
                    </li>
                    <li>
                        <a data-bs-toggle="tooltip" data-sidebar-tooltip="Activity Logs" href="<?= BASE_URL ?>views/system/activity_logs.php"
                            class="<?= $current_page === 'activity_logs.php' && strpos($_SERVER['REQUEST_URI'], 'system') !== false ? 'active' : '' ?>">
                            <i class="fa-solid fa-list-check"></i> <span class="nav-text">Activity Logs</span>
                        </a>
                    </li>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- Theme Toggle -->
        <li class="mt-auto pt-3 border-top border-secondary">
            <button class="w-100 border-0 bg-transparent p-0" id="sidebarThemeToggle" title="Switch Theme">
                <div class="sidebar-theme-toggle">
                    <i class="fa-solid fa-moon" id="sidebarMoonIcon"></i>
                    <i class="fa-solid fa-sun" id="sidebarSunIcon" style="display: none;"></i>
                    <span class="ms-2 fw-medium nav-text" id="sidebarThemeText">Dark Mode</span>
                </div>
            </button>
        </li>

        <li class="mt-2 text-center pb-4">
            <a data-bs-toggle="tooltip" data-sidebar-tooltip="Logout" href="<?= BASE_URL ?>logout.php" class="text-danger text-decoration-none fw-bold">
                <i class="fa-solid fa-power-off me-2"></i> <span class="nav-text">Logout</span>
            </a>
        </li>
    </ul>
</div>

<div id="page-content-wrapper">

    <!-- TOP NAVBAR -->
    <nav class="navbar navbar-expand-lg navbar-light bg-white shadow-sm">
        <div class="container-fluid">
            <button class="btn btn-outline-primary" id="sidebarToggle">
                <i class="fa-solid fa-bars"></i>
            </button>

            <!-- Digital Clock -->
            <div id="headerClock" class="ms-3 fw-bold text-dark fs-6 fs-md-5"></div>
            <script>
                function updateClock() {
                    const now = new Date();
                    const isMobile = window.innerWidth < 768;
                    const options = {
                        weekday: isMobile ? undefined : 'short',
                        month: 'short',
                        day: 'numeric',
                        hour: 'numeric',
                        minute: 'numeric',
                        second: isMobile ? undefined : 'numeric',
                        hour12: true
                    };
                    document.getElementById('headerClock').innerText = now.toLocaleString('en-US', options);
                }
                setInterval(updateClock, 1000);
                setTimeout(updateClock, 0); // Initial call
                window.addEventListener('resize', updateClock);
            </script>

            <div class="ms-auto d-flex align-items-center">
                <div class="text-end me-3">
                    <span class="d-block fw-bold text-dark">
                        <?= htmlspecialchars($_SESSION['full_name'] ?? 'User') ?>
                    </span>
                    <small class="text-muted"><?= $role === 'super_admin' ? 'Super Admin' : ucfirst($role) ?></small>
                </div>
                <div class="<?= $role !== 'super_admin' ? 'bg-primary' : '' ?> text-white rounded-circle d-flex align-items-center justify-content-center fw-bold"
                    style="width: 40px; height: 40px; <?= $role === 'super_admin' ? 'background: linear-gradient(135deg, #8b5cf6, #5b21b6);' : '' ?>">
                    <?= strtoupper(substr($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'U', 0, 1)) ?>
                </div>
            </div>
        </div>
    </nav>

    <!-- MAIN CONTENT -->
    <div id="content" class="container-fluid p-4">

        <!-- ✅ GLOBAL USER BRIDGE (PHP → JS) -->
        <script>
            window.CURRENT_USER_NAME = "<?= htmlspecialchars(
                $_SESSION['full_name']
                ?? $_SESSION['username']
                ?? 'Staff',
                ENT_QUOTES
            ) ?>";

            // Expose individual user preferences mapped in the database
            window.USER_PREFERENCES = <?= json_encode($_SESSION['preferences'] ?? new stdClass()) ?>;

            document.addEventListener("DOMContentLoaded", function () {
                const sidebarSearch = document.getElementById('sidebarSearch');
                const sidebar = document.getElementById('sidebar');
                const wrapper = document.getElementById('wrapper');

                if (!sidebarSearch || !sidebar || !wrapper) return;

                // QUICK SEARCH FILTER LOGIC
                sidebarSearch.addEventListener('input', function (e) {
                    const searchTerm = e.target.value.toLowerCase().trim();
                    const menuItems = sidebar.querySelectorAll('ul.components li:not(.mt-auto)');
                    const headings = sidebar.querySelectorAll('.sidebar-heading');

                    menuItems.forEach(item => {
                        const text = item.innerText.toLowerCase();
                        item.style.display = text.includes(searchTerm) ? 'block' : 'none';
                    });

                    // Handle headings and collapses
                    headings.forEach(heading => {
                        const isMiniMode = window.innerWidth >= 992 && wrapper.classList.contains('toggled');

                        if (searchTerm === '') {
                            heading.style.display = isMiniMode ? 'none' : 'flex';
                            return;
                        }

                        const targetId = heading.getAttribute('data-bs-target');
                        const collapseEl = targetId ? document.querySelector(targetId) : null;
                        let hasVisibleItem = false;

                        if (collapseEl) {
                            const items = collapseEl.querySelectorAll('li');
                            items.forEach(li => {
                                if (li.style.display !== 'none') hasVisibleItem = true;
                            });

                            if (hasVisibleItem) {
                                heading.style.display = 'flex';
                                // Auto-open collapse if item found
                                if (typeof bootstrap !== 'undefined' && !collapseEl.classList.contains('show')) {
                                    try {
                                        const bsCollapse = bootstrap.Collapse.getInstance(collapseEl) || new bootstrap.Collapse(collapseEl, { show: true });
                                        bsCollapse.show();
                                    } catch (e) { }
                                }
                            } else {
                                heading.style.display = 'none';
                            }
                        } else {
                            // For non-collapsible sections like "Main"
                            let nextElement = heading.nextElementSibling;
                            while (nextElement && nextElement.tagName === 'LI') {
                                if (nextElement.style.display !== 'none') {
                                    hasVisibleItem = true;
                                    break;
                                }
                                nextElement = nextElement.nextElementSibling;
                            }
                            heading.style.display = hasVisibleItem ? 'flex' : 'none';
                        }
                    });
                });

                // Chevron Rotation Logic
                sidebar.querySelectorAll('.collapse').forEach(collapse => {
                    collapse.addEventListener('show.bs.collapse', function () {
                        const heading = document.querySelector(`[data-bs-target="#${this.id}"]`);
                        const chevron = heading?.querySelector('.fa-chevron-down');
                        if (chevron) chevron.style.transform = 'rotate(0deg)';
                    });
                    collapse.addEventListener('hide.bs.collapse', function () {
                        const heading = document.querySelector(`[data-bs-target="#${this.id}"]`);
                        const chevron = heading?.querySelector('.fa-chevron-down');
                        if (chevron) chevron.style.transform = 'rotate(-90deg)';
                    });

                    // Set initial chevron state
                    if (!collapse.classList.contains('show')) {
                        const heading = document.querySelector(`[data-bs-target="#${collapse.id}"]`);
                        const chevron = heading?.querySelector('.fa-chevron-down');
                        if (chevron) chevron.style.transform = 'rotate(-90deg)';
                    }
                });

                // Ensure active items show their parents as open
                const activeLink = sidebar.querySelector('ul.components li a.active');
                if (activeLink && typeof bootstrap !== 'undefined') {
                    const parentCollapse = activeLink.closest('.collapse');
                    if (parentCollapse && !parentCollapse.classList.contains('show')) {
                        try {
                            const bsCollapse = bootstrap.Collapse.getInstance(parentCollapse) || new bootstrap.Collapse(parentCollapse, { show: true });
                            bsCollapse.show();
                        } catch (e) {}
                    }
                }

                // Initialize sidebar tooltips for better aesthetics (Bootstrap)
                if (typeof bootstrap !== 'undefined') {
                    const tooltipTriggerList = [].slice.call(document.querySelectorAll('#sidebar [data-bs-toggle="tooltip"]'));
                    tooltipTriggerList.forEach(function (tooltipTriggerEl) {
                        new bootstrap.Tooltip(tooltipTriggerEl, {
                            trigger: 'hover',
                            placement: 'right',
                            boundary: 'window',
                            title: function() {
                                // Only return the string if the sidebar is collapsed
                                const isCollapsed = window.innerWidth >= 992 && wrapper.classList.contains('toggled');
                                return isCollapsed ? this.getAttribute('data-sidebar-tooltip') : '';
                            }
                        });
                    });
                }
            });
        </script>
        <?php
        // End of sidebar.php logic
        ?>



<?php
// views/lazada/index.php — Lazada Sync Dashboard (UI Only)
$page_title = 'Lazada Sync — Dashboard';
require_once '../../config/config.php';
require_once '../../includes/auth.php';
requireLogin();

// Fetch Lazada Connection Status
$db = new Database();
$conn = $db->getConnection();
$stmtToken = $conn->prepare("SELECT access_token, refresh_token, token_expires_at, account_name, is_active FROM lazada_config WHERE is_active = 1 LIMIT 1");
$stmtToken->execute();
$configData = $stmtToken->fetch(PDO::FETCH_ASSOC);

$isConnected = $configData && !empty($configData['access_token']);
$accountName = $isConnected && !empty($configData['account_name']) ? $configData['account_name'] : 'Lazada Store';

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
?>
<style>
/* Enhanced Quick Navigation */
.lz-nav-card {
    transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
    border: 1px solid #e2e8f0;
    background: #f8fafc;
}
.lz-nav-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 12px 24px -8px rgba(15,19,109,0.15);
    border-color: rgba(15, 19, 109, 0.3);
    background: #ffffff;
}
.lz-nav-card:hover .fa-chevron-right {
    transform: translateX(4px);
    color: var(--lazada-primary) !important;
}
.fa-chevron-right {
    transition: transform 0.2s ease, color 0.2s ease;
}

/* Enhanced Health Alerts */
.health-alert-item {
    transition: all 0.2s ease;
    border: 1px solid transparent;
}
.health-alert-item:hover {
    filter: brightness(0.97);
    border-color: rgba(0,0,0,0.05);
}
/* New Quick Nav Styling */
.quick-nav-card {
    background: #ffffff;
    border: 1px solid rgba(0,0,0,0.05);
    border-radius: 16px;
    height: 168px; /* Strict height lock */
    padding: 1.5rem;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    text-align: center;
    gap: 0.5rem;
    text-decoration: none !important;
    transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
    box-shadow: 0 4px 15px rgba(0,0,0,0.02);
    position: relative;
    overflow: hidden;
}
.quick-nav-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 15px 35px rgba(15,19,109,0.12);
    border-color: rgba(15,19,109,0.15);
}
.quick-nav-icon-wrap {
    width: 56px;
    height: 56px;
    border-radius: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.4rem;
    flex-shrink: 0;
    transition: all 0.3s ease;
    margin-bottom: 0.25rem;
}
.quick-nav-card:hover .quick-nav-icon-wrap {
    transform: scale(1.1) rotate(-5deg);
}
.quick-nav-content {
    flex-grow: 1;
}
.quick-nav-title {
    font-weight: 700;
    color: #1e293b;
    font-size: 1.05rem;
    margin-bottom: 0.25rem;
    letter-spacing: -0.2px;
}
.quick-nav-desc {
    font-size: 0.8rem;
    color: #64748b;
    line-height: 1.3;
}
/* New KPI Horizontal Row */
.lz-kpi-row {
    background: #ffffff;
    border: 1px solid rgba(0,0,0,0.05);
    border-radius: 16px;
    height: 76px; /* Strict height lock */
    padding: 0 1.25rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
    box-shadow: 0 4px 15px rgba(0,0,0,0.02);
    transition: all 0.25s ease;
}
.lz-kpi-row:hover {
    box-shadow: 0 8px 25px rgba(15,19,109,0.06);
    border-color: rgba(15,19,109,0.1);
}
.lz-kpi-icon {
    width: 44px;
    height: 44px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.15rem;
    flex-shrink: 0;
}
.lz-kpi-title {
    font-weight: 700;
    font-size: 0.95rem;
    color: #334155;
    letter-spacing: -0.1px;
}
.lz-kpi-subtitle {
    font-size: 0.75rem;
    color: #94a3b8;
}
.lz-kpi-value {
    font-size: 1.5rem;
    font-weight: 800;
    letter-spacing: -0.5px;
}
</style>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/lazada-sync.css?v=<?= filemtime(__DIR__.'/../../assets/css/lazada-sync.css') ?>">

<div class="container-fluid py-2">

    <!-- Hero Header -->
    <div class="lz-hero-header mb-4">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
            <div style="position:relative;z-index:2;">
                <div class="d-flex align-items-center gap-3 mb-2">
                    <div style="width:52px;height:52px;border-radius:14px;background:var(--lazada-primary);display:flex;align-items:center;justify-content:center;box-shadow:0 4px 15px rgba(0,0,0,0.1);">
                        <i class="fa-solid fa-basket-shopping text-white fa-lg"></i>
                    </div>
                    <div>
                        <h2 class="mb-0 text-white fw-bold" style="letter-spacing:-0.5px;">Lazada Sync Dashboard</h2>
                        <div class="text-white text-opacity-75 small">Lazada POS Sync Overview</div>
                    </div>
                </div>
            </div>
            
            <div style="position:relative;z-index:2;" class="d-flex gap-3 align-items-center">
                <?php if ($isConnected): ?>
                    <div class="d-flex align-items-center gap-2 px-3 py-1 bg-white rounded-pill" style="box-shadow: 0 4px 12px rgba(0,0,0,0.15);">
                        <i class="fa-solid fa-circle-check text-success small"></i>
                        <span class="text-success fw-bolder" style="font-size: 0.85rem; letter-spacing: 0.3px;">Connected: <?= htmlspecialchars($accountName) ?></span>
                    </div>
                <?php else: ?>
                    <div class="d-flex align-items-center gap-2 px-3 py-1 bg-white rounded-pill" style="box-shadow: 0 4px 12px rgba(0,0,0,0.15);">
                        <i class="fa-solid fa-triangle-exclamation text-danger small"></i>
                        <span class="text-danger fw-bolder" style="font-size: 0.85rem; letter-spacing: 0.3px;">Not Connected</span>
                    </div>
                <?php endif; ?>

                <a href="<?= BASE_URL ?>views/lazada/settings.php" class="btn btn-light rounded-pill px-4" style="font-weight:600;box-shadow:0 4px 15px rgba(0,0,0,0.1);">
                    <i class="fas fa-cog me-2 text-primary"></i> Settings
                </a>
            </div>
        </div>
    </div>

    <!-- Top Row: Store Metrics (Horizontal) -->
    <div class="row g-3 mb-4">
        <!-- 4 KPI Cards -->
        <div class="col-xl-3 col-md-6 col-12">
            <div class="lz-kpi-row" style="animation-delay:0.05s">
                <div class="d-flex align-items-center gap-3">
                    <div class="lz-kpi-icon bg-blue"><i class="fa-solid fa-bag-shopping"></i></div>
                    <div>
                        <div class="lz-kpi-title">Total Products</div>
                        <div class="lz-kpi-subtitle">Lazada listings</div>
                    </div>
                </div>
                <div class="lz-kpi-value text-dark" id="lzTotalProducts">—</div>
            </div>
        </div>
        
        <div class="col-xl-3 col-md-6 col-12">
            <div class="lz-kpi-row" style="animation-delay:0.1s">
                <div class="d-flex align-items-center gap-3">
                    <div class="lz-kpi-icon" style="background:var(--lz-info-bg);color:var(--lz-info);"><i class="fa-solid fa-layer-group"></i></div>
                    <div>
                        <div class="lz-kpi-title">Total Variations</div>
                        <div class="lz-kpi-subtitle">Product variants</div>
                    </div>
                </div>
                <div class="lz-kpi-value text-dark" id="lzTotalVariations">—</div>
            </div>
        </div>
        
        <div class="col-xl-3 col-md-6 col-12">
            <div class="lz-kpi-row" style="animation-delay:0.15s">
                <div class="d-flex align-items-center gap-3">
                    <div class="lz-kpi-icon bg-success"><i class="fa-solid fa-link"></i></div>
                    <div>
                        <div class="lz-kpi-title">Mapped Items</div>
                        <div class="lz-kpi-subtitle">Linked to ERP</div>
                    </div>
                </div>
                <div class="lz-kpi-value text-success" id="lzMapped">—</div>
            </div>
        </div>
        
        <div class="col-xl-3 col-md-6 col-12">
            <div class="lz-kpi-row" style="animation-delay:0.2s">
                <div class="d-flex align-items-center gap-3">
                    <div class="lz-kpi-icon bg-warning"><i class="fa-solid fa-link-slash"></i></div>
                    <div>
                        <div class="lz-kpi-title">Unmapped Items</div>
                        <div class="lz-kpi-subtitle">Need mapping</div>
                    </div>
                </div>
                <div class="lz-kpi-value text-warning" id="lzUnmapped">—</div>
            </div>
        </div>
    </div>

    <!-- Main Content Row -->
    <div class="row g-4 mb-4">
        <!-- Left Side: Quick Navigation (3x2 Grid) -->
        <div class="col-lg-8">
            <div class="d-flex align-items-center gap-3 mb-4" style="animation-delay:0.25s">
                <div class="lz-icon-box bg-blue" style="width:40px;height:40px;font-size:1.1rem;border-radius:10px; box-shadow: 0 4px 12px rgba(15,19,109,0.1);">
                    <i class="fa-solid fa-compass"></i>
                </div>
                <div>
                    <h5 class="fw-bolder mb-0" style="color:var(--lazada-primary); letter-spacing:-0.5px;">Quick Navigation</h5>
                    <div class="text-muted small fw-600 mt-1">Select a Lazada module</div>
                </div>
            </div>
            
            <div class="row g-3">
                <!-- 1. Products -->
                <div class="col-md-4 col-sm-6">
                    <a href="<?= BASE_URL ?>views/lazada/products.php" class="quick-nav-card" style="border-top: 3px solid #4338ca;">
                        <div class="quick-nav-icon-wrap" style="background: linear-gradient(135deg, #e0e7ff 0%, #c7d2fe 100%); color: #4338ca;">
                            <i class="fa-solid fa-bag-shopping"></i>
                        </div>
                        <div class="quick-nav-content">
                            <div class="quick-nav-title">Products Catalog</div>
                            <div class="quick-nav-desc">Browse your live Lazada listings</div>
                        </div>
                    </a>
                </div>
                
                <!-- 2. Mapping -->
                <div class="col-md-4 col-sm-6">
                    <a href="<?= BASE_URL ?>views/lazada/mapping.php" class="quick-nav-card" style="border-top: 3px solid #0f766e;">
                        <div class="quick-nav-icon-wrap" style="background: linear-gradient(135deg, #ccfbf1 0%, #99f6e4 100%); color: #0f766e;">
                            <i class="fa-solid fa-link"></i>
                        </div>
                        <div class="quick-nav-content">
                            <div class="quick-nav-title">Product Mapping</div>
                            <div class="quick-nav-desc">Link local SKUs to Lazada products</div>
                        </div>
                    </a>
                </div>
                
                <!-- 3. Allocation -->
                <div class="col-md-4 col-sm-6">
                    <a href="<?= BASE_URL ?>views/lazada/allocation.php" class="quick-nav-card" style="border-top: 3px solid #b45309;">
                        <div class="quick-nav-icon-wrap" style="background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%); color: #b45309;">
                            <i class="fa-solid fa-sliders"></i>
                        </div>
                        <div class="quick-nav-content">
                            <div class="quick-nav-title">Stock Allocation</div>
                            <div class="quick-nav-desc">Manage safety limits and stock ratios</div>
                        </div>
                    </a>
                </div>
                
                <!-- 4. Sync Logs -->
                <div class="col-md-4 col-sm-6">
                    <a href="<?= BASE_URL ?>views/lazada/logs.php" class="quick-nav-card" style="border-top: 3px solid #4b5563;">
                        <div class="quick-nav-icon-wrap" style="background: linear-gradient(135deg, #f3f4f6 0%, #e5e7eb 100%); color: #4b5563;">
                            <i class="fa-solid fa-clock-rotate-left"></i>
                        </div>
                        <div class="quick-nav-content">
                            <div class="quick-nav-title">Sync Logs</div>
                            <div class="quick-nav-desc">Review automated stock sync events</div>
                        </div>
                    </a>
                </div>
                
                <!-- 5. Resolution Center (NEW) -->
                <div class="col-md-4 col-sm-6">
                    <a href="<?= BASE_URL ?>views/lazada/resolution.php" class="quick-nav-card" style="border-top: 3px solid #ef4444;">
                        <div class="quick-nav-icon-wrap" style="background: linear-gradient(135deg, #fee2e2 0%, #fca5a5 100%); color: #ef4444;">
                            <i class="fa-solid fa-triangle-exclamation"></i>
                        </div>
                        <div class="quick-nav-content">
                            <div class="quick-nav-title">Resolution Center</div>
                            <div class="quick-nav-desc">Manage sync errors and disputes</div>
                        </div>
                    </a>
                </div>
                
                <!-- 6. Settings (NEW) -->
                <div class="col-md-4 col-sm-6">
                    <a href="<?= BASE_URL ?>views/lazada/settings.php" class="quick-nav-card" style="border-top: 3px solid #8b5cf6;">
                        <div class="quick-nav-icon-wrap" style="background: linear-gradient(135deg, #ede9fe 0%, #ddd6fe 100%); color: #8b5cf6;">
                            <i class="fa-solid fa-gear"></i>
                        </div>
                        <div class="quick-nav-content">
                            <div class="quick-nav-title">System Settings</div>
                            <div class="quick-nav-desc">Configure API connection and rules</div>
                        </div>
                    </a>
                </div>
            </div>
        </div>

        <!-- Right Side: Combined System Connection & Health Alerts -->
        <div class="col-lg-4">
            <!-- Header for Right Side -->
            <div class="d-flex align-items-center gap-3 mb-4" style="animation-delay:0.3s">
                <div class="lz-icon-box bg-success" style="width:40px;height:40px;font-size:1.1rem;border-radius:10px; box-shadow: 0 4px 12px rgba(16,185,129,0.2);">
                    <i class="fa-solid fa-heart-pulse"></i>
                </div>
                <div>
                    <h5 class="fw-bolder mb-0" style="color:#0f172a; letter-spacing:-0.5px;">System Health</h5>
                    <div class="text-muted small fw-600 mt-1">API connection & alerts</div>
                </div>
            </div>
            
            <div class="d-flex flex-column gap-4">
                <!-- Connection Status -->
                <div class="lz-card" style="animation-delay:0.35s;">
                    <div class="lz-card-body p-3">
                        <div class="d-flex flex-column gap-3">
                            <div class="d-flex justify-content-between align-items-center">
                                <span class="small fw-600 text-muted">Lazada API</span>
                                <?php if ($isConnected): ?>
                                    <span class="lz-badge lz-badge-success">Connected</span>
                                <?php else: ?>
                                    <span class="lz-badge lz-badge-warning">Not Configured</span>
                                <?php endif; ?>
                            </div>
                            <div class="d-flex justify-content-between align-items-center">
                                <span class="small fw-600 text-muted">Access Token</span>
                                <?php if ($isConnected && !empty($configData['access_token'])): ?>
                                    <span class="lz-badge lz-badge-success">Valid</span>
                                <?php else: ?>
                                    <span class="lz-badge lz-badge-danger">Missing</span>
                                <?php endif; ?>
                            </div>
                            <div class="d-flex justify-content-between align-items-center">
                                <span class="small fw-600 text-muted">Refresh Token</span>
                                <?php if ($isConnected && !empty($configData['refresh_token'])): ?>
                                    <span class="lz-badge lz-badge-success">Valid</span>
                                <?php else: ?>
                                    <span class="lz-badge lz-badge-danger">Missing</span>
                                <?php endif; ?>
                            </div>
                            <hr style="margin:.25rem 0;opacity:.08">
                            <div class="d-flex justify-content-between align-items-center">
                                <span class="small fw-600 text-muted"><i class="fa-solid fa-clock me-1"></i>Last Sync</span>
                                <span class="small fw-bold">Never</span>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Health Alerts -->
                <div class="lz-card" style="animation-delay:0.4s;">
                    <div class="lz-card-body p-3">
                        <div class="d-flex flex-column gap-2">
                            <div class="d-flex justify-content-between align-items-center p-2 rounded-3 health-alert-item shadow-sm" style="background:#fff5f5; border-left:4px solid #ef4444;">
                                <span class="small fw-600" style="color:#991b1b;"><i class="fa-solid fa-triangle-exclamation me-2" style="font-size:1rem; color:#ef4444;"></i>Missing SKUs</span>
                                <span class="fw-bolder text-danger" id="lzMissingSKU">—</span>
                            </div>
                            <div class="d-flex justify-content-between align-items-center p-2 rounded-3 health-alert-item shadow-sm" style="background:#fff5f5; border-left:4px solid #ef4444;">
                                <span class="small fw-600" style="color:#991b1b;"><i class="fa-solid fa-circle-xmark me-2" style="font-size:1rem; color:#ef4444;"></i>Out of Stock</span>
                                <span class="fw-bolder text-danger" id="lzOos">—</span>
                            </div>
                            <div class="d-flex justify-content-between align-items-center p-2 rounded-3 health-alert-item shadow-sm" style="background:#fffbeb; border-left:4px solid #f59e0b;">
                                <span class="small fw-600" style="color:#92400e;"><i class="fa-solid fa-box-open me-2" style="font-size:1rem; color:#f59e0b;"></i>Low Stock</span>
                                <span class="fw-bolder text-warning" id="lzLowStock">—</span>
                            </div>
                            <div class="d-flex justify-content-between align-items-center p-2 rounded-3 health-alert-item shadow-sm" style="background:#ecfdf5; border-left:4px solid #10b981;">
                                <span class="small fw-600" style="color:#065f46;"><i class="fa-solid fa-rotate me-2" style="font-size:1rem; color:#10b981;"></i>Updates (24h)</span>
                                <span class="fw-bolder text-success" id="lzRecent">—</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Coming Soon Banner -->
    <div class="lz-alert-box mt-4 d-flex align-items-center gap-3">
        <i class="fa-solid fa-circle-info fa-lg" style="color:#d97706;flex-shrink:0;"></i>
        <div>
            <strong>Lazada Integration — Setup Required</strong>
            <div class="small mt-1">
                This module is in UI preview mode. To activate live syncing, configure your Lazada API credentials in
                <a href="<?= BASE_URL ?>views/lazada/settings.php" class="fw-bold" style="color:#92400e;">Settings</a>.
                No stock changes will be made until the integration is fully configured and enabled.
            </div>
        </div>
    </div>

</div>

<?php require_once '../../includes/footer.php'; ?>

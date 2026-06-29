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
                    <a href="<?= BASE_URL ?>views/lazada/settings.php" class="d-flex align-items-center gap-2 px-3 py-1 bg-white rounded-pill text-decoration-none" style="box-shadow: 0 4px 12px rgba(0,0,0,0.15); transition: transform 0.2s;" onmouseover="this.style.transform='scale(1.05)'" onmouseout="this.style.transform='scale(1)'">
                        <i class="fa-solid fa-circle-check text-success small"></i>
                        <span class="text-success fw-bolder" style="font-size: 0.85rem; letter-spacing: 0.3px;">Connected: <?= htmlspecialchars($accountName) ?></span>
                    </a>
                <?php else: ?>
                    <a href="<?= BASE_URL ?>views/lazada/settings.php" class="d-flex align-items-center gap-2 px-3 py-1 bg-white rounded-pill text-decoration-none" style="box-shadow: 0 4px 12px rgba(0,0,0,0.15); transition: transform 0.2s;" onmouseover="this.style.transform='scale(1.05)'" onmouseout="this.style.transform='scale(1)'">
                        <i class="fa-solid fa-triangle-exclamation text-danger small"></i>
                        <span class="text-danger fw-bolder" style="font-size: 0.85rem; letter-spacing: 0.3px;">Not Connected</span>
                    </a>
                <?php endif; ?>

                <a href="<?= BASE_URL ?>views/lazada/settings.php" class="btn btn-light rounded-pill px-4" style="font-weight:600;box-shadow:0 4px 15px rgba(0,0,0,0.1);">
                    <i class="fas fa-cog me-2 text-primary"></i> Settings
                </a>
            </div>
        </div>
    </div>

    <!-- Stat Cards -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-3">
            <div class="lz-stat-card" style="animation-delay:0.05s">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="lz-stat-label mb-2">Total Products</div>
                        <div class="lz-stat-value" id="lzTotalProducts">—</div>
                        <div class="small text-muted mt-1">Lazada listings</div>
                    </div>
                    <div class="lz-icon-box bg-blue"><i class="fa-solid fa-bag-shopping"></i></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="lz-stat-card" style="animation-delay:0.1s">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="lz-stat-label mb-2">Total Variations</div>
                        <div class="lz-stat-value" id="lzTotalVariations">—</div>
                        <div class="small text-muted mt-1">Product variants</div>
                    </div>
                    <div class="lz-icon-box" style="background:var(--lz-info-bg);color:var(--lz-info);"><i class="fa-solid fa-layer-group"></i></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="lz-stat-card" style="animation-delay:0.15s">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="lz-stat-label mb-2">Mapped Items</div>
                        <div class="lz-stat-value text-success" id="lzMapped">—</div>
                        <div class="small text-muted mt-1">Linked to ERP</div>
                    </div>
                    <div class="lz-icon-box bg-success"><i class="fa-solid fa-link"></i></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="lz-stat-card" style="animation-delay:0.2s">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="lz-stat-label mb-2">Unmapped Items</div>
                        <div class="lz-stat-value text-warning" id="lzUnmapped">—</div>
                        <div class="small text-muted mt-1">Need mapping</div>
                    </div>
                    <div class="lz-icon-box bg-warning"><i class="fa-solid fa-link-slash"></i></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Grid -->
    <div class="row g-4">
        <!-- Left: Navigation Tiles -->
        <div class="col-lg-7 d-flex">
            <div class="lz-card w-100 d-flex flex-column" style="animation-delay:0.25s">
                <div class="lz-card-header d-flex align-items-center gap-2">
                    <div class="lz-icon-box bg-blue" style="width:36px;height:36px;font-size:1rem;border-radius:10px;">
                        <i class="fa-solid fa-compass"></i>
                    </div>
                    <div>
                        <div class="fw-700" style="font-size:.92rem;color:var(--lazada-primary)">Quick Navigation</div>
                        <div class="small text-muted">Lazada module sections</div>
                    </div>
                </div>
                <div class="lz-card-body p-4">
                    <div class="lz-nav-grid" style="grid-template-columns:repeat(2,1fr);gap:1.25rem;">

                        <a href="<?= BASE_URL ?>views/lazada/products.php" class="lz-nav-card" style="padding:1.5rem;gap:1.25rem; background: linear-gradient(145deg, #ffffff, #f0f4ff); border-color: #e0e7ff;">
                            <div class="lz-nav-icon bg-blue" style="width:56px;height:56px;font-size:1.5rem;border-radius:14px;">
                                <i class="fa-solid fa-bag-shopping"></i>
                            </div>
                            <div class="lz-nav-content">
                                <div class="lz-nav-title" style="font-size:1.05rem;">Products</div>
                                <div class="lz-nav-desc" style="font-size:0.85rem;">Browse Lazada listings catalog</div>
                            </div>
                            <i class="fa-solid fa-chevron-right text-muted small"></i>
                        </a>

                        <a href="<?= BASE_URL ?>views/lazada/mapping.php" class="lz-nav-card" style="padding:1.5rem;gap:1.25rem; background: linear-gradient(145deg, #ffffff, #f0fdfa); border-color: #ccfbf1;">
                            <div class="lz-nav-icon bg-gradient-info" style="width:56px;height:56px;font-size:1.5rem;border-radius:14px;">
                                <i class="fa-solid fa-link"></i>
                            </div>
                            <div class="lz-nav-content">
                                <div class="lz-nav-title" style="font-size:1.05rem;">Product Mapping</div>
                                <div class="lz-nav-desc" style="font-size:0.85rem;">Link SKUs to ERP inventory</div>
                            </div>
                            <i class="fa-solid fa-chevron-right text-muted small"></i>
                        </a>

                        <a href="<?= BASE_URL ?>views/lazada/allocation.php" class="lz-nav-card" style="padding:1.5rem;gap:1.25rem; background: linear-gradient(145deg, #ffffff, #eff6ff); border-color: #dbeafe;">
                            <div class="lz-nav-icon" style="width:56px;height:56px;font-size:1.5rem;border-radius:14px;background:var(--lz-info-bg);color:var(--lz-info);">
                                <i class="fa-solid fa-sliders"></i>
                            </div>
                            <div class="lz-nav-content">
                                <div class="lz-nav-title" style="font-size:1.05rem;">Stock Allocation</div>
                                <div class="lz-nav-desc" style="font-size:0.85rem;">Manage online stock safety limits and rules</div>
                            </div>
                            <i class="fa-solid fa-chevron-right text-muted small"></i>
                        </a>

                        <a href="<?= BASE_URL ?>views/lazada/logs.php" class="lz-nav-card" style="padding:1.5rem;gap:1.25rem; background: linear-gradient(145deg, #ffffff, #f8fafc); border-color: #e2e8f0;">
                            <div class="lz-nav-icon" style="width:56px;height:56px;font-size:1.5rem;border-radius:14px;background:var(--lz-neutral-bg);color:var(--lz-neutral-text);">
                                <i class="fa-solid fa-clock-rotate-left"></i>
                            </div>
                            <div class="lz-nav-content">
                                <div class="lz-nav-title" style="font-size:1.05rem;">Sync Logs</div>
                                <div class="lz-nav-desc" style="font-size:0.85rem;">Review stock sync events</div>
                            </div>
                            <i class="fa-solid fa-chevron-right text-muted small"></i>
                        </a>

                        <a href="<?= BASE_URL ?>views/lazada/resolution.php" class="lz-nav-card" style="padding:1.5rem;gap:1.25rem; background: linear-gradient(145deg, #ffffff, #fef2f2); border-color: #fee2e2;">
                            <div class="lz-nav-icon bg-gradient-danger" style="width:56px;height:56px;font-size:1.5rem;border-radius:14px;">
                                <i class="fa-solid fa-triangle-exclamation"></i>
                            </div>
                            <div class="lz-nav-content">
                                <div class="lz-nav-title" style="font-size:1.05rem;">Resolution Center</div>
                                <div class="lz-nav-desc" style="font-size:0.85rem;">Manage sync errors and disputes</div>
                            </div>
                            <i class="fa-solid fa-chevron-right text-muted small"></i>
                        </a>

                        <a href="<?= BASE_URL ?>views/lazada/settings.php" class="lz-nav-card" style="padding:1.5rem;gap:1.25rem; background: linear-gradient(145deg, #ffffff, #fffbeb); border-color: #fef3c7;">
                            <div class="lz-nav-icon" style="width:56px;height:56px;font-size:1.5rem;border-radius:14px;background:var(--lz-warning-bg);color:var(--lz-warning);">
                                <i class="fa-solid fa-gear"></i>
                            </div>
                            <div class="lz-nav-content">
                                <div class="lz-nav-title" style="font-size:1.05rem;">Settings & Setup</div>
                                <div class="lz-nav-desc" style="font-size:0.85rem;">Configure API credentials and preferences</div>
                            </div>
                            <i class="fa-solid fa-chevron-right text-muted small"></i>
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right: System Health -->
        <div class="col-lg-5 d-flex">
            <!-- Consolidated System Health Card -->
            <div class="lz-card w-100 d-flex flex-column" style="animation-delay:0.3s">
                <div class="lz-card-header d-flex align-items-center gap-2">
                    <div class="lz-icon-box bg-blue" style="width:36px;height:36px;font-size:1rem;border-radius:10px;">
                        <i class="fa-solid fa-heart-pulse"></i>
                    </div>
                    <div>
                        <div class="fw-700" style="font-size:.92rem;color:var(--lazada-primary)">System Health</div>
                        <div class="small text-muted">API connection & store alerts</div>
                    </div>
                </div>
                <div class="lz-card-body p-4 d-flex flex-column flex-grow-1">
                    <div class="d-flex flex-column gap-3 mb-4">
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

                    <!-- Overall Status Indicator (Fills remaining height) -->
                    <div class="mt-auto d-flex flex-column align-items-center justify-content-center p-4 rounded-4" style="background: linear-gradient(145deg, #f8fafc, #f1f5f9); border: 1px solid rgba(0,0,0,0.03);">
                        
                        <?php if ($isConnected): ?>
                            <!-- Connected State -->
                            <div class="position-relative mb-3 d-flex align-items-center justify-content-center" style="width: 80px; height: 80px;">
                                <div class="position-absolute w-100 h-100 rounded-circle" style="background: #10b981; opacity: 0.15; animation: pulse 2s infinite;"></div>
                                <div class="position-absolute rounded-circle" style="width: 60px; height: 60px; background: #10b981; opacity: 0.25; animation: pulse 2s infinite 0.5s;"></div>
                                <div class="d-flex align-items-center justify-content-center rounded-circle" style="width: 44px; height: 44px; background: #10b981; color: white; position: relative; z-index: 2; box-shadow: 0 4px 15px rgba(16,185,129,0.3);">
                                    <i class="fa-solid fa-check fa-lg"></i>
                                </div>
                            </div>
                            <h5 class="fw-bolder text-dark mb-1">Systems Optimal</h5>
                            <div class="text-muted small text-center px-3">Your store is securely connected to the Lazada API. Sync services are running smoothly.</div>
                        <?php else: ?>
                            <!-- Disconnected State -->
                            <div class="position-relative mb-3 d-flex align-items-center justify-content-center" style="width: 80px; height: 80px;">
                                <div class="position-absolute w-100 h-100 rounded-circle" style="background: #ef4444; opacity: 0.15;"></div>
                                <div class="d-flex align-items-center justify-content-center rounded-circle" style="width: 44px; height: 44px; background: #ef4444; color: white; position: relative; z-index: 2; box-shadow: 0 4px 15px rgba(239,68,68,0.3);">
                                    <i class="fa-solid fa-link-slash fa-lg"></i>
                                </div>
                            </div>
                            <h5 class="fw-bolder text-dark mb-1">Action Required</h5>
                            <div class="text-muted small text-center px-3">Connection is missing. Please navigate to settings to configure your API tokens.</div>
                        <?php endif; ?>
                        
                        <style>
                            @keyframes pulse {
                                0% { transform: scale(0.95); opacity: 0.3; }
                                50% { transform: scale(1.1); opacity: 0.1; }
                                100% { transform: scale(0.95); opacity: 0.3; }
                            }
                        </style>
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

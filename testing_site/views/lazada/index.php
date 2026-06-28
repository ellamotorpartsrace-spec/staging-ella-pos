<?php
// views/lazada/index.php — Lazada Sync Dashboard (UI Only)
$page_title = 'Lazada Sync — Dashboard';
require_once '../../config/config.php';
require_once '../../includes/auth.php';
requireLogin();
require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/lazada-sync.css?v=<?= filemtime(__DIR__.'/../../assets/css/lazada-sync.css') ?>">

<div class="container-fluid py-2">

    <!-- Hero Header -->
    <div class="lz-hero-header mb-4">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
            <div style="position:relative;z-index:2;">
                <div class="d-flex align-items-center gap-3 mb-2">
                    <div style="width:52px;height:52px;border-radius:14px;background:rgba(255,255,255,0.2);display:flex;align-items:center;justify-content:center;backdrop-filter:blur(10px);">
                        <img src="https://lzd-img-global.slatic.net/g/tps/tfs/TB1vJWBXuSSBuNjy0FlXXbBpVXa-200-200.png"
                             onerror="this.style.display='none';this.nextElementSibling.style.display='flex';"
                             style="width:34px;height:34px;object-fit:contain;" alt="Lazada">
                        <i class="fa-solid fa-store" style="display:none;color:#fff;font-size:1.4rem;"></i>
                    </div>
                    <div>
                        <h1 class="lz-title mb-0">Lazada Sync Dashboard</h1>
                        <p class="lz-subtitle mb-0">Operational control center for your Lazada integration</p>
                    </div>
                </div>
            </div>
            <div style="position:relative;z-index:2;" class="d-flex gap-2 flex-wrap">
                <span class="lz-badge lz-badge-warning" id="lzConnBadge" style="font-size:.75rem;padding:.5rem 1rem;">
                    <i class="fa-solid fa-circle-dot me-1"></i> Coming Soon
                </span>
                <a href="<?= BASE_URL ?>views/lazada/settings.php" class="btn-outline-lazada" style="font-size:.85rem;padding:.5rem 1.25rem;">
                    <i class="fa-solid fa-gear me-1"></i> Settings
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
                        <div class="lz-stat-label mb-2">Total Listings</div>
                        <div class="lz-stat-value" id="lzTotalListings">—</div>
                        <div class="small text-muted mt-1">Lazada products</div>
                    </div>
                    <div class="lz-icon-box bg-blue"><i class="fa-solid fa-bag-shopping"></i></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="lz-stat-card" style="animation-delay:0.1s">
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
            <div class="lz-stat-card" style="animation-delay:0.15s">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="lz-stat-label mb-2">Unmapped</div>
                        <div class="lz-stat-value text-warning" id="lzUnmapped">—</div>
                        <div class="small text-muted mt-1">Need mapping</div>
                    </div>
                    <div class="lz-icon-box bg-warning"><i class="fa-solid fa-link-slash"></i></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="lz-stat-card" style="animation-delay:0.2s">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="lz-stat-label mb-2">Pending Orders</div>
                        <div class="lz-stat-value text-danger" id="lzOrders">—</div>
                        <div class="small text-muted mt-1">Awaiting fulfillment</div>
                    </div>
                    <div class="lz-icon-box bg-danger"><i class="fa-solid fa-box-open"></i></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Grid -->
    <div class="row g-4">
        <!-- Left: Navigation Tiles -->
        <div class="col-lg-7">
            <div class="lz-card" style="animation-delay:0.25s">
                <div class="lz-card-header d-flex align-items-center gap-2">
                    <div class="lz-icon-box bg-blue" style="width:36px;height:36px;font-size:1rem;border-radius:10px;">
                        <i class="fa-solid fa-compass"></i>
                    </div>
                    <div>
                        <div class="fw-700" style="font-size:.92rem;color:var(--lazada-primary)">Quick Navigation</div>
                        <div class="small text-muted">Lazada module sections</div>
                    </div>
                </div>
                <div class="lz-card-body p-3">
                    <div class="lz-nav-grid" style="grid-template-columns:repeat(2,1fr);gap:1rem;">

                        <a href="<?= BASE_URL ?>views/lazada/products.php" class="lz-nav-card" style="padding:1.25rem;gap:1rem;">
                            <div class="lz-nav-icon bg-blue" style="width:52px;height:52px;font-size:1.4rem;border-radius:13px;">
                                <i class="fa-solid fa-bag-shopping"></i>
                            </div>
                            <div class="lz-nav-content">
                                <div class="lz-nav-title" style="font-size:.95rem;">Products</div>
                                <div class="lz-nav-desc">Browse Lazada listings catalog</div>
                            </div>
                            <i class="fa-solid fa-chevron-right text-muted small"></i>
                        </a>

                        <a href="<?= BASE_URL ?>views/lazada/mapping.php" class="lz-nav-card" style="padding:1.25rem;gap:1rem;">
                            <div class="lz-nav-icon bg-gradient-info" style="width:52px;height:52px;font-size:1.4rem;border-radius:13px;">
                                <i class="fa-solid fa-link"></i>
                            </div>
                            <div class="lz-nav-content">
                                <div class="lz-nav-title" style="font-size:.95rem;">Product Mapping</div>
                                <div class="lz-nav-desc">Link SKUs to ERP inventory</div>
                            </div>
                            <i class="fa-solid fa-chevron-right text-muted small"></i>
                        </a>

                        <a href="<?= BASE_URL ?>views/lazada/orders.php" class="lz-nav-card" style="padding:1.25rem;gap:1rem;">
                            <div class="lz-nav-icon bg-gradient-danger" style="width:52px;height:52px;font-size:1.4rem;border-radius:13px;">
                                <i class="fa-solid fa-boxes-packing"></i>
                            </div>
                            <div class="lz-nav-content">
                                <div class="lz-nav-title" style="font-size:.95rem;">Orders</div>
                                <div class="lz-nav-desc">Manage Lazada order queue</div>
                            </div>
                            <i class="fa-solid fa-chevron-right text-muted small"></i>
                        </a>

                        <a href="<?= BASE_URL ?>views/lazada/logs.php" class="lz-nav-card" style="padding:1.25rem;gap:1rem;">
                            <div class="lz-nav-icon" style="width:52px;height:52px;font-size:1.4rem;border-radius:13px;background:var(--lz-neutral-bg);color:var(--lz-neutral-text);">
                                <i class="fa-solid fa-clock-rotate-left"></i>
                            </div>
                            <div class="lz-nav-content">
                                <div class="lz-nav-title" style="font-size:.95rem;">Sync Logs</div>
                                <div class="lz-nav-desc">Review stock sync events</div>
                            </div>
                            <i class="fa-solid fa-chevron-right text-muted small"></i>
                        </a>

                        <a href="<?= BASE_URL ?>views/lazada/settings.php" class="lz-nav-card" style="padding:1.25rem;gap:1rem;grid-column:span 2;">
                            <div class="lz-nav-icon" style="width:52px;height:52px;font-size:1.4rem;border-radius:13px;background:var(--lz-warning-bg);color:var(--lz-warning);">
                                <i class="fa-solid fa-gear"></i>
                            </div>
                            <div class="lz-nav-content">
                                <div class="lz-nav-title" style="font-size:.95rem;">Settings & Setup</div>
                                <div class="lz-nav-desc">Configure API credentials and sync preferences for your Lazada store</div>
                            </div>
                            <i class="fa-solid fa-chevron-right text-muted small"></i>
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right: Status & Alerts -->
        <div class="col-lg-5">
            <!-- Connection Status -->
            <div class="lz-card mb-4" style="animation-delay:0.3s">
                <div class="lz-card-header d-flex align-items-center gap-2">
                    <div class="lz-icon-box bg-blue" style="width:36px;height:36px;font-size:1rem;border-radius:10px;">
                        <i class="fa-solid fa-heart-pulse"></i>
                    </div>
                    <div>
                        <div class="fw-700" style="font-size:.92rem;color:var(--lazada-primary)">System Connection</div>
                        <div class="small text-muted">Lazada API health</div>
                    </div>
                </div>
                <div class="lz-card-body p-3">
                    <div class="d-flex flex-column gap-3">
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="small fw-600 text-muted">Lazada API</span>
                            <span class="lz-badge lz-badge-warning">Not Configured</span>
                        </div>
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="small fw-600 text-muted">Access Token</span>
                            <span class="lz-badge lz-badge-danger">Missing</span>
                        </div>
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="small fw-600 text-muted">Refresh Token</span>
                            <span class="lz-badge lz-badge-danger">Missing</span>
                        </div>
                        <hr style="margin:.25rem 0;opacity:.08">
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="small fw-600 text-muted"><i class="fa-solid fa-clock me-1"></i>Last Sync</span>
                            <span class="small fw-bold">Never</span>
                        </div>
                    </div>
                    <div class="mt-3">
                        <a href="<?= BASE_URL ?>views/lazada/settings.php" class="btn-lazada w-100 d-block text-center" style="font-size:.85rem;padding:.6rem 1rem;">
                            <i class="fa-solid fa-plug me-1"></i> Connect Lazada Account
                        </a>
                    </div>
                </div>
            </div>

            <!-- Health Alerts -->
            <div class="lz-card" style="animation-delay:0.35s">
                <div class="lz-card-header d-flex align-items-center gap-2">
                    <div class="lz-icon-box bg-danger" style="width:36px;height:36px;font-size:1rem;border-radius:10px;">
                        <i class="fa-solid fa-bell"></i>
                    </div>
                    <div>
                        <div class="fw-700" style="font-size:.92rem;color:var(--lazada-primary)">Health Alerts</div>
                        <div class="small text-muted">Issues requiring attention</div>
                    </div>
                </div>
                <div class="lz-card-body p-3">
                    <div class="d-flex flex-column gap-2">
                        <div class="d-flex justify-content-between align-items-center p-2 rounded-3" style="background:var(--lz-danger-bg);border-left:3px solid var(--lz-danger);">
                            <span class="small fw-600"><i class="fa-solid fa-triangle-exclamation text-danger me-2"></i>Missing SKUs</span>
                            <span class="fw-800 text-danger" id="lzMissingSKU">—</span>
                        </div>
                        <div class="d-flex justify-content-between align-items-center p-2 rounded-3" style="background:var(--lz-danger-bg);border-left:3px solid var(--lz-danger);">
                            <span class="small fw-600"><i class="fa-solid fa-circle-xmark text-danger me-2"></i>Out of Stock</span>
                            <span class="fw-800 text-danger" id="lzOos">—</span>
                        </div>
                        <div class="d-flex justify-content-between align-items-center p-2 rounded-3" style="background:var(--lz-warning-bg);border-left:3px solid var(--lz-warning);">
                            <span class="small fw-600"><i class="fa-solid fa-box-open text-warning me-2"></i>Low Stock</span>
                            <span class="fw-800 text-warning" id="lzLowStock">—</span>
                        </div>
                        <div class="d-flex justify-content-between align-items-center p-2 rounded-3" style="background:var(--lz-success-bg);border-left:3px solid var(--lz-success);">
                            <span class="small fw-600"><i class="fa-solid fa-rotate text-success me-2"></i>Updates (24h)</span>
                            <span class="fw-800 text-success" id="lzRecent">—</span>
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

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

    <!-- Premium Hero Header -->
    <div class="lz-premium-hero mb-4">
        <div class="lz-hero-shapes">
            <div class="lz-shape lz-shape-1"></div>
            <div class="lz-shape lz-shape-2"></div>
            <div class="lz-shape lz-shape-3"></div>
        </div>
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-4 position-relative" style="z-index:10;">
            <div class="d-flex align-items-center gap-4">
                <div class="lz-hero-logo-box">
                    <i class="fa-solid fa-basket-shopping text-white" style="font-size:1.8rem;"></i>
                </div>
                <div>
                    <h2 class="mb-1 text-white fw-bolder" style="letter-spacing:-0.5px; font-size: 2.2rem; text-shadow: 0 2px 10px rgba(0,0,0,0.2);">Dashboard</h2>
                    <div class="text-white text-opacity-75 fw-medium" style="font-size:1.05rem;">Lazada POS Sync Overview</div>
                </div>
            </div>
            
            <div class="d-flex gap-3 align-items-center">
                <?php include 'account_switcher.php'; ?>
                <a href="<?= BASE_URL ?>views/lazada/settings.php" class="lz-btn-glass">
                    <i class="fas fa-cog me-2"></i> Settings
                </a>
            </div>
        </div>
    </div>

    <!-- Stat Cards -->
    <div class="row g-4 mb-4">
        <div class="col-6 col-lg-3">
            <div class="lz-stat-card-premium" style="animation-delay:0.05s">
                <div class="lz-stat-glow bg-blue-glow"></div>
                <div class="d-flex justify-content-between align-items-start position-relative z-1">
                    <div>
                        <div class="lz-stat-label mb-1">Total Products</div>
                        <div class="lz-stat-value" id="lzTotalProducts">—</div>
                        <div class="lz-stat-desc mt-2"><i class="fa-solid fa-arrow-trend-up me-1 text-success"></i>Lazada listings</div>
                    </div>
                    <div class="lz-icon-premium bg-gradient-blue"><i class="fa-solid fa-bag-shopping"></i></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="lz-stat-card-premium" style="animation-delay:0.1s">
                <div class="lz-stat-glow bg-info-glow"></div>
                <div class="d-flex justify-content-between align-items-start position-relative z-1">
                    <div>
                        <div class="lz-stat-label mb-1">Total Variations</div>
                        <div class="lz-stat-value" id="lzTotalVariations">—</div>
                        <div class="lz-stat-desc mt-2"><i class="fa-solid fa-layer-group me-1 text-info"></i>Product variants</div>
                    </div>
                    <div class="lz-icon-premium bg-gradient-info"><i class="fa-solid fa-layer-group"></i></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="lz-stat-card-premium" style="animation-delay:0.15s">
                <div class="lz-stat-glow bg-success-glow"></div>
                <div class="d-flex justify-content-between align-items-start position-relative z-1">
                    <div>
                        <div class="lz-stat-label mb-1">Mapped Items</div>
                        <div class="lz-stat-value text-success" id="lzMapped">—</div>
                        <div class="lz-stat-desc mt-2"><i class="fa-solid fa-check-circle me-1 text-success"></i>Linked to ERP</div>
                    </div>
                    <div class="lz-icon-premium bg-gradient-success"><i class="fa-solid fa-link"></i></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="lz-stat-card-premium" style="animation-delay:0.2s">
                <div class="lz-stat-glow bg-warning-glow"></div>
                <div class="d-flex justify-content-between align-items-start position-relative z-1">
                    <div>
                        <div class="lz-stat-label mb-1">Unmapped Items</div>
                        <div class="lz-stat-value text-warning" id="lzUnmapped">—</div>
                        <div class="lz-stat-desc mt-2"><i class="fa-solid fa-triangle-exclamation me-1 text-warning"></i>Action needed</div>
                    </div>
                    <div class="lz-icon-premium bg-gradient-warning"><i class="fa-solid fa-link-slash"></i></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Grid -->
    <div class="row g-4">
        <!-- Left: Navigation Tiles -->
        <div class="col-lg-7">
            <div class="lz-card-premium" style="animation-delay:0.25s">
                <div class="lz-card-header-premium d-flex align-items-center gap-3">
                    <div class="lz-icon-box-sm bg-gradient-blue">
                        <i class="fa-solid fa-compass"></i>
                    </div>
                    <div>
                        <div class="fw-bold" style="font-size:1.05rem;color:var(--lz-dark)">Quick Navigation</div>
                        <div class="small text-muted fw-medium">Lazada module sections</div>
                    </div>
                </div>
                <div class="lz-card-body p-4">
                    <div class="lz-nav-grid">
                        <a href="<?= BASE_URL ?>views/lazada/products.php" class="lz-nav-card-premium">
                            <div class="lz-nav-icon-premium bg-gradient-blue">
                                <i class="fa-solid fa-bag-shopping"></i>
                            </div>
                            <div class="lz-nav-content">
                                <div class="lz-nav-title">Products</div>
                                <div class="lz-nav-desc">Browse Lazada listings catalog</div>
                            </div>
                            <div class="lz-nav-arrow"><i class="fa-solid fa-arrow-right"></i></div>
                        </a>

                        <a href="<?= BASE_URL ?>views/lazada/mapping.php" class="lz-nav-card-premium">
                            <div class="lz-nav-icon-premium bg-gradient-success">
                                <i class="fa-solid fa-link"></i>
                            </div>
                            <div class="lz-nav-content">
                                <div class="lz-nav-title">Product Mapping</div>
                                <div class="lz-nav-desc">Link SKUs to ERP inventory</div>
                            </div>
                            <div class="lz-nav-arrow"><i class="fa-solid fa-arrow-right"></i></div>
                        </a>

                        <a href="<?= BASE_URL ?>views/lazada/allocation.php" class="lz-nav-card-premium">
                            <div class="lz-nav-icon-premium bg-gradient-info">
                                <i class="fa-solid fa-sliders"></i>
                            </div>
                            <div class="lz-nav-content">
                                <div class="lz-nav-title">Stock Allocation</div>
                                <div class="lz-nav-desc">Manage online stock safety limits</div>
                            </div>
                            <div class="lz-nav-arrow"><i class="fa-solid fa-arrow-right"></i></div>
                        </a>

                        <a href="<?= BASE_URL ?>views/lazada/logs.php" class="lz-nav-card-premium">
                            <div class="lz-nav-icon-premium bg-gradient-neutral">
                                <i class="fa-solid fa-clock-rotate-left"></i>
                            </div>
                            <div class="lz-nav-content">
                                <div class="lz-nav-title">Sync Logs</div>
                                <div class="lz-nav-desc">Review stock sync events</div>
                            </div>
                            <div class="lz-nav-arrow"><i class="fa-solid fa-arrow-right"></i></div>
                        </a>

                        <a href="<?= BASE_URL ?>views/lazada/settings.php" class="lz-nav-card-premium full-width">
                            <div class="lz-nav-icon-premium bg-gradient-warning">
                                <i class="fa-solid fa-gear"></i>
                            </div>
                            <div class="lz-nav-content">
                                <div class="lz-nav-title">Settings & Setup</div>
                                <div class="lz-nav-desc">Configure API credentials and sync preferences</div>
                            </div>
                            <div class="lz-nav-arrow"><i class="fa-solid fa-arrow-right"></i></div>
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right: Status & Alerts -->
        <div class="col-lg-5">
            <!-- Connection Status -->
            <div class="lz-card-premium mb-4" style="animation-delay:0.3s">
                <div class="lz-card-header-premium d-flex align-items-center gap-3">
                    <div class="lz-icon-box-sm bg-gradient-blue">
                        <i class="fa-solid fa-heart-pulse"></i>
                    </div>
                    <div>
                        <div class="fw-bold" style="font-size:1.05rem;color:var(--lz-dark)">System Connection</div>
                        <div class="small text-muted fw-medium">Lazada API health</div>
                    </div>
                </div>
                <div class="lz-card-body p-4">
                    <div class="lz-status-list">
                        <div class="lz-status-item">
                            <span class="lz-status-label">Lazada API</span>
                            <span class="lz-badge-premium warning">Not Configured</span>
                        </div>
                        <div class="lz-status-item">
                            <span class="lz-status-label">Access Token</span>
                            <span class="lz-badge-premium danger">Missing</span>
                        </div>
                        <div class="lz-status-item">
                            <span class="lz-status-label">Refresh Token</span>
                            <span class="lz-badge-premium danger">Missing</span>
                        </div>
                        <div class="lz-status-divider"></div>
                        <div class="lz-status-item">
                            <span class="lz-status-label"><i class="fa-regular fa-clock me-2"></i>Last Sync</span>
                            <span class="lz-status-value fw-bold">Never</span>
                        </div>
                    </div>
                    <div class="mt-4">
                        <a href="<?= BASE_URL ?>views/lazada/settings.php" class="lz-btn-primary w-100">
                            <i class="fa-solid fa-plug me-2"></i> Connect Lazada Account
                        </a>
                    </div>
                </div>
            </div>

            <!-- Health Alerts -->
            <div class="lz-card-premium" style="animation-delay:0.35s">
                <div class="lz-card-header-premium d-flex align-items-center gap-3">
                    <div class="lz-icon-box-sm bg-gradient-danger">
                        <i class="fa-solid fa-bell"></i>
                    </div>
                    <div>
                        <div class="fw-bold" style="font-size:1.05rem;color:var(--lz-dark)">Health Alerts</div>
                        <div class="small text-muted fw-medium">Issues requiring attention</div>
                    </div>
                </div>
                <div class="lz-card-body p-4">
                    <div class="d-flex flex-column gap-3">
                        <div class="lz-alert-item danger">
                            <div class="d-flex align-items-center gap-3">
                                <div class="lz-alert-icon"><i class="fa-solid fa-triangle-exclamation"></i></div>
                                <span class="fw-semibold">Missing SKUs</span>
                            </div>
                            <span class="lz-alert-count" id="lzMissingSKU">—</span>
                        </div>
                        <div class="lz-alert-item danger">
                            <div class="d-flex align-items-center gap-3">
                                <div class="lz-alert-icon"><i class="fa-solid fa-circle-xmark"></i></div>
                                <span class="fw-semibold">Out of Stock</span>
                            </div>
                            <span class="lz-alert-count" id="lzOos">—</span>
                        </div>
                        <div class="lz-alert-item warning">
                            <div class="d-flex align-items-center gap-3">
                                <div class="lz-alert-icon"><i class="fa-solid fa-box-open"></i></div>
                                <span class="fw-semibold">Low Stock</span>
                            </div>
                            <span class="lz-alert-count" id="lzLowStock">—</span>
                        </div>
                        <div class="lz-alert-item success">
                            <div class="d-flex align-items-center gap-3">
                                <div class="lz-alert-icon"><i class="fa-solid fa-rotate"></i></div>
                                <span class="fw-semibold">Updates (24h)</span>
                            </div>
                            <span class="lz-alert-count" id="lzRecent">—</span>
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

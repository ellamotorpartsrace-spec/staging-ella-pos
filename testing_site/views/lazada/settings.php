<?php
// views/lazada/settings.php — Lazada Settings & Setup (UI Only)
$page_title = 'Lazada Sync — Settings';
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
        <nav aria-label="breadcrumb" style="position:relative;z-index:2;">
            <ol class="breadcrumb mb-2" style="font-size:.8rem;">
                <li class="breadcrumb-item"><a href="<?= BASE_URL ?>views/lazada/index.php" class="text-white-50">Lazada Sync</a></li>
                <li class="breadcrumb-item active text-white">Settings</li>
            </ol>
        </nav>
        <div style="position:relative;z-index:2;">
            <h1 class="lz-title mb-1"><i class="fa-solid fa-gear me-2" style="font-size:1.5rem;opacity:.9;"></i>Settings & Setup</h1>
            <p class="lz-subtitle mb-0">Configure your Lazada API credentials and sync preferences</p>
        </div>
    </div>

    <!-- Coming Soon Warning -->
    <div class="lz-alert-box mb-4 d-flex align-items-start gap-3">
        <i class="fa-solid fa-circle-info fa-lg mt-1" style="color:#d97706;flex-shrink:0;"></i>
        <div>
            <strong>Setup Mode — Integration Not Yet Active</strong>
            <div class="small mt-1">
                This settings panel is in preview mode. Saving credentials here will store them securely, but
                live syncing will not begin until the Lazada integration is fully implemented and enabled.
                No stock changes will occur in the meantime.
            </div>
        </div>
    </div>

    <div class="row g-4">
        <!-- Left Column -->
        <div class="col-lg-8">

            <!-- API Credentials -->
            <div class="lz-card mb-4" style="animation-delay:0.05s">
                <div class="lz-card-header d-flex align-items-center gap-2">
                    <div class="lz-icon-box bg-blue" style="width:36px;height:36px;font-size:.95rem;border-radius:10px;">
                        <i class="fa-solid fa-key"></i>
                    </div>
                    <div>
                        <div class="fw-700" style="font-size:.92rem;color:var(--lazada-primary)">API Credentials</div>
                        <div class="small text-muted">Lazada Open Platform (LOP) app credentials</div>
                    </div>
                </div>
                <div class="lz-card-body p-4">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">App Key</label>
                            <input type="text" class="form-control" placeholder="Enter Lazada App Key" disabled>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">App Secret</label>
                            <div class="input-group">
                                <input type="password" class="form-control" placeholder="Enter App Secret" disabled>
                                <button class="btn btn-outline-secondary" type="button" disabled>
                                    <i class="fa-solid fa-eye"></i>
                                </button>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Country / Region</label>
                            <select class="form-select" disabled>
                                <option>Philippines (PH)</option>
                                <option>Thailand (TH)</option>
                                <option>Indonesia (ID)</option>
                                <option>Malaysia (MY)</option>
                                <option>Vietnam (VN)</option>
                                <option>Singapore (SG)</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Environment</label>
                            <select class="form-select" disabled>
                                <option>Production</option>
                                <option>Sandbox (Testing)</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Redirect URI (OAuth Callback)</label>
                            <input type="text" class="form-control" value="https://yourstore.com/api/lazada/oauth_callback.php" disabled>
                            <div class="form-text">Register this URL in your Lazada Open Platform app settings.</div>
                        </div>
                    </div>
                    <div class="mt-3 d-flex gap-2">
                        <button class="btn-lazada" disabled><i class="fa-solid fa-floppy-disk me-1"></i> Save Credentials</button>
                        <button class="btn-outline-lazada" disabled><i class="fa-solid fa-plug me-1"></i> Authorize with Lazada</button>
                    </div>
                </div>
            </div>

            <!-- Token Status -->
            <div class="lz-card mb-4" style="animation-delay:0.1s">
                <div class="lz-card-header d-flex align-items-center gap-2">
                    <div class="lz-icon-box bg-warning" style="width:36px;height:36px;font-size:.95rem;border-radius:10px;">
                        <i class="fa-solid fa-id-badge"></i>
                    </div>
                    <div>
                        <div class="fw-700" style="font-size:.92rem;color:var(--lazada-primary)">Access Token</div>
                        <div class="small text-muted">OAuth 2.0 token for Lazada API calls</div>
                    </div>
                </div>
                <div class="lz-card-body p-4">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label">Access Token</label>
                            <div class="input-group">
                                <input type="password" class="form-control" placeholder="Not configured" disabled>
                                <button class="btn btn-outline-secondary" disabled><i class="fa-solid fa-eye"></i></button>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Refresh Token</label>
                            <input type="password" class="form-control" placeholder="Not configured" disabled>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Token Expiry</label>
                            <input type="text" class="form-control" placeholder="N/A" disabled>
                        </div>
                    </div>
                    <div class="mt-3">
                        <button class="btn-outline-lazada" disabled>
                            <i class="fa-solid fa-rotate me-1"></i> Refresh Token Manually
                        </button>
                    </div>
                </div>
            </div>

            <!-- Sync Preferences -->
            <div class="lz-card" style="animation-delay:0.15s">
                <div class="lz-card-header d-flex align-items-center gap-2">
                    <div class="lz-icon-box bg-success" style="width:36px;height:36px;font-size:.95rem;border-radius:10px;">
                        <i class="fa-solid fa-sliders"></i>
                    </div>
                    <div>
                        <div class="fw-700" style="font-size:.92rem;color:var(--lazada-primary)">Sync Preferences</div>
                        <div class="small text-muted">Control how and when syncs happen</div>
                    </div>
                </div>
                <div class="lz-card-body p-4">
                    <div class="lz-setting-row d-flex justify-content-between align-items-center">
                        <div>
                            <div class="fw-600" style="font-size:.92rem;">Auto Sync Stock</div>
                            <div class="small text-muted">Automatically push stock changes to Lazada</div>
                        </div>
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" disabled>
                        </div>
                    </div>
                    <div class="lz-setting-row d-flex justify-content-between align-items-center">
                        <div>
                            <div class="fw-600" style="font-size:.92rem;">Auto Pull Orders</div>
                            <div class="small text-muted">Automatically import new Lazada orders</div>
                        </div>
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" disabled>
                        </div>
                    </div>
                    <div class="lz-setting-row d-flex justify-content-between align-items-center">
                        <div>
                            <div class="fw-600" style="font-size:.92rem;">Low Stock Alerts</div>
                            <div class="small text-muted">Notify when Lazada stock drops below threshold</div>
                        </div>
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" disabled>
                        </div>
                    </div>
                    <div class="lz-setting-row">
                        <label class="form-label">Sync Interval</label>
                        <select class="form-select" style="max-width:250px;" disabled>
                            <option>Every 5 minutes</option>
                            <option>Every 15 minutes</option>
                            <option>Every 30 minutes</option>
                            <option>Every hour</option>
                        </select>
                    </div>
                    <div class="lz-setting-row">
                        <label class="form-label">Low Stock Threshold</label>
                        <input type="number" class="form-control" style="max-width:150px;" value="5" min="0" disabled>
                        <div class="form-text">Units remaining to trigger a low stock warning</div>
                    </div>
                    <div class="mt-3">
                        <button class="btn-lazada" disabled>
                            <i class="fa-solid fa-floppy-disk me-1"></i> Save Preferences
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right Column: Info & Status -->
        <div class="col-lg-4">

            <!-- Connection Status Card -->
            <div class="lz-card mb-4" style="animation-delay:0.1s">
                <div class="lz-card-header d-flex align-items-center gap-2">
                    <div class="lz-icon-box bg-danger" style="width:36px;height:36px;font-size:.95rem;border-radius:10px;">
                        <i class="fa-solid fa-heart-pulse"></i>
                    </div>
                    <div>
                        <div class="fw-700" style="font-size:.92rem;color:var(--lazada-primary)">Connection Status</div>
                    </div>
                </div>
                <div class="lz-card-body p-3">
                    <div class="d-flex flex-column gap-3">
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="small fw-600 text-muted">API Credentials</span>
                            <span class="lz-badge lz-badge-danger">Not Set</span>
                        </div>
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="small fw-600 text-muted">OAuth Token</span>
                            <span class="lz-badge lz-badge-danger">Missing</span>
                        </div>
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="small fw-600 text-muted">API Connectivity</span>
                            <span class="lz-badge lz-badge-warning">Untested</span>
                        </div>
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="small fw-600 text-muted">Last Sync</span>
                            <span class="small fw-bold">Never</span>
                        </div>
                    </div>
                    <div class="mt-3">
                        <button class="btn-outline-lazada w-100 d-block" disabled>
                            <i class="fa-solid fa-wifi me-1"></i> Test Connection
                        </button>
                    </div>
                </div>
            </div>

            <!-- How to Get Started -->
            <div class="lz-card" style="animation-delay:0.15s">
                <div class="lz-card-header d-flex align-items-center gap-2">
                    <div class="lz-icon-box" style="width:36px;height:36px;font-size:.95rem;border-radius:10px;background:var(--lz-info-bg);color:var(--lz-info);">
                        <i class="fa-solid fa-circle-question"></i>
                    </div>
                    <div>
                        <div class="fw-700" style="font-size:.92rem;color:var(--lazada-primary)">How to Get Started</div>
                    </div>
                </div>
                <div class="lz-card-body p-3">
                    <div class="lz-timeline" style="padding-left:24px;">
                        <div class="lz-timeline-item">
                            <div class="lz-timeline-dot dot-info"></div>
                            <div class="lz-timeline-content">
                                <div class="fw-700" style="font-size:.85rem;">1. Register on Lazada Open Platform</div>
                                <div class="small text-muted mt-1">Create a seller app at <span class="text-lazada-blue fw-600">open.lazada.com</span></div>
                            </div>
                        </div>
                        <div class="lz-timeline-item">
                            <div class="lz-timeline-dot dot-info"></div>
                            <div class="lz-timeline-content">
                                <div class="fw-700" style="font-size:.85rem;">2. Get App Key & Secret</div>
                                <div class="small text-muted mt-1">Copy credentials from your LOP dashboard</div>
                            </div>
                        </div>
                        <div class="lz-timeline-item">
                            <div class="lz-timeline-dot dot-info"></div>
                            <div class="lz-timeline-content">
                                <div class="fw-700" style="font-size:.85rem;">3. Enter Credentials Above</div>
                                <div class="small text-muted mt-1">Fill in App Key, Secret, and region</div>
                            </div>
                        </div>
                        <div class="lz-timeline-item">
                            <div class="lz-timeline-dot dot-info"></div>
                            <div class="lz-timeline-content">
                                <div class="fw-700" style="font-size:.85rem;">4. Authorize with OAuth</div>
                                <div class="small text-muted mt-1">Click "Authorize with Lazada" to get your access token</div>
                            </div>
                        </div>
                        <div class="lz-timeline-item" style="margin-bottom:0;">
                            <div class="lz-timeline-dot dot-success"></div>
                            <div class="lz-timeline-content">
                                <div class="fw-700" style="font-size:.85rem;">5. Map Products & Enable Sync</div>
                                <div class="small text-muted mt-1">Link your Lazada listings to ERP items and go live</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>

</div>

<?php require_once '../../includes/footer.php'; ?>

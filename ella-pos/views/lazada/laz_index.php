<?php
// views/lazada/laz_index.php — Lazada Sync Dashboard Redesign
$page_title = 'Lazada Sync — Dashboard';
require_once '../../config/config.php';
require_once '../../includes/auth.php';
requireLogin();
require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/lazada-sync.css?v=<?= filemtime(__DIR__.'/../../assets/css/lazada-sync.css') ?>">

<div class="container-fluid py-4" style="max-width: 1400px;">
    <?php require_once __DIR__ . '/laz_token_warning.php'; ?>
    
    <!-- Hero Header -->
    <div class="lz-hero-header">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center position-relative" style="z-index: 1;">
            <div>
                <h1 class="lz-title mb-1">Lazada Command Center</h1>
                <p class="lz-subtitle mb-0">Total operational control over your Lazada integrations</p>
            </div>
            <div class="mt-3 mt-md-0 d-flex align-items-center gap-3">
                <div class="text-end me-2">
                    <div class="small fw-bold text-secondary text-uppercase mb-1">System Status</div>
                    <div id="statusBadge" class="lz-badge lz-badge-primary">Connecting...</div>
                </div>
                <a href="<?= BASE_URL ?>views/lazada/laz_settings.php" class="btn-outline-lazada">
                    <i class="fa-solid fa-gear me-1"></i> Configure
                </a>
            </div>
        </div>
    </div>

    <!-- Top Stats Row (Aesthetic Redesign) -->
    <div class="row g-4 mb-4">
        <div class="col-md-3 col-sm-6">
            <div class="lz-stat-card lz-stat-card-blue">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="lz-stat-label">Total Listings</div>
                        <div class="lz-stat-value mt-1" id="kTotalP">—</div>
                    </div>
                    <div class="lz-icon-box bg-blue">
                        <i class="fa-solid fa-box"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-sm-6">
            <div class="lz-stat-card lz-stat-card-blue">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="lz-stat-label">Variations</div>
                        <div class="lz-stat-value mt-1" id="kTotalV">—</div>
                    </div>
                    <div class="lz-icon-box bg-blue">
                        <i class="fa-solid fa-layer-group"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-sm-6">
            <div class="lz-stat-card lz-stat-card-success">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="lz-stat-label">Mapped Successfully</div>
                        <div class="lz-stat-value mt-1" id="kMatched">—</div>
                    </div>
                    <div class="lz-icon-box bg-success">
                        <i class="fa-solid fa-link"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-sm-6">
            <div class="lz-stat-card lz-stat-card-danger">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="lz-stat-label">Action Required</div>
                        <div class="lz-stat-value mt-1 text-danger" id="kUnmatched">—</div>
                    </div>
                    <div class="lz-icon-box bg-danger">
                        <i class="fa-solid fa-triangle-exclamation"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Split Screen: Operations vs Feed -->
    <div class="row g-4">
        <!-- Operations Grid -->
        <div class="col-lg-8">
            <div class="d-flex align-items-center mb-3">
                <i class="fa-solid fa-bolt text-lazada me-2 fs-5"></i>
                <h4 class="fw-bold mb-0">Quick Operations</h4>
            </div>
            <div class="lz-nav-grid">
                <a href="<?= BASE_URL ?>views/lazada/laz_products.php" class="lz-nav-card">
                    <div class="lz-nav-icon bg-gradient-blue"><i class="fa-solid fa-bag-shopping"></i></div>
                    <div class="lz-nav-content">
                        <div class="lz-nav-title">Lazada Products</div>
                        <div class="lz-nav-desc">Browse local and online synced listings catalog</div>
                    </div>
                </a>
                <a href="<?= BASE_URL ?>views/lazada/laz_mapping.php" class="lz-nav-card">
                    <div class="lz-nav-icon bg-gradient-blue"><i class="fa-solid fa-link"></i></div>
                    <div class="lz-nav-content">
                        <div class="lz-nav-title">Product Mapping</div>
                        <div class="lz-nav-desc">Match local ERP items to Lazada listings manually</div>
                    </div>
                </a>
                <a href="<?= BASE_URL ?>views/lazada/laz_resolution_center.php" class="lz-nav-card">
                    <div class="lz-nav-icon bg-gradient-primary"><i class="fa-solid fa-wrench"></i></div>
                    <div class="lz-nav-content">
                        <div class="lz-nav-title">Resolution Center</div>
                        <div class="lz-nav-desc">Fix duplicate SKUs and missing identifiers on Lazada</div>
                    </div>
                </a>
                <a href="<?= BASE_URL ?>views/lazada/laz_allocation.php" class="lz-nav-card">
                    <div class="lz-nav-icon bg-gradient-info"><i class="fa-solid fa-chart-pie"></i></div>
                    <div class="lz-nav-content">
                        <div class="lz-nav-title">Stock Allocation</div>
                        <div class="lz-nav-desc">Manage online stock safety limits and business rules</div>
                    </div>
                </a>
                <a href="<?= BASE_URL ?>views/lazada/laz_settings.php" class="lz-nav-card">
                    <div class="lz-nav-icon bg-gradient-blue"><i class="fa-solid fa-gear"></i></div>
                    <div class="lz-nav-content">
                        <div class="lz-nav-title">API Settings</div>
                        <div class="lz-nav-desc">Configure Lazada API keys, store IDs, and global sync preferences</div>
                    </div>
                </a>
                <a href="<?= BASE_URL ?>views/lazada/laz_logs.php" class="lz-nav-card">
                    <div class="lz-nav-icon bg-gradient-info"><i class="fa-solid fa-clock-rotate-left"></i></div>
                    <div class="lz-nav-content">
                        <div class="lz-nav-title">System Logs</div>
                        <div class="lz-nav-desc">View historical sync events, API payloads, and detailed error traces</div>
                    </div>
                </a>
            </div>
        </div>

        <!-- System Alerts Timeline -->
        <div class="col-lg-4">
            <div class="lz-card h-100">
                <div class="lz-card-header d-flex align-items-center justify-content-between">
                    <div class="d-flex align-items-center">
                        <i class="fa-solid fa-heart-pulse text-lazada me-2 fs-5"></i>
                        <h5 class="fw-bold mb-0">Live Health Feed</h5>
                    </div>
                    <span class="lz-badge lz-badge-primary">Live</span>
                </div>
                
                <div class="p-3">
                    <div class="lz-timeline">
                        <!-- Dynamic Feed Items -->
                        <div class="lz-timeline-item">
                            <div class="lz-timeline-dot dot-success"></div>
                            <div class="lz-timeline-content">
                                <div class="fw-bold text-success mb-1">API Connection</div>
                                <div class="small text-secondary" id="valConn">Checking connection status...</div>
                            </div>
                        </div>

                        <div class="lz-timeline-item">
                            <div class="lz-timeline-dot dot-warning"></div>
                            <div class="lz-timeline-content">
                                <div class="fw-bold text-warning mb-1">Token Status</div>
                                <div class="small text-secondary" id="valToken">Verifying token validity...</div>
                            </div>
                        </div>

                        <div class="lz-timeline-item">
                            <div class="lz-timeline-dot dot-danger"></div>
                            <div class="lz-timeline-content">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <span class="fw-bold text-danger">Critical Errors</span>
                                    <span class="badge bg-danger rounded-pill" id="kTotalErrors">0</span>
                                </div>
                                <div class="small mt-2 text-secondary p-2 bg-light rounded border">
                                    <div class="d-flex justify-content-between mb-1">
                                        <span>Missing SKUs:</span>
                                        <strong id="kMissingErrors" class="text-danger">0</strong>
                                    </div>
                                    <div class="d-flex justify-content-between">
                                        <span>Duplicates:</span>
                                        <strong id="kDupErrors" class="text-danger">0</strong>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="lz-timeline-item">
                            <div class="lz-timeline-dot dot-info"></div>
                            <div class="lz-timeline-content">
                                <div class="fw-bold text-info mb-1">Last Sync Event</div>
                                <div class="small text-secondary" id="valLastSync">—</div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mt-3 pt-2 border-top">
                        <a href="<?= BASE_URL ?>views/lazada/laz_logs.php" class="btn-lazada w-100 text-center d-flex align-items-center justify-content-center gap-2">
                            <span>View Full Logs</span> <i class="fa-solid fa-arrow-right"></i>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', async () => {
    try {
        const res  = await fetch(`${window.BASE_URL}api/lazada/get_dashboard_stats.php`);
        const data = await res.json();
        if (!data.success) return;
        const k = data.kpi, s = data.status;

        // KPIs
        document.getElementById('kTotalP').textContent    = k.total_products.toLocaleString();
        document.getElementById('kTotalV').textContent    = k.total_variations.toLocaleString();
        document.getElementById('kMatched').textContent   = k.matched.toLocaleString();
        document.getElementById('kUnmatched').textContent = k.unmatched.toLocaleString();
        
        let missingErrors = 0;
        let duplicateErrors = 0;
        if (data.health) {
            if (data.health.missing_errors !== undefined) missingErrors = data.health.missing_errors;
            if (data.health.duplicate_errors !== undefined) duplicateErrors = data.health.duplicate_errors;
        }
        document.getElementById('kMissingErrors').textContent = missingErrors.toLocaleString();
        document.getElementById('kDupErrors').textContent     = duplicateErrors.toLocaleString();
        document.getElementById('kTotalErrors').textContent   = (missingErrors + duplicateErrors).toLocaleString();

        // Status Badge
        const cb = document.getElementById('statusBadge');
        if (s.connected) { 
            cb.innerHTML = '<i class="fa-solid fa-check-circle me-1"></i> Active'; 
            cb.className = 'lz-badge lz-badge-success'; 
        } else { 
            cb.innerHTML = '<i class="fa-solid fa-triangle-exclamation me-1"></i> Disconnected'; 
            cb.className = 'lz-badge lz-badge-danger'; 
        }

        // Timeline updates
        document.getElementById('valConn').textContent  = s.connected   ? 'Lazada API is reachable and responding.' : 'API is currently unreachable.';
        document.getElementById('valToken').textContent = s.token_valid ? 'Access token is valid and active.'  : 'Token expired or missing. Re-auth required.';
        document.getElementById('valLastSync').textContent = s.last_sync || 'Never';

    } catch(e) { console.error('Lazada Dashboard load failed:', e); }
});
</script>

<script src="../../views/lazada/laz_alerts.js"></script>
<?php require_once '../../includes/footer.php'; ?>

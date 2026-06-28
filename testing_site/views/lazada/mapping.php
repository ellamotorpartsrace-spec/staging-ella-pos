<?php
// views/lazada/mapping.php — Lazada Product Mapping (UI Only)
$page_title = 'Lazada Sync — Product Mapping';
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
                <li class="breadcrumb-item active text-white">Product Mapping</li>
            </ol>
        </nav>
        <div style="position:relative;z-index:2;">
            <h1 class="lz-title mb-1"><i class="fa-solid fa-link me-2" style="font-size:1.5rem;opacity:.9;"></i>Product Mapping</h1>
            <p class="lz-subtitle mb-0">Link Lazada listings to your local ERP inventory items</p>
        </div>
    </div>

    <!-- Two-Panel Layout -->
    <div class="row g-4">

        <!-- Left: Unmapped Lazada Listings -->
        <div class="col-lg-6">
            <div class="lz-card" style="animation-delay:0.05s">
                <div class="lz-card-header d-flex align-items-center justify-content-between">
                    <div class="d-flex align-items-center gap-2">
                        <div class="lz-icon-box bg-danger" style="width:36px;height:36px;font-size:.95rem;border-radius:10px;">
                            <i class="fa-solid fa-link-slash"></i>
                        </div>
                        <div>
                            <div class="fw-700" style="font-size:.92rem;color:var(--lazada-primary)">Unmapped Lazada Listings</div>
                            <div class="small text-muted">Select a listing to map</div>
                        </div>
                    </div>
                    <span class="lz-badge lz-badge-danger">0 items</span>
                </div>
                <div class="lz-card-body p-3">
                    <div class="mb-3">
                        <div class="input-group">
                            <span class="input-group-text bg-white border-end-0" style="border:1px solid #e2e8f0;border-radius:8px 0 0 8px;">
                                <i class="fa-solid fa-magnifying-glass text-muted"></i>
                            </span>
                            <input type="text" class="form-control border-start-0" placeholder="Search Lazada listings..."
                                   style="border-radius:0 8px 8px 0;border:1px solid #e2e8f0;" disabled>
                        </div>
                    </div>
                    <!-- Empty State -->
                    <div class="text-center py-5">
                        <div style="width:70px;height:70px;background:var(--lz-danger-bg);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 1rem;">
                            <i class="fa-solid fa-link-slash" style="font-size:1.6rem;color:var(--lz-danger);"></i>
                        </div>
                        <div class="fw-600 mb-1" style="color:var(--lazada-primary)">No Listings Available</div>
                        <div class="small text-muted">Connect your Lazada API to load product listings</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right: Local ERP Items -->
        <div class="col-lg-6">
            <div class="lz-card" style="animation-delay:0.1s">
                <div class="lz-card-header d-flex align-items-center justify-content-between">
                    <div class="d-flex align-items-center gap-2">
                        <div class="lz-icon-box bg-success" style="width:36px;height:36px;font-size:.95rem;border-radius:10px;">
                            <i class="fa-solid fa-boxes-stacked"></i>
                        </div>
                        <div>
                            <div class="fw-700" style="font-size:.92rem;color:var(--lazada-primary)">ERP Inventory Items</div>
                            <div class="small text-muted">Select item to link</div>
                        </div>
                    </div>
                    <span class="lz-badge lz-badge-success">Ready</span>
                </div>
                <div class="lz-card-body p-3">
                    <div class="mb-3">
                        <div class="input-group">
                            <span class="input-group-text bg-white border-end-0" style="border:1px solid #e2e8f0;border-radius:8px 0 0 8px;">
                                <i class="fa-solid fa-magnifying-glass text-muted"></i>
                            </span>
                            <input type="text" class="form-control border-start-0" placeholder="Search ERP products..."
                                   style="border-radius:0 8px 8px 0;border:1px solid #e2e8f0;" disabled>
                        </div>
                    </div>
                    <!-- Empty State -->
                    <div class="text-center py-5">
                        <div style="width:70px;height:70px;background:var(--lz-info-bg);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 1rem;">
                            <i class="fa-solid fa-boxes-stacked" style="font-size:1.6rem;color:var(--lz-info);"></i>
                        </div>
                        <div class="fw-600 mb-1" style="color:var(--lazada-primary)">Select a Lazada listing first</div>
                        <div class="small text-muted">Then search and pick the matching ERP product</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Bottom: Existing Mappings -->
        <div class="col-12">
            <div class="lz-card" style="animation-delay:0.15s">
                <div class="lz-card-header d-flex align-items-center justify-content-between">
                    <div class="d-flex align-items-center gap-2">
                        <div class="lz-icon-box bg-blue" style="width:36px;height:36px;font-size:.95rem;border-radius:10px;">
                            <i class="fa-solid fa-link"></i>
                        </div>
                        <div>
                            <div class="fw-700" style="font-size:.92rem;color:var(--lazada-primary)">Existing Mappings</div>
                            <div class="small text-muted">Lazada listings already linked to ERP</div>
                        </div>
                    </div>
                    <span class="lz-badge lz-badge-primary">0 mappings</span>
                </div>
                <div class="lz-card-body p-4 text-center">
                    <div style="width:70px;height:70px;background:var(--lazada-light);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 1rem;">
                        <i class="fa-solid fa-link" style="font-size:1.6rem;color:var(--lazada-primary);"></i>
                    </div>
                    <div class="fw-600 mb-1" style="color:var(--lazada-primary)">No Mappings Yet</div>
                    <div class="small text-muted">Mapped products will appear here with their sync status</div>
                </div>
            </div>
        </div>
    </div>

</div>

<?php require_once '../../includes/footer.php'; ?>

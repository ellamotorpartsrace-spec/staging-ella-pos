<?php
// views/lazada/products.php — Lazada Products (UI Only)
$page_title = 'Lazada Sync — Products';
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
                <li class="breadcrumb-item active text-white">Products</li>
            </ol>
        </nav>
        <div class="d-flex flex-wrap justify-content-between align-items-end gap-3" style="position:relative;z-index:2;">
            <div>
                <h1 class="lz-title mb-1"><i class="fa-solid fa-bag-shopping me-2" style="font-size:1.5rem;opacity:.9;"></i>Lazada Products</h1>
                <p class="lz-subtitle mb-0">Browse and manage your Lazada product listings</p>
            </div>
            <div class="d-flex gap-2 align-items-center">
                <?php include 'account_switcher.php'; ?>
                <button class="btn-outline-lazada" id="btnRefreshProducts" disabled>
                    <i class="fa-solid fa-rotate me-1"></i> Sync from Lazada
                </button>
            </div>
        </div>
    </div>

    <!-- Filters Bar -->
    <div class="lz-card mb-4" style="animation-delay:0.05s">
        <div class="lz-card-body p-3">
            <div class="row g-2 align-items-center">
                <div class="col-md-4">
                    <div class="input-group">
                        <span class="input-group-text bg-white border-end-0" style="border:1px solid #e2e8f0;border-radius:8px 0 0 8px;">
                            <i class="fa-solid fa-magnifying-glass text-muted"></i>
                        </span>
                        <input type="text" class="form-control border-start-0" id="searchProducts"
                               placeholder="Search products, SKU..." style="border-radius:0 8px 8px 0;border:1px solid #e2e8f0;" disabled>
                    </div>
                </div>
                <div class="col-md-2">
                    <select class="form-select" style="border-radius:8px;border:1px solid #e2e8f0;" disabled>
                        <option>All Status</option>
                        <option>Active</option>
                        <option>Inactive</option>
                        <option>Out of Stock</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <select class="form-select" style="border-radius:8px;border:1px solid #e2e8f0;" disabled>
                        <option>All Categories</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <select class="form-select" style="border-radius:8px;border:1px solid #e2e8f0;" disabled>
                        <option>Mapping: All</option>
                        <option>Mapped</option>
                        <option>Unmapped</option>
                    </select>
                </div>
                <div class="col-md-2 text-end">
                    <span class="small text-muted" id="productCount">No API connection</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Empty State / Coming Soon -->
    <div class="lz-card" style="animation-delay:0.1s">
        <div class="lz-card-body p-5 text-center">
            <div style="width:90px;height:90px;background:var(--lazada-light);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 1.5rem;">
                <i class="fa-solid fa-bag-shopping" style="font-size:2.2rem;color:var(--lazada-primary);"></i>
            </div>
            <h5 class="fw-bold mb-2" style="color:var(--lazada-primary)">No Products Loaded</h5>
            <p class="text-muted mb-3" style="max-width:420px;margin:0 auto;">
                Product listings will appear here once your Lazada API credentials are configured and a sync is performed.
            </p>
            <a href="<?= BASE_URL ?>views/lazada/settings.php" class="btn-lazada">
                <i class="fa-solid fa-plug me-2"></i> Configure API Settings
            </a>
        </div>

    </div>

</div>

<?php require_once '../../includes/footer.php'; ?>

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
    <div class="lz-hero-header mb-4" style="padding: 2rem 2.5rem; color: white;">
        <!-- Breadcrumb inside -->
        <nav aria-label="breadcrumb" style="position: relative; z-index: 2;">
            <ol class="breadcrumb mb-3" style="font-size: 0.85rem;">
                <li class="breadcrumb-item"><a href="<?= BASE_URL ?>views/lazada/index.php" style="color: rgba(255,255,255,0.7); text-decoration: none;">Lazada Dashboard</a></li>
                <li class="breadcrumb-item active" style="color: white; font-weight: 500;">Product Catalog</li>
            </ol>
        </nav>
        
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3" style="position: relative; z-index: 2;">
            <div class="d-flex align-items-center gap-3">
                <div style="background: white; border-radius: 14px; width: 64px; height: 64px; display: flex; align-items: center; justify-content: center; box-shadow: 0 4px 15px rgba(0,0,0,0.1);">
                    <i class="fa-solid fa-bag-shopping" style="color: var(--lazada-primary); font-size: 1.8rem;"></i>
                </div>
                <div>
                    <h1 class="mb-1 fw-bolder" style="font-size: 2rem; letter-spacing: -0.5px;">Lazada Catalog</h1>
                    <p class="mb-0" style="color: rgba(255,255,255,0.8); font-size: 0.95rem;">Live product data fetched directly from your Lazada store.</p>
                </div>
            </div>
            <div class="d-flex gap-2 align-items-center">
                <?php include 'account_switcher.php'; ?>
                <button class="btn btn-light fw-bold px-4 rounded-pill d-flex align-items-center" id="btnRefreshProducts" disabled style="color: var(--lazada-primary); height: 42px; box-shadow: 0 4px 10px rgba(0,0,0,0.15);">
                    <i class="fa-solid fa-rotate me-2"></i> Sync Products
                </button>
            </div>
        </div>
        <!-- Decorative bg -->
        <div style="position: absolute; top: -50px; right: -50px; width: 300px; height: 300px; background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, rgba(255,255,255,0) 70%); border-radius: 50%; z-index: 1;"></div>
    </div>

    <!-- Stat Cards -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-3">
            <div class="card border-0 rounded-4" style="border-bottom: 4px solid #cbd5e1 !important; box-shadow: 0 4px 15px rgba(0,0,0,0.05);">
                <div class="card-body p-3 d-flex align-items-center">
                    <div style="background: #f1f5f9; border-radius: 12px; width: 48px; height: 48px; display: flex; align-items: center; justify-content: center; margin-right: 15px;">
                        <i class="fa-solid fa-box" style="color: #111827; font-size: 1.25rem;"></i>
                    </div>
                    <div>
                        <div style="font-size: 0.7rem; font-weight: 700; color: #64748b; letter-spacing: 0.5px; text-transform: uppercase;">Total Products</div>
                        <div style="font-size: 1.5rem; font-weight: 800; color: #1e293b; line-height: 1.2;" id="lzTotalProducts">—</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card border-0 rounded-4" style="border-bottom: 4px solid #cbd5e1 !important; box-shadow: 0 4px 15px rgba(0,0,0,0.05);">
                <div class="card-body p-3 d-flex align-items-center">
                    <div style="background: #f1f5f9; border-radius: 12px; width: 48px; height: 48px; display: flex; align-items: center; justify-content: center; margin-right: 15px;">
                        <i class="fa-solid fa-layer-group" style="color: #111827; font-size: 1.25rem;"></i>
                    </div>
                    <div>
                        <div style="font-size: 0.7rem; font-weight: 700; color: #64748b; letter-spacing: 0.5px; text-transform: uppercase;">Total Variations</div>
                        <div style="font-size: 1.5rem; font-weight: 800; color: #1e293b; line-height: 1.2;" id="lzTotalVariations">—</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card border-0 rounded-4" style="border-bottom: 4px solid #10b981 !important; box-shadow: 0 4px 15px rgba(0,0,0,0.05);">
                <div class="card-body p-3 d-flex align-items-center">
                    <div style="background: #ecfdf5; border-radius: 12px; width: 48px; height: 48px; display: flex; align-items: center; justify-content: center; margin-right: 15px;">
                        <i class="fa-solid fa-circle-check" style="color: #10b981; font-size: 1.25rem;"></i>
                    </div>
                    <div>
                        <div style="font-size: 0.7rem; font-weight: 700; color: #64748b; letter-spacing: 0.5px; text-transform: uppercase;">In Stock</div>
                        <div style="font-size: 1.5rem; font-weight: 800; color: #1e293b; line-height: 1.2;" id="lzInStock">—</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card border-0 rounded-4" style="border-bottom: 4px solid #ef4444 !important; box-shadow: 0 4px 15px rgba(0,0,0,0.05);">
                <div class="card-body p-3 d-flex align-items-center">
                    <div style="background: #fef2f2; border-radius: 12px; width: 48px; height: 48px; display: flex; align-items: center; justify-content: center; margin-right: 15px;">
                        <i class="fa-solid fa-box-open" style="color: #ef4444; font-size: 1.25rem;"></i>
                    </div>
                    <div>
                        <div style="font-size: 0.7rem; font-weight: 700; color: #64748b; letter-spacing: 0.5px; text-transform: uppercase;">Out of Stock</div>
                        <div style="font-size: 1.5rem; font-weight: 800; color: #ef4444; line-height: 1.2;" id="lzOos">—</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters Bar -->
    <div class="card border-0 rounded-4 mb-4" style="box-shadow: 0 4px 15px rgba(0,0,0,0.03);">
        <div class="card-body p-3 d-flex align-items-center gap-3 flex-wrap">
            <div class="input-group" style="width: 320px; background: #f8fafc; border-radius: 8px; border: 1px solid #e2e8f0; overflow: hidden;">
                <span class="input-group-text bg-transparent border-0"><i class="fa-solid fa-magnifying-glass text-muted"></i></span>
                <input type="text" class="form-control bg-transparent border-0 shadow-none" id="searchProducts" placeholder="Search by product name or SKU..." disabled style="font-size: 0.9rem;">
            </div>
            
            <button class="btn rounded-pill fw-bold px-4" style="background: #eef2ff; color: #111827; font-size: 0.85rem;" disabled>All</button>
            <button class="btn btn-link text-decoration-none fw-bold px-3" style="color: #64748b; font-size: 0.85rem;" disabled><i class="fa-solid fa-circle-check text-success me-1"></i> In Stock</button>
            <button class="btn btn-link text-decoration-none fw-bold px-3" style="color: #64748b; font-size: 0.85rem;" disabled><i class="fa-solid fa-triangle-exclamation text-warning me-1"></i> Low Stock</button>
            <button class="btn btn-link text-decoration-none fw-bold px-3" style="color: #64748b; font-size: 0.85rem;" disabled><i class="fa-solid fa-box-open text-danger me-1"></i> Out of Stock</button>
            
            <div class="ms-auto d-flex gap-2">
                <select class="form-select border-0 bg-light fw-600 shadow-none" style="border-radius:8px; font-size: 0.85rem;" disabled>
                    <option>All Categories</option>
                </select>
                <select class="form-select border-0 bg-light fw-600 shadow-none" style="border-radius:8px; font-size: 0.85rem;" disabled>
                    <option>Mapping: All</option>
                    <option>Mapped</option>
                    <option>Unmapped</option>
                </select>
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

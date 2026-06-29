<?php
$page_title = 'Lazada Sync — Resolution Center';
require_once '../../config/config.php';
require_once '../../includes/auth.php';
requirePermission('lazada_mapping');
require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';

$db = new Database();
$conn = $db->getConnection();
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/lazada-sync.css?v=<?= filemtime(__DIR__.'/../../assets/css/lazada-sync.css') ?>">

<div class="lz-page lz-animate">
    <?php require_once __DIR__ . '/lazada_token_warning.php'; ?>
    
    <!-- Hero Header -->
    <div class="lz-hero-header mb-4" style="background: linear-gradient(135deg, #ef4444 0%, #b91c1c 100%);">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
            <div style="position:relative;z-index:2;">
                <div class="d-flex align-items-center gap-3 mb-2">
                    <div style="width:52px;height:52px;border-radius:14px;background:#ffffff;display:flex;align-items:center;justify-content:center;box-shadow:0 4px 15px rgba(0,0,0,0.1);">
                        <i class="fa-solid fa-triangle-exclamation fa-lg" style="color: #ef4444;"></i>
                    </div>
                    <div>
                        <h2 class="mb-0 text-white fw-bold" style="letter-spacing:-0.5px;">Resolution Center</h2>
                        <div class="text-white text-opacity-75 small">Manage sync errors, missing SKUs, and disputes</div>
                    </div>
                </div>
            </div>
            <div style="position:relative;z-index:2;">
                <a href="<?= BASE_URL ?>views/lazada/index.php" class="btn btn-light rounded-pill px-4 fw-bold shadow-sm">
                    <i class="fa-solid fa-arrow-left me-2"></i> Back to Dashboard
                </a>
            </div>
        </div>
    </div>

    <!-- Navigation Pills -->
    <div class="d-flex flex-wrap gap-2 mb-4">
        <button class="lz-pill active" style="background: #ef4444; color: white; border-color: #ef4444;">
            <i class="fa-solid fa-barcode"></i> Missing SKUs
            <span class="filter-count" style="background: rgba(255,255,255,0.3);">3</span>
        </button>
        <button class="lz-pill">
            <i class="fa-solid fa-box-open"></i> Out of Stock
            <span class="filter-count">0</span>
        </button>
        <button class="lz-pill">
            <i class="fa-solid fa-triangle-exclamation"></i> Sync Failures
            <span class="filter-count">0</span>
        </button>
    </div>

    <div class="row">
        <div class="col-12">
            <div class="lz-card">
                <div class="lz-card-header d-flex justify-content-between align-items-center">
                    <div>
                        <h5 class="mb-0 fw-bolder" style="color: var(--text-primary);">Action Required: Missing SKUs</h5>
                        <div class="small text-muted mt-1">The following Lazada items cannot be synced because they do not have a corresponding local SKU. Please add the local SKU manually.</div>
                    </div>
                </div>
                
                <div class="lz-card-body p-0">
                    <div class="table-responsive">
                        <table class="table lz-table mb-0 align-middle">
                            <thead>
                                <tr>
                                    <th>Lazada Item</th>
                                    <th width="150">Lazada ID</th>
                                    <th width="120">Price</th>
                                    <th width="350">Resolve (Add SKU)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <!-- Mock Data Row 1 -->
                                <tr>
                                    <td>
                                        <div class="d-flex align-items-center gap-3">
                                            <div style="width: 48px; height: 48px; border-radius: 8px; background: #f1f5f9; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                                                <i class="fa-solid fa-image text-muted"></i>
                                            </div>
                                            <div>
                                                <div class="fw-bold" style="color: var(--text-primary);">Honda Click 125i CVT Belt Genuine</div>
                                                <div class="small text-muted mt-1"><i class="fa-solid fa-shop me-1"></i>Ella Motor Parts</div>
                                            </div>
                                        </div>
                                    </td>
                                    <td><span class="badge bg-light text-dark font-monospace border">LZ-882910</span></td>
                                    <td class="fw-bold">₱ 850.00</td>
                                    <td>
                                        <div class="input-group">
                                            <input type="text" class="form-control" placeholder="Type local SKU..." style="font-size: 0.9rem; border-color: #e2e8f0; height: 42px;">
                                            <button class="btn btn-primary fw-bold px-3 shadow-sm" type="button" style="background: var(--lazada-primary); border-color: var(--lazada-primary); height: 42px;">
                                                <i class="fa-solid fa-plus me-1"></i> Add SKU
                                            </button>
                                        </div>
                                    </td>
                                </tr>

                                <!-- Mock Data Row 2 -->
                                <tr>
                                    <td>
                                        <div class="d-flex align-items-center gap-3">
                                            <div style="width: 48px; height: 48px; border-radius: 8px; background: #f1f5f9; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                                                <i class="fa-solid fa-image text-muted"></i>
                                            </div>
                                            <div>
                                                <div class="fw-bold" style="color: var(--text-primary);">Yamaha NMAX 155 Brake Pad Front</div>
                                                <div class="small text-muted mt-1"><i class="fa-solid fa-shop me-1"></i>Ella Motor Parts</div>
                                            </div>
                                        </div>
                                    </td>
                                    <td><span class="badge bg-light text-dark font-monospace border">LZ-441299</span></td>
                                    <td class="fw-bold">₱ 350.00</td>
                                    <td>
                                        <div class="input-group">
                                            <input type="text" class="form-control" placeholder="Type local SKU..." style="font-size: 0.9rem; border-color: #e2e8f0; height: 42px;">
                                            <button class="btn btn-primary fw-bold px-3 shadow-sm" type="button" style="background: var(--lazada-primary); border-color: var(--lazada-primary); height: 42px;">
                                                <i class="fa-solid fa-plus me-1"></i> Add SKU
                                            </button>
                                        </div>
                                    </td>
                                </tr>

                                <!-- Mock Data Row 3 -->
                                <tr>
                                    <td>
                                        <div class="d-flex align-items-center gap-3">
                                            <div style="width: 48px; height: 48px; border-radius: 8px; background: #f1f5f9; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                                                <i class="fa-solid fa-image text-muted"></i>
                                            </div>
                                            <div>
                                                <div class="fw-bold" style="color: var(--text-primary);">Honda Beat Fi Air Filter Element</div>
                                                <div class="small text-muted mt-1"><i class="fa-solid fa-shop me-1"></i>Ella Motor Parts</div>
                                            </div>
                                        </div>
                                    </td>
                                    <td><span class="badge bg-light text-dark font-monospace border">LZ-110293</span></td>
                                    <td class="fw-bold">₱ 220.00</td>
                                    <td>
                                        <div class="input-group">
                                            <input type="text" class="form-control" placeholder="Type local SKU..." style="font-size: 0.9rem; border-color: #e2e8f0; height: 42px;">
                                            <button class="btn btn-primary fw-bold px-3 shadow-sm" type="button" style="background: var(--lazada-primary); border-color: var(--lazada-primary); height: 42px;">
                                                <i class="fa-solid fa-plus me-1"></i> Add SKU
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>

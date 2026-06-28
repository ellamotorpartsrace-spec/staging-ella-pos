<?php
// views/lazada/allocation.php — Lazada Stock Allocation (UI Only)
$page_title = 'Lazada Sync — Stock Allocation';
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
                <li class="breadcrumb-item active text-white">Stock Allocation</li>
            </ol>
        </nav>
        <div class="d-flex flex-wrap justify-content-between align-items-end gap-3" style="position:relative;z-index:2;">
            <div>
                <h1 class="lz-title mb-1"><i class="fa-solid fa-sliders me-2" style="font-size:1.5rem;opacity:.9;"></i>Stock Allocation</h1>
                <p class="lz-subtitle mb-0">Manage online stock safety limits and allocation rules for Lazada</p>
            </div>
            <div class="d-flex gap-2">
                <button class="btn-outline-lazada" disabled>
                    <i class="fa-solid fa-floppy-disk me-1"></i> Save All Changes
                </button>
            </div>
        </div>
    </div>

    <!-- Info Banner -->
    <div class="lz-alert-box mb-4 d-flex align-items-start gap-3">
        <i class="fa-solid fa-circle-info fa-lg mt-1" style="color:#d97706;flex-shrink:0;"></i>
        <div>
            <strong>Stock Allocation — Integration Required</strong>
            <div class="small mt-1">
                This panel will let you define how much of your ERP stock is reserved for Lazada listings.
                Allocation rules only take effect once the Lazada API integration is connected and enabled in
                <a href="<?= BASE_URL ?>views/lazada/settings.php" class="fw-bold" style="color:#92400e;">Settings</a>.
                No stock will be affected until then.
            </div>
        </div>
    </div>

    <!-- Summary Cards -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="lz-stat-card" style="animation-delay:0.05s">
                <div class="lz-stat-label mb-2">Allocated Items</div>
                <div class="lz-stat-value text-lazada-blue" id="lzAllocTotal">—</div>
                <div class="small text-muted mt-1">Products with rules set</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="lz-stat-card" style="animation-delay:0.1s">
                <div class="lz-stat-label mb-2">Total Reserved</div>
                <div class="lz-stat-value text-success" id="lzAllocReserved">—</div>
                <div class="small text-muted mt-1">Units held for Lazada</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="lz-stat-card" style="animation-delay:0.15s">
                <div class="lz-stat-label mb-2">Below Safety Limit</div>
                <div class="lz-stat-value text-warning" id="lzAllocLow">—</div>
                <div class="small text-muted mt-1">Near threshold</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="lz-stat-card" style="animation-delay:0.2s">
                <div class="lz-stat-label mb-2">Unallocated Items</div>
                <div class="lz-stat-value text-danger" id="lzAllocNone">—</div>
                <div class="small text-muted mt-1">No rule assigned</div>
            </div>
        </div>
    </div>

    <!-- Allocation Rules Table -->
    <div class="lz-card" style="animation-delay:0.25s">
        <div class="lz-card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
            <div class="d-flex align-items-center gap-2">
                <div class="lz-icon-box bg-blue" style="width:36px;height:36px;font-size:.95rem;border-radius:10px;">
                    <i class="fa-solid fa-sliders"></i>
                </div>
                <div>
                    <div class="fw-700" style="font-size:.92rem;color:var(--lazada-primary)">Allocation Rules</div>
                    <div class="small text-muted">Set stock ratio and safety limits per mapped item</div>
                </div>
            </div>
            <div class="d-flex gap-2 flex-wrap">
                <select class="form-select form-select-sm" style="width:auto;border-radius:8px;border:1px solid #e2e8f0;" disabled>
                    <option>All Items</option>
                    <option>Has Rule</option>
                    <option>No Rule</option>
                    <option>Below Safety Limit</option>
                </select>
                <div class="input-group input-group-sm" style="width:220px;">
                    <span class="input-group-text bg-white border-end-0" style="border:1px solid #e2e8f0;border-radius:8px 0 0 8px;">
                        <i class="fa-solid fa-magnifying-glass text-muted" style="font-size:.8rem;"></i>
                    </span>
                    <input type="text" class="form-control border-start-0" placeholder="Search products, SKU..."
                           style="border:1px solid #e2e8f0;border-radius:0 8px 8px 0;" disabled>
                </div>
            </div>
        </div>

        <!-- Empty State -->
        <div class="lz-card-body p-5 text-center">
            <div style="width:90px;height:90px;background:var(--lazada-light);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 1.5rem;">
                <i class="fa-solid fa-sliders" style="font-size:2.2rem;color:var(--lazada-primary);"></i>
            </div>
            <h5 class="fw-bold mb-2" style="color:var(--lazada-primary)">No Allocation Rules Yet</h5>
            <p class="text-muted mb-3" style="max-width:480px;margin:0 auto;">
                Allocation rules will appear here once products are mapped in
                <a href="<?= BASE_URL ?>views/lazada/mapping.php" class="text-lazada-blue fw-600">Product Mapping</a>
                and the Lazada API is configured. Each mapped item can have its own stock ratio and safety floor.
            </p>
            <a href="<?= BASE_URL ?>views/lazada/mapping.php" class="btn-lazada">
                <i class="fa-solid fa-link me-2"></i> Go to Product Mapping
            </a>
        </div>

        <!-- Placeholder Table Preview -->
        <div class="px-3 pb-3" style="opacity:.25;pointer-events:none;">
            <table class="table table-hover align-middle" style="font-size:.85rem;">
                <thead>
                    <tr style="background:#f8fafc;">
                        <th style="padding:.75rem 1rem;font-size:.73rem;text-transform:uppercase;letter-spacing:.05em;font-weight:700;color:var(--lazada-primary);">Product / Variation</th>
                        <th style="padding:.75rem 1rem;font-size:.73rem;text-transform:uppercase;letter-spacing:.05em;font-weight:700;color:var(--lazada-primary);">SKU</th>
                        <th style="padding:.75rem 1rem;font-size:.73rem;text-transform:uppercase;letter-spacing:.05em;font-weight:700;color:var(--lazada-primary);">ERP Stock</th>
                        <th style="padding:.75rem 1rem;font-size:.73rem;text-transform:uppercase;letter-spacing:.05em;font-weight:700;color:var(--lazada-primary);">Allocation Ratio</th>
                        <th style="padding:.75rem 1rem;font-size:.73rem;text-transform:uppercase;letter-spacing:.05em;font-weight:700;color:var(--lazada-primary);">Safety Floor</th>
                        <th style="padding:.75rem 1rem;font-size:.73rem;text-transform:uppercase;letter-spacing:.05em;font-weight:700;color:var(--lazada-primary);">Lazada Stock</th>
                        <th style="padding:.75rem 1rem;font-size:.73rem;text-transform:uppercase;letter-spacing:.05em;font-weight:700;color:var(--lazada-primary);">Status</th>
                        <th style="padding:.75rem 1rem;font-size:.73rem;text-transform:uppercase;letter-spacing:.05em;font-weight:700;color:var(--lazada-primary);">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $samples = [
                        ['Product A — Red',  'SKU-001', 120, '80%', 5,  96,  'ok'],
                        ['Product A — Blue', 'SKU-002',  45, '80%', 5,  36,  'ok'],
                        ['Product B',        'SKU-003',   8, '80%', 5,   2,  'low'],
                        ['Product C — XL',   'SKU-004',   0, '80%', 5,   0,  'oos'],
                        ['Product D',        'SKU-005',  60, '—',   0,   0,  'none'],
                    ];
                    foreach ($samples as $s):
                        $statusBadge = match($s[6]) {
                            'ok'   => '<span class="lz-badge lz-badge-success">OK</span>',
                            'low'  => '<span class="lz-badge lz-badge-warning">Low Stock</span>',
                            'oos'  => '<span class="lz-badge lz-badge-danger">Out of Stock</span>',
                            'none' => '<span class="lz-badge" style="background:#f1f5f9;color:#64748b;">No Rule</span>',
                            default => ''
                        };
                    ?>
                    <tr>
                        <td style="padding:.75rem 1rem;">
                            <div class="fw-600" style="font-size:.88rem;color:#334155;"><?= $s[0] ?></div>
                        </td>
                        <td><span class="lz-badge lz-badge-primary"><?= $s[1] ?></span></td>
                        <td class="fw-bold"><?= $s[2] ?></td>
                        <td>
                            <input type="number" class="form-control form-control-sm text-center"
                                   value="<?= $s[3] === '—' ? '' : rtrim($s[3],'%') ?>" min="0" max="100"
                                   style="width:70px;border-radius:6px;border:1px solid #e2e8f0;" disabled>
                        </td>
                        <td>
                            <input type="number" class="form-control form-control-sm text-center"
                                   value="<?= $s[4] ?>" min="0"
                                   style="width:70px;border-radius:6px;border:1px solid #e2e8f0;" disabled>
                        </td>
                        <td class="fw-bold"><?= $s[5] ?></td>
                        <td><?= $statusBadge ?></td>
                        <td>
                            <button class="btn btn-sm btn-outline-secondary" disabled>
                                <i class="fa-solid fa-floppy-disk"></i>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <div class="text-center mt-1">
                <small class="text-muted fst-italic">Sample layout — allocation rules load once API is connected and products are mapped</small>
            </div>
        </div>
    </div>

</div>

<?php require_once '../../includes/footer.php'; ?>

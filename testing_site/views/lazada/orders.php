<?php
// views/lazada/orders.php — Lazada Orders (UI Only)
$page_title = 'Lazada Sync — Orders';
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
                <li class="breadcrumb-item active text-white">Orders</li>
            </ol>
        </nav>
        <div class="d-flex flex-wrap justify-content-between align-items-end gap-3" style="position:relative;z-index:2;">
            <div>
                <h1 class="lz-title mb-1"><i class="fa-solid fa-boxes-packing me-2" style="font-size:1.5rem;opacity:.9;"></i>Lazada Orders</h1>
                <p class="lz-subtitle mb-0">View and manage incoming Lazada orders</p>
            </div>
            <div class="d-flex gap-2">
                <button class="btn-outline-lazada" disabled>
                    <i class="fa-solid fa-rotate me-1"></i> Refresh Orders
                </button>
            </div>
        </div>
    </div>

    <!-- Order Status Summary -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="lz-stat-card" style="animation-delay:0.05s">
                <div class="lz-stat-label mb-2">Pending</div>
                <div class="lz-stat-value text-warning">—</div>
                <div class="small text-muted mt-1">Awaiting fulfillment</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="lz-stat-card" style="animation-delay:0.1s">
                <div class="lz-stat-label mb-2">Ready to Ship</div>
                <div class="lz-stat-value text-lazada-blue">—</div>
                <div class="small text-muted mt-1">Packed & labeled</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="lz-stat-card" style="animation-delay:0.15s">
                <div class="lz-stat-label mb-2">Shipped</div>
                <div class="lz-stat-value text-success">—</div>
                <div class="small text-muted mt-1">In transit</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="lz-stat-card" style="animation-delay:0.2s">
                <div class="lz-stat-label mb-2">Cancelled</div>
                <div class="lz-stat-value text-danger">—</div>
                <div class="small text-muted mt-1">This month</div>
            </div>
        </div>
    </div>

    <!-- Orders Table -->
    <div class="lz-card" style="animation-delay:0.25s">
        <div class="lz-card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
            <div class="d-flex align-items-center gap-2">
                <div class="lz-icon-box bg-blue" style="width:36px;height:36px;font-size:.95rem;border-radius:10px;">
                    <i class="fa-solid fa-list-check"></i>
                </div>
                <div>
                    <div class="fw-700" style="font-size:.92rem;color:var(--lazada-primary)">Order Queue</div>
                    <div class="small text-muted">All Lazada orders</div>
                </div>
            </div>
            <div class="d-flex gap-2 flex-wrap">
                <select class="form-select form-select-sm" style="width:auto;border-radius:8px;border:1px solid #e2e8f0;" disabled>
                    <option>All Status</option>
                    <option>Pending</option>
                    <option>Ready to Ship</option>
                    <option>Shipped</option>
                    <option>Cancelled</option>
                </select>
                <div class="input-group input-group-sm" style="width:220px;">
                    <span class="input-group-text bg-white border-end-0" style="border:1px solid #e2e8f0;border-radius:8px 0 0 8px;">
                        <i class="fa-solid fa-magnifying-glass text-muted" style="font-size:.8rem;"></i>
                    </span>
                    <input type="text" class="form-control border-start-0" placeholder="Search orders..." style="border:1px solid #e2e8f0;border-radius:0 8px 8px 0;" disabled>
                </div>
            </div>
        </div>

        <!-- Empty State -->
        <div class="lz-card-body p-5 text-center">
            <div style="width:90px;height:90px;background:var(--lazada-light);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 1.5rem;">
                <i class="fa-solid fa-boxes-packing" style="font-size:2.2rem;color:var(--lazada-primary);"></i>
            </div>
            <h5 class="fw-bold mb-2" style="color:var(--lazada-primary)">No Orders Available</h5>
            <p class="text-muted mb-3" style="max-width:420px;margin:0 auto;">
                Order data will load here once your Lazada API is connected and access tokens are configured.
            </p>
            <a href="<?= BASE_URL ?>views/lazada/settings.php" class="btn-lazada">
                <i class="fa-solid fa-plug me-2"></i> Configure API Settings
            </a>
        </div>

        <!-- Placeholder Table Preview -->
        <div class="px-3 pb-3" style="opacity:.25;pointer-events:none;">
            <table class="table table-hover align-middle" style="font-size:.85rem;">
                <thead>
                    <tr style="background:#f8fafc;">
                        <th style="padding:.75rem 1rem;font-size:.75rem;text-transform:uppercase;letter-spacing:.05em;font-weight:700;color:var(--lazada-primary);">Order ID</th>
                        <th style="padding:.75rem 1rem;font-size:.75rem;text-transform:uppercase;letter-spacing:.05em;font-weight:700;color:var(--lazada-primary);">Customer</th>
                        <th style="padding:.75rem 1rem;font-size:.75rem;text-transform:uppercase;letter-spacing:.05em;font-weight:700;color:var(--lazada-primary);">Items</th>
                        <th style="padding:.75rem 1rem;font-size:.75rem;text-transform:uppercase;letter-spacing:.05em;font-weight:700;color:var(--lazada-primary);">Total</th>
                        <th style="padding:.75rem 1rem;font-size:.75rem;text-transform:uppercase;letter-spacing:.05em;font-weight:700;color:var(--lazada-primary);">Status</th>
                        <th style="padding:.75rem 1rem;font-size:.75rem;text-transform:uppercase;letter-spacing:.05em;font-weight:700;color:var(--lazada-primary);">Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php for($i = 1; $i <= 4; $i++): ?>
                    <tr>
                        <td style="padding:.75rem 1rem;"><span class="lz-badge lz-badge-primary">#LZD-00<?= $i ?></span></td>
                        <td>Customer Name</td>
                        <td>1 item</td>
                        <td>₱0.00</td>
                        <td><span class="lz-badge lz-badge-warning">Pending</span></td>
                        <td>Jun 28, 2026</td>
                    </tr>
                    <?php endfor; ?>
                </tbody>
            </table>
            <div class="text-center">
                <small class="text-muted fst-italic">Sample layout — data loads once API is connected</small>
            </div>
        </div>
    </div>

</div>

<?php require_once '../../includes/footer.php'; ?>

<?php
// views/lazada/logs.php — Lazada Sync Logs (UI Only)
$page_title = 'Lazada Sync — Sync Logs';
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
                <li class="breadcrumb-item active text-white">Sync Logs</li>
            </ol>
        </nav>
        <div style="position:relative;z-index:2;">
            <h1 class="lz-title mb-1"><i class="fa-solid fa-clock-rotate-left me-2" style="font-size:1.5rem;opacity:.9;"></i>Lazada Sync Logs</h1>
            <p class="lz-subtitle mb-0">Review all stock updates and sync events triggered by the Lazada integration</p>
        </div>
    </div>

    <!-- Filter Bar -->
    <div class="lz-card mb-4" style="animation-delay:0.05s">
        <div class="lz-card-body p-3">
            <div class="row g-2 align-items-center">
                <div class="col-md-3">
                    <select class="form-select" style="border-radius:8px;border:1px solid #e2e8f0;" disabled>
                        <option>All Log Types</option>
                        <option>Stock Update</option>
                        <option>Order Received</option>
                        <option>Error</option>
                        <option>Mapping Change</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <select class="form-select" style="border-radius:8px;border:1px solid #e2e8f0;" disabled>
                        <option>All Results</option>
                        <option>Success</option>
                        <option>Failed</option>
                        <option>Warning</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <div class="input-group">
                        <span class="input-group-text bg-white border-end-0" style="border:1px solid #e2e8f0;border-radius:8px 0 0 8px;">
                            <i class="fa-solid fa-magnifying-glass text-muted"></i>
                        </span>
                        <input type="text" class="form-control border-start-0" placeholder="Search logs..."
                               style="border:1px solid #e2e8f0;border-radius:0 8px 8px 0;" disabled>
                    </div>
                </div>
                <div class="col-md-2">
                    <input type="date" class="form-control" style="border-radius:8px;border:1px solid #e2e8f0;" disabled>
                </div>
                <div class="col-md-2 text-end">
                    <button class="btn btn-sm btn-outline-secondary" disabled>
                        <i class="fa-solid fa-download me-1"></i> Export
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Log Timeline -->
    <div class="lz-card" style="animation-delay:0.1s">
        <div class="lz-card-header d-flex align-items-center gap-2">
            <div class="lz-icon-box bg-blue" style="width:36px;height:36px;font-size:.95rem;border-radius:10px;">
                <i class="fa-solid fa-clock-rotate-left"></i>
            </div>
            <div>
                <div class="fw-700" style="font-size:.92rem;color:var(--lazada-primary)">Activity Log</div>
                <div class="small text-muted">All sync events in chronological order</div>
            </div>
        </div>
        <div class="lz-card-body p-5 text-center">
            <div style="width:90px;height:90px;background:var(--lazada-light);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 1.5rem;">
                <i class="fa-solid fa-clock-rotate-left" style="font-size:2.2rem;color:var(--lazada-primary);"></i>
            </div>
            <h5 class="fw-bold mb-2" style="color:var(--lazada-primary)">No Log Entries</h5>
            <p class="text-muted mb-3" style="max-width:420px;margin:0 auto;">
                Sync events will be recorded here automatically once the Lazada integration is active. All stock updates, order events, and errors will appear in this log.
            </p>
            <a href="<?= BASE_URL ?>views/lazada/settings.php" class="btn-lazada">
                <i class="fa-solid fa-plug me-2"></i> Activate Integration
            </a>
        </div>

        <!-- Placeholder Timeline Preview -->
        <div class="p-4" style="opacity:.25;pointer-events:none;">
            <div class="lz-timeline">
                <?php
                $sampleLogs = [
                    ['dot-success', 'fa-rotate', 'Stock Synced', 'SKU-001 stock updated: 50 → 48', '2 min ago'],
                    ['dot-info', 'fa-boxes-packing', 'Order Received', 'Order #LZD-1234 received — 1 item', '15 min ago'],
                    ['dot-warning', 'fa-triangle-exclamation', 'Low Stock Warning', 'SKU-007 dropped below safety threshold', '1 hr ago'],
                    ['dot-danger', 'fa-circle-xmark', 'Sync Error', 'Failed to update SKU-009 — product not found', '3 hr ago'],
                    ['dot-success', 'fa-rotate', 'Stock Synced', 'Batch update: 12 items synced', '6 hr ago'],
                ];
                foreach ($sampleLogs as $log):
                ?>
                <div class="lz-timeline-item">
                    <div class="lz-timeline-dot <?= $log[0] ?>"></div>
                    <div class="lz-timeline-content">
                        <div class="d-flex justify-content-between align-items-start">
                            <div class="d-flex align-items-center gap-2">
                                <i class="fa-solid <?= $log[1] ?> small text-muted"></i>
                                <span class="fw-700" style="font-size:.88rem;"><?= $log[2] ?></span>
                            </div>
                            <span class="small text-muted"><?= $log[4] ?></span>
                        </div>
                        <div class="small text-muted mt-1"><?= $log[3] ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <div class="text-center mt-3">
                <small class="text-muted fst-italic">Sample layout — actual logs appear once integration is active</small>
            </div>
        </div>
    </div>

</div>

<?php require_once '../../includes/footer.php'; ?>

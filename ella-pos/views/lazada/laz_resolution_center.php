<?php
// views/lazada/laz_resolution_center.php
$page_title = 'Lazada Sync — Resolution Center';
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
requireRole(['admin', 'super_admin']);

$db = new Database();
$conn = $db->getConnection();

// --- 1. Pagination & Filter Configuration ---
$items_per_page = isset($_GET['limit']) ? filter_var($_GET['limit'], FILTER_VALIDATE_INT) : 10;
if (!$items_per_page || $items_per_page < 1) $items_per_page = 10;

$page_num = isset($_GET['page']) ? filter_var($_GET['page'], FILTER_VALIDATE_INT) : 1;
if (!$page_num || $page_num < 1) $page_num = 1;

$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';
$type_filter = isset($_GET['type']) ? trim($_GET['type']) : '';

$where_clause = "status = 'open'";
$params = [];

if ($search_query !== '') {
    $words = preg_split('/\s+/', $search_query);
    foreach ($words as $word) {
        $word = trim($word);
        if ($word === '') continue;
        $where_clause .= " AND (sku LIKE ? OR error_message LIKE ? OR lazada_item_id LIKE ? OR lazada_model_id LIKE ?)";
        $like = "%$word%";
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }
}

if ($type_filter !== '') {
    $where_clause .= " AND error_type = ?";
    $params[] = $type_filter;
}

// --- 2. Fetch global counts for KPI Cards ---
$statsStmt = $conn->query("SELECT error_type, COUNT(*) as cnt FROM lazada_error_logs WHERE status = 'open' GROUP BY error_type");
$statsRows = $statsStmt->fetchAll();

$missingCount = 0;
$unmatchedCount = 0;
$otherOpenCount = 0;

foreach ($statsRows as $row) {
    if ($row['error_type'] === 'missing_sku') {
        $missingCount += $row['cnt'];
    } elseif ($row['error_type'] === 'unmapped') {
        $unmatchedCount += $row['cnt'];
    } elseif ($row['error_type'] !== 'duplicate_sku') {
        $otherOpenCount += $row['cnt'];
    }
}

// Calculate unique duplicate SKU count globally
$dupStmt = $conn->query("SELECT COUNT(DISTINCT sku) FROM lazada_error_logs WHERE status = 'open' AND error_type = 'duplicate_sku'");
$duplicateCount = (int)$dupStmt->fetchColumn();

$totalOpen = $missingCount + $duplicateCount + $unmatchedCount + $otherOpenCount;

// Get resolved issues count
$resolvedStmt = $conn->query("SELECT COUNT(*) FROM lazada_error_logs WHERE status = 'resolved'");
$resolvedCount = (int)$resolvedStmt->fetchColumn();

// Calculate paginated bounds
$countStmt = $conn->prepare("SELECT COUNT(*) FROM lazada_error_logs WHERE $where_clause");
$countStmt->execute($params);
$total_filtered = $countStmt->fetchColumn();

$total_pages = ceil($total_filtered / $items_per_page);
if ($total_pages < 1) $total_pages = 1;
if ($page_num > $total_pages) $page_num = $total_pages;

$offset = ($page_num - 1) * $items_per_page;

// Fetch filtered and paginated open errors with mapping details
$query = "
    SELECT e.*, 
           m.lazada_product_name, 
           m.lazada_variation_name
    FROM lazada_error_logs e
    LEFT JOIN lazada_product_mappings m 
      ON e.lazada_item_id = m.lazada_item_id 
      AND (
          (e.lazada_model_id IS NOT NULL AND e.lazada_model_id != 0 AND e.lazada_model_id = m.lazada_model_id)
          OR
          ((e.lazada_model_id IS NULL OR e.lazada_model_id = 0) AND (m.lazada_model_id IS NULL OR m.lazada_model_id = 0))
      )
    WHERE e.$where_clause 
    ORDER BY e.created_at DESC 
    LIMIT $items_per_page OFFSET $offset
";
$stmt = $conn->prepare($query);
$stmt->execute($params);
$errors = $stmt->fetchAll();

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/lazada-sync.css?v=<?= filemtime(__DIR__ . '/../../assets/css/lazada-sync.css') ?>">
<style>
.style-option-card {
    border: 1px solid var(--border-color);
    background: var(--bg-surface);
    transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
}
.style-option-card:hover {
    background: var(--bg-body);
    border-color: var(--lazada-primary) !important;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.04);
}
.style-option-card.selected {
    background: var(--lazada-light) !important;
    border-color: var(--lazada-primary) !important;
    box-shadow: 0 4px 16px rgba(238, 77, 45, 0.08);
}
.bg-success-light {
    background-color: rgba(40, 167, 69, 0.08) !important;
}
.bg-info-light {
    background-color: rgba(23, 162, 184, 0.08) !important;
}
.bg-warning-light {
    background-color: rgba(255, 193, 7, 0.08) !important;
}
</style>

<div class="lz-animate" style="background: #f4f7fb; min-height: calc(100vh - 60px); padding-bottom: 3rem;">
    <?php require_once __DIR__ . '/laz_token_warning.php'; ?>

    <!-- Premium Hero Banner -->
    <div class="position-relative overflow-hidden" style="background: linear-gradient(135deg, #0f136d 0%, #1a237e 100%); padding: 2.5rem 2rem; border-radius: 0 0 24px 24px; box-shadow: 0 10px 30px rgba(15, 19, 109, 0.15); margin-bottom: 2rem;">
        <!-- Decorative Background Graphic -->
        <i class="fa-solid fa-triangle-exclamation position-absolute text-white" style="font-size: 16rem; opacity: 0.04; right: -2%; top: -15%;"></i>
        
        <div class="container-fluid px-4 position-relative" style="z-index: 2;">
            <div class="d-flex justify-content-between align-items-end flex-wrap gap-3">
                <div>
                    <div class="d-flex align-items-center gap-2 mb-3" style="font-size: 0.9rem;">
                        <a href="<?= BASE_URL ?>views/lazada/laz_index.php" style="color: rgba(255, 255, 255, 0.7); text-decoration: none; font-weight: 500; transition: color 0.2s;" onmouseover="this.style.color='white'" onmouseout="this.style.color='rgba(255,255,255,0.7)'">Lazada Dashboard</a>
                        <i class="fa-solid fa-chevron-right text-white" style="font-size: 0.6rem; opacity: 0.7;"></i>
                        <span class="text-white" style="opacity: 0.9; font-weight: 500;">Resolution Center</span>
                    </div>
                    
                    <div class="d-flex align-items-center gap-3">
                        <div class="d-flex align-items-center justify-content-center bg-white shadow-sm" style="width: 60px; height: 60px; border-radius: 16px; font-size: 1.8rem; color: var(--lz-danger);">
                            <i class="fa-solid fa-triangle-exclamation"></i>
                        </div>
                        <div>
                            <h1 class="fw-bold mb-0 text-white" style="font-size: 2.2rem; letter-spacing: -0.5px;">
                                Error Resolution Center
                            </h1>
                            <p class="mb-0 mt-1 text-white" style="opacity: 0.85; font-size: 1.05rem;">Detect and fix SKU conflicts, mapping errors, and sync failures.</p>
                        </div>
                    </div>
                </div>
                
                <div class="d-flex gap-2">
                    <button class="btn bg-white shadow-sm fw-bold px-4 py-2 d-flex align-items-center gap-2" style="border-radius: 12px; font-size: 1rem; color: var(--lz-danger); border: 1px solid rgba(255,255,255,0.8); transition: transform 0.2s;" onclick="startQuickScan(this)" onmouseover="this.style.transform='translateY(-2px)'" onmouseout="this.style.transform='translateY(0)'">
                        <i class="fa-solid fa-radar"></i>Scan for Conflicts
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="container-fluid px-4">
        <!-- Premium KPI Cards -->
        <div class="row g-4 mb-4">
            <div class="col-xl col-md-4 col-sm-6">
                <div class="bg-white p-4 h-100" style="border-radius: 16px; box-shadow: 0 4px 15px rgba(0,0,0,0.03); border: 1px solid #f1f5f9; border-bottom: 4px solid var(--lz-danger); display: flex; align-items: center; gap: 1.2rem;">
                    <div class="d-flex align-items-center justify-content-center flex-shrink-0" style="width: 54px; height: 54px; border-radius: 14px; background: rgba(220, 53, 69, 0.1); color: var(--lz-danger); font-size: 1.5rem;">
                        <i class="fa-solid fa-triangle-exclamation"></i>
                    </div>
                    <div>
                        <div class="text-uppercase fw-bold" style="font-size: 0.85rem; color: #64748b; letter-spacing: 0.5px;">Total Issues</div>
                        <div class="fw-bold text-danger" style="font-size: 1.8rem; line-height: 1.2;"><?= $totalOpen ?></div>
                    </div>
                </div>
            </div>
            <div class="col-xl col-md-4 col-sm-6">
                <div class="bg-white p-4 h-100" style="border-radius: 16px; box-shadow: 0 4px 15px rgba(0,0,0,0.03); border: 1px solid #f1f5f9; border-bottom: 4px solid var(--lz-warning); display: flex; align-items: center; gap: 1.2rem;">
                    <div class="d-flex align-items-center justify-content-center flex-shrink-0" style="width: 54px; height: 54px; border-radius: 14px; background: rgba(255, 193, 7, 0.1); color: var(--lz-warning); font-size: 1.5rem;">
                        <i class="fa-solid fa-xmark"></i>
                    </div>
                    <div>
                        <div class="text-uppercase fw-bold" style="font-size: 0.85rem; color: #64748b; letter-spacing: 0.5px;">Missing SKUs</div>
                        <div class="fw-bold text-warning" style="font-size: 1.8rem; line-height: 1.2;"><?= $missingCount ?></div>
                    </div>
                </div>
            </div>
            <div class="col-xl col-md-4 col-sm-6">
                <div class="bg-white p-4 h-100" style="border-radius: 16px; box-shadow: 0 4px 15px rgba(0,0,0,0.03); border: 1px solid #f1f5f9; border-bottom: 4px solid #d97706; display: flex; align-items: center; gap: 1.2rem;">
                    <div class="d-flex align-items-center justify-content-center flex-shrink-0" style="width: 54px; height: 54px; border-radius: 14px; background: rgba(217, 119, 6, 0.1); color: #d97706; font-size: 1.5rem;">
                        <i class="fa-solid fa-link-slash"></i>
                    </div>
                    <div>
                        <div class="text-uppercase fw-bold" style="font-size: 0.85rem; color: #64748b; letter-spacing: 0.5px;">Unmatched</div>
                        <div class="fw-bold" style="font-size: 1.8rem; line-height: 1.2; color: #d97706;"><?= $unmatchedCount ?></div>
                    </div>
                </div>
            </div>
            <div class="col-xl col-md-6 col-sm-6">
                <div class="bg-white p-4 h-100" style="border-radius: 16px; box-shadow: 0 4px 15px rgba(0,0,0,0.03); border: 1px solid #f1f5f9; border-bottom: 4px solid var(--lz-info); display: flex; align-items: center; gap: 1.2rem;">
                    <div class="d-flex align-items-center justify-content-center flex-shrink-0" style="width: 54px; height: 54px; border-radius: 14px; background: rgba(23, 162, 184, 0.1); color: var(--lz-info); font-size: 1.5rem;">
                        <i class="fa-solid fa-clone"></i>
                    </div>
                    <div>
                        <div class="text-uppercase fw-bold" style="font-size: 0.85rem; color: #64748b; letter-spacing: 0.5px;">Duplicate SKUs</div>
                        <div class="fw-bold text-info" style="font-size: 1.8rem; line-height: 1.2;"><?= $duplicateCount ?></div>
                    </div>
                </div>
            </div>
            <div class="col-xl col-md-6 col-sm-6">
                <div class="bg-white p-4 h-100" style="border-radius: 16px; box-shadow: 0 4px 15px rgba(0,0,0,0.03); border: 1px solid #f1f5f9; border-bottom: 4px solid var(--lz-success); display: flex; align-items: center; gap: 1.2rem;">
                    <div class="d-flex align-items-center justify-content-center flex-shrink-0" style="width: 54px; height: 54px; border-radius: 14px; background: rgba(40, 167, 69, 0.1); color: var(--lz-success); font-size: 1.5rem;">
                        <i class="fa-solid fa-circle-check"></i>
                    </div>
                    <div>
                        <div class="text-uppercase fw-bold" style="font-size: 0.85rem; color: #64748b; letter-spacing: 0.5px;">Resolved</div>
                        <div class="fw-bold" style="font-size: 1.8rem; line-height: 1.2; color: #1e293b;"><?= $resolvedCount ?></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Search & Filter Toolbar -->
        <div class="bg-white mb-4" id="globalFilterCard" style="border-radius: 16px; box-shadow: 0 4px 15px rgba(0,0,0,0.03); border: 1px solid #f1f5f9;">
            <div class="p-3">
                <form method="GET" action="" class="row g-3 align-items-center m-0" id="resolutionFilterForm">
                    <!-- Search Input -->
                    <div class="col-md-5">
                        <div class="position-relative">
                            <i class="fa-solid fa-magnifying-glass position-absolute text-secondary" style="left: 1rem; top: 50%; transform: translateY(-50%);"></i>
                            <input type="text" name="search" id="resolutionSearch" autocomplete="off" class="form-control" style="padding: 0.6rem 1rem 0.6rem 2.5rem; border-radius: 12px; border: 1px solid #e2e8f0; background: #f8fafc; font-size: 0.95rem;" placeholder="Search by SKU or error details..." value="<?= htmlspecialchars($search_query) ?>">
                        </div>
                    </div>
                    <!-- Type Filter Dropdown -->
                    <div class="col-md-3 col-6">
                        <select name="type" class="form-select border-0" style="background: #f8fafc; border-radius: 12px; padding: 0.6rem 1rem; border: 1px solid #e2e8f0 !important; font-size: 0.95rem; color: #475569;" onchange="this.form.submit()">
                            <option value="">All Issue Types</option>
                            <option value="missing_sku" <?= $type_filter === 'missing_sku' ? 'selected' : '' ?>>Missing SKU</option>
                            <option value="unmapped" <?= $type_filter === 'unmapped' ? 'selected' : '' ?>>Unmatched SKU</option>
                            <option value="duplicate_sku" <?= $type_filter === 'duplicate_sku' ? 'selected' : '' ?>>Duplicate SKU</option>
                            <option value="sync_error" <?= $type_filter === 'sync_error' ? 'selected' : '' ?>>Other Sync Errors</option>
                        </select>
                    </div>
                    <!-- Limit Dropdown -->
                    <div class="col-md-2 col-6">
                        <select name="limit" class="form-select border-0" style="background: #f8fafc; border-radius: 12px; padding: 0.6rem 1rem; border: 1px solid #e2e8f0 !important; font-size: 0.95rem; color: #475569;" onchange="this.form.submit()">
                            <option value="2" <?= $items_per_page == 2 ? 'selected' : '' ?>>2 per page</option>
                            <option value="5" <?= $items_per_page == 5 ? 'selected' : '' ?>>5 per page</option>
                            <option value="10" <?= $items_per_page == 10 ? 'selected' : '' ?>>10 per page</option>
                            <option value="25" <?= $items_per_page == 25 ? 'selected' : '' ?>>25 per page</option>
                            <option value="50" <?= $items_per_page == 50 ? 'selected' : '' ?>>50 per page</option>
                        </select>
                    </div>
                    <!-- Action Buttons -->
                    <div class="col-md-2 d-flex gap-2">
                        <button type="submit" class="btn text-white w-100 fw-bold" style="background: var(--lazada-gradient); border-radius: 12px; padding: 0.6rem 1rem;">
                            <i class="fa-solid fa-filter me-1"></i>Filter
                        </button>
                        <?php if ($search_query !== '' || $type_filter !== ''): ?>
                            <a href="laz_resolution_center.php" class="btn btn-light fw-bold" style="border-radius: 12px; padding: 0.6rem 1rem; color: #64748b; border: 1px solid #e2e8f0;">Clear</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>

    <!-- Table Card -->
    <div class="bg-white overflow-hidden mb-4" style="border-radius: 16px; box-shadow: 0 4px 15px rgba(0,0,0,0.03); border: 1px solid #f1f5f9;">
        <div class="p-0">
            <div class="table-responsive">
                <table class="table lz-table mb-0 align-middle" style="border-collapse: collapse;">
                    <thead style="background: #f8fafc; border-bottom: 2px solid #e2e8f0;">
                        <tr>
                            <th style="padding: 1rem 1.5rem; font-weight: 700; color: #475569; font-size: 0.85rem; text-transform: uppercase;">Error Type</th>
                            <th style="padding: 1rem 1.5rem; font-weight: 700; color: #475569; font-size: 0.85rem; text-transform: uppercase;">Reference</th>
                            <th style="padding: 1rem 1.5rem; font-weight: 700; color: #475569; font-size: 0.85rem; text-transform: uppercase;">Details</th>
                            <th style="padding: 1rem 1.5rem; font-weight: 700; color: #475569; font-size: 0.85rem; text-transform: uppercase;">Detected On</th>
                            <th class="text-end" style="padding: 1rem 1.5rem; font-weight: 700; color: #475569; font-size: 0.85rem; text-transform: uppercase;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($errors)): ?>
                            <tr>
                                <td colspan="5">
                                    <div class="text-center py-5 my-4">
                                        <i class="fa-solid fa-shield-check mb-3" style="font-size: 4rem; color: var(--lz-success); opacity: 0.2;"></i>
                                        <h4 class="fw-bold" style="color: #1e293b;">System Healthy</h4>
                                        <p class="text-secondary mb-0" style="font-size: 1.05rem;">No open errors matching filters.</p>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($errors as $e): ?>
                                <tr style="border-bottom: 1px solid #f1f5f9;">
                                    <td style="padding: 1.2rem 1.5rem;">
                                        <?php if ($e['error_type'] === 'missing_sku'): ?>
                                            <?php if (!empty($e['lazada_model_id'])): ?>
                                                <span class="lz-badge lz-badge-danger fw-bold"><i class="fa-solid fa-tags me-1"></i> Missing Variation SKU</span>
                                            <?php else: ?>
                                                <span class="lz-badge lz-badge-danger fw-bold"><i class="fa-solid fa-box me-1"></i> Missing Parent SKU</span>
                                            <?php endif; ?>
                                        <?php elseif ($e['error_type'] === 'unmapped'): ?>
                                            <span class="lz-badge lz-badge-warning fw-bold" style="color:#d97706;background:#fef3c7;border-color:#fde68a"><i class="fa-solid fa-link-slash me-1"></i> Unmatched SKU</span>
                                        <?php elseif ($e['error_type'] === 'duplicate_sku'): ?>
                                            <span class="lz-badge lz-badge-warning fw-bold"><i class="fa-solid fa-clone me-1"></i> Duplicate SKU</span>
                                        <?php else: ?>
                                            <span class="lz-badge lz-badge-info fw-bold"><?= htmlspecialchars($e['error_type']) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="padding: 1.2rem 1.5rem;">
                                        <?php if ($e['error_type'] === 'duplicate_sku'): 
                                            $dupItemsStmt = $conn->prepare("
                                                SELECT lazada_item_id, lazada_model_id, lazada_product_name, lazada_variation_name 
                                                FROM lazada_product_mappings 
                                                WHERE (has_variation = 0 AND lazada_parent_sku = ?)
                                                   OR (has_variation = 1 AND lazada_variation_sku = ?)
                                            ");
                                            $dupItemsStmt->execute([$e['sku'], $e['sku']]);
                                            $dupItems = $dupItemsStmt->fetchAll();
                                        ?>
                                            <div class="d-flex flex-column gap-2">
                                                <div class="d-flex flex-column gap-2 ps-3 border-start border-danger" style="border-width: 3px !important; border-radius: 2px;">
                                                    <?php foreach ($dupItems as $index => $item): ?>
                                                        <div class="<?= $index > 0 ? 'border-top pt-2 mt-2' : '' ?>" style="font-size: 0.85rem;">
                                                            <div class="fw-bold text-dark" style="color: #1e293b;"><?= htmlspecialchars($item['lazada_product_name']) ?></div>
                                                            <?php if (!empty($item['lazada_variation_name'])): ?>
                                                                <span class="badge bg-light text-secondary border mt-1 py-1 px-2 font-monospace" style="font-size:0.7rem;"><?= htmlspecialchars($item['lazada_variation_name']) ?></span>
                                                            <?php endif; ?>
                                                            <div class="text-secondary font-monospace mt-2" style="font-size: 0.75rem; line-height: 1.4;">
                                                                Item ID: <strong class="text-dark"><?= htmlspecialchars($item['lazada_item_id']) ?></strong>
                                                                <?php if (!empty($item['lazada_model_id'])): ?>
                                                                     | Model ID: <strong class="text-dark"><?= htmlspecialchars($item['lazada_model_id']) ?></strong>
                                                                <?php endif; ?>
                                                            </div>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                        <?php else: ?>
                                            <div class="d-flex flex-column gap-1">
                                                <div class="fw-bold text-dark" style="font-size: 0.9rem; line-height: 1.3; color: #1e293b;"><?= htmlspecialchars($e['lazada_product_name'] ?? 'Unknown Lazada Product') ?></div>
                                                <div class="text-secondary font-monospace mt-1" style="font-size: 0.75rem; line-height: 1.4;">
                                                    Item ID: <strong class="text-dark"><?= htmlspecialchars($e['lazada_item_id']) ?></strong>
                                                    <?php if (!empty($e['lazada_model_id'])): ?>
                                                        <br>Model ID: <strong class="text-dark"><?= htmlspecialchars($e['lazada_model_id']) ?></strong>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td style="padding: 1.2rem 1.5rem;">
                                        <div class="d-flex flex-column gap-1 py-1">
                                            <?php if ($e['error_type'] === 'duplicate_sku'): ?>
                                                <div class="small text-secondary text-uppercase fw-bold" style="font-size: 0.7rem; letter-spacing: 0.5px;">Conflict Status</div>
                                                <div class="fw-bold text-danger d-flex align-items-center gap-1" style="font-size: 0.9rem;"><i class="fa-solid fa-triangle-exclamation"></i> Duplicated SKU</div>
                                                <span class="lz-sku-code text-danger mt-1 bg-danger-subtle border-danger-subtle" style="width: fit-content; font-size: 0.8rem; padding: 4px 8px;"><i class="fa-solid fa-clone me-1"></i><?= htmlspecialchars($e['sku']) ?></span>
                                                <div class="text-muted mt-2" style="font-size: 0.8rem; line-height: 1.4;">Shared by the <strong class="text-dark"><?= count($dupItems) ?></strong> listings shown on the left. Click action to resolve.</div>
                                            <?php elseif ($e['error_type'] === 'unmapped'): ?>
                                                <div class="small text-secondary text-uppercase fw-bold mb-1" style="font-size: 0.7rem; letter-spacing: 0.5px;">Lazada SKU</div>
                                                <span class="lz-sku-code text-dark" style="width: fit-content; font-size: 0.85rem;"><i class="fa-solid fa-barcode me-1"></i><?= htmlspecialchars($e['sku']) ?></span>
                                                <?php if (!empty($e['lazada_variation_name'])): ?>
                                                    <div class="small text-secondary text-uppercase fw-bold mt-3" style="font-size: 0.7rem; letter-spacing: 0.5px;">Variation Name</div>
                                                    <div class="fw-bold text-dark mt-1" style="font-size: 0.9rem;"><i class="fa-solid fa-tags text-lazada me-1"></i><?= htmlspecialchars($e['lazada_variation_name']) ?></div>
                                                <?php else: ?>
                                                    <div class="small text-secondary text-uppercase fw-bold mt-3" style="font-size: 0.7rem; letter-spacing: 0.5px;">Product Type</div>
                                                    <div class="text-muted mt-1" style="font-size: 0.9rem;"><i class="fa-solid fa-box text-secondary me-1"></i>Standalone Product</div>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <?php if (!empty($e['lazada_variation_name'])): ?>
                                                    <div class="small text-secondary text-uppercase fw-bold" style="font-size: 0.7rem; letter-spacing: 0.5px;">Variation Name</div>
                                                    <div class="fw-bold text-dark mt-1" style="font-size: 0.9rem;"><i class="fa-solid fa-tags text-lazada me-1"></i><?= htmlspecialchars($e['lazada_variation_name']) ?></div>
                                                <?php else: ?>
                                                    <div class="small text-secondary text-uppercase fw-bold" style="font-size: 0.7rem; letter-spacing: 0.5px;">Product Type</div>
                                                    <div class="text-muted mt-1" style="font-size: 0.9rem;"><i class="fa-solid fa-box text-secondary me-1"></i>Standalone Product</div>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td style="padding: 1.2rem 1.5rem;">
                                        <div class="d-flex align-items-center gap-2 text-secondary" style="font-size: 0.85rem; font-weight: 500;">
                                            <div style="width: 32px; height: 32px; border-radius: 8px; background: #f1f5f9; display: flex; align-items: center; justify-content: center; color: #64748b;">
                                                <i class="fa-regular fa-clock"></i>
                                            </div>
                                            <?= date('M d, g:i A', strtotime($e['created_at'])) ?>
                                        </div>
                                    </td>
                                    <td class="text-end" style="padding: 1.2rem 1.5rem;">
                                        <?php if ($e['error_type'] === 'missing_sku' || $e['error_type'] === 'unmapped'): ?>
                                            <button class="btn btn-sm text-white fw-bold px-3 py-2" style="background: var(--lazada-gradient); border-radius: 8px; box-shadow: 0 2px 4px rgba(238,77,45,0.2);" onclick="openFixMissingSkuModal(<?= $e['lazada_item_id'] ?>, <?= (int)$e['lazada_model_id'] ?>, '<?= htmlspecialchars(addslashes($e['error_message']), ENT_QUOTES) ?>')">
                                                <i class="fa-solid fa-wrench me-1"></i>Fix SKU
                                            </button>
                                        <?php elseif ($e['error_type'] === 'duplicate_sku'): ?>
                                            <button class="btn btn-sm text-white fw-bold px-3 py-2" style="background: var(--lazada-gradient); border-radius: 8px; box-shadow: 0 2px 4px rgba(238,77,45,0.2);" onclick="openResolveDuplicateModal('<?= htmlspecialchars(addslashes($e['sku']), ENT_QUOTES) ?>')">
                                                <i class="fa-solid fa-wrench me-1"></i>Resolve Duplicate
                                            </button>
                                        <?php else: ?>
                                            <button class="btn btn-sm btn-light text-secondary fw-bold px-3 py-2" style="border: 1px solid #e2e8f0; border-radius: 8px;" onclick="ignoreError(<?= $e['id'] ?>, this)">
                                                <i class="fa-solid fa-eye-slash me-1"></i>Dismiss
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Premium Pagination Footer -->
        <?php if ($total_filtered > 0): ?>
            <div class="d-flex justify-content-between align-items-center p-3" style="background: #f8fafc; border-top: 1px solid #e2e8f0;">
                <div class="text-secondary fw-bold" style="font-size: 0.85rem;">
                    Showing <span class="text-dark"><?= $offset + 1 ?></span> to <span class="text-dark"><?= min($offset + $items_per_page, $total_filtered) ?></span> of <span class="text-dark"><?= $total_filtered ?></span> issues
                </div>
                <nav>
                    <ul class="pagination pagination-sm mb-0 gap-1">
                        <!-- Previous Page -->
                        <li class="page-item <?= $page_num <= 1 ? 'disabled' : '' ?>">
                            <?php if ($page_num <= 1): ?>
                                <span class="page-link text-secondary fw-bold border-0 bg-transparent disabled" style="opacity: 0.5;">
                                    <i class="fa-solid fa-chevron-left me-1"></i> Prev
                                </span>
                            <?php else: ?>
                                <a class="page-link text-secondary fw-bold border-0 bg-transparent" href="laz_resolution_center.php?page=<?= $page_num - 1 ?>&search=<?= urlencode($search_query) ?>&type=<?= urlencode($type_filter) ?>&limit=<?= $items_per_page ?>" style="transition: color 0.2s;">
                                    <i class="fa-solid fa-chevron-left me-1"></i> Prev
                                </a>
                            <?php endif; ?>
                        </li>
                        
                        <!-- Page Numbers -->
                        <?php if ($total_pages > 1): ?>
                            <?php
                            $range = 2; // Pages to display on each side of active page
                            $start_page = max(1, $page_num - $range);
                            $end_page = min($total_pages, $page_num + $range);
                            
                            if ($start_page > 1): ?>
                                <li class="page-item">
                                    <a class="page-link text-secondary fw-bold" href="laz_resolution_center.php?page=1&search=<?= urlencode($search_query) ?>&type=<?= urlencode($type_filter) ?>&limit=<?= $items_per_page ?>" style="border-radius: 8px; border: 1px solid transparent;">1</a>
                                </li>
                                <?php if ($start_page > 2): ?>
                                    <li class="page-item disabled"><span class="page-link text-secondary fw-bold bg-transparent border-0 disabled">...</span></li>
                                <?php endif; ?>
                            <?php endif; ?>

                            <?php for ($p = $start_page; $p <= $end_page; $p++): ?>
                                <li class="page-item <?= $page_num == $p ? 'active' : '' ?>">
                                    <a class="page-link fw-bold <?= $page_num == $p ? 'text-white' : 'text-secondary' ?>" href="laz_resolution_center.php?page=<?= $p ?>&search=<?= urlencode($search_query) ?>&type=<?= urlencode($type_filter) ?>&limit=<?= $items_per_page ?>" style="border-radius: 8px; border: 1px solid transparent; <?= $page_num == $p ? 'background: var(--lazada-primary); box-shadow: 0 2px 4px rgba(238,77,45,0.2);' : '' ?>">
                                        <?= $p ?>
                                    </a>
                                </li>
                            <?php endfor; ?>

                            <?php if ($end_page < $total_pages): ?>
                                <?php if ($end_page < $total_pages - 1): ?>
                                    <li class="page-item disabled"><span class="page-link text-secondary fw-bold bg-transparent border-0 disabled">...</span></li>
                                <?php endif; ?>
                                <li class="page-item">
                                    <a class="page-link text-secondary fw-bold" href="laz_resolution_center.php?page=<?= $total_pages ?>&search=<?= urlencode($search_query) ?>&type=<?= urlencode($type_filter) ?>&limit=<?= $items_per_page ?>" style="border-radius: 8px; border: 1px solid transparent;"><?= $total_pages ?></a>
                                </li>
                            <?php endif; ?>
                        <?php else: ?>
                            <li class="page-item active">
                                <span class="page-link text-white fw-bold" style="border-radius: 8px; background: var(--lazada-primary); border-color: var(--lazada-primary); box-shadow: 0 2px 4px rgba(238,77,45,0.2);">1</span>
                            </li>
                        <?php endif; ?>
                        
                        <!-- Next Page -->
                        <li class="page-item <?= $page_num >= $total_pages ? 'disabled' : '' ?>">
                            <?php if ($page_num >= $total_pages): ?>
                                <span class="page-link text-secondary fw-bold border-0 bg-transparent disabled" style="opacity: 0.5;">
                                    Next <i class="fa-solid fa-chevron-right ms-1"></i>
                                </span>
                            <?php else: ?>
                                <a class="page-link text-secondary fw-bold border-0 bg-transparent" href="laz_resolution_center.php?page=<?= $page_num + 1 ?>&search=<?= urlencode($search_query) ?>&type=<?= urlencode($type_filter) ?>&limit=<?= $items_per_page ?>" style="transition: color 0.2s;">
                                    Next <i class="fa-solid fa-chevron-right ms-1"></i>
                                </a>
                            <?php endif; ?>
                        </li>
                    </ul>
                </nav>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('resolutionFilterForm');
    const search = document.getElementById('resolutionSearch');
    if (!form || !search) return;

    let searchTimer = null;
    let lastSubmittedSearch = search.value.trim();

    function submitSearch() {
        const nextSearch = search.value.trim();
        if (nextSearch === lastSubmittedSearch) return;
        lastSubmittedSearch = nextSearch;
        const pageInput = form.querySelector('input[name="page"]');
        if (pageInput) pageInput.value = '1';
        form.submit();
    }

    search.addEventListener('input', function () {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(submitSearch, 800);
    });

    search.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter') return;
        e.preventDefault();
        clearTimeout(searchTimer);
        submitSearch();
    });
});

async function startQuickScan(btn) {
    const origHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-2"></i>Scanning...';
    
    try {
        const res = await fetch(`${window.BASE_URL}api/lazada/detect_conflicts.php`);
        const data = await res.json();
        
        if (data.success) {
            EllaToast.success('Scan complete! Fresh conflicts loaded.');
            setTimeout(() => window.location.reload(), 1200);
        } else {
            EllaToast.error(data.error || 'Failed to detect conflicts');
            btn.disabled = false;
            btn.innerHTML = origHtml;
        }
    } catch (e) {
        EllaToast.error('Scan failed: ' + e.message);
        btn.disabled = false;
        btn.innerHTML = origHtml;
    }
}

async function ignoreError(id, btn) {
    if (!confirm('Are you sure you want to dismiss this error?')) return;
    
    const origHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i>Dismissing...';
    
    try {
        const formData = new FormData();
        formData.append('id', id);
        
        const res = await fetch(`${window.BASE_URL}api/lazada/dismiss_error.php`, {
            method: 'POST',
            body: formData
        });
        const data = await res.json();
        
        if (data.success) {
            EllaToast.success(data.message);
            // Fade out the row
            const row = btn.closest('tr');
            if (row) {
                row.style.transition = 'all 0.4s ease';
                row.style.opacity = '0';
                row.style.transform = 'translateX(20px)';
                setTimeout(() => {
                    row.remove();
                    // Reload after quick row animation to keep pagination counts completely sync'd
                    window.location.reload();
                }, 400);
            }
        } else {
            EllaToast.error(data.error || 'Failed to dismiss error');
            btn.disabled = false;
            btn.innerHTML = origHtml;
        }
    } catch (e) {
        EllaToast.error('Network error: ' + e.message);
        btn.disabled = false;
        btn.innerHTML = origHtml;
    }
}

// Inline Resolution Center Modal Javascript Logic
let missingSkuModalInstance = null;
let duplicateModalInstance = null;

function openFixMissingSkuModal(itemId, modelId, errorMessage) {
    document.getElementById('missingSkuItemId').value = itemId;
    document.getElementById('missingSkuModelId').value = modelId;
    
    let warningHtml = `<i class="fa-solid fa-triangle-exclamation me-1"></i>${errorMessage}`;
    warningHtml += `<span class="small font-monospace opacity-75 mt-1 d-block" style="font-size: 0.72rem; line-height: 1.3;">`;
    warningHtml += `Lazada Item ID: <strong>${itemId}</strong>`;
    if (modelId && modelId !== 0) {
        warningHtml += ` | Model ID: <strong>${modelId}</strong>`;
    }
    warningHtml += `</span>`;
    document.getElementById('missingSkuWarningText').innerHTML = warningHtml;
    
    document.getElementById('newSkuInput').value = '';
    
    const label = document.getElementById('missingSkuInputLabel');
    const desc = document.getElementById('missingSkuInputDesc');
    const title = document.getElementById('fixMissingSkuModalLabel');
    
    if (modelId && modelId !== 0) {
        if (title) title.innerHTML = '<i class="fa-solid fa-circle-question text-lazada me-2"></i>Assign Variation SKU on Lazada';
        if (label) label.textContent = 'New Variation SKU for Lazada';
        if (desc) desc.textContent = 'This variation SKU will be pushed to Lazada instantly. If it matches a POS variation SKU, it will auto-link!';
    } else {
        if (title) title.innerHTML = '<i class="fa-solid fa-circle-question text-lazada me-2"></i>Assign Parent SKU on Lazada';
        if (label) label.textContent = 'New Parent SKU for Lazada';
        if (desc) desc.textContent = 'This parent product SKU will be pushed to Lazada instantly. If it matches a POS product SKU, it will auto-link!';
    }
    
    if (!missingSkuModalInstance) {
        missingSkuModalInstance = new bootstrap.Modal(document.getElementById('fixMissingSkuModal'));
    }
    missingSkuModalInstance.show();
}

async function submitFixMissingSku(e) {
    e.preventDefault();
    const itemId = document.getElementById('missingSkuItemId').value;
    const modelId = document.getElementById('missingSkuModelId').value;
    const newSku = document.getElementById('newSkuInput').value.trim();
    const btn = document.getElementById('btnSubmitMissingSku');
    
    if (!newSku) return;
    
    const origHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i>Syncing to Lazada...';
    
    try {
        const res = await fetch(`${window.BASE_URL}api/lazada/update_lazada_sku.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ item_id: itemId, model_id: modelId, new_sku: newSku })
        });
        const data = await res.json();
        
        if (data.success) {
            EllaToast.success(data.message + (data.auto_matched ? " (Automatically linked to POS variation!)" : ""));
            missingSkuModalInstance.hide();
            setTimeout(() => window.location.reload(), 1200);
        } else {
            EllaToast.error(data.error || 'Failed to update SKU');
            btn.disabled = false;
            btn.innerHTML = origHtml;
        }
    } catch (err) {
        EllaToast.error('Network error: ' + err.message);
        btn.disabled = false;
        btn.innerHTML = origHtml;
    }
}

async function openResolveDuplicateModal(sku) {
    document.getElementById('duplicateSkuTitleText').textContent = sku;
    const container = document.getElementById('duplicateItemsContainer');
    container.innerHTML = '<div class="text-center py-4"><i class="fa-solid fa-spinner fa-spin text-lazada fs-3"></i><p class="small text-secondary mt-2">Fetching duplicate listings...</p></div>';
    
    if (!duplicateModalInstance) {
        duplicateModalInstance = new bootstrap.Modal(document.getElementById('resolveDuplicateModal'));
    }
    duplicateModalInstance.show();
    
    try {
        const res = await fetch(`${window.BASE_URL}api/lazada/get_lazada_items_by_sku.php?sku=${encodeURIComponent(sku)}`);
        const data = await res.json();
        
        if (data.success && data.items.length) {
            container.innerHTML = data.items.map(item => {
                const title = item.lazada_product_name;
                const subtitle = item.has_variation ? `Variation: <strong>${item.lazada_variation_name}</strong>` : 'Simple Product';
                const currentSku = item.has_variation ? item.lazada_variation_sku : item.lazada_parent_sku;
                
                return `
                <div class="p-3 border rounded-3 bg-light d-flex flex-column gap-2">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="fw-bold text-dark" style="font-size:0.88rem;">${title}</div>
                            <div class="small text-secondary mt-1">${subtitle}</div>
                            <div class="small text-muted font-monospace mt-1" style="font-size:0.75rem;">Lazada Item ID: ${item.lazada_item_id} ${item.lazada_model_id ? `| Model ID: ${item.lazada_model_id}` : ''}</div>
                        </div>
                        <div class="text-end">
                            <span class="badge bg-secondary text-white">Stock: ${item.lazada_stock}</span>
                            <div class="small fw-semibold text-lazada mt-1">₱${Number(item.lazada_price).toFixed(2)}</div>
                        </div>
                    </div>
                    <hr class="my-2 text-muted" style="opacity:0.1;">
                    <div class="row align-items-center g-2">
                        <div class="col-sm-8">
                            <div class="input-group input-group-sm">
                                <span class="input-group-text bg-white font-monospace text-secondary" style="font-size:0.75rem;">SKU</span>
                                <input type="text" class="form-control form-control-sm font-monospace" id="dupInput_${item.id}" value="${currentSku}" style="font-size:0.8rem;">
                            </div>
                        </div>
                        <div class="col-sm-4 text-end">
                            <button class="btn btn-sm btn-lazada w-100 py-1" onclick="updateDuplicateItemSku(${item.id}, ${item.lazada_item_id}, ${item.lazada_model_id || 0}, this)">
                                <i class="fa-solid fa-floppy-disk me-1"></i>Update SKU
                            </button>
                        </div>
                    </div>
                </div>
                `;
            }).join('');
        } else {
            container.innerHTML = '<div class="alert alert-warning text-center py-3 m-0"><i class="fa-solid fa-triangle-exclamation me-1"></i>No active Lazada listings found for this SKU. It might have been cleared or re-scanned.</div>';
        }
    } catch (err) {
        container.innerHTML = `<div class="alert alert-danger text-center py-3 m-0"><i class="fa-solid fa-circle-exclamation me-1"></i>Error fetching details: ${err.message}</div>`;
    }
}

async function updateDuplicateItemSku(mappingId, itemId, modelId, btn) {
    const input = document.getElementById(`dupInput_${mappingId}`);
    const newSku = input ? input.value.trim() : '';
    
    if (!newSku) {
        EllaToast.error('SKU cannot be empty');
        return;
    }
    
    const origHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i>Updating...';
    
    try {
        const res = await fetch(`${window.BASE_URL}api/lazada/update_lazada_sku.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ item_id: itemId, model_id: modelId, new_sku: newSku })
        });
        const data = await res.json();
        
        if (data.success) {
            EllaToast.success(data.message + (data.auto_matched ? " (Automatically linked to POS variation!)" : ""));
            duplicateModalInstance.hide();
            setTimeout(() => window.location.reload(), 1200);
        } else {
            EllaToast.error(data.error || 'Failed to update SKU');
            btn.disabled = false;
            btn.innerHTML = origHtml;
        }
    } catch (err) {
        EllaToast.error('Network error: ' + err.message);
        btn.disabled = false;
        btn.innerHTML = origHtml;
    }
}

async function allowDuplicateSku(btn) {
    const sku = document.getElementById('duplicateSkuTitleText').textContent;
    if (!sku) return;
    
    if (!confirm(`Are you sure you want to allow "${sku}" as a shared listing? This will resolve the conflict and allow multiple Lazada products to share the same POS inventory.`)) return;

    const origHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i>Allowing...';
    
    try {
        const res = await fetch(`${window.BASE_URL}api/lazada/allow_duplicate.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ sku: sku })
        });
        const data = await res.json();
        
        if (data.success) {
            EllaToast.success(data.message);
            duplicateModalInstance.hide();
            setTimeout(() => window.location.reload(), 1200);
        } else {
            EllaToast.error(data.error || 'Failed to allow duplicate SKU');
            btn.disabled = false;
            btn.innerHTML = origHtml;
        }
    } catch (err) {
        EllaToast.error('Network error: ' + err.message);
        btn.disabled = false;
        btn.innerHTML = origHtml;
    }
}
</script>

<!-- Fix Missing SKU Modal -->
<div class="modal fade" id="fixMissingSkuModal" tabindex="-1" aria-labelledby="fixMissingSkuModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 16px; background: var(--bg-surface);">
            <div class="modal-header border-bottom-0 pb-0 pt-4 px-4 d-flex justify-content-between align-items-center">
                <div>
                    <h5 class="modal-title fw-bold" id="fixMissingSkuModalLabel"><i class="fa-solid fa-circle-question text-lazada me-2"></i>Assign SKU on Lazada</h5>
                    <p class="text-secondary small mb-0 mt-1" style="font-size: 0.78rem;">Resolve the missing SKU conflict by assigning a unique SKU</p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close" style="font-size: 0.8rem;"></button>
            </div>
            <div class="modal-body p-4">
                <div class="alert alert-warning py-2 px-3 small mb-3" id="missingSkuWarningText" style="font-size:0.75rem; border-radius:8px;"></div>
                
                <form id="formFixMissingSku" onsubmit="submitFixMissingSku(event)">
                    <input type="hidden" id="missingSkuItemId">
                    <input type="hidden" id="missingSkuModelId">
                    
                    <div class="mb-3">
                        <label for="newSkuInput" class="form-label small fw-bold" id="missingSkuInputLabel">New SKU for Lazada</label>
                        <input type="text" class="form-control" id="newSkuInput" placeholder="e.g. SLV-001" required style="border-radius: 8px;">
                        <div class="form-text small" style="font-size: 0.72rem;" id="missingSkuInputDesc">This SKU will be pushed to Lazada instantly. If it matches a POS SKU, it will auto-link!</div>
                    </div>
                    
                    <div class="d-flex gap-2 justify-content-end mt-4">
                        <button type="button" class="btn btn-sm btn-outline-secondary px-3" data-bs-dismiss="modal" style="border-radius: 8px;">Cancel</button>
                        <button type="submit" class="btn btn-sm btn-lazada px-4" id="btnSubmitMissingSku" style="border-radius: 8px;">Update Lazada SKU</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Resolve Duplicate SKU Modal -->
<div class="modal fade" id="resolveDuplicateModal" tabindex="-1" aria-labelledby="resolveDuplicateModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 16px; background: var(--bg-surface);">
            <div class="modal-header border-bottom-0 pb-0 pt-4 px-4 d-flex justify-content-between align-items-center">
                <div>
                    <h5 class="modal-title fw-bold" id="resolveDuplicateModalLabel"><i class="fa-solid fa-clone text-warning me-2"></i>Resolve Duplicate SKU</h5>
                    <p class="text-secondary small mb-0 mt-1" style="font-size: 0.78rem;">Multiple Lazada listings share the duplicate SKU: <strong id="duplicateSkuTitleText" class="text-dark"></strong></p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close" style="font-size: 0.8rem;"></button>
            </div>
            <div class="modal-body p-4">
                <p class="small text-secondary mb-3">To resolve this conflict, edit the SKU of one or more of the Lazada listings below to make them unique. Clicking update will push the new SKU to Lazada instantly.</p>
                
                <div id="duplicateItemsContainer" class="d-flex flex-column gap-3">
                    <div class="text-center py-4"><i class="fa-solid fa-spinner fa-spin text-lazada fs-3"></i></div>
                </div>
            </div>
            <div class="modal-footer border-top-0 pt-0 pb-4 px-4 d-flex justify-content-between">
                <button type="button" class="btn btn-sm btn-outline-lazada" onclick="allowDuplicateSku(this)" style="border-radius: 8px;">
                    <i class="fa-solid fa-link me-1"></i>Allow as Shared Listing
                </button>
                <button type="button" class="btn btn-sm btn-outline-secondary px-4" data-bs-dismiss="modal" style="border-radius: 8px;">Close</button>
            </div>
        </div>
    </div>
</div>

<script src="../../views/lazada/laz_alerts.js"></script>
<?php require_once '../../includes/footer.php'; ?>


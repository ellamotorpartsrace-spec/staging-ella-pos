<?php
// views/shopee/logs.php — Sync Logs
$page_title = 'Shopee Sync — Sync Logs';
require_once '../../config/config.php';
require_once '../../includes/auth.php';
requirePermission('shopee_sync');

$db = new Database();
$conn = $db->getConnection();

$startDate = $_GET['start_date'] ?? date('Y-m-d');
$endDate = $_GET['end_date'] ?? date('Y-m-d');

$where = [];
$params = [];

$where[] = "DATE(l.created_at) BETWEEN ? AND ?";
$params[] = $startDate;
$params[] = $endDate;

$whereSql = '';
if (!empty($where)) {
    $whereSql = 'WHERE ' . implode(' AND ', $where);
}

$stmt = $conn->prepare("
    SELECT l.*, u.full_name AS user_name,
           (SELECT p.product_name 
            FROM shopee_product_mappings m 
            JOIN product_variations v ON m.pos_product_id = v.variation_id
            JOIN products p ON v.product_id = p.product_id
            WHERE m.shopee_item_id = l.shopee_item_id 
            ORDER BY m.id DESC LIMIT 1) as current_mapped_pos_name
    FROM shopee_sync_logs l
    LEFT JOIN users u ON l.created_by = u.id
    $whereSql
    ORDER BY l.created_at DESC 
    LIMIT 1000
");
$stmt->execute($params);
$dbLogs = $stmt->fetchAll(PDO::FETCH_ASSOC);

$logsJson = [];
foreach ($dbLogs as $l) {
    $logsJson[] = [
        'ts' => date('Y-m-d h:i:s A', strtotime($l['created_at'])),
        'product' => $l['product_name'] ?: 'System Event',
        'sku' => $l['sku'] ?: '—',
        'type' => str_replace('_', ' ', ucfirst($l['event_type'])),
        'event' => $l['event_type'],
        'oldStock' => $l['old_value'] !== null ? $l['old_value'] : '—',
        'newStock' => $l['new_value'] !== null ? $l['new_value'] : '—',
        'source' => $l['source'] ?: 'Automated',
        'status' => $l['status'],
        'error' => $l['error_message'] ?: '',
        'user' => $l['user_name'] ?: 'System',
        'posName' => $l['current_mapped_pos_name'] ?: 'Unknown POS Product'
    ];
}

$countsStmt = $conn->prepare("SELECT 
    SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as success,
    SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
    COUNT(*) as total
FROM shopee_sync_logs l $whereSql");
$countsStmt->execute($params);
$counts = $countsStmt->fetch(PDO::FETCH_ASSOC);

$totalCount = $counts['total'] ?? 0;
$successCount = $counts['success'] ?? 0;
$successRateFormatted = $totalCount > 0 ? number_format(($successCount / $totalCount) * 100, 1) . '%' : '—';

if ($startDate === $endDate) {
    if ($startDate === date('Y-m-d')) {
        $periodLabel = "Today's Total";
    } elseif ($startDate === date('Y-m-d', strtotime('-1 day'))) {
        $periodLabel = "Yesterday's Total";
    } else {
        $periodLabel = date('M d, Y', strtotime($startDate));
    }
} else {
    $periodLabel = date('M d, Y', strtotime($startDate)) . ' - ' . date('M d, Y', strtotime($endDate));
}

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/shopee-sync.css?v=<?= filemtime(__DIR__ . '/../../assets/css/shopee-sync.css') ?>">

<style>
/* Custom inline Premium Filters block */
.sp-card-body.filter-row {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 0.75rem;
    padding: 0.75rem 1.25rem !important;
}

.premium-search-box {
    display: flex;
    align-items: center;
    background: var(--bg-surface);
    border: 1px solid var(--border-color);
    border-radius: var(--sp-radius-sm);
    padding: 0 0.75rem;
    transition: all 0.2s ease;
    width: 220px;
}
.premium-search-box:focus-within {
    border-color: var(--shopee-primary);
    box-shadow: 0 0 0 3px rgba(238, 77, 45, 0.1);
}
.premium-search-box i {
    color: var(--text-secondary);
    font-size: 0.85rem;
}
.premium-search-box input {
    border: none;
    background: transparent;
    padding: 0.45rem 0.5rem;
    color: var(--text-primary);
    font-size: 0.85rem;
    font-weight: 500;
    outline: none;
    width: 100%;
}

.premium-filter-label {
    font-size: 0.85rem;
    font-weight: 600;
    color: var(--text-secondary);
    white-space: nowrap;
}

.premium-select {
    appearance: none;
    -webkit-appearance: none;
    background: var(--sp-neutral-bg);
    border: 1px solid var(--border-color);
    border-radius: var(--sp-radius-sm);
    padding: 0.45rem 2rem 0.45rem 0.75rem !important;
    font-size: 0.85rem;
    font-weight: 600;
    color: var(--text-primary) !important;
    cursor: pointer;
    transition: all 0.2s ease;
    outline: none;
}
.premium-select:focus {
    border-color: var(--shopee-primary);
}

.premium-select-wrapper {
    position: relative;
    display: flex;
    align-items: center;
}
.premium-select-wrapper::after {
    content: "\f078";
    font-family: "Font Awesome 6 Free";
    font-weight: 900;
    font-size: 0.7rem;
    color: var(--text-secondary);
    position: absolute;
    right: 0.75rem;
    pointer-events: none;
}

.premium-date-input {
    background: var(--sp-neutral-bg);
    border: 1px solid var(--border-color);
    border-radius: var(--sp-radius-sm);
    padding: 0.45rem 0.75rem;
    font-size: 0.85rem;
    font-weight: 600;
    color: var(--text-primary);
    outline: none;
    transition: all 0.2s ease;
    cursor: pointer;
}
.premium-date-input:focus {
    border-color: var(--shopee-primary);
}

.premium-pill.sp-pill {
    padding: 0.45rem 0.95rem;
    border-radius: 999px;
    font-size: 0.82rem;
    font-weight: 600;
    border: 1px solid var(--border-color);
    background: transparent;
    color: var(--text-secondary);
    cursor: pointer;
    transition: all 0.2s ease;
}
.premium-pill.sp-pill:hover {
    border-color: var(--shopee-primary);
    color: var(--shopee-primary);
}
.premium-pill.sp-pill.active {
    background: var(--shopee-light);
    border-color: var(--shopee-primary);
    color: var(--shopee-primary);
}
.premium-pill.sp-pill.pill-failed:hover {
    border-color: var(--sp-danger);
    color: var(--sp-danger);
}
.premium-pill.sp-pill.pill-failed.active {
    background: var(--sp-danger-bg);
    border-color: var(--sp-danger);
    color: var(--sp-danger);
}

.premium-fade-in {
    animation: premium-fadeIn 0.2s ease-out forwards;
}
@keyframes premium-fadeIn {
    from { opacity: 0; transform: scale(0.98); }
    to { opacity: 1; transform: scale(1); }
}
</style>

<div class="sp-page sp-animate">
    <div class="sp-breadcrumb">
        <a href="<?= BASE_URL ?>views/shopee/index.php">Shopee Sync</a>
        <i class="fa-solid fa-chevron-right" style="font-size:0.6rem"></i>
        <span>Sync Logs</span>
    </div>

    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-3">
        <div>
            <h1 class="sp-title mb-0"><i class="fa-solid fa-clock-rotate-left text-shopee me-2"></i>Sync Logs</h1>
            <p class="sp-subtitle mb-0">Track every stock update, product sync, mapping change, and system event</p>
        </div>
        <div class="d-flex gap-2">
            <!-- Clear History button removed -->
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-3 col-6">
            <div class="sp-stat-card accent-success">
                <div class="sp-stat-icon" style="background:var(--sp-success-bg);color:var(--sp-success)"><i class="fa-solid fa-check-circle"></i></div>
                <div><div class="sp-stat-label">Successful</div><div class="sp-stat-value"><?= number_format($counts['success'] ?? 0) ?></div></div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="sp-stat-card accent-danger">
                <div class="sp-stat-icon" style="background:var(--sp-danger-bg);color:var(--sp-danger)"><i class="fa-solid fa-circle-xmark"></i></div>
                <div><div class="sp-stat-label">Failed</div><div class="sp-stat-value"><?= number_format($counts['failed'] ?? 0) ?></div></div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="sp-stat-card accent-warning">
                <div class="sp-stat-icon" style="background:var(--sp-warning-bg);color:var(--sp-warning)"><i class="fa-solid fa-rotate"></i></div>
                <div><div class="sp-stat-label"><?= $periodLabel ?></div><div class="sp-stat-value"><?= number_format($counts['total'] ?? 0) ?></div></div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="sp-stat-card accent-info">
                <div class="sp-stat-icon" style="background:var(--sp-info-bg);color:var(--sp-info)"><i class="fa-solid fa-percent"></i></div>
                <div><div class="sp-stat-label">Success Rate</div><div class="sp-stat-value"><?= $successRateFormatted ?></div></div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="sp-card mb-4">
        <form method="GET" action="" id="filterForm" class="w-100 m-0">
            <div class="sp-card-body filter-row">
                
                <!-- Search Box -->
                <div class="premium-search-box">
                    <i class="fa-solid fa-search"></i>
                    <input type="text" id="logSearch" autocomplete="off" placeholder="Search product, SKU..." oninput="renderLogs()">
                </div>
                
                <!-- Date Frame Selection -->
                <div class="d-flex align-items-center gap-2">
                    <span class="premium-filter-label">Date:</span>
                    <input type="date" class="premium-date-input" id="startDate" name="start_date" value="<?= htmlspecialchars($startDate) ?>" onchange="document.getElementById('filterForm').submit()">
                    <span class="text-secondary fw-semibold mx-1">to</span>
                    <input type="date" class="premium-date-input" id="endDate" name="end_date" value="<?= htmlspecialchars($endDate) ?>" onchange="document.getElementById('filterForm').submit()">
                </div>

                <!-- Category Pills -->
                <div class="sp-filter-pills ms-auto d-flex gap-2 flex-wrap">
                    <button type="button" class="premium-pill sp-pill pill-all active" onclick="setLogFilter('all',this)">All Events</button>
                    <button type="button" class="premium-pill sp-pill pill-sync" onclick="setLogFilter('product_import',this)">Product Sync</button>
                    <button type="button" class="premium-pill sp-pill pill-stock" onclick="setLogFilter('stock_update',this)">Stock Updates</button>
                    <button type="button" class="premium-pill sp-pill pill-sku" onclick="setLogFilter('shopee_sku',this)">Shopee SKU</button>
                    <button type="button" class="premium-pill sp-pill pill-map" onclick="setLogFilter('mapping',this)">Mappings</button>
                    <button type="button" class="premium-pill sp-pill pill-token" onclick="setLogFilter('token_refresh',this)">Token</button>
                    <button type="button" class="premium-pill sp-pill pill-failed" onclick="setLogFilter('failed',this)">Failed</button>
                </div>
            </div>
        </form>
    </div>

    <!-- Logs Table -->
    <div class="sp-card">
        <div class="sp-card-body p-0 sp-table-wrap">
            <table class="sp-table">
                <thead>
                    <tr>
                        <th style="width:160px">Timestamp</th>
                        <th style="width:380px">Scope / Product</th>
                        <th style="width:140px">Event Type</th>
                        <th>Details / Summary</th>
                        <th style="width:120px">User</th>
                        <th style="width:110px">Source</th>
                        <th style="width:110px">Status</th>
                    </tr>
                </thead>
                <tbody id="logBody"></tbody>
            </table>
        </div>
    </div>
</div>

<?php
// Database variables are handled at the top
?>
<script>
const LOGS = <?= json_encode($logsJson) ?>;

let logFilter = 'all';

function setLogFilter(f, btn) {
    logFilter = f;
    document.querySelectorAll('.sp-pill').forEach(p => p.classList.remove('active'));
    btn.classList.add('active');
    renderLogs();
}



function clearFilters() {
    window.location.href = 'logs.php';
}

function renderLogs() {
    const search = (document.getElementById('logSearch')?.value || '').toLowerCase();
    const body = document.getElementById('logBody');

    let items = LOGS.filter(l => {
        if (search && !l.product.toLowerCase().includes(search) && !l.sku.toLowerCase().includes(search) && !l.type.toLowerCase().includes(search) && !l.source.toLowerCase().includes(search)) return false;
        if (logFilter === 'failed') { if (l.status !== 'failed') return false; }
        else if (logFilter !== 'all' && l.event !== logFilter) return false;
        return true;
    });

    if (!items.length) {
        body.innerHTML = '<tr><td colspan="7"><div class="sp-empty"><i class="fa-solid fa-clock-rotate-left d-block"></i><h5>No logs found</h5><p>Try adjusting your filters.</p></div></td></tr>';
        return;
    }
    // Aggressively remove any stuck popovers from the DOM
    document.querySelectorAll('.popover').forEach(p => p.remove());

    body.innerHTML = items.map(l => {
        let eventBadge = '';
        switch(l.event) {
            case 'product_import': 
                if (l.product.includes('Quick Sync')) {
                    eventBadge = '<span class="sp-badge" style="background:rgba(238,77,45,0.12);color:#ee4d2d"><i class="fa-solid fa-bolt me-1"></i>Quick Sync</span>';
                } else if (l.product.includes('Full Sync')) {
                    eventBadge = '<span class="sp-badge" style="background:rgba(59,130,246,0.12);color:#3b82f6"><i class="fa-solid fa-arrows-rotate me-1"></i>Full Sync</span>';
                } else if (l.product.includes('Stock Sync')) {
                    eventBadge = '<span class="sp-badge" style="background:rgba(16,185,129,0.12);color:#10b981"><i class="fa-solid fa-box me-1"></i>Stock Sync</span>';
                } else if (l.product.includes('Price Sync')) {
                    eventBadge = '<span class="sp-badge" style="background:rgba(245,158,11,0.12);color:#f59e0b"><i class="fa-solid fa-sack-dollar me-1"></i>Price Sync</span>';
                } else if (l.product.includes('Mapping Sync')) {
                    eventBadge = '<span class="sp-badge" style="background:rgba(102,16,242,0.12);color:#6610f2"><i class="fa-solid fa-link me-1"></i>Mapping Sync</span>';
                } else if (l.product.includes('Product Sync (Products Page)')) {
                    eventBadge = '<span class="sp-badge" style="background:rgba(99,102,241,0.12);color:#6366f1"><i class="fa-solid fa-bag-shopping me-1"></i>Product Sync</span>';
                } else {
                    eventBadge = '<span class="sp-badge sp-badge-info"><i class="fa-solid fa-cloud-arrow-down me-1"></i>Import</span>';
                }
                break;
            case 'stock_update': eventBadge = '<span class="sp-badge sp-badge-success"><i class="fa-solid fa-boxes-stacked me-1"></i>Stock Update</span>'; break;
            case 'shopee_sku': eventBadge = '<span class="sp-badge" style="background:rgba(253,126,20,0.12);color:#fd7e14;border:1px solid rgba(253,126,20,0.25)"><i class="fa-solid fa-tag me-1"></i>Shopee SKU</span>'; break;
            case 'mapping': eventBadge = '<span class="sp-badge" style="background:rgba(102,16,242,0.1);color:#6610f2"><i class="fa-solid fa-link me-1"></i>Mapping</span>'; break;
            case 'token_refresh':
                if (l.source && l.source.includes('OAuth')) {
                    eventBadge = '<span class="sp-badge" style="background:rgba(99,102,241,0.12);color:#6366f1"><i class="fa-solid fa-right-to-bracket me-1"></i>OAuth</span>';
                } else if (l.source && (l.source.includes('Cron') || l.source.includes('Auto'))) {
                    eventBadge = '<span class="sp-badge sp-badge-warning"><i class="fa-solid fa-clock-rotate-left me-1"></i>Auto-Refresh</span>';
                } else {
                    eventBadge = '<span class="sp-badge sp-badge-warning"><i class="fa-solid fa-key me-1"></i>Token Refresh</span>';
                }
                break;
            default: eventBadge = '<span class="sp-badge sp-badge-neutral">' + l.type + '</span>';
        }

        let statusBadge = l.status === 'success'
            ? '<span class="sp-badge sp-badge-success"><i class="fa-solid fa-check me-1"></i>Success</span>'
            : '<span class="sp-badge sp-badge-danger"><i class="fa-solid fa-xmark me-1"></i>Failed</span>';

        let details = '—';
        if (l.status === 'failed') {
            // ── Failed — show the error message clearly ──────────────────────────
            const errMsg = l.error || l.newStock || 'Operation failed';
            details = `<span class="small"><i class="fa-solid fa-circle-exclamation text-danger me-1"></i><span class="text-danger fw-semibold">Error:</span> <span class="text-dark">${errMsg}</span></span>`;

        } else if (l.event === 'mapping') {
            // ── Mapping — link, unlink, relink, or bulk auto-match summary ───────
            const isBulkSummary = l.product === 'Bulk Auto-Match';
            const srcLower = (l.source || '').toLowerCase();
            
            let isUnlinked = false;
            let isNewLink = false;

            if (srcLower.includes('unlink')) {
                isUnlinked = true;
            } else if (srcLower.includes('link') && !srcLower.includes('unlink')) {
                isNewLink = true;
            } else {
                isUnlinked = (l.newStock === 'Unmapped' || l.newStock === '—' || !l.newStock);
                isNewLink  = (l.oldStock === '—' || l.oldStock === 'Unmapped' || !l.oldStock);
            }

            if (isBulkSummary) {
                details = `<span class="small"><i class="fa-solid fa-wand-magic-sparkles me-1" style="color:#6610f2"></i><span class="text-dark">${l.newStock}</span></span>`;
            } else {
                const getPopoverHtml = (text, iconColor) => {
                    const safeName = (l.posName || 'Unknown POS Product').replace(/"/g, '&quot;');
                    const popContent = `<div class='text-center fw-bold' style='user-select:all; word-break:break-word; line-height:1.4;'>${safeName}</div>`;
                    const popAttr = `tabindex="0" data-bs-toggle="popover" data-bs-placement="top" data-bs-trigger="hover" data-bs-custom-class="shopee-popover" title="<i class='fa-solid fa-boxes-stacked me-1'></i> Mapped POS Product" data-bs-content="${popContent}"`;
                    return `<a href="javascript:void(0)" role="button" class="text-decoration-none" style="color:inherit; outline:none;" ${popAttr}>${text} <i class="fa-solid fa-circle-info ms-1" style="font-size:0.75rem; color:${iconColor}"></i></a>`;
                };

                if (isUnlinked) {
                    const displayOld = (l.oldStock === 'Unmapped' || !l.oldStock) ? '[No SKU]' : l.oldStock;
                    const noSkuHtml = getPopoverHtml(displayOld, 'var(--text-secondary)');
                    details = `<span class="small"><i class="fa-solid fa-link-slash me-1 text-danger"></i><span class="text-secondary">Unlinked from POS SKU:</span> <del class="font-monospace text-muted ms-1" style="background:rgba(220,53,69,0.06);padding:2px 7px;border-radius:4px;border:1px solid rgba(220,53,69,0.15)">${noSkuHtml}</del></span>`;
                } else if (isNewLink) {
                    const displayNew = (l.newStock === 'Unmapped' || !l.newStock) ? '[No SKU]' : l.newStock;
                    const noSkuHtml = getPopoverHtml(displayNew, '#198754');
                    details = `<span class="small"><i class="fa-solid fa-link me-1" style="color:#198754"></i><span class="text-secondary">Linked to POS SKU:</span> <span class="font-monospace fw-semibold ms-1" style="background:rgba(25,135,84,0.08);color:#198754;padding:2px 7px;border-radius:4px;border:1px solid rgba(25,135,84,0.2)">${noSkuHtml}</span></span>`;
                } else {
                    const newHtml = getPopoverHtml(l.newStock, '#6366f1');
                    details = `<span class="small"><i class="fa-solid fa-arrows-rotate me-1" style="color:#6610f2"></i><span class="text-secondary">Relinked:</span> <del class="font-monospace text-muted ms-1" style="font-size:0.8rem;padding:2px 6px;background:rgba(0,0,0,0.04);border-radius:4px">${l.oldStock}</del> <i class="fa-solid fa-arrow-right mx-1" style="color:#ee4d2d;font-size:0.72rem"></i> <span class="font-monospace fw-semibold" style="background:rgba(99,102,241,0.08);color:#6366f1;padding:2px 7px;border-radius:4px;border:1px solid rgba(99,102,241,0.2)">${newHtml}</span></span>`;
                }
            }

        } else if (l.event === 'stock_update') {
            // ── Stock Update — Old Value / New Value with diff pill ───────────────
            const newValRaw = l.newStock || '—';
            const newValMatch = newValRaw.match(/^(\d+)\s*\(([^)]+)\)$/);
            const newValNum = newValMatch ? newValMatch[1] : newValRaw;
            const diffLabel = newValMatch ? newValMatch[2] : null;

            if (l.oldStock !== '—' && newValRaw !== '—') {
                const isIncrease = diffLabel && diffLabel.startsWith('+') && !diffLabel.startsWith('+0');
                const isDecrease = diffLabel && (diffLabel.startsWith('-') || diffLabel.includes('Deducted'));
                const isNoChange = !isIncrease && !isDecrease;

                let diffPill = '';
                if (diffLabel) {
                    const pillColor = isNoChange
                        ? 'background:rgba(108,117,125,0.12);color:#6c757d'
                        : isIncrease
                            ? 'background:rgba(25,135,84,0.12);color:#198754'
                            : 'background:rgba(220,53,69,0.12);color:#dc3545';
                    const pillIcon = isNoChange
                        ? '<i class="fa-solid fa-minus me-1" style="font-size:0.7rem"></i>'
                        : isIncrease
                            ? '<i class="fa-solid fa-arrow-trend-up me-1" style="font-size:0.7rem"></i>'
                            : '<i class="fa-solid fa-arrow-trend-down me-1" style="font-size:0.7rem"></i>';
                    diffPill = `<span class="ms-2" style="display:inline-block;padding:1px 7px;border-radius:20px;font-size:0.72rem;font-weight:600;${pillColor}">${pillIcon}${diffLabel}</span>`;
                }
                details = `<span class="small"><span class="text-secondary">Old Value:</span> <strong>${l.oldStock}</strong> <span class="text-secondary ms-2">New Value:</span> <strong>${newValNum}</strong>${diffPill}</span>`;
            } else {
                details = `<span class="small text-secondary">${newValRaw}</span>`;
            }

        } else if (l.event === 'shopee_sku') {
            // ── Shopee SKU — show the SKU value that was set ──────────────────────
            const rawSkuVal = (l.newStock || '').replace('Added/Fix Missing SKU: ', '').trim();
            if (rawSkuVal) {
                details = `<span class="small"><i class="fa-solid fa-tag me-1" style="color:#fd7e14"></i><span class="text-secondary">SKU set to:</span> <span class="font-monospace fw-semibold ms-1" style="background:rgba(253,126,20,0.08);color:#fd7e14;padding:2px 7px;border-radius:4px;border:1px solid rgba(253,126,20,0.2)">${rawSkuVal}</span></span>`;
            } else {
                details = '<span class="small text-secondary">SKU updated</span>';
            }

        } else if (l.event === 'product_import') {
            // ── Product Import — parse sync summary counts ────────────────────────
            const rawImport = l.newStock || '';
            // "50 Synced (12 New, 38 Updated)" — Products Page
            const simpleMatch = rawImport.match(/^(\d+)\s+Synced\s*\((\d+)\s+New,\s*(\d+)\s+Updated\)$/);
            // "Processed: 120 items (Inserted: 30, Updated: 90, Skipped: 0)" — Smart Sync
            const smartMatch  = rawImport.match(/Processed:\s*(\d+)\s*items\s*\(Inserted:\s*(\d+),\s*Updated:\s*(\d+),\s*Skipped:\s*(\d+)\)/);

            const pill = (txt, bg, color, icon) =>
                `<span class="ms-1" style="display:inline-block;padding:1px 7px;border-radius:20px;font-size:0.72rem;font-weight:600;background:${bg};color:${color}"><i class="${icon} me-1" style="font-size:0.7rem"></i>${txt}</span>`;

            if (simpleMatch) {
                const [, total, added, upd] = simpleMatch;
                details = `<span class="small"><span class="text-secondary">Synced:</span> <strong>${total}</strong>${pill(added+' New','rgba(25,135,84,0.12)','#198754','fa-solid fa-plus')}${pill(upd+' Updated','rgba(59,130,246,0.12)','#3b82f6','fa-solid fa-pen')}</span>`;
            } else if (smartMatch) {
                const [, total, ins, upd, skip] = smartMatch;
                const skipPill = parseInt(skip) > 0 ? pill(skip+' Skipped','rgba(108,117,125,0.12)','#6c757d','fa-solid fa-forward') : '';
                details = `<span class="small"><span class="text-secondary">Processed:</span> <strong>${total}</strong> items${pill(ins+' Inserted','rgba(25,135,84,0.12)','#198754','fa-solid fa-plus')}${pill(upd+' Updated','rgba(59,130,246,0.12)','#3b82f6','fa-solid fa-pen')}${skipPill}</span>`;
            } else if (rawImport) {
                details = `<span class="small text-secondary">${rawImport}</span>`;
            }

        } else if (l.event === 'token_refresh') {
            // ── Token Refresh — describe who triggered it and what happened ────────
            const src = l.source || '';
            let tokenIcon, tokenMsg;
            if (src.includes('OAuth')) {
                tokenIcon = '<i class="fa-solid fa-right-to-bracket me-1" style="color:#6366f1"></i>';
                tokenMsg  = 'Shop authorized via OAuth — access & refresh tokens issued';
            } else if (src.includes('Cron') || src.includes('Auto')) {
                tokenIcon = '<i class="fa-solid fa-clock-rotate-left me-1" style="color:#f59e0b"></i>';
                tokenMsg  = 'Token auto-refreshed by background scheduler (≤15 min remaining)';
            } else {
                tokenIcon = '<i class="fa-solid fa-rotate me-1" style="color:#3b82f6"></i>';
                tokenMsg  = 'Token manually refreshed from Settings page';
            }
            details = `<span class="small">${tokenIcon}<span class="text-dark">${tokenMsg}</span></span>`;

        } else {
            details = l.newStock && l.newStock !== '—'
                ? `<span class="small text-secondary">${l.newStock}</span>`
                : '<span class="small text-secondary">—</span>';
        }

        let productHtml = '';
        if (l.product.includes(' || ') || (l.source === 'Error Resolution Center' && l.product.includes(', '))) {
            const multiProducts = l.product.includes(' || ') ? l.product.split(' || ') : l.product.split(', ');
            productHtml = multiProducts.map((mp, index) => {
                let mainName = mp;
                let varName = '';
                
                // Fallback for the older " (Variation)" format from the previous commit
                if (mp.includes(' — ')) {
                    const parts = mp.split(' — ');
                    mainName = parts[0];
                    varName = parts[1] || '';
                } else if (mp.includes(' (') && mp.endsWith(')')) {
                    const parts = mp.split(' (');
                    mainName = parts[0];
                    varName = parts[1].replace(')', '');
                }

                const borderStyle = index !== multiProducts.length - 1 ? 'border-bottom border-light pb-2 mb-2' : '';
                return `
                    <div class="${borderStyle}">
                        <div class="fw-bold text-dark small" style="max-width:500px; word-break:break-word; line-height:1.3;" title="${mainName}"><span class="text-secondary me-1">•</span>${mainName}</div>
                        ${varName ? `<div class="small ms-3" style="font-size:0.75rem; margin-top:2px;">
                            <span class="text-secondary fw-semibold">Variation:</span> 
                            <span class="fw-bold text-dark">${varName}</span>
                        </div>` : ''}
                    </div>
                `;
            }).join('');
        } else {
            const parts = l.product.split(' — ');
            const mainName = parts[0];
            const varName = parts[1] || '';
            productHtml = `
                <div class="fw-bold text-dark small" style="max-width:500px; word-break:break-word; line-height:1.3;" title="${mainName}">${mainName}</div>
                ${varName ? `<div class="small" style="font-size:0.75rem; margin-top:2px;">
                    <span class="text-secondary fw-semibold">Variation:</span> 
                    <span class="fw-bold text-dark">${varName}</span>
                </div>` : ''}
            `;
        }

        return `<tr>
            <td><div class="small text-secondary">${l.ts}</div></td>
            <td>${productHtml}</td>
            <td>${eventBadge}</td>
            <td><div class="text-start">${details}</div></td>
            <td><span class="small fw-semibold text-secondary"><i class="fa-solid fa-user me-1" style="font-size:0.75rem"></i>${l.user}</span></td>
            <td><div class="small text-secondary">${l.source}</div></td>
            <td>${statusBadge}</td>
        </tr>`;
    }).join('');
}

document.addEventListener('DOMContentLoaded', () => {
    // Use Popover event delegation globally to completely bypass dynamic rendering bugs!
    if (typeof bootstrap !== 'undefined') {
        new bootstrap.Popover(document.body, {
            selector: '[data-bs-toggle="popover"]',
            html: true
        });
    }
    renderLogs();
});

</script>

<script src="../../views/shopee/shopee_alerts.js"></script>
<?php require_once '../../includes/footer.php'; ?>

<?php
$page_title = 'Shopee Sync — Product Mapping';
require_once '../../config/config.php';
require_once '../../includes/auth.php';
requirePermission('shopee_sync');
require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';

$db = new Database();
$conn = $db->getConnection();
$hasProductsStmt = $conn->query("SELECT 1 FROM shopee_product_mappings LIMIT 1");
$hasProducts = (bool)$hasProductsStmt->fetchColumn();
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/shopee-sync.css?v=<?= filemtime(__DIR__.'/../../assets/css/shopee-sync.css') ?>">
<style>
.match-hero { text-align:center; padding:4rem 2rem; background:var(--bg-surface); border:1px solid var(--border-color); border-radius:var(--sp-radius-lg); box-shadow:var(--sp-shadow-sm); }
.match-hero-icon { width:80px;height:80px;border-radius:50%;margin:0 auto 1.5rem;background:var(--shopee-light);display:flex;align-items:center;justify-content:center;font-size:2rem;color:var(--shopee-primary); }
.map-panel { max-height: 350px; overflow-y: auto; padding-right: .5rem; }
.map-item { padding:.75rem 1rem; border:1px solid var(--border-color); border-radius:var(--sp-radius-sm); margin-bottom:.4rem; cursor:pointer; transition:all .2s; display:flex; align-items:center; gap:.75rem; }
.map-item:hover,.map-item.selected { border-color:var(--shopee-primary); background:var(--shopee-light); }
.map-item.selected { box-shadow:0 0 0 2px rgba(238,77,45,.2); }
.unit-map-note { border:1px dashed rgba(238,77,45,.35); background:rgba(238,77,45,.06); border-radius:8px; padding:.65rem .75rem; }
.unit-result-badge { display:inline-flex; align-items:center; gap:.3rem; border:1px solid rgba(13,202,240,.35); background:rgba(13,202,240,.12); color:#087990; border-radius:999px; padding:.15rem .45rem; font-size:.68rem; font-weight:700; }
.filter-count { display:inline-flex;align-items:center;justify-content:center;min-width:1.3rem;height:1.3rem;padding:0 4px;margin-left:4px;font-size:0.65rem;font-weight:700;border-radius:20px;background:rgba(0,0,0,0.12);color:inherit;vertical-align:middle;line-height:1; }
.sp-pill.active .filter-count { background:rgba(255,255,255,0.3); }
    /* Popover Custom Styling */
    .shopee-popover {
        --bs-popover-max-width: 300px;
        --bs-popover-border-color: rgba(238, 77, 45, 0.25);
        --bs-popover-header-bg: #ee4d2d;
        --bs-popover-header-color: #fff;
        --bs-popover-body-padding-x: 0.75rem;
        --bs-popover-body-padding-y: 0.6rem;
        border-radius: 8px;
        box-shadow: 0 4px 16px rgba(238, 77, 45, 0.12);
        border: 1px solid var(--bs-popover-border-color);
        font-size: 0.82rem;
    }
    .shopee-popover .popover-header {
        font-weight: 600;
        font-size: 0.78rem;
        border-bottom: none;
        border-top-left-radius: 7px;
        border-top-right-radius: 7px;
        text-align: center;
        padding: 0.35rem 0.75rem;
        letter-spacing: 0.3px;
    }
    .shopee-popover .popover-body {
        background-color: #fff;
        color: #333;
        font-size: 0.82rem;
        border-bottom-left-radius: 7px;
        border-bottom-right-radius: 7px;
        padding: 0.5rem 0.75rem;
    }
    .shopee-popover .popover-arrow::before {
        border-top-color: rgba(238, 77, 45, 0.25);
    }
    .shopee-popover .popover-arrow::after {
        border-top-color: #fff;
    }

</style>

<div class="sp-page sp-animate">
<div class="sp-breadcrumb">
    <a href="<?= BASE_URL ?>views/shopee/index.php">Shopee Sync</a>
    <i class="fa-solid fa-chevron-right" style="font-size:.6rem"></i><span>Product Mapping</span>
</div>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-3">
    <div>
        <h1 class="sp-title mb-0"><i class="fa-solid fa-link text-shopee me-2"></i>Product Mapping</h1>
        <p class="sp-subtitle mb-0">Match Shopee products to POS inventory by SKU. Products and their variations are grouped together.</p>
    </div>
    <div class="d-flex align-items-center gap-3" id="headerBtns">
        <span class="small text-secondary d-flex align-items-center gap-1" style="opacity:.75"><i class="fa-solid fa-circle-check text-success" style="font-size:.75rem"></i>Changes auto-saved</span>
        <button class="btn btn-outline-shopee" id="headerAutoMatchBtn" disabled onclick="runAutoMatch(false, this)"><i class="fa-solid fa-spinner fa-spin me-2"></i>Loading...</button>
    </div>
</div>

<!-- Stats (hidden until match runs) -->
<div class="row g-3 mb-4" id="statsRow" <?php if (!$hasProducts) echo 'style="display:none"'; ?>>
    <div class="col-md-3 col-6">
        <div class="sp-stat-card accent-success">
            <div class="sp-stat-icon" style="background:var(--sp-success-bg);color:var(--sp-success)"><i class="fa-solid fa-link"></i></div>
            <div><div class="sp-stat-label">Total Matched</div><div class="sp-stat-value" id="cntMatched">0</div></div>
        </div>
    </div>
    <div class="col-md-3 col-6">
        <div class="sp-stat-card accent-warning">
            <div class="sp-stat-icon" style="background:var(--sp-warning-bg);color:var(--sp-warning)"><i class="fa-solid fa-link-slash"></i></div>
            <div><div class="sp-stat-label">Total Unmatched</div><div class="sp-stat-value" id="cntUnmatched">0</div></div>
        </div>
    </div>
    <div class="col-md-3 col-6">
        <div class="sp-stat-card accent-shopee">
            <div class="sp-stat-icon" style="background:var(--shopee-light);color:var(--shopee-primary)"><i class="fa-solid fa-clone"></i></div>
            <div><div class="sp-stat-label">Duplicate SKUs</div><div class="sp-stat-value" id="cntDupes">0</div></div>
        </div>
    </div>
    <div class="col-md-3 col-6">
        <div class="sp-stat-card accent-danger">
            <div class="sp-stat-icon" style="background:var(--sp-danger-bg);color:var(--sp-danger)"><i class="fa-solid fa-triangle-exclamation"></i></div>
            <div><div class="sp-stat-label">Missing SKUs</div><div class="sp-stat-value" id="cntMissing">0</div></div>
        </div>
    </div>
</div>

<!-- Idle State -->
<?php if (!$hasProducts): ?>
<div id="idleState">
    <div class="match-hero">
        <div class="match-hero-icon"><i class="fa-solid fa-bag-shopping"></i></div>
        <h4 class="fw-bold mb-2">No Shopee Products Found</h4>
        <p class="text-secondary mb-4">Sync products from Shopee first before mapping.</p>
        <a href="<?= BASE_URL ?>views/shopee/products.php" class="btn btn-shopee"><i class="fa-solid fa-bag-shopping me-2"></i>Go to Products</a>
    </div>
</div>
<?php endif; ?>

<!-- Results State -->
<div id="resultsState" <?php if (!$hasProducts) echo 'style="display:none"'; ?>>
    <!-- Filter -->
    <div class="sp-card mb-4">
        <div class="sp-card-body d-flex flex-wrap align-items-center gap-3" style="padding:.85rem 1.25rem">
            <div class="sp-search flex-grow-1" style="max-width:340px">
                <i class="fa-solid fa-search"></i>
                <input type="text" id="mapSearch" autocomplete="off" placeholder="Search by name or SKU..." oninput="debouncedRender()">
            </div>
            <div class="sp-filter-pills ms-2">
                <button class="sp-pill active" onclick="setFilter('all',this)">All <span class="filter-count" id="fc-all">0</span></button>
                <button class="sp-pill" onclick="setFilter('mapped',this)"><i class="fa-solid fa-link me-1"></i>Matched <span class="filter-count" id="fc-matched">0</span></button>
                <button class="sp-pill" onclick="setFilter('unmapped',this)"><i class="fa-solid fa-link-slash me-1"></i>Unmatched <span class="filter-count" id="fc-unmapped">0</span></button>
                <button class="sp-pill" onclick="setFilter('dupes',this)"><i class="fa-solid fa-clone me-1"></i>Duplicate SKUs <span class="filter-count" id="fc-dupes">0</span></button>
                <button class="sp-pill" onclick="setFilter('missing',this)"><i class="fa-solid fa-triangle-exclamation me-1"></i>Missing SKUs <span class="filter-count" id="fc-missing">0</span></button>
            </div>
            <div class="ms-auto d-flex gap-2">
                <button class="btn btn-outline-shopee btn-sm" id="reRunBtn" disabled onclick="runAutoMatch(true, this)"><i class="fa-solid fa-spinner fa-spin me-1"></i>Loading...</button>
            </div>
        </div>
    </div>


    <!-- Mapping Table (Grouped) -->
    <div class="sp-card mb-4">
        <div class="sp-card-body p-0 sp-table-wrap">
            <table class="sp-table">
                <thead>
                    <tr>
                        <th style="min-width:200px">Product / Variation</th>
                        <th>Parent SKU & Item ID</th>
                        <th>Variation SKU</th>
                        <th class="text-center"><i class="fa-solid fa-arrows-left-right"></i></th>
                        <th>POS Match</th>
                        <th>Status</th>
                        <th class="text-end">Action</th>
                    </tr>
                </thead>
                <tbody id="mapTableBody">
                    <tr>
                        <td colspan="7">
                            <div class="text-center py-5">
                                <i class="fa-solid fa-spinner fa-spin text-shopee" style="font-size:2rem"></i>
                                <div class="mt-2 text-secondary fw-bold">Loading your mapping data...</div>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <!-- Premium Pagination Footer -->
        <div class="sp-card-footer d-flex align-items-center justify-content-between flex-wrap gap-3 border-top p-3">
            <div class="d-flex align-items-center gap-2">
                <span class="text-secondary small">Items per page:</span>
                <select class="form-select form-select-sm" id="itemsPerPageSelect" onchange="changeItemsPerPage(this.value)" style="width: auto;">
                    <option value="10">10</option>
                    <option value="25" selected>25</option>
                    <option value="50">50</option>
                    <option value="100">100</option>
                </select>
            </div>
            <div class="text-secondary small fw-bold" id="paginationStatus">Page 1 of 1 (0 items)</div>
            <div class="d-flex align-items-center gap-1" id="paginationButtons"></div>
        </div>
    </div>

</div> <!-- Closes #resultsState -->
</div> <!-- Closes .sp-page (removes relative z-index context to prevent modal backdrop bugs) -->

<!-- Manual Map Modal -->
<div class="modal fade sp-modal" id="manualMapModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold"><i class="fa-solid fa-link text-shopee me-2"></i>Map to POS Product</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" style="filter:var(--btn-close-filter)"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3 p-3 rounded" style="background:var(--shopee-light); border:1px solid rgba(238,77,45,0.2)">
                    <div class="small fw-bold text-shopee mb-1">Shopee Item to Map:</div>
                    <div class="fw-bold fs-6" id="mmShopeeName"></div>
                    <div class="text-secondary small mt-1">SKU: <span id="mmShopeeSku" class="sp-sku-code"></span></div>
                </div>
                
                <h6 class="fw-bold mb-2"><i class="fa-solid fa-boxes-stacked me-2" style="color:var(--sp-info)"></i>Select POS Product</h6>
                <div class="unit-map-note d-flex align-items-center justify-content-between gap-3 mb-3">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" role="switch" id="mmUnitToggle" onchange="toggleModalUnitSearch()">
                        <label class="form-check-label small fw-bold" for="mmUnitToggle">Search per unit mapping</label>
                    </div>
                    <a class="small fw-bold text-shopee text-decoration-none" href="<?= BASE_URL ?>views/inventory/unit_types.php" target="_blank">
                        <i class="fa-solid fa-layer-group me-1"></i>Manage Units
                    </a>
                </div>
                <div class="sp-search mb-3">
                    <i class="fa-solid fa-search"></i>
                    <input type="text" id="mmPosSearch" autocomplete="off" placeholder="Search POS by name or SKU..." oninput="debouncedRenderModalPos()">
                </div>
                
                <div id="mmPosPanel" class="map-panel"></div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-shopee" id="mmLinkBtn" disabled onclick="linkFromModal()"><i class="fa-solid fa-link me-2"></i>Link Selected</button>
            </div>
        </div>
    </div>
</div>

<script>
window.BASE_URL = '<?= BASE_URL ?>';
let manualMapModal;
document.addEventListener('DOMContentLoaded', () => {
    // Use Popover event delegation globally — hover to show, mouseout to hide
    if (typeof bootstrap !== 'undefined') {
        new bootstrap.Popover(document.body, {
            selector: '[data-bs-toggle="popover"]',
            html: true,
            trigger: 'hover',
            placement: 'top'
        });
    }

    manualMapModal = new bootstrap.Modal(document.getElementById('manualMapModal'));
    
    // Clear session storage if arriving from navigation (not reload)
    const navEntries = window.performance.getEntriesByType('navigation');
    if (navEntries.length > 0 && navEntries[0].type !== 'reload') {
        sessionStorage.removeItem('shopee_mapSearch');
        sessionStorage.removeItem('shopee_map_filter');
        sessionStorage.removeItem('shopee_map_currentPage');
        sessionStorage.removeItem('shopee_map_itemsPerPage');
        activeFilter = 'all';
        currentPage = 1;
    }
    
    // Restore search input
    const savedSearch = sessionStorage.getItem('shopee_mapSearch');
    const searchInput = document.getElementById('mapSearch');
    if (savedSearch && searchInput) {
        searchInput.value = savedSearch;
    }
    
    // Restore limits dropdown
    const limitSel = document.getElementById('itemsPerPageSelect');
    if(limitSel) limitSel.value = itemsPerPage;
    
    // Restore filter button
    document.querySelectorAll('.sp-filter-pills .sp-pill').forEach(p => p.classList.remove('active'));
    const activeBtn = document.querySelector(`.sp-filter-pills .sp-pill[onclick*="setFilter('${activeFilter}'"]`) || document.querySelector('.sp-filter-pills .sp-pill');
    if(activeBtn) activeBtn.classList.add('active');
    
    fetchMappingData();
});

let GROUPS = [];
let ALL_ITEMS = [];
let POS_ITEMS = [];
const POS_SKU_MAP = {};

async function fetchMappingData() {
    try {
        const resultsState = document.getElementById('resultsState');
        if(resultsState && !resultsState.style.display || resultsState.style.display === 'none') {
            // Show a basic loader if needed
        }

        // 1. Fetch Shopee Mappings first to display the table immediately
        const mapRes = await fetch(`${window.BASE_URL}api/shopee/get_mappings.php`);
        const data = await mapRes.json();
        
        if (data.groups !== undefined) {
            GROUPS = data.groups;
            window.shopeeDuplicateWhitelist = new Set((data.whitelist || []).map(sku => sku.toLowerCase().trim()));
        } else {
            GROUPS = data; // Fallback for old cache
            window.shopeeDuplicateWhitelist = new Set();
        }
        GROUPS.forEach(g => g.variations.forEach(v => {
            v.groupName = g.name;
            v.itemId = g.itemId;
            ALL_ITEMS.push(v);
        }));
        
        if(ALL_ITEMS.length > 0) {
            showResults();
        }

        // 2. Fetch POS Items silently in the background
        fetch(`${window.BASE_URL}api/shopee/get_pos_items.php`)
            .then(res => res.json())
            .then(data => {
                POS_ITEMS = data;
                POS_ITEMS.filter(p => p.item_type === 'base').forEach(p => {
                    if (!p.sku) return;
                    const sku = String(p.sku).trim().toLowerCase();
                    if (!POS_SKU_MAP[sku]) POS_SKU_MAP[sku] = [];
                    POS_SKU_MAP[sku].push(p);
                });
                
                // Re-enable Auto-Match buttons
                const headerBtn = document.getElementById('headerAutoMatchBtn');
                if (headerBtn) {
                    headerBtn.disabled = false;
                    headerBtn.innerHTML = '<i class="fa-solid fa-wand-magic-sparkles me-2"></i>Auto-Match';
                }
                const reRunBtn = document.getElementById('reRunBtn');
                if (reRunBtn) {
                    reRunBtn.disabled = false;
                    reRunBtn.innerHTML = '<i class="fa-solid fa-wand-magic-sparkles me-1"></i>Re-run Auto-Match';
                }
            })
            .catch(e => console.error('Failed to fetch POS items asynchronously', e));

    } catch(e) {
        console.error('Failed to fetch data asynchronously', e);
    }
}

async function fetchPosItems() {
    // Legacy support
};

let activeFilter = sessionStorage.getItem('shopee_map_filter') || 'all';
let selectedShopee=null, selectedPosId=null, selectedPosUnitId=null, selectedPosBundleSetId=null;
let shopeeSkuCounts = {};
let renderTimeout = null;
let currentPage = parseInt(sessionStorage.getItem('shopee_map_currentPage')) || 1;
let itemsPerPage = parseInt(sessionStorage.getItem('shopee_map_itemsPerPage')) || 25;
let pendingAutoMatches = [];
let pendingUnlink = null;

function debouncedRender() {
    currentPage = 1; // Reset page on search
    sessionStorage.setItem('shopee_map_currentPage', currentPage);
    const searchInput = document.getElementById('mapSearch');
    if (searchInput) sessionStorage.setItem('shopee_mapSearch', searchInput.value);
    clearTimeout(renderTimeout);
    renderTimeout = setTimeout(() => renderTable(), 250);
}

function escHtml(s){if(!s)return'';return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}
function searchTerms(q) {
    return String(q || '').toLowerCase().trim().split(/\s+/).filter(Boolean);
}
function progressiveMatch(terms, fields) {
    if (!terms.length) return true;
    const haystack = fields.map(v => String(v || '').toLowerCase());
    return terms.every(term => haystack.some(field => field.includes(term)));
}
function getMatchKey(v){return v.hasVariation?(v.variationSku||''):(v.parentSku||'');}
function samePosChoice(p, posId, unitId) {
    if (p && p.item_type === 'bundle') {
        return (p.bundle_set_id || null) === (posId || null);
    }
    return p && p.id === posId && (p.unit_id || null) === (unitId || null);
}
function findMappedPosItem(v) {
    if (!v || (!v.posId && !v.posBundleSetId)) return null;
    if (v.posBundleSetId) {
        return POS_ITEMS.find(p => p.item_type === 'bundle' && p.bundle_set_id === v.posBundleSetId) || null;
    }
    return POS_ITEMS.find(p => samePosChoice(p, v.posId, v.posUnitId || null))
        || POS_ITEMS.find(p => p.id === v.posId && p.item_type === 'base')
        || null;
}
function formatPosChoiceName(p) {
    if (!p) return '';
    const parts = [p.product_name || ''];
    if (p.item_type === 'bundle') parts.push('- Bundle Set');
    if (p.variation_name) parts.push(`(${p.variation_name})`);
    if (p.item_type === 'unit') parts.push(`- ${p.unit_name || 'Custom Unit'} x${p.multiplier || 1}`);
    return parts.join(' ');
}
function formatPosChoiceBadge(p) {
    if (!p) return '';
    const sku = escHtml(p.sku || 'No SKU');
    if (p.item_type === 'bundle') {
        return `<span class="sp-badge sp-badge-info">${sku}</span><span class="unit-result-badge ms-1"><i class="fa-solid fa-boxes-stacked"></i>Bundle Set${p.component_count ? ` (${p.component_count} items)` : ''}</span>`;
    }
    if (p.item_type === 'unit') {
        return `<span class="sp-badge sp-badge-success">${sku}</span><span class="unit-result-badge ms-1"><i class="fa-solid fa-boxes-stacked"></i>${escHtml(p.unit_name || 'Unit')} x${p.multiplier || 1}</span>`;
    }
    return `<span class="sp-badge sp-badge-success">${sku}</span>`;
}

function showResults(){
    const idle = document.getElementById('idleState');
    if (idle) idle.style.display='none';
    document.getElementById('resultsState').style.display='block';
    document.getElementById('statsRow').style.display='flex';
    document.getElementById('headerBtns').style.display='flex';
    updateCounts();renderTable();
}
function showManualOnly(){showResults();}

async function runAutoMatch(isReRun = false, btn = null) {
    // Cancel any pending inline unlink confirmation
    if (pendingUnlink) cancelUnlink();

    let origHtml = '';
    if (btn) {
        origHtml = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-2"></i>Matching...';
    }

    let count = 0;
    pendingAutoMatches = [];

    // If re-running, process all non-missing items; otherwise only unmapped ones
    const filterCondition = isReRun
        ? (v => v.mapStatus !== 'missing_sku')
        : (v => !v.mapped && v.mapStatus !== 'missing_sku');

    const itemsToProcess = ALL_ITEMS.filter(filterCondition);

    itemsToProcess.forEach(v => {
        const key = String(getMatchKey(v) || '').trim().toLowerCase();
        if (!key) return;

        const isDuplicate = shopeeSkuCounts[key] > 1;
        const isWhitelisted = window.shopeeDuplicateWhitelist && window.shopeeDuplicateWhitelist.has(key);

        // Skip auto-matching if it's a conflict that hasn't been whitelisted
        if (isDuplicate && !isWhitelisted) {
            v.mapStatus = 'duplicate';
            return;
        }

        const matches = (POS_SKU_MAP[key] || []).filter(p => isReRun ? true : !p.used);

        if (matches.length === 1) {
            v.mapped = true;
            v.posId = matches[0].id;
            v.posUnitId = matches[0].unit_id || null;
            v.matchedPosSku = matches[0].sku;
            v.mapStatus = 'auto';
            matches[0].used = true;

            pendingAutoMatches.push({
                id: v.id,
                posSku: matches[0].sku,
                posId: matches[0].id,
                posUnitId: matches[0].unit_id || null,
                status: 'auto',
                displayName: v.groupName + (v.varName ? ` — ${v.varName}` : ''),
                posSkuDisplay: matches[0].sku
            });
            count++;
        } else if (matches.length > 1) {
            v.mapStatus = 'duplicate';
        }
    });

    if (count === 0) {
        if (btn) { btn.disabled = false; btn.innerHTML = origHtml; }
        if (typeof EllaToast !== 'undefined') EllaToast.warning('No new auto-match opportunities found.');
        return;
    }

    try {
        const res = await fetch(`${window.BASE_URL}api/shopee/save_mappings.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                mappings: pendingAutoMatches.map(m => ({ id: m.id, posSku: m.posSku, posId: m.posId, posUnitId: m.posUnitId, status: m.status })),
                trigger: isReRun ? 're_run_auto_match' : 'auto_match'
            })
        });
        const data = await res.json();
        
        if (btn) { btn.disabled = false; btn.innerHTML = origHtml; }
        
        if (data.success) {
            if (typeof EllaToast !== 'undefined') {
                EllaToast.success(`Auto-Match Applied! Linked ${count} product${count !== 1 ? 's' : ''} successfully.`);
            }
            updateCounts();
            renderTable();
        } else {
            throw new Error(data.error || 'Failed to save auto-matches');
        }
    } catch (e) {
        if (btn) { btn.disabled = false; btn.innerHTML = origHtml; }
        if (typeof EllaToast !== 'undefined') EllaToast.error(e.message || 'Network error during save');
        window.location.reload();
    }
}

function setFilter(f,btn){
    activeFilter=f;
    sessionStorage.setItem('shopee_map_filter', f);
    currentPage = 1; // Reset page on filter change
    sessionStorage.setItem('shopee_map_currentPage', currentPage);
    document.querySelectorAll('.sp-filter-pills .sp-pill').forEach(p=>p.classList.remove('active'));
    btn.classList.add('active');renderTable();
}

function changeItemsPerPage(val) {
    itemsPerPage = parseInt(val, 10);
    sessionStorage.setItem('shopee_map_itemsPerPage', itemsPerPage);
    currentPage = 1;
    sessionStorage.setItem('shopee_map_currentPage', currentPage);
    renderTable();
}

function goToPage(page) {
    currentPage = page;
    sessionStorage.setItem('shopee_map_currentPage', currentPage);
    renderTable();
}

function renderTable(){
    const q=(document.getElementById('mapSearch')?.value||'');
    const terms = searchTerms(q);
    const body=document.getElementById('mapTableBody');

    // First, filter groups
    const matchedGroups = [];
    GROUPS.forEach(g=>{
        const vars=g.variations.filter(v=>{
            if(!progressiveMatch(terms, [g.name, v.variationSku, v.parentSku, (g.variations[0] && g.variations[0].parentSku), v.varName, g.itemId])) return false;
            if(activeFilter==='mapped'&&!v.mapped)return false;
            if(activeFilter==='unmapped'&&v.mapStatus!=='unmapped')return false;
            if(activeFilter==='dupes') {
                const key = (getMatchKey(v) || '').toLowerCase().trim();
                if (!key || !shopeeSkuCounts[key] || shopeeSkuCounts[key] <= 1 || (window.shopeeDuplicateWhitelist && window.shopeeDuplicateWhitelist.has(key))) return false;
            }
            if(activeFilter==='missing'&&v.mapStatus!=='missing_sku')return false;
            return true;
        });
        if(vars.length > 0) {
            matchedGroups.push({ group: g, matchedVars: vars });
        }
    });

    const totalItems = matchedGroups.length;
    const totalPages = Math.ceil(totalItems / itemsPerPage) || 1;
    if (currentPage > totalPages) currentPage = totalPages;

    const start = (currentPage - 1) * itemsPerPage;
    const end = start + itemsPerPage;
    const slice = matchedGroups.slice(start, end);

    let html = '';
    slice.forEach(item => {
        const g = item.group;
        const vars = item.matchedVars;

        const parentSkuHtml = g.variations[0] && g.variations[0].parentSku
            ? `<span class="sp-sku-code">${escHtml(g.variations[0].parentSku)}</span>`
            : `<span class="text-danger" style="font-size:.75rem;font-style:italic">empty</span>`;

        const imgHtml = g.imageUrl
            ? `<img src="${escHtml(g.imageUrl)}" class="sp-product-img" alt="Product Image">`
            : `<div class="sp-img-placeholder"><i class="fa-solid fa-image"></i></div>`;

        const isSimple = g.variations.length === 1 && (!g.variations[0].varName || g.variations[0].varName.toLowerCase() === 'main item');

        if (isSimple) {
            const v = vars[0];
            if (v) {
                const posItem = findMappedPosItem(v);
                const posSku = v.matchedPosSku || (posItem ? posItem.sku : '');
                let statusBadge='';
                switch(v.mapStatus){
                    case 'auto':        statusBadge=`<span class="sp-badge sp-badge-success"><i class="fa-solid fa-wand-magic-sparkles"></i> Auto</span>`;break;
                    case 'manual':      statusBadge=`<span class="sp-badge sp-badge-info"><i class="fa-solid fa-hand-pointer"></i> Manual</span>`;break;
                    case 'unmapped':    statusBadge=`<span class="sp-badge sp-badge-warning"><i class="fa-solid fa-link-slash"></i> Unmatched</span>`;break;
                    case 'duplicate':   statusBadge=`<span class="sp-badge sp-badge-danger"><i class="fa-solid fa-clone"></i> Dup SKU</span>`;break;
                    case 'missing_sku': statusBadge=`<span class="sp-badge sp-badge-danger"><i class="fa-solid fa-triangle-exclamation"></i> No SKU</span>`;break;
                    default:            statusBadge=`<span class="sp-badge sp-badge-neutral">${escHtml(v.mapStatus)}</span>`;
                }
                const posCell=posSku?(posItem ? formatPosChoiceBadge(posItem) : `<span class="sp-badge sp-badge-success">${escHtml(posSku)}</span>`):`<span class="text-secondary">—</span>`;
                let linkPopover = '';
                if (v.mapped && posItem) {
                    const safeName = escHtml(posItem.product_name || '');
                    const safeVar = posItem.variation_name ? escHtml(posItem.variation_name) : '';
                    const varHtml = safeVar ? `<div style='font-size:0.75rem;color:#ee4d2d;margin-top:2px;font-weight:600'>${safeVar}</div>` : '';
                    const unitHtml = posItem.item_type === 'unit' ? `<div style='font-size:0.72rem;color:#6366f1;margin-top:3px;font-weight:600;border-top:1px solid rgba(0,0,0,0.08);padding-top:3px'>1 Shopee unit deducts ${posItem.multiplier || 1} ${escHtml(posItem.base_unit_type || 'pcs')}</div>` : '';
                    let popContent = `<div style='text-align:center;word-break:break-word;line-height:1.3;font-size:0.82rem;max-width:280px'><div style='font-weight:600;color:#1e293b'>${safeName}</div>${varHtml}${unitHtml}</div>`;
                    popContent = popContent.replace(/"/g, '&quot;');
                    linkPopover = `tabindex="0" data-bs-toggle="popover" data-bs-placement="top" data-bs-trigger="hover" data-bs-custom-class="shopee-popover" title="<i class='fa-solid fa-boxes-stacked me-1'></i> Mapped POS Product" data-bs-content="${popContent}"`;
                }
                const linkIcon=v.mapped?`<a href="javascript:void(0)" role="button" class="text-shopee text-decoration-none" style="cursor:pointer;padding:6px;" ${linkPopover}><i class="fa-solid fa-link"></i></a>`:`<i class="fa-solid fa-link-slash text-secondary" style="opacity:.3"></i>`;
                const actionBtn = v.mapped 
                    ? `<button class="btn btn-sm btn-ghost text-danger" onclick="unlinkItem(${v.id}, this)"><i class="fa-solid fa-unlink me-1"></i>Unlink</button>`
                    : `<button class="btn btn-sm btn-outline-shopee" onclick="openManualMap(${v.id})"><i class="fa-solid fa-link me-1"></i>Map</button>`;

                html += `<tr class="sp-group-start">
                    <td>
                        <div class="d-flex align-items-center gap-3">
                            ${imgHtml}
                            <div>
                                <div class="d-flex align-items-center gap-2 mb-1">
                                    <i class="fa-brands fa-shopee" style="color:var(--shopee-primary);font-size:.85rem;flex-shrink:0"></i>
                                    <span class="fw-bold" style="font-size:.9rem">${escHtml(g.name)}</span>
                                </div>
                            </div>
                        </div>
                    </td>
                    <td>
                        <div class="d-flex flex-column">
                            ${parentSkuHtml}
                            <span class="small text-secondary" style="font-size:0.72rem;margin-top:2px">ID: ${escHtml(g.itemId)}</span>
                        </div>
                    </td>
                    <td>${(() => {
                        const key = (getMatchKey(v) || '').toLowerCase().trim();
                        const isDuplicate = key && shopeeSkuCounts[key] > 1;
                        return isDuplicate
                            ? `<span class="sp-sku-code">${escHtml(v.variationSku || v.parentSku)}</span><span class="badge bg-danger-light text-danger ms-1" style="font-size:0.65rem; border:1px solid rgba(220,53,69,0.3);"><i class="fa-solid fa-clone"></i> Shared SKU</span>`
                            : `<span class="sp-sku-code">${escHtml(v.variationSku || v.parentSku)}</span>`;
                    })()}</td>
                    <td class="text-center">${linkIcon}</td>
                    <td>${posCell}</td>
                    <td>${statusBadge}</td>
                    <td class="text-end">${actionBtn}</td>
                </tr>`;
            }
        } else {
            // Parent Row
            html += `<tr class="sp-group-start">
                <td>
                    <div class="d-flex align-items-center gap-3">
                        ${imgHtml}
                        <div>
                            <div class="d-flex align-items-center gap-2 mb-1">
                                <i class="fa-brands fa-shopee" style="color:var(--shopee-primary);font-size:.85rem;flex-shrink:0"></i>
                                <span class="fw-bold" style="font-size:.9rem">${escHtml(g.name)}</span>
                            </div>
                        </div>
                    </div>
                </td>
                <td>
                    <div class="d-flex flex-column">
                        ${parentSkuHtml}
                        <span class="small text-secondary" style="font-size:0.72rem;margin-top:2px">ID: ${escHtml(g.itemId)}</span>
                    </div>
                </td>
                <td><span class="text-secondary">—</span></td>
                <td class="text-center"><span class="text-secondary">—</span></td>
                <td><span class="text-secondary">—</span></td>
                <td><span class="text-secondary">—</span></td>
                <td class="text-end"><span class="text-secondary">—</span></td>
            </tr>`;

            // Variation Rows
            vars.forEach(v=>{
                const pos=findMappedPosItem(v);
                let statusBadge='';
                switch(v.mapStatus){
                    case 'auto':        statusBadge=`<span class="sp-badge sp-badge-success"><i class="fa-solid fa-wand-magic-sparkles"></i> Auto</span>`;break;
                    case 'manual':      statusBadge=`<span class="sp-badge sp-badge-info"><i class="fa-solid fa-hand-pointer"></i> Manual</span>`;break;
                    case 'unmapped':    statusBadge=`<span class="sp-badge sp-badge-warning"><i class="fa-solid fa-link-slash"></i> Unmatched</span>`;break;
                    case 'duplicate':   statusBadge=`<span class="sp-badge sp-badge-danger"><i class="fa-solid fa-clone"></i> Dup SKU</span>`;break;
                    case 'missing_sku': statusBadge=`<span class="sp-badge sp-badge-danger"><i class="fa-solid fa-triangle-exclamation"></i> No SKU</span>`;break;
                    default:            statusBadge=`<span class="sp-badge sp-badge-neutral">${escHtml(v.mapStatus)}</span>`;
                }
                const posCell=pos?formatPosChoiceBadge(pos):`<span class="text-secondary">—</span>`;
                let linkPopover = '';
                if (v.mapped && pos) {
                    const safeName = escHtml(pos.product_name || '');
                    const safeVar = pos.variation_name ? escHtml(pos.variation_name) : '';
                    const varHtml = safeVar ? `<div style='font-size:0.75rem;color:#ee4d2d;margin-top:2px;font-weight:600'>${safeVar}</div>` : '';
                    const unitHtml = pos.item_type === 'unit' ? `<div style='font-size:0.72rem;color:#6366f1;margin-top:3px;font-weight:600;border-top:1px solid rgba(0,0,0,0.08);padding-top:3px'>1 Shopee unit deducts ${pos.multiplier || 1} ${escHtml(pos.base_unit_type || 'pcs')}</div>` : '';
                    let popContent = `<div style='text-align:center;word-break:break-word;line-height:1.3;font-size:0.82rem;max-width:280px'><div style='font-weight:600;color:#1e293b'>${safeName}</div>${varHtml}${unitHtml}</div>`;
                    popContent = popContent.replace(/"/g, '&quot;');
                    linkPopover = `tabindex="0" data-bs-toggle="popover" data-bs-placement="top" data-bs-trigger="hover" data-bs-custom-class="shopee-popover" title="<i class='fa-solid fa-boxes-stacked me-1'></i> Mapped POS Product" data-bs-content="${popContent}"`;
                }
                const linkIcon=v.mapped?`<a href="javascript:void(0)" role="button" class="text-shopee text-decoration-none" style="cursor:pointer;padding:6px;" ${linkPopover}><i class="fa-solid fa-link"></i></a>`:`<i class="fa-solid fa-link-slash text-secondary" style="opacity:.3"></i>`;
                const actionBtn = v.mapped 
                    ? `<button class="btn btn-sm btn-ghost text-danger" onclick="unlinkItem(${v.id}, this)"><i class="fa-solid fa-unlink me-1"></i>Unlink</button>`
                    : `<button class="btn btn-sm btn-outline-shopee" onclick="openManualMap(${v.id})"><i class="fa-solid fa-link me-1"></i>Map</button>`;
                
                const vNameHtml = v.varName
                    ? `<span class="sp-var-name-text">${escHtml(v.varName)}</span>`
                    : `<span class="sp-var-name-text text-secondary fst-italic">Main Item</span>`;

                html += `<tr>
                    <td class="sp-tree-indent">
                        <div class="d-flex align-items-center">
                            ${vNameHtml}
                        </div>
                    </td>
                    <td><span class="text-secondary">—</span></td>
                    <td>${(() => {
                        const key = (getMatchKey(v) || '').toLowerCase().trim();
                        const isDuplicate = key && shopeeSkuCounts[key] > 1;
                        return isDuplicate
                            ? `<span class="sp-sku-code">${escHtml(v.variationSku || v.parentSku)}</span><span class="badge bg-danger-light text-danger ms-1" style="font-size:0.65rem; border:1px solid rgba(220,53,69,0.3);"><i class="fa-solid fa-clone"></i> Shared SKU</span>`
                            : `<span class="sp-sku-code">${escHtml(v.variationSku || v.parentSku)}</span>`;
                    })()}</td>
                    <td class="text-center">${linkIcon}</td>
                    <td>${posCell}</td>
                    <td>${statusBadge}</td>
                    <td class="text-end">${actionBtn}</td>
                </tr>`;
            });
        }
    });

    // Aggressively remove any stuck popovers from the DOM
    document.querySelectorAll('.popover').forEach(p => p.remove());

    if(!totalItems){
        body.innerHTML=`<tr><td colspan="7"><div class="sp-empty"><i class="fa-solid fa-filter d-block"></i><h5>No items match this filter</h5></div></td></tr>`;
        document.getElementById('paginationStatus').textContent = 'Page 1 of 1 (0 items)';
        document.getElementById('paginationButtons').innerHTML = '';
        return;
    }
    
    body.innerHTML=html;

    document.getElementById('paginationStatus').textContent = `Page ${currentPage} of ${totalPages} (${totalItems} products)`;
    renderPaginationButtons(totalItems, totalPages);

    // Initialize Popovers for newly rendered elements
    if (typeof bootstrap !== 'undefined') {
        const popoverTriggerList = document.querySelectorAll('#mapTableBody [data-bs-toggle="popover"]');
        [...popoverTriggerList].forEach(el => {
            new bootstrap.Popover(el, { html: true, trigger: 'hover', placement: 'top' });
        });
    }
}

function renderPaginationButtons(totalItems, totalPages) {
    const container = document.getElementById('paginationButtons');
    if (!container) return;
    
    let html = '';
    html += `<button class="btn btn-sm btn-outline-shopee-secondary px-2 py-1" ${currentPage === 1 ? 'disabled' : ''} onclick="goToPage(1)"><i class="fa-solid fa-angles-left"></i></button>`;
    html += `<button class="btn btn-sm btn-outline-shopee-secondary px-2 py-1" ${currentPage === 1 ? 'disabled' : ''} onclick="goToPage(${currentPage - 1})"><i class="fa-solid fa-angle-left"></i></button>`;
    
    const maxVisiblePages = 5;
    let startPage = Math.max(1, currentPage - 2);
    let endPage = Math.min(totalPages, startPage + maxVisiblePages - 1);
    if (endPage - startPage < maxVisiblePages - 1) {
        startPage = Math.max(1, endPage - maxVisiblePages + 1);
    }
    
    for (let p = startPage; p <= endPage; p++) {
        if (p === currentPage) {
            html += `<button class="btn btn-sm btn-shopee px-3 py-1 active">${p}</button>`;
        } else {
            html += `<button class="btn btn-sm btn-outline-shopee-secondary px-3 py-1" onclick="goToPage(${p})">${p}</button>`;
        }
    }
    
    html += `<button class="btn btn-sm btn-outline-shopee-secondary px-2 py-1" ${currentPage === totalPages ? 'disabled' : ''} onclick="goToPage(${currentPage + 1})"><i class="fa-solid fa-angle-right"></i></button>`;
    html += `<button class="btn btn-sm btn-outline-shopee-secondary px-2 py-1" ${currentPage === totalPages ? 'disabled' : ''} onclick="goToPage(${totalPages})"><i class="fa-solid fa-angles-right"></i></button>`;
    
    container.innerHTML = html;
}

// Modal Manual Mapping Logic
function openManualMap(id) {
    selectedShopee = id;
    selectedPosId = null;
    selectedPosUnitId = null;
    selectedPosBundleSetId = null;
    const v = ALL_ITEMS.find(x => x.id === id);
    if (!v) return;
    
    document.getElementById('mmShopeeName').textContent = v.groupName + (v.varName ? ` — ${v.varName}` : '');
    document.getElementById('mmShopeeSku').textContent = getMatchKey(v) || 'No SKU';
    document.getElementById('mmPosSearch').value = '';
    const unitToggle = document.getElementById('mmUnitToggle');
    if (unitToggle) unitToggle.checked = !!v.posUnitId;
    
    // Proactively restore single-item linking handler
    document.getElementById('mmLinkBtn').onclick = linkFromModal;
    document.getElementById('mmLinkBtn').innerHTML = '<i class="fa-solid fa-link me-2"></i>Link Selected';
    
    renderModalPos();
    manualMapModal.show();
}

let posSearchTimer;
function debouncedRenderModalPos() {
    clearTimeout(posSearchTimer);
    posSearchTimer = setTimeout(renderModalPos, 200);
}

function toggleModalUnitSearch() {
    selectedPosId = null;
    selectedPosUnitId = null;
    selectedPosBundleSetId = null;
    renderModalPos();
}

function renderModalPos() {
    const ps = (document.getElementById('mmPosSearch')?.value || '').trim();
    const terms = searchTerms(ps);
    const pp = document.getElementById('mmPosPanel');
    
    // Find current Shopee item SKU
    let shopeeSku = '';
    if (typeof selectedShopee === 'number') {
        const v = ALL_ITEMS.find(x => x.id === selectedShopee);
        if (v) shopeeSku = getMatchKey(v);
    }
    
    // Filter POS items: Support global keyword matching across name, SKU, brand, and barcode
    let avail = [];
    const searchUnits = document.getElementById('mmUnitToggle')?.checked;
    const typeFiltered = POS_ITEMS.filter(p => searchUnits ? p.item_type === 'unit' : (p.item_type === 'base' || p.item_type === 'bundle'));

    if (terms.length) {
        avail = typeFiltered.filter(p => {
            return progressiveMatch(terms, [p.name, p.product_name, p.variation_name, p.sku, p.brand, p.barcode, p.unit_name]);
        });
    } else {
        avail = typeFiltered;
    }
    
    let html = '';
    
    // Render Suggested Match section if searching is empty and there's a SKU
    if (!ps && shopeeSku) {
        const exactMatches = typeFiltered.filter(p => p.sku && p.sku.toLowerCase() === shopeeSku.toLowerCase());
        if (exactMatches.length > 0) {
            html += `<div class="small fw-bold text-success mb-2"><i class="fa-solid fa-wand-magic-sparkles me-1"></i>Suggested SKU Match:</div>`;
            exactMatches.forEach(p => {
                const varBadge = p.variation_name 
                    ? `<span class="badge bg-light text-dark border ms-2 small" style="font-size: 0.7rem; font-weight: normal;">${escHtml(p.variation_name)}</span>` 
                    : '';
                const multBadge = p.item_type === 'bundle'
                    ? `<span class="badge bg-primary text-white ms-2 small" style="font-size: 0.7rem;"><i class="fa-solid fa-boxes-stacked me-1"></i>Bundle Set${p.component_count ? ` (${p.component_count} items)` : ''}</span>`
                    : (p.item_type === 'unit' ? `<span class="badge bg-info text-dark ms-2 small" style="font-size: 0.7rem;"><i class="fa-solid fa-boxes-stacked me-1"></i>${escHtml(p.unit_name || 'Unit')} x${p.multiplier || 1} ${escHtml(p.base_unit_type || 'pcs')}</span>` : '');
                const isSelected = p.item_type === 'bundle'
                    ? selectedPosBundleSetId === p.bundle_set_id
                    : (selectedPosId === p.id && selectedPosUnitId === p.unit_id);
                const selectArgs = p.item_type === 'bundle'
                    ? `null, null, ${p.bundle_set_id}`
                    : `${p.id}, ${p.unit_id || 'null'}, null`;
                
                html += `
                <div class="map-item border-success bg-success-light ${isSelected ? 'selected' : ''}" onclick="selectModalPos(${selectArgs})" style="padding: 0.75rem 1rem;">
                    <div style="width:30px;height:30px;background:var(--sp-success-bg);border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0"><i class="fa-solid fa-circle-check" style="color:var(--sp-success);font-size:.75rem"></i></div>
                    <div class="flex-grow-1" style="min-width:0; line-height: 1.4;">
                        <div class="fw-bold text-success" style="font-size: 0.85rem; word-break: break-word;">
                            ${escHtml(formatPosChoiceName(p))} ${multBadge}
                        </div>
                        <div class="d-flex align-items-center gap-2 mt-1" style="font-size:.72rem;">
                            <span class="text-secondary">SKU:</span>
                            <strong class="sp-sku-code border-success text-success" style="font-family: monospace; font-size: 0.75rem;">${escHtml(p.sku)}</strong>
                            ${p.brand ? `<span class="text-secondary">| Brand: <strong class="text-dark">${escHtml(p.brand)}</strong></span>` : ''}
                            ${p.barcode ? `<span class="text-secondary">| Barcode: <strong class="text-dark">${escHtml(p.barcode)}</strong></span>` : ''}
                        </div>
                    </div>
                    ${isSelected ? `<i class="fa-solid fa-circle-check text-success fs-5"></i>` : `<span class="badge bg-success text-white small px-2 py-1" style="font-size:0.6rem">SKU Match</span>`}
                </div>`;
            });
            html += `<hr style="margin:0.75rem 0; opacity:0.1">`;
        }
    }
    
    // Limit to 50 suggestions max to prevent massive DOM rendering spikes
    const topAvail = avail.slice(0, 50);
    
    html += topAvail.length ? topAvail.map(p => {
        // Skip rendering in general list if already shown in suggested section
        const isExactMatch = !ps && shopeeSku && p.sku && p.sku.toLowerCase() === shopeeSku.toLowerCase();
        if (isExactMatch) return '';
        
        const usedBadge = p.used ? `<span class="sp-badge sp-badge-neutral ms-auto" style="font-size:0.62rem; flex-shrink:0;"><i class="fa-solid fa-link me-1"></i>Linked</span>` : '';
        const varBadge = p.variation_name 
            ? `<span class="badge bg-light text-dark border ms-2 small" style="font-size: 0.7rem; font-weight: normal;">${escHtml(p.variation_name)}</span>` 
            : '';
        const multBadge = p.item_type === 'bundle'
            ? `<span class="badge bg-primary text-white ms-2 small" style="font-size: 0.7rem;"><i class="fa-solid fa-boxes-stacked me-1"></i>Bundle Set${p.component_count ? ` (${p.component_count} items)` : ''}</span>`
            : (p.item_type === 'unit' ? `<span class="badge bg-info text-dark ms-2 small" style="font-size: 0.7rem;"><i class="fa-solid fa-boxes-stacked me-1"></i>${escHtml(p.unit_name || 'Unit')} x${p.multiplier || 1} ${escHtml(p.base_unit_type || 'pcs')}</span>` : '');
        const isSelected = p.item_type === 'bundle'
            ? selectedPosBundleSetId === p.bundle_set_id
            : (selectedPosId === p.id && selectedPosUnitId === p.unit_id);
        const selectArgs = p.item_type === 'bundle'
            ? `null, null, ${p.bundle_set_id}`
            : `${p.id}, ${p.unit_id || 'null'}, null`;
        
        return `
        <div class="map-item ${isSelected ? 'selected' : ''}" onclick="selectModalPos(${selectArgs})" style="padding: 0.75rem 1rem;">
            <div style="width:30px;height:30px;background:var(--sp-info-bg);border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0"><i class="fa-solid fa-boxes-stacked" style="color:var(--sp-info);font-size:.75rem"></i></div>
            <div class="flex-grow-1" style="min-width:0; line-height: 1.4;">
                <div class="fw-bold text-dark" style="font-size: 0.85rem; word-break: break-word;">
                    ${escHtml(formatPosChoiceName(p))} ${multBadge}
                </div>
                <div class="d-flex align-items-center gap-2 mt-1" style="font-size:.72rem;">
                    <span class="text-secondary">SKU:</span>
                    <strong class="sp-sku-code text-shopee" style="font-family: monospace; font-size: 0.75rem;">${escHtml(p.sku)}</strong>
                    ${p.brand ? `<span class="text-secondary">| Brand: <strong class="text-dark">${escHtml(p.brand)}</strong></span>` : ''}
                    ${p.barcode ? `<span class="text-secondary">| Barcode: <strong class="text-dark">${escHtml(p.barcode)}</strong></span>` : ''}
                </div>
            </div>
            ${usedBadge}
            ${isSelected ? `<i class="fa-solid fa-circle-check text-shopee ms-2 fs-5"></i>` : ''}
        </div>`;
    }).join('') : `<div class="sp-empty py-4"><i class="fa-solid fa-boxes-stacked d-block"></i><h6>No POS products found</h6></div>`;
    
    if (avail.length > 50) {
        html += `<div class="text-center text-secondary small mt-2 py-2"><i class="fa-solid fa-circle-info me-1"></i>Showing top 50 results. Type more to refine your search.</div>`;
    }
        
    pp.innerHTML = html;
    document.getElementById('mmLinkBtn').disabled = !(selectedPosId || selectedPosBundleSetId);
}

function selectModalPos(id, unitId, bundleSetId = null) {
    if (bundleSetId) {
        if (selectedPosBundleSetId === bundleSetId) {
            selectedPosBundleSetId = null;
        } else {
            selectedPosBundleSetId = bundleSetId;
            selectedPosId = null;
            selectedPosUnitId = null;
        }
        renderModalPos();
        return;
    }

    if (selectedPosId === id && selectedPosUnitId === unitId) {
        selectedPosId = null;
        selectedPosUnitId = null;
    } else {
        selectedPosId = id;
        selectedPosUnitId = unitId;
        selectedPosBundleSetId = null;
    }
    renderModalPos();
}

async function linkFromModal() {
    if (!selectedShopee || (!selectedPosId && !selectedPosBundleSetId)) return;
    const v = ALL_ITEMS.find(x => x.id === selectedShopee);
    const p = selectedPosBundleSetId
        ? POS_ITEMS.find(x => x.item_type === 'bundle' && x.bundle_set_id === selectedPosBundleSetId)
        : POS_ITEMS.find(x => x.id === selectedPosId && x.unit_id === selectedPosUnitId);
    if (!v || !p) return;
    
    const btn = document.getElementById('mmLinkBtn');
    const origHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-2"></i>Saving...';
    
    try {
        const res = await fetch(`${window.BASE_URL}api/shopee/save_mappings.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                mappings: [{
                    id: v.id,
                    posSku: p.sku,
                    posId: p.item_type === 'bundle' ? null : p.id,
                    posUnitId: p.item_type === 'bundle' ? null : p.unit_id,
                    posBundleSetId: p.item_type === 'bundle' ? p.bundle_set_id : null,
                    status: 'manual'
                }],
                trigger: 'manual_link'
            })
        });
        const data = await res.json();
        if (data.success) {
            v.mapped = true;
            v.posId = p.item_type === 'bundle' ? null : p.id;
            v.posUnitId = p.item_type === 'bundle' ? null : (p.unit_id || null);
            v.posBundleSetId = p.item_type === 'bundle' ? p.bundle_set_id : null;
            v.matchedPosSku = p.sku;
            v.mapStatus = 'manual';
            p.used = true;
            manualMapModal.hide();
            updateCounts(); renderTable();
            if(typeof EllaToast!=='undefined') {
                const displayTarget = p.sku ? `SKU ${p.sku}` : p.product_name;
                EllaToast.success(`Successfully Linked to ${displayTarget}`);
            }
        } else {
            EllaToast.error(data.error || 'Failed to save mapping');
        }
    } catch (e) {
        EllaToast.error('Network error');
    } finally {
        btn.disabled = false;
        btn.innerHTML = origHtml;
        selectedShopee = null; selectedPosId = null; selectedPosUnitId = null; selectedPosBundleSetId = null;
    }
}

// ── Inline unlink confirmation (replaces browser confirm() dialog) ─────────────
async function unlinkItem(id, btn) {
    // If another pending confirmation exists, restore it first
    if (pendingUnlink) {
        pendingUnlink.cell.innerHTML = pendingUnlink.origHtml;
        if (pendingUnlink.id === id) { pendingUnlink = null; return; } // toggle off same item
        pendingUnlink = null;
    }
    const cell = btn.closest('td');
    const origHtml = cell.innerHTML;
    pendingUnlink = { id, cell, origHtml };
    cell.innerHTML = `<div class="d-flex align-items-center justify-content-end gap-1">
        <span class="small text-danger fw-semibold me-1" style="white-space:nowrap">Unlink?</span>
        <button class="btn btn-danger btn-sm" style="padding:2px 10px;font-size:0.75rem" onclick="doUnlink(${id})"><i class="fa-solid fa-check me-1"></i>Yes</button>
        <button class="btn btn-light btn-sm" style="padding:2px 10px;font-size:0.75rem" onclick="cancelUnlink()"><i class="fa-solid fa-times"></i></button>
    </div>`;
}

function cancelUnlink() {
    if (!pendingUnlink) return;
    pendingUnlink.cell.innerHTML = pendingUnlink.origHtml;
    pendingUnlink = null;
}

async function doUnlink(id) {
    if (pendingUnlink) {
        pendingUnlink.cell.innerHTML = '<div class="text-end"><i class="fa-solid fa-spinner fa-spin text-secondary small"></i></div>';
    }
    pendingUnlink = null;

    const v = ALL_ITEMS.find(x => x.id === id);
    if (!v) return;

    try {
        const res = await fetch(`${window.BASE_URL}api/shopee/save_mappings.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                mappings: [{ id: v.id, posSku: null, posId: null, posUnitId: null, posBundleSetId: null, status: 'unmapped' }],
                trigger: 'unlink'
            })
        });
        const data = await res.json();
        if (data.success) {
            const p = v.posBundleSetId
                ? POS_ITEMS.find(x => x.item_type === 'bundle' && x.bundle_set_id === v.posBundleSetId)
                : POS_ITEMS.find(x => samePosChoice(x, v.posId, v.posUnitId || null));
            if (p) p.used = false;
            v.mapped = false; v.posId = null; v.posUnitId = null; v.posBundleSetId = null; v.matchedPosSku = null; v.mapStatus = 'unmapped';
            updateCounts(); renderTable();
            if (typeof EllaToast !== 'undefined') EllaToast.warning(`Unlinked: ${v.groupName}${v.varName ? ' — ' + v.varName : ''}`);
        } else {
            if (typeof EllaToast !== 'undefined') EllaToast.error(data.error || 'Failed to unlink item');
            renderTable();
        }
    } catch (e) {
        if (typeof EllaToast !== 'undefined') EllaToast.error('Network error');
        renderTable();
    }
}
function updateCounts(){
    // First, count all SKU uses within Shopee (ALL_ITEMS) to accurately detect duplicates on the Shopee side
    shopeeSkuCounts = {};
    ALL_ITEMS.forEach(item => {
        const sku = (getMatchKey(item) || '').toLowerCase().trim();
        if (sku) {
            shopeeSkuCounts[sku] = (shopeeSkuCounts[sku] || 0) + 1;
        }
    });

    // Re-evaluate mapStatus dynamically for all unmapped variations based on standard conflict rules
    ALL_ITEMS.forEach(v => {
        if (!v.mapped) {
            const key = (getMatchKey(v) || '').toLowerCase().trim();
            if (!key) {
                v.mapStatus = 'missing_sku';
            } else if (shopeeSkuCounts[key] > 1) {
                v.mapStatus = 'duplicate';
            } else {
                v.mapStatus = 'unmapped';
            }
        }
    });

    const matched=ALL_ITEMS.filter(v=>v.mapped).length;
    const missing=ALL_ITEMS.filter(v=>v.mapStatus==='missing_sku').length;
    
    // We count all duplicate variations (both mapped and unmapped) to compute unique duplicate SKUs
    // EXCLUDING those that are whitelisted ("Allowed as Shared Listing")
    const duplicateVars = ALL_ITEMS.filter(v => {
        const key = (getMatchKey(v) || '').toLowerCase().trim();
        return key && shopeeSkuCounts[key] > 1 && (!window.shopeeDuplicateWhitelist || !window.shopeeDuplicateWhitelist.has(key));
    });

    // But for the total unmatched count logic, we only count the UNMAPPED duplicate variations
    const unmappedDupVarsCount = ALL_ITEMS.filter(v => {
        const key = (getMatchKey(v) || '').toLowerCase().trim();
        return !v.mapped && key && shopeeSkuCounts[key] > 1;
    }).length;

    // Calculate unique duplicate SKUs (matching standard calculation of Resolution Center)
    const uniqueDupes = new Set();
    duplicateVars.forEach(v => {
        const sku = (getMatchKey(v) || '').toLowerCase().trim();
        if (sku) uniqueDupes.add(sku);
    });
    const uniqueDupCount = uniqueDupes.size;
    
    document.getElementById('cntMatched').textContent=matched;
    document.getElementById('cntUnmatched').textContent=Math.max(0,ALL_ITEMS.length-matched-missing-unmappedDupVarsCount);
    if(document.getElementById('cntMissing')) document.getElementById('cntMissing').textContent=missing;
    if(document.getElementById('cntDupes')) document.getElementById('cntDupes').textContent=uniqueDupCount;
    // Update filter pill live counts
    const unmappedClean = ALL_ITEMS.filter(v => v.mapStatus === 'unmapped').length;
    const fcEl = id => document.getElementById(id);
    if(fcEl('fc-all'))      fcEl('fc-all').textContent      = ALL_ITEMS.length;
    if(fcEl('fc-matched'))  fcEl('fc-matched').textContent  = matched;
    if(fcEl('fc-unmapped')) fcEl('fc-unmapped').textContent = unmappedClean;
    if(fcEl('fc-dupes'))    fcEl('fc-dupes').textContent    = uniqueDupCount;
    if(fcEl('fc-missing'))  fcEl('fc-missing').textContent  = missing;
}

// saveMappings() removed — every action (Link, Unlink, Auto-Match) saves to DB instantly via the API.

function toggleGroup(itemId, btn) {
    const rows = document.querySelectorAll('.group-vars-' + itemId);
    const icon = btn.querySelector('i');
    const isCollapsed = icon.classList.contains('fa-chevron-right');
    
    if (isCollapsed) {
        icon.classList.replace('fa-chevron-right', 'fa-chevron-down');
        rows.forEach(r => r.classList.remove('collapsed'));
    } else {
        icon.classList.replace('fa-chevron-down', 'fa-chevron-right');
        rows.forEach(r => r.classList.add('collapsed'));
    }
}






</script>

<script src="../../views/shopee/shopee_alerts.js"></script>
<?php require_once '../../includes/footer.php'; ?>

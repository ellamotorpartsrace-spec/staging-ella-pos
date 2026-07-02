<?php
$page_title = 'Lazada Sync — Product Mapping';
require_once '../../config/config.php';
require_once '../../includes/auth.php';
requirePermission('lazada_mapping');
require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';

$db = new Database();
$conn = $db->getConnection();
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/lazada-sync.css?v=<?= filemtime(__DIR__.'/../../assets/css/lazada-sync.css') ?>">
<style>
.match-hero { text-align:center; padding:4rem 2rem; background:var(--bg-surface); border:1px solid var(--border-color); border-radius:var(--lz-radius-lg); box-shadow:var(--lz-shadow-sm); }
.match-hero-icon { width:80px;height:80px;border-radius:50%;margin:0 auto 1.5rem;background:var(--lazada-light);display:flex;align-items:center;justify-content:center;font-size:2rem;color:var(--lazada-primary); }
.map-panel { max-height: 350px; overflow-y: auto; padding-right: .5rem; }
.map-item { padding:.75rem 1rem; border:1px solid var(--border-color); border-radius:var(--lz-radius-sm); margin-bottom:.4rem; cursor:pointer; transition:all .2s; display:flex; align-items:center; gap:.75rem; }
.map-item:hover,.map-item.selected { border-color:var(--lazada-primary); background:var(--lazada-light); }
.map-item.selected { box-shadow:0 0 0 2px rgba(15,19,109,.2); }
.unit-map-note { border:1px dashed rgba(15,19,109,.35); background:rgba(15,19,109,.06); border-radius:8px; padding:.65rem .75rem; }
.unit-result-badge { display:inline-flex; align-items:center; gap:.3rem; border:1px solid rgba(13,202,240,.35); background:rgba(13,202,240,.12); color:#087990; border-radius:999px; padding:.15rem .45rem; font-size:.68rem; font-weight:700; }
.filter-count { display:inline-flex;align-items:center;justify-content:center;min-width:1.3rem;height:1.3rem;padding:0 4px;margin-left:4px;font-size:0.65rem;font-weight:700;border-radius:20px;background:rgba(0,0,0,0.12);color:inherit;vertical-align:middle;line-height:1; }
.lz-pill.active .filter-count { background:rgba(255,255,255,0.3); }
    /* Popover Custom Styling */
    .lazada-popover {
        --bs-popover-max-width: 300px;
        --bs-popover-border-color: rgba(15, 19, 109, 0.25);
        --bs-popover-header-bg: #0f136d;
        --bs-popover-header-color: #fff;
        --bs-popover-body-padding-x: 0.75rem;
        --bs-popover-body-padding-y: 0.6rem;
        border-radius: 8px;
        box-shadow: 0 4px 16px rgba(15, 19, 109, 0.12);
        border: 1px solid var(--bs-popover-border-color);
        font-size: 0.82rem;
    }
    .lazada-popover .popover-header {
        font-weight: 600;
        font-size: 0.78rem;
        border-bottom: none;
        border-top-left-radius: 7px;
        border-top-right-radius: 7px;
        text-align: center;
        padding: 0.35rem 0.75rem;
        letter-spacing: 0.3px;
    }
    .lazada-popover .popover-body {
        background-color: #fff;
        color: #333;
        font-size: 0.82rem;
        border-bottom-left-radius: 7px;
        border-bottom-right-radius: 7px;
        padding: 0.5rem 0.75rem;
    }
    .lazada-popover .popover-arrow::before {
        border-top-color: rgba(15, 19, 109, 0.25);
    }
    .lazada-popover .popover-arrow::after {
        border-top-color: #fff;
    }

</style><div class="lz-page lz-animate">
    <?php require_once __DIR__ . '/lazada_token_warning.php'; ?>
    
    <!-- Hero Header -->
    <div class="mb-4" style="background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%); border-radius: 16px; padding: 2rem 2.5rem; box-shadow: 0 10px 30px rgba(30,58,138,0.15); position: relative; overflow: hidden;">
        <!-- Breadcrumb inside -->
        <nav aria-label="breadcrumb" style="position: relative; z-index: 2;">
            <ol class="breadcrumb mb-3" style="font-size: 0.85rem;">
                <li class="breadcrumb-item"><a href="<?= BASE_URL ?>views/lazada/index.php" class="text-white text-decoration-none fw-bold px-2 py-1 rounded" style="background: rgba(255, 255, 255, 0.2); transition: background 0.2s;" onmouseover="this.style.background='rgba(255, 255, 255, 0.3)'" onmouseout="this.style.background='rgba(255, 255, 255, 0.2)'"><i class="fa-solid fa-arrow-left me-1"></i> Lazada Dashboard</a></li>
                <li class="breadcrumb-item active" style="color: white; font-weight: 600;">Product Mapping</li>
            </ol>
        </nav>
        
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3" style="position: relative; z-index: 2;">
            <div class="d-flex align-items-center gap-3">
                <div style="background: white; border-radius: 14px; width: 64px; height: 64px; display: flex; align-items: center; justify-content: center; box-shadow: 0 4px 15px rgba(0,0,0,0.1);">
                    <i class="fa-solid fa-link" style="color: #2563eb; font-size: 1.8rem;"></i>
                </div>
                <div>
                    <h1 class="mb-1 fw-bolder" style="font-size: 2rem; letter-spacing: -0.5px; color: white;">Product Mapping</h1>
                    <p class="mb-0" style="color: rgba(255,255,255,0.8); font-size: 0.95rem;">Match Lazada products to POS inventory by SKU. Products and their variations are grouped together.</p>
                </div>
            </div>
            <div class="d-flex gap-2 align-items-center" id="headerBtns">
                <span class="small d-flex align-items-center gap-1 me-2" style="color: rgba(255,255,255,0.8)"><i class="fa-solid fa-circle-check text-success" style="font-size:.75rem"></i>Changes auto-saved</span>
                <button class="btn btn-light fw-bold px-4 rounded-pill d-flex align-items-center" id="headerAutoMatchBtn" disabled onclick="runAutoMatch(false, this)" style="color: #2563eb; height: 42px; box-shadow: 0 4px 10px rgba(0,0,0,0.15);">
                    <i class="fa-solid fa-spinner fa-spin me-2"></i> Loading...
                </button>
            </div>
        </div>
        <!-- Decorative bg -->
        <div style="position: absolute; top: -50px; right: -50px; width: 300px; height: 300px; background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, rgba(255,255,255,0) 70%); border-radius: 50%; z-index: 1;"></div>
    </div>

    <!-- Stats (hidden until match runs) -->
    <div class="row g-3 mb-4" id="statsRow">
        <div class="col-6 col-lg-3">
            <div class="card border-0 rounded-4" style="border-bottom: 4px solid #10b981 !important; box-shadow: 0 4px 15px rgba(0,0,0,0.05);">
                <div class="card-body p-3 d-flex align-items-center">
                    <div style="background: #ecfdf5; border-radius: 12px; width: 48px; height: 48px; display: flex; align-items: center; justify-content: center; margin-right: 15px;">
                        <i class="fa-solid fa-link" style="color: #10b981; font-size: 1.25rem;"></i>
                    </div>
                    <div>
                        <div style="font-size: 0.7rem; font-weight: 700; color: #64748b; letter-spacing: 0.5px; text-transform: uppercase;">Total Matched</div>
                        <div style="font-size: 1.5rem; font-weight: 800; color: #1e293b; line-height: 1.2;" id="cntMatched">0</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card border-0 rounded-4" style="border-bottom: 4px solid #ef4444 !important; box-shadow: 0 4px 15px rgba(0,0,0,0.05);">
                <div class="card-body p-3 d-flex align-items-center">
                    <div style="background: #fef2f2; border-radius: 12px; width: 48px; height: 48px; display: flex; align-items: center; justify-content: center; margin-right: 15px;">
                        <i class="fa-solid fa-link-slash" style="color: #ef4444; font-size: 1.25rem;"></i>
                    </div>
                    <div>
                        <div style="font-size: 0.7rem; font-weight: 700; color: #64748b; letter-spacing: 0.5px; text-transform: uppercase;">Total Unmatched</div>
                        <div style="font-size: 1.5rem; font-weight: 800; color: #1e293b; line-height: 1.2;" id="cntUnmatched">0</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card border-0 rounded-4" style="border-bottom: 4px solid #f59e0b !important; box-shadow: 0 4px 15px rgba(0,0,0,0.05);">
                <div class="card-body p-3 d-flex align-items-center">
                    <div style="background: #fffbeb; border-radius: 12px; width: 48px; height: 48px; display: flex; align-items: center; justify-content: center; margin-right: 15px;">
                        <i class="fa-solid fa-clone" style="color: #f59e0b; font-size: 1.25rem;"></i>
                    </div>
                    <div>
                        <div style="font-size: 0.7rem; font-weight: 700; color: #64748b; letter-spacing: 0.5px; text-transform: uppercase;">Duplicate SKUs</div>
                        <div style="font-size: 1.5rem; font-weight: 800; color: #1e293b; line-height: 1.2;" id="cntDupes">0</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card border-0 rounded-4" style="border-bottom: 4px solid #3b82f6 !important; box-shadow: 0 4px 15px rgba(0,0,0,0.05);">
                <div class="card-body p-3 d-flex align-items-center">
                    <div style="background: #eff6ff; border-radius: 12px; width: 48px; height: 48px; display: flex; align-items: center; justify-content: center; margin-right: 15px;">
                        <i class="fa-solid fa-ban" style="color: #3b82f6; font-size: 1.25rem;"></i>
                    </div>
                    <div>
                        <div style="font-size: 0.7rem; font-weight: 700; color: #64748b; letter-spacing: 0.5px; text-transform: uppercase;">Ignored</div>
                        <div style="font-size: 1.5rem; font-weight: 800; color: #1e293b; line-height: 1.2;" id="cntIgnored">0</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Empty State -->
    <div id="emptyState" class="d-none text-center py-5">
        <div style="background: rgba(220, 53, 69, 0.1); width: 80px; height: 80px; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 1.5rem;">
            <i class="fa-solid fa-plug-circle-xmark" style="font-size: 2.5rem; color: #dc3545;"></i>
        </div>
        <h3 class="fw-bolder mb-2">API Not Connected</h3>
        <p class="text-secondary mx-auto mb-4" style="max-width: 400px;">We cannot fetch your product mappings until your Lazada API settings are configured.</p>
        <a href="<?= BASE_URL ?>views/lazada/settings.php" class="btn btn-danger fw-bold px-4 py-2" style="border-radius: 8px;">
            <i class="fa-solid fa-gear me-2"></i>Configure Settings
        </a>
    </div>

<!-- Results State -->
<div id="resultsState">
    <!-- Filter -->
    <div class="lz-card mb-4">
        <div class="lz-card-body d-flex flex-wrap align-items-center gap-3" style="padding:.85rem 1.25rem">
            <div class="lz-search flex-grow-1" style="max-width:340px">
                <i class="fa-solid fa-search"></i>
                <input type="text" id="mapSearch" autocomplete="off" placeholder="Search by name or SKU..." oninput="debouncedRender()">
            </div>
            <div class="lz-filter-pills ms-2">
                <button class="lz-pill active" onclick="setFilter('all',this)">All <span class="filter-count" id="fc-all">0</span></button>
                <button class="lz-pill" onclick="setFilter('mapped',this)"><i class="fa-solid fa-link me-1"></i>Matched <span class="filter-count" id="fc-matched">0</span></button>
                <button class="lz-pill" onclick="setFilter('unmapped',this)"><i class="fa-solid fa-link-slash me-1"></i>Unmatched <span class="filter-count" id="fc-unmapped">0</span></button>
                <button class="lz-pill" onclick="setFilter('dupes',this)"><i class="fa-solid fa-clone me-1"></i>Duplicate SKUs <span class="filter-count" id="fc-dupes">0</span></button>
                <button class="lz-pill" onclick="setFilter('missing',this)"><i class="fa-solid fa-triangle-exclamation me-1"></i>Missing SKUs <span class="filter-count" id="fc-missing">0</span></button>
            </div>
            <div class="ms-auto d-flex gap-2">
                <button class="btn btn-outline-lazada btn-sm" id="reRunBtn" disabled onclick="runAutoMatch(true, this)"><i class="fa-solid fa-spinner fa-spin me-1"></i>Loading...</button>
            </div>
        </div>
    </div>


    <!-- Mapping Table (Grouped) -->
    <div class="card border-0 rounded-4 mb-4" style="box-shadow: 0 4px 15px rgba(0,0,0,0.03); overflow: hidden;">
        <div class="table-responsive" style="max-height: 600px; overflow-y: auto;">
            <table class="table lz-table mb-0 align-middle table-hover">
                <thead style="background: #f8fafc; font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.5px; color: #64748b; position: sticky; top: 0; z-index: 10; box-shadow: 0 2px 4px rgba(0,0,0,0.05);">
                    <tr>
                        <th class="ps-4 py-3 border-0" style="width: 35%;">Product / Variation</th>
                        <th class="py-3 border-0" style="width: 15%;">Product ID</th>
                        <th class="py-3 border-0" style="width: 15%;">Variation SKU</th>
                        <th class="py-3 border-0 text-center" style="width: 5%;"><i class="fa-solid fa-arrows-left-right"></i></th>
                        <th class="py-3 border-0" style="width: 15%;">POS Match</th>
                        <th class="py-3 border-0" style="width: 10%;">Status</th>
                        <th class="pe-4 py-3 border-0 text-end" style="width: 5%;">Action</th>
                    </tr>
                </thead>
                <tbody id="mapTableBody">
                    <tr>
                        <td colspan="7">
                            <div class="text-center py-5">
                                <i class="fa-solid fa-spinner fa-spin text-lazada" style="font-size:2rem"></i>
                                <div class="mt-2 text-secondary fw-bold">Loading your mapping data...</div>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <!-- Premium Pagination Footer -->
        <div class="card-footer bg-white border-top py-3 d-flex align-items-center justify-content-between">
            <div class="d-flex align-items-center gap-2">
                <span class="small text-muted">Rows per page:</span>
                <select class="form-select form-select-sm" id="itemsPerPageSelect" onchange="changeItemsPerPage(this.value)" style="width: 70px;">
                    <option value="10">10</option>
                    <option value="25" selected>25</option>
                    <option value="50">50</option>
                    <option value="100">100</option>
                </select>
            </div>
            <div class="d-flex align-items-center gap-3">
                <span class="small text-muted" id="paginationStatus">Page 1 of 1 (0 items)</span>
                <div class="btn-group" id="paginationButtons"></div>
            </div>
        </div>
    </div>

</div> <!-- Closes #resultsState -->
</div> <!-- Closes .lz-page (removes relative z-index context to prevent modal backdrop bugs) -->

<!-- Manual Map Modal -->
<div class="modal fade lz-modal" id="manualMapModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold"><i class="fa-solid fa-link text-lazada me-2"></i>Map to POS Product</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" style="filter:var(--btn-close-filter)"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3 p-3 rounded" style="background:var(--lazada-light); border:1px solid rgba(15,19,109,0.2)">
                    <div class="small fw-bold text-lazada mb-1">Lazada Item to Map:</div>
                    <div class="fw-bold fs-6" id="mmLazadaName"></div>
                    <div class="text-secondary small mt-1">SKU: <span id="mmLazadaSku" class="lz-sku-code"></span></div>
                </div>
                
                <h6 class="fw-bold mb-2"><i class="fa-solid fa-boxes-stacked me-2" style="color:var(--lz-info)"></i>Select POS Product</h6>
                <div class="unit-map-note d-flex align-items-center justify-content-between gap-3 mb-3">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" role="switch" id="mmUnitToggle" onchange="toggleModalUnitSearch()">
                        <label class="form-check-label small fw-bold" for="mmUnitToggle">Search per unit mapping</label>
                    </div>
                    <a class="small fw-bold text-lazada text-decoration-none" href="<?= BASE_URL ?>views/inventory/unit_types.php" target="_blank">
                        <i class="fa-solid fa-layer-group me-1"></i>Manage Units
                    </a>
                </div>
                <div class="lz-search mb-3">
                    <i class="fa-solid fa-search"></i>
                    <input type="text" id="mmPosSearch" autocomplete="off" placeholder="Search POS by name or SKU..." oninput="debouncedRenderModalPos()">
                </div>
                
                <div id="mmPosPanel" class="map-panel"></div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-lazada" id="mmLinkBtn" disabled onclick="linkFromModal()"><i class="fa-solid fa-link me-2"></i>Link Selected</button>
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
    
    // Parse URL parameters for deep linking from Resolution Center
    const urlParams = new URLSearchParams(window.location.search);
    const urlFilter = urlParams.get('filter');
    const urlSearch = urlParams.get('search');
    
    // Clear session storage if arriving from navigation (not reload) AND we aren't deep linking
    const navEntries = window.performance.getEntriesByType('navigation');
    if (navEntries.length > 0 && navEntries[0].type !== 'reload' && !urlFilter && !urlSearch) {
        sessionStorage.removeItem('lazada_mapSearch');
        sessionStorage.removeItem('lazada_map_filter');
        sessionStorage.removeItem('lazada_map_currentPage');
        sessionStorage.removeItem('lazada_map_itemsPerPage');
        activeFilter = 'all';
        currentPage = 1;
    }
    
    // Apply URL overrides if they exist
    if (urlFilter) {
        activeFilter = urlFilter;
        sessionStorage.setItem('lazada_map_filter', activeFilter);
    }
    
    // Restore search input
    const savedSearch = sessionStorage.getItem('lazada_mapSearch');
    const searchInput = document.getElementById('mapSearch');
    if (urlSearch && searchInput) {
        searchInput.value = urlSearch;
        sessionStorage.setItem('lazada_mapSearch', urlSearch);
    } else if (savedSearch && searchInput) {
        searchInput.value = savedSearch;
    }
    
    // Clean up URL to prevent persistence on refresh
    if (urlFilter || urlSearch) {
        window.history.replaceState({}, document.title, window.location.pathname);
    }
    
    // Restore limits dropdown
    const limitSel = document.getElementById('itemsPerPageSelect');
    if(limitSel) limitSel.value = itemsPerPage;
    
    // Restore filter button
    document.querySelectorAll('.lz-filter-pills .lz-pill').forEach(p => p.classList.remove('active'));
    const activeBtn = document.querySelector(`.lz-filter-pills .lz-pill[onclick*="setFilter('${activeFilter}'"]`) || document.querySelector('.lz-filter-pills .lz-pill');
    if(activeBtn) activeBtn.classList.add('active');

    
    const isApiConnected = <?= isset($tokenWarning) && $tokenWarning ? 'false' : 'true' ?>;
    if (isApiConnected) {
        fetchMappingData();
    } else {
        document.getElementById('statsRow').classList.add('d-none');
        document.getElementById('resultsState').classList.add('d-none');
        document.getElementById('emptyState').classList.remove('d-none');
        document.getElementById('headerBtns').classList.add('d-none');
    }
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

        // 1. Fetch Lazada Mappings first to display the table immediately
        const mapRes = await fetch(`${window.BASE_URL}api/lazada/get_mappings.php`);
        const data = await mapRes.json();
        
        if (data.groups !== undefined) {
            GROUPS = data.groups;
            window.lazadaDuplicateWhitelist = new Set((data.whitelist || []).map(sku => sku.toLowerCase().trim()));
        } else {
            GROUPS = data; // Fallback for old cache
            window.lazadaDuplicateWhitelist = new Set();
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
        fetch(`${window.BASE_URL}api/lazada/get_pos_items.php`)
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
                
                // Re-render table now that POS items are loaded (enables popovers)
                renderTable();
            })
            .catch(e => console.error('Failed to fetch POS items asynchronously', e));

    } catch(e) {
        console.error('Failed to fetch data asynchronously', e);
    }
}

async function fetchPosItems() {
    // Legacy support
};

let activeFilter = sessionStorage.getItem('lazada_map_filter') || 'all';
let selectedLazada=null, selectedPosId=null, selectedPosUnitId=null, selectedPosBundleSetId=null;
let lazadaSkuCounts = {};
let renderTimeout = null;
let currentPage = parseInt(sessionStorage.getItem('lazada_map_currentPage')) || 1;
let itemsPerPage = parseInt(sessionStorage.getItem('lazada_map_itemsPerPage')) || 25;
let pendingAutoMatches = [];
let pendingUnlink = null;

function debouncedRender() {
    currentPage = 1; // Reset page on search
    sessionStorage.setItem('lazada_map_currentPage', currentPage);
    const searchInput = document.getElementById('mapSearch');
    if (searchInput) sessionStorage.setItem('lazada_mapSearch', searchInput.value);
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
        return `<div class="d-flex flex-column align-items-start gap-1">
                    <span class="lz-badge lz-badge-info">${sku}</span>
                    <span class="unit-result-badge" style="white-space:nowrap"><i class="fa-solid fa-boxes-stacked"></i>Bundle Set${p.component_count ? ` (${p.component_count} items)` : ''}</span>
                </div>`;
    }
    if (p.item_type === 'unit') {
        return `<div class="d-flex flex-column align-items-start gap-1">
                    <span class="lz-badge lz-badge-success">${sku}</span>
                    <span class="unit-result-badge" style="white-space:nowrap"><i class="fa-solid fa-boxes-stacked"></i>${escHtml(p.unit_name || 'Unit')} x${p.multiplier || 1}</span>
                </div>`;
    }
    return `<span class="lz-badge lz-badge-success">${sku}</span>`;
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

    // If re-running, process all non-missing items except manual mappings; otherwise only unmapped ones
    const filterCondition = isReRun
        ? (v => v.mapStatus !== 'missing_sku' && v.mapStatus !== 'manual')
        : (v => !v.mapped && v.mapStatus !== 'missing_sku');

    const itemsToProcess = ALL_ITEMS.filter(filterCondition);

    itemsToProcess.forEach(v => {
        const key = String(getMatchKey(v) || '').trim().toLowerCase();
        if (!key) return;

        const isDuplicate = lazadaSkuCounts[key] > 1;
        const isWhitelisted = window.lazadaDuplicateWhitelist && window.lazadaDuplicateWhitelist.has(key);

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
        const res = await fetch(`${window.BASE_URL}api/lazada/save_mappings.php`, {
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
                EllaToast.info(`Syncing initial stock for ${count} items in the background...`);
            }
            updateCounts();
            renderTable();
            // Trigger initial stock sync in the background for auto matched items
            pendingAutoMatches.forEach(m => {
                fetch(`${window.BASE_URL}api/lazada/sync_individual.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({ mapping_id: m.id })
                }).catch(e => console.error('Initial sync error:', e));
            });
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
    sessionStorage.setItem('lazada_map_filter', f);
    currentPage = 1; // Reset page on filter change
    sessionStorage.setItem('lazada_map_currentPage', currentPage);
    document.querySelectorAll('.lz-filter-pills .lz-pill').forEach(p=>p.classList.remove('active'));
    btn.classList.add('active');renderTable();
}

function changeItemsPerPage(val) {
    itemsPerPage = parseInt(val, 10);
    sessionStorage.setItem('lazada_map_itemsPerPage', itemsPerPage);
    currentPage = 1;
    sessionStorage.setItem('lazada_map_currentPage', currentPage);
    renderTable();
}

function goToPage(page) {
    currentPage = page;
    sessionStorage.setItem('lazada_map_currentPage', currentPage);
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
                if (!key || !lazadaSkuCounts[key] || lazadaSkuCounts[key] <= 1 || (window.lazadaDuplicateWhitelist && window.lazadaDuplicateWhitelist.has(key))) return false;
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

        const parentSkuHtml = ``;

        const imgHtml = g.imageUrl
            ? `<img src="${escHtml(g.imageUrl)}" class="lz-product-img" alt="Product Image">`
            : `<div class="lz-img-placeholder"><i class="fa-solid fa-image"></i></div>`;

        const isSimple = g.variations.length === 1 && (!g.variations[0].varName || g.variations[0].varName.toLowerCase() === 'main item');

        if (isSimple) {
            const v = vars[0];
            if (v) {
                const posItem = findMappedPosItem(v);
                const posSku = v.matchedPosSku || (posItem ? posItem.sku : '');
                let statusBadge='';
                switch(v.mapStatus){
                    case 'auto':        statusBadge=`<span class="lz-badge lz-badge-success"><i class="fa-solid fa-wand-magic-sparkles"></i> Auto</span>`;break;
                    case 'manual':      statusBadge=`<span class="lz-badge lz-badge-info"><i class="fa-solid fa-hand-pointer"></i> Manual</span>`;break;
                    case 'unmapped':    statusBadge=`<span class="lz-badge lz-badge-warning"><i class="fa-solid fa-link-slash"></i> Unmatched</span>`;break;
                    case 'duplicate':   statusBadge=`<span class="lz-badge lz-badge-danger"><i class="fa-solid fa-clone"></i> Dup SKU</span>`;break;
                    case 'missing_sku': statusBadge=`<span class="lz-badge lz-badge-danger"><i class="fa-solid fa-triangle-exclamation"></i> No SKU</span>`;break;
                    default:            statusBadge=`<span class="lz-badge lz-badge-neutral">${escHtml(v.mapStatus)}</span>`;
                }
                const posCell=posSku?(posItem ? formatPosChoiceBadge(posItem) : `<span class="lz-badge lz-badge-success">${escHtml(posSku)}</span>`):`<span class="text-secondary">—</span>`;
                let linkPopover = '';
                if (v.mapped && posItem) {
                    const safeName = escHtml(posItem.product_name || '');
                    let subtextParts = [];
                    if (posItem.brand) subtextParts.push(`<span style='color:#64748b'>${escHtml(posItem.brand)}</span>`);
                    if (posItem.variation_name) subtextParts.push(`<span style='color:#0f136d'>${escHtml(posItem.variation_name)}</span>`);
                    const varHtml = subtextParts.length > 0 ? `<div style='font-size:0.75rem;margin-top:2px;font-weight:600'>${subtextParts.join(" <span style='color:#cbd5e1'>|</span> ")}</div>` : '';
                    const unitHtml = posItem.item_type === 'unit' ? `<div style='font-size:0.72rem;color:#6366f1;margin-top:3px;font-weight:600;border-top:1px solid rgba(0,0,0,0.08);padding-top:3px'>1 Lazada unit deducts ${posItem.multiplier || 1} ${escHtml(posItem.base_unit_type || 'pcs')}</div>` : '';
                    let popContent = `<div style='text-align:center;word-break:break-word;line-height:1.3;font-size:0.82rem;max-width:280px'><div style='font-weight:600;color:#1e293b'>${safeName}</div>${varHtml}${unitHtml}</div>`;
                    popContent = popContent.replace(/"/g, '&quot;');
                    linkPopover = `tabindex="0" data-bs-toggle="popover" data-bs-placement="top" data-bs-trigger="hover" data-bs-custom-class="lazada-popover" title="<i class='fa-solid fa-boxes-stacked me-1'></i> Mapped POS Product" data-bs-content="${popContent}"`;
                }
                const linkIcon=v.mapped?`<button type="button" class="btn btn-link text-lazada text-decoration-none shadow-none p-0" style="padding:6px!important;" ${linkPopover}><i class="fa-solid fa-link"></i></button>`:`<i class="fa-solid fa-link-slash text-secondary" style="opacity:.3"></i>`;
                const actionBtn = v.mapped 
                    ? `<button class="btn btn-sm btn-ghost text-danger" onclick="unlinkItem(${v.id}, this)"><i class="fa-solid fa-unlink me-1"></i>Unlink</button>`
                    : `<button class="btn btn-sm btn-outline-lazada" onclick="openManualMap(${v.id})"><i class="fa-solid fa-link me-1"></i>Map</button>`;

                html += `<tr class="lz-group-start" style="border-bottom: 2px solid #cbd5e1;">
                    <td class="ps-3">
                        <div class="d-flex align-items-center gap-3 py-1" style="min-width:0;">
                            ${imgHtml}
                            <div style="min-width:0;">
                                <div class="d-flex align-items-start gap-2 mb-1" style="min-width:0;">
                                    <i class="fa-brands fa-lazada mt-1" style="color:var(--lazada-primary);font-size:.85rem;flex-shrink:0"></i>
                                    <span class="fw-bold text-wrap text-break" style="font-size:.9rem; line-height:1.2;">${escHtml(g.name)}</span>
                                </div>
                            </div>
                        </div>
                    </td>
                    <td>
                        <div class="fw-600 text-dark" style="font-size: 0.9rem;">${escHtml(g.itemId)}</div>
                    </td>
                    <td>${(() => {
                        const key = (getMatchKey(v) || '').toLowerCase().trim();
                        const isDuplicate = key && lazadaSkuCounts[key] > 1;
                        return isDuplicate
                            ? `<span class="lz-sku-code">${escHtml(v.variationSku || v.parentSku)}</span><span class="badge bg-danger-light text-danger ms-1" style="font-size:0.65rem; border:1px solid rgba(220,53,69,0.3);"><i class="fa-solid fa-clone"></i> Shared SKU</span>`
                            : `<span class="lz-sku-code">${escHtml(v.variationSku || v.parentSku)}</span>`;
                    })()}</td>
                    <td class="text-center">${linkIcon}</td>
                    <td>${posCell}</td>
                    <td>${statusBadge}</td>
                    <td class="text-end">${actionBtn}</td>
                </tr>`;
            }
        } else {
            // Parent Row
            html += `<tr class="lz-group-start" style="background: #fdfdfd; border-top: 2px solid #cbd5e1; border-bottom: 1px solid #e2e8f0;">
                <td class="ps-3">
                    <div class="d-flex align-items-center gap-3 py-1" style="min-width:0;">
                        ${imgHtml}
                        <div style="min-width:0;">
                            <div class="d-flex align-items-start gap-2 mb-1" style="min-width:0;">
                                <i class="fa-brands fa-lazada mt-1" style="color:var(--lazada-primary);font-size:.85rem;flex-shrink:0"></i>
                                <span class="fw-bold text-wrap text-break" style="font-size:.9rem; line-height:1.2;">${escHtml(g.name)}</span>
                            </div>
                        </div>
                    </div>
                </td>
                <td>
                    <div class="fw-600 text-dark" style="font-size: 0.9rem;">${escHtml(g.itemId)}</div>
                </td>
                <td colspan="5"></td>
            </tr>`;

            // Variation Rows
            vars.forEach((v, index)=>{
                const pos=findMappedPosItem(v);
                let statusBadge='';
                switch(v.mapStatus){
                    case 'auto':        statusBadge=`<span class="lz-badge lz-badge-success"><i class="fa-solid fa-wand-magic-sparkles"></i> Auto</span>`;break;
                    case 'manual':      statusBadge=`<span class="lz-badge lz-badge-info"><i class="fa-solid fa-hand-pointer"></i> Manual</span>`;break;
                    case 'unmapped':    statusBadge=`<span class="lz-badge lz-badge-warning"><i class="fa-solid fa-link-slash"></i> Unmatched</span>`;break;
                    case 'duplicate':   statusBadge=`<span class="lz-badge lz-badge-danger"><i class="fa-solid fa-clone"></i> Dup SKU</span>`;break;
                    case 'missing_sku': statusBadge=`<span class="lz-badge lz-badge-danger"><i class="fa-solid fa-triangle-exclamation"></i> No SKU</span>`;break;
                    default:            statusBadge=`<span class="lz-badge lz-badge-neutral">${escHtml(v.mapStatus)}</span>`;
                }
                const posCell=pos?formatPosChoiceBadge(pos):`<span class="text-secondary">—</span>`;
                let linkPopover = '';
                if (v.mapped && pos) {
                    const safeName = escHtml(pos.product_name || '');
                    let subtextParts = [];
                    if (pos.brand) subtextParts.push(`<span style='color:#64748b'>${escHtml(pos.brand)}</span>`);
                    if (pos.variation_name) subtextParts.push(`<span style='color:#0f136d'>${escHtml(pos.variation_name)}</span>`);
                    const varHtml = subtextParts.length > 0 ? `<div style='font-size:0.75rem;margin-top:2px;font-weight:600'>${subtextParts.join(" <span style='color:#cbd5e1'>|</span> ")}</div>` : '';
                    const unitHtml = pos.item_type === 'unit' ? `<div style='font-size:0.72rem;color:#6366f1;margin-top:3px;font-weight:600;border-top:1px solid rgba(0,0,0,0.08);padding-top:3px'>1 Lazada unit deducts ${pos.multiplier || 1} ${escHtml(pos.base_unit_type || 'pcs')}</div>` : '';
                    let popContent = `<div style='text-align:center;word-break:break-word;line-height:1.3;font-size:0.82rem;max-width:280px'><div style='font-weight:600;color:#1e293b'>${safeName}</div>${varHtml}${unitHtml}</div>`;
                    popContent = popContent.replace(/"/g, '&quot;');
                    linkPopover = `tabindex="0" data-bs-toggle="popover" data-bs-placement="top" data-bs-trigger="hover" data-bs-custom-class="lazada-popover" title="<i class='fa-solid fa-boxes-stacked me-1'></i> Mapped POS Product" data-bs-content="${popContent}"`;
                }
                const linkIcon=v.mapped?`<button type="button" class="btn btn-link text-lazada text-decoration-none shadow-none p-0" style="padding:6px!important;" ${linkPopover}><i class="fa-solid fa-link"></i></button>`:`<i class="fa-solid fa-link-slash text-secondary" style="opacity:.3"></i>`;
                const actionBtn = v.mapped 
                    ? `<button class="btn btn-sm btn-ghost text-danger" onclick="unlinkItem(${v.id}, this)"><i class="fa-solid fa-unlink me-1"></i>Unlink</button>`
                    : `<button class="btn btn-sm btn-outline-lazada" onclick="openManualMap(${v.id})"><i class="fa-solid fa-link me-1"></i>Map</button>`;
                
                const vNameHtml = v.varName
                    ? `<span class="lz-var-name-text">${escHtml(v.varName)}</span>`
                    : `<span class="lz-var-name-text text-secondary fst-italic">Main Item</span>`;

                const isLast = index === vars.length - 1;
                const borderStyle = isLast ? 'border-bottom: 2px solid #cbd5e1;' : 'border-bottom: 1px solid #f1f5f9;';

                html += `<tr style="${borderStyle}">
                    <td class="lz-tree-indent ps-4">
                        <div class="d-flex align-items-center gap-2">
                            <i class="fa-solid fa-level-up-alt fa-rotate-90 text-muted opacity-50"></i>
                            ${vNameHtml}
                        </div>
                    </td>
                    <td></td>
                    <td>${(() => {
                        const key = (getMatchKey(v) || '').toLowerCase().trim();
                        const isDuplicate = key && lazadaSkuCounts[key] > 1;
                        return isDuplicate
                            ? `<span class="lz-sku-code">${escHtml(v.variationSku || v.parentSku)}</span><span class="badge bg-danger-light text-danger ms-1" style="font-size:0.65rem; border:1px solid rgba(220,53,69,0.3);"><i class="fa-solid fa-clone"></i> Shared SKU</span>`
                            : `<span class="lz-sku-code">${escHtml(v.variationSku || v.parentSku)}</span>`;
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
        body.innerHTML=`<tr><td colspan="7"><div class="lz-empty"><i class="fa-solid fa-filter d-block"></i><h5>No items match this filter</h5></div></td></tr>`;
        document.getElementById('paginationStatus').textContent = 'Page 1 of 1 (0 items)';
        document.getElementById('paginationButtons').innerHTML = '';
        return;
    }
    
    body.innerHTML=html;

    document.getElementById('paginationStatus').textContent = `Page ${currentPage} of ${totalPages} (${totalItems} products)`;
    renderPaginationButtons(totalItems, totalPages);
}

function renderPaginationButtons(totalItems, totalPages) {
    const container = document.getElementById('paginationButtons');
    if (!container) return;
    
    let html = '';
    html += `<button class="btn btn-sm btn-outline-lazada-secondary px-2 py-1" ${currentPage === 1 ? 'disabled' : ''} onclick="goToPage(1)"><i class="fa-solid fa-angles-left"></i></button>`;
    html += `<button class="btn btn-sm btn-outline-lazada-secondary px-2 py-1" ${currentPage === 1 ? 'disabled' : ''} onclick="goToPage(${currentPage - 1})"><i class="fa-solid fa-angle-left"></i></button>`;
    
    const maxVisiblePages = 5;
    let startPage = Math.max(1, currentPage - 2);
    let endPage = Math.min(totalPages, startPage + maxVisiblePages - 1);
    if (endPage - startPage < maxVisiblePages - 1) {
        startPage = Math.max(1, endPage - maxVisiblePages + 1);
    }
    
    for (let p = startPage; p <= endPage; p++) {
        if (p === currentPage) {
            html += `<button class="btn btn-sm btn-lazada px-3 py-1 active">${p}</button>`;
        } else {
            html += `<button class="btn btn-sm btn-outline-lazada-secondary px-3 py-1" onclick="goToPage(${p})">${p}</button>`;
        }
    }
    
    html += `<button class="btn btn-sm btn-outline-lazada-secondary px-2 py-1" ${currentPage === totalPages ? 'disabled' : ''} onclick="goToPage(${currentPage + 1})"><i class="fa-solid fa-angle-right"></i></button>`;
    html += `<button class="btn btn-sm btn-outline-lazada-secondary px-2 py-1" ${currentPage === totalPages ? 'disabled' : ''} onclick="goToPage(${totalPages})"><i class="fa-solid fa-angles-right"></i></button>`;
    
    container.innerHTML = html;
}

// Modal Manual Mapping Logic
function openManualMap(id) {
    selectedLazada = id;
    selectedPosId = null;
    selectedPosUnitId = null;
    selectedPosBundleSetId = null;
    const v = ALL_ITEMS.find(x => x.id === id);
    if (!v) return;
    
    document.getElementById('mmLazadaName').textContent = v.groupName + (v.varName ? ` — ${v.varName}` : '');
    document.getElementById('mmLazadaSku').textContent = getMatchKey(v) || 'No SKU';
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
    
    // Find current Lazada item SKU
    let lazadaSku = '';
    if (typeof selectedLazada === 'number') {
        const v = ALL_ITEMS.find(x => x.id === selectedLazada);
        if (v) lazadaSku = getMatchKey(v);
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
    if (!ps && lazadaSku) {
        const exactMatches = typeFiltered.filter(p => p.sku && p.sku.toLowerCase() === lazadaSku.toLowerCase());
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
                    <div style="width:30px;height:30px;background:var(--lz-success-bg);border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0"><i class="fa-solid fa-circle-check" style="color:var(--lz-success);font-size:.75rem"></i></div>
                    <div class="flex-grow-1" style="min-width:0; line-height: 1.4;">
                        <div class="fw-bold text-success" style="font-size: 0.85rem; word-break: break-word;">
                            ${escHtml(formatPosChoiceName(p))} ${multBadge}
                        </div>
                        <div class="d-flex align-items-center gap-2 mt-1" style="font-size:.72rem;">
                            <span class="text-secondary">SKU:</span>
                            <strong class="lz-sku-code border-success text-success" style="font-family: monospace; font-size: 0.75rem;">${escHtml(p.sku)}</strong>
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
        const isExactMatch = !ps && lazadaSku && p.sku && p.sku.toLowerCase() === lazadaSku.toLowerCase();
        if (isExactMatch) return '';
        
        const usedBadge = p.used ? `<span class="lz-badge lz-badge-neutral ms-auto" style="font-size:0.62rem; flex-shrink:0;"><i class="fa-solid fa-link me-1"></i>Linked</span>` : '';
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
            <div style="width:30px;height:30px;background:var(--lz-info-bg);border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0"><i class="fa-solid fa-boxes-stacked" style="color:var(--lz-info);font-size:.75rem"></i></div>
            <div class="flex-grow-1" style="min-width:0; line-height: 1.4;">
                <div class="fw-bold text-dark" style="font-size: 0.85rem; word-break: break-word;">
                    ${escHtml(formatPosChoiceName(p))} ${multBadge}
                </div>
                <div class="d-flex align-items-center gap-2 mt-1" style="font-size:.72rem;">
                    <span class="text-secondary">SKU:</span>
                    <strong class="lz-sku-code text-lazada" style="font-family: monospace; font-size: 0.75rem;">${escHtml(p.sku)}</strong>
                    ${p.brand ? `<span class="text-secondary">| Brand: <strong class="text-dark">${escHtml(p.brand)}</strong></span>` : ''}
                    ${p.barcode ? `<span class="text-secondary">| Barcode: <strong class="text-dark">${escHtml(p.barcode)}</strong></span>` : ''}
                </div>
            </div>
            ${usedBadge}
            ${isSelected ? `<i class="fa-solid fa-circle-check text-lazada ms-2 fs-5"></i>` : ''}
        </div>`;
    }).join('') : `<div class="lz-empty py-4"><i class="fa-solid fa-boxes-stacked d-block"></i><h6>No POS products found</h6></div>`;
    
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
    if (!selectedLazada || (!selectedPosId && !selectedPosBundleSetId)) return;
    const v = ALL_ITEMS.find(x => x.id === selectedLazada);
    const p = selectedPosBundleSetId
        ? POS_ITEMS.find(x => x.item_type === 'bundle' && x.bundle_set_id === selectedPosBundleSetId)
        : POS_ITEMS.find(x => x.id === selectedPosId && x.unit_id === selectedPosUnitId);
    if (!v || !p) return;
    
    const btn = document.getElementById('mmLinkBtn');
    const origHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-2"></i>Saving...';
    
    try {
        const res = await fetch(`${window.BASE_URL}api/lazada/save_mappings.php`, {
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
            // Trigger initial stock sync in the background
            fetch(`${window.BASE_URL}api/lazada/sync_individual.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ mapping_id: v.id })
            }).then(r => r.json()).then(res => {
                if(res.success && typeof EllaToast !== 'undefined') {
                    EllaToast.info(`Initial stock pushed to Lazada: ${res.pushed_stock}`);
                }
            }).catch(e => console.error('Initial sync error:', e));
        } else {
            EllaToast.error(data.error || 'Failed to save mapping');
        }
    } catch (e) {
        EllaToast.error('Network error');
    } finally {
        btn.disabled = false;
        btn.innerHTML = origHtml;
        selectedLazada = null; selectedPosId = null; selectedPosUnitId = null; selectedPosBundleSetId = null;
    }
}

// â”€â”€ Inline unlink confirmation (replaces browser confirm() dialog) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
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
        const res = await fetch(`${window.BASE_URL}api/lazada/save_mappings.php`, {
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
    // First, count all SKU uses within Lazada (ALL_ITEMS) to accurately detect duplicates on the Lazada side
    lazadaSkuCounts = {};
    ALL_ITEMS.forEach(item => {
        const sku = (getMatchKey(item) || '').toLowerCase().trim();
        if (sku) {
            lazadaSkuCounts[sku] = (lazadaSkuCounts[sku] || 0) + 1;
        }
    });

    // Re-evaluate mapStatus dynamically for all unmapped variations based on standard conflict rules
    ALL_ITEMS.forEach(v => {
        if (!v.mapped) {
            const key = (getMatchKey(v) || '').toLowerCase().trim();
            if (!key) {
                v.mapStatus = 'missing_sku';
            } else if (lazadaSkuCounts[key] > 1) {
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
        return key && lazadaSkuCounts[key] > 1 && (!window.lazadaDuplicateWhitelist || !window.lazadaDuplicateWhitelist.has(key));
    });

    // But for the total unmatched count logic, we only count the UNMAPPED duplicate variations
    const unmappedDupVarsCount = ALL_ITEMS.filter(v => {
        const key = (getMatchKey(v) || '').toLowerCase().trim();
        return !v.mapped && key && lazadaSkuCounts[key] > 1;
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

<script src="../../views/lazada/lazada_alerts.js"></script>
<?php require_once '../../includes/footer.php'; ?>


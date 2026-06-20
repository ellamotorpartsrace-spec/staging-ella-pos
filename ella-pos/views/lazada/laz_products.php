<?php
$page_title = 'Lazada Sync — Products';
require_once '../../config/config.php';
require_once '../../includes/auth.php';
requireLogin();
require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';

$db   = new Database();
$conn = $db->getConnection();
$rows = $conn->query("
    SELECT id, lazada_item_id, lazada_model_id, lazada_product_name,
        lazada_variation_name, lazada_parent_sku, lazada_variation_sku,
        lazada_stock, lazada_price, has_variation, last_synced_at, lazada_image_url
    FROM lazada_product_mappings
    ORDER BY lazada_product_name ASC, lazada_variation_name ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Group by parent product (lazada_item_id)
$groups = [];
foreach ($rows as $r) {
    $iid = $r['lazada_item_id'];
    if (!isset($groups[$iid])) {
        $groups[$iid] = [
            'itemId'       => $iid,
            'name'         => $r['lazada_product_name'],
            'parentSku'    => $r['lazada_parent_sku'] ?? '',
            'hasVariation' => (bool)$r['has_variation'],
            'imageUrl'     => $r['lazada_image_url'] ?? '',
            'variations'   => [],
        ];
    }
    $groups[$iid]['variations'][] = [
        'id'       => (int)$r['id'],
        'modelId'  => $r['lazada_model_id'],
        'varName'  => $r['lazada_variation_name'] ?? '',
        'varSku'   => $r['lazada_variation_sku']  ?? '',
        'stock'    => (int)$r['lazada_stock'],
        'price'    => (float)$r['lazada_price'],
        'synced'   => $r['last_synced_at'] ? date('M d, Y H:i', strtotime($r['last_synced_at'])) : 'N/A',
    ];
}

$totalParents = count($groups);
$totalVars    = count($rows);
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/lazada-sync.css?v=<?= filemtime(__DIR__.'/../../assets/css/lazada-sync.css') ?>">
<style>
.lz-item-id  { font-size: 0.75rem; color: var(--text-secondary); font-family: monospace; font-weight: 600; }
.lz-model-id { font-size: 0.75rem; color: var(--text-secondary); font-family: monospace; font-weight: 600; }

.lz-products-hero {
    background: linear-gradient(135deg, #0f136d 0%, #1a237e 100%);
    padding: 2.5rem 2rem;
    border-radius: 0 0 24px 24px;
    box-shadow: 0 10px 30px rgba(15, 19, 109, 0.15);
    margin-bottom: 2rem;
    color: white;
}
.lz-breadcrumb-light a {
    color: rgba(255, 255, 255, 0.7);
    text-decoration: none;
    font-weight: 500;
    transition: color 0.2s;
}
.lz-breadcrumb-light a:hover { color: white; text-decoration: underline; }
</style>

<div class="lz-animate" style="background: #f4f7fb; min-height: calc(100vh - 60px); padding-bottom: 3rem;">
    <?php require_once __DIR__ . '/laz_token_warning.php'; ?>

    <div class="lz-products-hero position-relative overflow-hidden">
        <!-- Decorative Background Graphic -->
        <i class="fa-solid fa-boxes-packing position-absolute text-white" style="font-size: 16rem; opacity: 0.04; right: -2%; top: -15%;"></i>
        
        <div class="container-fluid px-4 position-relative" style="z-index: 2;">
            <div class="d-flex justify-content-between align-items-end flex-wrap gap-3">
                <div>
                    <div class="lz-breadcrumb-light d-flex align-items-center gap-2 mb-3" style="font-size: 0.9rem;">
                        <a href="<?= BASE_URL ?>views/lazada/laz_index.php">Lazada Dashboard</a>
                        <i class="fa-solid fa-chevron-right" style="font-size: 0.6rem; opacity: 0.7;"></i>
                        <span style="opacity: 0.9;">Product Catalog</span>
                    </div>
                    
                    <div class="d-flex align-items-center gap-3">
                        <div class="d-flex align-items-center justify-content-center bg-white shadow-sm" style="width: 60px; height: 60px; border-radius: 16px; font-size: 1.8rem; color: var(--lazada-primary);">
                            <i class="fa-solid fa-bag-shopping"></i>
                        </div>
                        <div>
                            <h1 class="fw-bold mb-0 text-white" style="font-size: 2.2rem; letter-spacing: -0.5px;">
                                Lazada Catalog
                            </h1>
                            <p class="mb-0 mt-1 text-white" style="opacity: 0.85; font-size: 1.05rem;">Live product data fetched directly from your Lazada store.</p>
                        </div>
                    </div>
                </div>
                
                <button class="btn bg-white shadow-sm fw-bold px-4 py-2 d-flex align-items-center gap-2" style="border-radius: 12px; font-size: 1rem; color: var(--lazada-primary); border: 1px solid rgba(255,255,255,0.8); transition: transform 0.2s;" id="btnSync" onclick="syncProducts(this)" onmouseover="this.style.transform='translateY(-2px)'" onmouseout="this.style.transform='translateY(0)'">
                    <i class="fa-solid fa-rotate"></i> Sync Products
                </button>
            </div>
        </div>
    </div>

    <div class="container-fluid px-4">
        <!-- Stat Cards -->
        <div class="row g-4 mb-4">
            <div class="col-xl-3 col-md-6">
                <div class="bg-white p-4" style="border-radius: 16px; box-shadow: 0 4px 15px rgba(0,0,0,0.03); border: 1px solid #f1f5f9; display: flex; align-items: center; gap: 1.2rem;">
                    <div class="d-flex align-items-center justify-content-center" style="width: 54px; height: 54px; border-radius: 14px; background: rgba(15, 19, 109, 0.08); color: var(--lazada-primary); font-size: 1.5rem;">
                        <i class="fa-solid fa-box"></i>
                    </div>
                    <div>
                        <div class="text-uppercase fw-bold" style="font-size: 0.85rem; color: #64748b; letter-spacing: 0.5px;">Total Products</div>
                        <div class="fw-bold" style="font-size: 1.8rem; color: #1e293b; line-height: 1.2;"><?= number_format($totalParents) ?></div>
                    </div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="bg-white p-4" style="border-radius: 16px; box-shadow: 0 4px 15px rgba(0,0,0,0.03); border: 1px solid #f1f5f9; display: flex; align-items: center; gap: 1.2rem;">
                    <div class="d-flex align-items-center justify-content-center" style="width: 54px; height: 54px; border-radius: 14px; background: rgba(15, 19, 109, 0.08); color: var(--lazada-primary); font-size: 1.5rem;">
                        <i class="fa-solid fa-layer-group"></i>
                    </div>
                    <div>
                        <div class="text-uppercase fw-bold" style="font-size: 0.85rem; color: #64748b; letter-spacing: 0.5px;">Total Variations</div>
                        <div class="fw-bold" style="font-size: 1.8rem; color: #1e293b; line-height: 1.2;"><?= number_format($totalVars) ?></div>
                    </div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="bg-white p-4" style="border-radius: 16px; box-shadow: 0 4px 15px rgba(0,0,0,0.03); border: 1px solid #f1f5f9; border-bottom: 4px solid var(--lz-success); display: flex; align-items: center; gap: 1.2rem;">
                    <div class="d-flex align-items-center justify-content-center" style="width: 54px; height: 54px; border-radius: 14px; background: rgba(40, 167, 69, 0.1); color: var(--lz-success); font-size: 1.5rem;">
                        <i class="fa-solid fa-circle-check"></i>
                    </div>
                    <div>
                        <div class="text-uppercase fw-bold" style="font-size: 0.85rem; color: #64748b; letter-spacing: 0.5px;">In Stock</div>
                        <div class="fw-bold" style="font-size: 1.8rem; color: #1e293b; line-height: 1.2;" id="kInStock">—</div>
                    </div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="bg-white p-4" style="border-radius: 16px; box-shadow: 0 4px 15px rgba(0,0,0,0.03); border: 1px solid #f1f5f9; border-bottom: 4px solid var(--lz-danger); display: flex; align-items: center; gap: 1.2rem;">
                    <div class="d-flex align-items-center justify-content-center" style="width: 54px; height: 54px; border-radius: 14px; background: rgba(220, 53, 69, 0.1); color: var(--lz-danger); font-size: 1.5rem;">
                        <i class="fa-solid fa-box-open"></i>
                    </div>
                    <div>
                        <div class="text-uppercase fw-bold" style="font-size: 0.85rem; color: #64748b; letter-spacing: 0.5px;">Out of Stock</div>
                        <div class="fw-bold text-danger" style="font-size: 1.8rem; line-height: 1.2;" id="kOos">—</div>
                    </div>
                </div>
            </div>
        </div>

<!-- Sync Status -->
<div id="syncStatus" class="mb-3" style="display:none"></div>

<!-- Filter Bar -->
<div class="bg-white mb-4" style="border-radius: 16px; box-shadow: 0 4px 15px rgba(0,0,0,0.03); border: 1px solid #f1f5f9;">
    <div class="d-flex flex-wrap align-items-center gap-3" style="padding: 1rem 1.5rem;">
        <div class="lz-search flex-grow-1" style="max-width: 400px; position: relative;">
            <i class="fa-solid fa-search position-absolute text-secondary" style="left: 1rem; top: 50%; transform: translateY(-50%);"></i>
            <input type="text" id="prodSearch" autocomplete="off" placeholder="Search by product name or SKU..." oninput="debouncedRender()" style="width: 100%; padding: 0.75rem 1rem 0.75rem 2.5rem; border-radius: 12px; border: 1px solid #e2e8f0; background: #f8fafc; font-size: 0.95rem; transition: all 0.2s;">
        </div>
        <div class="lz-filter-pills d-flex gap-2">
            <button class="lz-pill active px-3 py-2 border-0 fw-bold rounded-pill" onclick="setFilter('all',this)" style="background: transparent; color: #64748b; font-size: 0.9rem;">All</button>
            <button class="lz-pill px-3 py-2 border-0 fw-bold rounded-pill" onclick="setFilter('in_stock',this)" style="background: transparent; color: #64748b; font-size: 0.9rem;"><i class="fa-solid fa-circle-check me-1 text-success"></i>In Stock</button>
            <button class="lz-pill px-3 py-2 border-0 fw-bold rounded-pill" onclick="setFilter('low',this)" style="background: transparent; color: #64748b; font-size: 0.9rem;"><i class="fa-solid fa-triangle-exclamation me-1 text-warning"></i>Low Stock</button>
            <button class="lz-pill px-3 py-2 border-0 fw-bold rounded-pill" onclick="setFilter('oos',this)" style="background: transparent; color: #64748b; font-size: 0.9rem;"><i class="fa-solid fa-box-open me-1 text-danger"></i>Out of Stock</button>
        </div>
        <div class="text-secondary small ms-auto fw-bold px-3 py-2 bg-light rounded-pill" id="prodCount"></div>
    </div>
</div>

<!-- Products Table (Grouped) -->
<div class="bg-white overflow-hidden" style="border-radius: 16px; box-shadow: 0 4px 15px rgba(0,0,0,0.03); border: 1px solid #f1f5f9;">
    <div class="p-0 lz-table-wrap">
        <table class="lz-table table mb-0 align-middle" id="productsTable" style="border-collapse: collapse;">
            <thead style="background: #f8fafc; border-bottom: 2px solid #e2e8f0;">
                <tr>
                    <th style="min-width: 300px; padding: 1rem 1.5rem; font-weight: 700; color: #475569; font-size: 0.85rem; text-transform: uppercase;">Product / Variation</th>
                    <th style="padding: 1rem 1.5rem; font-weight: 700; color: #475569; font-size: 0.85rem; text-transform: uppercase;">Item ID</th>
                    <th style="padding: 1rem 1.5rem; font-weight: 700; color: #475569; font-size: 0.85rem; text-transform: uppercase;">Parent SKU</th>
                    <th style="padding: 1rem 1.5rem; font-weight: 700; color: #475569; font-size: 0.85rem; text-transform: uppercase;">Variation SKU</th>
                    <th style="padding: 1rem 1.5rem; font-weight: 700; color: #475569; font-size: 0.85rem; text-transform: uppercase;">Model ID</th>
                    <th class="text-center" style="padding: 1rem 1.5rem; font-weight: 700; color: #475569; font-size: 0.85rem; text-transform: uppercase;">Stock</th>
                    <th class="text-end" style="padding: 1rem 1.5rem; font-weight: 700; color: #475569; font-size: 0.85rem; text-transform: uppercase;">Price</th>
                </tr>
            </thead>
            <tbody id="prodBody">
                <tr><td colspan="7"><div class="text-center py-5 text-secondary"><i class="fa-solid fa-spinner fa-spin fs-3 mb-2 d-block"></i><h5>Loading Catalog...</h5></div></td></tr>
            </tbody>
        </table>
    </div>
    <!-- Premium Pagination Footer -->
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-3 p-3" style="background: #f8fafc; border-top: 1px solid #e2e8f0;">
        <div class="d-flex align-items-center gap-2">
            <span class="text-secondary small fw-bold">Items per page:</span>
            <select class="form-select form-select-sm" id="itemsPerPageSelect" onchange="changeItemsPerPage(this.value)" style="width: auto; border-radius: 8px;">
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
</div>

<style>
.lz-filter-pills .lz-pill.active {
    background: var(--lazada-light) !important;
    color: var(--lazada-primary) !important;
}
.lz-search input:focus {
    background: #ffffff !important;
    border-color: var(--lazada-primary) !important;
    box-shadow: 0 0 0 4px rgba(15, 19, 109, 0.1) !important;
    outline: none;
}
</style>

<script>
const GROUPS = <?= json_encode(array_values($groups)) ?>;
let activeFilter = 'all';
let renderTimeout = null;
let currentPage = 1;
let itemsPerPage = 25;

function debouncedRender() {
    currentPage = 1; // Reset page on search
    clearTimeout(renderTimeout);
    renderTimeout = setTimeout(() => renderProducts(), 250);
}

function escHtml(s) { if(!s)return''; return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
function searchTerms(q) {
    return String(q || '').toLowerCase().trim().split(/\s+/).filter(Boolean);
}
function progressiveMatch(terms, fields) {
    if (!terms.length) return true;
    const haystack = fields.map(v => String(v || '').toLowerCase());
    return terms.every(term => haystack.some(field => field.includes(term)));
}

function stockPill(qty) {
    if (qty===0)  return `<span class="lz-stock-pill stock-zero"><i class="fa-solid fa-times"></i>${qty}</span>`;
    if (qty<=5)   return `<span class="lz-stock-pill stock-low"><i class="fa-solid fa-triangle-exclamation"></i>${qty}</span>`;
    return             `<span class="lz-stock-pill stock-high"><i class="fa-solid fa-check"></i>${qty.toLocaleString()}</span>`;
}

function setFilter(f, btn) {
    activeFilter = f;
    currentPage = 1; // Reset page on filter change
    document.querySelectorAll('.lz-filter-pills .lz-pill').forEach(p=>p.classList.remove('active'));
    btn.classList.add('active');
    renderProducts();
}

function changeItemsPerPage(val) {
    itemsPerPage = parseInt(val, 10);
    currentPage = 1;
    renderProducts();
}

function goToPage(page) {
    currentPage = page;
    renderProducts();
}

function renderProducts() {
    const q    = (document.getElementById('prodSearch')?.value||'').trim();
    const terms = searchTerms(q);
    const body = document.getElementById('prodBody');
    let inStock=0, oos=0;
    
    // First, filter groups
    const matchedGroups = [];
    GROUPS.forEach(g => {
        // Count stats (from all variations, not filtered)
        g.variations.forEach(v => { if(v.stock>0) inStock++; else oos++; });

        const vars = g.variations.filter(v => {
            if (!progressiveMatch(terms, [g.name, g.parentSku, v.varName, v.varSku, g.itemId, v.modelId])) return false;
            if (activeFilter==='in_stock' && v.stock<=5)  return false;
            if (activeFilter==='low'      && (v.stock===0||v.stock>5)) return false;
            if (activeFilter==='oos'      && v.stock!==0)  return false;
            return true;
        });

        if (vars.length > 0) {
            matchedGroups.push({ group: g, matchedVars: vars });
        }
    });

    // Update stock counters (only run once on full data)
    if (!q && activeFilter==='all') {
        document.getElementById('kInStock').textContent = inStock.toLocaleString();
        document.getElementById('kOos').textContent     = oos.toLocaleString();
    }

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

        const parentSkuHtml = g.parentSku
            ? `<span class="lz-sku-code">${escHtml(g.parentSku)}</span>`
            : `<span class="text-danger" style="font-size:.75rem;font-style:italic">empty</span>`;

        const imgHtml = g.imageUrl
            ? `<img src="${escHtml(g.imageUrl)}" class="lz-product-img" alt="Product Image">`
            : `<div class="lz-img-placeholder"><i class="fa-solid fa-image"></i></div>`;

        const isSimple = g.variations.length === 1 && (!g.variations[0].varName || g.variations[0].varName.toLowerCase() === 'main item');

        if (isSimple) {
            const v = vars[0];
            if (v) {
                const vSku = v.varSku
                    ? `<span class="lz-sku-code">${escHtml(v.varSku)}</span>`
                    : `<span class="text-danger" style="font-size:.75rem;font-style:italic">empty</span>`;
                const mId = v.modelId
                    ? `<span class="lz-model-id">${escHtml(String(v.modelId))}</span>`
                    : `<span class="text-secondary">—</span>`;

                html += `<tr class="lz-group-start">
                    <td>
                        <div class="d-flex align-items-center gap-3">
                            ${imgHtml}
                            <div>
                                <div class="d-flex align-items-center gap-2 mb-1">
                                    <i class="fa-brands fa-lazada" style="color:var(--lazada-primary);font-size:.85rem;flex-shrink:0"></i>
                                    <span class="fw-bold" style="font-size:.9rem">${escHtml(g.name)}</span>
                                </div>
                            </div>
                        </div>
                    </td>
                    <td><span class="lz-item-id">${escHtml(String(g.itemId))}</span></td>
                    <td>${parentSkuHtml}</td>
                    <td>${vSku}</td>
                    <td>${mId}</td>
                    <td class="text-center">${stockPill(v.stock)}</td>
                    <td class="text-end fw-bold" style="font-size:.85rem">₱${v.price.toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2})}</td>
                </tr>`;
            }
        } else {
            // Parent Row
            html += `<tr class="lz-group-start">
                <td>
                    <div class="d-flex align-items-center gap-3">
                        ${imgHtml}
                        <div>
                            <div class="d-flex align-items-center gap-2 mb-1">
                                <i class="fa-brands fa-lazada" style="color:var(--lazada-primary);font-size:.85rem;flex-shrink:0"></i>
                                <span class="fw-bold" style="font-size:.9rem">${escHtml(g.name)}</span>
                            </div>
                        </div>
                    </div>
                </td>
                <td><span class="lz-item-id">${escHtml(String(g.itemId))}</span></td>
                <td>${parentSkuHtml}</td>
                <td><span class="text-secondary">—</span></td>
                <td><span class="text-secondary">—</span></td>
                <td class="text-center"><span class="text-secondary">—</span></td>
                <td class="text-end"><span class="text-secondary">—</span></td>
            </tr>`;

            // Variation Rows
            vars.forEach(v => {
                const vSku = v.varSku
                    ? `<span class="lz-sku-code">${escHtml(v.varSku)}</span>`
                    : `<span class="text-secondary">—</span>`;
                const mId = v.modelId
                    ? `<span class="lz-model-id">${escHtml(String(v.modelId))}</span>`
                    : `<span class="text-secondary">—</span>`;
                
                const vNameHtml = v.varName
                    ? `<span class="lz-var-name-text">${escHtml(v.varName)}</span>`
                    : `<span class="lz-var-name-text text-secondary fst-italic">Main Item</span>`;

                html += `<tr>
                    <td class="lz-tree-indent">
                        <div class="d-flex align-items-center">
                            ${vNameHtml}
                        </div>
                    </td>
                    <td><span class="text-secondary">—</span></td>
                    <td><span class="text-secondary">—</span></td>
                    <td>${vSku}</td>
                    <td>${mId}</td>
                    <td class="text-center">${stockPill(v.stock)}</td>
                    <td class="text-end fw-bold" style="font-size:.85rem">₱${v.price.toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2})}</td>
                </tr>`;
            });
        }
    });

    if (!GROUPS.length) {
        body.innerHTML = `<tr><td colspan="7"><div class="text-center py-5 my-3">
                <i class="fa-solid fa-box-open text-secondary mb-3" style="font-size: 4.5rem; opacity: 0.2;"></i>
                <h4 class="fw-bold" style="color: #1e293b;">No products found</h4>
                <p class="text-secondary mb-0" style="font-size: 1.05rem;">Click <strong class="text-primary"><i class="fa-solid fa-rotate me-1"></i>Sync Products</strong> above to fetch your live Lazada catalog.</p>
            </div></td></tr>`;
        document.getElementById('paginationStatus').textContent = 'Page 1 of 1 (0 items)';
        document.getElementById('paginationButtons').innerHTML = '';
        return;
    }
    if (!totalItems) {
        body.innerHTML = `<tr><td colspan="7"><div class="text-center py-5 my-3">
            <i class="fa-solid fa-magnifying-glass text-secondary mb-3" style="font-size: 4rem; opacity: 0.15;"></i>
            <h4 class="fw-bold" style="color: #1e293b;">No results found</h4>
            <p class="text-secondary mb-0" style="font-size: 1.05rem;">Try adjusting your search query or filter to find what you're looking for.</p>
        </div></td></tr>`;
        document.getElementById('paginationStatus').textContent = 'Page 1 of 1 (0 items)';
        document.getElementById('paginationButtons').innerHTML = '';
        return;
    }

    body.innerHTML = html;
    
    // Update labels and pagination status
    document.getElementById('prodCount').innerHTML = `Showing ${start + 1}-${Math.min(end, totalItems)} of ${totalItems} products`;
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

async function syncProducts(btn) {
    const orig = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-2"></i>Syncing...';
    const statusEl = document.getElementById('syncStatus');
    statusEl.style.display = 'block';

    let totalItems=0, totalRows=0, inserted=0, updated=0, offset=0, hasNext=true;
    try {
        while (hasNext) {
            statusEl.innerHTML = `<div class="lz-alert-card info" style="border-radius:var(--lz-radius-md)"><i class="fa-solid fa-spinner fa-spin"></i><span class="small fw-bold">Fetching page ${Math.floor(offset/50)+1}... (${totalRows} rows so far)</span></div>`;
            const res  = await fetch(`${window.BASE_URL}api/lazada/fetch_products.php?offset=${offset}`);
            const data = await res.json();
            if (data.success) {
                totalItems += data.total_items||0; totalRows += data.total_rows||0;
                inserted += data.inserted||0; updated += data.updated||0;
                hasNext = data.has_next_page; offset = data.next_offset;
            } else {
                statusEl.innerHTML = `<div class="lz-alert-card danger" style="border-radius:var(--lz-radius-md)"><i class="fa-solid fa-circle-xmark"></i><span class="small fw-bold">${data.error}</span></div>`;
                hasNext = false;
                if (typeof EllaToast!=='undefined') EllaToast.error(data.error);
                return;
            }
        }
        statusEl.innerHTML = `<div class="lz-alert-card success" style="border-radius:var(--lz-radius-md)"><i class="fa-solid fa-circle-check"></i><span class="small fw-bold">Sync complete! ${totalRows} rows synced (${inserted} new, ${updated} updated). Reloading...</span></div>`;

        // Log successful sync to lazada_sync_logs
        fetch(`${window.BASE_URL}api/lazada/log_product_sync.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                status: 'success',
                summary: `${totalRows} Synced (${inserted} New, ${updated} Updated)`
            })
        });

        if (typeof EllaToast!=='undefined') EllaToast.success(`Synced ${totalRows} products from Lazada`);
        setTimeout(()=>location.reload(), 1500);
    } catch(e) {
        statusEl.innerHTML = `<div class="lz-alert-card danger" style="border-radius:var(--lz-radius-md)"><i class="fa-solid fa-circle-xmark"></i><span class="small fw-bold">Network error: ${e.message}</span></div>`;

        // Log failed sync to lazada_sync_logs
        fetch(`${window.BASE_URL}api/lazada/log_product_sync.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ status: 'failed', error: e.message })
        });

        if (typeof EllaToast!=='undefined') EllaToast.error('Network error');
    } finally {
        btn.disabled = false; btn.innerHTML = orig;
    }
}

document.addEventListener('DOMContentLoaded', () => {
    // Init stock counters
    let inStock=0, oos=0;
    GROUPS.forEach(g => g.variations.forEach(v => { if(v.stock>0) inStock++; else oos++; }));
    document.getElementById('kInStock').textContent = inStock.toLocaleString();
    document.getElementById('kOos').textContent     = oos.toLocaleString();
    renderProducts();
});
</script>

<script src="../../views/lazada/laz_alerts.js"></script>
<?php require_once '../../includes/footer.php'; ?>


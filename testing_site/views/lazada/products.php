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
    <div class="mb-4" style="background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%); border-radius: 16px; padding: 2rem 2.5rem; box-shadow: 0 10px 30px rgba(30,58,138,0.15); position: relative; overflow: hidden;">
        <!-- Breadcrumb inside -->
        <nav aria-label="breadcrumb" style="position: relative; z-index: 2;">
            <ol class="breadcrumb mb-3" style="font-size: 0.85rem;">
                <li class="breadcrumb-item"><a href="<?= BASE_URL ?>views/lazada/index.php" style="color: rgba(255,255,255,0.7); text-decoration: none; font-weight: 500;">Lazada Dashboard</a></li>
                <li class="breadcrumb-item active" style="color: white; font-weight: 600;">Product Catalog</li>
            </ol>
        </nav>
        
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3" style="position: relative; z-index: 2;">
            <div class="d-flex align-items-center gap-3">
                <div style="background: white; border-radius: 14px; width: 64px; height: 64px; display: flex; align-items: center; justify-content: center; box-shadow: 0 4px 15px rgba(0,0,0,0.1);">
                    <i class="fa-solid fa-bag-shopping" style="color: #2563eb; font-size: 1.8rem;"></i>
                </div>
                <div>
                    <h1 class="mb-1 fw-bolder" style="font-size: 2rem; letter-spacing: -0.5px; color: white;">Lazada Catalog</h1>
                    <p class="mb-0" style="color: rgba(255,255,255,0.8); font-size: 0.95rem;">Live product data fetched directly from your Lazada store.</p>
                </div>
            </div>
            <div class="d-flex gap-2 align-items-center">

                <button class="btn btn-light fw-bold px-4 rounded-pill d-flex align-items-center" id="btnRefreshProducts" disabled style="color: #2563eb; height: 42px; box-shadow: 0 4px 10px rgba(0,0,0,0.15);">
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
                <input type="text" class="form-control bg-transparent border-0 shadow-none" id="searchProducts" placeholder="Search by product name or SKU..." oninput="handleSearch()" style="font-size: 0.9rem;">
            </div>
            
            <button class="btn rounded-pill fw-bold px-4" id="btnFilterAll" onclick="setFilter('all')" style="background: #eef2ff; color: #111827; font-size: 0.85rem;">All</button>
            <button class="btn btn-link text-decoration-none fw-bold px-3" id="btnFilterInstock" onclick="setFilter('instock')" style="color: #64748b; font-size: 0.85rem;"><i class="fa-solid fa-circle-check text-success me-1"></i> In Stock</button>
            <button class="btn btn-link text-decoration-none fw-bold px-3" id="btnFilterLowstock" onclick="setFilter('lowstock')" style="color: #64748b; font-size: 0.85rem;"><i class="fa-solid fa-triangle-exclamation text-warning me-1"></i> Low Stock</button>
            <button class="btn btn-link text-decoration-none fw-bold px-3" id="btnFilterOutofstock" onclick="setFilter('outofstock')" style="color: #64748b; font-size: 0.85rem;"><i class="fa-solid fa-box-open text-danger me-1"></i> Out of Stock</button>
            
            <div class="ms-auto d-flex align-items-center gap-3">
                <span class="small text-muted fw-bold" id="topPageInfo">Showing 0-0 of 0 products</span>
            </div>
        </div>
    </div>

    <!-- Products Table -->
    <div class="card border-0 rounded-4 mb-4" style="box-shadow: 0 4px 15px rgba(0,0,0,0.03); overflow: hidden;" id="productsContainer">
        <div class="table-responsive">
            <table class="table lz-table mb-0 align-middle table-hover">
                <thead style="background: #f8fafc; font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.5px; color: #64748b;">
                    <tr>
                        <th class="ps-4 py-3 border-0" style="width: 55%;">Product/Variation</th>
                        <th class="py-3 border-0" style="width: 15%;">Product ID</th>
                        <th class="py-3 border-0 text-center" style="width: 10%;">Stock</th>
                        <th class="py-3 border-0 text-end" style="width: 10%;">Price</th>
                        <th class="pe-4 py-3 border-0 text-end" style="width: 10%;">Mapping Status</th>
                    </tr>
                </thead>
                <tbody id="productsTbody">
                    <tr><td colspan="5" class="text-center py-5 text-muted"><i class="fa-solid fa-circle-notch fa-spin me-2"></i> Loading products from database...</td></tr>
                </tbody>
            </table>
        </div>
        <div class="card-footer bg-white border-top py-3 d-flex align-items-center justify-content-between" id="paginationControls" style="display: none !important;">
            <div class="d-flex align-items-center gap-2">
                <span class="small text-muted">Rows per page:</span>
                <select class="form-select form-select-sm" id="rowsPerPage" style="width: 70px;" onchange="changePerPage()">
                    <option value="10">10</option>
                    <option value="25" selected>25</option>
                    <option value="50">50</option>
                    <option value="100">100</option>
                </select>
            </div>
            <div class="d-flex align-items-center gap-3">
                <span class="small text-muted" id="pageInfo">Showing 1-10 of 0</span>
                <div class="btn-group">
                    <button class="btn btn-sm btn-outline-secondary" id="btnPrevPage" onclick="prevPage()"><i class="fa-solid fa-chevron-left"></i></button>
                    <button class="btn btn-sm btn-outline-secondary" id="btnNextPage" onclick="nextPage()"><i class="fa-solid fa-chevron-right"></i></button>
                </div>
            </div>
        </div>
    </div>

    <!-- Empty State -->
    <div class="lz-card d-none" id="emptyState" style="animation-delay:0.1s">
        <div class="lz-card-body p-5 text-center">
            <div style="width:90px;height:90px;background:var(--lazada-light);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 1.5rem;">
                <i class="fa-solid fa-bag-shopping" style="font-size:2.2rem;color:var(--lazada-primary);"></i>
            </div>
            <h5 class="fw-bold mb-2" style="color:var(--lazada-primary)">No Products Found</h5>
            <p class="text-muted mb-3" style="max-width:420px;margin:0 auto;">
                There are currently no products saved in the database for this Lazada store. Click 'Sync Products' to fetch them from Lazada.
            </p>
            <button class="btn btn-lazada rounded-pill" onclick="syncProducts()"><i class="fa-solid fa-rotate me-2"></i> Sync Now</button>
        </div>
    </div>

</div>

<?php require_once '../../includes/footer.php'; ?>

<script>
let allProducts = [];
let currentFilter = 'all';
let currentPage = 1;
let itemsPerPage = 25;

function handleSearch() {
    currentPage = 1;
    renderProducts();
}

function setFilter(filterType) {
    currentFilter = filterType;
    
    // Update active state styling
    ['All', 'Instock', 'Lowstock', 'Outofstock'].forEach(type => {
        let btn = document.getElementById('btnFilter' + type);
        if(btn) {
            if(type.toLowerCase() === filterType) {
                btn.style.background = '#eef2ff';
                btn.style.color = '#111827';
                btn.classList.add('rounded-pill', 'px-4');
                btn.classList.remove('btn-link', 'px-3');
            } else {
                btn.style.background = 'transparent';
                btn.style.color = '#64748b';
                btn.classList.remove('rounded-pill', 'px-4');
                btn.classList.add('btn-link', 'px-3');
            }
        }
    });
    
    currentPage = 1;
    renderProducts();
}

function changePerPage() {
    itemsPerPage = parseInt(document.getElementById('rowsPerPage').value);
    currentPage = 1;
    renderProducts();
}

function prevPage() {
    if (currentPage > 1) {
        currentPage--;
        renderProducts();
    }
}

function nextPage() {
    currentPage++;
    renderProducts();
}

async function loadProducts() {
    try {
        const res = await fetch(`${window.BASE_URL}api/lazada/get_mappings.php`);
        const text = await res.text(); // fetch as text first to catch fatal errors
        let data;
        try {
            data = JSON.parse(text);
        } catch (e) {
            console.error("Raw response:", text);
            EllaToast.error("JSON Error: The server returned invalid data. Check console.");
            showEmptyState();
            return;
        }
        
        if (data.groups) {
            allProducts = data.groups;
            renderProducts();
            updateStats();
        } else if (data.error) {
            console.error("API Error:", data.error);
            EllaToast.error("Database Error: " + data.error);
            showEmptyState();
        } else {
            showEmptyState();
        }
    } catch (e) {
        console.error("Failed to load products", e);
        EllaToast.error("Network error while loading products.");
        showEmptyState();
    }
}

function updateStats() {
    const totalProducts = allProducts.length;
    let totalVariations = 0;
    let inStock = 0;
    let outOfStock = 0;

    allProducts.forEach(p => {
        if (p.variations && p.variations.length > 0) {
            totalVariations += p.variations.length;
            p.variations.forEach(v => {
                if (v.online > 0) inStock++;
                else outOfStock++;
            });
        } else {
            // No variations, count the parent as 1 variant if it has stock data
        }
    });

    document.getElementById('lzTotalProducts').innerText = totalProducts;
    document.getElementById('lzTotalVariations').innerText = totalVariations;
    document.getElementById('lzInStock').innerText = inStock;
    document.getElementById('lzOos').innerText = outOfStock;
}

function renderProducts() {
    const tbody = document.getElementById('productsTbody');
    const container = document.getElementById('productsContainer');
    const emptyState = document.getElementById('emptyState');
    
    // Quick search filter
    const searchTerm = document.getElementById('searchProducts').value.toLowerCase();
    
    let filteredProducts = allProducts;
    
    if (currentFilter !== 'all') {
        filteredProducts = filteredProducts.filter(p => {
            if (!p.variations || p.variations.length === 0) return false;
            
            // For stock filters, evaluate based on online stock
            if (currentFilter === 'instock') {
                return p.variations.some(v => v.online > 5);
            } else if (currentFilter === 'lowstock') {
                return p.variations.some(v => v.online > 0 && v.online <= 5);
            } else if (currentFilter === 'outofstock') {
                return p.variations.every(v => v.online === 0);
            }
            return true;
        });
    }

    if (searchTerm) {
        filteredProducts = filteredProducts.filter(p => {
            return p.name.toLowerCase().includes(searchTerm) || 
                   p.itemId.toString().includes(searchTerm) || 
                   (p.parentSku && p.parentSku.toLowerCase().includes(searchTerm)) ||
                   p.variations.some(v => v.variationSku && v.variationSku.toLowerCase().includes(searchTerm));
        });
    }

    const totalFiltered = filteredProducts.length;

    if (totalFiltered === 0) {
        container.classList.add('d-none');
        emptyState.classList.remove('d-none');
        document.getElementById('paginationControls').style.setProperty('display', 'none', 'important');
        return;
    }
    
    container.classList.remove('d-none');
    emptyState.classList.add('d-none');
    document.getElementById('paginationControls').style.setProperty('display', 'flex', 'important');
    
    // Pagination logic
    const maxPage = Math.ceil(totalFiltered / itemsPerPage) || 1;
    if (currentPage > maxPage) currentPage = maxPage;
    
    const startIdx = (currentPage - 1) * itemsPerPage;
    const endIdx = startIdx + itemsPerPage;
    const paginatedProducts = filteredProducts.slice(startIdx, endIdx);
    
    const pageText = `Showing ${startIdx + 1}-${Math.min(endIdx, totalFiltered)} of ${totalFiltered} products`;
    document.getElementById('pageInfo').innerText = pageText;
    const topPageInfo = document.getElementById('topPageInfo');
    if (topPageInfo) topPageInfo.innerText = pageText;
    document.getElementById('btnPrevPage').disabled = (currentPage === 1);
    document.getElementById('btnNextPage').disabled = (currentPage === maxPage);
    
    let html = '';
    paginatedProducts.forEach(p => {
        let hasRealVariations = p.variations && p.variations.length > 1;

        let imgHtml = p.imageUrl 
            ? `<img src="${p.imageUrl}" style="max-width: 100%; max-height: 100%; object-fit: contain;">` 
            : `<i class="fa-solid fa-image text-muted opacity-50"></i>`;

        if (!hasRealVariations && p.variations && p.variations.length > 0) {
            // SINGLE ITEM - No variations. Render one complete row.
            let v = p.variations[0];
            html += `
                <tr style="border-bottom: 1px solid #f1f5f9;">
                    <td class="ps-4">
                        <div class="d-flex align-items-center gap-3">
                            <div style="width: 48px; height: 48px; border-radius: 8px; border: 1px solid #e2e8f0; overflow: hidden; display: flex; align-items: center; justify-content: center; background: #fff; flex-shrink: 0;">
                                ${imgHtml}
                            </div>
                            <div>
                                <div class="fw-bold" style="color: #1e293b; font-size: 0.95rem; max-width: 400px; white-space: normal; word-break: break-word; line-height: 1.4;">${p.name}</div>
                            </div>
                        </div>
                    </td>
                    <td>
                        <div class="fw-600 text-dark" style="font-size: 0.9rem;">${p.itemId}</div>
                    </td>
                    <td class="text-center">
                        <div class="fw-bold ${v.online > 0 ? 'text-success' : 'text-danger'}">${v.online}</div>
                    </td>
                    <td class="text-end">
                        <div class="fw-600 text-dark">₱${parseFloat(v.price).toFixed(2)}</div>
                    </td>
                    <td class="pe-4 text-end">
                        ${v.mapped 
                            ? `<span class="badge" style="background-color: #f0fdf4; color: #16a34a; border: 1px solid #bbf7d0; font-size: 0.75rem; padding: 0.4em 0.8em; font-weight: 600;"><i class="fa-solid fa-link me-1"></i> Mapped</span>` 
                            : `<span class="badge" style="background-color: #fff7ed; color: #ea580c; border: 1px solid #fed7aa; font-size: 0.75rem; padding: 0.4em 0.8em; font-weight: 600;"><i class="fa-solid fa-link-slash me-1"></i> Unmapped</span>`
                        }
                    </td>
                </tr>
            `;
        } else if (p.variations) {
            // HAS VARIATIONS - Render Parent row, then Child rows
            html += `
                <tr style="background: #fdfdfd; border-bottom: 1px solid #f1f5f9;">
                    <td class="ps-4">
                        <div class="d-flex align-items-center gap-3 py-2">
                            <div style="width: 48px; height: 48px; border-radius: 8px; border: 1px solid #e2e8f0; overflow: hidden; display: flex; align-items: center; justify-content: center; background: #fff; flex-shrink: 0;">
                                ${imgHtml}
                            </div>
                            <div>
                                <div class="fw-bold" style="color: #1e293b; font-size: 0.95rem; max-width: 400px; white-space: normal; word-break: break-word; line-height: 1.4;">${p.name}</div>
                            </div>
                        </div>
                    </td>
                    <td>
                        <div class="fw-600 text-dark" style="font-size: 0.9rem;">${p.itemId}</div>
                    </td>
                    <td colspan="3"></td>
                </tr>
            `;
            
            p.variations.forEach(v => {
                let dispName = v.varName || v.variationSku || 'Variant';
                let variationImgHtml = p.imageUrl 
                    ? `<img src="${p.imageUrl}" style="max-width: 100%; max-height: 100%; object-fit: contain;">` 
                    : `<i class="fa-solid fa-image text-muted opacity-50" style="font-size: 14px;"></i>`;

                html += `
                    <tr style="border-bottom: 1px solid #f1f5f9;">
                        <td class="ps-4">
                            <div class="d-flex align-items-center gap-3" style="padding-left: 2rem;">
                                <div style="width: 32px; height: 32px; border-radius: 4px; border: 1px solid #e2e8f0; overflow: hidden; display: flex; align-items: center; justify-content: center; background: #fff; flex-shrink: 0;">
                                    ${variationImgHtml}
                                </div>
                                <div>
                                    <div class="fw-bold text-dark" style="font-size: 0.9rem; max-width: 350px; white-space: normal; word-break: break-word; line-height: 1.4;">${dispName}</div>
                                </div>
                            </div>
                        </td>
                        <td></td>
                        <td class="text-center">
                            <div class="fw-bold ${v.online > 0 ? 'text-success' : 'text-danger'}">${v.online}</div>
                        </td>
                        <td class="text-end">
                            <div class="fw-600 text-dark">₱${parseFloat(v.price).toFixed(2)}</div>
                        </td>
                        <td class="pe-4 text-end">
                            ${v.mapped 
                                ? `<span class="badge" style="background-color: #f0fdf4; color: #16a34a; border: 1px solid #bbf7d0; font-size: 0.75rem; padding: 0.4em 0.8em; font-weight: 600;"><i class="fa-solid fa-link me-1"></i> Mapped</span>` 
                                : `<span class="badge" style="background-color: #fff7ed; color: #ea580c; border: 1px solid #fed7aa; font-size: 0.75rem; padding: 0.4em 0.8em; font-weight: 600;"><i class="fa-solid fa-link-slash me-1"></i> Unmapped</span>`
                            }
                        </td>
                    </tr>
                `;
            });
        }
    });
    
    tbody.innerHTML = html;
}

function showEmptyState() {
    document.getElementById('productsContainer').classList.add('d-none');
    document.getElementById('emptyState').classList.remove('d-none');
    updateStats();
}

async function syncProducts() {
    const btn = document.getElementById('btnRefreshProducts');
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-2"></i> Syncing...';
    btn.disabled = true;
    
    try {
        let hasMore = true;
        let offset = 0;
        let totalProcessed = 0;

        while (hasMore) {
            const res = await fetch(`${window.BASE_URL}api/lazada/fetch_products.php?offset=${offset}`);
            const data = await res.json();
            
            if (data.success) {
                totalProcessed += data.stats ? data.stats.total : 0;
                btn.innerHTML = `<i class="fa-solid fa-spinner fa-spin me-2"></i> Processed ${totalProcessed}...`;
                
                hasMore = data.has_more || false;
                offset = data.next_offset || 0;
            } else {
                throw new Error(data.error || 'Unknown error occurred.');
            }
        }
        
        EllaToast.success(`Products fetched successfully! Processed: ${totalProcessed}`);
        loadProducts(); // Reload table data
    } catch (e) {
        EllaToast.error('Sync error: ' + e.message);
    } finally {
        btn.innerHTML = '<i class="fa-solid fa-rotate me-2"></i> Sync Products';
        btn.disabled = false;
    }
}

document.getElementById('btnRefreshProducts').addEventListener('click', syncProducts);
document.getElementById('btnRefreshProducts').disabled = false;

document.getElementById('searchProducts').addEventListener('input', () => {
    currentPage = 1; // Reset to page 1 on search
    renderProducts();
});
document.getElementById('searchProducts').disabled = false;

// Initialize
document.addEventListener('DOMContentLoaded', () => {
    loadProducts();
});
</script>

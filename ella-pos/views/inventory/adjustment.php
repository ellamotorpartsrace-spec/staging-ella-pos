<?php
// views/inventory/adjustment.php
require_once '../../config/config.php';
require_once '../../includes/auth.php';

// Auth Check
requireLogin();
if (!in_array($_SESSION['role'], ['admin', 'super_admin']) && !hasPermission('adjust_stock')) {
    denyAccess("You do not have permission to adjust inventory.");
}

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
require_once '../../config/database.php';

$db = new Database();
$conn = $db->getConnection();

// Handle Search
$selected_product = null;
$search_results = [];
$search = $_GET['search'] ?? '';

if (!empty($search)) {
    $sqlSearch = "
        SELECT v.variation_id, v.variation_name, v.sku, v.barcode, 
               p.product_name, p.brand_name, p.image_path,
               COALESCE(inv.total_qty, 0) as current_stock,
               (
                   SELECT COALESCE(SUM(m.shopee_stock * COALESCE(u.multiplier, 1)), 0)
                   FROM shopee_product_mappings m
                   LEFT JOIN product_units u ON m.pos_unit_id = u.id
                   WHERE m.pos_product_id = v.variation_id AND m.mapping_status IN ('auto','manual')
               ) as shopee_allocated
        FROM product_variations v
        JOIN products p ON v.product_id = p.product_id
        LEFT JOIN (
            SELECT variation_id, SUM(quantity) as total_qty 
            FROM inventory 
            GROUP BY variation_id
        ) inv ON v.variation_id = inv.variation_id
        WHERE v.status = 'active' 
        AND (p.product_name LIKE ? OR v.sku LIKE ? OR v.barcode LIKE ? OR p.brand_name LIKE ?)
        LIMIT 10
    ";
    $stmt = $conn->prepare($sqlSearch);
    $term = "%$search%";
    $stmt->execute([$term, $term, $term, $term]);
    $search_results = $stmt->fetchAll();
}

if (isset($_GET['id'])) {
    $sqlSelect = "
        SELECT v.*, p.product_name, p.brand_name, p.image_path,
               COALESCE(inv.total_qty, 0) as current_stock,
               (
                   SELECT COALESCE(SUM(m.shopee_stock * COALESCE(u.multiplier, 1)), 0)
                   FROM shopee_product_mappings m
                   LEFT JOIN product_units u ON m.pos_unit_id = u.id
                   WHERE m.pos_product_id = v.variation_id AND m.mapping_status IN ('auto','manual')
               ) as shopee_allocated
        FROM product_variations v
        JOIN products p ON v.product_id = p.product_id
        LEFT JOIN (
            SELECT variation_id, SUM(quantity) as total_qty 
            FROM inventory 
            GROUP BY variation_id
        ) inv ON v.variation_id = inv.variation_id
        WHERE v.variation_id = ?
    ";
    $stmt = $conn->prepare($sqlSelect);
    $stmt->execute([$_GET['id']]);
    $selected_product = $stmt->fetch(PDO::FETCH_ASSOC);
}
?>

<style>
    .adjust-page {
        --adj-ink: #1f2937;
        --adj-muted: #6b7280;
        --adj-line: #e5e7eb;
        --adj-soft: #f8fafc;
        --adj-warn: #d97706;
        --adj-warn-strong: #b45309;
        --adj-success: #15803d;
        background:
            radial-gradient(circle at top left, rgba(245, 158, 11, 0.12), transparent 28rem),
            radial-gradient(circle at top right, rgba(59, 130, 246, 0.08), transparent 26rem);
        min-height: calc(100vh - 72px);
    }

    .adjust-hero {
        border: 1px solid rgba(217, 119, 6, 0.18);
        border-radius: 8px;
        background: linear-gradient(135deg, #ffffff 0%, #fffbeb 52%, #fff7e6 100%);
        box-shadow: 0 16px 32px rgba(15, 23, 42, 0.08);
        position: relative;
        overflow: hidden;
    }

    .adjust-hero::after {
        content: "";
        position: absolute;
        right: -5rem;
        bottom: -8rem;
        width: 21rem;
        height: 21rem;
        background: radial-gradient(circle, rgba(245, 158, 11, 0.20), transparent 65%);
        pointer-events: none;
    }

    .adjust-hero > * {
        position: relative;
        z-index: 1;
    }

    .adjust-title-icon {
        width: 46px;
        height: 46px;
        border-radius: 8px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        background: #fff4d6;
        color: var(--adj-warn);
        flex: 0 0 46px;
    }

    .adjust-kicker {
        color: var(--adj-muted);
        font-size: 0.78rem;
        font-weight: 700;
        letter-spacing: 0.08em;
        text-transform: uppercase;
    }

    .adjust-panel {
        border: 1px solid var(--adj-line) !important;
        border-radius: 8px;
        box-shadow: 0 10px 24px rgba(15, 23, 42, 0.08) !important;
        overflow: visible;
    }

    .adjust-panel .card-header {
        background: #fff;
        border-bottom: 1px solid var(--adj-line);
        padding: 1rem 1.15rem;
    }

    .adjust-panel-accent .card-header {
        background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
        color: #fff;
        border-bottom: 0;
    }

    .adjust-search-dropdown {
        position: absolute;
        z-index: 3000;
        width: 100%;
        margin-top: 8px;
        max-height: min(70vh, 540px);
        overflow-y: auto;
        border: 1px solid var(--adj-line);
        border-radius: 8px;
        background: #fff;
        box-shadow: 0 16px 34px rgba(15, 23, 42, 0.14);
        display: none;
    }

    .adjust-search-item {
        display: flex;
        gap: 12px;
        align-items: center;
        margin: 10px;
        padding: 12px;
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        color: inherit;
        text-decoration: none;
        transition: all 0.2s ease;
        background: #fff;
    }

    .adjust-search-item:hover {
        background: #fffaf0;
        border-color: rgba(217, 119, 6, 0.35);
        transform: translateY(-1px);
    }

    .adjust-search-icon,
    .adjust-product-image {
        width: 56px;
        height: 56px;
        border-radius: 8px;
        flex-shrink: 0;
        border: 1px solid var(--adj-line);
        display: inline-flex;
        align-items: center;
        justify-content: center;
        background: #f9fafb;
        color: #6b7280;
        object-fit: cover;
    }

    .adjust-search-content {
        flex: 1;
        min-width: 0;
    }

    .adjust-search-name {
        font-weight: 800;
        color: #111827;
        margin-bottom: 2px;
        line-height: 1.25;
    }

    .adjust-search-meta {
        font-size: 0.8rem;
        color: #64748b;
        margin-bottom: 6px;
    }

    .adjust-chip {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        font-size: 0.7rem;
        font-weight: 700;
        border-radius: 999px;
        padding: 4px 8px;
        background: #f3f4f6;
        color: #374151;
        margin-right: 6px;
    }

    .adjust-stock-badge {
        white-space: nowrap;
        font-size: 0.73rem;
        font-weight: 700;
        border-radius: 999px;
        padding: 5px 9px;
        border: 1px solid #e5e7eb;
        background: #ecfeff;
        color: #155e75;
    }

    .adjust-note {
        border: 1px solid #bae6fd;
        border-radius: 8px;
        background: linear-gradient(135deg, #f0f9ff 0%, #f8fbff 100%);
    }

    .adjust-product-shell {
        border: 1px solid var(--adj-line);
        border-radius: 8px;
        background: var(--adj-soft);
    }

    .adjust-result-box {
        border: 1px solid #fde68a;
        border-radius: 8px;
        background: linear-gradient(135deg, #fffbeb 0%, #fff7e6 100%);
    }

    .adjust-empty {
        min-height: 430px;
        border: 1px dashed #cbd5e1;
        border-radius: 8px;
        background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
    }

    .adjust-page .form-control,
    .adjust-page .form-select,
    .adjust-page .input-group-text {
        border-color: #dbe1ea;
    }

    .adjust-page .form-control:focus,
    .adjust-page .form-select:focus {
        border-color: rgba(217, 119, 6, 0.55);
        box-shadow: 0 0 0 0.2rem rgba(217, 119, 6, 0.12);
    }

    @media (max-width: 767.98px) {
        .adjust-search-item {
            gap: 10px;
            padding: 10px;
        }

        .adjust-search-icon,
        .adjust-product-image {
            width: 48px;
            height: 48px;
        }
    }
</style>

<div class="container-fluid p-3 p-lg-4 adjust-page">

    <!-- Page Header -->
    <div class="adjust-hero p-3 p-lg-4 mb-4">
        <div class="d-flex flex-column flex-lg-row justify-content-between gap-3">
            <div class="d-flex gap-3 align-items-start">
                <span class="adjust-title-icon">
                    <i class="fa-solid fa-sliders fa-lg"></i>
                </span>
                <div>
                    <div class="adjust-kicker mb-1">Inventory correction</div>
                    <h3 class="fw-bold text-dark mb-1">Stock Adjustment</h3>
                    <p class="text-muted mb-0">Correct inventory discrepancies with full traceability and reason logging.</p>
                </div>
            </div>
            <div class="d-flex align-items-start gap-2">
                <a href="movements.php" class="btn btn-light border shadow-sm">
                    <i class="fa-solid fa-arrows-rotate me-1"></i>Stock Movements
                </a>
                <a href="index.php" class="btn btn-outline-secondary bg-white shadow-sm">
                    <i class="fa-solid fa-arrow-left me-1"></i>Inventory
                </a>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <!-- Search Column -->
        <div class="col-lg-5">
            <div class="card adjust-panel border-0 mb-4">
                <div class="card-header bg-white fw-bold">
                    <i class="fa-solid fa-magnifying-glass text-primary me-2"></i>Find Product
                </div>
                <div class="card-body">
                    <!-- Progressive Search Input -->
                    <div class="position-relative mb-3">
                        <div class="input-group">
                            <span class="input-group-text"><i class="fa-solid fa-barcode"></i></span>
                            <input type="text" id="adjustment-search" class="form-control"
                                placeholder="Scan Barcode, Name or SKU..." value="<?= htmlspecialchars($search) ?>"
                                autofocus autocomplete="off">
                            <span class="input-group-text d-none" id="adjustment-search-spinner">
                                <i class="fa-solid fa-spinner fa-spin text-primary"></i>
                            </span>
                        </div>
                        <div id="adjustment-search-results" class="adjust-search-dropdown"></div>
                    </div>

                    <?php if (isset($_GET['id']) && !$selected_product): ?>
                        <div class="alert alert-warning small">Product not found</div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Instructions/Notes -->
            <div class="alert adjust-note border-0 shadow-sm">
                <h6 class="fw-bold"><i class="fa-solid fa-circle-info me-2"></i>Note</h6>
                <p class="small mb-0">Use this page to correct stock discrepancies. All adjustments are logged and
                    cannot be undone. For regular restocking, use the <a href="restock.php" class="fw-bold">Restock
                        Page</a>.</p>
            </div>
        </div>

        <!-- Adjustment Form Column -->
        <div class="col-lg-7">
            <?php if ($selected_product): ?>
                <div class="card adjust-panel adjust-panel-accent border-0 h-100">
                    <div class="card-header fw-bold d-flex justify-content-between">
                        <span><i class="fa-solid fa-pen-to-square me-2"></i>Adjustment Entry</span>
                        <span><?= htmlspecialchars($selected_product['sku'] ?? 'NO SKU') ?></span>
                    </div>
                    <div class="card-body p-4">

                        <!-- Product Summary -->
                        <div class="d-flex align-items-start mb-4 p-3 adjust-product-shell">
                            <?php if (!empty($selected_product['image_path'])): ?>
                                <img src="<?= BASE_URL . $selected_product['image_path'] ?>" class="adjust-product-image me-3">
                            <?php else: ?>
                                <div class="adjust-product-image me-3">
                                    <i class="fa-solid fa-image fa-lg"></i>
                                </div>
                            <?php endif; ?>
                            <div>
                                <h5 class="fw-bold mb-1"><?= htmlspecialchars($selected_product['product_name']) ?></h5>
                                <div class="text-muted small">
                                    <?= htmlspecialchars($selected_product['brand_name']) ?> -
                                    <?= htmlspecialchars($selected_product['variation_name']) ?>
                                </div>
                                <?php
                                $phys = max(0, $selected_product['current_stock'] - $selected_product['shopee_allocated']);
                                ?>
                                <div class="badge bg-primary mt-1" title="Total: <?= $selected_product['current_stock'] ?> | Online: <?= $selected_product['shopee_allocated'] ?>">Current Stock: <?= $phys ?>
                                </div>
                            </div>
                        </div>

                        <?php if (isset($_GET['error'])): ?>
                            <div class="alert alert-danger"><?= htmlspecialchars($_GET['error']) ?></div>
                        <?php endif; ?>
                        <?php if (isset($_GET['success'])): ?>
                            <div class="alert alert-success">Adjustment recorded successfully!</div>
                        <?php endif; ?>

                        <form action="../../api/inventory/process_adjustment.php" method="POST" id="adjustmentForm"
                            onsubmit="submitWithPassword(event)">
                            <input type="hidden" name="variation_id" value="<?= $selected_product['variation_id'] ?>">
                            <input type="hidden" name="current_stock" value="<?= $phys ?>">

                            <!-- Hidden input for final signed quantity -->
                            <input type="hidden" name="quantity_adjustment" id="final_adjustment">

                            <div class="row g-3">

                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Action</label>
                                    <div class="btn-group w-100" role="group">
                                        <input type="radio" class="btn-check" name="action_type" id="act_add" value="add"
                                            checked onchange="updateUI()">
                                        <label class="btn btn-outline-success" for="act_add">
                                            <i class="fa-solid fa-plus me-1"></i> Add Stock
                                        </label>

                                        <input type="radio" class="btn-check" name="action_type" id="act_sub" value="sub"
                                            onchange="updateUI()">
                                        <label class="btn btn-outline-danger" for="act_sub">
                                            <i class="fa-solid fa-minus me-1"></i> Remove Stock
                                        </label>
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Quantity</label>
                                    <input type="number" id="input_qty" class="form-control form-control-lg fw-bold"
                                        placeholder="0" min="1" required>
                                </div>

                                <div class="col-12">
                                    <div class="p-3 adjust-result-box d-flex justify-content-between align-items-center">
                                        <span class="text-muted">Resulting Stock:</span>
                                        <span class="h4 fw-bold mb-0"
                                            id="preview_stock"><?= $phys ?></span>
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Reason</label>
                                    <select name="reason" class="form-select" required>
                                        <option value="">-- Select Reason --</option>
                                        <option value="Damaged">Damaged / Broken</option>
                                        <option value="Lost">Lost / Stolen</option>
                                        <option value="Expired">Expired</option>
                                        <option value="Correction">Inventory Count Correction</option>
                                        <option value="Return">Customer Return (Restock)</option>
                                        <option value="Found">Found Item</option>
                                        <option value="Other">Other</option>
                                    </select>
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label">Remarks (Optional)</label>
                                    <input type="text" name="remarks" class="form-control"
                                        placeholder="Additional details...">
                                </div>

                                <div class="col-12 mt-4">
                                    <button type="submit" class="btn btn-warning w-100 shadow-sm fw-bold">
                                        <i class="fa-solid fa-save me-1"></i> Save Adjustment
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            <?php else: ?>
                <div class="adjust-empty h-100 d-flex align-items-center justify-content-center text-center p-5">
                    <div class="text-muted">
                        <i class="fa-solid fa-arrow-left fa-3x mb-3 opacity-25"></i>
                        <h5>No Product Selected</h5>
                        <p>Search and select a product from the left to adjust stock.</p>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
    function updateUI() {
        const action = document.querySelector('input[name="action_type"]:checked').value;
        const qtyInput = document.getElementById('input_qty');
        const currentStock = <?= $phys ?? 0 ?>;
        const qty = parseInt(qtyInput.value) || 0;

        let newStock = currentStock;
        let finalAdj = 0;

        if (action === 'add') {
            newStock = currentStock + qty;
            finalAdj = qty;
            qtyInput.classList.remove('text-danger', 'border-danger');
            qtyInput.classList.add('text-success', 'border-success');
        } else {
            newStock = currentStock - qty;
            finalAdj = -qty;
            qtyInput.classList.remove('text-success', 'border-success');
            qtyInput.classList.add('text-danger', 'border-danger');
        }

        const previewEl = document.getElementById('preview_stock');
        previewEl.innerText = newStock;

        // Validate negative stock visually
        if (newStock < 0) {
            previewEl.classList.add('text-danger');
        } else {
            previewEl.classList.remove('text-danger');
        }

        document.getElementById('final_adjustment').value = finalAdj;
    }

    function validateForm() {
        const action = document.querySelector('input[name="action_type"]:checked').value;
        const qty = parseInt(document.getElementById('input_qty').value) || 0;
        const currentStock = <?= $phys ?? 0 ?>;

        if (qty <= 0) {
            EllaToast.warning("Please enter a valid quantity greater than 0.");
            return false;
        }

        if (action === 'sub' && (currentStock - qty) < 0) {
            if (!confirm("Warning: This adjustment will result in NEGATIVE stock. Continue?")) {
                return false;
            }
        }

        updateUI();
        return true;
    }

    function submitWithPassword(e) {
        e.preventDefault();
        if (!validateForm()) return false;

        <?php if ($_SESSION['role'] !== 'super_admin'): ?>
            Swal.fire({
                background: '#ffffff',
                width: '460px',
                html: `
                    <div class="mt-1 mb-0">
                        <div class="d-flex align-items-center justify-content-center mb-4">
                            <div class="d-flex align-items-center justify-content-center bg-warning bg-opacity-10 text-warning rounded-circle me-3 flex-shrink-0" style="width: 50px; height: 50px;">
                                <i class="fa-solid fa-shield-halved fs-4"></i>
                            </div>
                            <h4 class="fw-bold text-dark mb-0 text-start" style="font-size: 1.3rem;">Super Admin Override</h4>
                        </div>
                        
                        <div class="rounded-3 mb-2 d-flex align-items-center px-3 py-2 mx-auto text-start" style="background-color: #fff3cd; border: 1px solid #ffeeba;">
                            <i class="fa-solid fa-circle-exclamation me-2 align-self-start mt-1" style="color: #856404; font-size: 1rem;"></i>
                            <span style="color: #856404; font-size: 0.85rem; font-weight: 500; line-height: 1.4;">This action modifies sensitive stock data. Please ask a <strong>Super Admin</strong> for the 4-digit PIN.</span>
                        </div>
                    </div>
                `,
                input: 'password',
                inputPlaceholder: '••••',
                inputAttributes: { 
                    required: 'true',
                    maxlength: '4',
                    autocapitalize: 'off',
                    autocorrect: 'off',
                    style: 'text-align: center; font-size: 2rem; letter-spacing: 0.25rem; text-indent: 0.25rem; width: 140px; height: 50px; margin: 15px auto 10px auto; border-radius: 8px; border: 2px solid #ffc107; box-shadow: none; font-weight: bold; color: #333; outline: none; padding: 0;'
                },
                showCancelButton: true,
                confirmButtonText: 'Authorize',
                cancelButtonText: 'Cancel',
                buttonsStyling: false,
                customClass: {
                    popup: 'rounded-4 shadow-lg border-0 border-top border-warning border-4 p-4',
                    actions: 'gap-3 mt-2 mb-0',
                    confirmButton: 'btn btn-warning fw-bold px-4 rounded-pill text-dark m-0',
                    cancelButton: 'btn btn-light fw-bold px-4 rounded-pill border m-0'
                }
            }).then((result) => {
                if (result.isConfirmed && result.value) {
                    let form = document.getElementById('adjustmentForm');
                    let input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'verification_password';
                    input.value = result.value;
                    form.appendChild(input);
                    form.submit();
                }
            });
        <?php else: ?>
            document.getElementById('adjustmentForm').submit();
        <?php endif; ?>
    }

    // Listen for input changes
    document.getElementById('input_qty')?.addEventListener('input', updateUI);

    // Initial call
    if (document.getElementById('input_qty')) updateUI();

    // =====================================================
    // PROGRESSIVE SEARCH MODULE FOR ADJUSTMENT
    // =====================================================
    const AdjustmentSearch = {
        searchTimeout: null,

        init() {
            const searchInput = document.getElementById('adjustment-search');
            if (!searchInput) return;

            searchInput.addEventListener('input', (e) => {
                clearTimeout(this.searchTimeout);
                const query = e.target.value.trim();
                if (query.length < 1) {
                    this.hideResults();
                    return;
                }
                this.searchTimeout = setTimeout(() => this.searchProducts(query), 300);
            });

            // Handle Enter key for immediate search
            searchInput.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    clearTimeout(this.searchTimeout);
                    const query = searchInput.value.trim();
                    if (query.length >= 1) {
                        this.searchProducts(query);
                    }
                }
            });

            // Close dropdown on outside click
            document.addEventListener('click', (e) => {
                if (!e.target.closest('#adjustment-search') && !e.target.closest('#adjustment-search-results')) {
                    this.hideResults();
                }
            });
        },

        async searchProducts(query) {
            const spinner = document.getElementById('adjustment-search-spinner');
            spinner?.classList.remove('d-none');

            try {
                const res = await fetch(`../../api/inventory/search_products.php?q=${encodeURIComponent(query)}`);
                const data = await res.json();
                // Handle both old format (array) and new format ({products: []})
                const products = data.products || data;
                this.renderSearchResults(products);
            } catch (err) {
                console.error('Search error:', err);
            } finally {
                spinner?.classList.add('d-none');
            }
        },

        renderSearchResults(products) {
            const container = document.getElementById('adjustment-search-results');
            const query = document.getElementById('adjustment-search').value.trim();
            const safeQuery = query ? query.replace(/[.*+?^${}()|[\\]\\\\]/g, '\\\\$&').split(/\\s+/).filter(Boolean) : [];
            const highlight = (text) => {
                if (!text) return '';
                let hlText = this.escapeHtml(text);
                if (safeQuery.length === 0) return hlText;
                safeQuery.forEach(q => {
                    const regex = new RegExp(`(${q})`, 'gi');
                    hlText = hlText.replace(regex, '<mark class="bg-warning bg-opacity-50 p-0 rounded text-dark">$1</mark>');
                });
                return hlText;
            };

            if (!products || products.length === 0) {
                container.innerHTML = '<div class="p-3 text-muted small">No products found</div>';
                container.style.display = 'block';
                return;
            }

            const baseUrl = '<?= BASE_URL ?>';
            container.innerHTML = products.slice(0, 15).map(p => {
                const imageHtml = p.image_path
                    ? `<img src="${baseUrl}${this.escapeHtml(p.image_path)}" class="adjust-search-icon" alt="">`
                    : `<span class="adjust-search-icon"><i class="fa-solid fa-box"></i></span>`;

                return `
                <a href="adjustment.php?id=${p.variation_id}" class="adjust-search-item">
                    ${imageHtml}
                    <div class="adjust-search-content">
                        <div class="adjust-search-name">${highlight(p.product_name)}</div>
                        <div class="adjust-search-meta">
                            <strong>${p.brand_name ? highlight(p.brand_name) : 'No Brand'}</strong> | ${highlight(p.variation_name || 'Default')}
                        </div>
                        <div>
                            ${p.sku ? `<span class="adjust-chip"><i class="fa-solid fa-tag"></i>${highlight(p.sku)}</span>` : ''}
                            ${p.barcode ? `<span class="adjust-chip"><i class="fa-solid fa-barcode"></i>${highlight(p.barcode)}</span>` : ''}
                        </div>
                    </div>
                    ${(() => {
                        const phys = Math.max(0, p.current_stock - (p.shopee_allocated || 0));
                        return `<span class="adjust-stock-badge" title="Total: ${p.current_stock} | Online: ${p.shopee_allocated || 0}">Stock: ${phys}</span>`;
                    })()}
                </a>`;
            }).join('');
            container.style.display = 'block';
        },

        hideResults() {
            const container = document.getElementById('adjustment-search-results');
            if (container) container.style.display = 'none';
        },

        escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text || '';
            return div.innerHTML;
        }
    };

    // Initialize search on page load
    document.addEventListener('DOMContentLoaded', () => {
        AdjustmentSearch.init();
    });
</script>

<?php require_once '../../includes/footer.php'; ?>

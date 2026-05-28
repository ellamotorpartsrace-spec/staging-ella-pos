<?php
require_once '../../config/config.php';
require_once '../../includes/auth.php';

requireLogin();
if ($_SESSION['role'] !== 'admin' && !hasPermission('adjust_prices') && !in_array($_SESSION['role'], ['manager', 'stockman'], true)) {
    denyAccess("You do not have permission to access bundle sets.");
}

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
require_once '../../config/database.php';

$db = new Database();
$conn = $db->getConnection();

$search = trim((string) ($_GET['search'] ?? ''));
$sets = [];
$tableMissing = false;

try {
    $params = [];
    $where = ["1=1"];

    if ($search !== '') {
        $words = preg_split('/\s+/', $search) ?: [];
        $words = array_values(array_filter(array_map('trim', $words), static fn($w) => $w !== ''));
        if (!empty($words)) {
            $conditions = [];
            foreach ($words as $idx => $word) {
                $key = ":word{$idx}";
                $params[$key] = '%' . $word . '%';
                $conditions[] = "(s.set_name LIKE {$key} OR s.set_sku LIKE {$key} OR s.description LIKE {$key})";
            }
            $where[] = '(' . implode(' AND ', $conditions) . ')';
        }
    }

    $sql = "
        SELECT
            s.id,
            s.set_name,
            s.set_sku,
            s.description,
            s.price_retail,
            s.status,
            COALESCE(sc.component_count, 0) AS component_count
        FROM product_unit_sets s
        LEFT JOIN (
            SELECT product_set_id, COUNT(*) AS component_count
            FROM product_unit_set_items
            GROUP BY product_set_id
        ) sc ON sc.product_set_id = s.id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY s.updated_at DESC, s.set_name ASC
        LIMIT 250
    ";

    $stmt = $conn->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, PDO::PARAM_STR);
    }
    $stmt->execute();
    $sets = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $tableMissing = stripos($e->getMessage(), 'product_unit_sets') !== false;
}
?>

<style>
    .set-card {
        border: 1px solid var(--bs-border-color);
        border-radius: 0.75rem;
    }

    .set-candidate-item {
        border: 1px solid var(--bs-border-color);
        border-radius: 0.65rem;
        padding: 0.6rem 0.7rem;
        cursor: pointer;
        transition: background 0.15s ease;
    }

    .set-candidate-item:hover {
        background: rgba(var(--bs-primary-rgb), 0.05);
    }

    .set-candidate-thumb {
        width: 44px;
        height: 44px;
        border-radius: 0.5rem;
        border: 1px solid var(--bs-border-color);
        background: var(--bs-gray-100);
        object-fit: cover;
        flex: 0 0 auto;
    }

    .set-candidate-thumb-placeholder {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        color: var(--bs-secondary);
    }

    .set-component-pill {
        font-size: 0.68rem;
        border: 1px solid rgba(var(--bs-secondary-rgb), 0.25);
        border-radius: 999px;
        padding: 0.05rem 0.5rem;
        color: var(--bs-secondary);
    }

    .bundle-meta-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 0.75rem;
    }

    @media (max-width: 767.98px) {
        .bundle-meta-grid {
            grid-template-columns: 1fr;
        }
    }
</style>

<div class="container-fluid p-3 p-md-4">
    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3 mb-3">
        <div>
            <h4 class="mb-1 fw-bold">
                <i class="fa-solid fa-boxes-stacked text-primary me-2"></i>Bundle Set Builder
            </h4>
            <p class="text-muted small mb-0">Create a sellable bundle, then add two or more component products to deduct together.</p>
        </div>
        <div class="d-flex flex-wrap gap-2 w-100 w-lg-auto justify-content-lg-end">
            <form method="GET" action="unit_sets.php" class="d-flex flex-grow-1" style="max-width: 450px;">
                <div class="input-group">
                    <input type="text" class="form-control" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search bundle name or SKU...">
                    <button class="btn btn-primary" type="submit"><i class="fa-solid fa-search"></i></button>
                </div>
            </form>
            <button class="btn btn-primary" type="button" onclick="openNewBundleModal()">
                <i class="fa-solid fa-plus me-1"></i>Create Bundle Set
            </button>
            <a class="btn btn-outline-secondary" href="<?= BASE_URL ?>views/inventory/unit_types.php">
                <i class="fa-solid fa-arrow-left me-1"></i>Unit Types
            </a>
        </div>
    </div>

    <?php if ($tableMissing): ?>
        <div class="alert alert-warning border small mb-3">
            <i class="fa-solid fa-triangle-exclamation me-1"></i>
            Bundle tables are not installed yet. Run <strong>ella-pos/migrations/create_product_unit_set_items.sql</strong> in phpMyAdmin first.
        </div>
    <?php else: ?>
        <div class="alert alert-light border small mb-3">
            <i class="fa-solid fa-circle-info text-primary me-1"></i>
            Components can be base products or custom units. Use <strong>Search Per Unit</strong> only when the component itself should deduct as a box/case/unit.
        </div>

        <div class="card set-card shadow-sm">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-3">Bundle Set</th>
                                <th>SKU</th>
                                <th>Retail</th>
                                <th>Components</th>
                                <th>Status</th>
                                <th class="text-end pe-3">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($sets)): ?>
                                <tr>
                                    <td colspan="6" class="text-center py-4 text-muted">
                                        No bundle sets yet. Click <strong>Create Bundle Set</strong> to start.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($sets as $row): ?>
                                    <tr>
                                        <td class="ps-3">
                                            <div class="fw-semibold"><?= htmlspecialchars($row['set_name']) ?></div>
                                            <?php if (!empty($row['description'])): ?>
                                                <div class="small text-muted"><?= htmlspecialchars($row['description']) ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= htmlspecialchars($row['set_sku'] ?: '-') ?></td>
                                        <td class="fw-semibold">PHP <?= number_format((float) $row['price_retail'], 2) ?></td>
                                        <td>
                                            <?php if ((int) $row['component_count'] > 0): ?>
                                                <span class="badge bg-success-subtle text-success border"><?= (int) $row['component_count'] ?> item<?= (int) $row['component_count'] === 1 ? '' : 's' ?></span>
                                            <?php else: ?>
                                                <span class="badge bg-warning-subtle text-warning border">No recipe yet</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge <?= $row['status'] === 'active' ? 'bg-success-subtle text-success' : 'bg-secondary-subtle text-secondary' ?> border">
                                                <?= htmlspecialchars(ucfirst($row['status'])) ?>
                                            </span>
                                        </td>
                                        <td class="text-end pe-3">
                                            <button
                                                type="button"
                                                class="btn btn-sm btn-outline-primary"
                                                data-set-id="<?= (int) $row['id'] ?>"
                                                onclick="openExistingBundleModal(this)">
                                                <i class="fa-solid fa-diagram-project me-1"></i>Configure
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<div class="modal fade" id="setBuilderModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title fw-bold mb-0"><i class="fa-solid fa-diagram-project text-primary me-2"></i>Bundle Recipe Builder</h5>
                    <small class="text-muted" id="setModalSubtitle">Create the sellable bundle and add component products.</small>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="border rounded-3 p-3 mb-3">
                    <div class="fw-semibold mb-2">Bundle Details</div>
                    <div class="bundle-meta-grid">
                        <div>
                            <label class="form-label small fw-semibold text-secondary">Bundle Name</label>
                            <input type="text" class="form-control form-control-sm" id="setNameInput" placeholder="e.g., Oil Change Bundle">
                        </div>
                        <div>
                            <label class="form-label small fw-semibold text-secondary">Bundle SKU</label>
                            <input type="text" class="form-control form-control-sm" id="setSkuInput" placeholder="Optional">
                        </div>
                        <div>
                            <label class="form-label small fw-semibold text-secondary">Retail Price</label>
                            <input type="number" min="0" step="0.01" class="form-control form-control-sm" id="setRetailInput" value="0.00">
                        </div>
                        <div>
                            <label class="form-label small fw-semibold text-secondary">Status</label>
                            <select class="form-select form-select-sm" id="setStatusInput">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                        <div>
                            <label class="form-label small fw-semibold text-secondary">Wholesale Price</label>
                            <input type="number" min="0" step="0.01" class="form-control form-control-sm" id="setWholesaleInput" value="0.00">
                        </div>
                        <div>
                            <label class="form-label small fw-semibold text-secondary">Dealer Price</label>
                            <input type="number" min="0" step="0.01" class="form-control form-control-sm" id="setDealerInput" value="0.00">
                        </div>
                    </div>
                    <div class="mt-2">
                        <label class="form-label small fw-semibold text-secondary">Description</label>
                        <textarea class="form-control form-control-sm" id="setDescriptionInput" rows="2" placeholder="Optional"></textarea>
                    </div>
                </div>

                <div class="row g-3">
                    <div class="col-lg-5">
                        <div class="border rounded-3 p-3 h-100">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <div class="fw-semibold">Component Search</div>
                                <div class="form-check form-switch mb-0">
                                    <input class="form-check-input" type="checkbox" id="setSearchUnitToggle">
                                    <label class="form-check-label small fw-semibold" for="setSearchUnitToggle">Search Per Unit</label>
                                </div>
                            </div>
                            <div class="input-group input-group-sm mb-2">
                                <input type="text" class="form-control" id="setSearchInput" placeholder="Search product or SKU...">
                                <button class="btn btn-outline-secondary" type="button" onclick="loadSetCandidates()"><i class="fa-solid fa-search"></i></button>
                            </div>
                            <div class="small text-muted mb-2">Click an item to add it as a component.</div>
                            <div id="setCandidateList" class="d-flex flex-column gap-2" style="max-height: 380px; overflow:auto;"></div>
                        </div>
                    </div>
                    <div class="col-lg-7">
                        <div class="border rounded-3 p-3 h-100">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <div class="fw-semibold">Bundle Components</div>
                                <div class="small text-muted" id="setComponentCountLabel">0 item</div>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-sm align-middle mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Component</th>
                                            <th style="width: 145px;">Qty / Bundle</th>
                                            <th style="width: 90px;"></th>
                                        </tr>
                                    </thead>
                                    <tbody id="setComponentBody">
                                        <tr><td colspan="3" class="text-center text-muted py-3">No components yet.</td></tr>
                                    </tbody>
                                </table>
                            </div>
                            <div class="small text-muted mt-2">
                                Selling 1 bundle will deduct every component in this list.
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-primary" id="btnSaveSetRecipe" onclick="saveSetRecipe()">
                    <i class="fa-solid fa-check me-1"></i>Save Bundle
                </button>
            </div>
        </div>
    </div>
</div>

<div class="position-fixed top-0 end-0 p-3" style="z-index: 1090;">
    <div id="setToast" class="toast align-items-center text-white bg-success border-0" role="alert" aria-live="assertive" aria-atomic="true">
        <div class="d-flex">
            <div class="toast-body" id="setToastMessage">Saved.</div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
        </div>
    </div>
</div>

<script>
    let setModal;
    let currentProductSetId = 0;
    let setItems = [];
    let searchDebounceTimer = null;
    let latestCandidateRows = [];

    function escHtml(v) {
        return String(v ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function showSetToast(message, type = 'success') {
        const toastEl = document.getElementById('setToast');
        const msgEl = document.getElementById('setToastMessage');
        toastEl.classList.remove('bg-success', 'bg-danger', 'bg-primary');
        toastEl.classList.add(type === 'error' ? 'bg-danger' : 'bg-success');
        msgEl.textContent = message;
        new bootstrap.Toast(toastEl, { delay: 2500 }).show();
    }

    function ensureSetModal() {
        if (!setModal) {
            setModal = new bootstrap.Modal(document.getElementById('setBuilderModal'));
        }
    }

    function resetBundleForm() {
        currentProductSetId = 0;
        setItems = [];
        latestCandidateRows = [];
        document.getElementById('setNameInput').value = '';
        document.getElementById('setSkuInput').value = '';
        document.getElementById('setRetailInput').value = '0.00';
        document.getElementById('setWholesaleInput').value = '0.00';
        document.getElementById('setDealerInput').value = '0.00';
        document.getElementById('setStatusInput').value = 'active';
        document.getElementById('setDescriptionInput').value = '';
        document.getElementById('setSearchInput').value = '';
        document.getElementById('setSearchUnitToggle').checked = false;
        document.getElementById('setCandidateList').innerHTML = '<div class="text-muted small">Search for a product to add.</div>';
        renderSetItems();
    }

    function openNewBundleModal() {
        ensureSetModal();
        resetBundleForm();
        document.getElementById('setModalSubtitle').textContent = 'Create a bundle that can contain products with or without unit types.';
        setModal.show();
        loadSetCandidates();
    }

    function openExistingBundleModal(btn) {
        ensureSetModal();
        resetBundleForm();
        currentProductSetId = parseInt(btn.dataset.setId, 10) || 0;
        document.getElementById('setModalSubtitle').textContent = 'Edit bundle details and component recipe.';
        setModal.show();
        loadSetItems();
        loadSetCandidates();
    }

    function itemKey(item) {
        return `${item.component_variation_id}|${item.component_unit_id || 0}`;
    }

    async function loadSetItems() {
        if (!currentProductSetId) return;
        try {
            const res = await fetch(`../../api/units/set_items_list.php?product_set_id=${currentProductSetId}`);
            const data = await res.json();
            if (!data.success) {
                showSetToast(data.message || 'Failed to load bundle set.', 'error');
                return;
            }

            const bundle = data.data?.set || {};
            document.getElementById('setNameInput').value = bundle.set_name || '';
            document.getElementById('setSkuInput').value = bundle.set_sku || '';
            document.getElementById('setRetailInput').value = parseFloat(bundle.price_retail || 0).toFixed(2);
            document.getElementById('setWholesaleInput').value = parseFloat(bundle.price_wholesale || 0).toFixed(2);
            document.getElementById('setDealerInput').value = parseFloat(bundle.price_dealer || 0).toFixed(2);
            document.getElementById('setStatusInput').value = bundle.status || 'active';
            document.getElementById('setDescriptionInput').value = bundle.description || '';

            setItems = (data.data?.items || []).map(row => ({
                component_variation_id: parseInt(row.component_variation_id, 10),
                component_unit_id: row.component_unit_id !== null ? parseInt(row.component_unit_id, 10) : null,
                component_qty: parseFloat(row.component_qty || 0),
                product_name: row.product_name || '',
                brand_name: row.brand_name || '',
                variation_name: row.variation_name || '',
                sku: row.sku || '',
                base_unit_type: row.base_unit_type || 'pc',
                unit_name: row.component_unit_name || null,
                multiplier: parseInt(row.component_unit_multiplier || 1, 10)
            }));
            renderSetItems();
        } catch (err) {
            showSetToast('Failed to load bundle set.', 'error');
        }
    }

    async function loadSetCandidates() {
        const q = document.getElementById('setSearchInput').value.trim();
        const mode = document.getElementById('setSearchUnitToggle').checked ? 'unit' : 'base';
        const box = document.getElementById('setCandidateList');
        box.innerHTML = '<div class="text-muted small">Searching...</div>';

        try {
            const params = new URLSearchParams({ q, mode });
            const res = await fetch(`../../api/units/search_components.php?${params.toString()}`);
            const data = await res.json();
            if (!data.success) {
                box.innerHTML = '<div class="text-danger small">Failed to search components.</div>';
                return;
            }

            const rows = data.data || [];
            latestCandidateRows = rows;
            if (!rows.length) {
                box.innerHTML = '<div class="text-muted small">No match found.</div>';
                return;
            }

            box.innerHTML = rows.map((r, idx) => {
                const isUnit = r.item_type === 'unit';
                const unitBadge = isUnit
                    ? `<span class="set-component-pill">${escHtml(r.unit_name || 'Unit')} x${parseInt(r.multiplier || 1, 10)}</span>`
                    : `<span class="set-component-pill">Base (${escHtml(r.base_unit_type || 'pc')})</span>`;
                const imagePath = String(r.image_path || '').trim();
                const imgHtml = imagePath
                    ? `<img src="${escHtml(window.BASE_URL + imagePath)}" class="set-candidate-thumb" alt="">`
                    : `<span class="set-candidate-thumb set-candidate-thumb-placeholder"><i class="fa-solid fa-box"></i></span>`;
                const brandHtml = r.brand_name
                    ? `<span class="badge bg-light text-secondary border">${escHtml(r.brand_name)}</span>`
                    : '';
                const variationHtml = r.variation_name
                    ? `<span class="badge bg-primary-subtle text-primary border">${escHtml(r.variation_name)}</span>`
                    : `<span class="badge bg-light text-secondary border">No variation</span>`;
                return `
                    <div class="set-candidate-item" onclick="addSetComponentByIndex(${idx})">
                        <div class="d-flex align-items-start gap-2">
                            ${imgHtml}
                            <div class="flex-grow-1" style="min-width:0;">
                                <div class="fw-semibold text-truncate">${escHtml(r.product_name)}</div>
                                <div class="d-flex flex-wrap gap-1 mt-1">${brandHtml}${variationHtml}${unitBadge}</div>
                                <div class="small text-muted mt-1"><i class="fa-solid fa-barcode me-1"></i>${escHtml(r.sku || 'No SKU')}</div>
                            </div>
                        </div>
                    </div>
                `;
            }).join('');
        } catch (err) {
            box.innerHTML = '<div class="text-danger small">Search request failed.</div>';
        }
    }

    function addSetComponent(item) {
        const key = `${item.component_variation_id}|${item.component_unit_id || 0}`;
        const found = setItems.find(x => itemKey(x) === key);
        if (found) {
            found.component_qty = parseFloat((parseFloat(found.component_qty) + 1).toFixed(4));
        } else {
            setItems.push({ ...item, component_qty: 1 });
        }
        renderSetItems();
    }

    function addSetComponentByIndex(index) {
        const row = latestCandidateRows[index];
        if (!row) return;
        addSetComponent({
            component_variation_id: parseInt(row.variation_id, 10),
            component_unit_id: row.unit_id !== null ? parseInt(row.unit_id, 10) : null,
            product_name: row.product_name,
            brand_name: row.brand_name,
            variation_name: row.variation_name,
            sku: row.sku,
            base_unit_type: row.base_unit_type,
            unit_name: row.unit_name,
            multiplier: parseInt(row.multiplier || 1, 10)
        });
    }

    function updateSetQty(key, value) {
        const row = setItems.find(x => itemKey(x) === key);
        if (!row) return;
        const n = parseFloat(value);
        row.component_qty = Number.isFinite(n) && n > 0 ? parseFloat(n.toFixed(4)) : 1;
    }

    function removeSetItem(key) {
        setItems = setItems.filter(x => itemKey(x) !== key);
        renderSetItems();
    }

    function renderSetItems() {
        const body = document.getElementById('setComponentBody');
        const countLabel = document.getElementById('setComponentCountLabel');
        countLabel.textContent = `${setItems.length} item${setItems.length === 1 ? '' : 's'}`;

        if (!setItems.length) {
            body.innerHTML = '<tr><td colspan="3" class="text-center text-muted py-3">No components yet.</td></tr>';
            return;
        }

        body.innerHTML = setItems.map(row => {
            const key = itemKey(row);
            const brandPrefix = row.brand_name ? `${escHtml(row.brand_name)} - ` : '';
            const label = row.unit_name
                ? `${brandPrefix}${escHtml(row.product_name)} (${escHtml(row.variation_name || '')}) - ${escHtml(row.unit_name)} x${parseInt(row.multiplier || 1, 10)}`
                : `${brandPrefix}${escHtml(row.product_name)} (${escHtml(row.variation_name || '')}) - Base ${escHtml(row.base_unit_type || 'pc')}`;
            return `
                <tr>
                    <td>
                        <div class="fw-semibold">${label}</div>
                        <div class="small text-muted">${escHtml(row.sku || '')}</div>
                    </td>
                    <td>
                        <input
                            type="number"
                            min="0.0001"
                            step="0.0001"
                            class="form-control form-control-sm"
                            value="${parseFloat(row.component_qty || 1)}"
                            oninput="updateSetQty('${key}', this.value)">
                    </td>
                    <td class="text-end">
                        <button class="btn btn-sm btn-outline-danger" type="button" onclick="removeSetItem('${key}')">
                            <i class="fa-solid fa-trash"></i>
                        </button>
                    </td>
                </tr>
            `;
        }).join('');
    }

    async function saveSetRecipe() {
        const name = document.getElementById('setNameInput').value.trim();
        if (!name) {
            showSetToast('Bundle name is required.', 'error');
            return;
        }
        if (setItems.length < 2) {
            showSetToast('A bundle set should have at least two component products.', 'error');
            return;
        }

        const btn = document.getElementById('btnSaveSetRecipe');
        const oldHtml = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Saving...';

        try {
            const payload = {
                product_set_id: currentProductSetId,
                set_name: name,
                set_sku: document.getElementById('setSkuInput').value.trim(),
                description: document.getElementById('setDescriptionInput').value.trim(),
                price_retail: parseFloat(document.getElementById('setRetailInput').value || 0),
                price_wholesale: parseFloat(document.getElementById('setWholesaleInput').value || 0),
                price_dealer: parseFloat(document.getElementById('setDealerInput').value || 0),
                status: document.getElementById('setStatusInput').value,
                items: setItems.map(row => ({
                    component_variation_id: parseInt(row.component_variation_id, 10),
                    component_unit_id: row.component_unit_id !== null ? parseInt(row.component_unit_id, 10) : null,
                    component_qty: parseFloat(row.component_qty || 0)
                }))
            };

            const res = await fetch('../../api/units/set_items_save.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const data = await res.json();

            if (!data.success) {
                showSetToast(data.message || 'Save failed.', 'error');
                return;
            }

            showSetToast('Bundle set saved.');
            setTimeout(() => window.location.reload(), 700);
        } catch (err) {
            showSetToast('Save request failed.', 'error');
        } finally {
            btn.disabled = false;
            btn.innerHTML = oldHtml;
        }
    }

    document.addEventListener('DOMContentLoaded', () => {
        const searchInput = document.getElementById('setSearchInput');
        const searchToggle = document.getElementById('setSearchUnitToggle');

        if (searchInput) {
            searchInput.addEventListener('input', () => {
                clearTimeout(searchDebounceTimer);
                searchDebounceTimer = setTimeout(loadSetCandidates, 220);
            });
        }

        if (searchToggle) {
            searchToggle.addEventListener('change', loadSetCandidates);
        }
    });
</script>

<?php require_once '../../includes/footer.php'; ?>

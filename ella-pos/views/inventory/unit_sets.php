<?php
require_once '../../config/config.php';
require_once '../../includes/auth.php';

requireLogin();
if ($_SESSION['role'] !== 'admin' && !hasPermission('adjust_prices') && !in_array($_SESSION['role'], ['manager', 'stockman'], true)) {
    denyAccess("You do not have permission to access set units.");
}

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
require_once '../../config/database.php';

$db = new Database();
$conn = $db->getConnection();

$search = trim((string) ($_GET['search'] ?? ''));
$params = [];
$where = ["v.status = 'active'"];

if ($search !== '') {
    $words = preg_split('/\s+/', $search) ?: [];
    $words = array_values(array_filter(array_map('trim', $words), static fn($w) => $w !== ''));
    if (!empty($words)) {
        $conditions = [];
        foreach ($words as $idx => $word) {
            $key = ":word{$idx}";
            $params[$key] = '%' . $word . '%';
            $conditions[] = "(
                p.product_name LIKE {$key}
                OR p.brand_name LIKE {$key}
                OR v.variation_name LIKE {$key}
                OR v.sku LIKE {$key}
                OR u.unit_name LIKE {$key}
            )";
        }
        $where[] = '(' . implode(' AND ', $conditions) . ')';
    }
}

$sql = "
    SELECT
        u.id AS unit_id,
        u.variation_id,
        u.unit_name,
        u.multiplier,
        v.sku,
        v.variation_name,
        v.unit_type AS base_unit_type,
        p.product_name,
        p.brand_name,
        p.image_path,
        COALESCE(sc.component_count, 0) AS component_count
    FROM product_units u
    INNER JOIN product_variations v ON v.variation_id = u.variation_id
    INNER JOIN products p ON p.product_id = v.product_id
    LEFT JOIN (
        SELECT product_unit_id, COUNT(*) AS component_count
        FROM product_unit_set_items
        GROUP BY product_unit_id
    ) sc ON sc.product_unit_id = u.id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY p.product_name ASC, v.variation_name ASC, u.unit_name ASC
    LIMIT 250
";

$stmt = $conn->prepare($sql);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value, PDO::PARAM_STR);
}
$stmt->execute();
$units = $stmt->fetchAll(PDO::FETCH_ASSOC);
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

    .set-component-pill {
        font-size: 0.68rem;
        border: 1px solid rgba(var(--bs-secondary-rgb), 0.25);
        border-radius: 999px;
        padding: 0.05rem 0.5rem;
        color: var(--bs-secondary);
    }
</style>

<div class="container-fluid p-3 p-md-4">
    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3 mb-3">
        <div>
            <h4 class="mb-1 fw-bold">
                <i class="fa-solid fa-boxes-stacked text-primary me-2"></i>Set Unit Builder
            </h4>
            <p class="text-muted small mb-0">Build bundled/set recipes for custom units. One sold set deducts all listed components.</p>
        </div>
        <div class="d-flex gap-2 w-100 w-lg-auto">
            <form method="GET" action="unit_sets.php" class="d-flex flex-grow-1" style="max-width: 450px;">
                <div class="input-group">
                    <input type="text" class="form-control" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search product, sku, or unit...">
                    <button class="btn btn-primary" type="submit"><i class="fa-solid fa-search"></i></button>
                </div>
            </form>
            <a class="btn btn-outline-secondary" href="<?= BASE_URL ?>views/inventory/unit_types.php">
                <i class="fa-solid fa-arrow-left me-1"></i>Unit Types
            </a>
        </div>
    </div>

    <div class="alert alert-light border small mb-3">
        <i class="fa-solid fa-circle-info text-primary me-1"></i>
        Use <strong>Search Per Unit</strong> when a component should deduct by a custom unit (box/case/set). Keep it off for base-piece deduction.
    </div>

    <div class="card set-card shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-3">Product / Variation</th>
                            <th>Set Unit</th>
                            <th>Base Multiplier</th>
                            <th>Components</th>
                            <th class="text-end pe-3">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($units)): ?>
                            <tr>
                                <td colspan="5" class="text-center py-4 text-muted">No custom units found.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($units as $row): ?>
                                <tr>
                                    <td class="ps-3">
                                        <div class="fw-semibold"><?= htmlspecialchars($row['product_name']) ?></div>
                                        <div class="small text-muted">
                                            <?= htmlspecialchars($row['variation_name']) ?> - <?= htmlspecialchars($row['sku']) ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="fw-semibold"><?= htmlspecialchars($row['unit_name']) ?></span>
                                    </td>
                                    <td>
                                        <span class="badge bg-secondary-subtle text-secondary border">
                                            1 <?= htmlspecialchars($row['unit_name']) ?> = <?= (int) $row['multiplier'] ?> <?= htmlspecialchars($row['base_unit_type'] ?: 'pcs') ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ((int) $row['component_count'] > 0): ?>
                                            <span class="badge bg-success-subtle text-success border"><?= (int) $row['component_count'] ?> item<?= (int) $row['component_count'] === 1 ? '' : 's' ?></span>
                                        <?php else: ?>
                                            <span class="badge bg-warning-subtle text-warning border">No recipe yet</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end pe-3">
                                        <button
                                            type="button"
                                            class="btn btn-sm btn-outline-primary"
                                            data-unit-id="<?= (int) $row['unit_id'] ?>"
                                            data-unit-name="<?= htmlspecialchars($row['unit_name']) ?>"
                                            data-product-name="<?= htmlspecialchars($row['product_name']) ?>"
                                            data-variation-name="<?= htmlspecialchars($row['variation_name']) ?>"
                                            data-multiplier="<?= (int) $row['multiplier'] ?>"
                                            onclick="openSetModal(this)">
                                            <i class="fa-solid fa-diagram-project me-1"></i>Configure Set
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
</div>

<div class="modal fade" id="setBuilderModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title fw-bold mb-0"><i class="fa-solid fa-diagram-project text-primary me-2"></i>Set Recipe Builder</h5>
                    <small class="text-muted" id="setModalSubtitle"></small>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
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
                            <div class="small text-muted mb-2">Select an item below to add it to this set recipe.</div>
                            <div id="setCandidateList" class="d-flex flex-column gap-2" style="max-height: 380px; overflow:auto;"></div>
                        </div>
                    </div>
                    <div class="col-lg-7">
                        <div class="border rounded-3 p-3 h-100">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <div class="fw-semibold">Set Components</div>
                                <div class="small text-muted" id="setComponentCountLabel">0 item</div>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-sm align-middle mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Component</th>
                                            <th style="width: 145px;">Qty / Set</th>
                                            <th style="width: 90px;"></th>
                                        </tr>
                                    </thead>
                                    <tbody id="setComponentBody">
                                        <tr><td colspan="3" class="text-center text-muted py-3">No components yet.</td></tr>
                                    </tbody>
                                </table>
                            </div>
                            <div class="small text-muted mt-2">
                                Qty means how many of that component is deducted when <strong>1 set unit</strong> is sold.
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-primary" id="btnSaveSetRecipe" onclick="saveSetRecipe()">
                    <i class="fa-solid fa-check me-1"></i>Save Recipe
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
    let currentSetUnitId = null;
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

    function itemKey(item) {
        return `${item.component_variation_id}|${item.component_unit_id || 0}`;
    }

    function openSetModal(btn) {
        if (!setModal) {
            setModal = new bootstrap.Modal(document.getElementById('setBuilderModal'));
        }

        currentSetUnitId = parseInt(btn.dataset.unitId, 10);
        setItems = [];

        document.getElementById('setModalSubtitle').textContent =
            `${btn.dataset.productName} - ${btn.dataset.variationName} - ${btn.dataset.unitName} (x${btn.dataset.multiplier})`;
        document.getElementById('setSearchInput').value = '';
        document.getElementById('setSearchUnitToggle').checked = false;
        renderSetItems();
        document.getElementById('setCandidateList').innerHTML = '<div class="text-muted small">Loading...</div>';

        setModal.show();
        loadSetItems();
        loadSetCandidates();
    }

    async function loadSetItems() {
        if (!currentSetUnitId) return;
        try {
            const res = await fetch(`../../api/units/set_items_list.php?product_unit_id=${currentSetUnitId}`);
            const data = await res.json();
            if (!data.success) {
                showSetToast(data.message || 'Failed to load set recipe.', 'error');
                return;
            }
            setItems = (data.data?.items || []).map(row => ({
                component_variation_id: parseInt(row.component_variation_id, 10),
                component_unit_id: row.component_unit_id !== null ? parseInt(row.component_unit_id, 10) : null,
                component_qty: parseFloat(row.component_qty || 0),
                product_name: row.product_name || '',
                variation_name: row.variation_name || '',
                sku: row.sku || '',
                base_unit_type: row.base_unit_type || 'pc',
                unit_name: row.component_unit_name || null,
                multiplier: parseInt(row.component_unit_multiplier || 1, 10)
            }));
            renderSetItems();
        } catch (err) {
            showSetToast('Failed to load set recipe.', 'error');
        }
    }

    async function loadSetCandidates() {
        if (!currentSetUnitId) return;
        const q = document.getElementById('setSearchInput').value.trim();
        const mode = document.getElementById('setSearchUnitToggle').checked ? 'unit' : 'base';
        const box = document.getElementById('setCandidateList');
        box.innerHTML = '<div class="text-muted small">Searching...</div>';

        try {
            const params = new URLSearchParams({
                q,
                mode,
                exclude_unit_id: String(currentSetUnitId)
            });
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
                return `
                    <div class="set-candidate-item" onclick="addSetComponentByIndex(${idx})">
                        <div class="fw-semibold">${escHtml(r.product_name)} ${r.variation_name ? `(${escHtml(r.variation_name)})` : ''}</div>
                        <div class="small text-muted">${escHtml(r.sku || '')}</div>
                        <div class="mt-1">${unitBadge}</div>
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
            setItems.push({
                ...item,
                component_qty: 1
            });
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
        renderSetItems();
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
            const label = row.unit_name
                ? `${escHtml(row.product_name)} (${escHtml(row.variation_name || '')}) - ${escHtml(row.unit_name)} x${parseInt(row.multiplier || 1, 10)}`
                : `${escHtml(row.product_name)} (${escHtml(row.variation_name || '')}) - Base ${escHtml(row.base_unit_type || 'pc')}`;
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
        if (!currentSetUnitId) return;

        const btn = document.getElementById('btnSaveSetRecipe');
        const oldHtml = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Saving...';

        try {
            const payload = {
                product_unit_id: currentSetUnitId,
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

            showSetToast('Set recipe saved.');
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

        searchInput.addEventListener('input', () => {
            clearTimeout(searchDebounceTimer);
            searchDebounceTimer = setTimeout(loadSetCandidates, 220);
        });

        searchToggle.addEventListener('change', loadSetCandidates);
    });
</script>

<?php require_once '../../includes/footer.php'; ?>

<?php
// views/inventory/stockin_records.php - Stock-In Records by Supplier
require_once '../../config/config.php';
require_once '../../includes/auth.php';

// Auth Check
requireLogin();
if (!in_array($_SESSION['role'], ['admin', 'super_admin']) && !hasPermission('adjust_prices') && !in_array($_SESSION['role'], ['manager', 'stockman'])) {
    denyAccess("You do not have permission to view stock-in records.");
}

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
require_once '../../config/database.php';

$db = new Database();
$conn = $db->getConnection();

// Get suppliers for dropdown
$suppliers = $conn->query("SELECT supplier_id, supplier_name FROM suppliers ORDER BY supplier_name")->fetchAll();

$selected_supplier = $_GET['supplier_id'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$isAdmin = (in_array($_SESSION['role'], ['admin', 'super_admin']));
?>

<style>
    .supplier-card {
        transition: all 0.3s ease;
        cursor: pointer;
        border: 2px solid transparent;
    }

    .supplier-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(0, 0, 0, 0.12);
    }

    .supplier-card.active {
        border-color: var(--bs-primary);
        background: linear-gradient(135deg, #f0f4ff 0%, #e8f0fe 100%);
    }

    .record-row {
        transition: all 0.2s ease;
    }

    .record-row:hover {
        background: #f8f9fa;
    }

    .stats-card {
        border-radius: 12px;
        transition: transform 0.2s;
    }

    .stats-card:hover {
        transform: scale(1.02);
    }

    .reference-link {
        text-decoration: none;
        font-family: monospace;
        font-size: 0.85rem;
    }

    .reference-link:hover {
        text-decoration: underline;
    }

    .date-group-header {
        background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
        font-weight: 600;
        font-size: 0.85rem;
    }

    .loading-overlay {
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(255, 255, 255, 0.8);
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 10;
        border-radius: inherit;
    }

    .empty-state {
        padding: 3rem 1rem;
    }

    .empty-state i {
        font-size: 3rem;
        opacity: 0.2;
        margin-bottom: 1rem;
    }

    /* Mobile card view */
    @media (max-width: 991.98px) {
        .mobile-cards {
            display: block !important;
        }

        .desktop-table {
            display: none !important;
        }
    }

    @media (min-width: 992px) {
        .mobile-cards {
            display: none !important;
        }

        .desktop-table {
            display: block !important;
        }
    }

    .mobile-record-card {
        border-left: 4px solid var(--bs-success);
        transition: all 0.2s ease;
    }

    .mobile-record-card:hover {
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
    }
</style>

<div class="container-fluid p-3 p-lg-4">

    <!-- Page Header -->
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-4">
        <div class="mb-3 mb-md-0">
            <h4 class="fw-bold text-dark mb-1">
                <i class="fa-solid fa-file-invoice text-primary me-2"></i>Stock-In Records
            </h4>
            <p class="text-muted mb-0 small">View stock-in receipts and records by supplier</p>
        </div>
        <div class="d-flex gap-2">
            <a href="movements.php" class="btn btn-outline-secondary btn-sm">
                <i class="fa-solid fa-arrow-right-arrow-left me-1"></i>Stock Movements
            </a>
            <a href="restock.php" class="btn btn-success btn-sm">
                <i class="fa-solid fa-plus me-1"></i>New Stock In
            </a>
        </div>
    </div>

    <!-- Filters Card -->
    <div class="card shadow-sm border-0 mb-4">
        <div class="card-body p-3">
            <form id="filter-form" class="row g-2 align-items-end">
                <div class="col-12 col-md-4">
                    <label class="form-label small fw-bold text-muted mb-1">SUPPLIER</label>
                    <select name="supplier_id" id="supplier-select" class="form-select" required>
                        <option value="">-- Select Supplier --</option>
                        <option value="none" <?= $selected_supplier === 'none' ? 'selected' : '' ?>>-- No Supplier / Unknown --</option>
                        <?php foreach ($suppliers as $s): ?>
                            <option value="<?= $s['supplier_id'] ?>" <?= $selected_supplier == $s['supplier_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($s['supplier_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label small fw-bold text-muted mb-1">FROM</label>
                    <input type="date" name="date_from" id="date-from" class="form-control" value="<?= $date_from ?>">
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label small fw-bold text-muted mb-1">TO</label>
                    <input type="date" name="date_to" id="date-to" class="form-control" value="<?= $date_to ?>">
                </div>
                <div class="col-12 col-md-4 d-flex gap-2">
                    <button type="submit" class="btn btn-primary flex-grow-1">
                        <i class="fa-solid fa-filter me-1"></i>Load
                    </button>
                    <button type="button" class="btn btn-success" onclick="exportCSV()" title="Export CSV"
                        id="export-btn" disabled>
                        <i class="fa-solid fa-file-csv"></i>
                    </button>
                    <button type="button" class="btn btn-outline-secondary" onclick="resetFilters()" title="Reset">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Stats Cards (hidden until data loads) -->
    <div class="row g-3 mb-4 d-none" id="stats-row">
        <div class="col-6 col-lg-3">
            <div class="card stats-card border-0 shadow-sm h-100">
                <div class="card-body p-3">
                    <div class="d-flex align-items-center">
                        <div class="rounded-circle bg-success bg-opacity-10 p-2 me-3">
                            <i class="fa-solid fa-arrow-down text-success fa-lg"></i>
                        </div>
                        <div>
                            <div class="h4 fw-bold mb-0 text-success" id="stat-records">0</div>
                            <small class="text-muted">Total Records</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card stats-card border-0 shadow-sm h-100">
                <div class="card-body p-3">
                    <div class="d-flex align-items-center">
                        <div class="rounded-circle bg-primary bg-opacity-10 p-2 me-3">
                            <i class="fa-solid fa-cubes text-primary fa-lg"></i>
                        </div>
                        <div>
                            <div class="h4 fw-bold mb-0 text-primary" id="stat-quantity">0</div>
                            <small class="text-muted">Total Qty</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card stats-card border-0 shadow-sm h-100">
                <div class="card-body p-3">
                    <div class="d-flex align-items-center">
                        <div class="rounded-circle bg-warning bg-opacity-10 p-2 me-3">
                            <i class="fa-solid fa-peso-sign text-warning fa-lg"></i>
                        </div>
                        <div>
                            <div class="h5 fw-bold mb-0 text-warning" id="stat-cost">₱0</div>
                            <small class="text-muted">Total Cost</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card stats-card border-0 shadow-sm h-100">
                <div class="card-body p-3">
                    <div class="d-flex align-items-center">
                        <div class="rounded-circle bg-info bg-opacity-10 p-2 me-3">
                            <i class="fa-solid fa-file-lines text-info fa-lg"></i>
                        </div>
                        <div>
                            <div class="h4 fw-bold mb-0 text-info" id="stat-references">0</div>
                            <small class="text-muted">References</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Records Card -->
    <div class="card shadow-sm border-0 position-relative" id="records-card">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
            <h6 class="mb-0 fw-bold">
                <i class="fa-solid fa-list text-primary me-2"></i>
                <span id="records-title">Stock-In Records</span>
            </h6>
            <span class="badge bg-secondary" id="records-count">Select a supplier</span>
        </div>

        <!-- Loading Overlay -->
        <div class="loading-overlay d-none" id="loading-overlay">
            <div class="text-center">
                <div class="spinner-border text-primary mb-2" role="status"></div>
                <div class="text-muted small">Loading records...</div>
            </div>
        </div>

        <!-- Desktop Table View -->
        <div class="card-body p-0 desktop-table" style="display: none;">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="bg-light">
                        <tr>
                            <th class="ps-4">Date & Time</th>
                            <th>Product</th>
                            <th class="text-end">Capital</th>
                            <th class="text-center">Qty Added</th>
                            <th class="text-end">Total</th>
                            <th class="text-center">Stock Level</th>
                            <th>Reference</th>
                            <th>By</th>
                        </tr>
                    </thead>
                    <tbody id="records-tbody">
                        <tr>
                            <td colspan="8" class="text-center py-5">
                                <div class="empty-state">
                                    <i class="fa-solid fa-truck-ramp-box d-block"></i>
                                    <h6 class="text-muted">Select a Supplier</h6>
                                    <p class="small text-muted mb-0">Choose a supplier from the dropdown above to view
                                        their stock-in records</p>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Mobile Card View -->
        <div class="card-body p-3 mobile-cards" style="display: none;">
            <div id="records-mobile">
                <div class="empty-state text-center">
                    <i class="fa-solid fa-truck-ramp-box d-block"></i>
                    <h6 class="text-muted">Select a Supplier</h6>
                    <p class="small text-muted mb-0">Choose a supplier from the dropdown above to view their stock-in
                        records</p>
                </div>
            </div>
        </div>

        <!-- Pagination -->
        <div class="card-footer bg-white py-3 d-none" id="pagination-footer">
            <div class="d-flex justify-content-between align-items-center">
                <small class="text-muted" id="pagination-info">Showing 0 records</small>
                <div class="d-flex gap-2" id="pagination-btns"></div>
            </div>
        </div>
    </div>

</div>

<script>
    const BASE_URL = '<?= BASE_URL ?>';
    const isAdmin = <?= $isAdmin ? 'true' : 'false' ?>;
    const LAST_SUPPLIER_KEY = 'stockin_records_last_supplier_id';
    let currentPage = 1;

    document.addEventListener('DOMContentLoaded', function () {
        updateView();
        window.addEventListener('resize', updateView);

        const supplierSelect = document.getElementById('supplier-select');
        restoreLastSupplierSelection(supplierSelect);

        // Auto-load if supplier is pre-selected or restored
        const supplierId = supplierSelect.value;
        if (supplierId) {
            loadRecords();
        }

        // Form submit
        document.getElementById('filter-form').addEventListener('submit', function (e) {
            e.preventDefault();
            currentPage = 1;
            loadRecords();
        });

        // Auto-load on supplier change
        supplierSelect.addEventListener('change', function () {
            if (this.value) {
                currentPage = 1;
                loadRecords();
            } else {
                localStorage.removeItem(LAST_SUPPLIER_KEY);
            }
        });
    });

    function restoreLastSupplierSelection(selectEl) {
        if (!selectEl || selectEl.value) return;

        const savedSupplierId = localStorage.getItem(LAST_SUPPLIER_KEY);
        if (!savedSupplierId) return;

        const hasSavedOption = Array.from(selectEl.options).some(option => option.value === savedSupplierId);
        if (hasSavedOption) {
            selectEl.value = savedSupplierId;
        } else {
            localStorage.removeItem(LAST_SUPPLIER_KEY);
        }
    }

    function updateView() {
        const isMobile = window.innerWidth < 992;
        document.querySelectorAll('.mobile-cards').forEach(el => {
            el.style.display = isMobile ? 'block' : 'none';
        });
        document.querySelectorAll('.desktop-table').forEach(el => {
            el.style.display = isMobile ? 'none' : 'block';
        });
    }

    async function loadRecords(page = 1) {
        const supplierId = document.getElementById('supplier-select').value;
        if (!supplierId) {
            EllaToast.warning('Please select a supplier');
            return;
        }

        currentPage = page;
        const dateFrom = document.getElementById('date-from').value;
        const dateTo = document.getElementById('date-to').value;

        // Show loading
        document.getElementById('loading-overlay').classList.remove('d-none');
        document.getElementById('export-btn').disabled = true;

        try {
            const params = new URLSearchParams({
                supplier_id: supplierId,
                date_from: dateFrom,
                date_to: dateTo,
                page: page
            });

            const res = await fetch(`../../api/inventory/get_stockin_records.php?${params}`);
            const data = await res.json();

            if (data.success) {
                localStorage.setItem(LAST_SUPPLIER_KEY, supplierId);
                renderStats(data.stats);
                renderDesktopTable(data.records);
                renderMobileCards(data.records);
                renderPagination(data.pagination);

                document.getElementById('records-title').textContent =
                    `Stock-In Records — ${escapeHtml(data.supplier_name)}`;
                document.getElementById('records-count').textContent =
                    `${data.pagination.total} records`;

                document.getElementById('export-btn').disabled = data.records.length === 0;

                // Update URL without reload
                const url = new URL(window.location);
                url.searchParams.set('supplier_id', supplierId);
                if (dateFrom) url.searchParams.set('date_from', dateFrom);
                else url.searchParams.delete('date_from');
                if (dateTo) url.searchParams.set('date_to', dateTo);
                else url.searchParams.delete('date_to');
                window.history.replaceState({}, '', url);
            } else {
                EllaToast.error('Error: ' + (data.error || 'Unknown error'));
            }
        } catch (err) {
            console.error('Load error:', err);
            EllaToast.success('Failed to load records');
        } finally {
            document.getElementById('loading-overlay').classList.add('d-none');
        }
    }

    function renderStats(stats) {
        document.getElementById('stats-row').classList.remove('d-none');
        document.getElementById('stat-records').textContent = parseInt(stats.total_records).toLocaleString();
        document.getElementById('stat-quantity').textContent = parseInt(stats.total_quantity).toLocaleString();
        document.getElementById('stat-cost').textContent = '₱' + parseFloat(stats.total_cost).toLocaleString(undefined, {
            minimumFractionDigits: 2, maximumFractionDigits: 2
        });
        document.getElementById('stat-references').textContent = parseInt(stats.unique_references).toLocaleString();
    }

    function renderDesktopTable(records) {
        const tbody = document.getElementById('records-tbody');

        if (records.length === 0) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="8" class="text-center py-5">
                        <div class="empty-state">
                            <i class="fa-solid fa-inbox d-block"></i>
                            <h6 class="text-muted">No Stock-In Records Found</h6>
                            <p class="small text-muted mb-0">No records found for this supplier with the selected filters</p>
                        </div>
                    </td>
                </tr>`;
            return;
        }

        let lastDate = '';
        let html = '';

        records.forEach(row => {
            const dateStr = new Date(row.created_at).toLocaleDateString('en-US', {
                year: 'numeric', month: 'short', day: 'numeric'
            });
            const timeStr = new Date(row.created_at).toLocaleTimeString('en-US', {
                hour: 'numeric', minute: '2-digit', hour12: true
            });

            // Date group separator
            if (dateStr !== lastDate) {
                html += `
                    <tr>
                        <td colspan="8" class="date-group-header ps-4 py-2">
                            <i class="fa-solid fa-calendar-day me-1 text-primary"></i>${dateStr}
                        </td>
                    </tr>`;
                lastDate = dateStr;
            }

            const refHtml = row.reference
                ? `<div class="d-flex flex-column align-items-start">
                        <a href="reference.php?ref=${encodeURIComponent(row.reference)}&from=stockin_records" class="reference-link text-primary fw-bold">
                            ${escapeHtml(row.reference)}
                        </a>
                        ${parseInt(row.has_attachment) > 0
                    ? `<a href="javascript:void(0)" 
                                  onclick="viewAttachment('${row.reference_image}', '${escapeHtml(row.reference)}')" 
                                  class="text-primary text-decoration-none mt-1 d-block small">
                                    <i class="fa-solid fa-paperclip me-1"></i>View Receipt
                                    ${parseInt(row.has_attachment) > 1 ? `<span class="badge bg-secondary rounded-pill small ms-1" style="font-size: 0.7em;">+${parseInt(row.has_attachment) - 1}</span>` : ''}
                               </a>`
                    : `<button class="btn btn-sm btn-link p-0 text-decoration-none mt-1" onclick="openRetroUpload(${row.movement_id}, '${escapeHtml(row.reference)}')">
                                <i class="fa-solid fa-cloud-arrow-up"></i> Add Photo
                               </button>`
                }
                   </div>`
                : `<div class="d-flex align-items-center">
                        <span class="text-muted">—</span>
                        <button class="btn btn-sm btn-link p-0 text-decoration-none ms-2" onclick="openRetroUpload(${row.movement_id}, 'No Reference')">
                            <i class="fa-solid fa-cloud-arrow-up"></i> Add Photo
                        </button>
                   </div>`;

            const isVoided = row.status === 'voided';
            const rowClass = isVoided ? 'record-row opacity-50 bg-light' : 'record-row';
            const voidBadge = isVoided ? '<span class="badge bg-danger small ms-2">VOIDED</span>' : '';

            html += `
                <tr class="${rowClass}">
                    <td class="ps-4">
                        <div class="fw-bold small">${dateStr}</div>
                        <small class="text-muted">${timeStr}</small>
                    </td>
                    <td>
                        <div class="fw-bold text-dark ${isVoided ? 'text-decoration-line-through text-muted' : ''}">${escapeHtml(row.product_name)} ${voidBadge}</div>
                        <small class="text-muted">
                            ${escapeHtml(row.brand_name || '')} | ${escapeHtml(row.variation_name || '')}
                        </small>
                        ${row.sku ? `<div class="small text-muted font-monospace">${escapeHtml(row.sku)}</div>` : ''}
                    </td>
                    <td class="text-end">
                        <div class="fw-medium text-secondary">₱${parseFloat(row.price_capital).toFixed(2)}</div>
                    </td>
                    <td class="text-center">
                        <span class="badge ${isVoided ? 'bg-danger-subtle text-danger' : 'bg-success-subtle text-success'} fs-6">
                            +${Math.abs(row.quantity)}
                        </span>
                    </td>
                    <td class="text-end">
                        <div class="fw-bold text-dark">₱${(Math.abs(row.quantity) * parseFloat(row.price_capital)).toFixed(2)}</div>
                    </td>
                    <td class="text-center">
                        <small class="text-muted">${row.previous_stock}</small>
                        <i class="fa-solid fa-arrow-right mx-1 text-muted" style="font-size:0.7em;"></i>
                        <span class="fw-bold">${row.new_stock}</span>
                    </td>
                    <td>
                        ${refHtml}
                    </td>
                    <td>
                        <div class="d-flex align-items-center justify-content-between">
                            <small class="text-muted">${escapeHtml(row.created_by_name || 'System')}</small>
                            ${isAdmin && !isVoided ? `<button class="btn btn-sm btn-light text-primary border shadow-sm ms-2" onclick="openEditModal(${row.movement_id})" title="Edit Record"><i class="fa-solid fa-pen"></i></button>` : ''}
                        </div>
                    </td>
                </tr>`;
        });

        tbody.innerHTML = html;
    }

    function renderMobileCards(records) {
        const container = document.getElementById('records-mobile');

        if (records.length === 0) {
            container.innerHTML = `
                <div class="empty-state text-center">
                    <i class="fa-solid fa-inbox d-block"></i>
                    <h6 class="text-muted">No Stock-In Records Found</h6>
                    <p class="small text-muted mb-0">No records found for this supplier</p>
                </div>`;
            return;
        }

        let lastDate = '';
        let html = '<div class="d-flex flex-column gap-3">';

        records.forEach(row => {
            const dateStr = new Date(row.created_at).toLocaleDateString('en-US', {
                year: 'numeric', month: 'short', day: 'numeric'
            });
            const timeStr = new Date(row.created_at).toLocaleTimeString('en-US', {
                hour: 'numeric', minute: '2-digit', hour12: true
            });

            if (dateStr !== lastDate) {
                html += `
                    <div class="fw-bold small text-muted bg-light rounded p-2">
                        <i class="fa-solid fa-calendar-day me-1 text-primary"></i>${dateStr}
                    </div>`;
                lastDate = dateStr;
            }

            const isVoided = row.status === 'voided';
            const voidBadge = isVoided ? '<span class="badge bg-danger small ms-2">VOIDED</span>' : '';

            html += `
                <div class="card mobile-record-card border-0 shadow-sm ${isVoided ? 'opacity-50 bg-light' : ''}">
                    <div class="card-body p-3">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <div class="flex-grow-1">
                                <div class="fw-bold text-dark ${isVoided ? 'text-decoration-line-through text-muted' : ''}">${escapeHtml(row.product_name)} ${voidBadge}</div>
                                <small class="text-muted">
                                    ${escapeHtml(row.brand_name || '')} | ${escapeHtml(row.variation_name || '')}
                                </small>
                            </div>
                            <div class="text-end">
                                <span class="badge ${isVoided ? 'bg-danger-subtle text-danger' : 'bg-success-subtle text-success'} fs-6 mb-1 d-block">
                                    +${Math.abs(row.quantity)}
                                </span>
                                <div class="fw-bold text-dark small">₱${(Math.abs(row.quantity) * parseFloat(row.price_capital)).toFixed(2)}</div>
                            </div>
                        </div>
                        <div class="d-flex justify-content-between align-items-center">
                            <div class="flex-grow-1">
                                ${row.reference
                    ? `<div class="d-flex align-items-center mb-1">
                                            <a href="reference.php?ref=${encodeURIComponent(row.reference)}&from=stockin_records" class="reference-link text-primary fw-bold">${escapeHtml(row.reference)}</a>
                                            ${parseInt(row.has_attachment) > 0
                        ? ''
                        : `<button class="btn btn-sm btn-link p-0 text-decoration-none ms-2" onclick="openRetroUpload(${row.movement_id}, '${escapeHtml(row.reference)}')">
                                                    <i class="fa-solid fa-cloud-arrow-up"></i> Add Photo
                                                   </button>`
                    }
                                       </div>`
                    : `<div class="d-flex align-items-center mb-1">
                                            <span class="text-muted small">No reference</span>
                                            <button class="btn btn-sm btn-link p-0 text-decoration-none ms-2" onclick="openRetroUpload(${row.movement_id}, 'No Reference')">
                                                <i class="fa-solid fa-cloud-arrow-up"></i> Add Photo
                                            </button>
                                       </div>`
                }
                                <small class="text-muted d-block">
                                    Cap: ₱${parseFloat(row.price_capital).toFixed(2)} | 
                                    Stock: ${row.previous_stock} → <strong>${row.new_stock}</strong>
                                </small>
                            </div>
                            <div class="d-flex align-items-center gap-2">
                                ${parseInt(row.has_attachment) > 0
                    ? `<a href="javascript:void(0)" 
                                          onclick="viewAttachment('${row.reference_image}', '${escapeHtml(row.reference)}')" 
                                          class="text-primary me-2">
                                            <i class="fa-solid fa-paperclip"></i>
                                            ${parseInt(row.has_attachment) > 1 ? `<span class="badge bg-secondary rounded-pill small ms-1" style="font-size: 0.7em;">+${parseInt(row.has_attachment) - 1}</span>` : ''}
                                       </a>`
                    : ''
                }
                                <small class="text-muted">${timeStr}</small>
                                ${isAdmin && !isVoided ? `<button class="btn btn-sm btn-light text-primary border shadow-sm ms-2" onclick="openEditModal(${row.movement_id})" title="Edit Record"><i class="fa-solid fa-pen"></i></button>` : ''}
                            </div>
                        </div>
                    </div>
                </div>`;
        });

        html += '</div>';
        container.innerHTML = html;
    }

    function renderPagination(pagination) {
        const footer = document.getElementById('pagination-footer');
        const info = document.getElementById('pagination-info');
        const btns = document.getElementById('pagination-btns');

        if (pagination.total <= pagination.per_page) {
            footer.classList.add('d-none');
            return;
        }

        footer.classList.remove('d-none');
        const start = (pagination.current_page - 1) * pagination.per_page + 1;
        const end = Math.min(pagination.current_page * pagination.per_page, pagination.total);
        info.textContent = `Showing ${start}–${end} of ${pagination.total} records`;

        let btnsHtml = '';
        if (pagination.current_page > 1) {
            btnsHtml += `<button class="btn btn-sm btn-outline-primary" onclick="loadRecords(${pagination.current_page - 1})">
                <i class="fa-solid fa-chevron-left"></i> Prev
            </button>`;
        }

        // Page numbers
        const maxVisible = 5;
        let startPage = Math.max(1, pagination.current_page - Math.floor(maxVisible / 2));
        let endPage = Math.min(pagination.total_pages, startPage + maxVisible - 1);
        startPage = Math.max(1, endPage - maxVisible + 1);

        for (let i = startPage; i <= endPage; i++) {
            btnsHtml += `<button class="btn btn-sm ${i === pagination.current_page ? 'btn-primary' : 'btn-outline-primary'}" 
                onclick="loadRecords(${i})">${i}</button>`;
        }

        if (pagination.current_page < pagination.total_pages) {
            btnsHtml += `<button class="btn btn-sm btn-outline-primary" onclick="loadRecords(${pagination.current_page + 1})">
                Next <i class="fa-solid fa-chevron-right"></i>
            </button>`;
        }

        btns.innerHTML = btnsHtml;
    }

    function resetFilters() {
        document.getElementById('supplier-select').value = '';
        document.getElementById('date-from').value = '';
        document.getElementById('date-to').value = '';
        localStorage.removeItem(LAST_SUPPLIER_KEY);
        document.getElementById('stats-row').classList.add('d-none');
        document.getElementById('pagination-footer').classList.add('d-none');
        document.getElementById('records-title').textContent = 'Stock-In Records';
        document.getElementById('records-count').textContent = 'Select a supplier';
        document.getElementById('export-btn').disabled = true;

        document.getElementById('records-tbody').innerHTML = `
            <tr>
                <td colspan="8" class="text-center py-5">
                    <div class="empty-state">
                        <i class="fa-solid fa-truck-ramp-box d-block"></i>
                        <h6 class="text-muted">Select a Supplier</h6>
                        <p class="small text-muted mb-0">Choose a supplier from the dropdown above to view their stock-in records</p>
                    </div>
                </td>
            </tr>`;
        document.getElementById('records-mobile').innerHTML = `
            <div class="empty-state text-center">
                <i class="fa-solid fa-truck-ramp-box d-block"></i>
                <h6 class="text-muted">Select a Supplier</h6>
                <p class="small text-muted mb-0">Choose a supplier from the dropdown above to view their stock-in records</p>
            </div>`;

        // Clear URL params
        window.history.replaceState({}, '', window.location.pathname);
    }

    function exportCSV() {
        const supplierId = document.getElementById('supplier-select').value;
        if (!supplierId) {
            EllaToast.warning('Please select a supplier');
            return;
        }

        const dateFrom = document.getElementById('date-from').value;
        const dateTo = document.getElementById('date-to').value;

        const params = new URLSearchParams({
            supplier_id: supplierId,
            date_from: dateFrom,
            date_to: dateTo
        });

        window.location.href = `../../api/inventory/export_stockin_records_csv.php?${params}`;
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text || '';
        return div.innerHTML;
    }

    function viewAttachment(imagesDataStr, ref) {
        const items = imagesDataStr.split(',');
        const container = document.getElementById('modal-carousel-inner');
        const prevBtn = document.querySelector('#attachmentCarousel .carousel-control-prev');
        const nextBtn = document.querySelector('#attachmentCarousel .carousel-control-next');
        const indicatorText = document.getElementById('carousel-indicator-text');

        container.innerHTML = '';
        document.getElementById('attachment-ref-title').textContent = ref;
        document.getElementById('total-img-count').textContent = items.length;
        document.getElementById('current-img-idx').textContent = '1';

        items.forEach((data, idx) => {
            const parts = data.split(':');
            const id = parts[0];
            const path = parts.slice(1).join(':');

            const item = document.createElement('div');
            item.className = `carousel-item ${idx === 0 ? 'active' : ''}`;
            item.innerHTML = `
                <div class="d-flex flex-column align-items-center justify-content-center p-4" style="min-height: 300px;">
                    <img src="${BASE_URL}${path}" class="img-fluid rounded shadow-sm" style="max-height: 70vh;" alt="Receipt">
                    <div class="mt-3" style="position: relative; z-index: 10;">
                        <a href="${BASE_URL}${path}" target="_blank" class="btn btn-sm btn-link text-decoration-none">
                            <i class="fa-solid fa-expand me-1"></i>View Full Size
                        </a>
                    </div>
                </div>
            `;
            // Store data for deletion
            item.dataset.id = id;
            item.dataset.path = path;
            container.appendChild(item);
        });

        if (items.length > 1) {
            prevBtn.classList.remove('d-none');
            nextBtn.classList.remove('d-none');
            indicatorText.classList.remove('d-none');
        } else {
            prevBtn.classList.add('d-none');
            nextBtn.classList.add('d-none');
            indicatorText.classList.add('d-none');
        }

        const modal = new bootstrap.Modal(document.getElementById('attachmentModal'));
        modal.show();

        // Handle index update
        const carouselEl = document.getElementById('attachmentCarousel');
        carouselEl.addEventListener('slide.bs.carousel', function (e) {
            document.getElementById('current-img-idx').textContent = e.to + 1;
        });
    }

    function deleteCurrentAttachment() {
        const activeItem = document.querySelector('#modal-carousel-inner .carousel-item.active');
        const id = activeItem ? activeItem.dataset.id : null;
        const path = activeItem ? activeItem.dataset.path : null;
        if (!path) return;

        if (!confirm('Are you sure you want to delete this specific photo? This action cannot be undone.')) return;

        const btn = document.querySelector('#attachmentModal .btn-outline-danger');
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i>Deleting...';
        }

        const formData = new FormData();
        if (id) formData.append('id', id);
        formData.append('image_path', path);

        fetch('../../api/inventory/delete_attachment.php', {
            method: 'POST',
            body: formData
        })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    if (typeof EllaToast !== 'undefined') EllaToast.success(data.message);
                    else alert(data.message);
                    setTimeout(() => {
                        const supplierId = document.getElementById('supplier-select').value;
                        if (supplierId) loadRecords(currentPage);
                        const modalObj = bootstrap.Modal.getInstance(document.getElementById('attachmentModal'));
                        modalObj.hide();
                    }, 1000);
                } else {
                    if (typeof EllaToast !== 'undefined') EllaToast.error('Error: ' + data.error);
                    else alert('Error: ' + data.error);
                    if (btn) {
                        btn.disabled = false;
                        btn.innerHTML = '<i class="fa-solid fa-trash me-1"></i>Delete Photo';
                    }
                }
            })
            .catch(err => {
                console.error(err);
                if (typeof EllaToast !== 'undefined') EllaToast.error('An error occurred during deletion.');
                else alert('An error occurred during deletion.');
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fa-solid fa-trash me-1"></i>Delete Photo';
                }
            });
    }

    function openRetroUpload(movementId, refLabel) {
        document.getElementById('upload-movement-id').value = movementId;
        document.getElementById('upload-ref-label').textContent = refLabel;
        document.getElementById('uploadForm').reset();
        const modal = new bootstrap.Modal(document.getElementById('uploadModal'));
        modal.show();
    }

    function openEditModal(movementId) {
        if (typeof EllaToast !== 'undefined') EllaToast.success('Loading record...', {duration: 1000});
        
        // Reset UI
        document.getElementById('edit-action-type').value = 'edit';
        document.getElementById('edit-product-search-container').classList.add('d-none');
        document.getElementById('edit-product-search').value = '';
        document.getElementById('edit-search-results').innerHTML = '';
        document.getElementById('edit-search-results').classList.add('d-none');
        document.getElementById('edit-qty-row').classList.remove('d-none');
        document.getElementById('edit-cap-row').classList.remove('d-none');
        document.getElementById('edit-reference-row').classList.add('d-none');
        document.getElementById('edit-reference-help').classList.add('d-none');
        document.getElementById('edit-new-qty').required = true;
        document.getElementById('edit-new-cap').required = true;
        document.getElementById('edit-new-reference').required = false;
        document.getElementById('edit-info-text').textContent = 'Original record is preserved in the audit log. Inventory will be updated.';

        fetch(`../../api/inventory/get_stockin_movement.php?movement_id=${movementId}`)
            .then(res => res.json())
            .then(data => {
                if(data.success) {
                    const mov = data.movement;
                    document.getElementById('edit-movement-id').value = mov.movement_id;
                    document.getElementById('edit-new-variation-id').value = mov.variation_id;
                    
                    document.getElementById('edit-product-name').textContent = mov.product_name;
                    document.getElementById('edit-product-details').textContent = `${mov.brand_name || ''} | ${mov.variation_name || ''}`;
                    document.getElementById('edit-current-inv').textContent = mov.current_inventory_stock;
                    
                    document.getElementById('edit-old-qty').value = mov.quantity;
                    document.getElementById('edit-new-qty').value = mov.quantity;
                    
                    const cap = parseFloat(mov.capital_cost).toFixed(2);
                    document.getElementById('edit-old-cap').value = cap;
                    document.getElementById('edit-new-cap').value = cap;
                    document.getElementById('edit-old-reference').value = mov.reference || 'No Reference';
                    document.getElementById('edit-new-reference').value = mov.reference || '';
                    
                    document.getElementById('edit-reason').value = '';
                    document.getElementById('edit-notes').value = '';
                    
                    const modal = new bootstrap.Modal(document.getElementById('editStockinModal'));
                    modal.show();
                } else {
                    if (typeof EllaToast !== 'undefined') EllaToast.error('Error: ' + data.error);
                    else alert('Error: ' + data.error);
                }
            })
            .catch(err => {
                console.error(err);
                if (typeof EllaToast !== 'undefined') EllaToast.error('Failed to load record');
                else alert('Failed to load record');
            });
    }

    function toggleProductSearch() {
        const container = document.getElementById('edit-product-search-container');
        if (container.classList.contains('d-none')) {
            container.classList.remove('d-none');
            document.getElementById('edit-action-type').value = 'swap';
            document.getElementById('edit-reason').value = 'Wrong Item';
            document.getElementById('edit-product-search').focus();
            document.getElementById('edit-reference-row').classList.add('d-none');
            document.getElementById('edit-reference-help').classList.add('d-none');
            document.getElementById('edit-new-reference').required = false;
            document.getElementById('edit-info-text').textContent = 'Original record is preserved in the audit log. Inventory will be updated.';
            
            // Re-enable in case they clicked void before
            document.getElementById('edit-qty-row').classList.remove('d-none');
            document.getElementById('edit-cap-row').classList.remove('d-none');
            document.getElementById('edit-new-qty').required = true;
            document.getElementById('edit-new-cap').required = true;
        } else {
            container.classList.add('d-none');
            document.getElementById('edit-action-type').value = 'edit';
        }
    }

    function startReferenceCorrection() {
        document.getElementById('edit-action-type').value = 'reference';
        document.getElementById('edit-reason').value = 'Reference Number Correction';
        document.getElementById('edit-product-search-container').classList.add('d-none');
        document.getElementById('edit-reference-row').classList.remove('d-none');
        document.getElementById('edit-reference-help').classList.remove('d-none');
        document.getElementById('edit-qty-row').classList.add('d-none');
        document.getElementById('edit-cap-row').classList.add('d-none');
        document.getElementById('edit-new-qty').required = false;
        document.getElementById('edit-new-cap').required = false;
        document.getElementById('edit-new-reference').required = true;
        document.getElementById('edit-info-text').textContent = 'Reference correction updates the receipt links only. Inventory quantities and costs are not changed.';
        document.getElementById('edit-new-reference').focus();
    }

    function selectNewProduct(p) {
        document.getElementById('edit-new-variation-id').value = p.variation_id;
        document.getElementById('edit-product-name').textContent = p.product_name;
        document.getElementById('edit-product-details').innerHTML = `${p.brand_name || ''} | ${p.variation_name || ''} <span class="badge bg-warning text-dark ms-2">Swapped</span>`;
        document.getElementById('edit-current-inv').textContent = p.current_stock || 0;
        document.getElementById('edit-new-cap').value = parseFloat(p.price_capital).toFixed(2);
        
        document.getElementById('edit-search-results').classList.add('d-none');
        document.getElementById('edit-product-search').value = '';
        if (typeof EllaToast !== 'undefined') EllaToast.success('Product changed to ' + p.product_name);
    }

    function confirmVoidRecord() {
        if(!confirm('Are you sure you want to VOID this record? The items will be deducted from inventory immediately.')) return;
        
        document.getElementById('edit-action-type').value = 'void';
        document.getElementById('edit-reason').value = 'Item Returned/Removed';
        document.getElementById('edit-new-qty').required = false;
        document.getElementById('edit-new-cap').required = false;
        document.getElementById('edit-new-reference').required = false;
        document.getElementById('edit-reference-row').classList.add('d-none');
        document.getElementById('edit-reference-help').classList.add('d-none');
        document.getElementById('edit-info-text').textContent = 'Original record is preserved in the audit log. Inventory will be updated.';
        
        // Hide search
        document.getElementById('edit-product-search-container').classList.add('d-none');
        
        // Disable rows visually
        document.getElementById('edit-qty-row').classList.add('d-none');
        document.getElementById('edit-cap-row').classList.add('d-none');
        
        if (typeof EllaToast !== 'undefined') EllaToast.warning('Click Save Correction to confirm Void.');
    }

    document.addEventListener('DOMContentLoaded', function () {
        // Handle Retroactive Upload Form
        document.getElementById('uploadForm')?.addEventListener('submit', async function (e) {
            e.preventDefault();
            const btn = document.getElementById('btn-upload');
            const fileInput = document.getElementById('upload-file');

            if (!fileInput.files.length) {
                if (typeof EllaToast !== 'undefined') EllaToast.warning('Please select an image first.');
                else alert('Please select an image first.');
                return;
            }

            btn.disabled = true;
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i>Uploading...';

            const formData = new FormData(this);

            try {
                const res = await fetch('../../api/inventory/upload_retroactive_attachment.php', {
                    method: 'POST',
                    body: formData
                });

                const data = await res.json();

                if (data.success) {
                    if (typeof EllaToast !== 'undefined') EllaToast.success(data.message);
                    else alert(data.message);

                    // Reload current records
                    const supplierId = document.getElementById('supplier-select').value;
                    if (supplierId) loadRecords(1);

                    const modalObj = bootstrap.Modal.getInstance(document.getElementById('uploadModal'));
                    modalObj.hide();
                } else {
                    if (typeof EllaToast !== 'undefined') EllaToast.error('Error: ' + data.error);
                    else alert('Error: ' + data.error);
                }
            } catch (err) {
                console.error(err);
                if (typeof EllaToast !== 'undefined') EllaToast.error('An error occurred during upload.');
                else alert('An error occurred during upload.');
            } finally {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-upload me-1"></i>Upload Photo';
            }
        });

        // Search logic inside modal
        const searchInput = document.getElementById('edit-product-search');
        const resultsContainer = document.getElementById('edit-search-results');
        let searchTimeout;

        if(searchInput) {
            searchInput.addEventListener('input', function() {
                clearTimeout(searchTimeout);
                const q = this.value.trim();
                
                if (q.length < 2) {
                    resultsContainer.innerHTML = '';
                    resultsContainer.classList.add('d-none');
                    return;
                }

                searchTimeout = setTimeout(async () => {
                    try {
                        const res = await fetch(`../../api/inventory/search_products.php?q=${encodeURIComponent(q)}`);
                        const data = await res.json();
                        
                        if (data.success && data.products.length > 0) {
                            let html = '';
                            data.products.forEach(p => {
                                html += `
                                    <button type="button" class="list-group-item list-group-item-action p-2" onclick='selectNewProduct(${JSON.stringify(p).replace(/'/g, "&#39;")})'>
                                        <div class="fw-bold small">${escapeHtml(p.product_name)}</div>
                                        <small class="text-muted" style="font-size: 0.75rem;">${escapeHtml(p.brand_name || '')} | ${escapeHtml(p.variation_name || '')}</small>
                                    </button>
                                `;
                            });
                            resultsContainer.innerHTML = html;
                            resultsContainer.classList.remove('d-none');
                        } else {
                            resultsContainer.innerHTML = '<div class="list-group-item text-muted small p-2">No products found</div>';
                            resultsContainer.classList.remove('d-none');
                        }
                    } catch (err) {
                        console.error(err);
                    }
                }, 300);
            });
        }

        // Handle Edit Form
        document.getElementById('editStockinForm')?.addEventListener('submit', async function(e) {
            e.preventDefault();
            const btn = document.getElementById('btn-save-edit');
            
            const movement_id = document.getElementById('edit-movement-id').value;
            const action_type = document.getElementById('edit-action-type').value;
            const new_variation_id = document.getElementById('edit-new-variation-id').value;
            const new_quantity = document.getElementById('edit-new-qty').value ? parseInt(document.getElementById('edit-new-qty').value) : 0;
            const new_capital = document.getElementById('edit-new-cap').value ? parseFloat(document.getElementById('edit-new-cap').value) : 0;
            const new_reference = document.getElementById('edit-new-reference').value.trim();
            const reason = document.getElementById('edit-reason').value;
            const notes = document.getElementById('edit-notes').value;

            if (action_type === 'reference' && !new_reference) {
                if (typeof EllaToast !== 'undefined') EllaToast.warning('Enter the corrected reference number.');
                else alert('Enter the corrected reference number.');
                return;
            }

            btn.disabled = true;
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i>Saving...';

            try {
                const res = await fetch('../../api/inventory/update_stockin_record.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ movement_id, action_type, new_variation_id, new_quantity, new_capital, new_reference, reason, notes })
                });

                const data = await res.json();
                if(data.success) {
                    if (typeof EllaToast !== 'undefined') EllaToast.success(data.message);
                    else alert(data.message);
                    
                    const modalObj = bootstrap.Modal.getInstance(document.getElementById('editStockinModal'));
                    modalObj.hide();
                    
                    loadRecords(currentPage); // reload
                } else {
                    if (typeof EllaToast !== 'undefined') EllaToast.error('Error: ' + data.error);
                    else alert('Error: ' + data.error);
                }
            } catch(err) {
                console.error(err);
                if (typeof EllaToast !== 'undefined') EllaToast.error('Network error');
                else alert('Network error');
            } finally {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-save me-1"></i>Save Correction';
            }
        });
    });
</script>

<!-- Upload Retroactive Modal -->
<div class="modal fade" id="uploadModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header">
                <h6 class="modal-title fw-bold"><i class="fa-solid fa-cloud-arrow-up me-2 text-primary"></i>Upload
                    Reference Photo</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="uploadForm">
                <div class="modal-body">
                    <input type="hidden" id="upload-movement-id" name="movement_id">
                    <p class="small text-muted mb-3">Uploading photo for Reference: <strong id="upload-ref-label"
                            class="text-dark"></strong></p>

                    <div class="mb-3">
                        <label class="form-label small fw-bold">Select Images (Max 8) <span
                                class="text-danger">*</span></label>
                        <input type="file" name="reference_images[]" id="upload-file" class="form-control"
                            accept="image/*" multiple required>
                        <div class="form-text small">You can select up to 8 images at once.</div>
                    </div>
                </div>
                <div class="modal-footer bg-light border-0">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm" id="btn-upload">
                        <i class="fa-solid fa-upload me-1"></i>Upload Photo
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Attachment Modal -->
<div class="modal fade" id="attachmentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header border-0 pb-0">
                <h6 class="modal-title fw-bold"><i class="fa-solid fa-paperclip me-2 text-primary"></i>Reference: <span
                        id="attachment-ref-title"></span></h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-0">
                <div id="attachmentCarousel" class="carousel slide" data-bs-ride="false">
                    <div class="carousel-inner" id="modal-carousel-inner">
                        <!-- Images will be injected here -->
                    </div>
                    <button class="carousel-control-prev d-none" type="button" data-bs-target="#attachmentCarousel"
                        data-bs-slide="prev" style="width: 10%;">
                        <span class="carousel-control-prev-icon bg-dark rounded-circle p-2" aria-hidden="true"></span>
                        <span class="visually-hidden">Previous</span>
                    </button>
                    <button class="carousel-control-next d-none" type="button" data-bs-target="#attachmentCarousel"
                        data-bs-slide="next" style="width: 10%;">
                        <span class="carousel-control-next-icon bg-dark rounded-circle p-2" aria-hidden="true"></span>
                        <span class="visually-hidden">Next</span>
                    </button>
                </div>
                <div class="text-center p-2 bg-light border-top d-none" id="carousel-indicator-text">
                    <small class="text-muted">Image <span id="current-img-idx">1</span> of <span
                            id="total-img-count">1</span></small>
                </div>
            </div>
            <div class="modal-footer border-0 pt-0 justify-content-between">
                <div>
                    <?php if (in_array($_SESSION['role'], ['admin', 'super_admin', 'manager'])): ?>
                        <button type="button" class="btn btn-sm btn-outline-danger" onclick="deleteCurrentAttachment()">
                            <i class="fa-solid fa-trash me-1"></i>Delete Photo
                        </button>
                    <?php endif; ?>
                </div>
                <div class="d-flex gap-2">
                    <a id="attachment-download" href="" download class="btn btn-sm btn-outline-primary"
                        onclick="this.href=document.getElementById('attachment-img').src">
                        <i class="fa-solid fa-download me-1"></i>Download
                    </a>
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Edit Stock-In Modal -->
<div class="modal fade" id="editStockinModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-warning text-dark">
                <h6 class="modal-title fw-bold"><i class="fa-solid fa-pen-to-square me-2"></i>Edit Stock-In Record</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="editStockinForm">
                <div class="modal-body">
                    <input type="hidden" id="edit-movement-id" name="movement_id">
                    <input type="hidden" id="edit-action-type" name="action_type" value="edit">
                    <input type="hidden" id="edit-new-variation-id" name="new_variation_id" value="">
                    
                    <div class="alert alert-info small py-2 mb-3 border-0">
                        <i class="fa-solid fa-circle-info me-1"></i> <span id="edit-info-text">Original record is preserved in the audit log. Inventory will be updated.</span>
                    </div>

                    <div class="mb-3 bg-light p-3 rounded border position-relative">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <div class="fw-bold text-dark" id="edit-product-name"></div>
                                <div class="small text-muted" id="edit-product-details"></div>
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-primary" onclick="toggleProductSearch()">
                                <i class="fa-solid fa-right-left me-1"></i>Change Product
                            </button>
                        </div>
                        <div class="small mt-2"><span class="text-muted">Current System Inventory:</span> <span id="edit-current-inv" class="fw-bold text-dark bg-white px-2 py-1 rounded border"></span></div>
                        
                        <!-- Product Search Dropdown (Hidden by default) -->
                        <div id="edit-product-search-container" class="mt-3 d-none border-top pt-3">
                            <label class="form-label small fw-bold text-primary"><i class="fa-solid fa-search me-1"></i>Search New Product</label>
                            <div class="position-relative">
                                <input type="text" id="edit-product-search" class="form-control form-control-sm" placeholder="Scan Barcode or Search Name..." autocomplete="off">
                                <div id="edit-search-results" class="search-dropdown list-group position-absolute w-100 shadow-sm" style="z-index: 1050; max-height: 200px; overflow-y: auto;"></div>
                            </div>
                        </div>
                    </div>

                    <div class="row g-3 mb-3" id="edit-qty-row">
                        <div class="col-6">
                            <label class="form-label small fw-bold text-muted">Old Quantity</label>
                            <input type="number" id="edit-old-qty" class="form-control bg-light text-muted" readonly>
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-bold text-primary">New Quantity <span class="text-danger">*</span></label>
                            <input type="number" id="edit-new-qty" name="new_quantity" class="form-control fw-bold border-primary" min="1" required>
                        </div>
                    </div>

                    <div class="row g-3 mb-3" id="edit-cap-row">
                        <div class="col-6">
                            <label class="form-label small fw-bold text-muted">Old Capital</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light text-muted">₱</span>
                                <input type="number" id="edit-old-cap" class="form-control bg-light text-muted" readonly>
                            </div>
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-bold text-success">New Capital</label>
                            <div class="input-group">
                                <span class="input-group-text bg-success text-white border-success">₱</span>
                                <input type="number" step="0.01" id="edit-new-cap" name="new_capital" class="form-control fw-bold border-success" required>
                            </div>
                        </div>
                    </div>

                    <div class="row g-3 mb-2 d-none" id="edit-reference-row">
                        <div class="col-6">
                            <label class="form-label small fw-bold text-muted">Old Reference</label>
                            <input type="text" id="edit-old-reference" class="form-control bg-light text-muted" readonly>
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-bold text-primary">New Reference <span class="text-danger">*</span></label>
                            <input type="text" id="edit-new-reference" name="new_reference" class="form-control fw-bold border-primary" maxlength="100">
                        </div>
                    </div>
                    <div class="alert alert-warning small py-2 mb-3 d-none" id="edit-reference-help">
                        <i class="fa-solid fa-triangle-exclamation me-1"></i> This updates all stock-in lines, purchase order links, and receipt photos under the same reference.
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-bold">Reason for Correction <span class="text-danger">*</span></label>
                        <select name="reason" id="edit-reason" class="form-select border-warning" required>
                            <option value="">-- Select Reason --</option>
                            <option value="Wrong Item">Wrong Item Selected</option>
                            <option value="Wrong Quantity">Wrong Quantity Entered</option>
                            <option value="Price Correction">Capital Price Correction</option>
                            <option value="Reference Number Correction">Reference Number Correction</option>
                            <option value="Supplier Invoice Correction">Supplier Invoice Correction</option>
                            <option value="Data Entry Error">Data Entry Error</option>
                            <option value="Item Returned/Removed">Item Returned/Removed</option>
                            <option value="Other">Other (Please specify in notes)</option>
                        </select>
                    </div>

                    <div class="mb-2">
                        <label class="form-label small fw-bold">Notes</label>
                        <textarea name="notes" id="edit-notes" class="form-control" rows="2" placeholder="Additional details..."></textarea>
                    </div>
                </div>
                <div class="modal-footer border-0 bg-light d-flex justify-content-between">
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-outline-primary btn-sm" onclick="startReferenceCorrection()">
                            <i class="fa-solid fa-hashtag me-1"></i>Correct Reference
                        </button>
                        <button type="button" class="btn btn-outline-danger btn-sm" onclick="confirmVoidRecord()">
                            <i class="fa-solid fa-trash-can me-1"></i>Void Entry
                        </button>
                    </div>
                    <div>
                        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-warning btn-sm fw-bold shadow-sm" id="btn-save-edit">
                            <i class="fa-solid fa-save me-1"></i>Save Correction
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>

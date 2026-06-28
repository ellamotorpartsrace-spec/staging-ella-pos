<?php
// views/inventory/reference_image_sync.php
require_once '../../config/config.php';
require_once '../../includes/auth.php';

requireLogin();
if (!in_array($_SESSION['role'] ?? '', ['admin', 'super_admin'])) {
    denyAccess("Admin access is required to sync reference image backups.");
}

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
?>

<style>
    .sync-stat-card {
        border: 0;
        border-radius: 8px;
        box-shadow: 0 0.125rem 0.5rem rgba(15, 23, 42, 0.08);
    }

    .sync-stat-card .stat-icon {
        width: 42px;
        height: 42px;
        border-radius: 8px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
    }

    .sync-results-table {
        table-layout: fixed;
    }

    .sync-results-table td,
    .sync-results-table th {
        vertical-align: middle;
    }

    .sync-path {
        max-width: 100%;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
</style>

<div class="container-fluid p-4">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
        <div>
            <h4 class="fw-bold mb-1">
                <i class="fa-solid fa-database text-primary me-2"></i>Reference Image Backup Sync
            </h4>
            <div class="text-muted">
                Copy existing local reference images into the database backup columns.
            </div>
        </div>
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-outline-primary" id="btn-scan">
                <i class="fa-solid fa-magnifying-glass me-1"></i> Scan
            </button>
            <button type="button" class="btn btn-primary" id="btn-sync">
                <i class="fa-solid fa-cloud-arrow-up me-1"></i> Sync Missing Backups
            </button>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-6 col-xl-3">
            <div class="card sync-stat-card">
                <div class="card-body d-flex align-items-center gap-3">
                    <span class="stat-icon bg-primary bg-opacity-10 text-primary">
                        <i class="fa-solid fa-paperclip"></i>
                    </span>
                    <div>
                        <div class="small text-muted fw-semibold text-uppercase">Total</div>
                        <div class="h4 fw-bold mb-0" id="stat-total">0</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="card sync-stat-card">
                <div class="card-body d-flex align-items-center gap-3">
                    <span class="stat-icon bg-warning bg-opacity-10 text-warning">
                        <i class="fa-solid fa-circle-exclamation"></i>
                    </span>
                    <div>
                        <div class="small text-muted fw-semibold text-uppercase">Missing DB Backup</div>
                        <div class="h4 fw-bold mb-0" id="stat-missing-backup">0</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="card sync-stat-card">
                <div class="card-body d-flex align-items-center gap-3">
                    <span class="stat-icon bg-success bg-opacity-10 text-success">
                        <i class="fa-solid fa-check"></i>
                    </span>
                    <div>
                        <div class="small text-muted fw-semibold text-uppercase">Already Backed Up</div>
                        <div class="h4 fw-bold mb-0" id="stat-backed-up">0</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="card sync-stat-card">
                <div class="card-body d-flex align-items-center gap-3">
                    <span class="stat-icon bg-danger bg-opacity-10 text-danger">
                        <i class="fa-solid fa-file-circle-xmark"></i>
                    </span>
                    <div>
                        <div class="small text-muted fw-semibold text-uppercase">Missing Local File</div>
                        <div class="h4 fw-bold mb-0" id="stat-missing-file">0</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white py-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <h6 class="fw-bold mb-0">Sync Results</h6>
                <small class="text-muted" id="sync-status">Run a scan to check backup status.</small>
            </div>
            <span class="badge bg-light text-dark border" id="sync-action-label">Idle</span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 sync-results-table">
                <thead class="table-light">
                    <tr>
                        <th style="width: 90px;">ID</th>
                        <th style="width: 190px;">Reference</th>
                        <th>Local Path</th>
                        <th style="width: 170px;">Status</th>
                        <th style="width: 280px;">Message</th>
                    </tr>
                </thead>
                <tbody id="sync-results-body">
                    <tr>
                        <td colspan="5" class="text-center py-5 text-muted">No scan results yet.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
    const scanButton = document.getElementById('btn-scan');
    const syncButton = document.getElementById('btn-sync');
    const statusText = document.getElementById('sync-status');
    const actionLabel = document.getElementById('sync-action-label');
    const resultsBody = document.getElementById('sync-results-body');

    const statEls = {
        total: document.getElementById('stat-total'),
        missing_backup: document.getElementById('stat-missing-backup'),
        already_backed_up: document.getElementById('stat-backed-up'),
        missing_file: document.getElementById('stat-missing-file')
    };

    function escapeHtml(value) {
        const div = document.createElement('div');
        div.textContent = value ?? '';
        return div.innerHTML;
    }

    function statusBadge(status) {
        const map = {
            ready: 'bg-warning text-dark',
            backed_up: 'bg-success',
            already_backed_up: 'bg-success',
            missing_file: 'bg-danger',
            unreadable_file: 'bg-danger',
            missing_path: 'bg-secondary',
            failed: 'bg-danger'
        };
        const label = String(status || 'unknown').replaceAll('_', ' ');
        return `<span class="badge ${map[status] || 'bg-secondary'}">${escapeHtml(label)}</span>`;
    }

    function setLoading(isLoading, action) {
        scanButton.disabled = isLoading;
        syncButton.disabled = isLoading;
        if (isLoading) {
            actionLabel.className = 'badge bg-primary';
            actionLabel.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i>' + action;
        } else {
            actionLabel.className = 'badge bg-light text-dark border';
            actionLabel.textContent = 'Idle';
        }
    }

    function updateSummary(summary) {
        statEls.total.textContent = Number(summary.total || 0).toLocaleString();
        statEls.missing_backup.textContent = Number(summary.missing_backup || 0).toLocaleString();
        statEls.already_backed_up.textContent = Number(summary.already_backed_up || 0).toLocaleString();
        statEls.missing_file.textContent = Number(summary.missing_file || 0).toLocaleString();
    }

    function renderResults(results) {
        if (!results || results.length === 0) {
            resultsBody.innerHTML = '<tr><td colspan="5" class="text-center py-5 text-muted">No reference attachments found.</td></tr>';
            return;
        }

        resultsBody.innerHTML = results.map(row => `
            <tr>
                <td class="fw-semibold">#${Number(row.id || 0)}</td>
                <td class="fw-semibold">${escapeHtml(row.reference_number || '-')}</td>
                <td><div class="sync-path" title="${escapeHtml(row.image_path || '')}">${escapeHtml(row.image_path || '-')}</div></td>
                <td>${statusBadge(row.status)}</td>
                <td class="small text-muted">${escapeHtml(row.message || '')}</td>
            </tr>
        `).join('');
    }

    async function runSyncAction(action) {
        setLoading(true, action === 'sync' ? 'Syncing' : 'Scanning');
        statusText.textContent = action === 'sync'
            ? 'Copying local images into missing database backups...'
            : 'Checking local files and database backups...';

        try {
            const res = await fetch(`../../api/inventory/sync_reference_images.php?action=${encodeURIComponent(action)}`, {
                method: 'POST'
            });
            const data = await res.json();

            if (!data.success) {
                throw new Error(data.error || 'Sync request failed.');
            }

            updateSummary(data.summary || {});
            renderResults(data.results || []);

            if (action === 'sync') {
                statusText.textContent = `Sync complete. Backed up ${Number(data.summary.backed_up || 0).toLocaleString()} image(s).`;
            } else {
                statusText.textContent = `Scan complete. ${Number(data.summary.missing_backup || 0).toLocaleString()} image(s) need DB backup.`;
            }
        } catch (error) {
            console.error(error);
            statusText.textContent = error.message || 'Request failed.';
            if (typeof EllaToast !== 'undefined') {
                EllaToast.error(error.message || 'Request failed.');
            }
        } finally {
            setLoading(false);
        }
    }

    scanButton.addEventListener('click', () => runSyncAction('scan'));
    syncButton.addEventListener('click', () => runSyncAction('sync'));

    document.addEventListener('DOMContentLoaded', () => runSyncAction('scan'));
</script>

<?php require_once '../../includes/footer.php'; ?>

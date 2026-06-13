<?php
// views/inventory/pending_approvals.php
require_once '../../config/config.php';
require_once '../../includes/auth.php';

require_once '../../config/database.php';

requireLogin();
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin', 'super_admin'])) {
    denyAccess("Only admins can access the Pending Approvals page.");
}

// Restricted UI for regular admins
if ($_SESSION['role'] === 'admin') {
    require_once '../../includes/header.php';
    require_once '../../includes/sidebar.php';
    ?>
    ?>
    <style>
        .restricted-wrapper {
            min-height: calc(100vh - 120px);
            display: flex;
            justify-content: center;
            align-items: center;
            background: #f8fafc;
        }
        .restricted-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            padding: 3.5rem 3rem;
            max-width: 460px;
            width: 100%;
            text-align: center;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.05), 0 8px 10px -6px rgba(0, 0, 0, 0.01);
            position: relative;
            overflow: hidden;
        }
        .restricted-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(to right, #ef4444, #f87171);
        }
        .icon-circle {
            width: 80px;
            height: 80px;
            margin: 0 auto 1.5rem;
            background: #fef2f2;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #ef4444;
            font-size: 2.2rem;
            transition: transform 0.3s ease;
        }
        .restricted-card:hover .icon-circle {
            transform: scale(1.05);
            background: #fee2e2;
        }
        .restricted-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 0.5rem;
        }
        .restricted-text {
            color: #64748b;
            font-size: 1rem;
            line-height: 1.6;
            margin-bottom: 2rem;
        }
        .restricted-text strong {
            color: #334155;
            font-weight: 600;
        }
        .btn-return {
            background: #ffffff;
            color: #334155;
            border: 1px solid #cbd5e1;
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            font-weight: 500;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 100%;
            text-decoration: none;
            box-shadow: 0 1px 2px rgba(0,0,0,0.05);
        }
        .btn-return:hover {
            background: #f8fafc;
            border-color: #94a3b8;
            color: #0f172a;
            transform: translateY(-1px);
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
        }
    </style>
    
    <div class="restricted-wrapper">
        <div class="restricted-card">
            <div class="icon-circle">
                <i class="fa-solid fa-lock"></i>
            </div>
            <h4 class="restricted-title">Access Restricted</h4>
            <p class="restricted-text">
                Only <strong>Super Admins</strong> have the necessary clearance to view and approve restock requests.
            </p>
            <a href="<?= BASE_URL ?>views/dashboard/index.php" class="btn-return">
                <i class="fa-solid fa-arrow-left me-2"></i>Return to Dashboard
            </a>
        </div>
    </div>
    <?php
    require_once '../../includes/footer.php';
    exit;
}

$db = new Database();
$conn = $db->getConnection();

// Removed redundant password protection since page is now strictly role-based.

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';

// Fetch Pending Requests (Grouped by Batch ID if applicable)
$sql = "
    SELECT 
        COALESCE(r.batch_id, CONCAT('REQ-', r.request_id)) as group_id,
        r.batch_id,
        r.request_id,
        r.supplier_name,
        r.reference,
        r.created_at,
        r.payment_status,
        u.full_name as requested_by_name,
        COUNT(r.request_id) as item_count,
        SUM(r.quantity * r.cost) as total_cost
    FROM restock_requests r
    JOIN users u ON r.requested_by = u.id
    WHERE r.status = 'pending'
    GROUP BY group_id
    ORDER BY r.created_at DESC
";
$stmt = $conn->prepare($sql);
$stmt->execute();
$pendingGroups = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>
<div class="container-fluid p-3 p-lg-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="fw-bold text-dark mb-1">Pending Restock Approvals</h3>
            <p class="text-muted mb-0">Review and approve inventory incoming stock requests.</p>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="bg-light">
                        <tr>
                            <th class="ps-4">Reference</th>
                            <th>Requested By</th>
                            <th>Supplier & ID</th>
                            <th>Items</th>
                            <th>Total Cost</th>
                            <th>Date</th>
                            <th class="pe-4 text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($pendingGroups)): ?>
                            <tr>
                                <td colspan="7" class="text-center py-5 text-muted">
                                    <i class="fa-solid fa-check-circle fa-3x mb-3 text-success opacity-50"></i>
                                    <h5>All Caught Up!</h5>
                                    <p>There are no pending restocks waiting for approval.</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($pendingGroups as $group): ?>
                                <tr>
                                    <td class="ps-4 fw-bold">
                                        <a href="#" onclick="viewDetails('<?= $group['batch_id'] ?>', '<?= $group['request_id'] ?>', '<?= $group['group_id'] ?>'); return false;" class="text-primary text-decoration-none">
                                            <?= htmlspecialchars($group['reference'] ?: 'No Reference') ?>
                                        </a>
                                    </td>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <div class="avatar-sm bg-primary text-white rounded-circle d-flex align-items-center justify-content-center me-2" style="width: 32px; height: 32px;">
                                                <?= strtoupper(substr($group['requested_by_name'], 0, 1)) ?>
                                            </div>
                                            <?= htmlspecialchars($group['requested_by_name']) ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="fw-bold"><?= htmlspecialchars($group['supplier_name'] ?: 'No Supplier') ?></div>
                                        <div class="small text-muted"><?= htmlspecialchars($group['group_id']) ?></div>
                                    </td>
                                    <td>
                                        <span class="badge bg-info text-dark"><?= $group['item_count'] ?> item(s)</span>
                                    </td>
                                    <td class="fw-bold text-success">
                                        ₱<?= number_format($group['total_cost'], 2) ?>
                                        <?php if ($group['payment_status'] === 'unpaid'): ?>
                                            <span class="badge bg-warning text-dark ms-1">Unpaid</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-muted small">
                                        <?= date('M d, Y h:i A', strtotime($group['created_at'])) ?>
                                    </td>
                                    <td class="pe-4 text-end">
                                        <button class="btn btn-sm btn-success shadow-sm me-1" onclick="approveRequest('<?= $group['batch_id'] ?>', '<?= $group['request_id'] ?>')">
                                            <i class="fa-solid fa-check me-1"></i>Approve
                                        </button>
                                        <button class="btn btn-sm btn-danger shadow-sm" onclick="rejectRequest('<?= $group['batch_id'] ?>', '<?= $group['request_id'] ?>')">
                                            <i class="fa-solid fa-times me-1"></i>Reject
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

<!-- Details Modal -->
<div class="modal fade" id="detailsModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content border-0 shadow">
      <div class="modal-header bg-light">
        <h5 class="modal-title fw-bold" id="detailsModalLabel">Restock Details</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="bg-light">
                    <tr>
                        <th class="ps-3">Product</th>
                        <th>Variation</th>
                        <th>Qty</th>
                        <th>Unit Cost</th>
                        <th class="pe-3 text-end">Total</th>
                    </tr>
                </thead>
                <tbody id="detailsTbody">
                    <!-- Loaded via AJAX -->
                </tbody>
                <tfoot id="detailsTfoot" class="table-light d-none">
                    <tr>
                        <td colspan="4" class="text-end fw-bold">Grand Total:</td>
                        <td class="text-end fw-bold text-success fs-5" id="detailsGrandTotal">₱0.00</td>
                    </tr>
                </tfoot>
            </table>
        </div>
        
        <!-- Attachments Section -->
        <div id="detailsAttachments" class="p-3 bg-light border-top d-none">
            <h6 class="fw-bold mb-2"><i class="fa-solid fa-paperclip me-2 text-muted"></i>Attached References</h6>
            <div id="attachmentsContainer" class="d-flex flex-wrap gap-2">
                <!-- Images loaded here -->
            </div>
        </div>
      </div>
      <div class="modal-footer bg-light">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
function viewDetails(batchId, requestId, groupId) {
    document.getElementById('detailsModalLabel').innerText = 'Details: ' + groupId;
    const tbody = document.getElementById('detailsTbody');
    tbody.innerHTML = '<tr><td colspan="5" class="text-center py-4"><i class="fa-solid fa-spinner fa-spin fa-2x text-primary"></i></td></tr>';
    
    let url = '../../api/inventory/get_restock_details.php?';
    if (batchId) url += 'batch_id=' + batchId;
    else url += 'request_id=' + requestId;

    const modal = new bootstrap.Modal(document.getElementById('detailsModal'));
    modal.show();

    fetch(url)
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            tbody.innerHTML = '';
            data.items.forEach(item => {
                const total = parseFloat(item.quantity) * parseFloat(item.cost);
                
                let productMeta = '';
                if (item.brand_name || item.sku) {
                    productMeta = `<div class="small text-muted mt-1">`;
                    if (item.brand_name) productMeta += `<span class="badge bg-secondary me-1">${item.brand_name}</span>`;
                    if (item.sku) productMeta += `<span class="text-secondary"><i class="fa-solid fa-barcode me-1"></i>${item.sku}</span>`;
                    productMeta += `</div>`;
                }

                tbody.innerHTML += `
                    <tr>
                        <td class="ps-3">
                            <div class="fw-bold text-dark">${item.product_name}</div>
                            ${productMeta}
                        </td>
                        <td class="text-muted">${item.variation_name}</td>
                        <td><span class="badge bg-success">+${item.quantity}</span></td>
                        <td>₱${parseFloat(item.cost).toLocaleString(undefined, {minimumFractionDigits: 2})}</td>
                        <td class="pe-3 text-end fw-bold">₱${total.toLocaleString(undefined, {minimumFractionDigits: 2})}</td>
                    </tr>
                `;
            });

            // Show Grand Total
            document.getElementById('detailsTfoot').classList.remove('d-none');
            document.getElementById('detailsGrandTotal').innerText = '₱' + parseFloat(data.grand_total).toLocaleString(undefined, {minimumFractionDigits: 2});

            // Show Attachments
            const attachmentsDiv = document.getElementById('detailsAttachments');
            const attachmentsContainer = document.getElementById('attachmentsContainer');
            if (data.attachments && data.attachments.length > 0) {
                attachmentsDiv.classList.remove('d-none');
                attachmentsContainer.innerHTML = '';
                data.attachments.forEach(att => {
                    attachmentsContainer.innerHTML += `
                        <a href="../../${att.image_path}" target="_blank" class="border rounded p-1 d-inline-block shadow-sm" style="background: #fff;">
                            <img src="../../${att.image_path}" alt="${att.original_filename}" style="height: 60px; object-fit: cover; border-radius: 4px;">
                        </a>
                    `;
                });
            } else {
                attachmentsDiv.classList.add('d-none');
                attachmentsContainer.innerHTML = '';
            }

        } else {
            document.getElementById('detailsTfoot').classList.add('d-none');
            document.getElementById('detailsAttachments').classList.add('d-none');
            tbody.innerHTML = `<tr><td colspan="5" class="text-center text-danger py-4">${data.error}</td></tr>`;
        }
    })
    .catch(err => {
        document.getElementById('detailsTfoot').classList.add('d-none');
        document.getElementById('detailsAttachments').classList.add('d-none');
        tbody.innerHTML = `<tr><td colspan="5" class="text-center text-danger py-4">Error loading details.</td></tr>`;
    });
}

function approveRequest(batchId, requestId) {
    Swal.fire({
        title: 'Approve Restock?',
        text: "Are you sure you want to approve this request?",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#198754',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Yes, approve it!'
    }).then((result) => {
        if (result.isConfirmed) {
            processAction('../../api/inventory/approve_restock.php', batchId, requestId);
        }
    });
}

function rejectRequest(batchId, requestId) {
    Swal.fire({
        title: 'Reject Restock?',
        text: "Are you sure you want to reject this request?",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Yes, reject it!'
    }).then((result) => {
        if (result.isConfirmed) {
            processAction('../../api/inventory/reject_restock.php', batchId, requestId);
        }
    });
}

function processAction(url, batchId, requestId) {
    let payload = {};
    if (batchId) payload.batch_id = batchId;
    else payload.request_id = requestId;
    
    fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            Swal.fire('Success!', data.message, 'success').then(() => {
                location.reload();
            });
        } else {
            Swal.fire('Error', data.error || 'Something went wrong', 'error');
        }
    })
    .catch(err => {
        Swal.fire('Error', 'Network error occurred', 'error');
        console.error(err);
    });
}
</script>

<?php require_once '../../includes/footer.php'; ?>

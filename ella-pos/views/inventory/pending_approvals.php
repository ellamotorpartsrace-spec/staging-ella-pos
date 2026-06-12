<?php
// views/inventory/pending_approvals.php
require_once '../../config/config.php';
require_once '../../includes/auth.php';

require_once '../../config/database.php';

requireLogin();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    denyAccess("Only admins can access the Pending Approvals page.");
}

$db = new Database();
$conn = $db->getConnection();

// --- PASSWORD PROTECTION FOR PAGE ACCESS ---
if (isset($_POST['access_password'])) {
    $stmtAuth = $conn->prepare("SELECT password FROM users WHERE id = ?");
    $stmtAuth->execute([$_SESSION['user_id']]);
    $user = $stmtAuth->fetch(PDO::FETCH_ASSOC);

    if ($user && password_verify($_POST['access_password'], $user['password'])) {
        $_SESSION['pending_approvals_unlocked'] = true;
        header("Location: pending_approvals.php");
        exit;
    } else {
        $access_error = "Incorrect password. Please try again.";
    }
}

// Check if session is unlocked
if (!isset($_SESSION['pending_approvals_unlocked']) || $_SESSION['pending_approvals_unlocked'] !== true) {
    require_once '../../includes/header.php';
    require_once '../../includes/sidebar.php';
    ?>
    <div class="container-fluid p-3 p-lg-4 d-flex justify-content-center align-items-center" style="min-height: 70vh;">
        <div class="card border-0 shadow-sm" style="max-width: 400px; width: 100%;">
            <div class="card-body p-4 text-center">
                <i class="fa-solid fa-lock fa-3x text-primary mb-3"></i>
                <h4 class="fw-bold mb-3">Restricted Access</h4>
                <p class="text-muted small mb-4">Please enter your admin password to view pending restock approvals.</p>
                
                <?php if (isset($access_error)): ?>
                    <div class="alert alert-danger small py-2"><?= htmlspecialchars($access_error) ?></div>
                <?php endif; ?>

                <form method="POST" action="">
                    <div class="mb-3">
                        <input type="password" name="access_password" class="form-control form-control-lg text-center" placeholder="Admin Password" required autofocus>
                    </div>
                    <button type="submit" class="btn btn-primary btn-lg w-100 shadow-sm">
                        <i class="fa-solid fa-unlock me-2"></i>Unlock Page
                    </button>
                </form>
            </div>
        </div>
    </div>
    <?php
    require_once '../../includes/footer.php';
    exit;
}
// --- END PASSWORD PROTECTION ---

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

function promptPassword(actionText, confirmColor, callback) {
    Swal.fire({
        title: actionText,
        text: "Please enter your admin password to confirm.",
        input: 'password',
        inputAttributes: {
            autocapitalize: 'off',
            autocorrect: 'off'
        },
        showCancelButton: true,
        confirmButtonColor: confirmColor,
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Confirm',
        showLoaderOnConfirm: true,
        preConfirm: (password) => {
            if (!password) {
                Swal.showValidationMessage('Password is required');
                return false;
            }
            return password;
        }
    }).then((result) => {
        if (result.isConfirmed) {
            callback(result.value);
        }
    });
}

function approveRequest(batchId, requestId) {
    promptPassword('Approve Restock?', '#198754', (password) => {
        processAction('../../api/inventory/approve_restock.php', batchId, requestId, password);
    });
}

function rejectRequest(batchId, requestId) {
    promptPassword('Reject Restock?', '#dc3545', (password) => {
        processAction('../../api/inventory/reject_restock.php', batchId, requestId, password);
    });
}

function processAction(url, batchId, requestId, password) {
    let payload = { password: password };
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

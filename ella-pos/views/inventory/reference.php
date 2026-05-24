<?php
// views/inventory/reference.php
require_once '../../config/config.php';
require_once '../../includes/auth.php';

// Auth Check
requireLogin();
if ($_SESSION['role'] !== 'admin' && !hasPermission('adjust_prices') && !in_array($_SESSION['role'], ['manager', 'stockman'])) {
    denyAccess("You do not have permission to view stock movements.");
}

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
require_once '../../config/database.php';

$ref = $_GET['ref'] ?? null;
$from = $_GET['from'] ?? 'movements';

// Determine back URL based on origin page
$back_urls = [
    'stockin_records' => ['url' => 'stockin_records.php', 'label' => 'Back to Stock-In Records'],
    'movements' => ['url' => 'movements.php', 'label' => 'Back to Stock Movements'],
];
$back_info = $back_urls[$from] ?? $back_urls['movements'];

if (!$ref) {
    echo "<div class='container p-4'><h3>❌ No reference provided</h3><a href='" . $back_info['url'] . "'>Back</a></div>";
    require_once '../../includes/footer.php';
    exit;
}

$db = new Database();
$conn = $db->getConnection();

// 1. Fetch Reference Image
$stmtImg = $conn->prepare("SELECT * FROM reference_attachments WHERE reference_number = ?");
$stmtImg->execute([$ref]);
$attachments = $stmtImg->fetchAll(PDO::FETCH_ASSOC);

// 2. Fetch Items (Stock Movements)
$sqlItems = "
    SELECT sm.*, 
           p.product_name, p.brand_name, 
           v.variation_name, v.sku, v.unit_type, COALESCE(sm.capital_cost, v.price_capital) as price_capital,
           u.full_name as created_by_name
    FROM stock_movements sm
    JOIN product_variations v ON sm.variation_id = v.variation_id
    JOIN products p ON v.product_id = p.product_id
    LEFT JOIN users u ON sm.created_by = u.id
    WHERE sm.reference = ?
    ORDER BY sm.created_at DESC
";
$stmtItems = $conn->prepare($sqlItems);
$stmtItems->execute([$ref]);
$items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

// 3. Fetch Adjustment Logs for these items
$sqlLogs = "
    SELECT log.*, 
           p_old.product_name as old_product_name, v_old.variation_name as old_variation_name,
           p_new.product_name as new_product_name, v_new.variation_name as new_variation_name,
           u.full_name as adjusted_by_name
    FROM stockin_adjustment_log log
    JOIN stock_movements sm ON log.movement_id = sm.movement_id
    LEFT JOIN product_variations v_old ON log.old_variation_id = v_old.variation_id
    LEFT JOIN products p_old ON v_old.product_id = p_old.product_id
    LEFT JOIN product_variations v_new ON log.new_variation_id = v_new.variation_id
    LEFT JOIN products p_new ON v_new.product_id = p_new.product_id
    LEFT JOIN users u ON log.adjusted_by = u.id
    WHERE sm.reference = ?
    ORDER BY log.adjusted_at DESC
";
$stmtLogs = $conn->prepare($sqlLogs);
$stmtLogs->execute([$ref]);
$adjLogs = $stmtLogs->fetchAll(PDO::FETCH_ASSOC);

// 4. Fetch Capital Price History for all variations in this reference
$variationIds = array_unique(array_column($items, 'variation_id'));
$priceHistory = [];
if (!empty($variationIds)) {
    $placeholders = implode(',', array_fill(0, count($variationIds), '?'));
    $sqlPH = "
        SELECT h.variation_id, h.old_capital, h.new_capital, h.changed_at,
               u.full_name as changed_by_name
        FROM product_price_history h
        LEFT JOIN users u ON h.user_id = u.id
        WHERE h.variation_id IN ($placeholders)
          AND h.old_capital != h.new_capital
        ORDER BY h.changed_at DESC
    ";
    $stmtPH = $conn->prepare($sqlPH);
    $stmtPH->execute($variationIds);
    $allPriceHistory = $stmtPH->fetchAll(PDO::FETCH_ASSOC);
    
    // Group by variation_id for easy lookup
    foreach ($allPriceHistory as $ph) {
        $priceHistory[$ph['variation_id']][] = $ph;
    }
}
?>

<div class="container-fluid p-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="fw-bold mb-1"><i class="fa-solid fa-file-invoice text-primary me-2"></i>Reference Details</h4>
            <div class="text-muted">
                Reference Code: <span class="fw-bold text-dark selectable"><?= htmlspecialchars($ref) ?></span>
            </div>
        </div>
        <a href="<?= htmlspecialchars($back_info['url']) ?>" class="btn btn-outline-secondary">
            <i class="fa-solid fa-arrow-left me-1"></i> <?= htmlspecialchars($back_info['label']) ?>
        </a>
    </div>

    <?php
    $totalItemsCount = 0;
    $totalItemsQty = 0;
    $totalTransactionCost = 0;
    foreach ($items as $row) {
        if (($row['status'] ?? '') === 'voided') {
            continue; // Skip counting voided entries in active transaction totals
        }
        $totalItemsCount++;
        $totalItemsQty += abs($row['quantity']);
        if (in_array($row['type'], ['stock_in', 'return'])) {
            $totalTransactionCost += abs($row['quantity']) * (float) $row['price_capital'];
        }
    }
    ?>

    <?php if (count($items) > 0 && $totalTransactionCost > 0): ?>
        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <div class="card shadow-sm border-0 bg-primary bg-opacity-10">
                    <div class="card-body p-3">
                        <div class="text-primary small fw-bold text-uppercase mb-1">Total Items</div>
                        <div class="h4 fw-bold mb-0 text-primary"><?= number_format($totalItemsCount) ?> <small
                                class="fw-normal fs-6">unique products</small></div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card shadow-sm border-0 bg-success bg-opacity-10">
                    <div class="card-body p-3">
                        <div class="text-success small fw-bold text-uppercase mb-1">Total Quantity Count</div>
                        <div class="h4 fw-bold mb-0 text-success">+<?= number_format($totalItemsQty) ?> <small
                                class="fw-normal fs-6">qty added</small></div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card shadow-sm border-0 bg-warning bg-opacity-10">
                    <div class="card-body p-3">
                        <div class="text-warning small fw-bold text-uppercase mb-1">Reference Total Cost</div>
                        <div class="h4 fw-bold mb-0 text-warning">₱<?= number_format($totalTransactionCost, 2) ?></div>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <!-- Left Column: Items List -->
        <div class="col-lg-7">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="fw-bold mb-0">Items in this Reference</h6>
                        <small class="text-muted"><?= count($items) ?> item(s) found</small>
                    </div>
                    <div class="d-flex align-items-center gap-3">
                        <?php if (count($items) > 0): ?>
                            <div class="text-end d-none d-sm-block">
                                <small class="text-muted d-block">Created By</small>
                                <span
                                    class="fw-bold small"><?= htmlspecialchars($items[0]['created_by_name'] ?? 'System') ?></span>
                                <div class="small text-muted"><?= date('M d, Y h:i A', strtotime($items[0]['created_at'])) ?>
                                </div>
                            </div>
                        <?php endif; ?>
                        <?php if (in_array($_SESSION['role'], ['admin', 'manager'])): ?>
                            <button class="btn btn-primary btn-sm" onclick="openAddItemModal()">
                                <i class="fa-solid fa-plus me-1"></i> Add Item
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="bg-light">
                                <tr>
                                    <th class="ps-4">Product Detail</th>
                                    <th class="text-end">Capital</th>
                                    <th class="text-center">Qty Change</th>
                                    <th class="text-end">Total</th>
                                    <th>Remarks</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($items) > 0): ?>
                                    <?php foreach ($items as $row):
                                        $is_voided = ($row['status'] ?? '') === 'voided';
                                        $qty_sign = in_array($row['type'], ['stock_in', 'return']) ? '+' : '-';
                                        $qty_color = $is_voided ? 'secondary' : (in_array($row['type'], ['stock_in', 'return']) ? 'success' : 'danger');
                                        
                                        // Find adjustments for this row
                                        $row_logs = array_filter($adjLogs ?? [], function($l) use ($row) {
                                            return (int)$l['movement_id'] === (int)$row['movement_id'];
                                        });
                                        ?>
                                        <tr class="<?= $is_voided ? 'table-light opacity-75' : '' ?>">
                                            <td class="ps-4">
                                                <div class="fw-bold <?= $is_voided ? 'text-decoration-line-through text-muted' : 'text-dark' ?>">
                                                    <?= htmlspecialchars($row['product_name']) ?>
                                                </div>
                                                <small class="text-muted <?= $is_voided ? 'text-decoration-line-through' : '' ?>">
                                                    <?= htmlspecialchars($row['brand_name']) ?> |
                                                    <?= htmlspecialchars($row['variation_name']) ?>
                                                    <?php if ($row['sku']): ?> (<?= htmlspecialchars($row['sku']) ?>)
                                                    <?php endif; ?>
                                                </small>

                                                <?php if (!empty($row_logs)): ?>
                                                    <div class="mt-2 p-2 bg-warning bg-opacity-10 border-start border-3 border-warning rounded-end" style="font-size: 0.8rem; max-width: 450px;">
                                                        <?php foreach ($row_logs as $log): ?>
                                                            <div class="mb-1">
                                                                <span class="badge bg-warning text-dark me-1" style="font-size: 0.7rem;">Adjusted</span>
                                                                <span class="text-muted small">by <?= htmlspecialchars($log['adjusted_by_name'] ?? 'System') ?> on <?= date('M d, Y h:i A', strtotime($log['adjusted_at'])) ?>:</span>
                                                            </div>
                                                            <div class="fw-semibold text-dark mb-1">
                                                                <?php if ($log['action_type'] === 'edit'): ?>
                                                                    Capital: ₱<?= number_format($log['old_capital'], 2) ?> → <span class="text-success">₱<?= number_format($log['new_capital'], 2) ?></span>
                                                                    <br>Qty: <?= $log['old_quantity'] ?> → <span class="text-success"><?= $log['new_quantity'] ?></span>
                                                                <?php elseif ($log['action_type'] === 'swap'): ?>
                                                                    Swapped from variation: <span class="text-danger text-decoration-line-through"><?= htmlspecialchars($log['old_product_name'] . ' (' . $log['old_variation_name'] . ')') ?></span>
                                                                    <br>Capital: ₱<?= number_format($log['old_capital'], 2) ?> → <span class="text-success">₱<?= number_format($log['new_capital'], 2) ?></span>
                                                                    <br>Qty: <?= $log['old_quantity'] ?> → <span class="text-success"><?= $log['new_quantity'] ?></span>
                                                                <?php elseif ($log['action_type'] === 'void'): ?>
                                                                    Voided Transaction (Previous Total Cost: ₱<?= number_format($log['old_quantity'] * $log['old_capital'], 2) ?>)
                                                                <?php elseif ($log['action_type'] === 'add_to_ref'): ?>
                                                                    Missed product added manually (Capital: ₱<?= number_format($log['new_capital'], 2) ?>)
                                                                <?php endif; ?>
                                                            </div>
                                                            <?php if (!empty($log['reason'])): ?>
                                                                <div class="text-secondary small fst-italic mt-1"><i class="fa-solid fa-comment me-1"></i>Reason: "<?= htmlspecialchars($log['reason']) ?>"</div>
                                                            <?php endif; ?>
                                                        <?php endforeach; ?>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end" style="min-width: 180px;">
                                                <div class="fw-bold <?= $is_voided ? 'text-decoration-line-through text-muted' : 'text-dark' ?>">
                                                    ₱<?= number_format((float) $row['price_capital'], 2) ?>
                                                </div>
                                                <?php 
                                                $varPH = $priceHistory[$row['variation_id']] ?? [];
                                                if (!empty($varPH)): ?>
                                                    <div class="mt-1" style="font-size: 0.75rem;">
                                                        <a href="javascript:void(0)" class="text-primary text-decoration-none fw-semibold price-history-toggle" 
                                                           onclick="this.nextElementSibling.classList.toggle('d-none'); this.querySelector('i').classList.toggle('fa-chevron-down'); this.querySelector('i').classList.toggle('fa-chevron-up');">
                                                            <i class="fa-solid fa-chevron-down me-1" style="font-size: 0.6rem;"></i><?= count($varPH) ?> price change<?= count($varPH) > 1 ? 's' : '' ?>
                                                        </a>
                                                        <div class="d-none mt-1 p-2 bg-light border rounded-2 text-start" style="max-width: 220px;">
                                                            <?php foreach ($varPH as $idx => $ph): ?>
                                                                <div class="<?= $idx > 0 ? 'mt-1 pt-1 border-top' : '' ?>">
                                                                    <div>
                                                                        <span class="text-decoration-line-through text-muted">₱<?= number_format($ph['old_capital'], 2) ?></span>
                                                                        <i class="fa-solid fa-arrow-right mx-1 text-muted" style="font-size: 0.6rem;"></i>
                                                                        <span class="fw-bold <?= $ph['new_capital'] > $ph['old_capital'] ? 'text-danger' : 'text-success' ?>">₱<?= number_format($ph['new_capital'], 2) ?></span>
                                                                    </div>
                                                                    <div class="text-muted" style="font-size: 0.65rem;">
                                                                        <?= date('M d, Y', strtotime($ph['changed_at'])) ?>
                                                                        <?php if (!empty($ph['changed_by_name'])): ?>
                                                                            · <?= htmlspecialchars($ph['changed_by_name']) ?>
                                                                        <?php endif; ?>
                                                                    </div>
                                                                </div>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center">
                                                <?php if ($is_voided): ?>
                                                    <span class="badge bg-danger-subtle text-danger fs-6 fw-bold">VOIDED</span>
                                                <?php else: ?>
                                                    <span class="badge bg-<?= $qty_color ?>-subtle text-<?= $qty_color ?> fs-6">
                                                        <?= $qty_sign ?> <?= abs($row['quantity']) ?>
                                                    </span>
                                                    <div class="small text-muted mt-1">
                                                        Stock: <?= $row['previous_stock'] ?> <i class="fa-solid fa-arrow-right mx-1"
                                                            style="font-size: 0.7em;"></i> <?= $row['new_stock'] ?>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end">
                                                <div class="fw-bold <?= $is_voided ? 'text-decoration-line-through text-muted' : 'text-dark' ?>">
                                                    ₱<?= number_format($is_voided ? 0 : (abs($row['quantity']) * (float) $row['price_capital']), 2) ?>
                                                </div>
                                            </td>
                                            <td class="text-muted small">
                                                <?= htmlspecialchars($row['remarks']) ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="5" class="text-center py-5 text-muted">No items found linked to this
                                            reference.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                            <?php if (count($items) > 0 && $totalTransactionCost > 0): ?>
                                <tfoot class="bg-light bg-opacity-50 border-top-0">
                                    <tr>
                                        <td colspan="2" class="text-end fw-bold text-dark py-3">Totals:</td>
                                        <td class="text-center py-3">
                                            <div class="badge bg-success text-white fs-6 shadow-sm">+
                                                <?= number_format($totalItemsQty) ?>
                                            </div>
                                        </td>
                                        <td class="text-end py-3">
                                            <div class="fw-bold text-dark fs-5">₱
                                                <?= number_format($totalTransactionCost, 2) ?>
                                            </div>
                                        </td>
                                        <td></td>
                                    </tr>
                                </tfoot>
                            <?php endif; ?>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right Column: Image/Attachment -->
        <div class="col-lg-5">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <h6 class="fw-bold mb-0"><i class="fa-solid fa-paperclip me-2"></i>Attachment</h6>
                    <?php if (in_array($_SESSION['role'], ['admin', 'manager'])): ?>
                        <button type="button" class="btn btn-sm btn-success"
                            onclick="document.getElementById('retroactive-upload').click()">
                            <i class="fa-solid fa-plus me-1"></i> Add Photo
                        </button>
                        <input type="file" id="retroactive-upload" class="d-none" accept="image/*" multiple
                            onchange="uploadAttachment(this, '<?= htmlspecialchars($ref) ?>')">
                    <?php endif; ?>
                </div>
                <div class="card-body bg-light d-flex align-items-center justify-content-center p-0"
                    style="min-height: 400px; position: relative;">
                    <?php if (count($attachments) > 0): ?>
                        <div id="attachmentCarousel" class="carousel slide w-100 h-100" data-bs-ride="carousel">
                            <?php if (count($attachments) > 1): ?>
                                <div class="carousel-indicators">
                                    <?php foreach ($attachments as $index => $att): ?>
                                        <button type="button" data-bs-target="#attachmentCarousel" data-bs-slide-to="<?= $index ?>"
                                            class="<?= $index === 0 ? 'active' : '' ?>"
                                            aria-current="<?= $index === 0 ? 'true' : 'false' ?>"
                                            aria-label="Slide <?= $index + 1 ?>"></button>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                            <div class="carousel-inner h-100">
                                <?php foreach ($attachments as $index => $att): ?>
                                    <div class="carousel-item h-100 <?= $index === 0 ? 'active' : '' ?>">
                                        <div class="d-flex flex-column align-items-center justify-content-center h-100 p-2">
                                            <img src="<?= BASE_URL . $att['image_path'] ?>"
                                                class="d-block img-fluid rounded shadow-sm"
                                                style="max-height: 550px; width: auto;" alt="Attachment <?= $index + 1 ?>">
                                            <div class="mt-3 d-flex gap-2" style="position: relative; z-index: 10;">
                                                <a href="<?= BASE_URL . $att['image_path'] ?>" target="_blank"
                                                    class="btn btn-sm btn-primary">
                                                    <i class="fa-solid fa-expand me-1"></i> View Full Size
                                                </a>
                                                <?php if (in_array($_SESSION['role'], ['admin', 'manager'])): ?>
                                                    <button type="button" class="btn btn-sm btn-outline-danger"
                                                        onclick="deleteAttachment(<?= $att['id'] ?>, '<?= $att['image_path'] ?>', this)">
                                                        <i class="fa-solid fa-trash me-1"></i> Delete
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <?php if (count($attachments) > 1): ?>
                                <button class="carousel-control-prev" type="button" data-bs-target="#attachmentCarousel"
                                    data-bs-slide="prev" style="width: 10%;">
                                    <span class="carousel-control-prev-icon bg-dark rounded-circle p-2"
                                        aria-hidden="true"></span>
                                    <span class="visually-hidden">Previous</span>
                                </button>
                                <button class="carousel-control-next" type="button" data-bs-target="#attachmentCarousel"
                                    data-bs-slide="next" style="width: 10%;">
                                    <span class="carousel-control-next-icon bg-dark rounded-circle p-2"
                                        aria-hidden="true"></span>
                                    <span class="visually-hidden">Next</span>
                                </button>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="text-center text-muted opacity-50">
                            <i class="fa-regular fa-image fa-4x mb-3"></i>
                            <p>No image attached to this reference.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Add Item Modal -->
<div class="modal fade" id="addItemModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header">
                <h5 class="modal-title fw-bold"><i class="fa-solid fa-cart-plus me-2 text-primary"></i>Add Missed Item</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="addItemForm">
                <div class="modal-body">
                    <input type="hidden" name="reference" value="<?= htmlspecialchars($ref) ?>">
                    
                    <!-- Product Search -->
                    <div class="mb-3 position-relative">
                        <label class="form-label small fw-bold">SEARCH PRODUCT <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text bg-white"><i class="fa-solid fa-search text-muted"></i></span>
                            <input type="text" id="product-search" class="form-control" placeholder="Search by name, brand or barcode..." autocomplete="off">
                        </div>
                        <div id="search-results" class="list-group position-absolute w-100 shadow-sm d-none" style="z-index: 1050; max-height: 250px; overflow-y: auto;"></div>
                        <input type="hidden" name="variation_id" id="selected-variation-id" required>
                    </div>

                    <!-- Selected Product Display -->
                    <div id="selected-product-info" class="p-3 bg-light rounded-3 mb-3 d-none">
                        <div class="fw-bold text-dark" id="disp-product-name"></div>
                        <small class="text-muted" id="disp-variation-name"></small>
                        <div class="mt-2 text-primary small fw-bold">Current Capital: ₱<span id="disp-capital">0.00</span></div>
                    </div>

                    <div class="row g-3">
                        <div class="col-6">
                            <label class="form-label small fw-bold">QUANTITY <span class="text-danger">*</span></label>
                            <input type="number" name="quantity" class="form-control" min="1" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-bold">CAPITAL PRICE <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text bg-light">₱</span>
                                <input type="number" name="capital_cost" id="input-capital" class="form-control" step="0.01" required>
                            </div>
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-bold">REMARKS</label>
                            <input type="text" name="remarks" class="form-control" placeholder="Optional notes...">
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light border-0">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="btn-save-item">
                        <i class="fa-solid fa-save me-1"></i> Save Item
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    let addItemModal;
    
    document.addEventListener('DOMContentLoaded', function() {
        const modalEl = document.getElementById('addItemModal');
        if (modalEl) {
            addItemModal = new bootstrap.Modal(modalEl);
        }
    });
    
    function openAddItemModal() {
        if (!addItemModal) {
            const modalEl = document.getElementById('addItemModal');
            if (modalEl) addItemModal = new bootstrap.Modal(modalEl);
        }
        document.getElementById('addItemForm').reset();
        document.getElementById('selected-product-info').classList.add('d-none');
        document.getElementById('selected-variation-id').value = '';
        if (addItemModal) addItemModal.show();
    }

    // Product Search Logic
    const searchInput = document.getElementById('product-search');
    const resultsContainer = document.getElementById('search-results');
    let searchTimeout = null;

    searchInput.addEventListener('input', function() {
        const query = this.value.trim();
        clearTimeout(searchTimeout);
        
        if (query.length < 2) {
            resultsContainer.classList.add('d-none');
            return;
        }

        searchTimeout = setTimeout(() => {
            fetch(`../../api/inventory/search_products.php?q=${encodeURIComponent(query)}`)
                .then(res => res.json())
                .then(data => {
                    resultsContainer.innerHTML = '';
                    const products = data.products || [];
                    if (products.length > 0) {
                        products.forEach(item => {
                            const btn = document.createElement('button');
                            btn.type = 'button';
                            btn.className = 'list-group-item list-group-item-action py-2';
                            btn.innerHTML = `
                                <div class="fw-bold small">${item.product_name}</div>
                                <div class="text-muted extra-small">${item.brand_name} | ${item.variation_name} | ₱${item.price_capital}</div>
                            `;
                            btn.onclick = () => selectProduct(item);
                            resultsContainer.appendChild(btn);
                        });
                        resultsContainer.classList.remove('d-none');
                    } else {
                        resultsContainer.innerHTML = '<div class="list-group-item disabled text-center small">No products found</div>';
                        resultsContainer.classList.remove('d-none');
                    }
                });
        }, 300);
    });

    function selectProduct(item) {
        document.getElementById('selected-variation-id').value = item.variation_id;
        document.getElementById('disp-product-name').textContent = item.product_name;
        document.getElementById('disp-variation-name').textContent = `${item.brand_name} | ${item.variation_name}`;
        document.getElementById('disp-capital').textContent = item.price_capital;
        document.getElementById('input-capital').value = item.price_capital;
        document.getElementById('selected-product-info').classList.remove('d-none');
        resultsContainer.classList.add('d-none');
        searchInput.value = '';
    }

    // Hide search results when clicking outside
    document.addEventListener('click', function(e) {
        if (!searchInput.contains(e.target) && !resultsContainer.contains(e.target)) {
            resultsContainer.classList.add('d-none');
        }
    });

    // Form Submission
    document.getElementById('addItemForm').addEventListener('submit', function(e) {
        e.preventDefault();
        const btn = document.getElementById('btn-save-item');
        
        if (!document.getElementById('selected-variation-id').value) {
            EllaToast.error('Please select a product first');
            return;
        }

        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i>Saving...';

        const formData = new FormData(this);
        const data = Object.fromEntries(formData.entries());

        fetch('../../api/inventory/add_stockin_to_reference.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        })
        .then(res => res.json())
        .then(result => {
            if (result.success) {
                EllaToast.success(result.message);
                setTimeout(() => window.location.reload(), 1000);
            } else {
                EllaToast.error(result.error || 'Failed to add item');
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-save me-1"></i> Save Item';
            }
        })
        .catch(err => {
            console.error(err);
            EllaToast.error('An error occurred');
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-save me-1"></i> Save Item';
        });
    });


    function deleteAttachment(id, path, btn) {
        if (!confirm('Are you sure you want to delete this receipt photo? This action cannot be undone.')) return;

        // Disable button to prevent double clicks
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
                    setTimeout(() => window.location.reload(), 1000);
                } else {
                    if (typeof EllaToast !== 'undefined') EllaToast.error('Error: ' + data.error);
                    else alert('Error: ' + data.error);
                    if (btn) {
                        btn.disabled = false;
                        btn.innerHTML = '<i class="fa-solid fa-trash me-1"></i> Delete';
                    }
                }
            })
            .catch(err => {
                console.error(err);
                if (typeof EllaToast !== 'undefined') EllaToast.error('An error occurred during deletion.');
                else alert('An error occurred during deletion.');
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fa-solid fa-trash me-1"></i> Delete';
                }
            });
    }

    function uploadAttachment(input, ref) {
        if (!input.files || input.files.length === 0) return;

        const formData = new FormData();
        formData.append('reference_number', ref);
        for (let i = 0; i < input.files.length; i++) {
            formData.append('reference_images[]', input.files[i]);
        }

        // Show loading state
        if (typeof EllaToast !== 'undefined') EllaToast.info('Uploading attachment(s)...');

        fetch('../../api/inventory/upload_retroactive_attachment.php', {
            method: 'POST',
            body: formData
        })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    if (typeof EllaToast !== 'undefined') EllaToast.success(data.message || 'Attachments uploaded successfully!');
                    else alert('Attachments uploaded successfully!');
                    setTimeout(() => window.location.reload(), 1000);
                } else {
                    if (typeof EllaToast !== 'undefined') EllaToast.error('Error: ' + data.error);
                    else alert('Error: ' + data.error);
                    input.value = ''; // Reset input so it can be triggered again
                }
            })
            .catch(err => {
                console.error(err);
                if (typeof EllaToast !== 'undefined') EllaToast.error('An error occurred during upload.');
                else alert('An error occurred during upload.');
                input.value = '';
            });
    }
</script>

<?php require_once '../../includes/footer.php'; ?>
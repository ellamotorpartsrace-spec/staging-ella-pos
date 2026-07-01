<?php
$page_title = 'Lazada Sync — Resolution Center';
require_once '../../config/config.php';
require_once '../../includes/auth.php';
requirePermission('lazada_mapping');
require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';

$db = new Database();
$conn = $db->getConnection();
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/lazada-sync.css?v=<?= filemtime(__DIR__.'/../../assets/css/lazada-sync.css') ?>">

<div class="lz-page lz-animate">
    <?php require_once __DIR__ . '/lazada_token_warning.php'; ?>
    
    <!-- Hero Header -->
    <div class="lz-hero-header mb-4" style="background: linear-gradient(135deg, #ef4444 0%, #b91c1c 100%);">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
            <div style="position:relative;z-index:2;">
                <div class="d-flex align-items-center gap-3 mb-2">
                    <div style="width:52px;height:52px;border-radius:14px;background:#ffffff;display:flex;align-items:center;justify-content:center;box-shadow:0 4px 15px rgba(0,0,0,0.1);">
                        <i class="fa-solid fa-triangle-exclamation fa-lg" style="color: #ef4444;"></i>
                    </div>
                    <div>
                        <h2 class="mb-0 text-white fw-bold" style="letter-spacing:-0.5px;">Resolution Center</h2>
                        <div class="text-white text-opacity-75 small">Manage sync errors, missing SKUs, and disputes</div>
                    </div>
                </div>
            </div>
            <div style="position:relative;z-index:2;">
                <a href="<?= BASE_URL ?>views/lazada/index.php" class="btn btn-light rounded-pill px-4 fw-bold shadow-sm">
                    <i class="fa-solid fa-arrow-left me-2"></i> Back to Dashboard
                </a>
            </div>
        </div>
    </div>

    <!-- Navigation Pills -->
    <div class="d-flex flex-wrap gap-2 mb-4">
        <button class="lz-pill active" style="background: #ef4444; color: white; border-color: #ef4444;">
            <i class="fa-solid fa-barcode"></i> Missing SKUs
            <span class="filter-count" id="unmappedCount" style="background: rgba(255,255,255,0.3);">0</span>
        </button>
    </div>

    <div class="row">
        <div class="col-12">
            <div class="lz-card">
                <div class="lz-card-header d-flex justify-content-between align-items-center">
                    <div>
                        <h5 class="mb-0 fw-bolder" style="color: var(--text-primary);">Action Required: Missing SKUs</h5>
                        <div class="small text-muted mt-1">The following Lazada items cannot be synced because they do not have a corresponding local SKU. Please map them to a POS product.</div>
                    </div>
                </div>
                
                <div class="lz-card-body p-0">
                    <div class="table-responsive">
                        <table class="table lz-table mb-0 align-middle">
                            <thead>
                                <tr>
                                    <th>Lazada Item</th>
                                    <th width="150">Lazada ID</th>
                                    <th width="120">Price</th>
                                    <th width="200" class="text-end pe-4">Resolve</th>
                                </tr>
                            </thead>
                            <tbody id="missingSkusTbody">
                                <tr><td colspan="4" class="text-center py-4 text-muted"><i class="fa-solid fa-spinner fa-spin me-2"></i>Loading missing SKUs...</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>

<script>
document.addEventListener("DOMContentLoaded", async function() {
    loadMissingSkus();
});

async function loadMissingSkus() {
    try {
        const res = await fetch(`${window.BASE_URL}api/lazada/get_resolution_data.php`);
        const data = await res.json();
        
        if (data.success && data.missing_skus) {
            document.getElementById('unmappedCount').innerText = data.missing_skus.length;
            renderMissingSkus(data.missing_skus);
        } else {
            console.error(data.error);
            document.getElementById('missingSkusTbody').innerHTML = `<tr><td colspan="4" class="text-center py-4 text-danger">Failed to load data.</td></tr>`;
        }
    } catch (e) {
        console.error("Failed to fetch resolution data", e);
        document.getElementById('missingSkusTbody').innerHTML = `<tr><td colspan="4" class="text-center py-4 text-danger">Network error.</td></tr>`;
    }
}

function renderMissingSkus(skus) {
    const tbody = document.getElementById('missingSkusTbody');
    if (!skus || skus.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="4" class="text-center py-5">
                    <div style="width: 60px; height: 60px; border-radius: 50%; background: #ecfdf5; display: flex; align-items: center; justify-content: center; margin: 0 auto 15px;">
                        <i class="fa-solid fa-check fa-xl text-success"></i>
                    </div>
                    <h5 class="fw-bold text-dark">All Caught Up!</h5>
                    <p class="text-muted mb-0">There are no missing SKUs. All Lazada items are currently mapped.</p>
                </td>
            </tr>
        `;
        return;
    }

    let html = '';
    skus.forEach(item => {
        const imgHtml = item.lazada_image_url 
            ? `<img src="${item.lazada_image_url}" style="max-width: 100%; max-height: 100%; object-fit: contain;">`
            : `<i class="fa-solid fa-image text-muted opacity-50"></i>`;
            
        const varBadge = item.lazada_variation_name 
            ? `<span class="badge bg-light text-dark border ms-2 small" style="font-size: 0.7rem; font-weight: normal;">${escapeHtml(item.lazada_variation_name)}</span>` 
            : '';
            
        // Use the seller sku if available to pre-fill the search, otherwise use item id
        const searchParam = encodeURIComponent(item.lazada_seller_sku || item.lazada_item_id);

        html += `
            <tr>
                <td class="ps-4">
                    <div class="d-flex align-items-center gap-3">
                        <div style="width: 48px; height: 48px; border-radius: 8px; border: 1px solid #e2e8f0; overflow: hidden; display: flex; align-items: center; justify-content: center; background: #fff; flex-shrink: 0;">
                            ${imgHtml}
                        </div>
                        <div>
                            <div class="fw-bold" style="color: #1e293b; font-size: 0.95rem; line-height: 1.4;">
                                ${escapeHtml(item.lazada_product_name)}
                                ${varBadge}
                            </div>
                        </div>
                    </div>
                </td>
                <td>
                    <div class="fw-600 text-dark" style="font-size: 0.9rem;">${item.lazada_item_id}</div>
                    ${item.lazada_seller_sku ? `<div class="small text-muted" style="font-size: 0.75rem;">${escapeHtml(item.lazada_seller_sku)}</div>` : ''}
                </td>
                <td>
                    <div class="fw-600 text-dark">₱${parseFloat(item.lazada_price).toFixed(2)}</div>
                </td>
                <td class="pe-4 text-end">
                    <a href="${window.BASE_URL}views/lazada/mapping.php?filter=unmapped&search=${searchParam}" class="btn btn-sm btn-primary fw-bold px-3 shadow-sm" style="background: var(--lazada-primary); border-color: var(--lazada-primary); border-radius: 6px;">
                        <i class="fa-solid fa-link me-1"></i> Resolve Mapping
                    </a>
                </td>
            </tr>
        `;
    });
    tbody.innerHTML = html;
}

function escapeHtml(unsafe) {
    if (!unsafe) return '';
    return (unsafe + '').replace(/[&<"']/g, function(m) {
        switch (m) {
            case '&': return '&amp;';
            case '<': return '&lt;';
            case '"': return '&quot;';
            default: return '&#039;';
        }
    });
}
</script>

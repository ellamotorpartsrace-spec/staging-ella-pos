<?php
$page_title = 'Lazada Sync — Stock Allocation';
require_once '../../config/config.php';
require_once '../../includes/auth.php';
requirePermission('lazada_allocation');
require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';

$db = new Database();
$conn = $db->getConnection();

$platform = $_SESSION['lazada_active_platform'] ?? 'lazada_main';

$mappedStmt = $conn->prepare("
    SELECT m.id, m.lazada_item_id, m.lazada_product_name, m.lazada_variation_name,
        m.lazada_sku_id, m.lazada_stock, m.mapping_status, m.lazada_image_url,
        m.stock_allocation_ratio, m.pos_product_id, m.pos_unit_id, m.pos_bundle_set_id,
        (COALESCE(i1.quantity,0) + COALESCE(i2.quantity,0)) as pos_qty,
        COALESCE(v.sku, m.matched_pos_sku, m.lazada_seller_sku, m.lazada_seller_sku) as sku,
        u.unit_name, u.multiplier
    FROM lazada_product_mappings m
    LEFT JOIN product_variations v ON m.pos_product_id = v.variation_id
    LEFT JOIN product_units u ON m.pos_unit_id = u.id
    LEFT JOIN inventory i1 ON v.variation_id = i1.variation_id AND i1.store_id = 1
    LEFT JOIN inventory i2 ON v.variation_id = i2.variation_id AND i2.store_id = 2
    WHERE m.platform_name = ? AND m.mapping_status IN ('auto','manual','mapped')
    ORDER BY m.lazada_product_name ASC, m.lazada_variation_name ASC
");
$mappedStmt->execute([$platform]);
$mappedRows = $mappedStmt->fetchAll(PDO::FETCH_ASSOC);

// Compute available stock for standalone bundle sets from component recipe.
$bundleSetIds = [];
foreach ($mappedRows as $r) {
    $bundleId = (int)($r['pos_bundle_set_id'] ?? 0);
    if ($bundleId > 0) {
        $bundleSetIds[$bundleId] = true;
    }
}

$bundleStockMap = [];
$bundleStockDetailsMap = [];
if (!empty($bundleSetIds)) {
    // Reserve map per component variation from existing non-bundle mapped allocations (base pieces).
    $reservedByVariation = [];
    foreach ($mappedRows as $mr) {
        $variationId = (int)($mr['pos_product_id'] ?? 0);
        $rowBundleId = (int)($mr['pos_bundle_set_id'] ?? 0);
        if ($variationId <= 0 || $rowBundleId > 0) {
            continue;
        }
        $rowMultiplier = max(1, (int)($mr['multiplier'] ?? 1));
        $rowAllocatedBase = ((int)($mr['lazada_stock'] ?? 0)) * $rowMultiplier;
        $reservedByVariation[$variationId] = ($reservedByVariation[$variationId] ?? 0) + $rowAllocatedBase;
    }

    $placeholders = implode(',', array_fill(0, count($bundleSetIds), '?'));
    $stmtBundleComp = $conn->prepare("
        SELECT
            si.product_set_id,
            si.component_qty,
            si.component_unit_id,
            si.component_variation_id,
            p.product_name AS component_product_name,
            v.variation_name AS component_variation_name,
            COALESCE(cu.multiplier, 1) AS component_unit_multiplier,
            (COALESCE(i1.quantity, 0) + COALESCE(i2.quantity, 0)) AS component_base_qty
        FROM product_unit_set_items si
        LEFT JOIN product_variations v ON v.variation_id = si.component_variation_id
        LEFT JOIN products p ON p.product_id = v.product_id
        LEFT JOIN product_units cu ON cu.id = si.component_unit_id
        LEFT JOIN inventory i1 ON i1.variation_id = si.component_variation_id AND i1.store_id = 1
        LEFT JOIN inventory i2 ON i2.variation_id = si.component_variation_id AND i2.store_id = 2
        WHERE si.product_set_id IN ($placeholders)
        ORDER BY si.product_set_id ASC
    ");
    $stmtBundleComp->execute(array_map('intval', array_keys($bundleSetIds)));
    $bundleComponents = $stmtBundleComp->fetchAll(PDO::FETCH_ASSOC);

    $componentsBySet = [];
    foreach ($bundleComponents as $c) {
        $setId = (int)$c['product_set_id'];
        $requiredBase = (float)$c['component_qty'] * max(1, (int)$c['component_unit_multiplier']);
        if ($setId <= 0 || $requiredBase <= 0) {
            continue;
        }
        $componentsBySet[$setId][] = [
            'variation_id' => (int)$c['component_variation_id'],
            'name' => trim((string)($c['component_product_name'] ?? '') . ' ' . (string)($c['component_variation_name'] ?? '')),
            'required_base' => $requiredBase,
            'stock_base' => (int)$c['component_base_qty'],
        ];
    }

    // Bundle mappings also reserve their component stock. Exclude the row being displayed
    // so Overall Stock means this listing's current allocation plus remaining pairable capacity.
    $bundleReservedByMap = [];
    foreach ($mappedRows as $mr) {
        $mapId = (int)($mr['id'] ?? 0);
        $setId = (int)($mr['pos_bundle_set_id'] ?? 0);
        $allocatedSets = (int)($mr['lazada_stock'] ?? 0);
        if ($mapId <= 0 || $setId <= 0 || $allocatedSets <= 0 || empty($componentsBySet[$setId])) {
            continue;
        }
        foreach ($componentsBySet[$setId] as $component) {
            $variationId = $component['variation_id'];
            $bundleReservedByMap[$mapId][$variationId] = ($bundleReservedByMap[$mapId][$variationId] ?? 0)
                + ($allocatedSets * $component['required_base']);
        }
    }

    foreach ($mappedRows as $mr) {
        $mapId = (int)($mr['id'] ?? 0);
        $setId = (int)($mr['pos_bundle_set_id'] ?? 0);
        if ($mapId <= 0 || $setId <= 0 || empty($componentsBySet[$setId])) {
            continue;
        }

        $minSets = null;
        $bundleDetails = [];
        foreach ($componentsBySet[$setId] as $component) {
            $variationId = $component['variation_id'];
            $reservedBase = (float)($reservedByVariation[$variationId] ?? 0);
            foreach ($bundleReservedByMap as $otherMapId => $reservedComponents) {
                if ((int)$otherMapId === $mapId) {
                    continue;
                }
                $reservedBase += (float)($reservedComponents[$variationId] ?? 0);
            }
            $freeBase = max(0, $component['stock_base'] - $reservedBase);
            $possibleSets = (int)floor($freeBase / $component['required_base']);
            $minSets = $minSets === null ? $possibleSets : min($minSets, $possibleSets);
            $bundleDetails[] = [
                'name' => $component['name'],
                'stock' => (int)$component['stock_base'],
                'reserved' => (int)$reservedBase,
                'free' => (int)$freeBase,
                'required' => (float)$component['required_base'],
                'possible' => $possibleSets,
            ];
        }
        $bundleStockMap[$mapId] = max(0, (int)($minSets ?? 0));
        $bundleStockDetailsMap[$mapId] = $bundleDetails;
    }
}

$unmappedStmt = $conn->prepare("
    SELECT id, lazada_item_id, lazada_product_name, lazada_variation_name, lazada_stock, lazada_image_url,
        COALESCE(lazada_seller_sku, lazada_seller_sku,'') as sku
    FROM lazada_product_mappings
    WHERE platform_name = ? AND mapping_status NOT IN ('auto','manual','mapped')
    ORDER BY lazada_product_name ASC, lazada_variation_name ASC
");
$unmappedStmt->execute([$platform]);
$unmappedRows = $unmappedStmt->fetchAll(PDO::FETCH_ASSOC);

// Count POS ID / SKU frequencies
$dupCounts = [];
foreach ($mappedRows as $r) {
    $posId = (int)($r['pos_product_id'] ?? 0);
    $sku = trim($r['sku'] ?? '');
    
    $bundleId = (int)($r['pos_bundle_set_id'] ?? 0);
    if ($bundleId > 0) continue;

    if ($posId > 0) {
        $key = 'pos_' . $posId;
        $dupCounts[$key] = ($dupCounts[$key] ?? 0) + 1;
    } elseif ($sku !== '') {
        $key = 'sku_' . $sku;
        $dupCounts[$key] = ($dupCounts[$key] ?? 0) + 1;
    }
}

// Map keys to their lists
$dupMap = [];
foreach ($mappedRows as $r) {
    $posId = (int)($r['pos_product_id'] ?? 0);
    $sku = trim($r['sku'] ?? '');
    
    $bundleId = (int)($r['pos_bundle_set_id'] ?? 0);
    $key = null;
    
    if ($bundleId === 0) {
        if ($posId > 0) {
            $key = 'pos_' . $posId;
        } elseif ($sku !== '') {
            $key = 'sku_' . $sku;
        }
    }
    
    if ($key !== null) {
        $multiplier = max(1, (int)($r['multiplier'] ?? 1));
        $bundleSetId = (int)($r['pos_bundle_set_id'] ?? 0);
        $isBundle = $bundleSetId > 0;
        $baseQty = $isBundle ? (int)($bundleStockMap[(int)$r['id']] ?? 0) : (int)$r['pos_qty'];
        $unitQty = floor($baseQty / $multiplier);

        $dupMap[$key][] = [
            'id' => (int)$r['id'],
            'name' => $r['lazada_product_name'],
            'varName' => $r['lazada_variation_name'] ?? '',
            'itemId' => $r['lazada_item_id'],
            'ratio' => (int)($r['stock_allocation_ratio'] ?? 100),
            'online' => (int)$r['lazada_stock'],
            'imageUrl' => $r['lazada_image_url'] ?? '',
            'unitName' => $isBundle ? 'Bundle Set' : ($r['unit_name'] ?? null),
            'multiplier' => $multiplier,
            'isBundle' => $isBundle,
            'total' => $baseQty,
            'unitTotal' => $unitQty
        ];
    }
}

// Group mapped rows
$mappedGroups = [];
foreach ($mappedRows as $r) {
    $iid = $r['lazada_item_id'];
    if (!isset($mappedGroups[$iid])) $mappedGroups[$iid] = ['itemId'=>$iid,'name'=>$r['lazada_product_name'],'imageUrl'=>$r['lazada_image_url']??'','vars'=>[]];
    
    $posId = (int)($r['pos_product_id'] ?? 0);
    $sku = trim($r['sku'] ?? '');
    
    $bundleId = (int)($r['pos_bundle_set_id'] ?? 0);
    $key = null;
    if ($bundleId === 0) {
        if ($posId > 0) {
            $key = 'pos_' . $posId;
        } elseif ($sku !== '') {
            $key = 'sku_' . $sku;
        }
    }
    
    $isDup = ($key !== null && ($dupCounts[$key] ?? 0) > 1);
    
    $dupDetails = [];
    if ($isDup && isset($dupMap[$key])) {
        foreach ($dupMap[$key] as $other) {
            if ($other['id'] !== (int)$r['id']) {
                $dupDetails[] = $other;
            }
        }
    }
    
    $multiplier = isset($r['multiplier']) ? (int)$r['multiplier'] : 1;
    if ($multiplier <= 0) $multiplier = 1;
    $bundleSetId = (int)($r['pos_bundle_set_id'] ?? 0);
    $isBundle = $bundleSetId > 0;
    $baseQty = $isBundle ? (int)($bundleStockMap[(int)$r['id']] ?? 0) : (int)$r['pos_qty'];
    $unitQty = floor($baseQty / $multiplier);

    $availableStock = (int)$r['lazada_stock'];
    $st = $r['lazada_stock']==0?'unallocated':($availableStock<=5?'low':'synced');
    $mappedGroups[$iid]['vars'][] = [
        'id'=>(int)$r['id'],'varName'=>$r['lazada_variation_name']??'','sku'=>$r['sku']??'',
        'total'=>$baseQty,'unitTotal'=>$unitQty,'online'=>(int)$r['lazada_stock'],'status'=>$st,
        'itemId'=>(int)$r['lazada_item_id'],'modelId'=>$r['lazada_sku_id'],
        'ratio'=>(int)($r['stock_allocation_ratio'] ?? 100),
        'isDuplicate'=>$isDup,
        'dupDetails'=>$dupDetails,
        'mappingStatus'=>$r['mapping_status'],
        'unitName'=>$isBundle ? 'Bundle Set' : ($r['unit_name'] ?? null),
        'multiplier'=>$multiplier,
        'isBundle'=>$isBundle,
        'bundleDetails'=>$isBundle ? ($bundleStockDetailsMap[(int)$r['id']] ?? []) : []
    ];
}

// Group unmapped rows
$unmappedGroups = [];
foreach ($unmappedRows as $r) {
    $iid = $r['lazada_item_id'];
    if (!isset($unmappedGroups[$iid])) $unmappedGroups[$iid] = ['itemId'=>$iid,'name'=>$r['lazada_product_name'],'imageUrl'=>$r['lazada_image_url']??'','vars'=>[]];
    $unmappedGroups[$iid]['vars'][] = ['id'=>(int)$r['id'],'varName'=>$r['lazada_variation_name']??'','sku'=>$r['sku'],'online'=>(int)$r['lazada_stock']];
}

$totalMapped   = count($mappedRows);
$totalUnmapped = count($unmappedRows);
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/lazada-sync.css?v=<?= filemtime(__DIR__.'/../../assets/css/lazada-sync.css') ?>">
<style>
/* ── Tab Buttons ── */
.lz-tab-btn{padding:.45rem 1.1rem;border-radius:var(--lz-radius-sm);border:1.5px solid var(--border-color);background:transparent;font-weight:600;font-size:.82rem;color:var(--text-secondary);cursor:pointer;transition:all .2s;}
.lz-tab-btn.active{background:var(--lazada-gradient);color:#fff;border-color:transparent;box-shadow:0 2px 8px rgba(15,19,109,.25);}
.lz-tab-btn:not(.active):hover{border-color:var(--lazada-primary);color:var(--lazada-primary);}
.bg-lazada{background:var(--lazada-gradient) !important;}
.text-lazada{color:var(--lazada-primary) !important;}
.btn-lazada{background:var(--lazada-gradient) !important;border:none !important;color:#fff !important;transition:all .3s ease;}
.btn-lazada:hover{background:var(--lazada-gradient) !important;opacity:0.9;box-shadow:0 4px 12px rgba(15,19,109,0.35);color:#fff !important;}
.btn-outline-lazada-secondary{border-color:rgba(15,19,109,0.2) !important;color:var(--text-secondary) !important;background:transparent;}
.btn-outline-lazada-secondary:hover{border-color:var(--lazada-primary) !important;color:var(--lazada-primary) !important;}
.btn-light:hover{background-color:var(--border-color) !important;}



/* ── Enhanced Table Column Segments ── */
.lz-table th.col-pos { background: var(--bg-surface); }
.lz-table th.col-lazada { background: linear-gradient(180deg, rgba(15,19,109,.06) 0%, var(--bg-surface) 100%); }
.lz-table th.col-reserved { background: linear-gradient(180deg, rgba(245,158,11,.06) 0%, var(--bg-surface) 100%); }
.lz-table th.col-available { background: linear-gradient(180deg, rgba(16,185,129,.06) 0%, var(--bg-surface) 100%); }
.lz-table th.col-sep-left { border-left: 2px solid rgba(15,19,109,.15); }
.lz-table td.col-sep-left { border-left: 2px solid rgba(15,19,109,.08); }

/* ── Allocation Progress Bar ── */
.alloc-progress-wrap { width:100%; max-width:80px; margin:3px auto 0; }
.alloc-progress { height:4px; border-radius:2px; background:var(--lz-neutral-bg); overflow:hidden; }
.alloc-progress-fill { height:100%; border-radius:2px; transition: width .4s ease; }
.alloc-progress-fill.ratio-full { background: linear-gradient(90deg, #10b981, #34d399); }
.alloc-progress-fill.ratio-mid { background: linear-gradient(90deg, #3b82f6, #60a5fa); }
.alloc-progress-fill.ratio-low { background: linear-gradient(90deg, #f59e0b, #fbbf24); }

/* ── Live Sync Button ── */
.btn-live-sync {
    width:30px; height:30px; border-radius:50%; border:1.5px solid var(--border-color);
    background:transparent; color:var(--text-secondary); display:inline-flex;
    align-items:center; justify-content:center; cursor:pointer;
    transition:all .25s; font-size:.72rem;
}
.btn-live-sync:hover {
    border-color:var(--lz-info); color:var(--lz-info);
    background:var(--lz-info-bg); transform:rotate(30deg);
}
.btn-live-sync:disabled { opacity:.4; cursor:not-allowed; transform:none; }
.btn-live-sync .fa-spin { animation-duration:.6s; }

/* ── Enhanced Variation Tree ── */
.lz-table .var-indent {
    padding-left: 3.5rem !important; position:relative;
}
.lz-table .var-indent::before {
    content:''; position:absolute; left:1.8rem; top:0; bottom:0; width:1.5px;
    background: linear-gradient(180deg, rgba(15,19,109,.25) 0%, rgba(15,19,109,.05) 100%);
}
.lz-table .var-indent::after {
    content:''; position:absolute; left:1.8rem; top:50%; width:.8rem; height:1.5px;
    background: rgba(15,19,109,.25);
}
.var-connector-icon { color:var(--lazada-primary); font-size:.55rem; margin-right:.4rem; opacity:.6; }

/* ── Available to Sell Cell ── */
.avail-cell { font-weight:700; font-size:.9rem; }
.avail-cell.avail-good { color:var(--lz-success); }
.avail-cell.avail-low { color:var(--lz-warning); }
.avail-cell.avail-zero { color:var(--lz-danger); }

/* ── Status Badge Enhancements ── */
.status-chip {
    display:inline-flex; align-items:center; gap:.3rem;
    padding:.25rem .65rem; border-radius:999px;
    font-size:.68rem; font-weight:700; white-space:nowrap;
    letter-spacing:.01em;
}
.status-chip.s-ok { background:var(--lz-success-bg); color:var(--lz-success); }
.status-chip.s-low { background:var(--lz-warning-bg); color:var(--lz-warning); }
.status-chip.s-out { background:var(--lz-danger-bg); color:var(--lz-danger); }
.status-chip.s-none { background:var(--lz-neutral-bg); color:var(--lz-neutral-text); }

/* ── Row entry animation ── */
@keyframes rowSlideIn { from { opacity:0; transform:translateX(-6px); } to { opacity:1; transform:translateX(0); } }
.lz-table tbody tr { animation: rowSlideIn .25s ease-out forwards; }

/* ── Reserved cell chip ── */
.reserved-chip {
    display:inline-flex; align-items:center; gap:.25rem;
    padding:.15rem .5rem; border-radius:999px;
    font-size:.72rem; font-weight:700;
    background:var(--lz-warning-bg); color:var(--lz-warning);
}
.reserved-chip.zero { background:var(--lz-neutral-bg); color:var(--lz-neutral-text); }

/* ── Premium Stock Summary Cards in Modal ── */
.stock-summary-card {
    background: var(--bg-surface) !important;
    border: 1px solid var(--border-color) !important;
    border-radius: 12px !important;
    transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
    box-shadow: 0 2px 8px rgba(0,0,0,0.02) !important;
}
.stock-summary-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.05) !important;
}
.stock-summary-card.allocated-card {
    border-color: rgba(15,19,109,0.18) !important;
    background: linear-gradient(135deg, var(--bg-surface) 0%, rgba(15,19,109,0.02) 100%) !important;
}
.stock-summary-card.allocated-card:hover {
    border-color: rgba(15,19,109,0.3) !important;
    box-shadow: 0 4px 12px rgba(15,19,109,0.06) !important;
}
.stock-summary-card.remaining-card {
    border-color: rgba(16,185,129,0.18) !important;
    background: linear-gradient(135deg, var(--bg-surface) 0%, rgba(16,185,129,0.02) 100%) !important;
}
.stock-summary-card.remaining-card:hover {
    border-color: rgba(16,185,129,0.3) !important;
    box-shadow: 0 4px 12px rgba(16,185,129,0.06) !important;
}
.stock-card-label {
    font-size: 0.68rem !important;
    font-weight: 700 !important;
    letter-spacing: 0.06em !important;
}
.stock-card-number {
    font-size: 1.6rem !important;
    line-height: 1.15 !important;
    letter-spacing: -0.03em !important;
}
.stock-card-sub {
    font-size: 0.65rem !important;
    opacity: 0.75 !important;
    margin-top: 3px !important;
    font-weight: 500 !important;
}
.lz-qty-control.border-lazada {
    border-color: rgba(15,19,109,0.25) !important;
    transition: all 0.2s ease;
}
.lz-qty-control.border-lazada:focus-within {
    border-color: var(--lazada-primary) !important;
    box-shadow: 0 0 0 3px rgba(15,19,109,0.1) !important;
}
.lz-qty-btn {
    transition: background-color 0.2s, opacity 0.2s !important;
}
.lz-qty-btn:hover {
    background-color: rgba(15,19,109,0.08) !important;
}
.lz-qty-btn:active {
    opacity: 0.75;
}
.lz-qty-input::-webkit-outer-spin-button,
.lz-qty-input::-webkit-inner-spin-button {
    -webkit-appearance: none;
    margin: 0;
}
.lz-qty-input {
    -moz-appearance: textfield;
}
</style>

<div class="lz-page">
    <?php require_once __DIR__ . '/lazada_token_warning.php'; ?>
    <!-- Hero Header -->
    <div class="mb-4" style="background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%); border-radius: 16px; padding: 2rem 2.5rem; box-shadow: 0 10px 30px rgba(30,58,138,0.15); position: relative; overflow: hidden;">
        <!-- Breadcrumb inside -->
        <nav aria-label="breadcrumb" style="position: relative; z-index: 2;">
            <ol class="breadcrumb mb-3" style="font-size: 0.85rem;">
                <li class="breadcrumb-item"><a href="<?= BASE_URL ?>views/lazada/index.php" class="text-white text-decoration-none fw-bold px-2 py-1 rounded" style="background: rgba(255, 255, 255, 0.2); transition: background 0.2s;" onmouseover="this.style.background='rgba(255, 255, 255, 0.3)'" onmouseout="this.style.background='rgba(255, 255, 255, 0.2)'"><i class="fa-solid fa-arrow-left me-1"></i> Lazada Dashboard</a></li>
                <li class="breadcrumb-item active" style="color: white; font-weight: 600;">Stock Allocation</li>
            </ol>
        </nav>
        
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3" style="position: relative; z-index: 2;">
            <div class="d-flex align-items-center gap-3">
                <div style="background: white; border-radius: 14px; width: 64px; height: 64px; display: flex; align-items: center; justify-content: center; box-shadow: 0 4px 15px rgba(0,0,0,0.1);">
                    <i class="fa-solid fa-sliders" style="color: #2563eb; font-size: 1.8rem;"></i>
                </div>
                <div>
                    <h1 class="mb-1 fw-bolder" style="font-size: 2rem; letter-spacing: -0.5px; color: white;">Stock Allocation</h1>
                    <p class="mb-0" style="color: rgba(255,255,255,0.8); font-size: 0.95rem;">Allocate POS inventory to Lazada per variation. Only mapped products sync stock to Lazada.</p>
                </div>
            </div>
            <div class="d-flex gap-2 align-items-center">
                <button class="btn btn-light fw-bold px-4 rounded-pill d-flex align-items-center" id="btnManualSync" onclick="triggerManualSync()" style="color: #2563eb; height: 42px; box-shadow: 0 4px 10px rgba(0,0,0,0.15);">
                    <i class="fa-solid fa-rotate me-2"></i> <span id="syncText">Sync Stock</span>
                </button>
            </div>
        </div>
        <!-- Decorative bg -->
        <div style="position: absolute; top: -50px; right: -50px; width: 300px; height: 300px; background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, rgba(255,255,255,0) 70%); border-radius: 50%; z-index: 1;"></div>
    </div>

<!-- KPI Cards -->
<div class="row g-3 mb-4">
    <div class="col-md-3 col-6">
        <div class="lz-stat-card accent-success">
            <div class="lz-stat-icon" style="background:var(--lz-success-bg);color:var(--lz-success)"><i class="fa-solid fa-check-circle"></i></div>
            <div><div class="lz-stat-label">Allocated</div><div class="lz-stat-value" id="sumAllocated">0</div></div>
        </div>
    </div>
    <div class="col-md-3 col-6">
        <div class="lz-stat-card accent-warning">
            <div class="lz-stat-icon" style="background:var(--lz-warning-bg);color:var(--lz-warning)"><i class="fa-solid fa-triangle-exclamation"></i></div>
            <div><div class="lz-stat-label">Low Stock</div><div class="lz-stat-value" id="sumLowStock">0</div></div>
        </div>
    </div>
    <div class="col-md-3 col-6">
        <div class="lz-stat-card" style="border-left: 3px solid var(--lz-neutral-text);">
            <div class="lz-stat-icon" style="background:var(--lz-neutral-bg);color:var(--lz-neutral-text)"><i class="fa-solid fa-minus-circle"></i></div>
            <div><div class="lz-stat-label">Unallocated</div><div class="lz-stat-value" id="sumUnallocated">0</div></div>
        </div>
    </div>
    <div class="col-md-3 col-6">
        <div class="lz-stat-card accent-danger">
            <div class="lz-stat-icon" style="background:var(--lz-danger-bg);color:var(--lz-danger)"><i class="fa-solid fa-arrow-trend-up"></i></div>
            <div><div class="lz-stat-label">Overallocated</div><div class="lz-stat-value" id="sumOverallocated">0</div></div>
        </div>
    </div>
</div>

<!-- Tab Toggle -->
<div class="d-flex align-items-center gap-2 mb-3">
    <button class="lz-tab-btn active" onclick="switchTab('mapped',this)">
        <i class="fa-solid fa-link me-1"></i>Mapped <span class="lz-badge lz-badge-success ms-1"><?= $totalMapped ?></span>
    </button>
    <button class="lz-tab-btn" onclick="switchTab('unmapped',this)">
        <i class="fa-solid fa-link-slash me-1"></i>Unmapped <span class="lz-badge lz-badge-neutral ms-1"><?= $totalUnmapped ?></span>
    </button>
</div>

<!-- Mapped Section -->
<div id="mappedSection">
    <!-- Global Filters -->
    <div class="lz-card mb-4" id="globalFilterCard">
        <div class="lz-card-body d-flex flex-wrap align-items-center gap-3" style="padding:.85rem 1.25rem">
            <div class="lz-search flex-grow-1" style="max-width:340px">
                <i class="fa-solid fa-search"></i>
                <input type="text" id="allocSearch" autocomplete="off" placeholder="Search by name or SKU..." oninput="debouncedRender()">
            </div>
            
            <div class="lz-filter-pills">
                <button class="lz-pill active" onclick="setAllocFilter('all',this)"><i class="fa-solid fa-list me-1"></i>All</button>
                <button class="lz-pill" onclick="setAllocFilter('manual',this)"><i class="fa-solid fa-hand-pointer me-1 text-info"></i>Manually Mapped</button>
                <button class="lz-pill" onclick="setAllocFilter('duplicate',this)"><i class="fa-solid fa-clone me-1 text-danger"></i>Shared SKUs</button>
                <button class="lz-pill" onclick="setAllocFilter('synced',this)"><i class="fa-solid fa-check-circle me-1 text-success"></i>Allocated</button>
                <button class="lz-pill" onclick="setAllocFilter('low',this)"><i class="fa-solid fa-triangle-exclamation me-1 text-warning"></i>Low Stock</button>
                <button class="lz-pill" onclick="setAllocFilter('unallocated',this)"><i class="fa-solid fa-minus-circle me-1 text-secondary"></i>Unallocated</button>
                <button class="lz-pill" onclick="setAllocFilter('overallocated',this)"><i class="fa-solid fa-arrow-trend-up me-1 text-danger"></i>Overallocated</button>
            </div>
        </div>
    </div>
    <div class="lz-card">
        <div class="lz-card-body p-0 lz-table-wrap">
            <table class="lz-table">
                <thead>
                    <tr>
                        <th style="width: auto">Product / Variation</th>
                        <th style="width: 1%; white-space: nowrap;">Parent SKU</th>
                        <th style="width: 1%; white-space: nowrap;">Variation SKU</th>
                        <th class="text-center" style="width: 1%; white-space: nowrap;">Overall Stock</th>
                        <th class="text-center" style="width: 1%; white-space: nowrap;">Allocated</th>
                        <th class="text-center" style="width: 1%; white-space: nowrap;">Current Stock</th>
                        <th class="text-center" style="width: 1%; white-space: nowrap;">Status</th>
                        <th class="text-end" style="width: 1%; white-space: nowrap;">Action</th>
                    </tr>
                </thead>
                <tbody id="allocBody"></tbody>
            </table>
        </div>
        <!-- Premium Pagination Footer (Mapped) -->
        <div class="lz-card-footer d-flex align-items-center justify-content-between flex-wrap gap-3 border-top p-3" id="mappedPaginationFooter">
            <div class="d-flex align-items-center gap-2">
                <span class="text-secondary small">Items per page:</span>
                <select class="form-select form-select-sm" id="itemsPerPageSelect" onchange="changeItemsPerPage(this.value)" style="width: auto;">
                    <option value="10">10</option>
                    <option value="25" selected>25</option>
                    <option value="50">50</option>
                    <option value="100">100</option>
                </select>
            </div>
            <div class="text-secondary small fw-bold" id="paginationStatus">Page 1 of 1 (0 items)</div>
            <div class="d-flex align-items-center gap-1" id="paginationButtons"></div>
        </div>
    </div>
</div>

<!-- Unmapped Table -->
<div id="unmappedSection" style="display:none">
    <div class="lz-card">
        <div class="lz-card-body p-0 lz-table-wrap">
            <table class="lz-table">
                <thead>
                    <tr>
                        <th style="width: auto">Product / Variation</th>
                        <th style="width: 1%; white-space: nowrap;">Parent SKU</th>
                        <th style="width: 1%; white-space: nowrap;">Variation SKU</th>
                        <th class="text-center" style="width: 1%; white-space: nowrap;">Current Lazada Stock</th>
                        <th class="text-end" style="width: 1%; white-space: nowrap;">Action</th>
                    </tr>
                </thead>
                <tbody id="unmappedBody"></tbody>
            </table>
        </div>
        <!-- Premium Pagination Footer (Unmapped) -->
        <div class="lz-card-footer d-flex align-items-center justify-content-between flex-wrap gap-3 border-top p-3" id="unmappedPaginationFooter">
            <div class="d-flex align-items-center gap-2">
                <span class="text-secondary small">Items per page:</span>
                <select class="form-select form-select-sm" id="unmappedItemsPerPageSelect" onchange="changeUnmappedItemsPerPage(this.value)" style="width: auto;">
                    <option value="10">10</option>
                    <option value="25" selected>25</option>
                    <option value="50">50</option>
                    <option value="100">100</option>
                </select>
            </div>
            <div class="text-secondary small fw-bold" id="unmappedPaginationStatus">Page 1 of 1 (0 items)</div>
            <div class="d-flex align-items-center gap-1" id="unmappedPaginationButtons"></div>
        </div>
    </div>
</div>
</div>

<!-- Edit Modal -->
<div class="modal fade lz-modal" id="editModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered" id="editModalDialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold"><i class="fa-solid fa-sliders text-lazada me-2"></i>Set Lazada Stock Allocation</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" style="filter:var(--btn-close-filter)"></button>
            </div>
            <div class="modal-body" style="padding: 1.25rem 1.5rem;">
                <div class="mb-3 pb-2.5 border-bottom" style="border-color: rgba(0,0,0,0.06) !important;">
                    <div class="fw-bold text-dark fs-6 lh-sm mb-1" id="mName"></div>
                    <div class="text-secondary small d-flex align-items-center gap-1.5" style="font-size: 0.72rem;">
                        <span>SKU:</span><span id="mSku" class="lz-sku-code">—</span>
                    </div>
                </div>
                
                <div class="row g-4">
                    <!-- Main Column -->
                    <div class="col-12" id="modalMainCol">
                        <div class="d-flex align-items-stretch justify-content-between gap-2.5 mb-2">
                            <!-- Overall Stock Card -->
                            <div class="stock-summary-card flex-fill text-center p-3 rounded-3 border bg-light shadow-xs position-relative" style="border-color: rgba(0,0,0,0.06) !important; min-width: 0;">
                                <div class="stock-card-label text-uppercase text-secondary mb-1" style="font-size: 0.68rem; letter-spacing: 0.05em; font-weight: 700;">Overall Stock</div>
                                <div class="stock-card-number text-dark fw-extrabold" id="mTotal" style="font-size: 2.2rem; line-height: 1.1; font-weight: 800 !important;">0</div>
                                <div class="stock-card-sub text-muted" id="mTotalSub" style="font-size: 0.65rem; font-weight: 500;">Physical POS</div>
                            </div>
                            
                            <!-- Sleek Connection indicator -->
                            <div class="d-flex align-items-center justify-content-center text-muted px-0.5">
                                <i class="fa-solid fa-chevron-right opacity-30 fs-6"></i>
                            </div>
                            
                            <!-- Lazada Allocation Card -->
                            <div class="stock-summary-card flex-fill text-center p-3 rounded-3 border shadow-xs position-relative allocated-card" style="min-width: 0;">
                                <div class="stock-card-label text-uppercase text-lazada mb-1" style="font-size: 0.68rem; letter-spacing: 0.05em; font-weight: 700;"><i class="fa-brands fa-lazada me-1"></i>Allocated</div>
                                <div class="d-flex align-items-center justify-content-center my-1.5">
                                    <div class="lz-qty-control rounded bg-white border border-lazada d-flex align-items-center justify-content-between overflow-hidden" style="width: 110px; height: 32px;">
                                        <button class="lz-qty-btn text-lazada border-0 px-2.5 h-100" style="background: var(--lazada-light); font-size: 0.8rem;" onclick="adjStock(-1)"><i class="fa-solid fa-minus"></i></button>
                                        <input type="number" class="lz-qty-input fw-bold text-lazada border-0 text-center flex-fill h-100" style="font-size: 1.15rem; width: 40px; background: transparent; padding: 0;" id="mOnlineStock" value="0" min="0" oninput="calcModal()">
                                        <button class="lz-qty-btn text-lazada border-0 px-2.5 h-100" style="background: var(--lazada-light); font-size: 0.8rem;" onclick="adjStock(1)"><i class="fa-solid fa-plus"></i></button>
                                    </div>
                                </div>
                                <div class="stock-card-sub text-muted" style="font-size: 0.65rem; font-weight: 500;">On Lazada</div>
                            </div>
                            
                            <!-- Sleek Connection indicator -->
                            <div class="d-flex align-items-center justify-content-center text-muted px-0.5">
                                <i class="fa-solid fa-chevron-right opacity-30 fs-6"></i>
                            </div>
                            
                            <!-- Current POS Stock Card -->
                            <div class="stock-summary-card flex-fill text-center p-3 rounded-3 border bg-light shadow-xs position-relative remaining-card" style="min-width: 0;">
                                <div class="stock-card-label text-uppercase text-success mb-1" style="font-size: 0.68rem; letter-spacing: 0.05em; font-weight: 700;">POS Remaining</div>
                                <div class="stock-card-number text-success fw-extrabold" id="mRemainingCalc" style="font-size: 2.2rem; line-height: 1.1; font-weight: 800 !important; margin-top: 2px;">0</div>
                                <div class="stock-card-sub text-muted" style="font-size: 0.65rem; font-weight: 500; margin-top: 2px;">Stays in Store</div>
                            </div>
                        </div>

                        <!-- Quick Splits & Info -->
                        <div class="d-flex justify-content-between align-items-center mb-1 mt-3.5">
                            <div class="d-flex align-items-center gap-2">
                                <span class="text-uppercase text-secondary fw-bold" style="font-size: 0.72rem; letter-spacing: 0.05em;"><i class="fa-solid fa-bolt text-warning me-1"></i>Quick Splits:</span>
                                <div class="d-flex gap-2" style="gap: 6px;">
                                    <button class="btn btn-xs rounded-pill px-3 py-1 fw-bold shadow-none" style="background: var(--lazada-light); color: var(--lazada-primary); border: 1px solid rgba(15,19,109,0.15); transition: all 0.2s; font-size: 0.75rem; line-height: 1;" onclick="presetStock(0)">0%</button>
                                    <button class="btn btn-xs rounded-pill px-3 py-1 fw-bold shadow-none" style="background: var(--lazada-light); color: var(--lazada-primary); border: 1px solid rgba(15,19,109,0.15); transition: all 0.2s; font-size: 0.75rem; line-height: 1;" onclick="presetStock(10)">10%</button>
                                    <button class="btn btn-xs rounded-pill px-3 py-1 fw-bold shadow-none" style="background: var(--lazada-light); color: var(--lazada-primary); border: 1px solid rgba(15,19,109,0.15); transition: all 0.2s; font-size: 0.75rem; line-height: 1;" onclick="presetStock(25)">25%</button>
                                    <button class="btn btn-xs rounded-pill px-3 py-1 fw-bold shadow-none" style="background: var(--lazada-light); color: var(--lazada-primary); border: 1px solid rgba(15,19,109,0.15); transition: all 0.2s; font-size: 0.75rem; line-height: 1;" onclick="presetStock(50)">50%</button>
                                    <button class="btn btn-xs rounded-pill px-3 py-1 fw-bold shadow-none" style="background: var(--lazada-light); color: var(--lazada-primary); border: 1px solid rgba(15,19,109,0.15); transition: all 0.2s; font-size: 0.75rem; line-height: 1;" onclick="presetStock(75)">75%</button>
                                </div>
                            </div>
                            <div class="text-secondary d-flex align-items-center bg-light px-3 py-1 rounded-pill border" style="font-size: 0.75rem; font-weight: 500;">
                                <i class="fa-solid fa-info-circle me-1.5 text-primary" style="font-size: 0.82rem;"></i>Remaining stays in POS.
                            </div>
                        </div>

                        <!-- Plain-Language Stock Distribution Summary -->
                        <div class="mt-4 p-3 rounded-3 border bg-white shadow-xs" style="border-color: rgba(0,0,0,0.06) !important;">
                            <div class="d-flex justify-content-between align-items-center mb-2.5">
                                <span class="fw-bold text-uppercase text-dark" style="font-size: 0.78rem; letter-spacing: 0.05em; font-weight: 700;"><i class="fa-solid fa-chart-pie me-1 text-primary"></i>Stock Distribution Summary</span>
                                <span id="mRatioText" class="badge bg-light text-lazada border fw-bold" style="font-size: 0.78rem; padding: 0.3rem 0.6rem;">0% on Lazada</span>
                            </div>
                            
                            <!-- Visual Progress Bar -->
                            <div class="progress rounded-pill overflow-hidden bg-light border mb-3.5" style="height: 12px;">
                                <div id="mBarLazada" class="progress-bar bg-lazada progress-bar-striped progress-bar-animated" role="progressbar" style="width: 0%;" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100"></div>
                                <div id="mBarPos" class="progress-bar bg-success" role="progressbar" style="width: 0%;" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100"></div>
                            </div>
                            
                            <!-- Plain Language Explanations -->
                            <div class="d-flex flex-column gap-2.5" style="font-size: 0.85rem; line-height: 1.4;">
                                <!-- Lazada Statement -->
                                <div class="d-flex align-items-center gap-2">
                                    <div class="rounded-circle bg-lazada d-flex align-items-center justify-content-center text-white flex-shrink-0" style="width: 22px; height: 22px; font-size: 0.7rem;">
                                        <i class="fa-brands fa-lazada"></i>
                                    </div>
                                    <div class="text-dark">
                                        <strong id="mRatioLabelLazada" class="text-dark" style="font-size: 0.95rem;">0 items</strong> will be listed on your <strong>Lazada store</strong> for online buyers.
                                    </div>
                                </div>
                                
                                <!-- POS Statement -->
                                <div class="d-flex align-items-center gap-2">
                                    <div class="rounded-circle bg-success d-flex align-items-center justify-content-center text-white flex-shrink-0" style="width: 22px; height: 22px; font-size: 0.7rem;">
                                        <i class="fa-solid fa-store"></i>
                                    </div>
                                    <div class="text-dark">
                                        <strong id="mRatioLabelPos" class="text-dark" style="font-size: 0.95rem;">0 items</strong> will stay in your <strong>Physical POS</strong> for walk-in customers.
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div id="mWarning" class="d-none alert py-2 small mt-3 mb-0 shadow-sm rounded-3"></div>
                    </div>
                    
                    <!-- Shared Details Column -->
                    <div class="col-md-6 d-none" id="mSharedDetailsWrapper">
                        <div id="mSharedDetailsContainer" class="h-100"></div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-lazada" onclick="saveAllocation(this)"><i class="fa-solid fa-check me-2"></i>Save & Sync</button>
            </div>
        </div>
    </div>
</div>




<script>
const MAPPED_GROUPS   = <?= json_encode(array_values($mappedGroups)) ?>;
const UNMAPPED_GROUPS = <?= json_encode(array_values($unmappedGroups)) ?>;

// Flat list for easier lookup
const MAPPED_FLAT = [];
MAPPED_GROUPS.forEach(g => g.vars.forEach(v => {
    v.groupName = g.name;
    MAPPED_FLAT.push(v);
}));

let currentEdit=null, editModal, allocFilter='all';
let currentPage = 1;
let itemsPerPage = 25;
let unmappedCurrentPage = 1;
let unmappedItemsPerPage = 25;
let sessionChangedCount = 0;

function escHtml(s){if(!s)return'';return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}
function searchTerms(q) {
    return String(q || '').toLowerCase().trim().split(/\s+/).filter(Boolean);
}
function progressiveMatch(terms, fields) {
    if (!terms.length) return true;
    const haystack = fields.map(v => String(v || '').toLowerCase());
    return terms.every(term => haystack.some(field => field.includes(term)));
}

function unitMultiplier(item) {
    return Math.max(1, parseInt(item?.multiplier, 10) || 1);
}
function unitLimit(item) {
    const explicit = parseInt(item?.unitTotal, 10);
    if (!Number.isNaN(explicit) && explicit >= 0) return explicit;
    return Math.floor((parseInt(item?.total, 10) || 0) / unitMultiplier(item));
}
function toBaseQty(qty, item) {
    return (parseInt(qty, 10) || 0) * unitMultiplier(item);
}
function allocationUnitLabel(item) {
    return item?.unitName ? item.unitName : 'item';
}
function formatAllocatedLabel(units, baseQty, item) {
    if (unitMultiplier(item) <= 1) return `${baseQty} item${baseQty === 1 ? '' : 's'}`;
    return `${units} ${allocationUnitLabel(item)}${units === 1 ? '' : 's'} / ${baseQty} pcs`;
}
function bundleFormulaHtml(item) {
    if (!item?.isBundle || !Array.isArray(item.bundleDetails) || item.bundleDetails.length === 0) return '';
    const rows = item.bundleDetails.map(d => {
        const required = Number(d.required || 0);
        const requiredLabel = Number.isInteger(required) ? required : required.toFixed(4).replace(/0+$/, '').replace(/\.$/, '');
        return `<div style="font-size:0.75rem; color:#475569; margin-bottom: 8px; text-align: left;">
            <div style="font-weight:600; color:#1e293b; margin-bottom: 3px;"><i class="fa-solid fa-caret-right text-secondary me-1"></i>${escHtml(d.name || 'Component')}</div>
            <div style="display:flex; justify-content:space-between;"><span>Stock:</span> <span class="fw-semibold">${parseInt(d.stock || 0, 10).toLocaleString()}</span></div>
            <div style="display:flex; justify-content:space-between;"><span>Reserved:</span> <span class="text-danger">-${parseInt(d.reserved || 0, 10).toLocaleString()}</span></div>
            <div style="display:flex; justify-content:space-between; border-top:1px dashed #cbd5e1; margin-top:2px; padding-top:2px;">
                <span class="fw-semibold text-success">Free:</span> <span class="fw-bold text-success">${parseInt(d.free || 0, 10).toLocaleString()}</span>
            </div>
            <div style="display:flex; justify-content:space-between; color:#64748b; font-size:0.7rem; margin-top:2px;">
                <span>Per bundle:</span> <span>&divide; ${requiredLabel}</span>
            </div>
            <div style="display:flex; justify-content:space-between; border-top:1px solid #cbd5e1; margin-top:2px; padding-top:2px; font-weight:700;">
                <span class="text-dark">Pairable sets:</span> <span class="text-lazada">${parseInt(d.possible || 0, 10).toLocaleString()}</span>
            </div>
        </div>`;
    }).join('');
    
    let popContent = `<div style="text-align:left;word-break:break-word;line-height:1.3;min-width:220px;max-width:280px;">
        <div style="font-weight:700;color:#1e293b;margin-bottom:8px;padding-bottom:6px;border-bottom:1px solid rgba(0,0,0,0.1);"><i class="fa-solid fa-calculator text-lazada me-1"></i> Component Breakdown</div>
        ${rows}
        <div class="small fw-bold text-success mt-2 text-center" style="border-top:1px solid rgba(0,0,0,0.15);padding:6px;background:rgba(25,135,84,0.06);border-radius:4px;">
            Total Sellable = ${parseInt(item.total || 0, 10).toLocaleString()} sets
        </div>
    </div>`;
    popContent = popContent.replace(/"/g, '&quot;');
    
    const popAttr = `tabindex="0" data-bs-container="body" data-bs-toggle="popover" data-bs-placement="right" data-bs-trigger="hover focus" data-bs-custom-class="lazada-popover" title="" data-bs-content="${popContent}"`;
    return `<button type="button" class="btn btn-link text-info text-decoration-none shadow-none p-0 ms-2 align-baseline" style="font-size:0.75rem;" ${popAttr}><i class="fa-solid fa-circle-info"></i> Details</button>`;
}
function sharedItemById(id) {
    if (!currentEdit || !currentEdit.dupDetails) return null;
    return currentEdit.dupDetails.find(d => parseInt(d.id, 10) === parseInt(id, 10)) || null;
}
function getSharedInputItem(inp) {
    return sharedItemById(inp.dataset.id) || currentEdit;
}
function getTotalAllocatedBaseFromModal() {
    let totalBase = toBaseQty(document.getElementById('mOnlineStock')?.value || 0, currentEdit);
    const sharedInputs = document.querySelectorAll('.shared-alloc-input');
    if (sharedInputs.length > 0) {
        sharedInputs.forEach(inp => {
            totalBase += toBaseQty(inp.value, getSharedInputItem(inp));
        });
    } else if (currentEdit?.dupDetails) {
        currentEdit.dupDetails.forEach(d => {
            totalBase += toBaseQty(d.online, d);
        });
    }
    return totalBase;
}
function getTotalAllocatedUnitsFromModal() {
    let totalUnits = parseInt(document.getElementById('mOnlineStock')?.value, 10) || 0;
    const sharedInputs = document.querySelectorAll('.shared-alloc-input');
    if (sharedInputs.length > 0) {
        sharedInputs.forEach(inp => totalUnits += (parseInt(inp.value, 10) || 0));
    } else if (currentEdit?.dupDetails) {
        currentEdit.dupDetails.forEach(d => totalUnits += (parseInt(d.online, 10) || 0));
    }
    return totalUnits;
}
function getExistingAllocatedBase(item) {
    let totalBase = toBaseQty(item.online, item);
    if (item.isDuplicate && item.dupDetails) {
        item.dupDetails.forEach(d => totalBase += toBaseQty(d.online, d));
    }
    return totalBase;
}

let renderTimeout = null;

function saveState() {
    const searchEl = document.getElementById('allocSearch');
    const state = {
        allocFilter,
        currentPage,
        itemsPerPage,
        unmappedCurrentPage,
        unmappedItemsPerPage,
        search: searchEl ? searchEl.value : '',
        activeTab: document.getElementById('unmappedSection').style.display === 'block' ? 'unmapped' : 'mapped'
    };
    sessionStorage.setItem('lazadaAllocState', JSON.stringify(state));
}

function debouncedRender() {
    currentPage = 1; // Reset page on search
    clearTimeout(renderTimeout);
    renderTimeout = setTimeout(() => {
        saveState();
        renderMapped();
    }, 250);
}

document.addEventListener('DOMContentLoaded',()=>{
    // Use Popover event delegation globally
    if (typeof bootstrap !== 'undefined') {
        new bootstrap.Popover(document.body, {
            selector: '[data-bs-toggle="popover"]',
            html: true,
            trigger: 'hover',
            placement: 'top'
        });
    }

    editModal=new bootstrap.Modal(document.getElementById('editModal'));
    
    // --- Restore State on Reload ---
    const navEntries = performance.getEntriesByType('navigation');
    const isReload = navEntries.length > 0 && navEntries[0].type === 'reload';
    
    if (isReload) {
        const saved = sessionStorage.getItem('lazadaAllocState');
        if (saved) {
            try {
                const state = JSON.parse(saved);
                allocFilter = state.allocFilter || 'all';
                currentPage = state.currentPage || 1;
                itemsPerPage = state.itemsPerPage || 25;
                unmappedCurrentPage = state.unmappedCurrentPage || 1;
                unmappedItemsPerPage = state.unmappedItemsPerPage || 25;
                
                if (state.search) {
                    document.getElementById('allocSearch').value = state.search;
                }
                
                if (state.activeTab === 'unmapped') {
                    const uBtn = document.querySelector('.lz-tab-btn[onclick*="unmapped"]');
                    if (uBtn) {
                        document.querySelectorAll('.lz-tab-btn').forEach(b=>b.classList.remove('active'));
                        uBtn.classList.add('active');
                        document.getElementById('mappedSection').style.display = 'none';
                        document.getElementById('unmappedSection').style.display = 'block';
                    }
                }
                
                document.querySelectorAll('#mappedSection .lz-filter-pills .lz-pill').forEach(p=>p.classList.remove('active'));
                const fBtn = document.querySelector(`.lz-pill[onclick*="'${allocFilter}'"]`) || document.querySelector(`.lz-pill[onclick*='"${allocFilter}"']`);
                if (fBtn) fBtn.classList.add('active');
                
                const sel = document.getElementById('itemsPerPageSelect');
                if (sel) sel.value = itemsPerPage;
                const unsel = document.getElementById('unmappedItemsPerPageSelect');
                if (unsel) unsel.value = unmappedItemsPerPage;
                
            } catch(e){}
        }
    } else {
        sessionStorage.removeItem('lazadaAllocState');
    }
    
    renderMapped(); renderUnmapped(); updateSummary();
});

function switchTab(tab, btn){
    document.querySelectorAll('.lz-tab-btn').forEach(b=>b.classList.remove('active'));
    btn.classList.add('active');
    document.getElementById('mappedSection').style.display  = tab==='mapped'   ?'block':'none';
    document.getElementById('unmappedSection').style.display= tab==='unmapped' ?'block':'none';
    saveState();
}
function setAllocFilter(f,btn){
    allocFilter=f;
    currentPage = 1; // Reset page on filter change
    document.querySelectorAll('#mappedSection .lz-filter-pills .lz-pill').forEach(p=>p.classList.remove('active'));
    btn.classList.add('active');
    saveState();
    renderMapped();
}

function stockPill(online){
    if(online===0)  return`<span class="lz-stock-pill s-zero"><i class="fa-solid fa-times"></i>${online}</span>`;
    if(online<=5)   return`<span class="lz-stock-pill s-low"><i class="fa-solid fa-triangle-exclamation"></i>${online}</span>`;
    return               `<span class="lz-stock-pill s-high"><i class="fa-solid fa-check"></i>${online.toLocaleString()}</span>`;
}

function changeItemsPerPage(val) {
    itemsPerPage = parseInt(val, 10);
    currentPage = 1;
    saveState();
    renderMapped();
}

function goToPage(page) {
    currentPage = page;
    saveState();
    renderMapped();
}

function changeUnmappedItemsPerPage(val) {
    unmappedItemsPerPage = parseInt(val, 10);
    unmappedCurrentPage = 1;
    saveState();
    renderUnmapped();
}

function goToUnmappedPage(page) {
    unmappedCurrentPage = page;
    saveState();
    renderUnmapped();
}

function renderModalSharedDetails(v) {
    const container = document.getElementById('mSharedDetailsContainer');
    const dialog = document.getElementById('editModalDialog');
    const mainCol = document.getElementById('modalMainCol');
    const sharedCol = document.getElementById('mSharedDetailsWrapper');

    if (!v.isDuplicate || !v.dupDetails || !v.dupDetails.length) {
        container.innerHTML = '';
        container.className = 'd-none';
        
        if (dialog) {
            dialog.classList.remove('modal-xl');
            dialog.classList.add('modal-lg');
        }
        if (mainCol) mainCol.className = 'col-12';
        if (sharedCol) {
            sharedCol.className = 'col-md-6 d-none';
        }
        return;
    }

    if (dialog) {
        dialog.classList.remove('modal-lg');
        dialog.classList.add('modal-xl');
    }
    if (mainCol) mainCol.className = 'col-md-6 border-end pe-md-4';
    if (sharedCol) {
        sharedCol.className = 'col-md-6 ps-md-4 d-flex flex-column';
        sharedCol.classList.remove('d-none');
    }

    container.className = 'h-100 d-flex flex-column';

    let itemsHtml = '';
    const currentInputVal = parseInt(document.getElementById('mOnlineStock').value) || 0;
    const currentBaseQty = toBaseQty(currentInputVal, v);
    const currentRatio = v.total > 0 ? Math.round((currentBaseQty / v.total) * 100) : 100;
    let totalOnlineStock = currentInputVal;
    let totalOnlineBase = currentBaseQty;
    
    // Add current listing detail (Active)
    itemsHtml += `
    <div class="d-flex align-items-center justify-content-between p-2 mb-2 rounded-2" style="background: linear-gradient(90deg, rgba(15,19,109,0.04) 0%, transparent 100%); border: 1px solid rgba(15,19,109,0.15); border-left: 3px solid var(--lazada-primary);">
        <div class="fw-bold text-dark d-flex align-items-center gap-1.5" style="font-size: 0.8rem;">
            <i class="fa-solid fa-angle-right text-lazada"></i>
            <span>This Listing (Active)</span>
        </div>
        <div class="fw-bold text-lazada fs-6" style="padding-right: 0.5rem;"><span id="mSharedCurrentOnline">${currentInputVal}</span>${unitMultiplier(v) > 1 ? `<span class="d-block small text-secondary fw-normal" id="mSharedCurrentBase">${currentBaseQty} pcs</span>` : ''}</div>
    </div>`;

    // Add other listings
    v.dupDetails.forEach(d => {
        const dName = escHtml(d.name);
        const dVar = d.varName ? escHtml(d.varName) : '';
        const imgHtml = d.imageUrl 
            ? `<img src="${escHtml(d.imageUrl)}" class="lz-product-img me-2 shadow-sm" style="width: 32px; height: 32px; border-radius: 4px; border: 1px solid var(--border-color); object-fit: cover;">`
            : `<div class="lz-img-placeholder me-2 shadow-sm d-flex align-items-center justify-content-center bg-light" style="width: 32px; height: 32px; border-radius: 4px; border: 1px solid var(--border-color);"><i class="fa-solid fa-image text-secondary" style="font-size: 0.75rem;"></i></div>`;

        itemsHtml += `
        <div class="d-flex align-items-center justify-content-between p-2 mb-2 bg-white rounded-2 border shadow-xs" style="gap: 12px;">
            <div class="d-flex align-items-center" style="min-width: 0; flex: 1; gap: 8px;">
                ${imgHtml}
                <div style="min-width: 0; flex: 1;">
                    <div class="fw-bold text-dark lh-sm" style="font-size: 0.8rem; word-break: break-word; white-space: normal;">${dName}</div>
                    ${dVar ? `<span class="badge bg-light border text-secondary mt-0.5 px-1.5 py-0.5 font-normal" style="font-size: 0.65rem; font-weight: 500;">${dVar}</span>` : ''}
                </div>
            </div>
            <div class="flex-shrink-0">
                <div class="lz-qty-control rounded bg-white border d-flex align-items-center justify-content-between overflow-hidden border-lazada" style="width: 105px; height: 32px;">
                    <button class="lz-qty-btn text-lazada border-0 px-2 h-100" style="background: var(--lazada-light); font-size: 0.8rem;" onclick="adjSharedStock(this, -1)"><i class="fa-solid fa-minus"></i></button>
                    <input type="number" class="lz-qty-input fw-bold text-center border-0 text-lazada flex-fill h-100 shared-alloc-input" style="font-size: 1rem; width: 35px; background: transparent; padding: 0;" data-id="${d.id}" data-orig="${d.online}" value="${d.online}" min="0" oninput="updateModalSharedDetailsAlert()">
                    <button class="lz-qty-btn text-lazada border-0 px-2 h-100" style="background: var(--lazada-light); font-size: 0.8rem;" onclick="adjSharedStock(this, 1)"><i class="fa-solid fa-plus"></i></button>
                </div>
            </div>
        </div>`;
        totalOnlineStock += d.online;
        totalOnlineBase += toBaseQty(d.online, d);
    });

    const isExceeded = totalOnlineBase > v.total;
    const alertClass = isExceeded ? 'alert-danger' : 'alert-success';
    const alertIcon = isExceeded ? 'fa-triangle-exclamation' : 'fa-circle-check';
    const alertMsg = isExceeded 
        ? `Exceeds POS physical stock limit!`
        : `Stock is safely allocated across all shared listings.`;

    container.innerHTML = `
        <div class="d-flex justify-content-between align-items-center mb-2.5">
            <h6 class="mb-0 fw-bold" style="font-size: 0.9rem; color: var(--text-primary);"><i class="fa-solid fa-clone me-2 text-lazada"></i>Shared Allocations</h6>
            <div class="badge bg-light text-secondary border px-2.5 py-1.5 shadow-none" style="font-size: 0.72rem; font-weight: 600;"><span id="mSharedTotalOnline" class="fw-bold text-lazada" style="font-size:0.8rem">${totalOnlineStock}</span> <span class="opacity-50 fw-normal" id="mSharedTotalBase">/ ${totalOnlineBase} of ${v.total} pcs</span></div>
        </div>
        <div class="flex-grow-1 p-1" style="max-height: 48vh; overflow-y: auto; scrollbar-width: thin;" id="mSharedDetailsList">
            ${itemsHtml}
        </div>
        <div class="alert ${alertClass} py-2 px-3 small mt-2.5 mb-0 d-flex align-items-center gap-2 border-0 shadow-xs" id="mSharedAlertBox" style="border-radius: 8px;">
            <i class="fa-solid ${alertIcon} fs-6" id="mSharedAlertIcon"></i>
            <div class="flex-grow-1 d-flex align-items-center justify-content-between flex-wrap gap-1">
                <strong id="mSharedTotalOnlineMsg" class="mb-0" style="font-size: 0.75rem;">Total Allocated: ${totalOnlineStock} units / ${totalOnlineBase} of ${v.total} pcs</strong>
                <span id="mSharedAlertText" style="font-size: 0.75rem;">${alertMsg}</span>
            </div>
        </div>
    `;
    container.classList.remove('d-none');
}

function adjSharedStock(btn, delta) {
    const input = btn.parentElement.querySelector('input');
    let val = parseInt(input.value) || 0;
    val += delta;
    if (val < 0) val = 0;
    val = Math.min(val, unitLimit(getSharedInputItem(input)));
    input.value = val;
    updateModalSharedDetailsAlert();
    calcModal();
}

function updateModalSharedDetailsAlert() {
    const currentInputVal = parseInt(document.getElementById('mOnlineStock').value) || 0;
    
    // Update active row text in modal
    const activeRow = document.getElementById('mSharedCurrentOnline');
    if (activeRow) {
        activeRow.textContent = currentInputVal;
    }
    const activeBase = document.getElementById('mSharedCurrentBase');
    if (activeBase) {
        activeBase.textContent = `${toBaseQty(currentInputVal, currentEdit)} pcs`;
    }

    let totalOnlineStock = currentInputVal;
    let totalOnlineBase = toBaseQty(currentInputVal, currentEdit);
    const sharedInputs = document.querySelectorAll('.shared-alloc-input');
    if (sharedInputs.length > 0) {
        sharedInputs.forEach(inp => {
            const item = getSharedInputItem(inp);
            const clamped = Math.max(0, Math.min(parseInt(inp.value, 10) || 0, unitLimit(item)));
            if ((parseInt(inp.value, 10) || 0) !== clamped) inp.value = clamped;
            totalOnlineStock += (parseInt(inp.value)||0);
            totalOnlineBase += toBaseQty(inp.value, item);
        });
    } else {
        currentEdit.dupDetails.forEach(d => {
            totalOnlineStock += d.online;
            totalOnlineBase += toBaseQty(d.online, d);
        });
    }

    const totalLabel = document.getElementById('mSharedTotalOnline');
    if (totalLabel) {
        totalLabel.textContent = totalOnlineStock;
    }
    const totalBaseLabel = document.getElementById('mSharedTotalBase');
    if (totalBaseLabel) {
        totalBaseLabel.textContent = `/ ${totalOnlineBase} of ${currentEdit.total} pcs`;
    }

    const alertContainer = document.querySelector('#mSharedDetailsContainer .alert');
    if (alertContainer) {
        const isExceeded = totalOnlineBase > currentEdit.total;
        alertContainer.className = `alert ${isExceeded ? 'alert-danger' : 'alert-success'} py-2 px-3 small mb-0 mt-2.5 d-flex align-items-center gap-2 border-0 shadow-xs`;
        alertContainer.querySelector('i').className = `fa-solid ${isExceeded ? 'fa-triangle-exclamation' : 'fa-circle-check'}`;
        
        const totalMsg = alertContainer.querySelector('#mSharedTotalOnlineMsg');
        if (totalMsg) {
            totalMsg.textContent = `Total Allocated: ${totalOnlineStock} units / ${totalOnlineBase} of ${currentEdit.total} pcs`;
        }
        
        const spanText = alertContainer.querySelector('#mSharedAlertText');
        if (spanText) {
            if (isExceeded) {
                spanText.innerHTML = `Exceeds POS stock by ${totalOnlineBase - currentEdit.total} pcs!`;
            } else {
                spanText.innerHTML = `Stock is safely allocated across all shared listings.`;
            }
        }
    }
}

function renderMapped(){
    const q=(document.getElementById('allocSearch')?.value||'');
    const terms = searchTerms(q);
    const body=document.getElementById('allocBody');

    // First, filter groups
    const matchedGroups = [];
    MAPPED_GROUPS.forEach(g=>{
        const vars=g.vars.filter(v=>{
            if(!progressiveMatch(terms, [g.name, v.sku, v.varName, g.itemId, v.modelId, v.unitName]))return false;
            if(allocFilter==='manual'&&v.mappingStatus!=='manual')return false;
            if(allocFilter==='synced'&&v.online===0)return false;
            if(allocFilter==='duplicate'&&!v.isDuplicate)return false;
            if(allocFilter==='low'&&v.status!=='low')return false;
            if(allocFilter==='unallocated'&&v.online!==0)return false;
            let totalAlloc = getExistingAllocatedBase(v);
            if(allocFilter==='overallocated' && (totalAlloc <= v.total || v.online === 0)) return false;
            return true;
        });
        if(vars.length > 0) {
            matchedGroups.push({ group: g, matchedVars: vars });
        }
    });

    const totalItems = matchedGroups.length;
    const totalPages = Math.ceil(totalItems / itemsPerPage) || 1;
    if (currentPage > totalPages) currentPage = totalPages;

    const start = (currentPage - 1) * itemsPerPage;
    const end = start + itemsPerPage;
    const slice = matchedGroups.slice(start, end);

    let html = '';
    slice.forEach(item => {
        const g = item.group;
        const vars = item.matchedVars;

        const parentSkuHtml = g.parentSku
            ? `<span class="lz-sku-code">${escHtml(g.parentSku)}</span>`
            : `<span class="text-danger" style="font-size:.75rem;font-style:italic">empty</span>`;

        const imgHtml = g.imageUrl
            ? `<img src="${escHtml(g.imageUrl)}" class="lz-product-img" alt="Product Image">`
            : `<div class="lz-img-placeholder"><i class="fa-solid fa-image"></i></div>`;

        const isSimple = g.vars && g.vars.length === 1;

        if (isSimple) {
            const v = vars[0];
            if (v) {
                const available = v.online;
                let totalAllocated = getExistingAllocatedBase(v);
                const rem = v.total - totalAllocated;
                let badge = '';
                if (totalAllocated > v.total && v.online > 0) badge = `<span class="lz-badge lz-badge-danger" style="background:rgba(220,53,69,0.12);color:#dc3545"><i class="fa-solid fa-arrow-trend-up"></i> Overallocated</span>`;
                else if (v.online === 0) badge = `<span class="lz-badge lz-badge-neutral"><i class="fa-solid fa-minus-circle"></i> Unallocated</span>`;
                else if (available <= 0) badge = `<span class="lz-badge lz-badge-danger"><i class="fa-solid fa-ban"></i> Sold Out</span>`;
                else if (available <= 5) badge = `<span class="lz-badge lz-badge-warning"><i class="fa-solid fa-triangle-exclamation"></i> Low</span>`;
                else badge = `<span class="lz-badge lz-badge-success"><i class="fa-solid fa-check"></i> OK</span>`;

                const actualPct = v.total > 0 ? Math.floor((toBaseQty(v.online, v) / v.total) * 100) : 0;
                const actualSharedPct = v.total > 0 ? Math.floor((totalAllocated / v.total) * 100) : 0;

                const unitBadge = v.unitName 
                    ? `<span class="badge bg-light text-secondary border font-normal ms-1" style="font-size:0.68rem;" title="${v.isBundle ? 'Bundle stock is computed from component pairable stock' : 'Mapped to custom unit type'}"><i class="fa-solid fa-box me-1"></i>${escHtml(v.unitName)} (x${v.multiplier})</span>` 
                    : '';
                const bundleBreakdown = bundleFormulaHtml(v);

                html += `<tr class="lz-group-start">
                    <td>
                        <div class="d-flex align-items-center gap-3" style="min-width:0;">
                            ${imgHtml}
                            <div style="min-width:0;">
                                <div class="d-flex align-items-start gap-2 mb-1" style="min-width:0;">
                                    <i class="fa-brands fa-lazada mt-1" style="color:var(--lazada-primary);font-size:.85rem;flex-shrink:0"></i>
                                    <span class="fw-bold text-wrap text-break" style="font-size:.9rem; line-height:1.2;">${escHtml(g.name)}</span>
                                </div>
                            </div>
                        </div>
                    </td>
                    <td>
                        <div class="d-flex flex-column">
                            <span class="small text-secondary" style="font-size:0.72rem;margin-top:2px">ID: ${escHtml(g.itemId)}</span>
                        </div>
                    </td>
                    <td>
                        ${v.sku?`<span class="lz-sku-code">${escHtml(v.sku)}</span>`:`<span class="text-secondary">—</span>`}
                        ${unitBadge}
                        ${v.isDuplicate?`<span class="badge bg-danger-light text-danger ms-1" style="font-size:0.68rem; border:1px solid rgba(220,53,69,0.25); cursor:pointer;" onclick="openEdit(${v.id})" title="Click to view shared allocation details"><i class="fa-solid fa-clone me-1"></i>Shared (${actualSharedPct}% Allocated)</span>`:''}
                        ${v.mappingStatus==='manual'?`<span class="lz-badge lz-badge-info ms-1" style="padding:0.2rem 0.5rem; font-size:0.68rem;" title="Manually mapped in mapping page"><i class="fa-solid fa-hand-pointer me-1"></i>Manual</span>`:''}
                        ${bundleBreakdown}
                    </td>
                    <td class="text-center fw-bold">${v.total.toLocaleString()}</td>
                    <td class="text-center">
                        <span class="fw-bold text-lazada d-block">${v.online.toLocaleString()}</span>
                        <span class="text-secondary small font-normal" style="font-size:0.72rem;">${unitMultiplier(v) > 1 ? `${toBaseQty(v.online, v).toLocaleString()} pcs - ` : ''}${actualPct}%</span>
                    </td>
                    <td class="text-center fw-bold ${rem < 0 ? 'text-danger' : 'text-success'}">${rem.toLocaleString()}</td>
                    <td>${badge}</td>
                    <td class="text-end">
                        <div class="d-flex align-items-center justify-content-end gap-2">
                            <button class="btn btn-sm btn-outline-lazada px-3" onclick="openEdit(${v.id})"><i class="fa-solid fa-pen-to-square me-1"></i>Edit</button>
                        </div>
                    </td>
                </tr>`;
            }
        } else {
            // Parent Row
            html += `<tr class="lz-group-start">
                <td>
                    <div class="d-flex align-items-center gap-3" style="min-width:0;">
                        ${imgHtml}
                        <div style="min-width:0;">
                            <div class="d-flex align-items-start gap-2 mb-1" style="min-width:0;">
                                <i class="fa-brands fa-lazada mt-1" style="color:var(--lazada-primary);font-size:.85rem;flex-shrink:0"></i>
                                <span class="fw-bold text-wrap text-break" style="font-size:.9rem; line-height:1.2;">${escHtml(g.name)}</span>
                            </div>
                        </div>
                    </div>
                </td>
                <td>
                    <div class="d-flex flex-column">
                        ${parentSkuHtml}
                        <span class="small text-secondary" style="font-size:0.72rem;margin-top:2px">ID: ${escHtml(g.itemId)}</span>
                    </div>
                </td>
                <td><span class="text-secondary">—</span></td>
                <td class="text-center"><span class="text-secondary">—</span></td>
                <td class="text-center"><span class="text-secondary">—</span></td>
                <td class="text-center"><span class="text-secondary">—</span></td>
                <td><span class="text-secondary">—</span></td>
                <td class="text-end"><span class="text-secondary">—</span></td>
            </tr>`;

            // Variation Rows
            vars.forEach(v=>{
                const available = v.online;
                let totalAllocated = getExistingAllocatedBase(v);
                const rem = v.total - totalAllocated;
                let badge = '';
                if (totalAllocated > v.total && v.online > 0) badge = `<span class="lz-badge lz-badge-danger" style="background:rgba(220,53,69,0.12);color:#dc3545"><i class="fa-solid fa-arrow-trend-up"></i> Overallocated</span>`;
                else if (v.online === 0) badge = `<span class="lz-badge lz-badge-neutral"><i class="fa-solid fa-minus-circle"></i> Unallocated</span>`;
                else if (available <= 0) badge = `<span class="lz-badge lz-badge-danger"><i class="fa-solid fa-ban"></i> Sold Out</span>`;
                else if (available <= 5) badge = `<span class="lz-badge lz-badge-warning"><i class="fa-solid fa-triangle-exclamation"></i> Low</span>`;
                else badge = `<span class="lz-badge lz-badge-success"><i class="fa-solid fa-check"></i> OK</span>`;


                const vNameHtml = v.varName
                    ? `${escHtml(v.varName)}`
                    : `<span class="text-secondary fst-italic">Main Item</span>`;

                const actualPct = v.total > 0 ? Math.floor((toBaseQty(v.online, v) / v.total) * 100) : 0;
                const actualSharedPct = v.total > 0 ? Math.floor((totalAllocated / v.total) * 100) : 0;

                const unitBadge = v.unitName 
                    ? `<span class="badge bg-light text-secondary border font-normal ms-1" style="font-size:0.68rem;" title="${v.isBundle ? 'Bundle stock is computed from component pairable stock' : 'Mapped to custom unit type'}"><i class="fa-solid fa-box me-1"></i>${escHtml(v.unitName)} (x${v.multiplier})</span>` 
                    : '';
                const bundleBreakdown = bundleFormulaHtml(v);

                html += `<tr style="background: #f8fafc; border-bottom: 1px solid #f1f5f9;">
                    <td class="ps-4">
                        <div class="d-flex align-items-center gap-3" style="padding-left: 1.5rem;">
                            <i class="fa-solid fa-turn-up fa-rotate-90 text-muted" style="opacity: 0.4; font-size: 1rem;"></i>
                            <div style="width: 36px; height: 36px; border-radius: 6px; border: 1px solid #e2e8f0; overflow: hidden; display: flex; align-items: center; justify-content: center; background: #fff; flex-shrink: 0; box-shadow: 0 2px 4px rgba(0,0,0,0.02);">
                                ${g.imageUrl ? `<img src="${escHtml(g.imageUrl)}" style="max-width: 100%; max-height: 100%; object-fit: contain;">` : `<i class="fa-solid fa-image text-muted opacity-25" style="font-size: 14px;"></i>`}
                            </div>
                            <div class="fw-bold" style="color: #334155; font-size: 0.9rem;">
                                ${vNameHtml}
                            </div>
                        </div>
                    </td>
                    <td><span class="text-secondary">—</span></td>
                    <td>
                        ${v.sku?`<span class="lz-sku-code">${escHtml(v.sku)}</span>`:`<span class="text-secondary">—</span>`}
                        ${unitBadge}
                        ${v.isDuplicate?`<span class="badge bg-danger-light text-danger ms-1" style="font-size:0.68rem; border:1px solid rgba(220,53,69,0.25); cursor:pointer;" onclick="openEdit(${v.id})" title="Click to view shared allocation details"><i class="fa-solid fa-clone me-1"></i>Shared (${actualSharedPct}% Allocated)</span>`:''}
                        ${v.mappingStatus==='manual'?`<span class="lz-badge lz-badge-info ms-1" style="padding:0.2rem 0.5rem; font-size:0.68rem;" title="Manually mapped in mapping page"><i class="fa-solid fa-hand-pointer me-1"></i>Manual</span>`:''}
                        ${bundleBreakdown}
                    </td>
                    <td class="text-center fw-bold">${v.total.toLocaleString()}</td>
                    <td class="text-center">
                        <span class="fw-bold text-lazada d-block">${v.online.toLocaleString()}</span>
                        <span class="text-secondary small font-normal" style="font-size:0.72rem;">${unitMultiplier(v) > 1 ? `${toBaseQty(v.online, v).toLocaleString()} pcs - ` : ''}${actualPct}%</span>
                    </td>
                    <td class="text-center fw-bold ${rem < 0 ? 'text-danger' : 'text-success'}">${rem.toLocaleString()}</td>
                    <td>${badge}</td>
                    <td class="text-end">
                        <div class="d-flex align-items-center justify-content-end gap-2">
                            <button class="btn btn-sm btn-outline-lazada px-3" onclick="openEdit(${v.id})"><i class="fa-solid fa-pen-to-square me-1"></i>Edit</button>
                        </div>
                    </td>
                </tr>`;
            });
        }
    });

    if(!totalItems){
        const msg=MAPPED_FLAT.length===0
            ?`<div class="lz-empty"><i class="fa-solid fa-link d-block"></i><h5>No mapped products yet</h5><p>Go to <a href="${window.BASE_URL}views/lazada/mapping.php" class="text-lazada fw-bold">Product Mapping</a> first.</p></div>`
            :`<div class="lz-empty"><i class="fa-solid fa-filter d-block"></i><h5>No products match this filter</h5></div>`;
        body.innerHTML=`<tr><td colspan="9">${msg}</td></tr>`;
        document.getElementById('paginationStatus').textContent = 'Page 1 of 1 (0 items)';
        document.getElementById('paginationButtons').innerHTML = '';
        return;
    }
    
    body.innerHTML=html;

    document.getElementById('paginationStatus').textContent = `Page ${currentPage} of ${totalPages} (${totalItems} products)`;
    renderPaginationButtons(totalItems, totalPages);
}

function renderUnmapped(){
    const body=document.getElementById('unmappedBody');
    if(!UNMAPPED_GROUPS.length){
        body.innerHTML=`<tr><td colspan="5"><div class="lz-empty"><i class="fa-solid fa-circle-check d-block" style="color:var(--lz-success)"></i><h5>All products are mapped!</h5></div></td></tr>`;
        document.getElementById('unmappedPaginationStatus').textContent = 'Page 1 of 1 (0 items)';
        document.getElementById('unmappedPaginationButtons').innerHTML = '';
        return;
    }

    const totalItems = UNMAPPED_GROUPS.length;
    const totalPages = Math.ceil(totalItems / unmappedItemsPerPage) || 1;
    if (unmappedCurrentPage > totalPages) unmappedCurrentPage = totalPages;

    const start = (unmappedCurrentPage - 1) * unmappedItemsPerPage;
    const end = start + unmappedItemsPerPage;
    const slice = UNMAPPED_GROUPS.slice(start, end);

    let html='';
    slice.forEach(g=>{
        const imgHtml = g.imageUrl
            ? `<img src="${escHtml(g.imageUrl)}" class="lz-product-img" alt="Product Image">`
            : `<div class="lz-img-placeholder"><i class="fa-solid fa-image"></i></div>`;

        const isSimple = g.vars && g.vars.length === 1;

        if (isSimple) {
            const v = g.vars[0];
            html += `<tr class="lz-group-start">
                <td>
                    <div class="d-flex align-items-center gap-3" style="min-width:0;">
                        ${imgHtml}
                        <div style="min-width:0;">
                            <div class="d-flex align-items-start gap-2 mb-1" style="min-width:0;">
                                <i class="fa-brands fa-lazada mt-1" style="color:var(--lazada-primary);font-size:.85rem;flex-shrink:0"></i>
                                <span class="fw-bold text-wrap text-break" style="font-size:.9rem; line-height:1.2;">${escHtml(g.name)}</span>
                            </div>
                        </div>
                    </div>
                </td>
                <td>
                    <div class="d-flex flex-column">
                        <span class="small text-secondary" style="font-size:0.72rem">ID: ${escHtml(g.itemId)}</span>
                    </div>
                </td>
                <td>${v.sku?`<span class="lz-sku-code">${escHtml(v.sku)}</span>`:`<span class="text-secondary">—</span>`}</td>
                <td class="text-center">${stockPill(v.online)}</td>
                <td class="text-end"><a href="${window.BASE_URL}views/lazada/mapping.php" class="btn btn-sm btn-outline-lazada px-3"><i class="fa-solid fa-link me-1"></i>Map Now</a></td>
            </tr>`;
        } else {
            // Parent Row
            html += `<tr class="lz-group-start">
                <td>
                    <div class="d-flex align-items-center gap-3" style="min-width:0;">
                        ${imgHtml}
                        <div style="min-width:0;">
                            <div class="d-flex align-items-start gap-2 mb-1" style="min-width:0;">
                                <i class="fa-brands fa-lazada mt-1" style="color:var(--lazada-primary);font-size:.85rem;flex-shrink:0"></i>
                                <span class="fw-bold text-wrap text-break" style="font-size:.9rem; line-height:1.2;">${escHtml(g.name)}</span>
                            </div>
                        </div>
                    </div>
                </td>
                <td>
                    <div class="d-flex flex-column">
                        <span class="small text-secondary" style="font-size:0.72rem">ID: ${escHtml(g.itemId)}</span>
                    </div>
                </td>
                <td><span class="text-secondary">—</span></td>
                <td class="text-center"><span class="text-secondary">—</span></td>
                <td class="text-end"><span class="text-secondary">—</span></td>
            </tr>`;

            // Variation Rows
            g.vars.forEach(v=>{
                const vNameHtml = v.varName
                    ? `<span class="lz-var-name-text">${escHtml(v.varName)}</span>`
                    : `<span class="lz-var-name-text text-secondary fst-italic">Main Item</span>`;

                html += `<tr>
                    <td class="lz-tree-indent">
                        <div class="d-flex align-items-center">
                            ${vNameHtml}
                        </div>
                    </td>
                    <td><span class="text-secondary">—</span></td>
                    <td>${v.sku?`<span class="lz-sku-code">${escHtml(v.sku)}</span>`:`<span class="text-secondary">—</span>`}</td>
                    <td class="text-center">${stockPill(v.online)}</td>
                    <td class="text-end"><a href="${window.BASE_URL}views/lazada/mapping.php" class="btn btn-sm btn-outline-lazada px-3"><i class="fa-solid fa-link me-1"></i>Map Now</a></td>
                </tr>`;
            });
        }
    });

    body.innerHTML=html;

    document.getElementById('unmappedPaginationStatus').textContent = `Page ${unmappedCurrentPage} of ${totalPages} (${totalItems} products)`;
    renderUnmappedPaginationButtons(totalItems, totalPages);
}

function renderPaginationButtons(totalItems, totalPages) {
    const container = document.getElementById('paginationButtons');
    if (!container) return;
    
    let html = '';
    html += `<button class="btn btn-sm btn-outline-lazada-secondary px-2 py-1" ${currentPage === 1 ? 'disabled' : ''} onclick="goToPage(1)"><i class="fa-solid fa-angles-left"></i></button>`;
    html += `<button class="btn btn-sm btn-outline-lazada-secondary px-2 py-1" ${currentPage === 1 ? 'disabled' : ''} onclick="goToPage(${currentPage - 1})"><i class="fa-solid fa-angle-left"></i></button>`;
    
    const maxVisiblePages = 5;
    let startPage = Math.max(1, currentPage - 2);
    let endPage = Math.min(totalPages, startPage + maxVisiblePages - 1);
    if (endPage - startPage < maxVisiblePages - 1) {
        startPage = Math.max(1, endPage - maxVisiblePages + 1);
    }
    
    for (let p = startPage; p <= endPage; p++) {
        if (p === currentPage) {
            html += `<button class="btn btn-sm btn-lazada px-3 py-1 active">${p}</button>`;
        } else {
            html += `<button class="btn btn-sm btn-outline-lazada-secondary px-3 py-1" onclick="goToPage(${p})">${p}</button>`;
        }
    }
    
    html += `<button class="btn btn-sm btn-outline-lazada-secondary px-2 py-1" ${currentPage === totalPages ? 'disabled' : ''} onclick="goToPage(${currentPage + 1})"><i class="fa-solid fa-angle-right"></i></button>`;
    html += `<button class="btn btn-sm btn-outline-lazada-secondary px-2 py-1" ${currentPage === totalPages ? 'disabled' : ''} onclick="goToPage(${totalPages})"><i class="fa-solid fa-angles-right"></i></button>`;
    
    container.innerHTML = html;
}

function renderUnmappedPaginationButtons(totalItems, totalPages) {
    const container = document.getElementById('unmappedPaginationButtons');
    if (!container) return;
    
    let html = '';
    html += `<button class="btn btn-sm btn-outline-lazada-secondary px-2 py-1" ${unmappedCurrentPage === 1 ? 'disabled' : ''} onclick="goToUnmappedPage(1)"><i class="fa-solid fa-angles-left"></i></button>`;
    html += `<button class="btn btn-sm btn-outline-lazada-secondary px-2 py-1" ${unmappedCurrentPage === 1 ? 'disabled' : ''} onclick="goToUnmappedPage(${unmappedCurrentPage - 1})"><i class="fa-solid fa-angle-left"></i></button>`;
    
    const maxVisiblePages = 5;
    let startPage = Math.max(1, unmappedCurrentPage - 2);
    let endPage = Math.min(totalPages, startPage + maxVisiblePages - 1);
    if (endPage - startPage < maxVisiblePages - 1) {
        startPage = Math.max(1, endPage - maxVisiblePages + 1);
    }
    
    for (let p = startPage; p <= endPage; p++) {
        if (p === unmappedCurrentPage) {
            html += `<button class="btn btn-sm btn-lazada px-3 py-1 active">${p}</button>`;
        } else {
            html += `<button class="btn btn-sm btn-outline-lazada-secondary px-3 py-1" onclick="goToUnmappedPage(${p})">${p}</button>`;
        }
    }
    
    html += `<button class="btn btn-sm btn-outline-lazada-secondary px-2 py-1" ${unmappedCurrentPage === totalPages ? 'disabled' : ''} onclick="goToUnmappedPage(${unmappedCurrentPage + 1})"><i class="fa-solid fa-angle-right"></i></button>`;
    html += `<button class="btn btn-sm btn-outline-lazada-secondary px-2 py-1" ${unmappedCurrentPage === totalPages ? 'disabled' : ''} onclick="goToUnmappedPage(${totalPages})"><i class="fa-solid fa-angles-right"></i></button>`;
    
    container.innerHTML = html;
}
function updateSummary(){
    let allocated = 0;
    let lowStock = 0;
    let unallocated = 0;
    let overallocated = 0;

    MAPPED_FLAT.forEach(v => {
        if (v.online === 0) {
            unallocated++;
        } else {
            allocated++;
            let totalAlloc = getExistingAllocatedBase(v);
            if (totalAlloc > v.total) {
                overallocated++;
            }
            if (v.status === 'low') {
                lowStock++;
            }
        }
    });

    document.getElementById('sumAllocated').textContent = allocated.toLocaleString();
    document.getElementById('sumLowStock').textContent = lowStock.toLocaleString();
    document.getElementById('sumUnallocated').textContent = unallocated.toLocaleString();
    document.getElementById('sumOverallocated').textContent = overallocated.toLocaleString();
}

function openEdit(id){
    currentEdit=MAPPED_FLAT.find(v=>v.id===id);
    document.getElementById('mName').textContent =currentEdit.groupName+(currentEdit.varName?' — '+currentEdit.varName:'');
    
    let skuHtml = currentEdit.sku || '—';
    if (currentEdit.unitName) {
        skuHtml += ` <span class="badge bg-light text-secondary border font-normal ms-2" style="font-size: 0.72rem;"><i class="fa-solid fa-box me-1"></i>Unit: ${escHtml(currentEdit.unitName)} (${currentEdit.multiplier} pcs)</span>`;
    }
    if (currentEdit.isBundle) {
        skuHtml += bundleFormulaHtml(currentEdit);
    }
    document.getElementById('mSku').innerHTML = skuHtml;
    
    document.getElementById('mTotal').textContent=currentEdit.total;
    document.getElementById('mTotalSub').textContent = currentEdit.isBundle ? 'Pairable bundle sets' : 'Physical POS';
    document.getElementById('mOnlineStock').removeAttribute('max');
    document.getElementById('mOnlineStock').value =currentEdit.online !== undefined ? currentEdit.online : unitLimit(currentEdit);
    calcModal();
    renderModalSharedDetails(currentEdit);
    editModal.show();
}

function adjStock(d){
    const inp=document.getElementById('mOnlineStock');
    const newVal = Math.max(0, (parseInt(inp.value)||0)+d);
    inp.value=newVal;
    calcModal();
}

function presetStock(pct){
    const totalPos = currentEdit.total;
    const targetBaseAllocated = Math.floor(totalPos * (pct / 100));
    const targetTotalAllocated = Math.floor(targetBaseAllocated / unitMultiplier(currentEdit));

    if (currentEdit.isDuplicate) {
        // Distribute evenly among all listings (1 active + N shared)
        const totalListings = 1 + (currentEdit.dupDetails ? currentEdit.dupDetails.length : 0);
        const perListing = Math.floor(targetTotalAllocated / totalListings);
        let remainder = targetTotalAllocated % totalListings;

        // Set active listing
        let activeVal = perListing;
        if (remainder > 0) {
            activeVal++;
            remainder--;
        }
        document.getElementById('mOnlineStock').value = activeVal;

        // Set shared inputs if they are rendered
        const sharedInputs = document.querySelectorAll('.shared-alloc-input');
        sharedInputs.forEach(inp => {
            let val = perListing;
            if (remainder > 0) {
                val++;
                remainder--;
            }
            inp.value = val;
        });
    } else {
        document.getElementById('mOnlineStock').value = targetTotalAllocated;
    }
    
    calcModal();
}

function calcModal(){
    const mainInput = document.getElementById('mOnlineStock');
    let onlineVal=parseInt(mainInput.value)||0;
    onlineVal = Math.max(0, onlineVal);
    mainInput.value = onlineVal;
    
    let totalOnlineStock = onlineVal;
    let totalOnlineBase = toBaseQty(onlineVal, currentEdit);
    if (currentEdit.isDuplicate) {
        const sharedInputs = document.querySelectorAll('.shared-alloc-input');
        if (sharedInputs.length > 0) {
            sharedInputs.forEach(inp => {
                totalOnlineStock += (parseInt(inp.value)||0);
                totalOnlineBase += toBaseQty(inp.value, getSharedInputItem(inp));
            });
        } else {
            currentEdit.dupDetails.forEach(d => {
                totalOnlineStock += d.online;
                totalOnlineBase += toBaseQty(d.online, d);
            });
        }
    }
    const remaining = Math.max(0, currentEdit.total - totalOnlineBase);
    const warn=document.getElementById('mWarning');
    
    document.getElementById('mRemainingCalc').textContent=remaining;
    
    // Dynamic Stock Breakdown Meter calculations
    const totalStock = currentEdit.total;
    const lazadaPct = totalStock > 0 ? Math.round((totalOnlineBase / totalStock) * 100) : 0;
    const posPct = totalStock > 0 ? Math.max(0, 100 - lazadaPct) : 0;

    const ratioText = document.getElementById('mRatioText');
    const barLazada = document.getElementById('mBarLazada');
    const barPos = document.getElementById('mBarPos');
    const labelLazada = document.getElementById('mRatioLabelLazada');
    const labelPos = document.getElementById('mRatioLabelPos');

    if (ratioText) {
        ratioText.textContent = `${lazadaPct}% on Lazada`;
    }
    if (barLazada) {
        barLazada.style.width = `${lazadaPct}%`;
        barLazada.setAttribute('aria-valuenow', lazadaPct);
    }
    if (barPos) {
        barPos.style.width = `${posPct}%`;
        barPos.setAttribute('aria-valuenow', posPct);
    }
    if (labelLazada) {
        labelLazada.textContent = formatAllocatedLabel(totalOnlineStock, totalOnlineBase, currentEdit);
    }
    if (labelPos) {
        labelPos.textContent = `${remaining} item${remaining === 1 ? '' : 's'}`;
    }

    if(onlineVal > unitLimit(currentEdit)){
        warn.className='alert alert-danger py-2 small mb-0 mt-2';
        warn.innerHTML=`<i class="fa-solid fa-triangle-exclamation me-2"></i>Allocated stock cannot exceed available ${allocationUnitLabel(currentEdit)} stock (${unitLimit(currentEdit)}).`;
    } else if(onlineVal === 0 && currentEdit.total > 0){
        warn.className='alert alert-warning py-2 small mb-0 mt-2';
        warn.innerHTML=`<i class="fa-solid fa-triangle-exclamation me-2"></i>Lazada will receive 0 stock.`;
    } else {
        warn.className='d-none';
    }

    if (currentEdit.isDuplicate && typeof updateModalSharedDetailsAlert === 'function') {
        // Prevent infinite loop by passing a flag if needed, but in our current code it is safe
        const activeRow = document.getElementById('mSharedCurrentOnline');
        if (activeRow) {
            activeRow.textContent = onlineVal;
        }
        
        const totalLabel = document.getElementById('mSharedTotalOnline');
        if (totalLabel) {
            totalLabel.textContent = totalOnlineStock;
        }
        const totalBaseLabel = document.getElementById('mSharedTotalBase');
        if (totalBaseLabel) {
            totalBaseLabel.textContent = `/ ${totalOnlineBase} of ${currentEdit.total} pcs`;
        }

        const alertContainer = document.querySelector('#mSharedDetailsContainer .alert');
        if (alertContainer) {
            const isExceeded = totalOnlineBase > currentEdit.total;
            alertContainer.className = `alert ${isExceeded ? 'alert-danger' : 'alert-success'} py-2 px-3 small mb-0 mt-2.5 d-flex align-items-center gap-2 border-0 shadow-xs`;
            alertContainer.querySelector('i').className = `fa-solid ${isExceeded ? 'fa-triangle-exclamation' : 'fa-circle-check'}`;
            
            const totalMsg = alertContainer.querySelector('#mSharedTotalOnlineMsg');
            if (totalMsg) {
                totalMsg.textContent = `Total Allocated: ${totalOnlineStock} units / ${totalOnlineBase} of ${currentEdit.total} pcs`;
            }
            
            const spanText = alertContainer.querySelector('#mSharedAlertText');
            if (spanText) {
                if (isExceeded) {
                    spanText.innerHTML = `Exceeds POS stock by ${totalOnlineBase - currentEdit.total} pcs!`;
                } else {
                    spanText.innerHTML = `Stock is safely allocated across all shared listings.`;
                }
            }
        }
    }
}

async function saveAllocation(btnEl) {
    const onlineVal = parseInt(document.getElementById('mOnlineStock').value) || 0;
    
    let totalVal = toBaseQty(onlineVal, currentEdit);
    const sharedInputs = document.querySelectorAll('.shared-alloc-input');
    const updates = [{ id: currentEdit.id, val: onlineVal, orig: (currentEdit.online !== undefined ? currentEdit.online : currentEdit.total) }];
    
    sharedInputs.forEach(inp => {
        const val = parseInt(inp.value) || 0;
        totalVal += toBaseQty(val, getSharedInputItem(inp));
        updates.push({ id: parseInt(inp.dataset.id), val: val, orig: parseInt(inp.dataset.orig) });
    });

    if(totalVal > currentEdit.total){
        EllaToast.error(`Total allocated stock cannot exceed physical POS stock (${currentEdit.total} pcs).`);
        return;
    }
    
    const changedUpdates = updates.filter(u => u.val !== u.orig);
    if(changedUpdates.length === 0) {
        editModal.hide();
        return;
    }

    const btn = btnEl || (typeof event !== 'undefined' ? event.currentTarget : null);
    const originalText = btn ? btn.innerHTML : 'Save & Sync';
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-2"></i>Saving...';
    }

    try {
        const results = [];
        let hasError = false;
        let successCount = 0;
        
        for (const u of changedUpdates) {
            const res = await fetch(`${window.BASE_URL}api/lazada/update_allocation.php`,{
                method:'POST',
                headers:{'Content-Type':'application/json'},
                body:JSON.stringify({id:u.id,online_stock:u.val})
            });
            
            const rawText = await res.text();
            if (!res.ok) throw new Error(`HTTP Error`);
            
            let data = JSON.parse(rawText);
            
            if(data.success){
                successCount++;
                const item = MAPPED_FLAT.find(v => v.id === u.id);
                if (item) {
                    const newRatio = item.total > 0 ? Math.round((toBaseQty(u.val, item) / item.total) * 100) : 100;
                    item.ratio = newRatio;
                    item.online = u.val;
                    item.status = u.val === 0 ? 'unallocated' : (unitLimit(item) - u.val <= 5 ? 'low' : 'synced');
                }
            } else {
                hasError = true;
                EllaToast.error(data.error || 'Failed to update listing.');
            }
        }

        if (successCount > 0) {
            MAPPED_FLAT.forEach(item => {
                if (item.dupDetails) {
                    item.dupDetails.forEach(d => {
                        const flatMatch = MAPPED_FLAT.find(f => f.id === d.id);
                        if (flatMatch) {
                            d.online = flatMatch.online;
                            d.ratio = flatMatch.ratio;
                        }
                    });
                }
            });

            renderMapped();
            updateSummary();
            if (!hasError) editModal.hide();
            EllaToast.success(`${successCount} listing(s) allocated successfully!`);
        }
    } catch (e) {
        EllaToast.error('Network error while saving. Please check console for details.');
    } finally {
        if(btn) {
            btn.disabled = false;
            btn.innerHTML = originalText;
        }
    }
}



async function triggerManualSync() {
    if (!MAPPED_FLAT || MAPPED_FLAT.length === 0) {
        EllaToast.error('No mapped items to sync.');
        return;
    }

    const btn = document.getElementById('btnManualSync');
    if (!btn) return;
    const syncText = document.getElementById('syncText') || btn;
    const icon = btn.querySelector('i');
    
    btn.disabled = true;
    if(icon) icon.classList.add('fa-spin');
    
    try {
        const ids = MAPPED_FLAT.map(v => v.id);
        const totalItems = ids.length;
        const chunkSize = 20; // Reduced from 100 to prevent Hostinger execution timeouts
        let processedCount = 0;
        let successCount = 0;
        let changedCount = sessionChangedCount;
        sessionChangedCount = 0; // Reset after claiming
        let failCount = 0;

        syncText.textContent = `Syncing (0/${totalItems})...`;

        for (let i = 0; i < totalItems; i += chunkSize) {
            const chunk = ids.slice(i, i + chunkSize);
            try {
                const res = await fetch(`${window.BASE_URL}api/lazada/fetch_mapped_live_stocks.php`, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ ids: chunk, skip_log: true })
                });
                const data = await res.json();
                if (data.success) {
                    const updatedLen = (data.updated || []).length;
                    const errorLen = (data.errors || []).length;
                    const changedLen = (data.updated || []).filter(u => u.changed).length;
                    successCount += updatedLen;
                    changedCount += changedLen;
                    failCount += errorLen;
                } else {
                    failCount += chunk.length;
                }
            } catch (err) {
                console.error('Batch sync error:', err);
                failCount += chunk.length;
            }
            
            processedCount += chunk.length;
            if (processedCount > totalItems) processedCount = totalItems;
            syncText.textContent = `Syncing (${processedCount}/${totalItems})...`;
        }

        try {
            await fetch(`${window.BASE_URL}api/lazada/log_stock_sync_summary.php`, {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ success_count: successCount, changed_count: changedCount, fail_count: failCount })
            });
        } catch (logErr) {}

        if (successCount > 0) {
            if (failCount > 0) {
                EllaToast.warning(`Sync finished: ${successCount} checked, ${changedCount} updated, ${failCount} failed.`);
            } else {
                EllaToast.success(`Sync finished! ${successCount} checked, ${changedCount} had stock updates.`);
            }
            setTimeout(() => {
                window.location.reload();
            }, 1500);
        } else {
            EllaToast.error(`Sync failed completely. ${failCount} item(s) failed.`);
            btn.disabled = false;
            if(icon) icon.classList.remove('fa-spin');
            syncText.textContent = 'Sync Stock';
        }
    } catch (e) {
        EllaToast.error('An unexpected error occurred during sync.');
        btn.disabled = false;
        if(icon) icon.classList.remove('fa-spin');
        syncText.textContent = 'Sync Stock';
    }
}

function toggleGroup(itemId, btn) {
    const rows = document.querySelectorAll('.group-vars-' + itemId);
    const icon = btn.querySelector('i');
    const isCollapsed = icon.classList.contains('fa-chevron-right');
    
    if (isCollapsed) {
        icon.classList.replace('fa-chevron-right', 'fa-chevron-down');
        rows.forEach(r => r.classList.remove('collapsed'));
    } else {
        icon.classList.replace('fa-chevron-down', 'fa-chevron-right');
        rows.forEach(r => r.classList.add('collapsed'));
    }
}

async function refreshLiveRow(id, btn) {
    const icon = btn.querySelector('i');
    const origHtml = btn.innerHTML;
    btn.disabled = true;
    icon.classList.add('fa-spin');
    
    try {
        const res = await fetch(`${window.BASE_URL}api/lazada/fetch_mapped_live_stocks.php`, {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ ids: [id] })
        });
        const data = await res.json();
        if (data.success && data.updated && data.updated.length > 0) {
            const upd = data.updated[0];
            const item = MAPPED_FLAT.find(v => v.id === id);
            if (item) {
                item.online = upd.lazada_stock;
                item.total = item.isBundle ? (parseInt(upd.bundle_total_sets, 10) || 0) : (upd.pos_physical_stock + upd.pos_online_stock);
                item.unitTotal = Math.floor(item.total / unitMultiplier(item));
                item.ratio = upd.stock_allocation_ratio;
                const avail = item.online;
                item.status = item.online === 0 ? 'unallocated' : (avail <= 5 ? 'low' : 'synced');
            }
            
            MAPPED_GROUPS.forEach(g => {
                g.vars.forEach(v => {
                    if (v.id === id) {
                        v.online = upd.lazada_stock;
                        v.total = v.isBundle ? (parseInt(upd.bundle_total_sets, 10) || 0) : (upd.pos_physical_stock + upd.pos_online_stock);
                        v.unitTotal = Math.floor(v.total / unitMultiplier(v));
                        v.ratio = upd.stock_allocation_ratio;
                        const avail = v.online;
                        v.status = v.online === 0 ? 'unallocated' : (avail <= 5 ? 'low' : 'synced');
                    }
                    if (v.isDuplicate && v.dupDetails) {
                        v.dupDetails.forEach(d => {
                            if (d.id === id) {
                                d.online = upd.lazada_stock;
                                d.total = d.isBundle ? (parseInt(upd.bundle_total_sets, 10) || 0) : (upd.pos_physical_stock + upd.pos_online_stock);
                                d.unitTotal = Math.floor(d.total / unitMultiplier(d));
                                d.ratio = upd.stock_allocation_ratio;
                            }
                        });
                    }
                });
            });
            
            renderMapped();
            updateSummary();
            EllaToast.success('Stock synced live from Lazada!');
        } else {
            const errMsg = data.errors && data.errors.length > 0 ? data.errors[0].error : (data.error || 'Failed to sync');
            EllaToast.error('Sync failed: ' + errMsg);
        }
    } catch (e) {
        EllaToast.error('Network error during sync');
    } finally {
        btn.disabled = false;
        icon.classList.remove('fa-spin');
    }
}

async function autoSyncOnLoad() {
    if (!MAPPED_FLAT || MAPPED_FLAT.length === 0) return;
    
    // Show a tiny non-intrusive indicator next to the title or just use the sync button icon
    const btn = document.getElementById('btnManualSync');
    const icon = btn ? btn.querySelector('i') : null;
    if(icon) icon.classList.add('fa-spin');
    
    try {
        const ids = MAPPED_FLAT.map(v => v.id);
        const chunkSize = 100;
        let successCount = 0;
        let didUpdate = false;

        for (let i = 0; i < ids.length; i += chunkSize) {
            const chunk = ids.slice(i, i + chunkSize);
            const res = await fetch(`${window.BASE_URL}api/lazada/fetch_mapped_live_stocks.php`, {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ ids: chunk, skip_log: true })
            });
            const data = await res.json();
            
            if (data.success && data.updated && data.updated.length > 0) {
                const changedLen = data.updated.filter(u => u.changed).length;
                if (changedLen > 0) {
                    didUpdate = true;
                    sessionChangedCount += changedLen;
                }
                data.updated.forEach(upd => {
                    const item = MAPPED_FLAT.find(v => v.id === upd.id);
                    if (item) {
                        item.online = upd.lazada_stock;
                        item.total = item.isBundle ? (parseInt(upd.bundle_total_sets, 10) || 0) : (upd.pos_physical_stock + upd.pos_online_stock);
                        item.unitTotal = Math.floor(item.total / unitMultiplier(item));
                        item.ratio = upd.stock_allocation_ratio;
                        const avail = item.online;
                        item.status = item.online === 0 ? 'unallocated' : (avail <= 5 ? 'low' : 'synced');
                    }
                    
                    MAPPED_GROUPS.forEach(g => {
                        g.vars.forEach(v => {
                            if (v.id === upd.id) {
                                v.online = upd.lazada_stock;
                                v.total = v.isBundle ? (parseInt(upd.bundle_total_sets, 10) || 0) : (upd.pos_physical_stock + upd.pos_online_stock);
                                v.unitTotal = Math.floor(v.total / unitMultiplier(v));
                                v.ratio = upd.stock_allocation_ratio;
                                const avail = v.online;
                                v.status = v.online === 0 ? 'unallocated' : (avail <= 5 ? 'low' : 'synced');
                            }
                            if (v.isDuplicate && v.dupDetails) {
                                v.dupDetails.forEach(d => {
                                    if (d.id === upd.id) {
                                        d.online = upd.lazada_stock;
                                        d.total = d.isBundle ? (parseInt(upd.bundle_total_sets, 10) || 0) : (upd.pos_physical_stock + upd.pos_online_stock);
                                        d.unitTotal = Math.floor(d.total / unitMultiplier(d));
                                        d.ratio = upd.stock_allocation_ratio;
                                    }
                                });
                            }
                        });
                    });
                });
            }
        }
        
        if (didUpdate) {
            renderMapped();
            updateSummary();
            // Don't show toast for auto-sync to keep it silent and clean
        }
    } catch (e) {
        console.error("Auto sync on load failed:", e);
    } finally {
        if(icon) icon.classList.remove('fa-spin');
    }
}

// Run the auto-sync automatically after a short delay so the initial cached page renders instantly
setTimeout(autoSyncOnLoad, 500);


</script>

<script src="../../views/lazada/lazada_alerts.js"></script>
<?php require_once '../../includes/footer.php'; ?>

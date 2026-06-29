<?php
// views/inventory/stock_checker.php
// Dedicated Stock Monitor for Stockman role — mobile-first, scanner-friendly

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

require_once '../../config/config.php';
require_once '../../includes/auth.php';

requireLogin();
// Accessible to stockman, manager, admin, super_admin
if (!in_array($_SESSION['role'], ['admin', 'super_admin', 'manager', 'stockman'])) {
    denyAccess("You do not have permission to access the Stock Checker.");
}

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
require_once '../../config/database.php';

$db = new Database();
$conn = $db->getConnection();

// --- PAGINATION & SEARCH LOGIC ---
$search = $_GET['search'] ?? '';
$filter = $_GET['filter'] ?? '';
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int) $_GET['page'] : 1;
$limit = 30;
$offset = ($page - 1) * $limit;

// Base Query
$baseSql = "
    FROM product_variations v
    JOIN products p ON v.product_id = p.product_id
    LEFT JOIN inventory i_phys ON v.variation_id = i_phys.variation_id AND i_phys.store_id = 1
    LEFT JOIN inventory i_online ON v.variation_id = i_online.variation_id AND i_online.store_id = 2
    WHERE v.status = 'active'
";

$params = [];

if (!empty($search)) {
    $words = preg_split('/\s+/', $search);
    $validWords = [];
    foreach ($words as $word) {
        $word = trim($word);
        if (strlen($word) >= 1) {
            $validWords[] = $word;
        }
    }

    $wordConditions = [];
    if (!empty($validWords)) {
        foreach ($validWords as $idx => $word) {
            $wordConditions[] = "(p.product_name LIKE ? OR p.brand_name LIKE ? OR v.sku LIKE ? OR v.barcode LIKE ? OR v.variation_name LIKE ?)";
            $term = "%$word%";
            array_push($params, $term, $term, $term, $term, $term);
        }
    }

    if (!empty($wordConditions)) {
        $baseSql .= " AND (v.barcode = ? OR (" . implode(' AND ', $wordConditions) . "))";
        array_unshift($params, $search);
    }
}

if ($filter === 'low_stock') {
    $baseSql .= " AND COALESCE(i_phys.quantity, 0) <= v.low_stock_threshold";
} elseif ($filter === 'out_of_stock') {
    $baseSql .= " AND COALESCE(i_phys.quantity, 0) = 0";
}

// Stats
$sqlStats = "
    SELECT 
        COUNT(*) as total_items,
        SUM(CASE WHEN COALESCE(i_phys.quantity, 0) <= v.low_stock_threshold THEN 1 ELSE 0 END) as low_stock_count,
        SUM(CASE WHEN COALESCE(i_phys.quantity, 0) = 0 THEN 1 ELSE 0 END) as out_of_stock_count
    " . $baseSql;

$stmtStats = $conn->prepare($sqlStats);
$stmtStats->execute($params);
$stats = $stmtStats->fetch(PDO::FETCH_ASSOC);

$total_items = $stats['total_items'] ?? 0;
$low_stock_count = $stats['low_stock_count'] ?? 0;
$out_of_stock_count = $stats['out_of_stock_count'] ?? 0;
$total_pages = ceil($total_items / $limit);

// Products
$sqlProducts = "
    SELECT v.variation_id, v.variation_name, v.sku, v.barcode, v.unit_type,
           v.status, v.low_stock_threshold,
           p.product_name, p.brand_name, p.image_path,
           COALESCE(i_phys.quantity, 0) + COALESCE(i_online.quantity, 0) as current_stock
    " . $baseSql . "
    ORDER BY p.product_name ASC
    LIMIT $limit OFFSET $offset
";

$stmt = $conn->prepare($sqlProducts);
$stmt->execute($params);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Online stock mappings
if (count($products) > 0) {
    $variation_ids = [];
    $skus = [];
    foreach ($products as $p) {
        $variation_ids[] = (int) $p['variation_id'];
        if (!empty($p['sku']) && !in_array(strtolower(trim($p['sku'])), ['', '-', 'n/a', 'na', 'none', 'null'])) {
            $skus[] = $p['sku'];
        }
    }

    $variation_ids_str = implode(',', $variation_ids);
    $skus_escaped = array_map(function($s) use ($conn) { return $conn->quote($s); }, $skus);
    $skus_str = !empty($skus_escaped) ? implode(',', $skus_escaped) : "''";

    $mappingSql = "
        SELECT 
            m.pos_product_id,
            m.matched_pos_sku,
            (m.shopee_stock * COALESCE(u.multiplier, 1)) as stock_value
        FROM shopee_product_mappings m
        LEFT JOIN product_units u ON m.pos_unit_id = u.id
        WHERE (m.pos_product_id IN ($variation_ids_str) OR m.matched_pos_sku IN ($skus_str))
          AND m.mapping_status IN ('auto','manual')
          AND (m.pos_bundle_set_id IS NULL OR m.pos_bundle_set_id = 0)
        UNION ALL
        SELECT 
            m2.pos_product_id,
            m2.matched_pos_sku,
            (m2.lazada_stock * COALESCE(u2.multiplier, 1)) as stock_value,
            'lazada' as platform
        FROM lazada_product_mappings m2
        LEFT JOIN product_units u2 ON m2.pos_unit_id = u2.id
        WHERE (m2.pos_product_id IN ($variation_ids_str) OR m2.matched_pos_sku IN ($skus_str))
          AND m2.mapping_status IN ('auto','manual','mapped')
          AND (m2.pos_bundle_set_id IS NULL OR m2.pos_bundle_set_id = 0)
    ";

    $mappingStmt = $conn->query($mappingSql);
    $mappings = $mappingStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($products as &$p) {
        $p['shopee_stock'] = 0;
        $p['lazada_stock'] = 0;
        $p['is_shopee_mapped'] = false;
        $p['is_lazada_mapped'] = false;
        $v_id = $p['variation_id'];
        $v_sku = strtolower(trim($p['sku'] ?? ''));
        $valid_sku = !empty($v_sku) && !in_array($v_sku, ['', '-', 'n/a', 'na', 'none', 'null']);

        foreach ($mappings as $m) {
            $matches_id = ($m['pos_product_id'] == $v_id);
            $matches_sku = ($valid_sku && strtolower(trim($m['matched_pos_sku'] ?? '')) == $v_sku);
            
            if ($matches_id || $matches_sku) {
                if ($m['platform'] === 'shopee') {
                    $p['shopee_stock'] += (int) $m['stock_value'];
                    $p['is_shopee_mapped'] = true;
                } else {
                    $p['lazada_stock'] += (int) $m['stock_value'];
                    $p['is_lazada_mapped'] = true;
                }
            }
        }
    }
    unset($p);
}
?>

<style>
    /* ============================================
       STOCK CHECKER — CLEAN, FOCUSED DESIGN
       ============================================ */

    .sc-page-header {
        background: linear-gradient(135deg, #2563eb 0%, #1e40af 50%, #1e3a8a 100%);
        border-radius: 16px;
        padding: 1.75rem 2rem;
        margin-bottom: 1.5rem;
        color: #fff;
        position: relative;
        overflow: hidden;
    }
    .sc-page-header::before {
        content: '';
        position: absolute;
        top: -50%;
        right: -20%;
        width: 300px;
        height: 300px;
        background: radial-gradient(circle, rgba(255,255,255,0.12) 0%, transparent 70%);
        border-radius: 50%;
    }
    .sc-page-header h1 {
        font-size: 1.5rem;
        font-weight: 800;
        letter-spacing: -0.02em;
        margin: 0;
        color: #ffffff;
        text-shadow: 0 1px 3px rgba(0,0,0,0.2);
    }
    .sc-page-header .sc-subtitle {
        font-size: 0.85rem;
        color: rgba(255, 255, 255, 0.85);
        margin-top: 0.25rem;
    }

    /* Stat Chips */
    .sc-stat-row {
        display: flex;
        gap: 0.75rem;
        flex-wrap: wrap;
        margin-bottom: 1.25rem;
    }
    .sc-stat-chip {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0.6rem 1rem;
        border-radius: 12px;
        font-weight: 700;
        font-size: 0.85rem;
        text-decoration: none;
        transition: all 0.2s ease;
        border: 2px solid transparent;
        cursor: pointer;
    }
    .sc-stat-chip:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0,0,0,0.08);
    }
    .sc-stat-chip.chip-total {
        background: var(--bg-surface, #f1f5f9);
        color: var(--text-primary, #1e293b);
        border-color: var(--border-color, #e2e8f0);
    }
    .sc-stat-chip.chip-low {
        background: #fef3c7;
        color: #92400e;
        border-color: #fbbf24;
    }
    .sc-stat-chip.chip-out {
        background: #fee2e2;
        color: #991b1b;
        border-color: #f87171;
    }
    .sc-stat-chip.chip-active {
        border-color: var(--text-primary, #1e293b) !important;
        box-shadow: 0 0 0 1px var(--text-primary, #1e293b);
    }
    .sc-stat-chip .chip-count {
        font-size: 1.1rem;
        font-weight: 800;
    }

    /* Search Bar */
    .sc-search-wrap {
        position: relative;
        margin-bottom: 1.25rem;
    }
    .sc-search-wrap .sc-search-input {
        width: 100%;
        padding: 1rem 1rem 1rem 3.25rem;
        font-size: 1.05rem;
        border: 2px solid var(--border-color, #e2e8f0);
        border-radius: 14px;
        background: var(--card-bg, #fff);
        color: var(--text-primary, #1e293b);
        transition: border-color 0.2s ease, box-shadow 0.2s ease;
        outline: none;
    }
    .sc-search-wrap .sc-search-input:focus {
        border-color: #3b82f6;
        box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15);
    }
    .sc-search-wrap .sc-search-icon {
        position: absolute;
        left: 1.1rem;
        top: 50%;
        transform: translateY(-50%);
        color: var(--text-secondary, #94a3b8);
        font-size: 1.1rem;
    }
    .sc-search-wrap .sc-search-spinner {
        position: absolute;
        right: 1.1rem;
        top: 50%;
        transform: translateY(-50%);
        display: none;
    }
    .sc-search-wrap .sc-search-spinner.show {
        display: block;
    }

    /* Product Card */
    .sc-product-card {
        background: var(--card-bg, #fff);
        border: 1px solid var(--border-color, #e2e8f0);
        border-radius: 14px;
        padding: 1rem;
        display: flex;
        flex-wrap: wrap;
        gap: 1rem;
        align-items: flex-start;
        transition: all 0.2s ease;
        border-left: 5px solid transparent;
    }
    .sc-product-card:hover {
        box-shadow: 0 4px 16px rgba(0,0,0,0.06);
        transform: translateY(-1px);
    }
    .sc-product-card.stock-ok {
        border-left-color: #22c55e;
    }
    .sc-product-card.stock-low {
        border-left-color: #f59e0b;
        background: linear-gradient(135deg, var(--card-bg, #fff) 85%, #fef9ee 100%);
    }
    .sc-product-card.stock-out {
        border-left-color: #ef4444;
        background: linear-gradient(135deg, var(--card-bg, #fff) 85%, #fef2f2 100%);
    }

    /* Product Image */
    .sc-product-img {
        width: 80px;
        height: 80px;
        min-width: 80px;
        border-radius: 12px;
        overflow: hidden;
        background: var(--bg-surface, #f1f5f9);
        border: 1px solid var(--border-color, #e2e8f0);
        display: flex;
        align-items: center;
        justify-content: center;
    }
    .sc-product-img img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }
    .sc-product-img .sc-no-img {
        font-size: 2rem;
        color: var(--text-secondary, #94a3b8);
    }

    /* Product Info */
    .sc-product-info {
        flex: 1;
        min-width: 0;
    }
    .sc-product-name {
        font-weight: 700;
        font-size: 1rem;
        color: var(--text-primary, #1e293b);
        line-height: 1.35;
        margin-bottom: 0.15rem;
        word-break: break-word;
    }
    .sc-product-meta {
        font-size: 0.85rem;
        color: var(--text-secondary, #64748b);
        margin-bottom: 0.5rem;
    }
    .sc-product-meta .sc-variation {
        color: #3b82f6;
        font-weight: 600;
    }
    .sc-sku-badge {
        display: inline-block;
        padding: 0.2rem 0.5rem;
        border-radius: 6px;
        font-size: 0.85rem;
        font-weight: 700;
        background: #e0f2fe;
        color: #0369a1;
        border: 1px solid #bae6fd;
        font-family: monospace;
        letter-spacing: 0.05em;
        margin-bottom: 0.2rem;
    }

    /* Stock Display — the hero element */
    .sc-stock-display {
        display: flex;
        flex-direction: column;
        align-items: flex-end;
        justify-content: center;
        text-align: right;
        min-width: 100px;
    }
    .sc-stock-number {
        font-size: 2.2rem;
        font-weight: 800;
        line-height: 1;
        margin-bottom: 0.1rem;
    }
    .sc-stock-number.stock-ok { color: #16a34a; }
    .sc-stock-number.stock-low { color: #d97706; }
    .sc-stock-number.stock-out { color: #dc2626; }

    .sc-stock-label {
        font-size: 0.7rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        color: var(--text-secondary, #64748b);
    }
    .sc-stock-unit {
        font-size: 0.75rem;
        font-weight: 600;
        color: var(--text-secondary, #64748b);
    }

    .sc-online-badge {
        margin-top: 0.35rem;
        font-size: 0.7rem;
        font-weight: 600;
        color: #0ea5e9;
        display: flex;
        align-items: center;
        gap: 0.3rem;
    }

    /* History Button */
    .sc-history-btn {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        padding: 0.3rem 0.65rem;
        border-radius: 8px;
        font-size: 0.72rem;
        font-weight: 600;
        color: #6366f1;
        background: #eef2ff;
        border: 1px solid #c7d2fe;
        text-decoration: none;
        transition: all 0.2s ease;
        margin-top: 0.5rem;
    }
    .sc-history-btn:hover {
        background: #e0e7ff;
        color: #4f46e5;
        transform: translateY(-1px);
        box-shadow: 0 2px 6px rgba(99, 102, 241, 0.15);
    }

    /* Card Footer — full-width row for the history button */
    .sc-card-footer {
        width: 100%;
        display: flex;
        align-items: center;
        justify-content: flex-end;
        padding-top: 0.65rem;
        margin-top: 0;
        border-top: 1px solid var(--border-color, #e9ecef);
    }
    .sc-card-footer .sc-footer-btn {
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        padding: 0.45rem 1rem;
        border-radius: 8px;
        font-size: 0.8rem;
        font-weight: 700;
        color: #fff;
        background: linear-gradient(135deg, #6366f1, #4f46e5);
        border: none;
        text-decoration: none;
        transition: all 0.2s ease;
        box-shadow: 0 2px 6px rgba(99, 102, 241, 0.25);
    }
    .sc-card-footer .sc-footer-btn:hover {
        background: linear-gradient(135deg, #4f46e5, #4338ca);
        color: #fff;
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(99, 102, 241, 0.35);
    }

    /* Status badges */
    .sc-status-badge {
        display: inline-flex;
        align-items: center;
        gap: 0.3rem;
        padding: 0.25rem 0.6rem;
        border-radius: 8px;
        font-size: 0.72rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.03em;
    }
    .sc-status-badge.badge-ok {
        background: #dcfce7;
        color: #166534;
    }
    .sc-status-badge.badge-low {
        background: #fef3c7;
        color: #92400e;
    }
    .sc-status-badge.badge-out {
        background: #fee2e2;
        color: #991b1b;
    }

    /* Grid layout for cards */
    .sc-product-grid {
        display: flex;
        flex-direction: column;
        gap: 0.75rem;
    }

    /* Pagination / Load more */
    .sc-load-more {
        display: flex;
        justify-content: center;
        padding: 1.5rem 0;
    }

    /* Empty state */
    .sc-empty-state {
        text-align: center;
        padding: 3rem 1rem;
    }
    .sc-empty-state i {
        font-size: 3rem;
        color: var(--text-secondary, #94a3b8);
        margin-bottom: 1rem;
    }
    .sc-empty-state h5 {
        color: var(--text-secondary, #64748b);
        font-weight: 600;
    }

    /* Footer showing text */
    .sc-footer {
        text-align: center;
        padding: 1rem 0;
        font-size: 0.8rem;
        color: var(--text-secondary, #94a3b8);
    }

    /* Responsive tweaks */
    @media (min-width: 768px) {
        .sc-product-card {
            padding: 1.25rem;
        }
        .sc-product-img {
            width: 100px;
            height: 100px;
            min-width: 100px;
        }
        .sc-product-name {
            font-size: 1.15rem;
        }
        .sc-sku-badge {
            font-size: 0.95rem;
        }
        .sc-stock-number {
            font-size: 2.5rem;
        }
    }

    @media (max-width: 480px) {
        .sc-page-header {
            padding: 1.25rem 1rem;
            border-radius: 12px;
        }
        .sc-page-header h1 {
            font-size: 1.2rem;
        }
        .sc-stat-chip {
            padding: 0.5rem 0.75rem;
            font-size: 0.78rem;
        }
        .sc-product-img {
            width: 65px;
            height: 65px;
            min-width: 65px;
        }
        .sc-stock-number {
            font-size: 1.8rem;
        }
        .sc-card-footer .sc-footer-btn {
            width: 100%;
            justify-content: center;
        }
    }

    /* Highlight animation for search matches */
    @keyframes scPulse {
        0% { box-shadow: 0 0 0 0 rgba(59, 130, 246, 0.3); }
        70% { box-shadow: 0 0 0 6px rgba(59, 130, 246, 0); }
        100% { box-shadow: 0 0 0 0 rgba(59, 130, 246, 0); }
    }
    .sc-product-card.sc-highlight {
        animation: scPulse 0.6s ease-out;
    }

    /* ============================================
       CAMERA BARCODE SCANNER
       ============================================ */
    .sc-scan-btn {
        position: absolute;
        right: 0.6rem;
        top: 50%;
        transform: translateY(-50%);
        width: 42px;
        height: 42px;
        border-radius: 10px;
        border: none;
        background: linear-gradient(135deg, #2563eb, #1d4ed8);
        color: #fff;
        font-size: 1.1rem;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        transition: all 0.2s ease;
        box-shadow: 0 2px 8px rgba(37, 99, 235, 0.3);
        z-index: 2;
    }
    .sc-scan-btn:hover {
        background: linear-gradient(135deg, #1d4ed8, #1e40af);
        transform: translateY(-50%) scale(1.05);
        box-shadow: 0 4px 12px rgba(37, 99, 235, 0.4);
    }
    .sc-scan-btn:active {
        transform: translateY(-50%) scale(0.97);
    }

    /* Scanner Modal Overlay */
    .sc-scanner-overlay {
        display: none;
        position: fixed;
        inset: 0;
        z-index: 9999;
        background: rgba(0, 0, 0, 0.85);
        backdrop-filter: blur(4px);
        flex-direction: column;
        align-items: center;
        justify-content: center;
        padding: 1rem;
    }
    .sc-scanner-overlay.active {
        display: flex;
    }
    .sc-scanner-box {
        width: 100%;
        max-width: 450px;
        background: #111827;
        border-radius: 20px;
        overflow: hidden;
        box-shadow: 0 25px 50px rgba(0, 0, 0, 0.5);
    }
    .sc-scanner-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 1rem 1.25rem;
        background: #1f2937;
    }
    .sc-scanner-header h6 {
        margin: 0;
        color: #fff;
        font-weight: 700;
        font-size: 0.95rem;
    }
    .sc-scanner-close {
        width: 36px;
        height: 36px;
        border-radius: 50%;
        border: none;
        background: rgba(255,255,255,0.1);
        color: #fff;
        font-size: 1.1rem;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        transition: background 0.2s;
    }
    .sc-scanner-close:hover {
        background: rgba(239, 68, 68, 0.7);
    }
    #sc-camera-viewfinder {
        width: 100%;
        aspect-ratio: 4/3;
        background: #000;
    }
    #sc-camera-viewfinder video {
        width: 100% !important;
        height: 100% !important;
        object-fit: cover !important;
    }
    .sc-scanner-footer {
        padding: 1rem 1.25rem;
        text-align: center;
        background: #1f2937;
    }
    .sc-scanner-footer .sc-scanned-code {
        font-size: 1.5rem;
        font-weight: 800;
        color: #22c55e;
        font-family: monospace;
        letter-spacing: 1px;
        min-height: 2rem;
    }
    .sc-scanner-footer .sc-scanner-hint {
        font-size: 0.78rem;
        color: rgba(255,255,255,0.5);
        margin-top: 0.25rem;
    }

    @keyframes scScanLine {
        0%, 100% { top: 10%; }
        50% { top: 85%; }
    }
    .sc-scan-line {
        position: absolute;
        left: 5%;
        right: 5%;
        height: 2px;
        background: linear-gradient(90deg, transparent, #22c55e, transparent);
        box-shadow: 0 0 8px #22c55e;
        animation: scScanLine 2s ease-in-out infinite;
        z-index: 1;
    }
</style>

<div class="container-fluid p-3 p-md-4" style="max-width: 900px; margin: 0 auto;">

    <!-- Page Header -->
    <div class="sc-page-header">
        <h1><i class="fa-solid fa-magnifying-glass-chart me-2"></i>Stock Checker</h1>
        <div class="sc-subtitle">Search products by name, barcode, or SKU to verify stock levels</div>
    </div>

    <!-- Stat Chips -->
    <div class="sc-stat-row">
        <a href="stock_checker.php" class="sc-stat-chip chip-total <?= empty($filter) ? 'chip-active' : '' ?>">
            <i class="fa-solid fa-boxes-stacked"></i>
            <span class="chip-count"><?= number_format($total_items) ?></span>
            <span>Products</span>
        </a>
        <a href="stock_checker.php?filter=low_stock" class="sc-stat-chip chip-low <?= $filter === 'low_stock' ? 'chip-active' : '' ?>">
            <i class="fa-solid fa-triangle-exclamation"></i>
            <span class="chip-count"><?= number_format($low_stock_count) ?></span>
            <span>Low</span>
        </a>
        <a href="stock_checker.php?filter=out_of_stock" class="sc-stat-chip chip-out <?= $filter === 'out_of_stock' ? 'chip-active' : '' ?>">
            <i class="fa-solid fa-circle-xmark"></i>
            <span class="chip-count"><?= number_format($out_of_stock_count) ?></span>
            <span>Out</span>
        </a>
    </div>

    <!-- Search Bar -->
    <div class="sc-search-wrap">
        <i class="fa-solid fa-barcode sc-search-icon"></i>
        <input type="text" id="sc-search-input" class="sc-search-input"
            placeholder="Scan barcode or type product name..."
            value="<?= htmlspecialchars($search ?? '') ?>" autocomplete="off" autofocus
            style="padding-right: 3.5rem;">
        <button type="button" class="sc-scan-btn" id="sc-cam-scan-btn" title="Scan with camera">
            <i class="fa-solid fa-camera"></i>
        </button>
        <div class="sc-search-spinner" id="sc-spinner">
            <i class="fa-solid fa-spinner fa-spin text-primary"></i>
        </div>
    </div>

    <!-- Camera Scanner Modal -->
    <div class="sc-scanner-overlay" id="sc-scanner-overlay">
        <div class="sc-scanner-box">
            <div class="sc-scanner-header">
                <h6><i class="fa-solid fa-camera me-2"></i>Camera Scanner</h6>
                <button class="sc-scanner-close" id="sc-scanner-close-btn">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
            <div style="position: relative;">
                <div id="sc-camera-viewfinder"></div>
                <div class="sc-scan-line"></div>
            </div>
            <div class="sc-scanner-footer">
                <div class="sc-scanned-code" id="sc-scanned-code">—</div>
                <div class="sc-scanner-hint">Point camera at barcode</div>
            </div>
        </div>
    </div>

    <!-- Product List -->
    <div class="sc-product-grid" id="sc-product-grid">
        <?php if (count($products) > 0): ?>
            <?php foreach ($products as $row):
                $qty = $row['current_stock'];
                $online = $row['online_stock'] ?? 0;
                $phys = max(0, $qty - $online);
                $thresh = $row['low_stock_threshold'];

                if ($phys == 0) {
                    $stockClass = 'stock-out';
                    $stockNumClass = 'stock-out';
                } elseif ($phys <= $thresh) {
                    $stockClass = 'stock-low';
                    $stockNumClass = 'stock-low';
                } else {
                    $stockClass = 'stock-ok';
                    $stockNumClass = 'stock-ok';
                }
            ?>
                <div class="sc-product-card <?= $stockClass ?>">
                    <!-- Product Image -->
                    <div class="sc-product-img">
                        <?php if (!empty($row['image_path'])): ?>
                            <img src="<?= BASE_URL . $row['image_path'] ?>" loading="lazy" decoding="async"
                                alt="<?= htmlspecialchars($row['product_name']) ?>">
                        <?php else: ?>
                            <i class="fa-solid fa-cube sc-no-img"></i>
                        <?php endif; ?>
                    </div>

                    <!-- Product Info -->
                    <div class="sc-product-info">
                        <div class="sc-product-name"><?= htmlspecialchars($row['product_name'] ?? '') ?></div>
                        <div class="sc-product-meta">
                            <?= htmlspecialchars($row['brand_name'] ?? '') ?> &middot;
                            <span class="sc-variation"><?= htmlspecialchars($row['variation_name'] ?? '') ?></span>
                        </div>
                        <?php if (!empty($row['sku'])): ?>
                            <span class="sc-sku-badge"><?= htmlspecialchars($row['sku']) ?></span>
                        <?php endif; ?>
                        <?php if (!empty($row['barcode'])): ?>
                            <span class="sc-sku-badge ms-1"><i class="fa-solid fa-barcode me-1"></i><?= htmlspecialchars($row['barcode']) ?></span>
                        <?php endif; ?>

                        <!-- Status badge (mobile-friendly inline) -->
                        <div class="mt-2 d-md-none">
                            <?php if ($phys == 0): ?>
                                <span class="sc-status-badge badge-out"><i class="fa-solid fa-circle-xmark"></i> Out of Stock</span>
                            <?php elseif ($phys <= $thresh): ?>
                                <span class="sc-status-badge badge-low"><i class="fa-solid fa-triangle-exclamation"></i> Low Stock</span>
                            <?php else: ?>
                                <span class="sc-status-badge badge-ok"><i class="fa-solid fa-circle-check"></i> In Stock</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Stock Display -->
                    <div class="sc-stock-display">
                        <div class="sc-stock-number <?= $stockNumClass ?>"><?= number_format($phys) ?></div>
                        <div class="sc-stock-unit"><?= htmlspecialchars($row['unit_type'] ?? 'pc') ?></div>
                        <div class="sc-stock-label">Physical</div>

                        <?php if (!empty($row['is_shopee_mapped']) || ($row['shopee_stock'] ?? 0) > 0): ?>
                            <div class="sc-online-badge" title="Reserved for Shopee">
                                <i class="fa-solid fa-globe" style="color: #ee4d2d;"></i>
                                <span><?= ($row['shopee_stock'] ?? 0) ?> online</span>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($row['is_lazada_mapped']) || ($row['lazada_stock'] ?? 0) > 0): ?>
                            <div class="sc-online-badge" title="Reserved for Lazada">
                                <i class="fa-solid fa-globe" style="color: #002db4;"></i>
                                <span><?= ($row['lazada_stock'] ?? 0) ?> online</span>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Card Footer with History Button -->
                    <div class="sc-card-footer">
                        <a href="history.php?id=<?= $row['variation_id'] ?>" class="sc-footer-btn" title="View stock-in history">
                            <i class="fa-solid fa-clock-rotate-left"></i>
                            View Stock History
                        </a>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="sc-empty-state">
                <i class="fa-solid fa-box-open d-block"></i>
                <h5>No products found</h5>
                <p class="text-muted">Try a different search term or clear the filter</p>
            </div>
        <?php endif; ?>
    </div>

    <!-- Load More / Infinite Scroll Trigger -->
    <div class="sc-load-more" id="sc-load-more">
        <div class="spinner-border text-primary spinner-border-sm d-none" id="sc-infinite-spinner" role="status">
            <span class="visually-hidden">Loading...</span>
        </div>
    </div>

    <!-- Footer -->
    <div class="sc-footer" id="sc-footer-text">
        Showing <?= count($products) > 0 ? $offset + 1 : 0 ?> to <?= min($offset + $limit, $total_items) ?> of <?= $total_items ?> items
    </div>
</div>

<script>
    // =====================================================
    // STOCK CHECKER SEARCH MODULE
    // =====================================================
    const StockCheckerSearch = {
        debounceTimer: null,
        currentFilter: '<?= $filter ?>',
        spinner: null,
        searchInput: null,
        grid: null,
        footerText: null,
        loadMoreTrigger: null,
        infiniteSpinner: null,
        currentPage: <?= $page ?>,
        pageSize: 30,

        // Progressive Loading State
        observer: null,
        isFetching: false,
        hasMore: true,
        initialHtml: '',
        currentFetchController: null,

        init() {
            this.searchInput = document.getElementById('sc-search-input');
            this.spinner = document.getElementById('sc-spinner');
            this.grid = document.getElementById('sc-product-grid');
            this.footerText = document.getElementById('sc-footer-text');
            this.loadMoreTrigger = document.getElementById('sc-load-more');
            this.infiniteSpinner = document.getElementById('sc-infinite-spinner');

            if (!this.searchInput) return;

            // Cache initial HTML
            this.initialHtml = this.grid ? this.grid.innerHTML : '';

            // Debounced live search
            this.searchInput.addEventListener('input', (e) => {
                clearTimeout(this.debounceTimer);
                this.debounceTimer = setTimeout(() => {
                    this.currentPage = 1;
                    this.hasMore = true;
                    this.performSearch(e.target.value.trim());
                }, 150);
            });

            // Handle Enter key
            this.searchInput.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    clearTimeout(this.debounceTimer);
                    this.currentPage = 1;
                    this.hasMore = true;
                    this.performSearch(this.searchInput.value.trim());
                }
            });

            // Infinite Scroll
            if (this.loadMoreTrigger) {
                this.observer = new IntersectionObserver((entries) => {
                    if (entries[0].isIntersecting && this.hasMore && !this.isFetching) {
                        this.currentPage++;
                        this.performSearch(this.searchInput.value.trim(), true);
                    }
                }, { rootMargin: '200px' });

                this.observer.observe(this.loadMoreTrigger);
            }
        },

        performSearch(query, isAppending = false) {
            if (this.isFetching && isAppending) return;

            let url = `../../api/inventory/search_products.php?q=${encodeURIComponent(query)}`;
            url += `&page=${this.currentPage}&limit=${this.pageSize}`;

            if (this.currentFilter) {
                url += `&filter=${encodeURIComponent(this.currentFilter)}`;
            }

            // Sync browser URL
            if (!isAppending) {
                let queryParams = new URLSearchParams();
                if (query) queryParams.set('search', query);
                if (this.currentFilter) queryParams.set('filter', this.currentFilter);
                let browserUrl = window.location.pathname;
                if (queryParams.toString() !== '') browserUrl += '?' + queryParams.toString();
                history.replaceState(null, '', browserUrl);
            }

            if (this.currentFetchController) {
                this.currentFetchController.abort();
            }

            // Restore initial if empty
            if (query === '' && this.currentPage === 1 && !this.currentFilter) {
                if (this.grid) this.grid.innerHTML = this.initialHtml;
                this.isFetching = false;
                this.spinner?.classList.remove('show');
                return;
            }

            this.currentFetchController = new AbortController();
            this.isFetching = true;

            if (isAppending) {
                this.infiniteSpinner?.classList.remove('d-none');
            } else {
                this.spinner?.classList.add('show');
            }

            url += `&_t=${Date.now()}`;

            fetch(url, {
                signal: this.currentFetchController.signal,
                cache: 'no-store',
                headers: { 'Pragma': 'no-cache', 'Cache-Control': 'no-cache' }
            })
            .then(res => res.json())
            .then(data => {
                this.isFetching = false;
                const products = data.products || [];
                const pagination = data.pagination || {};

                this.hasMore = this.currentPage < (pagination.total_pages || 1);
                this.renderResults(products, query, isAppending);
                this.updateFooter(pagination);
            })
            .catch(err => {
                if (err.name !== 'AbortError') {
                    console.error('Search error:', err);
                    this.isFetching = false;
                }
            })
            .finally(() => {
                if (!this.currentFetchController || !this.currentFetchController.signal.aborted) {
                    this.spinner?.classList.remove('show');
                    this.infiniteSpinner?.classList.add('d-none');
                }
            });
        },

        renderResults(products, query, isAppending) {
            if (!this.grid) return;

            if (products.length === 0 && !isAppending) {
                this.grid.innerHTML = `
                    <div class="sc-empty-state">
                        <i class="fa-solid fa-box-open d-block"></i>
                        <h5>No products found</h5>
                        <p class="text-muted">Try a different search term or clear the filter</p>
                    </div>`;
                return;
            }

            const baseUrl = '<?= BASE_URL ?>';
            const html = products.map(row => this.renderCard(row, baseUrl, query)).join('');

            if (isAppending) {
                this.grid.insertAdjacentHTML('beforeend', html);
            } else {
                this.grid.innerHTML = html;
            }
        },

        renderCard(row, baseUrl, query) {
            const qty = parseFloat(row.current_stock) || 0;
            const online = parseFloat(row.online_stock) || 0;
            const phys = Math.max(0, qty - online);
            const thresh = parseFloat(row.low_stock_threshold) || 0;

            let stockClass = 'stock-ok';
            let stockNumClass = 'stock-ok';
            let statusBadge = '<span class="sc-status-badge badge-ok"><i class="fa-solid fa-circle-check"></i> In Stock</span>';

            if (phys === 0) {
                stockClass = 'stock-out';
                stockNumClass = 'stock-out';
                statusBadge = '<span class="sc-status-badge badge-out"><i class="fa-solid fa-circle-xmark"></i> Out of Stock</span>';
            } else if (phys <= thresh) {
                stockClass = 'stock-low';
                stockNumClass = 'stock-low';
                statusBadge = '<span class="sc-status-badge badge-low"><i class="fa-solid fa-triangle-exclamation"></i> Low Stock</span>';
            }

            // Highlight helper
            const safeQuery = query ? query.replace(/[.*+?^${}()|[\]\\]/g, '\\$&').split(/\s+/).filter(Boolean) : [];
            const highlight = (text) => {
                if (!text) return '';
                let hl = this.escapeHtml(text);
                if (safeQuery.length === 0) return hl;
                safeQuery.forEach(q => {
                    const regex = new RegExp(`(${q})`, 'gi');
                    hl = hl.replace(regex, '<mark class="bg-warning bg-opacity-50 p-0 rounded text-dark">$1</mark>');
                });
                return hl;
            };

            const imgHtml = row.image_path
                ? `<img src="${baseUrl}${row.image_path}" loading="lazy" decoding="async" alt="${this.escapeHtml(row.product_name || '')}">`
                : '<i class="fa-solid fa-cube sc-no-img"></i>';

            const skuHtml = row.sku
                ? `<span class="sc-sku-badge">${highlight(row.sku)}</span>` : '';
            const barcodeHtml = row.barcode
                ? `<span class="sc-sku-badge ms-1"><i class="fa-solid fa-barcode me-1"></i>${highlight(row.barcode)}</span>` : '';

            const shopeeStock = parseInt(row.shopee_stock) || 0;
            const lazadaStock = parseInt(row.lazada_stock) || 0;
            
            const shopeeHtml = (row.is_shopee_mapped || shopeeStock > 0)
                ? `<div class="sc-online-badge" title="Reserved for Shopee"><i class="fa-solid fa-globe" style="color: #ee4d2d;"></i><span>${shopeeStock.toLocaleString()} online</span></div>` : '';
                
            const lazadaHtml = (row.is_lazada_mapped || lazadaStock > 0)
                ? `<div class="sc-online-badge" title="Reserved for Lazada"><i class="fa-solid fa-globe" style="color: #002db4;"></i><span>${lazadaStock.toLocaleString()} online</span></div>` : '';

            const unit = this.escapeHtml(row.unit_type || 'pc');

            return `
                <div class="sc-product-card ${stockClass} sc-highlight">
                    <div class="sc-product-img">${imgHtml}</div>
                    <div class="sc-product-info">
                        <div class="sc-product-name">${highlight(row.product_name || '')}</div>
                        <div class="sc-product-meta">
                            ${highlight(row.brand_name || '')} &middot;
                            <span class="sc-variation">${highlight(row.variation_name || '')}</span>
                        </div>
                        ${skuHtml}${barcodeHtml}
                        <div class="mt-2 d-md-none">${statusBadge}</div>
                    </div>
                    <div class="sc-stock-display">
                        <div class="sc-stock-number ${stockNumClass}">${phys.toLocaleString()}</div>
                        <div class="sc-stock-unit">${unit}</div>
                        <div class="sc-stock-label">Physical</div>
                        ${shopeeHtml}
                        ${lazadaHtml}
                    </div>

                    <div class="sc-card-footer">
                        <a href="history.php?id=${row.variation_id}" class="sc-footer-btn" title="View stock-in history">
                            <i class="fa-solid fa-clock-rotate-left"></i>
                            View Stock History
                        </a>
                    </div>
                </div>`;
        },

        updateFooter(pagination) {
            if (!this.footerText) return;
            const total = parseInt(pagination.total_items) || 0;
            const page = parseInt(pagination.current_page) || 1;
            const limit = parseInt(pagination.limit) || 30;
            const end = Math.min(page * limit, total);
            this.footerText.textContent = `Showing ${total > 0 ? 1 : 0} to ${end} of ${total} items`;
        },

        escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text || '';
            return div.innerHTML;
        }
    };

    // Prevent bfcache
    window.addEventListener('pageshow', function(event) {
        if (event.persisted) {
            window.location.reload();
        }
    });

    document.addEventListener('DOMContentLoaded', function() {
        StockCheckerSearch.init();
    });
</script>

<!-- Camera Barcode Scanner Library -->
<script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
<script>
    // =====================================================
    // CAMERA BARCODE SCANNER MODULE
    // =====================================================
    const CameraScanner = {
        scanner: null,
        isActive: false,
        overlay: null,
        codeDisplay: null,
        cooldown: false,

        init() {
            this.overlay = document.getElementById('sc-scanner-overlay');
            this.codeDisplay = document.getElementById('sc-scanned-code');

            const openBtn = document.getElementById('sc-cam-scan-btn');
            const closeBtn = document.getElementById('sc-scanner-close-btn');

            if (!openBtn || !this.overlay) return;

            openBtn.addEventListener('click', () => this.open());
            closeBtn.addEventListener('click', () => this.close());

            // Close on overlay background click
            this.overlay.addEventListener('click', (e) => {
                if (e.target === this.overlay) this.close();
            });

            // Close on Escape key
            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape' && this.isActive) this.close();
            });
        },

        async open() {
            if (this.isActive) return;
            this.isActive = true;
            this.overlay.classList.add('active');
            this.codeDisplay.textContent = '—';

            try {
                this.scanner = new Html5Qrcode('sc-camera-viewfinder');

                await this.scanner.start(
                    { facingMode: 'environment' }, // Rear camera
                    {
                        fps: 15,
                        qrbox: { width: 280, height: 120 },
                        aspectRatio: 4 / 3,
                        formatsToSupport: [
                            Html5QrcodeSupportedFormats.EAN_13,
                            Html5QrcodeSupportedFormats.EAN_8,
                            Html5QrcodeSupportedFormats.UPC_A,
                            Html5QrcodeSupportedFormats.UPC_E,
                            Html5QrcodeSupportedFormats.CODE_128,
                            Html5QrcodeSupportedFormats.CODE_39,
                            Html5QrcodeSupportedFormats.CODE_93,
                            Html5QrcodeSupportedFormats.ITF,
                            Html5QrcodeSupportedFormats.QR_CODE
                        ]
                    },
                    (decodedText) => this.onScanSuccess(decodedText),
                    () => {} // Ignore scan failures (noise)
                );
            } catch (err) {
                console.error('Camera error:', err);
                alert('Could not access camera. Please allow camera permission and try again.');
                this.close();
            }
        },

        onScanSuccess(decodedText) {
            if (this.cooldown) return;
            this.cooldown = true;

            // Show the scanned code
            this.codeDisplay.textContent = decodedText;

            // Vibrate for feedback (if supported)
            if (navigator.vibrate) navigator.vibrate(100);

            // Fill the search bar and trigger search
            const searchInput = document.getElementById('sc-search-input');
            if (searchInput) {
                searchInput.value = decodedText;
                searchInput.dispatchEvent(new Event('input', { bubbles: true }));
            }

            // Close after a brief delay so user sees the result
            setTimeout(() => {
                this.close();
                this.cooldown = false;
            }, 600);
        },

        async close() {
            if (this.scanner) {
                try {
                    await this.scanner.stop();
                    this.scanner.clear();
                } catch (e) {
                    // Ignore stop errors
                }
                this.scanner = null;
            }
            this.isActive = false;
            this.overlay.classList.remove('active');

            // Refocus search bar
            const searchInput = document.getElementById('sc-search-input');
            if (searchInput) searchInput.focus();
        }
    };

    document.addEventListener('DOMContentLoaded', function() {
        CameraScanner.init();
    });
</script>

<?php require_once '../../includes/footer.php'; ?>

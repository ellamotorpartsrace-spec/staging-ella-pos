<?php
$content = file_get_contents('views/shopee/logs.php');

$replacements = [
    'shopee_sync_logs' => 'lazada_sync_logs',
    'shopee_item_id' => 'lazada_item_id',
    'shopee_product_mappings' => 'lazada_product_mappings',
    '/shopee/' => '/lazada/',
    'Shopee' => 'Lazada',
    'shopee' => 'lazada',
    'sp-' => 'lz-',
    'var(--shopee-primary)' => 'var(--lazada-primary)',
    'var(--shopee-light)' => 'var(--lazada-light)',
    '#ee4d2d' => '#1e3a8a', // Using a dark blue for borders/highlights
    'rgba(238, 77, 45, 0.1)' => 'rgba(30, 58, 138, 0.1)',
    'rgba(238, 77, 45, 0.25)' => 'rgba(30, 58, 138, 0.25)',
    'rgba(238, 77, 45, 0.12)' => 'rgba(30, 58, 138, 0.12)',
    'rgba(238,77,45,0.12)' => 'rgba(30,58,138,0.12)',
    'rgba(238,77,45,0.1)' => 'rgba(30,58,138,0.1)',
    'rgba(238,77,45,0.08)' => 'rgba(30,58,138,0.08)',
    'text-shopee' => 'text-lazada',
    'shopee-sync.css' => 'lazada-sync.css',
    'shopee_token_warning.php' => 'lazada_token_warning.php',
    'shopee_alerts.js' => 'lazada_alerts.js',
];

foreach ($replacements as $search => $replace) {
    // We want to be careful with 'shopee' -> 'lazada' lowercase to not mess up variable names that might be generic, but in this context it's fine
    // However, str_replace is case-sensitive, which is good.
    $content = str_replace($search, $replace, $content);
}

// Ensure the page title didn't become "Lazada Sync - Lazada Sync Logs" instead of "Lazada Sync Logs"
$content = str_replace('Lazada Sync — Lazada Sync Logs', 'Lazada Sync — Sync Logs', $content);

// For the Hero Header, we'll want to add the blue gradient that Lazada uses
// Find the sp-title / sp-subtitle block and wrap it in the hero header style
$heroBlockOld = '<div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-3">
        <div>
            <h1 class="lz-title mb-0"><i class="fa-solid fa-clock-rotate-left text-lazada me-2"></i>Sync Logs</h1>
            <p class="lz-subtitle mb-0">Track every stock update, product sync, mapping change, and system event</p>
        </div>
        <div class="d-flex gap-2">
            <!-- Clear History button removed -->
        </div>
    </div>';

$heroBlockNew = '<div class="mb-4" style="background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%); border-radius: 16px; padding: 2rem 2.5rem; box-shadow: 0 10px 30px rgba(30,58,138,0.15); position: relative; overflow: hidden;">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3" style="position: relative; z-index: 2;">
            <div class="d-flex align-items-center gap-3">
                <div style="background: white; border-radius: 14px; width: 64px; height: 64px; display: flex; align-items: center; justify-content: center; box-shadow: 0 4px 15px rgba(0,0,0,0.1);">
                    <i class="fa-solid fa-clock-rotate-left" style="color: #2563eb; font-size: 1.8rem;"></i>
                </div>
                <div>
                    <h1 class="mb-1 fw-bolder" style="font-size: 2rem; letter-spacing: -0.5px; color: white;">Sync Logs</h1>
                    <p class="mb-0" style="color: rgba(255,255,255,0.8); font-size: 0.95rem;">Track every stock update, product sync, mapping change, and system event</p>
                </div>
            </div>
            <div class="d-flex gap-2">
            </div>
        </div>
        <!-- Decorative bg -->
        <div style="position: absolute; top: -50px; right: -50px; width: 300px; height: 300px; background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, rgba(255,255,255,0) 70%); border-radius: 50%; z-index: 1;"></div>
    </div>';

$content = str_replace($heroBlockOld, $heroBlockNew, $content);

// In the breadcrumb, remove the old one since it conflicts with the new hero layout style
$oldBreadcrumb = '<div class="lz-breadcrumb">
        <a href="<?= BASE_URL ?>views/lazada/index.php">Lazada Sync</a>
        <i class="fa-solid fa-chevron-right" style="font-size:0.6rem"></i>
        <span>Sync Logs</span>
    </div>';
$content = str_replace($oldBreadcrumb, '', $content);

// Put the breadcrumb inside the hero header correctly
$breadcrumbHTML = '
        <!-- Breadcrumb inside -->
        <nav aria-label="breadcrumb" style="position: relative; z-index: 2;">
            <ol class="breadcrumb mb-3" style="font-size: 0.85rem;">
                <li class="breadcrumb-item"><a href="<?= BASE_URL ?>views/lazada/index.php" style="color: rgba(255,255,255,0.7); text-decoration: none; font-weight: 500;">Lazada Dashboard</a></li>
                <li class="breadcrumb-item active" style="color: white; font-weight: 600;">Sync Logs</li>
            </ol>
        </nav>';
$content = str_replace('<div class="d-flex flex-wrap justify-content-between align-items-center gap-3"', $breadcrumbHTML . "\n" . '        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3"', $content);

file_put_contents('views/lazada/logs.php', $content);
echo "Successfully generated views/lazada/logs.php\n";

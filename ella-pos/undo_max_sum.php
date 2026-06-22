<?php
$files = [
    'views/inventory/history.php',
    'views/inventory/edit.php',
    'views/inventory/adjustment.php',
    'includes/stock_guard.php',
    'benchmark.php',
    'api/shopee/fetch_mapped_live_stocks.php',
    'api/shopee/sync_helpers.php',
    'api/shopee/sync_individual.php',
    'api/shopee/sync_pos_inventory.php',
    'api/shopee/update_allocation.php',
    'api/pos/simple_search.php',
    'api/lazada/fetch_mapped_live_stocks.php',
    'api/lazada/sync_helpers.php',
    'api/lazada/sync_individual.php',
    'api/lazada/sync_pos_inventory.php',
    'api/lazada/update_allocation.php'
];

foreach ($files as $file) {
    if (!file_exists($file)) {
        echo "File not found: $file\n";
        continue;
    }
    
    $content = file_get_contents($file);
    $newContent = str_replace(
        ['MAX(m.shopee_stock', 'MAX(m.lazada_stock'],
        ['SUM(m.shopee_stock', 'SUM(m.lazada_stock'],
        $content
    );
    
    if ($content !== $newContent) {
        file_put_contents($file, $newContent);
        echo "Updated $file\n";
    } else {
        echo "No changes needed for $file\n";
    }
}
echo "Done.\n";

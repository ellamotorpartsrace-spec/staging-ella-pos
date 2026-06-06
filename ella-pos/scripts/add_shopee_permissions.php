<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

try {
    $db = new Database();
    $conn = $db->getConnection();

    $permissions = [
        ['shopee_dashboard', 'Shopee Dashboard', 'View Shopee synchronization dashboard and key metrics', 'Shopee Module'],
        ['shopee_products', 'Shopee Products', 'Browse local and online synced listings catalog', 'Shopee Module'],
        ['shopee_mapping', 'Product Mapping', 'Match local ERP items to Shopee listings', 'Shopee Module'],
        ['shopee_allocation', 'Stock Allocation', 'Manage online stock safety limits and rules', 'Shopee Module'],
        ['shopee_resolution', 'Resolution Center', 'Detect and fix duplicate SKU inventory blocks', 'Shopee Module'],
        ['shopee_logs', 'Sync Logs', 'Review automatic stock and order update events', 'Shopee Module'],
        ['shopee_settings', 'Settings & Setup', 'Configure API settings and system sync loops', 'Shopee Module'],
    ];

    $stmt = $conn->prepare("INSERT IGNORE INTO permissions (slug, name, description, module) VALUES (?, ?, ?, ?)");
    foreach ($permissions as $p) {
        $stmt->execute($p);
        echo "Inserted/Checked permission: {$p[0]}\n";
    }

    echo "Done.\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

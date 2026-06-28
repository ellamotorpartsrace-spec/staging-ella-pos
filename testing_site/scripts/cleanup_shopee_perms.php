<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

try {
    $db = new Database();
    $conn = $db->getConnection();
    
    // Delete the old 'shopee_sync' permission
    $conn->query("DELETE FROM permissions WHERE slug = 'shopee_sync'");
    $conn->query("DELETE FROM role_permissions WHERE permission_slug = 'shopee_sync'");
    
    // Also, update the "Shopee" module name to be consistent with "Shopee Module"
    $conn->query("UPDATE permissions SET module = 'Shopee' WHERE module = 'Shopee Module'");
    
    echo "Old permission removed and module name unified.\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

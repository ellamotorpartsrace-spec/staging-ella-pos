<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

try {
    $db = new Database();
    $conn = $db->getConnection();
    
    $slugs_to_remove = [
        'shopee_dashboard',
        'shopee_products',
        'shopee_resolution',
        'shopee_logs',
        'shopee_settings'
    ];
    
    foreach ($slugs_to_remove as $slug) {
        $conn->prepare("DELETE FROM permissions WHERE slug = ?")->execute([$slug]);
        $conn->prepare("DELETE FROM role_permissions WHERE permission_slug = ?")->execute([$slug]);
    }
    
    echo "Unwanted permissions removed.\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

<?php
// api/system/get_role_permissions.php
header('Content-Type: application/json');
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';

requireLogin();
if (!in_array($_SESSION['role'], ['admin', 'super_admin']) && !hasPermission('manage_settings')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Permission denied']);
    exit;
}

try {
    $db = new Database();
    $conn = $db->getConnection();

    // Auto-seed default permissions if they don't exist
    $defaultPermissions = [
        ['slug' => 'manage_finance', 'name' => 'Manage Finances', 'description' => 'Can edit or void financial records', 'module' => 'Finance'],
        ['slug' => 'view_expenses', 'name' => 'View Expenses', 'description' => 'Can view business expenses module', 'module' => 'Finance'],
        ['slug' => 'view_finance', 'name' => 'View Finances', 'description' => 'Can monitor Home Credit & installment data', 'module' => 'Finance'],
        ['slug' => 'view_payables', 'name' => 'View Payables', 'description' => 'Can view store payables', 'module' => 'Finance'],
        ['slug' => 'view_receivables', 'name' => 'View Receivables', 'description' => 'Can view debt collections and receivables', 'module' => 'Finance'],
        ['slug' => 'view_wallet_ledger', 'name' => 'View Wallet Ledger', 'description' => 'Can monitor all wallet credits and debits', 'module' => 'Finance'],
        ['slug' => 'adjust_prices', 'name' => 'Adjust Prices', 'description' => 'Can adjust product prices', 'module' => 'Inventory'],
        ['slug' => 'adjust_stock', 'name' => 'Adjust Stocks', 'description' => 'Can adjust stock levels directly', 'module' => 'Inventory'],
        ['slug' => 'void_sales', 'name' => 'Void Sales', 'description' => 'Can void processed POS checkouts', 'module' => 'POS'],
        ['slug' => 'pos_preview_receipt', 'name' => 'Preview Receipt', 'description' => 'Can preview receipts before checkout', 'module' => 'Sales'],
        ['slug' => 'make_sales', 'name' => 'Process POS Checkouts', 'description' => 'Can process sales and checkouts', 'module' => 'Sales'],
        ['slug' => 'view_buyers', 'name' => 'View Buyers', 'description' => 'Can manage the buyers and customers database', 'module' => 'Sales'],
        ['slug' => 'view_product_history', 'name' => 'View Product History', 'description' => 'Can view individual item transaction logs', 'module' => 'Sales'],
        ['slug' => 'view_sales', 'name' => 'View Sales Records', 'description' => 'Can view previous receipts and sales records', 'module' => 'Sales'],
        ['slug' => 'manage_service_fees', 'name' => 'Manage Service Fees', 'description' => 'Can manage platform and service fees', 'module' => 'Sales & Finance'],
        ['slug' => 'shopee_mapping', 'name' => 'Product Mapping', 'description' => 'Can map local products to Shopee listings', 'module' => 'Shopee'],
        ['slug' => 'shopee_allocation', 'name' => 'Stock Allocation', 'description' => 'Can allocate stock limits for Shopee listings', 'module' => 'Shopee'],
        ['slug' => 'manage_settings', 'name' => 'Manage Settings', 'description' => 'Can change global POS and store settings', 'module' => 'System'],
        ['slug' => 'manage_users', 'name' => 'Manage Users', 'description' => 'Can create, edit, or delete user accounts', 'module' => 'System'],
        ['slug' => 'view_profit', 'name' => 'View Profit & Capital', 'description' => 'Can see overall capital costs, profits, and markups', 'module' => 'System']
    ];

    $insertStmt = $conn->prepare("INSERT IGNORE INTO permissions (slug, name, description, module) VALUES (:slug, :name, :description, :module)");
    foreach ($defaultPermissions as $perm) {
        $insertStmt->execute($perm);
    }

    // 1. Get all available permissions grouped by module
    $stmtPerms = $conn->query("SELECT slug, name, description, module FROM permissions ORDER BY module ASC, name ASC");
    $allPermissions = $stmtPerms->fetchAll(PDO::FETCH_ASSOC);

    // Group permissions by module for the UI
    $groupedPermissions = [];
    foreach ($allPermissions as $perm) {
        $module = $perm['module'];
        if (!isset($groupedPermissions[$module])) {
            $groupedPermissions[$module] = [];
        }
        $groupedPermissions[$module][] = $perm;
    }

    // 1.5 Get dynamic roles
    $stmtDynamic = $conn->query("SELECT role_slug FROM roles");
    $activeRoles = $stmtDynamic->fetchAll(PDO::FETCH_COLUMN);

    // 2. Get current role_permissions mappings
    $stmtRoles = $conn->query("SELECT role, permission_slug FROM role_permissions");
    $mappings = $stmtRoles->fetchAll(PDO::FETCH_ASSOC);

    // Format mappings into a fast lookup object dynamically
    $rolePermissions = [];
    foreach ($activeRoles as $r) {
        $rolePermissions[$r] = [];
    }

    foreach ($mappings as $row) {
        $r = $row['role'];
        if (isset($rolePermissions[$r])) {
            $rolePermissions[$r][] = $row['permission_slug'];
        }
    }

    echo json_encode([
        'success' => true,
        'grouped_permissions' => $groupedPermissions,
        'role_permissions' => $rolePermissions
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

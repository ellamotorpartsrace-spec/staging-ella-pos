<?php
require_once __DIR__ . '/../config/database.php';

$db = new Database();
$conn = $db->getConnection();

$shopee_tables_sql = file_get_contents(__DIR__ . '/shopee_tables.sql');
$lazada_tables_sql = str_replace(
    ['shopee', 'Shopee', 'SHOPEE'],
    ['lazada', 'Lazada', 'LAZADA'],
    $shopee_tables_sql
);
file_put_contents(__DIR__ . '/lazada_tables.sql', $lazada_tables_sql);

$shopee_sales_sql = file_get_contents(__DIR__ . '/shopee_sales_income_tables.sql');
$lazada_sales_sql = str_replace(
    ['shopee', 'Shopee', 'SHOPEE'],
    ['lazada', 'Lazada', 'LAZADA'],
    $shopee_sales_sql
);
file_put_contents(__DIR__ . '/lazada_sales_income_tables.sql', $lazada_sales_sql);

try {
    $conn->exec($lazada_tables_sql);
    $conn->exec("
    ALTER TABLE stock_movements 
    MODIFY COLUMN type ENUM(
        'shopee_sale',
        'allocation_adjustment',
        'stock_in',
        'stock_out',
        'sales',
        'adjustment',
        'return',
        'allocation_to_online',
        'allocation_to_physical',
        'online_sale',
        'online_adjustment',
        'shopee_balance_sync',
        'lazada_balance_sync'
    ) NOT NULL
");

echo "Lazada DB migrations completed successfully.\n";
    
    $conn->exec($lazada_sales_sql);
    echo "Lazada sales income tables created successfully!\n";
    
} catch (PDOException $e) {
    echo "Error creating tables: " . $e->getMessage() . "\n";
}

// Add lazada integration to api_platforms if not exists
try {
    $stmt = $conn->query("SELECT id FROM api_platforms WHERE platform_name = 'lazada'");
    if (!$stmt->fetch()) {
        $conn->exec("INSERT INTO api_platforms (platform_name, is_test) VALUES ('lazada', 1)");
        echo "Inserted lazada into api_platforms.\n";
    }
} catch (PDOException $e) {
    echo "Error inserting api_platforms: " . $e->getMessage() . "\n";
}

echo "Database migrations for Lazada complete.\n";

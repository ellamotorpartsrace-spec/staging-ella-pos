<?php
/**
 * api/lazada/setup_db.php — Create Database Tables for Lazada Integration
 */
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';

requireRole(['admin', 'super_admin']);

$db = new Database();
$conn = $db->getConnection();

// Optionally DROP tables if we are truly starting fresh
$dropQueries = [
    "DROP TABLE IF EXISTS lazada_product_mappings",
    "DROP TABLE IF EXISTS lazada_config",
    "DROP TABLE IF EXISTS lazada_error_logs",
    "DROP TABLE IF EXISTS lazada_sync_logs",
    "DROP TABLE IF EXISTS lazada_alerts"
];

foreach ($dropQueries as $query) {
    try {
        $conn->exec($query);
    } catch (PDOException $e) {
        // ignore drop errors
    }
}

$queries = [
    "CREATE TABLE IF NOT EXISTS lazada_config (
        id INT AUTO_INCREMENT PRIMARY KEY,
        platform_name VARCHAR(50) NOT NULL UNIQUE,
        app_key VARCHAR(255) NOT NULL,
        app_secret VARCHAR(255) NOT NULL,
        access_token TEXT NULL,
        refresh_token TEXT NULL,
        token_expires_at DATETIME NULL,
        refresh_expires_at DATETIME NULL,
        country_code VARCHAR(10) DEFAULT 'PH',
        seller_id VARCHAR(100) NULL,
        account_id VARCHAR(100) NULL,
        account_name VARCHAR(255) NULL,
        environment VARCHAR(50) DEFAULT 'sandbox',
        enable_stock_sync TINYINT(1) DEFAULT 0,
        respect_allocation TINYINT(1) DEFAULT 1,
        low_stock_alerts TINYINT(1) DEFAULT 1,
        low_stock_threshold INT DEFAULT 5,
        buffer_stock INT DEFAULT 0,
        is_active TINYINT(1) DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )",

    "CREATE TABLE IF NOT EXISTS lazada_product_mappings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        platform_name VARCHAR(50) NOT NULL,
        lazada_item_id BIGINT NOT NULL,
        lazada_sku_id BIGINT NOT NULL,
        lazada_product_name VARCHAR(255) NOT NULL,
        lazada_variation_name VARCHAR(255) NULL,
        lazada_seller_sku VARCHAR(255) NULL,
        lazada_stock INT DEFAULT 0,
        lazada_price DECIMAL(10,2) DEFAULT 0.00,
        lazada_image_url TEXT NULL,
        pos_product_id INT NULL,
        pos_unit_id INT NULL,
        pos_bundle_set_id INT NULL,
        matched_pos_sku VARCHAR(255) NULL,
        stock_allocation_ratio DECIMAL(5,2) DEFAULT 100.00,
        safety_floor INT DEFAULT 0,
        mapping_status ENUM('unmapped', 'auto', 'manual', 'mapped') DEFAULT 'unmapped',
        sync_hash VARCHAR(64) NULL,
        last_synced_at DATETIME NULL,
        sync_status ENUM('active','inactive') DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_mapping (platform_name, lazada_item_id, lazada_sku_id),
        INDEX idx_lazada_item (lazada_item_id),
        INDEX idx_lazada_sku (lazada_seller_sku),
        INDEX idx_pos_product (pos_product_id),
        INDEX idx_platform (platform_name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "CREATE TABLE IF NOT EXISTS lazada_error_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        platform_name VARCHAR(50) DEFAULT 'lazada_main',
        error_type VARCHAR(100) NOT NULL,
        lazada_item_id BIGINT NULL,
        lazada_sku_id BIGINT NULL,
        sku VARCHAR(255) NULL,
        error_message TEXT NOT NULL,
        status ENUM('open', 'resolved', 'ignored') DEFAULT 'open',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        resolved_at DATETIME NULL
    )",

    "CREATE TABLE IF NOT EXISTS lazada_sync_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        platform_name VARCHAR(50) DEFAULT 'lazada_main',
        sync_type VARCHAR(50) NULL,
        event_type VARCHAR(50) NULL,
        lazada_item_id BIGINT NULL,
        lazada_sku_id BIGINT NULL,
        product_name VARCHAR(255) NULL,
        sku VARCHAR(255) NULL,
        old_value VARCHAR(255) NULL,
        new_value VARCHAR(255) NULL,
        source VARCHAR(100) NULL,
        status ENUM('success', 'error', 'warning', 'failed') DEFAULT 'success',
        message TEXT NULL,
        error_message TEXT NULL,
        items_affected INT DEFAULT 0,
        created_by INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )",

    "CREATE TABLE IF NOT EXISTS lazada_alerts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        platform_name VARCHAR(50) DEFAULT 'lazada_main',
        mapping_id INT NULL,
        message TEXT NOT NULL,
        alert_type VARCHAR(50) DEFAULT 'warning',
        is_read TINYINT(1) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )"
];

$successCount = 0;
$errors = [];

foreach ($queries as $query) {
    try {
        $conn->exec($query);
        echo "Table created/verified successfully.<br>\n";
    } catch (PDOException $e) {
        echo "Error creating table: " . $e->getMessage() . "<br>\n";
    }
}

// Ensure the new sync_status column is present (Migration)
try {
    $conn->exec("ALTER TABLE lazada_product_mappings ADD COLUMN sync_status ENUM('active','inactive') DEFAULT 'active'");
    echo "Added sync_status column.<br>\n";
} catch (PDOException $e) {
    // Column already exists or error
}

// Ensure mapping_status enum is updated (Migration)
try {
    $conn->exec("ALTER TABLE lazada_product_mappings MODIFY COLUMN mapping_status ENUM('unmapped', 'auto', 'manual', 'mapped') DEFAULT 'unmapped'");
    echo "Updated mapping_status enum.<br>\n";
} catch (PDOException $e) {
    // Already updated or error
}

// Initial config insert for default main platform
try {
    $stmt = $conn->query("SELECT COUNT(*) FROM lazada_config WHERE platform_name = 'lazada_main'");
    if ($stmt->fetchColumn() == 0) {
        $conn->exec("INSERT INTO lazada_config (platform_name, app_key, app_secret) VALUES ('lazada_main', '', '')");
    }
} catch (PDOException $e) {
    $errors[] = "Config Insert Error: " . $e->getMessage();
}

if (empty($errors)) {
    echo json_encode(['success' => true, 'message' => "Lazada database tables rebuilt successfully!"]);
} else {
    echo json_encode(['success' => false, 'errors' => $errors]);
}


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

$queries = [
    "CREATE TABLE IF NOT EXISTS lazada_config (
        id INT AUTO_INCREMENT PRIMARY KEY,
        app_key VARCHAR(255) NOT NULL,
        app_secret VARCHAR(255) NOT NULL,
        access_token TEXT NULL,
        refresh_token TEXT NULL,
        token_expires_at DATETIME NULL,
        country_code VARCHAR(10) DEFAULT 'PH',
        seller_id VARCHAR(100) NULL,
        environment VARCHAR(50) DEFAULT 'sandbox',
        enable_stock_sync TINYINT(1) DEFAULT 0,
        respect_allocation TINYINT(1) DEFAULT 1,
        low_stock_alerts TINYINT(1) DEFAULT 1,
        sync_interval_mins INT DEFAULT 15,
        low_stock_threshold INT DEFAULT 5,
        is_active TINYINT(1) DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )",

    "CREATE TABLE IF NOT EXISTS lazada_product_mappings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        lazada_item_id BIGINT NOT NULL,
        lazada_sku_id BIGINT NOT NULL,
        lazada_product_name VARCHAR(255) NOT NULL,
        lazada_variation_name VARCHAR(255) NULL,
        lazada_seller_sku VARCHAR(255) NULL,
        lazada_stock INT DEFAULT 0,
        lazada_image_url TEXT NULL,
        pos_product_id INT NULL,
        pos_unit_id INT NULL,
        pos_bundle_set_id INT NULL,
        matched_pos_sku VARCHAR(255) NULL,
        stock_allocation_ratio DECIMAL(5,2) DEFAULT 100.00,
        safety_floor INT DEFAULT 0,
        mapping_status ENUM('unmapped', 'auto', 'manual') DEFAULT 'unmapped',
        sync_hash VARCHAR(64) NULL,
        last_stock_sync_at DATETIME NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_lazada_item (lazada_item_id),
        INDEX idx_lazada_sku (lazada_seller_sku),
        INDEX idx_pos_product (pos_product_id)
    )",

    "CREATE TABLE IF NOT EXISTS lazada_error_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
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
        sync_type VARCHAR(50) NOT NULL,
        status ENUM('success', 'error', 'warning') DEFAULT 'success',
        message TEXT NULL,
        items_affected INT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )"
];

$successCount = 0;
$errors = [];

foreach ($queries as $query) {
    try {
        $conn->exec($query);
        $successCount++;
    } catch (PDOException $e) {
        $errors[] = $e->getMessage();
    }
}

// Initial config insert if empty
try {
    $stmt = $conn->query("SELECT COUNT(*) FROM lazada_config");
    if ($stmt->fetchColumn() == 0) {
        $conn->exec("INSERT INTO lazada_config (app_key, app_secret) VALUES ('', '')");
    }
} catch (PDOException $e) {
    $errors[] = "Config Insert Error: " . $e->getMessage();
}

if (empty($errors)) {
    echo "Lazada database tables setup successfully!\n";
} else {
    echo "Errors occurred:\n";
    print_r($errors);
}

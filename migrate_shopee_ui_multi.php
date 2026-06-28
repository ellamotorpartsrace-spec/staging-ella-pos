<?php
// migrate_shopee_ui_multi.php

require_once 'testing_site/config/config.php';
require_once 'testing_site/config/database.php';

try {
    $db = new Database();
    $conn = $db->getConnection();

    echo "Starting migration for Shopee UI Multi-Account...\n";

    // 1. shopee_config
    $stmt = $conn->query("SHOW COLUMNS FROM shopee_config LIKE 'platform_name'");
    if ($stmt->rowCount() == 0) {
        $conn->exec("ALTER TABLE shopee_config ADD COLUMN platform_name VARCHAR(50) NOT NULL DEFAULT 'shopee_main' AFTER id");
        echo "Added platform_name to shopee_config.\n";
        
        // Update the existing row to be shopee_main
        $conn->exec("UPDATE shopee_config SET platform_name = 'shopee_main'");
        
        // Insert a new row for shopee_secondary if it doesn't exist
        $stmt = $conn->query("SELECT id FROM shopee_config WHERE platform_name = 'shopee_secondary'");
        if ($stmt->rowCount() == 0) {
            $conn->exec("INSERT INTO shopee_config (platform_name, environment, partner_id, partner_key, is_active) 
                         VALUES ('shopee_secondary', 'test', '', '', 1)");
            echo "Created shopee_secondary row in shopee_config.\n";
        }
    } else {
        echo "shopee_config already has platform_name.\n";
    }

    // 2. shopee_product_mappings
    $stmt = $conn->query("SHOW COLUMNS FROM shopee_product_mappings LIKE 'platform_name'");
    if ($stmt->rowCount() == 0) {
        $conn->exec("ALTER TABLE shopee_product_mappings ADD COLUMN platform_name VARCHAR(50) NOT NULL DEFAULT 'shopee_main' AFTER id");
        $conn->exec("CREATE INDEX idx_spm_platform ON shopee_product_mappings(platform_name)");
        echo "Added platform_name to shopee_product_mappings and indexed it.\n";
        
        // Assume all existing mappings are for shopee_main
        $conn->exec("UPDATE shopee_product_mappings SET platform_name = 'shopee_main'");
    } else {
        echo "shopee_product_mappings already has platform_name.\n";
    }

    // 3. shopee_sync_logs
    $stmt = $conn->query("SHOW COLUMNS FROM shopee_sync_logs LIKE 'platform_name'");
    if ($stmt->rowCount() == 0) {
        $conn->exec("ALTER TABLE shopee_sync_logs ADD COLUMN platform_name VARCHAR(50) NOT NULL DEFAULT 'shopee_main' AFTER id");
        $conn->exec("CREATE INDEX idx_ssl_platform ON shopee_sync_logs(platform_name)");
        echo "Added platform_name to shopee_sync_logs and indexed it.\n";
        
        // Assume all existing logs are for shopee_main
        $conn->exec("UPDATE shopee_sync_logs SET platform_name = 'shopee_main'");
    } else {
        echo "shopee_sync_logs already has platform_name.\n";
    }

    echo "Migration completed successfully!\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

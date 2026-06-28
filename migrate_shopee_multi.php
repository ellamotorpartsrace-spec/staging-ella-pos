<?php
require_once __DIR__ . '/testing_site/config/config.php';
require_once __DIR__ . '/testing_site/config/database.php';

try {
    $db = new Database();
    $conn = $db->getConnection();

    // 1. Remove the unique key from platform_name
    $conn->exec("ALTER TABLE api_platforms DROP INDEX idx_platform_name");
    echo "Dropped index idx_platform_name\n";

    // 2. Add account_label column
    // Check if column exists first
    $colCheck = $conn->query("SHOW COLUMNS FROM api_platforms LIKE 'account_label'");
    if ($colCheck->rowCount() == 0) {
        $conn->exec("ALTER TABLE api_platforms ADD COLUMN account_label VARCHAR(100) DEFAULT NULL AFTER platform_name");
        echo "Added account_label column\n";
    }

    // 3. Update existing 'shopee' row to 'shopee_main' and 'Shopee Main'
    $conn->exec("UPDATE api_platforms SET platform_name = 'shopee_main', account_label = 'Shopee Main' WHERE platform_name = 'shopee'");
    echo "Updated existing shopee to shopee_main\n";

    // 4. Also rename 'Shopee' to 'shopee_main' in online_platform_links
    $conn->exec("UPDATE online_platform_links SET platform = 'shopee_main' WHERE platform = 'Shopee' OR platform = 'shopee'");
    echo "Updated online_platform_links\n";

    // 5. Update api_sync_queue
    $conn->exec("UPDATE api_sync_queue SET platform = 'shopee_main' WHERE platform = 'shopee'");
    echo "Updated api_sync_queue\n";

    // 6. Re-add a UNIQUE index on platform_name since we want shopee_main, shopee_secondary to be unique
    try {
        $conn->exec("ALTER TABLE api_platforms ADD UNIQUE INDEX idx_platform_name (platform_name)");
        echo "Re-added unique index on platform_name\n";
    } catch (Exception $e) {
        echo "Index probably exists or error: " . $e->getMessage() . "\n";
    }

    // 7. Insert the secondary shopee account if it doesn't exist
    $conn->exec("INSERT IGNORE INTO api_platforms (platform_name, account_label, is_active) VALUES ('shopee_secondary', 'Shopee Secondary', 0)");
    echo "Inserted shopee_secondary\n";

    echo "Migration completed successfully.\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

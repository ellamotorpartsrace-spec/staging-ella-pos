<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';

try {
    $db = new Database();
    $conn = $db->getConnection();
    
    // Add sync_prices
    $conn->exec("ALTER TABLE lazada_config ADD COLUMN sync_prices TINYINT(1) DEFAULT 0");
    echo "Added sync_prices column.\n";
    
    // Add price_markup_percent
    $conn->exec("ALTER TABLE lazada_config ADD COLUMN price_markup_percent DECIMAL(5,2) DEFAULT 0.00");
    echo "Added price_markup_percent column.\n";
    
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
        echo "Columns already exist.\n";
    } else {
        echo "Error: " . $e->getMessage() . "\n";
    }
}

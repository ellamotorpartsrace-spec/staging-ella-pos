<?php
require_once __DIR__ . "/config/database.php";
try {
    $db = new Database();
    $conn = $db->getConnection();
    $conn->exec("CREATE TABLE IF NOT EXISTS lazada_error_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        error_type VARCHAR(50) NOT NULL,
        status VARCHAR(20) DEFAULT 'open',
        sku VARCHAR(100) DEFAULT NULL,
        error_message TEXT,
        lazada_item_id BIGINT DEFAULT NULL,
        lazada_model_id BIGINT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    echo "lazada_error_logs table created successfully!";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}

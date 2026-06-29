<?php
require 'testing_site/config/database.php';
$db = new Database();
$conn = $db->getConnection();
$conn->query("
CREATE TABLE IF NOT EXISTS lazada_config (
    id INT AUTO_INCREMENT PRIMARY KEY,
    platform_name VARCHAR(50) NOT NULL UNIQUE,
    account_name VARCHAR(100),
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)
");
echo 'Created lazada_config';

<?php
header("Content-Type: application/json");
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';

requireLogin();

// This is a stub. Once Lazada API is connected, this will fetch live stock numbers.
echo json_encode([
    'success' => true,
    'stocks' => []
]);

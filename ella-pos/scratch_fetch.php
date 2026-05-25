<?php
// Mock session
session_start();
$_SESSION['user_id'] = 1;
$_SESSION['role'] = 'admin';

chdir(__DIR__ . '/api/shopee');
// Include the script
require_once 'fetch_products.php';

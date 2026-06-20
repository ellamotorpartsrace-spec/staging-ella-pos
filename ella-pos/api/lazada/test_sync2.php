<?php
$_GET['offset'] = 100;
session_start();
$_SESSION['user_id'] = 1;
$_SESSION['role'] = 'admin';
require 'c:/xampp/htdocs/ella-pos/ella-pos/api/lazada/fetch_products.php';

<?php
session_start();
$_SESSION['user_id'] = 1;
$_SESSION['role'] = 'admin';
require 'c:/xampp/htdocs/ella-pos/ella-pos/api/shopee/fetch_products.php';

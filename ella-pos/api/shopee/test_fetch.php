<?php
$_GET['offset'] = 0;
session_start();
$_SESSION['user_id'] = 1;
$_SESSION['role'] = 'admin';
try {
    ob_start();
    require 'c:/xampp/htdocs/ella-pos/ella-pos/api/shopee/fetch_products.php';
    $out = ob_get_clean();
    file_put_contents('debug_output.txt', $out);
    echo "Saved to debug_output.txt\n";
} catch (Throwable $e) {
    file_put_contents('debug_error.txt', $e->getMessage() . "\n" . $e->getTraceAsString());
    echo "Error saved\n";
}

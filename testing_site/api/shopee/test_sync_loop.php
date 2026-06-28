<?php
session_start();
$_SESSION['user_id'] = 1;
$_SESSION['role'] = 'admin';

$offset = 0;
$hasNext = true;

while ($hasNext) {
    echo "Fetching offset $offset...\n";
    $_GET['offset'] = $offset;
    
    // We can't simply require inside the loop because it declares functions and has exit().
    // We need to use exec() or similar to run it in a separate process.
    $output = shell_exec("c:\\xampp\\php\\php.exe -r \"session_start(); \\\$_SESSION['user_id']=1; \\\$_SESSION['role']='admin'; \\\$_GET['offset']=$offset; require 'c:/xampp/htdocs/ella-pos/ella-pos/api/shopee/fetch_products.php';\"");
    
    $json = json_decode($output, true);
    if (!$json) {
        echo "FAILED AT OFFSET $offset. Output was:\n$output\n";
        break;
    }
    if (!$json['success']) {
        echo "API returned error at offset $offset: " . $json['error'] . "\n";
        break;
    }
    
    $hasNext = $json['has_next_page'];
    $offset = $json['next_offset'];
}
echo "Done.\n";

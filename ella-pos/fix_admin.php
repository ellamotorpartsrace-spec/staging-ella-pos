<?php
$files1 = [
    'c:\\xampp\\htdocs\\ella-pos\\ella-pos\\views\\system\\integrations.php',
    'c:\\xampp\\htdocs\\ella-pos\\ella-pos\\api\\system\\shopee_auth_callback.php',
    'c:\\xampp\\htdocs\\ella-pos\\ella-pos\\api\\system\\test_shopee.php',
    'c:\\xampp\\htdocs\\ella-pos\\ella-pos\\api\\system\\shopee_auth_redirect.php',
    'c:\\xampp\\htdocs\\ella-pos\\ella-pos\\api\\system\\save_integrations.php',
    'c:\\xampp\\htdocs\\ella-pos\\ella-pos\\api\\system\\get_integrations.php',
    'c:\\xampp\\htdocs\\ella-pos\\ella-pos\\api\\inventory\\migrate_platform_links.php',
    'c:\\xampp\\htdocs\\ella-pos\\ella-pos\\api\\inventory\\get_stockin_movement.php',
    'c:\\xampp\\htdocs\\ella-pos\\ella-pos\\api\\inventory\\update_stockin_record.php'
];

foreach ($files1 as $file) {
    if (file_exists($file)) {
        $content = file_get_contents($file);
        $content = str_replace("if (\$_SESSION['role'] !== 'admin') {", "if (!in_array(\$_SESSION['role'], ['admin', 'super_admin'])) {", $content);
        file_put_contents($file, $content);
        echo "Fixed $file\n";
    }
}

$files2 = [
    'c:\\xampp\\htdocs\\ella-pos\\ella-pos\\api\\suppliers\\process_mass_stock.php',
    'c:\\xampp\\htdocs\\ella-pos\\ella-pos\\api\\pos\\adjust_transaction.php'
];

foreach ($files2 as $file) {
    if (file_exists($file)) {
        $content = file_get_contents($file);
        $content = str_replace("if (\$_SESSION['role'] !== 'admin' && \$_SESSION['role'] !== 'manager') {", "if (!in_array(\$_SESSION['role'], ['admin', 'super_admin', 'manager'])) {", $content);
        file_put_contents($file, $content);
        echo "Fixed $file\n";
    }
}

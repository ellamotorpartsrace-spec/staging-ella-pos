<?php
$files = [
    'c:\\xampp\\htdocs\\ella-pos\\ella-pos\\views\\buyers\\transactions.php',
    'c:\\xampp\\htdocs\\ella-pos\\ella-pos\\views\\buyers\\edit.php',
    'c:\\xampp\\htdocs\\ella-pos\\ella-pos\\views\\buyers\\create.php',
    'c:\\xampp\\htdocs\\ella-pos\\ella-pos\\api\\buyers\\export_csv.php',
    'c:\\xampp\\htdocs\\ella-pos\\ella-pos\\api\\buyers\\process_import.php'
];

foreach ($files as $file) {
    if (file_exists($file)) {
        $content = file_get_contents($file);
        $content = str_replace(
            "if (\$_SESSION['role'] !== 'admin' && !in_array(\$_SESSION['role'], ['manager'])) {",
            "if (!in_array(\$_SESSION['role'], ['admin', 'super_admin', 'manager'])) {",
            $content
        );
        file_put_contents($file, $content);
        echo "Fixed $file\n";
    }
}

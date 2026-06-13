<?php
$dir = 'c:\\xampp\\htdocs\\ella-pos\\ella-pos';

$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));

foreach ($iterator as $file) {
    if ($file->isFile() && $file->getExtension() === 'php') {
        $path = $file->getPathname();
        $content = file_get_contents($path);
        
        $originalContent = $content;
        
        // 1. in_array($_SESSION['role'], ['admin', 'super_admin', 'manager']) -> ['admin', 'super_admin', 'manager']
        $content = str_replace(
            "in_array(\$_SESSION['role'], ['admin', 'manager'])",
            "in_array(\$_SESSION['role'], ['admin', 'super_admin', 'manager'])",
            $content
        );

        // 2. in_array($role, ['admin', 'super_admin', 'manager']) -> ['admin', 'super_admin', 'manager']
        $content = str_replace(
            "in_array(\$role, ['admin', 'manager'])",
            "in_array(\$role, ['admin', 'super_admin', 'manager'])",
            $content
        );
        
        // 3. in_array($_SESSION['role'] ?? '', ['admin', 'super_admin', 'manager']) -> ['admin', 'super_admin', 'manager']
        $content = str_replace(
            "in_array(\$_SESSION['role'] ?? '', ['admin', 'manager'])",
            "in_array(\$_SESSION['role'] ?? '', ['admin', 'super_admin', 'manager'])",
            $content
        );

        // 4. if ($_SESSION['role'] === 'admin') -> if (in_array($_SESSION['role'], ['admin', 'super_admin']))
        // But wait! This might break the restricted UI for admin in pending_approvals.php!
        // So I'll only do string replacements for the array ones.

        if ($content !== $originalContent) {
            file_put_contents($path, $content);
            echo "Fixed $path\n";
        }
    }
}
echo "Done.\n";

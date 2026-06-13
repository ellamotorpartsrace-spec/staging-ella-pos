<?php
$dir = new RecursiveIteratorIterator(new RecursiveDirectoryIterator('.'));
foreach ($dir as $file) {
    if ($file->isFile() && $file->getExtension() === 'php') {
        $c = file_get_contents($file->getPathname());
        if (preg_match("/\\b_SESSION\\['role'\\]\\s*(===|!==|==|!=)\\s*'admin'\\b/", $c)) {
            if (strpos($c, "hasPermission") === false && strpos($c, "super_admin") === false) {
                echo "Match: " . $file->getPathname() . "\n";
            }
        }
    }
}

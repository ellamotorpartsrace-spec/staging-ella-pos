<?php
$dir = new RecursiveIteratorIterator(new RecursiveDirectoryIterator('.'));
foreach ($dir as $file) {
    if ($file->isFile() && $file->getExtension() === 'php') {
        $c = file_get_contents($file->getPathname());
        if (strpos($c, "'admin'") !== false && strpos($c, "'super_admin'") === false && strpos($file->getPathname(), 'login_process.php') === false && strpos($file->getPathname(), 'test_') === false) {
            echo $file->getPathname() . "\n";
        }
    }
}

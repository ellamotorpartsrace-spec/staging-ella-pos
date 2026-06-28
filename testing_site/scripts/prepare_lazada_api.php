<?php
$source_dir = __DIR__ . '/../api/shopee';
$target_dir = __DIR__ . '/../api/lazada';

if (!is_dir($target_dir)) {
    mkdir($target_dir, 0777, true);
}

$files = scandir($source_dir);
foreach ($files as $file) {
    if ($file === '.' || $file === '..') continue;
    
    $source_path = $source_dir . '/' . $file;
    $target_file = str_replace(['shopee', 'Shopee'], ['lazada', 'Lazada'], $file);
    $target_path = $target_dir . '/' . $target_file;
    
    if (is_file($source_path)) {
        $content = file_get_contents($source_path);
        
        // Replacements
        $content = str_replace(
            ['shopee', 'Shopee', 'SHOPEE', 'sp-', 'sp_'],
            ['lazada', 'Lazada', 'LAZADA', 'lz-', 'lz_'],
            $content
        );
        
        file_put_contents($target_path, $content);
        echo "Created $target_file\n";
    }
}
echo "Done copying API files!\n";

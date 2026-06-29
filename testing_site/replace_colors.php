<?php
$content = file_get_contents('views/lazada/mapping.php');

// Replace all shopee orange with lazada blue
$content = str_replace('rgba(238,77,45,', 'rgba(15,19,109,', $content);
$content = str_replace('rgba(238, 77, 45,', 'rgba(15, 19, 109,', $content);
$content = str_replace('#ee4d2d', '#0f136d', $content);

file_put_contents('views/lazada/mapping.php', $content);
echo "Replaced shopee colors in mapping.php\n";

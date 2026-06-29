<?php
$content = file_get_contents('testing_site/views/lazada/allocation.php');

$content = str_replace([
    'Shopee', 'shopee', 'sp-', 'shopee_product_mappings'
], [
    'Lazada', 'lazada', 'lz-', 'lazada_product_mappings'
], $content);

$content = str_replace('api/lazada/', 'api/lazada/', $content); 

file_put_contents('testing_site/views/lazada/allocation.php', $content);
echo "Done";

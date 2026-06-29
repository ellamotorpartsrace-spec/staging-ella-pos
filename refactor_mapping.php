<?php
$content = file_get_contents('testing_site/views/lazada/mapping.php');

// Replace standard terms
$content = str_replace([
    'Shopee', 'shopee', 'sp-', 'shopee_product_mappings'
], [
    'Lazada', 'lazada', 'lz-', 'lazada_product_mappings'
], $content);

// Some specific API endpoints
$content = str_replace('api/lazada/', 'api/lazada/', $content); // if it was already lazada

// Since we replaced shopee with lazada, 'Shopee Sync' became 'Lazada Sync'
// 'shopee_stock' -> 'lazada_stock'
// 'shopee_item_id' -> 'lazada_item_id'
// 'shopee_variation_name' -> 'lazada_variation_name'
// 'shopee_parent_sku' -> 'lazada_seller_sku' (Wait! Lazada doesn't have parent_sku/variation_sku, just lazada_seller_sku)

file_put_contents('testing_site/views/lazada/mapping.php', $content);
echo "Done";

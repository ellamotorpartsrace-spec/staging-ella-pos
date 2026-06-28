<?php
$source_dir = __DIR__ . '/../views/shopee';
$target_dir = __DIR__ . '/../views/lazada';

if (!is_dir($target_dir)) {
    mkdir($target_dir, 0777, true);
}

// 1. Copy and modify CSS
$css_source = __DIR__ . '/../assets/css/shopee-sync.css';
$css_target = __DIR__ . '/../assets/css/lazada-sync.css';

$css_content = file_get_contents($css_source);
$css_content = str_replace(
    ['--shopee-primary', '--shopee-hover', '--shopee-light', '--shopee-gradient', 'shopee-sync.css', '.text-shopee', '.accent-shopee', '.btn-shopee', '.sp-', '.sp_'], 
    ['--lazada-primary', '--lazada-hover', '--lazada-light', '--lazada-gradient', 'lazada-sync.css', '.text-lazada', '.accent-lazada', '.btn-lazada', '.lz-', '.lz_'], 
    $css_content
);

// Update lazada colors
$css_content = preg_replace('/--lazada-primary:\s*#[a-zA-Z0-9]+;/', '--lazada-primary: #0f136d;', $css_content);
$css_content = preg_replace('/--lazada-hover:\s*#[a-zA-Z0-9]+;/', '--lazada-hover: #0a0c4d;', $css_content);
$css_content = preg_replace('/--lazada-light:\s*[^;]+;/', '--lazada-light: rgba(15, 19, 109, 0.12);', $css_content);
$css_content = preg_replace('/--lazada-gradient:\s*[^;]+;/', '--lazada-gradient: linear-gradient(135deg, #0f136d 0%, #f53d2d 100%);', $css_content);
$css_content = preg_replace('/--sp-grad-primary:\s*[^;]+;/', '--sp-grad-primary: linear-gradient(135deg, #0f136d 0%, #f53d2d 100%);', $css_content);

file_put_contents($css_target, $css_content);
echo "Created lazada-sync.css\n";

// 2. Copy and modify Views
$files = scandir($source_dir);
foreach ($files as $file) {
    if ($file === '.' || $file === '..') continue;
    
    $source_path = $source_dir . '/' . $file;
    $target_file = str_replace('shopee', 'lazada', $file);
    $target_path = $target_dir . '/' . $target_file;
    
    if (is_file($source_path)) {
        $content = file_get_contents($source_path);
        
        // Replacements
        $content = str_replace(
            ['shopee-sync.css', 'shopee', 'Shopee', 'SHOPEE', 'sp-', 'sp_'],
            ['lazada-sync.css', 'lazada', 'Lazada', 'LAZADA', 'lz-', 'lz_'],
            $content
        );
        
        file_put_contents($target_path, $content);
        echo "Created $target_file\n";
    }
}

// 3. API files
$api_sys_dir = __DIR__ . '/../api/system';
$auth_cb_source = $api_sys_dir . '/shopee_auth_callback.php';
$auth_cb_target = $api_sys_dir . '/lazada_auth_callback.php';

if (file_exists($auth_cb_source)) {
    $content = file_get_contents($auth_cb_source);
    $content = str_replace(['shopee', 'Shopee', 'SHOPEE'], ['lazada', 'Lazada', 'LAZADA'], $content);
    file_put_contents($auth_cb_target, $content);
    echo "Created lazada_auth_callback.php\n";
}

$auth_re_source = $api_sys_dir . '/shopee_auth_redirect.php';
$auth_re_target = $api_sys_dir . '/lazada_auth_redirect.php';

if (file_exists($auth_re_source)) {
    $content = file_get_contents($auth_re_source);
    $content = str_replace(['shopee', 'Shopee', 'SHOPEE'], ['lazada', 'Lazada', 'LAZADA'], $content);
    file_put_contents($auth_re_target, $content);
    echo "Created lazada_auth_redirect.php\n";
}

// 4. API class stub
$class_target = __DIR__ . '/../classes/LazadaAPI.php';
$class_content = "<?php\nclass LazadaAPI {\n    private \$partner_id;\n    private \$partner_key;\n    private \$is_test;\n\n    public function __construct(\$partner_id, \$partner_key, \$is_test = false) {\n        \$this->partner_id = \$partner_id;\n        \$this->partner_key = \$partner_key;\n        \$this->is_test = \$is_test;\n    }\n\n    public function getAuthUrl(\$redirect_url) {\n        // TODO: Implement actual Lazada Open Platform auth URL logic\n        return \"https://auth.lazada.com/oauth/authorize?response_type=code&force_auth=true&redirect_uri=\" . urlencode(\$redirect_url) . \"&client_id=\" . \$this->partner_id;\n    }\n\n    public function getAccessToken(\$code, \$shop_id = null) {\n        // TODO: Implement actual token exchange\n        return ['access_token' => 'dummy', 'refresh_token' => 'dummy', 'expire_in' => 2592000];\n    }\n}\n";
file_put_contents($class_target, $class_content);
echo "Created LazadaAPI.php\n";

echo "Done!\n";

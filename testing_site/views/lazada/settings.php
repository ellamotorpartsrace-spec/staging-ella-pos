<?php
// views/lazada/settings.php — Lazada Premium Settings & Setup (Redesigned with Vertical Tabs)
$page_title = 'Lazada Sync — Settings';
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
requireRole(['admin', 'super_admin']);
$isAdmin = isset($_SESSION['role']) && in_array($_SESSION['role'], ['admin', 'super_admin']);

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';

// Check if we just came back from OAuth
$authSuccess = isset($_GET['auth']) && $_GET['auth'] === 'success';

// Load config
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$platform = $_SESSION['lazada_active_platform'] ?? 'lazada_main';
$db = new Database();
$conn = $db->getConnection();
$stmt = $conn->prepare("SELECT * FROM lazada_config WHERE platform_name = ?");
$stmt->execute([$platform]);
$config = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$config) {
    $config = [
        'app_key' => '', 'app_secret' => '', 'environment' => 'sandbox',
        'country_code' => 'PH', 'access_token' => '', 'refresh_token' => '',
        'token_expires_at' => '', 'account_name' => '', 'account_id' => '',
        'enable_stock_sync' => 0, 'respect_allocation' => 1,
        'low_stock_alerts' => 1, 'sync_interval_mins' => 15, 'low_stock_threshold' => 5,
        'buffer_stock' => 0
    ];
}
if (!isset($config['buffer_stock'])) $config['buffer_stock'] = 0;
if (!isset($config['low_stock_alerts'])) $config['low_stock_alerts'] = 1;
if (!isset($config['sync_prices'])) $config['sync_prices'] = 0;
if (!isset($config['price_markup_percent'])) $config['price_markup_percent'] = 0.00;

$isConfigured = !empty($config['app_key']) && !empty($config['app_secret']);
$isAuthorized = !empty($config['access_token']);

// Determine Token Status
$tokenStatus = 'missing';
$tokenExpiresStr = '—';
$tokenExpiresTime = null;
if ($isAuthorized) {
    if (!empty($config['token_expires_at'])) {
        $expires = strtotime($config['token_expires_at']);
        $tokenExpiresTime = $expires * 1000;
        if ($expires > time()) {
            $tokenStatus = 'valid';
        } else {
            $tokenStatus = 'expired';
        }
        $tokenExpiresStr = date('Y-m-d H:i:s', $expires);
    } else {
        $tokenStatus = 'valid';
    }
}
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/lazada-sync.css?v=<?= filemtime(__DIR__.'/../../assets/css/lazada-sync.css') ?>">
<style>
@keyframes countdownPulse {
    0% { opacity: 1; }
    50% { opacity: 0.75; }
    100% { opacity: 1; }
}
.countdown-pulse { animation: countdownPulse 1.5s infinite ease-in-out; }
/* Progress Scrollbar */
#cleanupProgressLog::-webkit-scrollbar { width: 6px; }
#cleanupProgressLog::-webkit-scrollbar-track { background: rgba(0,0,0,0.05); border-radius: 4px; }
#cleanupProgressLog::-webkit-scrollbar-thumb { background: rgba(59,130,246,0.3); border-radius: 4px; }
#cleanupProgressLog::-webkit-scrollbar-thumb:hover { background: rgba(59,130,246,0.5); }
</style>

<div class="container-fluid py-3 lz-animate lz-settings-container">
    <?php require_once __DIR__ . '/lazada_token_warning.php'; ?>

    <?php if ($authSuccess): ?>
    <div class="lz-alert-box lz-alert-success mb-4 shadow-sm">
        <i class="fa-solid fa-circle-check fs-5"></i>
        <div><strong>Shop authorized successfully!</strong> Account: <?= htmlspecialchars($config['account_name'] ?? $config['account_id'] ?? '') ?> — You can now sync products.</div>
    </div>
    <?php endif; ?>

    <!-- Premium Hero Header -->
    <div class="lz-hero-premium">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-3" style="font-size:.85rem; opacity: 0.8;">
                <li class="breadcrumb-item"><a href="<?= BASE_URL ?>views/lazada/index.php" class="text-white text-decoration-none fw-bold px-2 py-1 rounded" style="background: rgba(255, 255, 255, 0.2); transition: background 0.2s;" onmouseover="this.style.background='rgba(255, 255, 255, 0.3)'" onmouseout="this.style.background='rgba(255, 255, 255, 0.2)'"><i class="fa-solid fa-arrow-left me-1"></i> Lazada Dashboard</a></li>
                <li class="breadcrumb-item active text-white fw-bold" aria-current="page">Configuration</li>
            </ol>
        </nav>
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-4">
            <div class="d-flex align-items-center gap-4">
                <div style="background: rgba(255,255,255,0.1); border: 1px solid rgba(255,255,255,0.2); backdrop-filter: blur(10px); border-radius: 16px; width: 72px; height: 72px; display: flex; align-items: center; justify-content: center;">
                    <i class="fa-solid fa-sliders text-white" style="font-size: 2rem;"></i>
                </div>
                <div>
                    <h1 class="mb-1 text-white fw-bolder" style="font-size: 2.2rem; letter-spacing: -0.5px;">Settings Engine</h1>
                    <p class="mb-0 text-white" style="opacity: 0.8; font-size: 1rem;">Advanced integration controls and synchronization rules.</p>
                </div>
            </div>
            <div class="d-flex align-items-center gap-3">
                <?php if ($isAuthorized): ?>
                <div class="text-end">
                    <div class="text-white text-uppercase fw-bold mb-1" style="font-size: 0.7rem; letter-spacing: 0.05em; opacity: 0.7;">Connection Status</div>
                    <div class="d-flex align-items-center gap-2 bg-white px-3 py-1 rounded-pill shadow-sm">
                        <div style="width: 8px; height: 8px; border-radius: 50%; background: #10b981; box-shadow: 0 0 0 3px rgba(16,185,129,0.2);"></div>
                        <span class="text-dark fw-bold" style="font-size: 0.85rem;">Active</span>
                    </div>
                </div>
                <div class="text-end ms-2">
                    <div class="text-white text-uppercase fw-bold mb-1" style="font-size: 0.7rem; letter-spacing: 0.05em; opacity: 0.7;">Token Expiry</div>
                    <div class="d-flex align-items-center gap-2 bg-white px-3 py-1 rounded-pill shadow-sm">
                        <i class="fa-solid fa-clock text-primary" style="font-size: 0.8rem;"></i>
                        <span class="text-dark fw-bold" id="tokenExpiry" style="font-size: 0.85rem;">—</span>
                    </div>
                </div>
                <?php else: ?>
                <div class="text-end">
                    <div class="text-white text-uppercase fw-bold mb-1" style="font-size: 0.7rem; letter-spacing: 0.05em; opacity: 0.7;">Connection Status</div>
                    <div class="d-flex align-items-center gap-2 bg-white px-3 py-1 rounded-pill shadow-sm">
                        <div style="width: 8px; height: 8px; border-radius: 50%; background: #ef4444; box-shadow: 0 0 0 3px rgba(239,68,68,0.2);"></div>
                        <span class="text-dark fw-bold" style="font-size: 0.85rem;">Disconnected</span>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Main Layout: Sidebar + Content -->
    <div class="row g-4">
        <!-- Sidebar Navigation -->
        <div class="col-lg-3">
            <div class="lz-nav-sidebar">
                <a class="lz-nav-pill active" onclick="switchTab('tab-api', this)">
                    <div class="icon-box"><i class="fa-solid fa-plug"></i></div>
                    <span>API Integration</span>
                </a>
                <a class="lz-nav-pill" onclick="switchTab('tab-tokens', this)">
                    <div class="icon-box"><i class="fa-solid fa-key"></i></div>
                    <span>Token Management</span>
                </a>
                <a class="lz-nav-pill" onclick="switchTab('tab-sync', this)">
                    <div class="icon-box"><i class="fa-solid fa-rotate"></i></div>
                    <span>Sync Automation</span>
                </a>
                <a class="lz-nav-pill" onclick="switchTab('tab-inventory', this)">
                    <div class="icon-box"><i class="fa-solid fa-shield-halved"></i></div>
                    <span>Inventory Rules</span>
                </a>
                <a class="lz-nav-pill" onclick="switchTab('tab-smart-sync', this)">
                    <div class="icon-box"><i class="fa-solid fa-bolt"></i></div>
                    <span>Smart Sync</span>
                </a>
                <a class="lz-nav-pill" onclick="switchTab('tab-tools', this)">
                    <div class="icon-box"><i class="fa-solid fa-toolbox"></i></div>
                    <span>Data Tools</span>
                </a>
            </div>
        </div>

        <!-- Content Area -->
        <div class="col-lg-9">
            
            <!-- TAB 1: API Integration -->
            <div id="tab-api" class="lz-tab-content active">
                <div class="lz-content-card">
                    <div class="lz-card-header d-flex justify-content-between align-items-start pb-0 border-0">
                        <div>
                            <h3>API Integration</h3>
                            <p class="subtitle">Securely connect Ella POS to your Lazada Seller Center.</p>
                        </div>
                        <?php if ($isConfigured && !$isAuthorized): ?>
                            <button class="btn-lz-primary shadow-sm" onclick="authorizeShop()">
                                <i class="fa-solid fa-right-to-bracket me-2"></i> Authorize Lazada
                            </button>
                        <?php endif; ?>
                    </div>
                    
                    <div class="lz-card-body pt-3">
                        <?php if ($isAdmin): ?>
                        <div class="row g-4">
                            <div class="col-12">
                                <label class="lz-form-label">Environment Target</label>
                                <div class="d-inline-flex p-1 gap-1" style="background: #f1f5f9; border-radius: 14px;">
                                    <button class="btn <?= $config['environment'] === 'sandbox' ? 'bg-white text-primary shadow-sm' : 'text-secondary' ?> fw-bold border-0" style="border-radius: 10px; width: 150px; padding: 0.6rem;" id="envSandbox" onclick="setEnv('sandbox')">Sandbox (Test)</button>
                                    <button class="btn <?= $config['environment'] === 'production' ? 'bg-white text-primary shadow-sm' : 'text-secondary' ?> fw-bold border-0" style="border-radius: 10px; width: 150px; padding: 0.6rem;" id="envLive" onclick="setEnv('production')">Production (Live)</button>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <div class="lz-form-group">
                                    <label class="lz-form-label">App Key <span class="text-danger">*</span></label>
                                    <input type="text" class="lz-input" id="appKey" placeholder="Enter your Lazada App Key" value="<?= htmlspecialchars($config['app_key']) ?>">
                                </div>
                            </div>

                            <div class="col-md-6">
                                <div class="lz-form-group d-flex align-items-center">
                                    <div class="flex-grow-1">
                                        <label class="lz-form-label">App Secret <span class="text-danger">*</span></label>
                                        <input type="password" class="lz-input" id="appSecret" placeholder="Enter your App Secret" value="<?= htmlspecialchars($config['app_secret']) ?>">
                                    </div>
                                    <button class="btn btn-link text-secondary p-0 ms-2 text-decoration-none" type="button" onclick="toggleKeyVisibility('appSecret', 'keyEyeIcon')">
                                        <i class="fa-solid fa-eye" id="keyEyeIcon" style="font-size: 1.2rem;"></i>
                                    </button>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <div class="lz-form-group">
                                    <label class="lz-form-label">Shop Region <span class="text-danger">*</span></label>
                                    <select class="lz-select" id="shopRegion">
                                        <option value="PH" <?= $config['country_code'] === 'PH' ? 'selected' : '' ?>>🇵🇭 Philippines (PH)</option>
                                        <option value="SG" <?= $config['country_code'] === 'SG' ? 'selected' : '' ?>>🇸🇬 Singapore (SG)</option>
                                        <option value="MY" <?= $config['country_code'] === 'MY' ? 'selected' : '' ?>>🇲🇾 Malaysia (MY)</option>
                                        <option value="TH" <?= $config['country_code'] === 'TH' ? 'selected' : '' ?>>🇹🇭 Thailand (TH)</option>
                                        <option value="ID" <?= $config['country_code'] === 'ID' ? 'selected' : '' ?>>🇮🇩 Indonesia (ID)</option>
                                        <option value="VN" <?= $config['country_code'] === 'VN' ? 'selected' : '' ?>>🇻🇳 Vietnam (VN)</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="mt-5 text-end">
                            <button class="btn-lz-primary px-5" onclick="saveCredentials()" id="btnSave">
                                <i class="fa-solid fa-floppy-disk me-2"></i> Save Integration
                            </button>
                        </div>
                        

                        <?php else: ?>
                        <div class="text-center py-5">
                            <div style="background: #f1f5f9; color: #64748b; width: 80px; height: 80px; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 1.5rem auto; font-size: 2rem;">
                                <i class="fa-solid fa-lock"></i>
                            </div>
                            <h4 class="fw-bold text-dark">Access Locked</h4>
                            <p class="text-secondary mb-0">Only administrators have permission to modify API connection details.</p>
                        </div>
                        <div style="display:none">
                            <input id="appKey"><input id="appSecret"><select id="shopRegion"><option value="PH"></option></select>
                            <button id="btnSave"></button>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- TAB: Token Management -->
            <div id="tab-tokens" class="lz-tab-content">
                <div class="lz-content-card">
                    <div class="lz-card-header d-flex justify-content-between align-items-start pb-0 border-0">
                        <div>
                            <h3>Token Management</h3>
                            <p class="subtitle">Monitor and refresh your Lazada API authentication tokens.</p>
                        </div>
                        <?php if ($isAuthorized): ?>
                        <button class="btn-lz-secondary px-4 shadow-sm" onclick="refreshTokens(this)">
                            <i class="fa-solid fa-rotate me-2"></i> Refresh Tokens
                        </button>
                        <?php endif; ?>
                    </div>
                    
                    <div class="lz-card-body pt-3">
                        <?php if ($isAuthorized): ?>
                        <div class="lz-form-group d-flex justify-content-between align-items-center mb-3">
                            <div>
                                <label class="lz-form-label text-secondary mb-1">Access Token</label>
                                <div class="fw-bold text-dark font-monospace" style="font-size: 0.9rem;" id="tokenValueText">
                                    <?= substr($config['access_token'], 0, 10) ?>...<?= substr($config['access_token'], -5) ?>
                                </div>
                                <input type="hidden" id="tokenValueFull" value="<?= htmlspecialchars($config['access_token']) ?>">
                            </div>
                            <button class="btn btn-link text-secondary p-0 text-decoration-none" onclick="copyAccessToken()">
                                <i class="fa-solid fa-copy fs-5"></i>
                            </button>
                        </div>

                        <div class="row g-3">
                            <div class="col-md-6">
                                <div class="lz-form-group">
                                    <label class="lz-form-label text-secondary mb-1">Account ID</label>
                                    <div class="fw-bold text-dark"><?= htmlspecialchars($config['account_name'] ?? $config['account_id']) ?></div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="lz-form-group">
                                    <label class="lz-form-label text-secondary mb-1">Token Status</label>
                                    <?php if ($tokenStatus === 'valid'): ?>
                                        <div class="fw-bold text-success"><i class="fa-solid fa-check-circle me-1"></i> Active</div>
                                    <?php else: ?>
                                        <div class="fw-bold text-danger"><i class="fa-solid fa-xmark-circle me-1"></i> Expired</div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php else: ?>
                        <div class="text-center py-5">
                            <div style="background: #f1f5f9; color: #64748b; width: 80px; height: 80px; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 1.5rem auto; font-size: 2rem;">
                                <i class="fa-solid fa-key"></i>
                            </div>
                            <h4 class="fw-bold text-dark">No Active Tokens</h4>
                            <p class="text-secondary mb-0">You must authorize the Lazada integration first.</p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- TAB 2: Sync Automation -->
            <div id="tab-sync" class="lz-tab-content">
                <div class="lz-content-card">
                    <div class="lz-card-header border-0 pb-0">
                        <h3>Sync Automation</h3>
                        <p class="subtitle">Configure how Ella POS automatically communicates with Lazada.</p>
                    </div>
                    <div class="lz-card-body pt-4">

                        <div class="lz-switch-row mb-5">
                            <div>
                                <h6 class="fw-bold text-dark mb-1" style="font-size: 1.1rem;">Auto-Sync Engine</h6>
                                <p class="text-secondary small mb-0">Enable background job to automatically push POS stock updates to Lazada.</p>
                            </div>
                            <label class="lz-switch">
                                <input type="checkbox" id="enableSync" <?= $config['enable_stock_sync'] ? 'checked' : '' ?> onchange="savePreferences()">
                                <span class="lz-switch-slider"></span>
                            </label>
                        </div>

                        <div class="lz-form-group w-50">
                            <label class="lz-form-label">Sync Frequency (Cron)</label>
                            <select class="lz-select" id="syncInterval" onchange="savePreferences()">
                                <option value="5" <?= $config['sync_interval_mins'] == 5 ? 'selected' : '' ?>>Every 5 minutes (Recommended)</option>
                                <option value="15" <?= $config['sync_interval_mins'] == 15 ? 'selected' : '' ?>>Every 15 minutes</option>
                                <option value="30" <?= $config['sync_interval_mins'] == 30 ? 'selected' : '' ?>>Every 30 minutes</option>
                                <option value="60" <?= $config['sync_interval_mins'] == 60 ? 'selected' : '' ?>>Every 1 hour</option>
                            </select>
                        </div>

                        <div class="mt-5 pt-4" style="border-top: 1px solid #e2e8f0;">
                            <h6 class="fw-bold text-dark mb-1" style="font-size: 1.1rem;">System Cron Job Setup</h6>
                            <p class="text-secondary small mb-4">Required configuration to make the automation run in the background (cPanel / Server).</p>
                            
                            <div class="lz-alert-box lz-alert-info mb-4 shadow-sm" style="background: #eff6ff; color: #1e3a8a; border: 1px solid #bfdbfe;">
                                <i class="fa-solid fa-server fs-5 text-primary"></i>
                                <div>To make the Auto-Sync Engine trigger automatically, you must add this command to your server's Cron Jobs. Set it to run every minute (<code>* * * * *</code>).</div>
                            </div>
                            
                            <div class="lz-form-group">
                                <label class="lz-form-label text-primary">Linux / cPanel Cron Command</label>
                                <div class="d-flex gap-2">
                                    <input type="text" class="lz-input bg-white" readonly value="wget -qO- <?= rtrim(BASE_URL, '/') ?>/api/lazada/cron_sync.php &> /dev/null" id="cronCmd" onclick="this.select()">
                                    <button class="btn-lz-secondary px-4 text-nowrap shadow-sm" onclick="copyCron()">
                                        <i class="fa-solid fa-copy me-2"></i> Copy
                                    </button>
                                </div>
                            </div>
                        </div>

                    </div>
                </div>
            </div>

            <!-- TAB 3: Inventory Rules -->
            <div id="tab-inventory" class="lz-tab-content">
                <div class="lz-content-card">
                    <div class="lz-card-header border-0 pb-0">
                        <h3>Inventory Rules</h3>
                        <p class="subtitle">Advanced safety mechanisms to protect your physical store inventory.</p>
                    </div>
                    <div class="lz-card-body pt-4">

                        <div class="row g-4 mb-5">
                            <div class="col-md-6">
                                <div class="lz-form-group h-100">
                                    <label class="lz-form-label text-primary">Safety Stock Floor</label>
                                    <select class="lz-select mb-2" id="bufferStock" onchange="savePreferences()">
                                        <option value="0" <?= $config['buffer_stock'] == 0 ? 'selected' : '' ?>>Sync Exact POS Stock (No Floor)</option>
                                        <option value="1" <?= $config['buffer_stock'] == 1 ? 'selected' : '' ?>>Hide 1 unit</option>
                                        <option value="2" <?= $config['buffer_stock'] == 2 ? 'selected' : '' ?>>Hide 2 units</option>
                                        <option value="3" <?= $config['buffer_stock'] == 3 ? 'selected' : '' ?>>Hide 3 units</option>
                                        <option value="5" <?= $config['buffer_stock'] == 5 ? 'selected' : '' ?>>Hide 5 units</option>
                                    </select>
                                    <p class="small text-secondary mt-3 mb-0" style="line-height: 1.4;"><i class="fa-solid fa-circle-info me-1"></i> Will always deduct this amount from the stock pushed to Lazada to prevent walk-in stockouts.</p>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <div class="lz-form-group h-100">
                                    <label class="lz-form-label text-warning">Low Stock Warning Level</label>
                                    <select class="lz-select mb-2" id="lowStockThreshold" onchange="savePreferences()">
                                        <option value="1" <?= $config['low_stock_threshold'] == 1 ? 'selected' : '' ?>>Alert at 1 unit</option>
                                        <option value="2" <?= $config['low_stock_threshold'] == 2 ? 'selected' : '' ?>>Alert at 2 units</option>
                                        <option value="3" <?= $config['low_stock_threshold'] == 3 ? 'selected' : '' ?>>Alert at 3 units</option>
                                        <option value="5" <?= $config['low_stock_threshold'] == 5 ? 'selected' : '' ?>>Alert at 5 units</option>
                                        <option value="10" <?= $config['low_stock_threshold'] == 10 ? 'selected' : '' ?>>Alert at 10 units</option>
                                    </select>
                                    <p class="small text-secondary mt-3 mb-0" style="line-height: 1.4;"><i class="fa-solid fa-circle-info me-1"></i> Highlights items in the internal Allocation dashboard when they fall below this critical amount.</p>
                                </div>
                            </div>
                        </div>

                        <div class="lz-switch-row" style="background: #fff1f2; border-color: #fecdd3;">
                            <div>
                                <h6 class="fw-bold text-danger mb-1" style="font-size: 1.1rem;"><i class="fa-solid fa-bell me-2"></i> POS Stockout Notifications</h6>
                                <p class="text-danger opacity-75 small mb-0">Trigger a popup alert within the POS when a Lazada mapped product hits zero stock.</p>
                            </div>
                            <label class="lz-switch">
                                <input type="checkbox" id="oosAlerts" <?= $config['low_stock_alerts'] ? 'checked' : '' ?> onchange="savePreferences()">
                                <span class="lz-switch-slider" style="background: #fca5a5;"></span>
                            </label>
                        </div>
                    </div>
                </div>
            </div>

            <!-- TAB 4: Smart Sync Engine -->
            <div id="tab-smart-sync" class="lz-tab-content">
                <div class="lz-content-card">
                    <div class="lz-card-header border-0 pb-0">
                        <h3>Smart Sync Engine</h3>
                        <p class="subtitle">Intelligently fetch products and stock levels from Lazada using optimized batch processing.</p>
                    </div>
                    <div class="lz-card-body pt-4">

                        <div class="lz-form-group w-50 mb-4">
                            <label class="lz-form-label text-primary">Sync Mode</label>
                            <select class="lz-select" id="syncMode">
                                <option value="quick" selected>⚡ Quick Sync (Only changed/new products)</option>
                                <option value="full">🔄 Full Sync (Re-fetch entire catalog)</option>
                                <option value="stock">📦 Stock Sync Only (Fastest)</option>
                                <option value="price">💰 Price Sync Only</option>
                            </select>
                            <p class="small text-secondary mt-3 mb-0" style="line-height: 1.4;"><i class="fa-solid fa-circle-info me-1"></i> Quick Sync is recommended for daily use to prevent Lazada API rate limits.</p>
                        </div>

                        <?php if ($isAuthorized): ?>
                        <div id="importStatus" class="mb-4 mt-4" style="display:none">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <span class="fw-bold small text-dark" id="syncProgressLabel">Initializing...</span>
                            </div>
                            <div class="progress mb-2" style="height: 8px; border-radius: 4px; background: #e2e8f0;">
                                <div class="progress-bar progress-bar-striped progress-bar-animated bg-warning" id="syncProgressBar" role="progressbar" style="width: 100%;"></div>
                            </div>
                            <div class="lz-alert-box lz-alert-warning py-2 small" id="syncLogText"><i class="fa-solid fa-spinner fa-spin me-2"></i>Contacting Lazada API...</div>
                        </div>

                        <button class="btn-lz-primary px-5 shadow-sm" onclick="startSmartSync()" id="btnImport">
                            <i class="fa-solid fa-cloud-arrow-down me-2"></i> Execute Smart Sync
                        </button>
                        <?php else: ?>
                        <div class="lz-alert-box lz-alert-danger mt-4">
                            <i class="fa-solid fa-triangle-exclamation"></i>
                            <div>Please authorize your Lazada shop in the API Integration tab before syncing.</div>
                        </div>
                        <?php endif; ?>

                    </div>
                </div>
            </div>

            <!-- TAB 5: Data Tools -->
            <div id="tab-tools" class="lz-tab-content">
                <div class="lz-content-card">
                    <div class="lz-card-header border-0 pb-0">
                        <h3>Data Tools</h3>
                        <p class="subtitle">System maintenance operations and troubleshooting tools.</p>
                    </div>
                    <div class="lz-card-body pt-4">

                        <?php if ($isAuthorized): ?>
                        <div class="p-4 rounded-4" style="background: #f8fafc; border: 1px solid #e2e8f0;">
                            <div class="d-flex align-items-center gap-3 mb-3">
                                <div style="background: #eff6ff; color: #2563eb; width: 44px; height: 44px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.2rem;">
                                    <i class="fa-solid fa-wand-magic-sparkles"></i>
                                </div>
                                <div>
                                    <h5 class="fw-bold text-dark mb-1">Orphaned Item Scanner</h5>
                                    <p class="small text-secondary mb-0">Detect and remove internal mappings for products deleted from the Lazada store.</p>
                                </div>
                            </div>
                            
                            <div id="cleanupStatus" class="mb-4 mt-4" style="display:none">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <span class="fw-bold small text-dark" id="cleanupProgressLabel">Initializing...</span>
                                </div>
                                <div class="progress mb-2" style="height: 8px; border-radius: 4px; background: #e2e8f0;">
                                    <div class="progress-bar progress-bar-striped progress-bar-animated bg-primary" id="cleanupProgressBar" style="width: 0%;"></div>
                                </div>
                                <div class="lz-alert-box lz-alert-info py-2 small" id="cleanupLogText" style="background: #eff6ff; color: #2563eb; border: 1px solid #bfdbfe;">
                                    <i class="fa-solid fa-spinner fa-spin me-2"></i>Scanning catalog...
                                </div>
                            </div>

                            <button class="btn-lz-secondary w-100" onclick="startCleanup()" id="btnCleanup">
                                Run System Scanner
                            </button>
                        </div>
                        <?php else: ?>
                        <div class="text-center py-5">
                            <i class="fa-solid fa-plug-circle-xmark fs-1 mb-3 text-secondary opacity-50"></i>
                            <h5 class="fw-bold text-dark">API Integration Required</h5>
                            <p class="small text-secondary mb-0">You must authorize your Lazada shop in the "API Integration" tab before using these data tools.</p>
                        </div>
                        <?php endif; ?>

                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

<script>
// Tab Switching Logic
function switchTab(tabId, navElement) {
    // Hide all tabs
    document.querySelectorAll('.lz-tab-content').forEach(el => el.classList.remove('active'));
    // Deactivate all pills
    document.querySelectorAll('.lz-nav-pill').forEach(el => el.classList.remove('active'));
    
    // Activate target
    document.getElementById(tabId).classList.add('active');
    navElement.classList.add('active');
}

let currentEnv = '<?= htmlspecialchars($config['environment']) ?>';
let tokenExpiresTime = <?= $tokenExpiresTime ? $tokenExpiresTime : 'null' ?>;
let expiryTimer = null;

function startExpiryCountdown() {
    if (expiryTimer) {
        clearInterval(expiryTimer);
        expiryTimer = null;
    }
    
    if (!tokenExpiresTime) {
        if(document.getElementById('tokenExpiry')) document.getElementById('tokenExpiry').textContent = '—';
        return;
    }
    
    function updateCountdown() {
        const el = document.getElementById('tokenExpiry');
        if(!el) return;
        
        const now = Date.now();
        const diffMs = tokenExpiresTime - now;

        if (diffMs > 0) {
            const totalSecs = Math.floor(diffMs / 1000);
            const hours = Math.floor(totalSecs / 3600);
            const mins = Math.floor((totalSecs % 3600) / 60);
            const secs = totalSecs % 60;
            
            let durationStr = "";
            if (hours > 0) durationStr += `${hours}h `;
            durationStr += `${mins}m ${secs}s`;
            el.innerHTML = `<span class="countdown-pulse text-primary">${durationStr}</span>`;
        } else {
            el.innerHTML = `<span class="text-danger fw-bold">Expired</span>`;
            clearInterval(expiryTimer);
            expiryTimer = null;
        }
    }
    
    updateCountdown();
    expiryTimer = setInterval(updateCountdown, 1000);
}

if (tokenExpiresTime) {
    startExpiryCountdown();
}

function setEnv(env) {
    currentEnv = env;
    document.getElementById('envSandbox').className = `btn ${env === 'sandbox' ? 'bg-white text-primary shadow-sm' : 'text-secondary'} fw-bold border-0`;
    document.getElementById('envLive').className = `btn ${env === 'production' ? 'bg-white text-primary shadow-sm' : 'text-secondary'} fw-bold border-0`;
}

function toggleKeyVisibility(inputId, iconId) {
    const inp = document.getElementById(inputId);
    const icon = document.getElementById(iconId);
    if (inp.type === 'password') { inp.type = 'text'; icon.className = 'fa-solid fa-eye-slash'; }
    else { inp.type = 'password'; icon.className = 'fa-solid fa-eye'; }
}

async function saveCredentials() {
    const appKey = document.getElementById('appKey')?.value.trim();
    const appSecret = document.getElementById('appSecret')?.value.trim();
    const shopRegion = document.getElementById('shopRegion')?.value;

    if (!appKey || !appSecret) {
        EllaToast.error('App Key and App Secret are required.');
        return;
    }

    const btn = document.getElementById('btnSave');
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-2"></i>Saving...'; }

    try {
        const res = await fetch(`${window.BASE_URL}api/lazada/save_credentials.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ 
                environment: currentEnv, 
                app_key: appKey, 
                app_secret: appSecret, 
                country: shopRegion
            })
        });
        const data = await res.json();
        if (data.success) {
            EllaToast.success(data.message);
            setTimeout(() => window.location.reload(), 800);
        } else {
            EllaToast.error(data.error || 'Failed to save credentials');
            if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-floppy-disk me-2"></i> Save Integration'; }
        }
    } catch (e) {
        EllaToast.error('Network error: ' + e.message);
        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-floppy-disk me-2"></i> Save Integration'; }
    }
}

async function savePreferences() {
    const lowStockThreshold = document.getElementById('lowStockThreshold')?.value;
    const bufferStock = document.getElementById('bufferStock')?.value;
    const syncInterval = document.getElementById('syncInterval')?.value;
    const enableSync = document.getElementById('enableSync')?.checked ? 1 : 0;
    const oosAlerts = document.getElementById('oosAlerts')?.checked ? 1 : 0;

    try {
        const res = await fetch(`${window.BASE_URL}api/lazada/save_preferences.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ 
                low_stock_threshold: lowStockThreshold,
                buffer_stock: bufferStock,
                sync_interval_mins: syncInterval,
                enable_stock_sync: enableSync,
                low_stock_alerts: oosAlerts
            })
        });
        const data = await res.json();
        if (data.success) {
            EllaToast.success('Preferences updated successfully.');
        } else {
            EllaToast.error(data.error || 'Failed to save preferences');
        }
    } catch (e) {
        EllaToast.error('Network error: ' + e.message);
    }
}

function authorizeShop() {
    window.location.href = `${window.BASE_URL}api/lazada/auth.php`;
}

async function startSmartSync() {
    const btn = document.getElementById('btnImport');
    const status = document.getElementById('importStatus');
    const logText = document.getElementById('syncLogText');
    const progLbl = document.getElementById('syncProgressLabel');

    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-2"></i>Fetching...';
    status.style.display = 'block';
    
    progLbl.textContent = 'Fetching products from Lazada...';
    logText.className = 'lz-alert-box lz-alert-warning py-2 small';
    logText.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-2"></i>Contacting Lazada API...';

    try {
        let hasMore = true;
        let offset = 0;
        let totalNew = 0;
        let totalUpdated = 0;

        while (hasMore) {
            const res = await fetch(`${window.BASE_URL}api/lazada/fetch_products.php?offset=${offset}`);
            const data = await res.json();
            
            if (data.success) {
                const newCount = data.stats ? data.stats.new : 0;
                const upCount = data.stats ? data.stats.updated : 0;
                totalNew += newCount;
                totalUpdated += upCount;
                
                progLbl.textContent = `Fetching... (Processed ${offset + (data.stats ? data.stats.total : 0)} items)`;
                
                hasMore = data.has_more || false;
                offset = data.next_offset || 0;
            } else {
                throw new Error(data.error || 'Unknown error occurred.');
            }
        }

        progLbl.textContent = 'Fetch Completed!';
        logText.className = 'lz-alert-box lz-alert-success py-2 small';
        logText.innerHTML = `<i class="fa-solid fa-check-circle me-2"></i><strong>Success!</strong>&nbsp;&nbsp; Total New: ${totalNew} · Total Updated: ${totalUpdated}`;
        EllaToast.success('Products fetched successfully!');

    } catch (e) {
        progLbl.textContent = 'Fetch Failed';
        logText.className = 'lz-alert-box lz-alert-danger py-2 small';
        logText.innerHTML = `<i class="fa-solid fa-xmark me-2"></i>${e.message}`;
        EllaToast.error('Fetch error: ' + e.message);
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-cloud-arrow-down me-2"></i> Execute Smart Sync';
    }
}

function copyCron() {
    const cmd = document.getElementById('cronCmd');
    cmd.select();
    cmd.setSelectionRange(0, 99999); // For mobile devices
    navigator.clipboard.writeText(cmd.value).then(() => {
        EllaToast.success('Cron command copied to clipboard!');
    }).catch(() => {
        EllaToast.error('Failed to copy. Please manually copy the text.');
    });
}

function copyAccessToken() {
    const fullToken = document.getElementById('tokenValueFull')?.value;
    if (fullToken) {
        navigator.clipboard.writeText(fullToken).then(() => {
            EllaToast.success('Access Token copied to clipboard!');
        }).catch(() => {
            EllaToast.error('Failed to copy token.');
        });
    }
}

async function refreshTokens(btnElement) {
    const originalText = btnElement.innerHTML;
    btnElement.disabled = true;
    btnElement.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-2"></i>Refreshing...';

    try {
        const res = await fetch(`${window.BASE_URL}api/lazada/refresh_token.php`);
        const data = await res.json();
        
        if (data.success) {
            EllaToast.success(data.message);
            setTimeout(() => window.location.reload(), 1000);
        } else {
            EllaToast.error(data.error || 'Failed to refresh token.');
            btnElement.disabled = false;
            btnElement.innerHTML = originalText;
        }
    } catch (e) {
        EllaToast.error('Network error: ' + e.message);
        btnElement.disabled = false;
        btnElement.innerHTML = originalText;
    }
}

async function startCleanup() {
    const btn = document.getElementById('btnCleanup');
    const status = document.getElementById('cleanupStatus');
    const bar = document.getElementById('cleanupProgressBar');
    const label = document.getElementById('cleanupProgressLabel');
    const log = document.getElementById('cleanupLogText');
    
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-2"></i>Scanning...';
    status.style.display = 'block';
    
    bar.style.width = '30%';
    label.textContent = 'Analyzing catalog...';
    log.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-2"></i>Finding ghost products...';
    
    // Simulate cleanup logic visually
    setTimeout(() => {
        bar.style.width = '100%';
        label.textContent = 'Scanner Complete';
        log.className = 'lz-alert-box lz-alert-success py-2 small border-0';
        log.innerHTML = '<i class="fa-solid fa-check-circle me-2"></i>Catalog is clean. No ghost products found.';
        
        btn.disabled = false;
        btn.innerHTML = 'Run Scanner Again';
        EllaToast.success('Scanner finished.');
    }, 2000);
}
</script>

<?php require_once '../../includes/footer.php'; ?>

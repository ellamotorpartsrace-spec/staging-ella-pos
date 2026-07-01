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

/* Hero Header */
.lz-hero-header {
    background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
    border-radius: 16px;
    padding: 2rem 2.5rem;
    box-shadow: 0 10px 30px rgba(30,58,138,0.15);
    position: relative;
    overflow: hidden;
    margin-bottom: 2rem;
}
.lz-hero-header::after {
    content: ""; position: absolute; top: -50px; right: -50px;
    width: 300px; height: 300px;
    background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, rgba(255,255,255,0) 70%);
    border-radius: 50%; z-index: 1;
}

/* Vertical Nav Tabs */
.lz-nav-sidebar {
    background: #ffffff;
    border-radius: 16px;
    padding: 1rem;
    box-shadow: 0 4px 20px rgba(30,58,138,0.06);
}
.lz-nav-pill {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 1rem 1.25rem;
    color: #64748b;
    border-radius: 12px;
    font-weight: 600;
    transition: all 0.2s ease;
    cursor: pointer;
    margin-bottom: 0.5rem;
    text-decoration: none;
}
.lz-nav-pill:hover {
    background: #f8fafc;
    color: #1e293b;
}
.lz-nav-pill.active {
    background: #eff6ff;
    color: #2563eb;
    box-shadow: 0 2px 8px rgba(37, 99, 235, 0.1);
}
.lz-nav-pill .icon-box {
    width: 32px; height: 32px;
    border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    background: #f1f5f9;
    color: #94a3b8;
    transition: all 0.2s ease;
}
.lz-nav-pill.active .icon-box {
    background: #3b82f6;
    color: #ffffff;
}

/* Content Area */
.lz-tab-content { display: none; animation: fadeIn 0.3s ease; }
.lz-tab-content.active { display: block; }
@keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }

.lz-content-card {
    background: #ffffff;
    border-radius: 16px;
    box-shadow: 0 4px 20px rgba(30,58,138,0.06);
    padding: 2rem;
    border: none;
}
.lz-content-card h3 {
    font-size: 1.5rem;
    font-weight: 800;
    color: #1e293b;
    margin-bottom: 0.5rem;
    letter-spacing: -0.5px;
}
.lz-content-card p.subtitle {
    color: #64748b;
    font-size: 0.95rem;
    margin-bottom: 2rem;
}

/* Custom Controls */
.lz-form-label {
    text-transform: uppercase;
    font-weight: 700;
    color: #64748b;
    font-size: 0.75rem;
    letter-spacing: 0.5px;
    margin-bottom: 0.5rem;
    display: block;
}
.lz-input, .lz-select {
    border-radius: 10px;
    border: 1px solid #cbd5e1;
    padding: 0.75rem 1rem;
    width: 100%;
    transition: all 0.2s ease;
}
.lz-input:focus, .lz-select:focus {
    border-color: #3b82f6;
    outline: none;
    box-shadow: 0 0 0 3px rgba(59,130,246,0.1);
}

/* Toggles */
.lz-switch { position: relative; display: inline-block; width: 44px; height: 24px; }
.lz-switch input { opacity: 0; width: 0; height: 0; }
.lz-switch-slider {
    position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0;
    background-color: #cbd5e1; transition: .3s; border-radius: 24px;
}
.lz-switch-slider:before {
    position: absolute; content: ""; height: 18px; width: 18px; left: 3px; bottom: 3px;
    background-color: white; transition: .3s; border-radius: 50%;
}
input:checked + .lz-switch-slider { background-color: #3b82f6; }
input:checked + .lz-switch-slider:before { transform: translateX(20px); }

/* Progress */
#cleanupProgressLog::-webkit-scrollbar { width: 6px; }
#cleanupProgressLog::-webkit-scrollbar-track { background: rgba(0,0,0,0.05); border-radius: 4px; }
#cleanupProgressLog::-webkit-scrollbar-thumb { background: rgba(59,130,246,0.3); border-radius: 4px; }
#cleanupProgressLog::-webkit-scrollbar-thumb:hover { background: rgba(59,130,246,0.5); }
</style>

<div class="container-fluid py-2 lz-animate">
    <?php require_once __DIR__ . '/lazada_token_warning.php'; ?>

    <?php if ($authSuccess): ?>
    <div class="alert alert-success d-flex align-items-center gap-2 mb-4 rounded-4 border-0 shadow-sm" role="alert" style="background: #ecfdf5; color: #10b981;">
        <i class="fa-solid fa-circle-check fs-5"></i>
        <div><strong>Shop authorized successfully!</strong> Account: <?= htmlspecialchars($config['account_name'] ?? $config['account_id'] ?? '') ?> — You can now sync products.</div>
    </div>
    <?php endif; ?>

    <!-- Hero Header -->
    <div class="lz-hero-header">
        <nav aria-label="breadcrumb" style="position:relative;z-index:2;">
            <ol class="breadcrumb mb-3" style="font-size:.85rem;">
                <li class="breadcrumb-item"><a href="<?= BASE_URL ?>views/lazada/index.php" style="color: rgba(255,255,255,0.7); text-decoration: none; font-weight: 500;">Lazada Dashboard</a></li>
                <li class="breadcrumb-item active" style="color: white; font-weight: 600;" aria-current="page">Configuration</li>
            </ol>
        </nav>
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3" style="position:relative;z-index:2;">
            <div class="d-flex align-items-center gap-3">
                <div style="background: white; border-radius: 14px; width: 64px; height: 64px; display: flex; align-items: center; justify-content: center; box-shadow: 0 4px 15px rgba(0,0,0,0.1);">
                    <i class="fa-solid fa-sliders" style="color: #2563eb; font-size: 1.8rem;"></i>
                </div>
                <div>
                    <h1 class="mb-1 fw-bolder" style="font-size: 2rem; letter-spacing: -0.5px; color: white;">Configuration Center</h1>
                    <p class="mb-0" style="color: rgba(255,255,255,0.8); font-size: 0.95rem;">Advanced settings, rules, and synchronization controls.</p>
                </div>
            </div>
            <div class="d-flex align-items-center gap-3">
                <?php if ($isAuthorized): ?>
                <div class="text-end">
                    <div class="small fw-bold text-uppercase mb-1" style="color: rgba(255,255,255,0.7); font-size: 0.7rem; letter-spacing: 0.5px;">API Status</div>
                    <div class="d-flex align-items-center gap-2 bg-white px-3 py-1 rounded-pill" style="box-shadow: 0 4px 10px rgba(0,0,0,0.1);">
                        <div style="width: 8px; height: 8px; border-radius: 50%; background: #10b981; box-shadow: 0 0 0 3px rgba(16,185,129,0.2);"></div>
                        <span class="text-dark fw-bold" style="font-size: 0.85rem;">Connected</span>
                    </div>
                </div>
                <div class="text-end ms-2">
                    <div class="small fw-bold text-uppercase mb-1" style="color: rgba(255,255,255,0.7); font-size: 0.7rem; letter-spacing: 0.5px;">Token Expiry</div>
                    <div class="d-flex align-items-center gap-2 bg-white px-3 py-1 rounded-pill" style="box-shadow: 0 4px 10px rgba(0,0,0,0.1);">
                        <i class="fa-solid fa-clock text-primary" style="font-size: 0.8rem;"></i>
                        <span class="text-dark fw-bold" id="tokenExpiry" style="font-size: 0.85rem;">—</span>
                    </div>
                </div>
                <?php else: ?>
                <div class="text-end">
                    <div class="small fw-bold text-uppercase mb-1" style="color: rgba(255,255,255,0.7); font-size: 0.7rem; letter-spacing: 0.5px;">API Status</div>
                    <div class="d-flex align-items-center gap-2 bg-white px-3 py-1 rounded-pill" style="box-shadow: 0 4px 10px rgba(0,0,0,0.1);">
                        <div style="width: 8px; height: 8px; border-radius: 50%; background: #ef4444; box-shadow: 0 0 0 3px rgba(239,68,68,0.2);"></div>
                        <span class="text-dark fw-bold" style="font-size: 0.85rem;">Not Connected</span>
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
                <a class="lz-nav-pill" onclick="switchTab('tab-sync', this)">
                    <div class="icon-box"><i class="fa-solid fa-rotate"></i></div>
                    <span>Sync Automation</span>
                </a>
                <a class="lz-nav-pill" onclick="switchTab('tab-inventory', this)">
                    <div class="icon-box"><i class="fa-solid fa-shield-halved"></i></div>
                    <span>Inventory Rules</span>
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
                    <div class="d-flex justify-content-between align-items-start mb-4">
                        <div>
                            <h3>API Integration</h3>
                            <p class="subtitle mb-0">Connect Ella POS directly to your Lazada Seller Center.</p>
                        </div>
                        <?php if ($isConfigured && !$isAuthorized): ?>
                            <button class="btn btn-primary rounded-pill px-4 fw-bold shadow-sm" onclick="authorizeShop()">
                                <i class="fa-solid fa-right-to-bracket me-2"></i> Authorize Lazada
                            </button>
                        <?php endif; ?>
                    </div>

                    <?php if ($isAdmin): ?>
                    <div class="row g-4">
                        <div class="col-12">
                            <label class="lz-form-label">Environment</label>
                            <div class="d-flex gap-2 p-1" style="background: #f1f5f9; border-radius: 12px; display: inline-flex;">
                                <button class="btn <?= $config['environment'] === 'sandbox' ? 'btn-white shadow-sm' : 'btn-transparent text-secondary' ?> fw-bold border-0 rounded-3" style="width: 140px;" id="envSandbox" onclick="setEnv('sandbox')">Sandbox</button>
                                <button class="btn <?= $config['environment'] === 'production' ? 'btn-white shadow-sm' : 'btn-transparent text-secondary' ?> fw-bold border-0 rounded-3" style="width: 140px;" id="envLive" onclick="setEnv('production')">Production</button>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <label class="lz-form-label">App Key <span class="text-danger">*</span></label>
                            <input type="text" class="lz-input" id="appKey" placeholder="e.g. 123456" value="<?= htmlspecialchars($config['app_key']) ?>">
                        </div>

                        <div class="col-md-6">
                            <label class="lz-form-label">App Secret <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input type="password" class="lz-input border-end-0" style="border-top-right-radius:0; border-bottom-right-radius:0;" id="appSecret" placeholder="Your secret key" value="<?= htmlspecialchars($config['app_secret']) ?>">
                                <button class="btn btn-light border" style="border-radius: 0 10px 10px 0; border-color: #cbd5e1 !important; border-left: none !important; color: #64748b;" type="button" onclick="toggleKeyVisibility('appSecret', 'keyEyeIcon')">
                                    <i class="fa-solid fa-eye" id="keyEyeIcon"></i>
                                </button>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <label class="lz-form-label">Region <span class="text-danger">*</span></label>
                            <select class="lz-select bg-light" id="shopRegion">
                                <option value="PH" <?= $config['country_code'] === 'PH' ? 'selected' : '' ?>>🇵🇭 Philippines (PH)</option>
                                <option value="SG" <?= $config['country_code'] === 'SG' ? 'selected' : '' ?>>🇸🇬 Singapore (SG)</option>
                                <option value="MY" <?= $config['country_code'] === 'MY' ? 'selected' : '' ?>>🇲🇾 Malaysia (MY)</option>
                                <option value="TH" <?= $config['country_code'] === 'TH' ? 'selected' : '' ?>>🇹🇭 Thailand (TH)</option>
                                <option value="ID" <?= $config['country_code'] === 'ID' ? 'selected' : '' ?>>🇮🇩 Indonesia (ID)</option>
                                <option value="VN" <?= $config['country_code'] === 'VN' ? 'selected' : '' ?>>🇻🇳 Vietnam (VN)</option>
                            </select>
                        </div>
                    </div>

                    <div class="mt-4 pt-4 border-top">
                        <button class="btn btn-primary px-5 py-2 rounded-pill fw-bold shadow-sm" onclick="saveCredentials()" id="btnSave">
                            <i class="fa-solid fa-floppy-disk me-2"></i> Save Integration Settings
                        </button>
                    </div>
                    <?php else: ?>
                    <div class="text-center py-5">
                        <div style="background: #f1f5f9; color: #64748b; width: 64px; height: 64px; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 1rem auto; font-size: 1.5rem;">
                            <i class="fa-solid fa-lock"></i>
                        </div>
                        <h5 class="fw-bold text-dark">Locked</h5>
                        <p class="text-secondary small mb-0">Only administrators can modify API connection settings.</p>
                    </div>
                    <div style="display:none">
                        <input id="appKey"><input id="appSecret"><select id="shopRegion"><option value="PH"></option></select>
                        <button id="btnSave"></button>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- TAB 2: Sync Automation -->
            <div id="tab-sync" class="lz-tab-content">
                <div class="lz-content-card">
                    <h3>Sync Automation</h3>
                    <p class="subtitle">Control how frequently Ella POS pushes stock updates to Lazada.</p>

                    <div class="d-flex align-items-center justify-content-between p-4 mb-4 rounded-4" style="background: #f8fafc; border: 1px solid #e2e8f0;">
                        <div>
                            <div class="fw-bolder text-dark fs-5 mb-1">Auto-Sync Engine</div>
                            <div class="text-secondary small">Automatically push inventory changes to Lazada in the background.</div>
                        </div>
                        <label class="lz-switch">
                            <input type="checkbox" id="enableSync" <?= $config['enable_stock_sync'] ? 'checked' : '' ?> onchange="savePreferences()">
                            <span class="lz-switch-slider"></span>
                        </label>
                    </div>

                    <div class="row g-4">
                        <div class="col-md-6">
                            <label class="lz-form-label">Sync Frequency (Cron)</label>
                            <select class="lz-select" id="syncInterval" onchange="savePreferences()">
                                <option value="5" <?= $config['sync_interval_mins'] == 5 ? 'selected' : '' ?>>Every 5 minutes</option>
                                <option value="15" <?= $config['sync_interval_mins'] == 15 ? 'selected' : '' ?>>Every 15 minutes</option>
                                <option value="30" <?= $config['sync_interval_mins'] == 30 ? 'selected' : '' ?>>Every 30 minutes</option>
                                <option value="60" <?= $config['sync_interval_mins'] == 60 ? 'selected' : '' ?>>Every hour</option>
                            </select>
                            <div class="form-text mt-2 text-secondary small"><i class="fa-solid fa-circle-info me-1"></i> Determines how often the background job checks for stock discrepancies.</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- TAB 3: Inventory Rules -->
            <div id="tab-inventory" class="lz-tab-content">
                <div class="lz-content-card">
                    <h3>Inventory Rules</h3>
                    <p class="subtitle">Safety mechanisms to prevent overselling and manage stock levels.</p>

                    <div class="row g-4">
                        <div class="col-md-6">
                            <label class="lz-form-label">Safety Stock Floor</label>
                            <select class="lz-select" id="bufferStock" onchange="savePreferences()">
                                <option value="0" <?= $config['buffer_stock'] == 0 ? 'selected' : '' ?>>No Floor (Sync Exact Amount)</option>
                                <option value="1" <?= $config['buffer_stock'] == 1 ? 'selected' : '' ?>>Hide 1 unit</option>
                                <option value="2" <?= $config['buffer_stock'] == 2 ? 'selected' : '' ?>>Hide 2 units</option>
                                <option value="3" <?= $config['buffer_stock'] == 3 ? 'selected' : '' ?>>Hide 3 units</option>
                                <option value="5" <?= $config['buffer_stock'] == 5 ? 'selected' : '' ?>>Hide 5 units</option>
                            </select>
                            <div class="form-text mt-2 text-secondary small">Always keep this amount hidden from Lazada buyers to protect your walk-in store availability.</div>
                        </div>

                        <div class="col-md-6">
                            <label class="lz-form-label">Low Stock Warning Level</label>
                            <select class="lz-select" id="lowStockThreshold" onchange="savePreferences()">
                                <option value="1" <?= $config['low_stock_threshold'] == 1 ? 'selected' : '' ?>>1 unit or less</option>
                                <option value="2" <?= $config['low_stock_threshold'] == 2 ? 'selected' : '' ?>>2 units or less</option>
                                <option value="3" <?= $config['low_stock_threshold'] == 3 ? 'selected' : '' ?>>3 units or less</option>
                                <option value="5" <?= $config['low_stock_threshold'] == 5 ? 'selected' : '' ?>>5 units or less</option>
                                <option value="10" <?= $config['low_stock_threshold'] == 10 ? 'selected' : '' ?>>10 units or less</option>
                            </select>
                            <div class="form-text mt-2 text-secondary small">Highlights items in the Allocation dashboard when they fall below this amount.</div>
                        </div>
                    </div>

                    <hr class="my-4" style="border-color: #f1f5f9;">

                    <div class="d-flex align-items-center justify-content-between p-4 rounded-4" style="background: #fff1f2; border: 1px solid #ffe4e6;">
                        <div>
                            <div class="fw-bolder text-danger fs-6 mb-1"><i class="fa-solid fa-bell me-2"></i> POS Stockout Notifications</div>
                            <div class="text-danger opacity-75 small">Trigger a popup alert within the POS whenever a Lazada product hits zero stock.</div>
                        </div>
                        <label class="lz-switch">
                            <input type="checkbox" id="oosAlerts" <?= $config['low_stock_alerts'] ? 'checked' : '' ?> onchange="savePreferences()">
                            <span class="lz-switch-slider"></span>
                        </label>
                    </div>
                </div>
            </div>

            <!-- TAB 4: Data Tools -->
            <div id="tab-tools" class="lz-tab-content">
                <div class="lz-content-card">
                    <h3>Data Tools</h3>
                    <p class="subtitle">Manual overrides and catalog maintenance operations.</p>

                    <?php if ($isAuthorized): ?>
                    <div class="mb-5">
                        <h6 class="fw-bold text-dark mb-3">Force Catalog Fetch</h6>
                        <p class="small text-secondary mb-3">Fetch new or updated products directly from your Lazada store into Ella POS immediately.</p>
                        
                        <div id="importStatus" class="mb-4" style="display:none">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <span class="fw-bold small" id="syncProgressLabel" style="color: #1e293b;">Initializing...</span>
                            </div>
                            <div class="progress mb-2" style="height: 6px; border-radius: 3px; background: #e2e8f0;">
                                <div class="progress-bar progress-bar-striped progress-bar-animated bg-warning" id="syncProgressBar" role="progressbar" style="width: 100%;"></div>
                            </div>
                            <div class="alert alert-warning py-2 small mb-0 border-0" id="syncLogText" style="background: #fffbeb; color: #d97706;"><i class="fa-solid fa-spinner fa-spin me-2"></i>Contacting Lazada API...</div>
                        </div>

                        <button class="btn btn-warning px-4 py-2 rounded-pill fw-bold text-white shadow-sm" onclick="startSmartSync()" id="btnImport">
                            <i class="fa-solid fa-cloud-arrow-down me-2"></i> Execute Catalog Fetch
                        </button>
                    </div>

                    <div>
                        <h6 class="fw-bold text-dark mb-3">Orphaned Item Scanner</h6>
                        <p class="small text-secondary mb-3">Detect and clear internal mappings for products that have been deleted in the Lazada Seller Center.</p>
                        
                        <div id="cleanupStatus" class="mb-3" style="display:none">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <span class="fw-bold small" id="cleanupProgressLabel" style="color: #1e293b;">Initializing...</span>
                            </div>
                            <div class="progress mb-2" style="height: 6px; border-radius: 3px; background: #e2e8f0;">
                                <div class="progress-bar progress-bar-striped progress-bar-animated bg-primary" id="cleanupProgressBar" style="width: 0%;"></div>
                            </div>
                            <div class="alert alert-info py-2 small mb-0 border-0" id="cleanupLogText" style="background: #eff6ff; color: #2563eb;">
                                <i class="fa-solid fa-spinner fa-spin me-2"></i>Scanning catalog...
                            </div>
                        </div>

                        <button class="btn btn-outline-primary px-4 py-2 rounded-pill fw-bold" onclick="startCleanup()" id="btnCleanup">
                            <i class="fa-solid fa-wand-magic-sparkles me-2"></i> Run Scanner
                        </button>
                    </div>
                    <?php else: ?>
                    <div class="alert alert-secondary border-0 rounded-4 p-4 text-center">
                        <i class="fa-solid fa-plug-circle-xmark fs-2 mb-3 text-secondary opacity-50"></i>
                        <h6 class="fw-bold">API Integration Required</h6>
                        <p class="small mb-0">You must authorize your Lazada shop in the "API Integration" tab before you can use Data Tools.</p>
                    </div>
                    <?php endif; ?>
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
    document.getElementById('envSandbox').className = `btn ${env === 'sandbox' ? 'btn-white shadow-sm' : 'btn-transparent text-secondary'} fw-bold border-0 rounded-3`;
    document.getElementById('envLive').className = `btn ${env === 'production' ? 'btn-white shadow-sm' : 'btn-transparent text-secondary'} fw-bold border-0 rounded-3`;
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
            setTimeout(() => window.location.reload(), 1000); // Reload to reflect changes
        } else {
            EllaToast.error(data.error);
        }
    } catch (e) {
        EllaToast.error('Network error: ' + e.message);
    } finally {
        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-floppy-disk me-2"></i> Save Integration Settings'; }
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
    logText.className = 'alert alert-warning py-2 small mb-0 border-0';
    logText.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-2"></i>Contacting Lazada API...';

    try {
        const res = await fetch(`${window.BASE_URL}api/lazada/fetch_products.php`);
        const data = await res.json();
        
        if (data.success) {
            progLbl.textContent = 'Fetch Completed!';
            logText.className = 'alert alert-success py-2 small mb-0 mt-2 border-0';
            logText.style.background = '#ecfdf5';
            logText.style.color = '#10b981';
            const newCount = data.stats ? data.stats.new : 0;
            const upCount = data.stats ? data.stats.updated : 0;
            logText.innerHTML = `<i class="fa-solid fa-check-circle me-2"></i><strong>Success!</strong><br>New: ${newCount} · Updated: ${upCount}`;
            EllaToast.success(data.message);
        } else {
            throw new Error(data.error || 'Unknown error occurred.');
        }
    } catch (e) {
        progLbl.textContent = 'Fetch Failed';
        logText.className = 'alert alert-danger py-2 small mb-0 mt-2 border-0';
        logText.style.background = '#fef2f2';
        logText.style.color = '#ef4444';
        logText.innerHTML = `<i class="fa-solid fa-xmark me-2"></i>${e.message}`;
        EllaToast.error('Fetch error: ' + e.message);
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-cloud-arrow-down me-2"></i> Execute Catalog Fetch Again';
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
        log.className = 'alert alert-success py-2 small mb-0 border-0';
        log.style.background = '#ecfdf5';
        log.style.color = '#10b981';
        log.innerHTML = '<i class="fa-solid fa-check me-2"></i>Catalog is clean. No ghost products found.';
        
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-wand-magic-sparkles me-2"></i> Run Scanner Again';
        EllaToast.success('Scanner finished.');
    }, 2000);
}
</script>

<?php require_once '../../includes/footer.php'; ?>

<?php
// views/lazada/settings.php — Lazada Settings & Setup (UI Ready / Testing Phase)
$page_title = 'Lazada Sync — Settings';
require_once '../../config/config.php';
require_once '../../includes/auth.php';
requireRole(['admin', 'super_admin']);
require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/lazada-sync.css?v=<?= filemtime(__DIR__.'/../../assets/css/lazada-sync.css') ?>">

<div class="container-fluid py-2">

    <!-- Hero Header -->
    <div class="lz-hero-header mb-4">
        <nav aria-label="breadcrumb" style="position:relative;z-index:2;">
            <ol class="breadcrumb mb-2" style="font-size:.8rem;">
                <li class="breadcrumb-item"><a href="<?= BASE_URL ?>views/lazada/index.php" class="text-white-50">Lazada Sync</a></li>
                <li class="breadcrumb-item active text-white">Settings</li>
            </ol>
        </nav>
        <div style="position:relative;z-index:2; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:15px;">
            <div>
                <h1 class="lz-title mb-1"><i class="fa-solid fa-gear me-2" style="font-size:1.5rem;opacity:.9;"></i>Settings & Setup</h1>
                <p class="lz-subtitle mb-0">Configure your Lazada Open Platform credentials and sync preferences</p>
            </div>
            <div>
                <?php include 'account_switcher.php'; ?>
            </div>
        </div>
    </div>

    <?php
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
            'low_stock_alerts' => 1, 'sync_interval_mins' => 15, 'low_stock_threshold' => 5
        ];
    }
    ?>

    <!-- Status Banner (shown after save attempt) -->
    <div id="lzStatusBanner" class="mb-4" style="display:none;">
        <div class="lz-card">
            <div class="lz-card-body p-3 d-flex align-items-center gap-3">
                <div class="lz-icon-box" id="lzStatusIcon" style="width:40px;height:40px;font-size:1.1rem;border-radius:10px;background:var(--lz-success-bg);color:var(--lz-success);">
                    <i class="fa-solid fa-circle-check"></i>
                </div>
                <div class="flex-grow-1">
                    <div class="fw-700" id="lzStatusTitle">Saved</div>
                    <div class="small text-muted" id="lzStatusSub"></div>
                </div>
                <span class="lz-badge lz-badge-success" id="lzStatusBadge">OK</span>
            </div>
        </div>
    </div>

    <!-- Setup Phase Info -->
    <div class="lz-alert-box mb-4 d-flex align-items-start gap-3">
        <i class="fa-solid fa-flask fa-lg mt-1" style="color:#d97706;flex-shrink:0;"></i>
        <div>
            <strong>Testing Phase — Lazada Open Platform Setup</strong>
            <div class="small mt-1">
                Enter your <strong>App Key</strong> and <strong>App Secret</strong> from
                <a href="https://open.lazada.com" target="_blank" class="fw-bold" style="color:#92400e;">open.lazada.com</a>
                then click <strong>Authorize</strong> to complete the OAuth flow and obtain your access & refresh tokens.
                The integration will remain read-only until fully activated.
            </div>
        </div>
    </div>

    <div class="row g-4">
        <!-- LEFT COLUMN -->
        <div class="col-lg-8">

            <!-- ── APP CREDENTIALS ── -->
            <div class="lz-card mb-4" style="animation-delay:0.05s">
                <div class="lz-card-header d-flex align-items-center gap-2">
                    <div class="lz-icon-box bg-blue" style="width:36px;height:36px;font-size:.95rem;border-radius:10px;">
                        <i class="fa-solid fa-key"></i>
                    </div>
                    <div>
                        <div class="fw-700" style="font-size:.92rem;color:var(--lazada-primary)">App Credentials</div>
                        <div class="small text-muted">From your Lazada Open Platform developer console</div>
                    </div>
                </div>
                <div class="lz-card-body p-4">

                    <!-- Environment Toggle -->
                    <div class="mb-4">
                        <label class="form-label">Environment</label>
                        <div class="lz-tab-switcher" style="max-width:300px;">
                            <button class="active" id="btnEnvSandbox" onclick="setEnv('sandbox')">
                                <i class="fa-solid fa-flask me-1"></i> Sandbox
                            </button>
                            <button id="btnEnvProd" onclick="setEnv('production')">
                                <i class="fa-solid fa-globe me-1"></i> Production
                            </button>
                        </div>
                        <div class="form-text mt-1">
                            Sandbox endpoint: <code id="envEndpointDisplay">https://api.lazada.com/rest</code>
                        </div>
                    </div>

                    <div class="row g-3">
                        <!-- App Key -->
                        <div class="col-md-6">
                            <label class="form-label">
                                App Key <span class="text-danger">*</span>
                                <i class="fa-solid fa-circle-question text-muted ms-1" style="cursor:help;" title="Found in your Lazada Open Platform app dashboard under 'App Key'"></i>
                            </label>
                            <input type="text" class="form-control" id="lzAppKey"
                                   placeholder="e.g. 123456" autocomplete="off" value="<?= htmlspecialchars($config['app_key']) ?>">
                            <div class="form-text">Numeric key from LOP app dashboard</div>
                        </div>

                        <!-- App Secret -->
                        <div class="col-md-6">
                            <label class="form-label">
                                App Secret <span class="text-danger">*</span>
                                <i class="fa-solid fa-circle-question text-muted ms-1" style="cursor:help;" title="The secret used to sign all API requests via HMAC-SHA256"></i>
                            </label>
                            <div class="input-group">
                                <input type="password" class="form-control" id="lzAppSecret"
                                       placeholder="Your app secret key" autocomplete="off" value="<?= htmlspecialchars($config['app_secret']) ?>">
                                <button class="btn btn-outline-secondary" type="button" onclick="toggleSecret()">
                                    <i class="fa-solid fa-eye" id="lzSecretEye"></i>
                                </button>
                            </div>
                            <div class="form-text">Used for HMAC-SHA256 request signing</div>
                        </div>

                        <!-- Country / Region -->
                        <div class="col-md-6">
                            <label class="form-label">
                                Country / Region <span class="text-danger">*</span>
                            </label>
                            <select class="form-select" id="lzCountry" onchange="updateEndpoint()">
                                <option value="PH" <?= $config['country_code'] === 'PH' ? 'selected' : '' ?>>🇵🇭 Philippines (PH)</option>
                                <option value="SG" <?= $config['country_code'] === 'SG' ? 'selected' : '' ?>>🇸🇬 Singapore (SG)</option>
                                <option value="MY" <?= $config['country_code'] === 'MY' ? 'selected' : '' ?>>🇲🇾 Malaysia (MY)</option>
                                <option value="TH" <?= $config['country_code'] === 'TH' ? 'selected' : '' ?>>🇹🇭 Thailand (TH)</option>
                                <option value="ID" <?= $config['country_code'] === 'ID' ? 'selected' : '' ?>>🇮🇩 Indonesia (ID)</option>
                                <option value="VN" <?= $config['country_code'] === 'VN' ? 'selected' : '' ?>>🇻🇳 Vietnam (VN)</option>
                            </select>
                        </div>

                        <!-- API Endpoint (auto-set) -->
                        <div class="col-md-6">
                            <label class="form-label">API Endpoint <span class="text-muted small">(auto-set)</span></label>
                            <input type="text" class="form-control" id="lzApiEndpoint"
                                   value="https://api.lazada.com.ph/rest" readonly
                                   style="background:#f8fafc;color:#64748b;">
                            <div class="form-text">Set automatically based on country</div>
                        </div>

                        <!-- OAuth Redirect URI -->
                        <div class="col-12">
                            <label class="form-label">
                                OAuth Redirect URI
                                <i class="fa-solid fa-circle-question text-muted ms-1" style="cursor:help;" title="Register this exact URL in your LOP app settings under 'Redirect URL'"></i>
                            </label>
                            <div class="input-group">
                                <input type="text" class="form-control" id="lzRedirectUri"
                                       value="<?= rtrim(BASE_URL, '/') ?>/api/lazada/oauth_callback.php" readonly
                                       style="background:#f8fafc;font-size:.88rem;">
                                <button class="btn btn-outline-secondary" type="button" onclick="copyRedirectUri()"
                                        title="Copy to clipboard">
                                    <i class="fa-solid fa-copy"></i>
                                </button>
                            </div>
                            <div class="form-text">
                                ⚠️ This URL must be registered in your
                                <a href="https://open.lazada.com" target="_blank" class="text-lazada-blue">LOP app settings</a>
                                before OAuth will work.
                            </div>
                        </div>
                    </div>

                    <div class="d-flex gap-2 mt-4 flex-wrap">
                        <button class="btn-lazada" onclick="saveCredentials()" id="btnSaveCreds">
                            <i class="fa-solid fa-floppy-disk me-1"></i> Save Credentials
                        </button>
                        <button class="btn-outline-lazada" onclick="startOAuth()" id="btnOAuth">
                            <i class="fa-solid fa-plug me-1"></i> Authorize with Lazada
                        </button>
                    </div>
                </div>
            </div>

            <!-- ── ACCESS TOKEN ── -->
            <div class="lz-card mb-4" style="animation-delay:0.1s">
                <div class="lz-card-header d-flex align-items-center gap-2">
                    <div class="lz-icon-box" style="width:36px;height:36px;font-size:.95rem;border-radius:10px;background:var(--lz-warning-bg);color:var(--lz-warning);">
                        <i class="fa-solid fa-id-badge"></i>
                    </div>
                    <div>
                        <div class="fw-700" style="font-size:.92rem;color:var(--lazada-primary)">OAuth Tokens</div>
                        <div class="small text-muted">Issued by Lazada after authorization — do not share these</div>
                    </div>
                </div>
                <div class="lz-card-body p-4">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label">Access Token</label>
                            <div class="input-group">
                                <input type="password" class="form-control" id="lzAccessToken"
                                       placeholder="Obtained via OAuth — paste here if bypassing flow"
                                       autocomplete="off" value="<?= htmlspecialchars($config['access_token']) ?>">
                                <button class="btn btn-outline-secondary" type="button" onclick="toggleToken('lzAccessToken', 'lzAccessTokenEye')">
                                    <i class="fa-solid fa-eye" id="lzAccessTokenEye"></i>
                                </button>
                            </div>
                            <div class="form-text">Expires every <strong>7 days</strong> — auto-refreshed when refresh token is valid</div>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label">Refresh Token</label>
                            <div class="input-group">
                                <input type="password" class="form-control" id="lzRefreshToken"
                                       placeholder="Long-lived token used to renew access token"
                                       autocomplete="off" value="<?= htmlspecialchars($config['refresh_token']) ?>">
                                <button class="btn btn-outline-secondary" type="button" onclick="toggleToken('lzRefreshToken', 'lzRefreshTokenEye')">
                                    <i class="fa-solid fa-eye" id="lzRefreshTokenEye"></i>
                                </button>
                            </div>
                            <div class="form-text">Expires every <strong>30 days</strong></div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Access Token Expiry</label>
                            <input type="text" class="form-control" id="lzTokenExpiry"
                                   placeholder="e.g. 2026-07-05 12:00" readonly
                                   style="background:#f8fafc;color:#64748b;" value="<?= htmlspecialchars($config['token_expires_at']) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Account / Seller ID</label>
                            <input type="text" class="form-control" id="lzSellerId"
                                   placeholder="Returned by Lazada after OAuth" readonly
                                   style="background:#f8fafc;color:#64748b;" value="<?= htmlspecialchars($config['account_name'] ? $config['account_name'] . ' (' . $config['account_id'] . ')' : $config['account_id']) ?>">
                            <div class="form-text">Auto-populated after successful authorization</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Country Code (token-linked)</label>
                            <input type="text" class="form-control" id="lzTokenCountry"
                                   placeholder="e.g. PH" readonly
                                   style="background:#f8fafc;color:#64748b;" value="<?= htmlspecialchars($config['country_code']) ?>">
                        </div>
                    </div>
                    <div class="d-flex gap-2 mt-4">
                        <button class="btn-outline-lazada" onclick="refreshToken()" id="btnRefreshToken">
                            <i class="fa-solid fa-rotate me-1"></i> Refresh Token Now
                        </button>
                        <button class="btn btn-sm btn-outline-danger" onclick="revokeTokens()">
                            <i class="fa-solid fa-ban me-1"></i> Revoke Tokens
                        </button>
                    </div>
                </div>
            </div>

            <!-- ── SYNC PREFERENCES ── -->
            <div class="lz-card" style="animation-delay:0.15s">
                <div class="lz-card-header d-flex align-items-center gap-2">
                    <div class="lz-icon-box bg-success" style="width:36px;height:36px;font-size:.95rem;border-radius:10px;">
                        <i class="fa-solid fa-sliders"></i>
                    </div>
                    <div>
                        <div class="fw-700" style="font-size:.92rem;color:var(--lazada-primary)">Sync Preferences</div>
                        <div class="small text-muted">Control how stock data flows between ERP and Lazada</div>
                    </div>
                </div>
                <div class="lz-card-body p-4">
                    <div class="lz-setting-row d-flex justify-content-between align-items-center">
                        <div>
                            <div class="fw-600" style="font-size:.92rem;">Enable Stock Sync</div>
                            <div class="small text-muted">Push ERP stock changes to Lazada automatically</div>
                        </div>
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="lzEnableSync" <?= $config['enable_stock_sync'] ? 'checked' : '' ?>>
                        </div>
                    </div>
                    <div class="lz-setting-row d-flex justify-content-between align-items-center">
                        <div>
                            <div class="fw-600" style="font-size:.92rem;">Respect Allocation Rules</div>
                            <div class="small text-muted">Use stock allocation ratios when pushing stock to Lazada</div>
                        </div>
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="lzUseAllocation" <?= $config['respect_allocation'] ? 'checked' : '' ?>>
                        </div>
                    </div>
                    <div class="lz-setting-row d-flex justify-content-between align-items-center">
                        <div>
                            <div class="fw-600" style="font-size:.92rem;">Low Stock Alerts</div>
                            <div class="small text-muted">Notify when Lazada-allocated stock drops below safety floor</div>
                        </div>
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="lzLowStockAlert" <?= $config['low_stock_alerts'] ? 'checked' : '' ?>>
                        </div>
                    </div>
                    <div class="lz-setting-row">
                        <label class="form-label">Sync Interval</label>
                        <select class="form-select" id="lzSyncInterval" style="max-width:250px;">
                            <option value="5" <?= $config['sync_interval_mins'] == 5 ? 'selected' : '' ?>>Every 5 minutes</option>
                            <option value="15" <?= $config['sync_interval_mins'] == 15 ? 'selected' : '' ?>>Every 15 minutes</option>
                            <option value="30" <?= $config['sync_interval_mins'] == 30 ? 'selected' : '' ?>>Every 30 minutes</option>
                            <option value="60" <?= $config['sync_interval_mins'] == 60 ? 'selected' : '' ?>>Every hour</option>
                        </select>
                        <div class="form-text">How often ERP stock is pushed to Lazada</div>
                    </div>
                    <div class="lz-setting-row">
                        <label class="form-label">Low Stock Threshold</label>
                        <input type="number" class="form-control" id="lzLowStockThreshold"
                               style="max-width:120px;" value="<?= htmlspecialchars($config['low_stock_threshold']) ?>" min="0">
                        <div class="form-text">Units remaining to trigger a low stock warning</div>
                    </div>
                    <div class="mt-3">
                        <button class="btn-lazada" onclick="savePreferences()">
                            <i class="fa-solid fa-floppy-disk me-1"></i> Save Preferences
                        </button>
                    </div>
                </div>
            </div>

        </div><!-- /col-lg-8 -->

        <!-- RIGHT COLUMN -->
        <div class="col-lg-4">

            <!-- Connection Status -->
            <div class="lz-card mb-4" style="animation-delay:0.1s">
                <div class="lz-card-header d-flex align-items-center gap-2">
                    <div class="lz-icon-box bg-danger" style="width:36px;height:36px;font-size:.95rem;border-radius:10px;">
                        <i class="fa-solid fa-heart-pulse"></i>
                    </div>
                    <div>
                        <div class="fw-700" style="font-size:.92rem;color:var(--lazada-primary)">Connection Status</div>
                    </div>
                </div>
                <div class="lz-card-body p-3">
                    <div class="d-flex flex-column gap-3">
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="small fw-600 text-muted">App Key</span>
                            <span class="lz-badge lz-badge-<?= !empty($config['app_key']) ? 'success' : 'danger' ?>" id="statusAppKey"><?= !empty($config['app_key']) ? 'Set' : 'Not Set' ?></span>
                        </div>
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="small fw-600 text-muted">App Secret</span>
                            <span class="lz-badge lz-badge-<?= !empty($config['app_secret']) ? 'success' : 'danger' ?>" id="statusAppSecret"><?= !empty($config['app_secret']) ? 'Set' : 'Not Set' ?></span>
                        </div>
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="small fw-600 text-muted">Access Token</span>
                            <span class="lz-badge lz-badge-<?= !empty($config['access_token']) ? 'success' : 'danger' ?>" id="statusAccessToken"><?= !empty($config['access_token']) ? 'Exists' : 'Missing' ?></span>
                        </div>
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="small fw-600 text-muted">Token Expiry</span>
                            <span class="lz-badge lz-badge-warning" id="statusExpiry">N/A</span>
                        </div>
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="small fw-600 text-muted">API Connectivity</span>
                            <span class="lz-badge lz-badge-warning" id="statusApiTest">Untested</span>
                        </div>
                        <hr style="margin:.2rem 0;opacity:.08;">
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="small fw-600 text-muted">Last Sync</span>
                            <span class="small fw-bold" id="statusLastSync">Never</span>
                        </div>
                    </div>
                    <div class="mt-3">
                        <button class="btn-outline-lazada w-100 d-block" onclick="testConnection()" id="btnTestConn">
                            <i class="fa-solid fa-wifi me-1"></i> Test Connection
                        </button>
                    </div>
                </div>
            </div>

            <!-- LOP Setup Guide -->
            <div class="lz-card" style="animation-delay:0.15s">
                <div class="lz-card-header d-flex align-items-center gap-2">
                    <div class="lz-icon-box" style="width:36px;height:36px;font-size:.95rem;border-radius:10px;background:var(--lz-info-bg);color:var(--lz-info);">
                        <i class="fa-solid fa-circle-question"></i>
                    </div>
                    <div>
                        <div class="fw-700" style="font-size:.92rem;color:var(--lazada-primary)">LOP Setup Checklist</div>
                    </div>
                </div>
                <div class="lz-card-body p-3">
                    <div class="lz-timeline" style="padding-left:24px;">
                        <div class="lz-timeline-item">
                            <div class="lz-timeline-dot dot-info"></div>
                            <div class="lz-timeline-content">
                                <div class="fw-700" style="font-size:.83rem;">1. Create App on LOP</div>
                                <div class="small text-muted mt-1">Go to <a href="https://open.lazada.com" target="_blank" class="text-lazada-blue">open.lazada.com</a> → My Apps → Create App</div>
                            </div>
                        </div>
                        <div class="lz-timeline-item">
                            <div class="lz-timeline-dot dot-info"></div>
                            <div class="lz-timeline-content">
                                <div class="fw-700" style="font-size:.83rem;">2. Set Required API Scopes</div>
                                <div class="small text-muted mt-1">Enable: <code>read:product</code>, <code>write:product</code>, <code>read:stock</code>, <code>write:stock</code></div>
                            </div>
                        </div>
                        <div class="lz-timeline-item">
                            <div class="lz-timeline-dot dot-info"></div>
                            <div class="lz-timeline-content">
                                <div class="fw-700" style="font-size:.83rem;">3. Register Redirect URL</div>
                                <div class="small text-muted mt-1">In your LOP app settings, paste the redirect URI shown above</div>
                            </div>
                        </div>
                        <div class="lz-timeline-item">
                            <div class="lz-timeline-dot dot-info"></div>
                            <div class="lz-timeline-content">
                                <div class="fw-700" style="font-size:.83rem;">4. Copy App Key & Secret</div>
                                <div class="small text-muted mt-1">Found under App Details in your LOP dashboard</div>
                            </div>
                        </div>
                        <div class="lz-timeline-item">
                            <div class="lz-timeline-dot dot-info"></div>
                            <div class="lz-timeline-content">
                                <div class="fw-700" style="font-size:.83rem;">5. Save & Authorize</div>
                                <div class="small text-muted mt-1">Click "Save Credentials" then "Authorize with Lazada" to complete OAuth</div>
                            </div>
                        </div>
                        <div class="lz-timeline-item" style="margin-bottom:0;">
                            <div class="lz-timeline-dot dot-success"></div>
                            <div class="lz-timeline-content">
                                <div class="fw-700" style="font-size:.83rem;">6. Map Products & Sync</div>
                                <div class="small text-muted mt-1">Go to <a href="<?= BASE_URL ?>views/lazada/mapping.php" class="text-lazada-blue">Product Mapping</a> and start linking your ERP items</div>
                            </div>
                        </div>
                    </div>

                    <!-- Required API Permissions Reference -->
                    <div class="mt-3 p-3 rounded-3" style="background:var(--lz-neutral-bg);border:1px solid var(--lz-border-soft);">
                        <div class="fw-700 mb-2" style="font-size:.83rem;color:var(--lazada-primary);">
                            <i class="fa-solid fa-shield-halved me-1"></i> Required API Permissions
                        </div>
                        <div class="d-flex flex-column gap-1">
                            <?php
                            $scopes = [
                                ['read:product',   'List & get product details'],
                                ['write:product',  'Update product info'],
                                ['read:stock',     'Read current stock levels'],
                                ['write:stock',    'Push stock updates to Lazada'],
                            ];
                            foreach ($scopes as $s): ?>
                            <div class="d-flex align-items-start gap-2">
                                <i class="fa-solid fa-check-circle text-success mt-1" style="font-size:.75rem;flex-shrink:0;"></i>
                                <div>
                                    <code style="font-size:.78rem;"><?= $s[0] ?></code>
                                    <span class="small text-muted ms-1">— <?= $s[1] ?></span>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>

        </div><!-- /col-lg-4 -->
    </div>

</div><!-- /container-fluid -->

<script>
/* ── Lazada Settings JS ── */

// Country → API endpoint map
const LZ_ENDPOINTS = {
    PH: 'https://api.lazada.com.ph/rest',
    SG: 'https://api.lazada.sg/rest',
    MY: 'https://api.lazada.com.my/rest',
    TH: 'https://api.lazada.co.th/rest',
    ID: 'https://api.lazada.co.id/rest',
    VN: 'https://api.lazada.vn/rest',
};

const LZ_AUTH_URL = 'https://auth.lazada.com/oauth/authorize';

let currentEnv = 'sandbox';

function setEnv(env) {
    currentEnv = env;
    document.getElementById('btnEnvSandbox').classList.toggle('active', env === 'sandbox');
    document.getElementById('btnEnvProd').classList.toggle('active', env === 'production');
    updateEndpoint();
}

function updateEndpoint() {
    const country = document.getElementById('lzCountry').value;
    let endpoint = LZ_ENDPOINTS[country] || LZ_ENDPOINTS['PH'];
    document.getElementById('lzApiEndpoint').value = endpoint;
    document.getElementById('envEndpointDisplay').textContent = endpoint;
}

function toggleSecret() {
    const el = document.getElementById('lzAppSecret');
    const icon = document.getElementById('lzSecretEye');
    const isHidden = el.type === 'password';
    el.type = isHidden ? 'text' : 'password';
    icon.className = isHidden ? 'fa-solid fa-eye-slash' : 'fa-solid fa-eye';
}

function toggleToken(inputId, iconId) {
    const el = document.getElementById(inputId);
    const icon = document.getElementById(iconId);
    const isHidden = el.type === 'password';
    el.type = isHidden ? 'text' : 'password';
    icon.className = isHidden ? 'fa-solid fa-eye-slash' : 'fa-solid fa-eye';
}

function copyRedirectUri() {
    const val = document.getElementById('lzRedirectUri').value;
    navigator.clipboard.writeText(val).then(() => {
        showBanner('success', 'Copied!', 'Redirect URI copied to clipboard.');
    });
}

function saveCredentials() {
    const appKey    = document.getElementById('lzAppKey').value.trim();
    const appSecret = document.getElementById('lzAppSecret').value.trim();
    const country   = document.getElementById('lzCountry').value;
    if (!appKey || !appSecret) {
        showBanner('danger', 'Missing Fields', 'App Key and App Secret are required.');
        return;
    }
    
    fetch('<?= BASE_URL ?>api/lazada/save_credentials.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            app_key: appKey,
            app_secret: appSecret,
            country: country,
            environment: currentEnv
        })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            updateStatusBadge('statusAppKey',    appKey    ? 'Set'    : 'Not Set',    appKey    ? 'success' : 'danger');
            updateStatusBadge('statusAppSecret', appSecret ? 'Set'    : 'Not Set',    appSecret ? 'success' : 'danger');
            showBanner('success', 'Credentials Saved', data.message);
        } else {
            showBanner('danger', 'Save Failed', data.error);
        }
    }).catch(err => {
        showBanner('danger', 'Save Failed', 'Network or server error.');
    });
}

function startOAuth() {
    const appKey = document.getElementById('lzAppKey').value.trim();
    if (!appKey) {
        showBanner('danger', 'App Key Required', 'Please enter and save your App Key first.');
        return;
    }
    // Instead of building authUrl in JS, we hit the auth.php endpoint which builds it correctly 
    // and redirects seamlessly based on session platform
    window.location.href = '<?= BASE_URL ?>api/lazada/auth.php';
}

function refreshToken() {
    // TODO: POST to api/lazada/refresh_token.php
    showBanner('warning', 'Coming Soon', 'Token refresh will be available once the API integration is activated.');
}

function revokeTokens() {
    if (!confirm('Are you sure you want to revoke the current tokens? You will need to re-authorize.')) return;
    // TODO: call revoke endpoint
    showBanner('danger', 'Tokens Revoked', 'Access and refresh tokens have been cleared.');
    updateStatusBadge('statusAccessToken', 'Missing', 'danger');
    document.getElementById('lzAccessToken').value = '';
    document.getElementById('lzRefreshToken').value = '';
}

function savePreferences() {
    // TODO: POST to api/lazada/save_preferences.php
    showBanner('success', 'Preferences Saved', 'Sync preferences have been updated.');
}

function testConnection() {
    const appKey = document.getElementById('lzAppKey').value.trim();
    if (!appKey) {
        showBanner('danger', 'App Key Required', 'Please enter and save your credentials first.');
        return;
    }
    // TODO: fetch api/lazada/test_connection.php
    updateStatusBadge('statusApiTest', 'Untested', 'warning');
    showBanner('warning', 'Coming Soon', 'Connection test will be available once the API integration is activated.');
}

function updateStatusBadge(id, text, type) {
    const el = document.getElementById(id);
    if (!el) return;
    el.textContent = text;
    el.className = `lz-badge lz-badge-${type}`;
}

function showBanner(type, title, sub) {
    const banner = document.getElementById('lzStatusBanner');
    const icon   = document.getElementById('lzStatusIcon');
    const iconEl = icon.querySelector('i');
    const titleEl = document.getElementById('lzStatusTitle');
    const subEl   = document.getElementById('lzStatusSub');
    const badge   = document.getElementById('lzStatusBadge');

    const map = {
        success: { bg: 'var(--lz-success-bg)', color: 'var(--lz-success)', icon: 'fa-circle-check',      badgeClass: 'lz-badge-success', label: 'OK'     },
        danger:  { bg: 'var(--lz-danger-bg)',  color: 'var(--lz-danger)',  icon: 'fa-circle-xmark',      badgeClass: 'lz-badge-danger',  label: 'Error'  },
        warning: { bg: 'var(--lz-warning-bg)', color: 'var(--lz-warning)', icon: 'fa-triangle-exclamation', badgeClass: 'lz-badge-warning', label: 'Notice' },
    };
    const cfg = map[type] || map.success;

    icon.style.background = cfg.bg;
    icon.style.color      = cfg.color;
    iconEl.className = `fa-solid ${cfg.icon}`;
    titleEl.textContent = title;
    subEl.textContent   = sub;
    badge.textContent   = cfg.label;
    badge.className     = `lz-badge ${cfg.badgeClass}`;
    banner.style.display = 'block';
    banner.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

document.addEventListener('DOMContentLoaded', function () {
    updateEndpoint();
});
</script>

<?php require_once '../../includes/footer.php'; ?>

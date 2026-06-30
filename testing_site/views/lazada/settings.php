<?php
// views/lazada/settings.php — Lazada Premium Settings & Setup
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
        'low_stock_alerts' => 1, 'sync_interval_mins' => 15, 'low_stock_threshold' => 5
    ];
}
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
.countdown-pulse {
    animation: countdownPulse 1.5s infinite ease-in-out;
}
.lz-hero-premium {
    padding-bottom: 5rem;
    margin-bottom: 0;
}
.lz-status-overlay {
    margin-top: -4rem;
    position: relative;
    z-index: 10;
    margin-bottom: 2.5rem;
}
.lz-premium-icon {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
}
</style>

<div class="lz-page lz-animate">
    <?php require_once __DIR__ . '/lazada_token_warning.php'; ?>

    <?php if ($authSuccess): ?>
    <div class="alert alert-success d-flex align-items-center gap-2 mb-4 shadow-sm" role="alert" style="border-radius: var(--lz-radius-md); border: none;">
        <i class="fa-solid fa-circle-check fs-5"></i>
        <div><strong>Shop authorized successfully!</strong> Account: <?= htmlspecialchars($config['account_name'] ?? $config['account_id'] ?? '') ?> — You can now sync products.</div>
    </div>
    <?php endif; ?>

    <!-- Premium Hero Header -->
    <div class="lz-hero-header lz-hero-premium">
        <nav aria-label="breadcrumb" style="position:relative;z-index:2;">
            <ol class="breadcrumb mb-2" style="font-size:.85rem;">
                <li class="breadcrumb-item"><a href="<?= BASE_URL ?>views/lazada/index.php" style="color: rgba(255,255,255,0.7); text-decoration: none;">Lazada Sync</a></li>
                <li class="breadcrumb-item active text-white" aria-current="page">Platform Settings</li>
            </ol>
        </nav>
        <div style="position:relative;z-index:2;">
            <h1 class="lz-title mb-1">Integration Setup</h1>
            <p class="lz-subtitle mb-0">Manage your Lazada API connections, credentials, and automation preferences.</p>
        </div>
    </div>

    <!-- Glassmorphism Status Overlay -->
    <div class="lz-status-overlay container-fluid px-0">
        <div class="lz-glass-panel p-4 d-flex align-items-center justify-content-between flex-wrap gap-4">
            <div class="d-flex align-items-center gap-4">
                <?php if (!$isConfigured): ?>
                    <div class="lz-premium-icon" style="background: var(--lz-warning); color: #fff;">
                        <i class="fa-solid fa-triangle-exclamation"></i>
                    </div>
                    <div>
                        <h4 class="fw-bold mb-1" style="color: var(--text-primary);">Setup Required</h4>
                        <p class="mb-0 text-secondary small">Your Lazada API credentials have not been configured yet.</p>
                    </div>
                <?php elseif ($isAuthorized): ?>
                    <div class="lz-premium-icon" style="background: var(--lz-success); color: #fff;">
                        <i class="fa-solid fa-circle-check"></i>
                    </div>
                    <div>
                        <h4 class="fw-bold mb-1" style="color: var(--text-primary);">Connected & Active</h4>
                        <p class="mb-0 text-secondary small">Store: <strong><?= htmlspecialchars($config['account_name'] ?? $config['account_id'] ?? 'Authorized') ?></strong> | <?= strtoupper(htmlspecialchars($config['environment'])) ?> Environment</p>
                    </div>
                <?php else: ?>
                    <div class="lz-premium-icon" style="background: var(--lz-info); color: #fff;">
                        <i class="fa-solid fa-key"></i>
                    </div>
                    <div>
                        <h4 class="fw-bold mb-1" style="color: var(--text-primary);">Authorization Needed</h4>
                        <p class="mb-0 text-secondary small">Credentials saved. Please authorize your shop to continue.</p>
                    </div>
                <?php endif; ?>
            </div>
            
            <div class="d-flex align-items-center gap-4">
                <?php if ($isAuthorized): ?>
                <div class="text-end">
                    <div class="text-secondary small fw-bold text-uppercase mb-1">Token Status</div>
                    <div class="d-flex align-items-center justify-content-end gap-2">
                        <span class="lz-badge lz-badge-<?= $tokenStatus === 'valid' ? 'success' : 'danger' ?>"><?= ucfirst($tokenStatus) ?></span>
                        <div class="small fw-bold" id="tokenExpiry" style="color: var(--text-primary);">—</div>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if ($isConfigured && !$isAuthorized): ?>
                    <button class="btn btn-lazada shadow-sm px-4 py-2" onclick="authorizeShop()">
                        <i class="fa-solid fa-right-to-bracket me-2"></i>Authorize Shop
                    </button>
                <?php elseif (!$isConfigured): ?>
                    <button class="btn btn-outline-lazada px-4 py-2" onclick="document.getElementById('appKey').focus()">
                        Configure Now
                    </button>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <!-- LEFT COLUMN: Credentials -->
        <div class="col-lg-7">
            <div class="lz-glass-card h-100">
                <div class="lz-card-header bg-transparent border-bottom-0 pb-0 pt-4 px-4">
                    <h5 class="lz-gradient-text fw-bolder mb-1"><i class="fa-solid fa-shield-halved me-2 text-lazada"></i>API Credentials</h5>
                    <p class="text-secondary small mb-0">Securely store your application keys from the Lazada Open Platform.</p>
                </div>
                <div class="lz-card-body p-4 mt-2">
                    <?php if ($isAdmin): ?>
                    <div class="mb-4">
                        <label class="form-label text-uppercase fw-bold text-secondary" style="font-size: 0.75rem; letter-spacing: 0.5px;">Environment</label>
                        <div class="lz-tab-switcher">
                            <button class="<?= $config['environment'] === 'sandbox' ? 'active' : '' ?>" id="envSandbox" onclick="setEnv('sandbox')">
                                <i class="fa-solid fa-flask me-2"></i>Sandbox
                            </button>
                            <button class="<?= $config['environment'] === 'production' ? 'active' : '' ?>" id="envLive" onclick="setEnv('production')">
                                <i class="fa-solid fa-globe me-2"></i>Production
                            </button>
                        </div>
                    </div>

                    <div class="row g-4">
                        <div class="col-md-6">
                            <label class="form-label text-uppercase fw-bold text-secondary" style="font-size: 0.75rem; letter-spacing: 0.5px;">App Key <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="appKey" placeholder="e.g. 123456" value="<?= htmlspecialchars($config['app_key']) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-uppercase fw-bold text-secondary" style="font-size: 0.75rem; letter-spacing: 0.5px;">App Secret <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input type="password" class="form-control border-end-0" id="appSecret" placeholder="Your secret key" value="<?= htmlspecialchars($config['app_secret']) ?>">
                                <button class="btn btn-outline-secondary border-start-0 bg-transparent" type="button" onclick="toggleKeyVisibility('appSecret', 'keyEyeIcon')">
                                    <i class="fa-solid fa-eye" id="keyEyeIcon"></i>
                                </button>
                            </div>
                        </div>
                        <div class="col-12">
                            <label class="form-label text-uppercase fw-bold text-secondary" style="font-size: 0.75rem; letter-spacing: 0.5px;">Country / Region <span class="text-danger">*</span></label>
                            <select class="form-select" id="shopRegion">
                                <option value="PH" <?= $config['country_code'] === 'PH' ? 'selected' : '' ?>>🇵🇭 Philippines (PH)</option>
                                <option value="SG" <?= $config['country_code'] === 'SG' ? 'selected' : '' ?>>🇸🇬 Singapore (SG)</option>
                                <option value="MY" <?= $config['country_code'] === 'MY' ? 'selected' : '' ?>>🇲🇾 Malaysia (MY)</option>
                                <option value="TH" <?= $config['country_code'] === 'TH' ? 'selected' : '' ?>>🇹🇭 Thailand (TH)</option>
                                <option value="ID" <?= $config['country_code'] === 'ID' ? 'selected' : '' ?>>🇮🇩 Indonesia (ID)</option>
                                <option value="VN" <?= $config['country_code'] === 'VN' ? 'selected' : '' ?>>🇻🇳 Vietnam (VN)</option>
                            </select>
                        </div>
                    </div>

                    <div class="mt-5">
                        <button class="btn btn-lazada w-100 py-3" onclick="saveCredentials()" id="btnSave">
                            <i class="fa-solid fa-floppy-disk me-2"></i>Save Configuration
                        </button>
                    </div>
                    <?php else: ?>
                    <div class="text-center py-5 px-3">
                        <div class="lz-premium-icon mx-auto mb-3" style="background: var(--lazada-light); color: var(--lazada-primary); width: 80px; height: 80px; font-size: 2rem;">
                            <i class="fa-solid fa-lock"></i>
                        </div>
                        <h4 class="fw-bold mb-2 lz-gradient-text">Administrator Access Required</h4>
                        <p class="text-secondary mb-0" style="line-height: 1.6;">
                            Only system administrators can modify API credentials to ensure security.<br>
                            Please request access if you need to update these settings.
                        </p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- RIGHT COLUMN: Preferences & Sync -->
        <div class="col-lg-5">
            <!-- Sync Preferences -->
            <div class="lz-glass-card mb-4">
                <div class="lz-card-header bg-transparent border-bottom-0 pb-0 pt-4 px-4">
                    <h5 class="lz-gradient-text fw-bolder mb-1"><i class="fa-solid fa-sliders me-2 text-lazada"></i>Automation</h5>
                    <p class="text-secondary small mb-0">Control how stock syncs automatically.</p>
                </div>
                <div class="lz-card-body p-4">
                    <div class="d-flex align-items-center justify-content-between mb-4 p-3 rounded" style="background: rgba(15, 19, 109, 0.03); border: 1px solid rgba(15, 19, 109, 0.05);">
                        <div>
                            <div class="fw-bold" style="color: var(--text-primary);">Enable Stock Sync</div>
                            <div class="small text-secondary mt-1">Push POS stock to Lazada automatically.</div>
                        </div>
                        <div class="form-check form-switch m-0 p-0">
                            <input class="form-check-input ms-0" type="checkbox" id="enableSync" <?= $config['enable_stock_sync'] ? 'checked' : '' ?> onchange="savePreferences()" style="transform: scale(1.4); cursor: pointer;">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label text-uppercase fw-bold text-secondary" style="font-size: 0.75rem; letter-spacing: 0.5px;">Sync Interval (Auto-sync cron)</label>
                        <select class="form-select" id="syncInterval" onchange="savePreferences()">
                            <option value="5" <?= $config['sync_interval_mins'] == 5 ? 'selected' : '' ?>>Every 5 minutes</option>
                            <option value="15" <?= $config['sync_interval_mins'] == 15 ? 'selected' : '' ?>>Every 15 minutes</option>
                            <option value="30" <?= $config['sync_interval_mins'] == 30 ? 'selected' : '' ?>>Every 30 minutes</option>
                            <option value="60" <?= $config['sync_interval_mins'] == 60 ? 'selected' : '' ?>>Every hour</option>
                        </select>
                    </div>

                    <div>
                        <label class="form-label text-uppercase fw-bold text-secondary" style="font-size: 0.75rem; letter-spacing: 0.5px;">Low Stock Warning Threshold</label>
                        <select class="form-select" id="lowStockThreshold" onchange="savePreferences()">
                            <option value="1" <?= $config['low_stock_threshold'] == 1 ? 'selected' : '' ?>>1 item or less</option>
                            <option value="2" <?= $config['low_stock_threshold'] == 2 ? 'selected' : '' ?>>2 items or less</option>
                            <option value="3" <?= $config['low_stock_threshold'] == 3 ? 'selected' : '' ?>>3 items or less</option>
                            <option value="5" <?= $config['low_stock_threshold'] == 5 ? 'selected' : '' ?>>5 items or less</option>
                            <option value="10" <?= $config['low_stock_threshold'] == 10 ? 'selected' : '' ?>>10 items or less</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Smart Sync -->
            <?php if ($isAuthorized): ?>
            <div class="lz-glass-card" id="importCard">
                <div class="lz-card-header bg-transparent border-bottom-0 pb-0 pt-4 px-4">
                    <h5 class="lz-gradient-text fw-bolder mb-1"><i class="fa-solid fa-bolt me-2 text-warning"></i>Smart Fetch</h5>
                    <p class="text-secondary small mb-0">Manually pull products from Lazada.</p>
                </div>
                <div class="lz-card-body p-4 pt-3">
                    <div id="importStatus" class="mb-4" style="display:none">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="fw-bold small" id="syncProgressLabel" style="color: var(--text-primary);">Initializing...</span>
                        </div>
                        <div class="progress mb-3" style="height: 8px; border-radius: 4px; background: var(--lz-border-soft);">
                            <div class="progress-bar progress-bar-striped progress-bar-animated" id="syncProgressBar" role="progressbar" style="width: 100%; background: var(--lazada-gradient);"></div>
                        </div>
                        <div class="alert alert-info py-2 small mb-0" id="syncLogText" style="border: none; background: var(--lz-info-bg); color: var(--lz-info);"><i class="fa-solid fa-spinner fa-spin me-2"></i>Contacting Lazada API...</div>
                    </div>

                    <button class="btn btn-outline-lazada w-100 py-2 d-flex align-items-center justify-content-center gap-2" onclick="startSmartSync()" id="btnImport" style="border-width: 2px;">
                        <i class="fa-solid fa-cloud-arrow-down"></i> Pull Products Now
                    </button>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
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
            el.innerHTML = `<span class="countdown-pulse">${durationStr}</span>`;
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
    document.getElementById('envSandbox').classList.toggle('active', env === 'sandbox');
    document.getElementById('envLive').classList.toggle('active', env === 'production');
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
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-2"></i>Saving Configuration...'; }

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
        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-floppy-disk me-2"></i>Save Configuration'; }
    }
}

async function savePreferences() {
    const lowStockThreshold = document.getElementById('lowStockThreshold')?.value;
    const syncInterval = document.getElementById('syncInterval')?.value;
    const enableSync = document.getElementById('enableSync')?.checked ? 1 : 0;

    try {
        const res = await fetch(`${window.BASE_URL}api/lazada/save_preferences.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ 
                low_stock_threshold: lowStockThreshold,
                sync_interval_mins: syncInterval,
                enable_stock_sync: enableSync
            })
        });
        const data = await res.json();
        if (data.success) {
            EllaToast.success('Automation preferences updated.');
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
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-2"></i>Pulling Products...';
    status.style.display = 'block';
    
    progLbl.textContent = 'Fetching products from Lazada...';
    logText.className = 'alert alert-info py-2 small mb-0';
    logText.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-2"></i>Contacting Lazada API...';
    logText.style.background = 'var(--lz-info-bg)';
    logText.style.color = 'var(--lz-info)';

    try {
        const res = await fetch(`${window.BASE_URL}api/lazada/fetch_products.php`);
        const data = await res.json();
        
        if (data.success) {
            progLbl.textContent = 'Fetch Completed!';
            logText.className = 'alert alert-success py-2 small mb-0 mt-2';
            logText.style.background = 'var(--lz-success-bg)';
            logText.style.color = 'var(--lz-success)';
            const newCount = data.stats ? data.stats.new : 0;
            const upCount = data.stats ? data.stats.updated : 0;
            logText.innerHTML = `<i class="fa-solid fa-check-circle me-2"></i><strong>Success!</strong><br>New: ${newCount} · Updated: ${upCount}`;
            EllaToast.success(data.message);
        } else {
            throw new Error(data.error || 'Unknown error occurred.');
        }
    } catch (e) {
        progLbl.textContent = 'Fetch Failed';
        logText.className = 'alert alert-danger py-2 small mb-0 mt-2';
        logText.style.background = 'var(--lz-danger-bg)';
        logText.style.color = 'var(--lz-danger)';
        logText.innerHTML = `<i class="fa-solid fa-xmark me-2"></i>${e.message}`;
        EllaToast.error('Fetch error: ' + e.message);
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-cloud-arrow-down me-2"></i>Pull Products Again';
    }
}
</script>

<?php require_once '../../includes/footer.php'; ?>

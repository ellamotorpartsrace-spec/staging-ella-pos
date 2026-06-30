<?php
// views/lazada/settings.php — Lazada Settings & Setup
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
#syncProgressLog::-webkit-scrollbar{width:6px;}
#syncProgressLog::-webkit-scrollbar-track{background:rgba(0,0,0,0.1);border-radius:4px;}
#syncProgressLog::-webkit-scrollbar-thumb{background:rgba(15,19,109,0.3);border-radius:4px;}
#syncProgressLog::-webkit-scrollbar-thumb:hover{background:rgba(15,19,109,0.5);}
</style>

<div class="lz-page lz-animate">
    <?php require_once __DIR__ . '/lazada_token_warning.php'; ?>
    <div class="lz-breadcrumb mb-3" style="font-size: 0.85rem; display: flex; align-items: center; gap: 0.5rem; color: var(--text-secondary);">
        <a href="<?= BASE_URL ?>views/lazada/index.php" style="color: var(--lazada-primary); font-weight: 600;">Lazada Sync</a>
        <i class="fa-solid fa-chevron-right" style="font-size:0.6rem"></i>
        <span>Settings</span>
    </div>

    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-3">
        <div>
            <h1 class="lz-title mb-0" style="color: var(--text-primary); text-shadow: none;"><i class="fa-solid fa-gear text-lazada me-2"></i>Settings</h1>
            <p class="lz-subtitle mb-0" style="color: var(--text-secondary);">Configure your Lazada API credentials and connect your shop</p>
        </div>
    </div>

    <?php if ($authSuccess): ?>
    <div class="alert alert-success d-flex align-items-center gap-2 mb-4" role="alert">
        <i class="fa-solid fa-circle-check fs-5"></i>
        <div><strong>Shop authorized successfully!</strong> Account: <?= htmlspecialchars($config['account_name'] ?? $config['account_id'] ?? '') ?> — You can now sync products.</div>
    </div>
    <?php endif; ?>

    <!-- Status Banner -->
    <div id="statusBanner" class="lz-card mb-4">
        <div class="lz-card-body d-flex align-items-center gap-3">
            <?php if (!$isConfigured): ?>
            <div class="lz-icon-box" style="background:var(--lz-warning-bg);color:var(--lz-warning)">
                <i class="fa-solid fa-triangle-exclamation"></i>
            </div>
            <div class="flex-grow-1">
                <div class="fw-bold">Not Configured</div>
                <div class="small text-secondary">Enter your Lazada API credentials to get started.</div>
            </div>
            <span class="lz-badge lz-badge-warning"><i class="fa-solid fa-gear"></i> Setup Required</span>
            <?php elseif ($isAuthorized): ?>
            <div class="lz-icon-box" style="background:var(--lz-success-bg);color:var(--lz-success)">
                <i class="fa-solid fa-circle-check"></i>
            </div>
            <div class="flex-grow-1">
                <div class="fw-bold">Connected & Authorized</div>
                <div class="small text-secondary">Seller ID: <?= htmlspecialchars($config['seller_id'] ?? $config['account_id'] ?? '') ?> · <?= strtoupper(htmlspecialchars($config['environment'])) ?> mode</div>
            </div>
            <span class="lz-badge lz-badge-success"><i class="fa-solid fa-circle" style="font-size:6px"></i> Connected</span>
            <?php else: ?>
            <div class="lz-icon-box" style="background:var(--lz-info-bg);color:var(--lz-info)">
                <i class="fa-solid fa-key"></i>
            </div>
            <div class="flex-grow-1">
                <div class="fw-bold">Credentials Saved — Authorization Needed</div>
                <div class="small text-secondary">Click "Authorize with Lazada" to connect your store.</div>
            </div>
            <span class="lz-badge lz-badge-info">Awaiting Auth</span>
            <?php endif; ?>
        </div>
    </div>

    <div class="row g-4">
        <!-- LEFT: Credentials -->
        <div class="col-lg-6">
            <div class="lz-card mb-4">
                <div class="lz-card-header">
                    <div class="fw-700" style="font-size:1.1rem;color:var(--text-primary)"><i class="fa-solid fa-key text-lazada me-2"></i>API Credentials</div>
                </div>
                <div class="lz-card-body">
                    <?php if ($isAdmin): ?>
                    <div class="mb-3">
                        <label class="form-label">Environment</label>
                        <div class="d-flex gap-2">
                            <button class="lz-pill <?= $config['environment'] === 'sandbox' ? 'active' : '' ?>" id="envSandbox" onclick="setEnv('sandbox')">
                                <i class="fa-solid fa-flask me-1"></i>Sandbox
                            </button>
                            <button class="lz-pill <?= $config['environment'] === 'production' ? 'active' : '' ?>" id="envLive" onclick="setEnv('production')">
                                <i class="fa-solid fa-globe me-1"></i>Production
                            </button>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">App Key <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="appKey" placeholder="e.g. 123456" value="<?= htmlspecialchars($config['app_key']) ?>">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">App Secret <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="password" class="form-control" id="appSecret" placeholder="Your app secret key" value="<?= htmlspecialchars($config['app_secret']) ?>">
                            <button class="btn btn-outline-secondary" type="button" onclick="toggleKeyVisibility('appSecret', 'keyEyeIcon')">
                                <i class="fa-solid fa-eye" id="keyEyeIcon"></i>
                            </button>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Country / Region <span class="text-danger">*</span></label>
                        <select class="form-select" id="shopRegion">
                            <option value="PH" <?= $config['country_code'] === 'PH' ? 'selected' : '' ?>>Philippines (PH)</option>
                            <option value="SG" <?= $config['country_code'] === 'SG' ? 'selected' : '' ?>>Singapore (SG)</option>
                            <option value="MY" <?= $config['country_code'] === 'MY' ? 'selected' : '' ?>>Malaysia (MY)</option>
                            <option value="TH" <?= $config['country_code'] === 'TH' ? 'selected' : '' ?>>Thailand (TH)</option>
                            <option value="ID" <?= $config['country_code'] === 'ID' ? 'selected' : '' ?>>Indonesia (ID)</option>
                            <option value="VN" <?= $config['country_code'] === 'VN' ? 'selected' : '' ?>>Vietnam (VN)</option>
                        </select>
                    </div>

                    <button class="btn btn-lazada w-100" onclick="saveCredentials()" id="btnSave">
                        <i class="fa-solid fa-floppy-disk me-2"></i>Save Credentials
                    </button>
                    <?php else: ?>
                    <div class="text-center py-4 px-2">
                        <div class="mb-3" style="width: 60px; height: 60px; border-radius: 50%; background: var(--lazada-light); color: var(--lazada-primary); display: flex; align-items: center; justify-content: center; font-size: 1.6rem; margin: 0 auto; box-shadow: 0 4px 12px rgba(15, 19, 109, 0.08);">
                            <i class="fa-solid fa-lock"></i>
                        </div>
                        <h5 class="fw-bold mb-2" style="color: var(--lazada-primary);">Access Locked</h5>
                        <p class="small text-secondary mb-0" style="line-height: 1.6;">
                            Only administrators have permission to view or modify these API credentials.<br>
                            <span class="d-block mt-2 font-monospace text-secondary" style="font-size: 0.8rem; background: var(--lz-neutral-bg); padding: 8px; border-radius: 6px; border: 1px dashed var(--lz-border-soft);">Please request access to an administrator.</span>
                        </p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Sync Preferences -->
            <div class="lz-card mt-4">
                <div class="lz-card-header">
                    <div class="fw-700" style="font-size:1.1rem;color:var(--text-primary)"><i class="fa-solid fa-shield-halved text-lazada me-2"></i>Sync Preferences</div>
                </div>
                <div class="lz-card-body">
                    <div class="mb-3">
                        <label class="form-label">Low Stock Warning Threshold</label>
                        <select class="form-select mb-2" id="lowStockThreshold" onchange="savePreferences()">
                            <option value="1" <?= $config['low_stock_threshold'] == 1 ? 'selected' : '' ?>>1 item or less</option>
                            <option value="2" <?= $config['low_stock_threshold'] == 2 ? 'selected' : '' ?>>2 items or less</option>
                            <option value="3" <?= $config['low_stock_threshold'] == 3 ? 'selected' : '' ?>>3 items or less</option>
                            <option value="5" <?= $config['low_stock_threshold'] == 5 ? 'selected' : '' ?>>5 items or less</option>
                            <option value="10" <?= $config['low_stock_threshold'] == 10 ? 'selected' : '' ?>>10 items or less</option>
                        </select>
                        <div class="small text-secondary">Items hitting this threshold will show a yellow warning badge in your mapping screen.</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Sync Interval (Auto-sync cron)</label>
                        <select class="form-select mb-2" id="syncInterval" onchange="savePreferences()">
                            <option value="5" <?= $config['sync_interval_mins'] == 5 ? 'selected' : '' ?>>Every 5 minutes</option>
                            <option value="15" <?= $config['sync_interval_mins'] == 15 ? 'selected' : '' ?>>Every 15 minutes</option>
                            <option value="30" <?= $config['sync_interval_mins'] == 30 ? 'selected' : '' ?>>Every 30 minutes</option>
                            <option value="60" <?= $config['sync_interval_mins'] == 60 ? 'selected' : '' ?>>Every hour</option>
                        </select>
                    </div>

                    <div class="d-flex align-items-center justify-content-between mt-4">
                        <div>
                            <div class="fw-bold">Enable Auto Stock Sync</div>
                            <div class="small text-secondary mt-1">Push POS stock changes to Lazada automatically.</div>
                        </div>
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="enableSync" <?= $config['enable_stock_sync'] ? 'checked' : '' ?> onchange="savePreferences()" style="transform: scale(1.3); margin-right: 10px;">
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- RIGHT: Connection & Authorization -->
        <div class="col-lg-6">
            <div class="lz-card mb-4">
                <div class="lz-card-header">
                    <div class="fw-700" style="font-size:1.1rem;color:var(--text-primary)"><i class="fa-solid fa-plug text-lazada me-2"></i>Shop Authorization</div>
                </div>
                <div class="lz-card-body">
                    <?php if (!$isConfigured): ?>
                        <div class="lz-empty"><i class="fa-solid fa-plug d-block"></i><h5>No Credentials</h5><p>Save your App Key and Secret first.</p></div>
                    <?php elseif ($isAuthorized): ?>
                        <div class="d-flex align-items-center gap-3 p-3 rounded mb-3" style="background:var(--lz-neutral-bg);border:1px solid var(--lz-border-soft)">
                            <div class="lz-icon-box" style="background:var(--lazada-light);color:var(--lazada-primary)"><i class="fa-solid fa-shop"></i></div>
                            <div class="flex-grow-1">
                                <div class="fw-bold">Account: <?= htmlspecialchars($config['account_name'] ?? 'Authorized') ?></div>
                                <div class="small text-success"><i class="fa-solid fa-circle me-1" style="font-size:6px"></i>Authorized</div>
                            </div>
                            <span class="lz-badge lz-badge-<?= $config['environment'] === 'sandbox' ? 'info' : 'success' ?>"><?= strtoupper(htmlspecialchars($config['environment'])) ?></span>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-3">
                            <i class="fa-solid fa-shop-lock text-secondary mb-3 d-block" style="font-size:2.5rem;opacity:0.4"></i>
                            <h5 class="fw-bold">Authorize Your Shop</h5>
                            <p class="small text-secondary mb-3">You'll be redirected to Lazada to grant access. After authorizing, you'll be sent back here automatically.</p>
                            <button class="btn btn-lazada" onclick="authorizeShop()">
                                <i class="fa-solid fa-right-to-bracket me-2"></i>Authorize with Lazada (<?= strtoupper(htmlspecialchars($config['environment'])) ?>)
                            </button>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Token Info -->
            <?php if ($isAuthorized): ?>
            <div class="lz-card mb-4" id="tokenCard">
                <div class="lz-card-header d-flex justify-content-between align-items-center">
                    <div class="fw-700" style="font-size:1.1rem;color:var(--text-primary)"><i class="fa-solid fa-shield-halved text-lazada me-2"></i>Token Status</div>
                </div>
                <div class="lz-card-body">
                    <div class="d-flex justify-content-between mb-2 pb-2" style="border-bottom:1px solid var(--lz-border-soft)">
                        <span class="small text-secondary">Token Validity</span>
                        <span class="lz-badge lz-badge-<?= $tokenStatus === 'valid' ? 'success' : 'danger' ?>"><?= ucfirst($tokenStatus) ?></span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center mb-2 pb-2" style="border-bottom:1px solid var(--lz-border-soft)">
                        <span class="small text-secondary">Access Token</span>
                        <span class="lz-badge lz-badge-success">Active</span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center mb-2 pb-2" style="border-bottom:1px solid var(--lz-border-soft)">
                        <span class="small text-secondary">Expires</span>
                        <span class="small text-secondary d-inline-flex align-items-center gap-2" id="tokenExpiry">—</span>
                    </div>
                    <div class="d-flex justify-content-between mb-3">
                        <span class="small text-secondary">Environment</span>
                        <span class="lz-badge lz-badge-<?= $config['environment'] === 'sandbox' ? 'info' : 'success' ?>"><?= strtoupper(htmlspecialchars($config['environment'])) ?></span>
                    </div>
                </div>
            </div>
            
            <!-- Smart Sync -->
            <div class="lz-card mb-4" id="importCard">
                <div class="lz-card-header">
                    <div class="fw-700" style="font-size:1.1rem;color:var(--text-primary)"><i class="fa-solid fa-cloud-arrow-down text-lazada me-2"></i>Smart Sync Products</div>
                </div>
                <div class="lz-card-body">
                    <p class="small text-secondary mb-3">Fetch products and stock levels from your Lazada store.</p>
                    
                    <div id="importStatus" class="mb-3" style="display:none">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="fw-bold small" id="syncProgressLabel">Initializing Sync...</span>
                        </div>
                        <div class="progress mb-2" style="height: 6px;">
                            <div class="progress-bar progress-bar-striped progress-bar-animated bg-primary" id="syncProgressBar" role="progressbar" style="width: 100%"></div>
                        </div>
                        <div class="alert alert-info py-2 small mb-0" id="syncLogText"><i class="fa-solid fa-spinner fa-spin me-2"></i>Contacting Lazada API...</div>
                    </div>

                    <button class="btn btn-lazada w-100" onclick="startSmartSync()" id="btnImport">
                        <i class="fa-solid fa-play me-2"></i>Start Sync
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
        document.getElementById('tokenExpiry').textContent = '—';
        return;
    }
    
    function updateCountdown() {
        const now = Date.now();
        const diffMs = tokenExpiresTime - now;

        if (diffMs > 0) {
            const totalSecs = Math.floor(diffMs / 1000);
            const hours = Math.floor(totalSecs / 3600);
            const mins = Math.floor((totalSecs % 3600) / 60);
            const secs = totalSecs % 60;
            
            let durationStr = "";
            if (hours > 0) durationStr += `${hours}h `;
            durationStr += `${mins}m ${secs}s left`;
            document.getElementById('tokenExpiry').innerHTML = `
                <span class="text-dark countdown-pulse">${durationStr}</span>
            `;
        } else {
            document.getElementById('tokenExpiry').innerHTML = `<span class="text-danger">Expired</span>`;
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
        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-floppy-disk me-2"></i>Save Credentials'; }
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
            EllaToast.success('Preferences saved');
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
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-2"></i>Syncing...';
    status.style.display = 'block';
    
    progLbl.textContent = 'Fetching products from Lazada...';
    logText.className = 'alert alert-info py-2 small mb-0';
    logText.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-2"></i>Contacting Lazada API...';

    try {
        const res = await fetch(`${window.BASE_URL}api/lazada/fetch_products.php`);
        const data = await res.json();
        
        if (data.success) {
            progLbl.textContent = 'Sync Completed Successfully!';
            logText.className = 'alert alert-success py-2 small mb-0 mt-2';
            const newCount = data.stats ? data.stats.new : 0;
            const upCount = data.stats ? data.stats.updated : 0;
            logText.innerHTML = `<i class="fa-solid fa-check-circle me-2"></i><strong>Done!</strong><br>New: ${newCount} · Updated: ${upCount}`;
            EllaToast.success(data.message);
        } else {
            throw new Error(data.error || 'Unknown error occurred.');
        }
    } catch (e) {
        progLbl.textContent = 'Sync Failed';
        logText.className = 'alert alert-danger py-2 small mb-0 mt-2';
        logText.innerHTML = `<i class="fa-solid fa-xmark me-2"></i>${e.message}`;
        EllaToast.error('Sync error: ' + e.message);
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-play me-2"></i>Start Sync Again';
    }
}
</script>

<?php require_once '../../includes/footer.php'; ?>

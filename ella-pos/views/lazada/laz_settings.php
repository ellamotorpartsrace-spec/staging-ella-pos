<?php
// views/lazada/laz_settings.php — Lazada Sync Settings (Vertical Tab Interface)
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
$authShopId  = $_GET['shop_id'] ?? '';
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/lazada-sync.css?v=<?= filemtime(__DIR__ . '/../../assets/css/lazada-sync.css') ?>">
<style>
/* ── Unique Vertical Tab Interface for Lazada ── */
.lz-settings-wrapper {
    background: #f4f7fb;
    min-height: calc(100vh - 60px);
    padding-bottom: 3rem;
}
.lz-settings-hero {
    background: linear-gradient(135deg, #0f136d 0%, #1a237e 100%);
    padding: 2.5rem 2rem;
    border-radius: 0 0 24px 24px;
    box-shadow: 0 10px 30px rgba(15, 19, 109, 0.15);
    margin-bottom: 2rem;
    color: white;
}
.lz-breadcrumb-light a {
    color: rgba(255, 255, 255, 0.7);
    text-decoration: none;
    font-weight: 500;
    transition: color 0.2s;
}
.lz-breadcrumb-light a:hover {
    color: white;
    text-decoration: underline;
}

/* Sidebar Tabs */
.lz-sidebar-nav {
    background: #ffffff;
    border-radius: 16px;
    padding: 1rem;
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.03);
    position: sticky;
    top: 2rem;
}
.lz-tab-btn {
    display: flex;
    align-items: center;
    width: 100%;
    padding: 12px 18px;
    margin-bottom: 6px;
    border-radius: 12px;
    border: none;
    background: transparent;
    color: #64748b;
    font-weight: 600;
    font-size: 0.95rem;
    transition: all 0.2s ease;
}
.lz-tab-btn i {
    width: 24px;
    text-align: center;
    font-size: 1.1rem;
    margin-right: 12px;
    opacity: 0.7;
    transition: transform 0.2s;
}
.lz-tab-btn:hover {
    background: #f8fafc;
    color: #1e293b;
}
.lz-tab-btn:hover i {
    transform: scale(1.1);
}
.lz-tab-btn.active {
    background: rgba(15, 19, 109, 0.05);
    color: var(--lazada-primary);
}
.lz-tab-btn.active i {
    opacity: 1;
    color: var(--lazada-primary);
}
.lz-tab-btn.danger-tab:hover {
    background: #fef2f2;
    color: #ef4444;
}
.lz-tab-btn.danger-tab.active {
    background: #fef2f2;
    color: #ef4444;
}
.lz-tab-btn.danger-tab.active i {
    color: #ef4444;
}

/* Content Panes */
.lz-tab-pane {
    display: none;
    animation: slideUpFade 0.4s cubic-bezier(0.16, 1, 0.3, 1);
}
.lz-tab-pane.active {
    display: block;
}
@keyframes slideUpFade {
    from { opacity: 0; transform: translateY(15px); }
    to { opacity: 1; transform: translateY(0); }
}

.lz-content-card {
    background: #ffffff;
    border-radius: 16px;
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.03);
    padding: 2rem;
    margin-bottom: 1.5rem;
}
.lz-content-title {
    font-size: 1.4rem;
    font-weight: 800;
    color: #1e293b;
    margin-bottom: 0.5rem;
}
.lz-content-desc {
    color: #64748b;
    font-size: 0.95rem;
    margin-bottom: 2rem;
    padding-bottom: 1.5rem;
    border-bottom: 1px solid #f1f5f9;
}

/* Inputs & Forms */
.lz-form-label {
    font-weight: 700;
    font-size: 0.85rem;
    color: #334155;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 0.6rem;
}
.lz-input-modern {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 0.8rem 1.2rem;
    font-size: 1rem;
    color: #1e293b;
    transition: all 0.2s;
}
.lz-input-modern:focus {
    background: #ffffff;
    border-color: var(--lazada-primary);
    box-shadow: 0 0 0 4px rgba(15, 19, 109, 0.1);
}

/* Pill Toggle */
.lz-modern-toggle {
    display: inline-flex;
    background: #f1f5f9;
    padding: 4px;
    border-radius: 12px;
}
.lz-modern-toggle button {
    background: transparent;
    border: none;
    padding: 10px 24px;
    border-radius: 8px;
    font-weight: 600;
    color: #64748b;
    transition: all 0.2s;
}
.lz-modern-toggle button.active {
    background: #ffffff;
    color: var(--lazada-primary);
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}

.btn-lazada-solid {
    background: var(--lazada-primary);
    color: white;
    border: none;
    border-radius: 10px;
    padding: 0.8rem 1.5rem;
    font-weight: 700;
    font-size: 1rem;
    transition: all 0.2s;
}
.btn-lazada-solid:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 15px rgba(15, 19, 109, 0.2);
    color: white;
}

/* Status Banner */
.lz-status-banner {
    background: #ffffff;
    border-radius: 16px;
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.03);
    padding: 1.5rem 2rem;
    margin-bottom: 2rem;
    display: flex;
    align-items: center;
    gap: 1.5rem;
}
.lz-status-icon-wrapper {
    width: 54px; height: 54px;
    border-radius: 14px;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.6rem;
}

@keyframes countdownPulse {
    0% { opacity: 1; }
    50% { opacity: 0.75; }
    100% { opacity: 1; }
}
.countdown-pulse { animation: countdownPulse 1.5s infinite ease-in-out; }
</style>

<div class="lz-settings-wrapper lz-animate">
    <?php require_once __DIR__ . '/laz_token_warning.php'; ?>

    <!-- Hero Banner -->
    <div class="lz-settings-hero position-relative overflow-hidden">
        <!-- Decorative Background Graphic -->
        <i class="fa-solid fa-gears position-absolute text-white" style="font-size: 14rem; opacity: 0.04; right: -2%; top: -20%;"></i>
        
        <div class="container-fluid px-4 position-relative" style="z-index: 2;">
            <div class="lz-breadcrumb-light d-flex align-items-center gap-2 mb-3" style="font-size: 0.9rem;">
                <a href="<?= BASE_URL ?>views/lazada/laz_index.php">Lazada Dashboard</a>
                <i class="fa-solid fa-chevron-right" style="font-size: 0.6rem; opacity: 0.7;"></i>
                <span style="opacity: 0.9;">System Settings</span>
            </div>
            
            <div class="d-flex align-items-center gap-3">
                <div class="d-flex align-items-center justify-content-center bg-white shadow-sm" style="width: 60px; height: 60px; border-radius: 16px; font-size: 1.8rem; color: var(--lazada-primary);">
                    <i class="fa-solid fa-sliders"></i>
                </div>
                <div>
                    <h1 class="fw-bold mb-0 text-white" style="font-size: 2.2rem; letter-spacing: -0.5px;">
                        Configuration Panel
                    </h1>
                    <p class="mb-0 mt-1 text-white" style="opacity: 0.85; font-size: 1.05rem;">Manage your API connection, store credentials, and synchronization rules.</p>
                </div>
            </div>
        </div>
    </div>

    <div class="container-fluid px-4">
        
        <?php if ($authSuccess): ?>
        <div class="alert alert-success d-flex align-items-center gap-3 mb-4 shadow-sm" style="border-radius: 12px; border: none;">
            <i class="fa-solid fa-circle-check fs-4"></i>
            <div><strong>Shop authorized successfully!</strong> Shop ID: <?= htmlspecialchars($authShopId) ?> — You can now import products.</div>
        </div>
        <?php endif; ?>

        <!-- Global Status Banner -->
        <div id="statusBanner" class="lz-status-banner" style="display:none">
            <div id="statusIcon" class="lz-status-icon-wrapper bg-success text-white">
                <i class="fa-solid fa-check"></i>
            </div>
            <div class="flex-grow-1">
                <h4 class="fw-bold mb-1" id="statusTitle" style="color: #1e293b;">Connected & Active</h4>
                <div style="font-size: 0.95rem; color: #64748b;" id="statusSub">System is fully operational.</div>
            </div>
            <span class="badge bg-success" style="font-size: 0.85rem; padding: 6px 12px; border-radius: 8px;" id="statusBadge">Active</span>
        </div>

        <div class="row g-4">
            <!-- Left Navigation Sidebar -->
            <div class="col-lg-3">
                <div class="lz-sidebar-nav">
                    <button class="lz-tab-btn active" onclick="switchTab('tab-api', this)">
                        <i class="fa-solid fa-key"></i> API Access
                    </button>
                    <button class="lz-tab-btn" onclick="switchTab('tab-auth', this)">
                        <i class="fa-solid fa-plug-circle-check"></i> Shop Authorization
                    </button>
                    <button class="lz-tab-btn" onclick="switchTab('tab-safety', this)">
                        <i class="fa-solid fa-shield-halved"></i> Safety Rules
                    </button>
                    <button class="lz-tab-btn" onclick="switchTab('tab-sync', this)">
                        <i class="fa-solid fa-bolt"></i> Sync Engine
                    </button>
                    <hr class="my-2" style="opacity: 0.1;">
                    <button class="lz-tab-btn danger-tab" onclick="switchTab('tab-danger', this)">
                        <i class="fa-solid fa-skull"></i> Danger Zone
                    </button>
                </div>
            </div>

            <!-- Right Content Area -->
            <div class="col-lg-9">
                
                <!-- TAB 1: API ACCESS -->
                <div class="lz-tab-pane active" id="tab-api">
                    <div class="lz-content-card">
                        <h2 class="lz-content-title">API Credentials</h2>
                        <div class="lz-content-desc">Enter your Lazada Open Platform details to allow Ella POS to securely connect to your seller account.</div>

                        <?php if ($isAdmin): ?>
                        <div class="row g-4">
                            <div class="col-12">
                                <label class="lz-form-label">Operating Environment</label><br>
                                <div class="lz-modern-toggle">
                                    <button class="active" id="envTest" onclick="setEnv('test')">Sandbox (Test)</button>
                                    <button id="envLive" onclick="setEnv('live')">Production (Live)</button>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <label class="lz-form-label">Partner ID / App Key</label>
                                <input type="text" class="form-control lz-input-modern" id="partnerId" placeholder="e.g. 1234567">
                            </div>

                            <div class="col-md-6">
                                <label class="lz-form-label">Shop Region</label>
                                <select class="form-select lz-input-modern" id="shopRegion">
                                    <option value="PH" selected>Philippines (PH)</option>
                                    <option value="SG">Singapore (SG)</option>
                                    <option value="MY">Malaysia (MY)</option>
                                    <option value="TH">Thailand (TH)</option>
                                    <option value="ID">Indonesia (ID)</option>
                                    <option value="VN">Vietnam (VN)</option>
                                </select>
                            </div>

                            <div class="col-12">
                                <label class="lz-form-label">Partner Key (App Secret)</label>
                                <div class="input-group">
                                    <input type="password" class="form-control lz-input-modern" id="partnerKey" placeholder="••••••••••••••••••••••" style="border-right: none;">
                                    <button class="btn border-top border-bottom border-end" style="border-color: #e2e8f0; background: #f8fafc; color: #64748b; border-radius: 0 10px 10px 0; padding: 0 1.2rem;" onclick="toggleKeyVisibility()">
                                        <i class="fa-solid fa-eye" id="keyEyeIcon"></i>
                                    </button>
                                </div>
                            </div>

                            <div class="col-12 mt-4 pt-3 border-top">
                                <button class="btn-lazada-solid" onclick="saveCredentials()" id="btnSave">
                                    <i class="fa-solid fa-floppy-disk me-2"></i>Save Configuration
                                </button>
                            </div>
                        </div>
                        <?php else: ?>
                        <div class="text-center py-5">
                            <i class="fa-solid fa-lock text-secondary mb-3" style="font-size: 3rem; opacity: 0.3;"></i>
                            <h4 class="fw-bold">Access Restricted</h4>
                            <p class="text-secondary">Only administrators have permission to view or modify credentials.</p>
                        </div>
                        <div style="display: none;">
                            <button id="envTest"></button><button id="envLive"></button>
                            <input id="partnerId"><input id="partnerKey"><select id="shopRegion"><option value="PH"></option></select>
                            <button id="btnSave"></button>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- TAB 2: SHOP AUTHORIZATION -->
                <div class="lz-tab-pane" id="tab-auth">
                    <div class="lz-content-card">
                        <h2 class="lz-content-title">Store Authorization</h2>
                        <div class="lz-content-desc">Authorize this application to access your specific Lazada seller store.</div>
                        
                        <div id="authSection" class="mb-5">
                            <!-- Dynamic Content -->
                        </div>

                        <div id="tokenCard" style="display:none">
                            <h5 class="fw-bold mb-3 border-top pt-4">Access Token Status</h5>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <div class="p-3 bg-light rounded" style="border: 1px solid #e2e8f0;">
                                        <div class="small text-secondary fw-bold text-uppercase mb-1">Expiration Time</div>
                                        <div class="fs-5 fw-bold" id="tokenExpiry" style="color: #1e293b;">—</div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="p-3 bg-light rounded d-flex align-items-center justify-content-between" style="border: 1px solid #e2e8f0; height: 100%;">
                                        <div>
                                            <div class="small text-secondary fw-bold text-uppercase mb-1">Token State</div>
                                            <div class="fw-bold text-success" id="tokenActiveStatus">Active</div>
                                        </div>
                                        <button class="btn btn-sm btn-outline-secondary" onclick="refreshTokens(this)">
                                            <i class="fa-solid fa-rotate me-1"></i> Force Refresh
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <input type="hidden" id="tokenValue">
                        </div>
                    </div>
                </div>

                <!-- TAB 3: SAFETY RULES -->
                <div class="lz-tab-pane" id="tab-safety">
                    <div class="lz-content-card">
                        <h2 class="lz-content-title">Inventory Safety Rules</h2>
                        <div class="lz-content-desc">Configure how the POS manages your online inventory to prevent overselling and protect your physical store stock.</div>

                        <div class="row g-4">
                            <div class="col-md-6">
                                <label class="lz-form-label">Low Stock Warning Threshold</label>
                                <select class="form-select lz-input-modern mb-1" id="lowStockThreshold" onchange="saveCredentials()">
                                    <option value="1">1 item or less</option>
                                    <option value="2">2 items or less</option>
                                    <option value="3">3 items or less</option>
                                    <option value="5" selected>5 items or less</option>
                                    <option value="10">10 items or less</option>
                                </select>
                                <div class="small text-muted">Triggers yellow warning badges in the Allocation screen.</div>
                            </div>

                            <div class="col-md-6">
                                <label class="lz-form-label">Buffer / Ghost Stock</label>
                                <select class="form-select lz-input-modern mb-1" id="bufferStock" onchange="saveCredentials()">
                                    <option value="0" selected>0 (No Buffer - Sync Exact Amount)</option>
                                    <option value="1">1 item hidden</option>
                                    <option value="2">2 items hidden</option>
                                    <option value="3">3 items hidden</option>
                                    <option value="5">5 items hidden</option>
                                </select>
                                <div class="small text-muted">Permanently hides this quantity from Lazada to prevent walk-in stockouts.</div>
                            </div>

                            <div class="col-12 mt-4">
                                <div class="p-4 rounded d-flex justify-content-between align-items-center" style="background: #f8fafc; border: 1px solid #e2e8f0;">
                                    <div>
                                        <h6 class="fw-bold mb-1">Out-of-Stock Popups</h6>
                                        <p class="small text-muted mb-0">Show an immediate popup alert on the POS screen whenever a Lazada item hits zero stock.</p>
                                    </div>
                                    <div class="form-check form-switch" style="font-size: 1.5rem;">
                                        <input class="form-check-input" type="checkbox" id="oosAlerts" checked onchange="saveCredentials()" style="cursor:pointer">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- TAB 4: SYNC ENGINE -->
                <div class="lz-tab-pane" id="tab-sync">
                    <div class="lz-content-card mb-4" id="importCard">
                        <h2 class="lz-content-title">Smart Sync Products</h2>
                        <div class="lz-content-desc">Manually trigger synchronization batches between Lazada and your local ERP.</div>
                        
                        <div class="mb-4">
                            <label class="lz-form-label">Select Sync Mode</label>
                            <select class="form-select lz-input-modern mb-2" id="syncMode">
                                <option value="quick" selected>⚡ Quick Sync (Only changed/new products)</option>
                                <option value="full">🔄 Full Sync (Re-fetch all products & variations)</option>
                                <option value="stock">📦 Stock Sync Only (Fast stock updates)</option>
                                <option value="price">💰 Price Sync Only (Fast price updates)</option>
                            </select>
                            <div class="p-3 bg-light rounded text-secondary small" id="syncModeDesc">Fastest. Only fetches products updated on Lazada since your last sync.</div>
                        </div>

                        <div id="importStatus" class="mb-4 p-3 border rounded" style="display:none; background: #f8fafc;">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <strong id="syncProgressLabel" class="text-primary">Initializing Sync...</strong>
                                <span class="badge bg-secondary" id="syncProgressCount">0 / 0</span>
                            </div>
                            <div class="progress mb-2" style="height: 8px; border-radius: 4px;">
                                <div class="progress-bar progress-bar-striped progress-bar-animated bg-primary" id="syncProgressBar" style="width: 0%;"></div>
                            </div>
                            <div class="small fw-semibold" id="syncLogText" style="color: #64748b;">Starting queue...</div>
                        </div>

                        <button class="btn-lazada-solid w-100" onclick="startSmartSync()" id="btnImport">
                            <i class="fa-solid fa-play me-2"></i>Execute Manual Sync
                        </button>
                    </div>

                    <div class="lz-content-card">
                        <h2 class="lz-content-title">Ghost Product Cleanup</h2>
                        <div class="lz-content-desc mb-3">Detect and completely remove any products or variations from your POS that were deleted directly inside the Lazada Seller Centre.</div>
                        
                        <div id="cleanupStatus" class="mb-3 p-3 border rounded" style="display:none; background: #f8fafc;">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <strong id="cleanupProgressLabel" class="text-primary">Initializing...</strong>
                            </div>
                            <div class="progress mb-2" style="height: 8px; border-radius: 4px;">
                                <div class="progress-bar progress-bar-striped progress-bar-animated bg-primary" id="cleanupProgressBar" style="width: 0%;"></div>
                            </div>
                            <div class="small fw-semibold mb-2" id="cleanupLogText" style="color: #64748b;">Starting cleanup...</div>
                            
                            <div class="row g-2 text-center mt-2" id="cleanupDetails" style="display:none;">
                                <div class="col-4 border-end">
                                    <div class="small text-muted text-uppercase fw-bold">Checked</div>
                                    <div class="fs-5 fw-bold" id="cTotalChecked">0</div>
                                </div>
                                <div class="col-4 border-end">
                                    <div class="small text-muted text-uppercase fw-bold">Items</div>
                                    <div class="fs-5 fw-bold text-danger" id="cGhostItems">0</div>
                                </div>
                                <div class="col-4">
                                    <div class="small text-muted text-uppercase fw-bold">Vars</div>
                                    <div class="fs-5 fw-bold text-danger" id="cGhostVars">0</div>
                                </div>
                            </div>
                        </div>

                        <button class="btn btn-outline-danger w-100 fw-bold py-2" style="border-radius: 10px;" onclick="startCleanupSync()" id="btnCleanup">
                            <i class="fa-solid fa-broom me-2"></i>Run Ghost Cleanup
                        </button>
                    </div>
                </div>

                <!-- TAB 5: DANGER ZONE -->
                <div class="lz-tab-pane" id="tab-danger">
                    <div class="lz-content-card" style="border: 1px solid #fecaca; background: #fff5f5;">
                        <h2 class="lz-content-title text-danger"><i class="fa-solid fa-skull me-2"></i>Danger Zone</h2>
                        <div class="lz-content-desc text-danger opacity-75">Actions here can cause permanent data loss. Proceed with extreme caution.</div>
                        
                        <div class="text-center py-4">
                            <div class="mb-3">
                                <i class="fa-solid fa-lock text-danger opacity-50" style="font-size: 3rem;"></i>
                            </div>
                            <h5 class="fw-bold text-danger">Wipe Action Prohibited</h5>
                            <p class="text-danger opacity-75 mb-0">The ability to completely wipe and reset the integration data has been permanently disabled by the system administrator to prevent accidental data loss. Please contact support if you need to factory reset the Lazada module.</p>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>
</div>

<script>
// Tab Switching Logic
function switchTab(tabId, btnElement) {
    document.querySelectorAll('.lz-tab-btn').forEach(btn => btn.classList.remove('active'));
    btnElement.classList.add('active');
    
    document.querySelectorAll('.lz-tab-pane').forEach(pane => pane.classList.remove('active'));
    document.getElementById(tabId).classList.add('active');
}

// Global Variables
let currentEnv = 'test';
let expiryTimer = null;
let tokenExpiresTime = null;
let rawTokenExpiresStr = "";
let isAutoRefreshing = false;

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
            // Automatically refresh if there are 15 minutes or less remaining (15 * 60 * 1000 = 900000ms)
            if (diffMs <= 15 * 60 * 1000 && !isAutoRefreshing) {
                isAutoRefreshing = true;
                refreshTokens(null, true);
            }

            const totalSecs = Math.floor(diffMs / 1000);
            const hours = Math.floor(totalSecs / 3600);
            const mins = Math.floor((totalSecs % 3600) / 60);
            const secs = totalSecs % 60;
            
            let durationStr = "";
            if (hours > 0) {
                durationStr += `${hours}h `;
            }
            durationStr += `${mins}m ${secs}s left`;
            document.getElementById('tokenExpiry').innerHTML = `
                <span class="countdown-pulse text-primary">${durationStr}</span>
            `;
        } else {
            document.getElementById('tokenExpiry').innerHTML = `
                <span class="text-danger fw-bold">Expired</span>
            `;
            clearInterval(expiryTimer);
            expiryTimer = null;
        }
    }
    
    updateCountdown(); // Run immediately
    expiryTimer = setInterval(updateCountdown, 1000);
}

function setEnv(env) {
    currentEnv = env;
    document.getElementById('envTest').classList.toggle('active', env === 'test');
    document.getElementById('envLive').classList.toggle('active', env === 'live');
}

function toggleKeyVisibility() {
    const inp = document.getElementById('partnerKey');
    const icon = document.getElementById('keyEyeIcon');
    if (inp.type === 'password') { inp.type = 'text'; icon.className = 'fa-solid fa-eye-slash'; }
    else { inp.type = 'password'; icon.className = 'fa-solid fa-eye'; }
}

// ── Save Credentials ──

async function saveCredentials() {
    const partnerId  = document.getElementById('partnerId').value.trim();
    const partnerKey = document.getElementById('partnerKey').value.trim();
    const shopRegion = document.getElementById('shopRegion').value;
    const lowStockThreshold = document.getElementById('lowStockThreshold') ? document.getElementById('lowStockThreshold').value : 5;
    const oosAlerts = document.getElementById('oosAlerts') ? (document.getElementById('oosAlerts').checked ? 1 : 0) : 1;
    const bufferStock = document.getElementById('bufferStock') ? document.getElementById('bufferStock').value : 0;

    if (!partnerId) {
        EllaToast.error('Partner ID is required');
        return;
    }

    const btn = document.getElementById('btnSave');
    if(btn) {
        btn.disabled = true; 
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-2"></i>Saving...';
    }

    try {
        const res = await fetch(`${window.BASE_URL}api/lazada/save_config.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ 
                environment: currentEnv, 
                partner_id: partnerId, 
                partner_key: partnerKey, 
                shop_region: shopRegion,
                low_stock_threshold: lowStockThreshold,
                out_of_stock_alerts: oosAlerts,
                buffer_stock: bufferStock
            })
        });
        const data = await res.json();
        if (data.success) {
            EllaToast.success(data.message);
            loadStatus();
        } else {
            EllaToast.error(data.error);
        }
    } catch (e) {
        EllaToast.error('Network error: ' + e.message);
    } finally {
        if(btn) {
            btn.disabled = false; 
            btn.innerHTML = '<i class="fa-solid fa-floppy-disk me-2"></i>Save Configuration';
        }
    }
}

// ── Authorize Shop ──
async function authorizeShop() {
    try {
        const res = await fetch(`${window.BASE_URL}api/lazada/auth.php`);
        const data = await res.json();
        if (data.success) {
            EllaToast.success('Redirecting to Lazada authorization...');
            window.location.href = data.auth_url;
        } else {
            EllaToast.error(data.error);
        }
    } catch (e) {
        EllaToast.error('Network error: ' + e.message);
    }
}

// ── Smart Sync System ──
document.getElementById('syncMode')?.addEventListener('change', function() {
    const descs = {
        'quick': 'Fastest. Only fetches products updated on Lazada since your last sync.',
        'full': 'Slowest. Deeply re-fetches every product, variation, image, and price from Lazada.',
        'stock': 'Fast. Only updates stock levels from Lazada, ignoring name/image changes.',
        'price': 'Fast. Only updates price changes from Lazada.',
        'mapping': 'Instant. Runs auto-matching algorithms against existing unmatched products.'
    };
    document.getElementById('syncModeDesc').textContent = descs[this.value];
});

async function startSmartSync() {
    const btn = document.getElementById('btnImport');
    const status = document.getElementById('importStatus');
    const mode = document.getElementById('syncMode').value;
    const logText = document.getElementById('syncLogText');
    const progBar = document.getElementById('syncProgressBar');
    const progLbl = document.getElementById('syncProgressLabel');
    const progCnt = document.getElementById('syncProgressCount');

    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-2"></i>Sync in progress...';
    status.style.display = 'block';
    
    // Reset UI
    progBar.className = 'progress-bar progress-bar-striped progress-bar-animated bg-primary';
    progBar.style.width = '0%'; 
    progLbl.textContent = 'Initializing Queue...';
    progCnt.textContent = '0 / ?';
    logText.className = 'small fw-semibold text-primary';
    logText.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-2"></i>Contacting Lazada API...';

    // --- Standard Smart Sync Implementation ---
    let totalItems = 0, totalRows = 0, totalInserted = 0, totalUpdated = 0, totalMatched = 0;
    let offset = 0, hasNextPage = true, queueId = null;

    try {
        const initRes = await fetch(`${window.BASE_URL}api/lazada/sync_init.php`, {
            method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({mode})
        });
        const initData = await initRes.json();
        if (!initData.success) throw new Error(initData.error);
        queueId = initData.queue_id;

        let chunksProcessed = 0;
        while (hasNextPage) {
            logText.innerHTML = `<i class="fa-solid fa-spinner fa-spin me-2"></i>Processing batch ${chunksProcessed + 1} (Offset: ${offset})...`;
            
            const res = await fetch(`${window.BASE_URL}api/lazada/fetch_products.php?offset=${offset}&mode=${mode}&queue_id=${queueId}`);
            const data = await res.json();
            
            if (data.success) {
                totalItems += data.total_items || 0;
                totalRows += data.total_rows || 0;
                totalInserted += data.inserted || 0;
                totalUpdated += data.updated || 0;
                totalMatched += data.auto_matched || 0;
                
                hasNextPage = data.has_next_page;
                offset = data.next_offset;
                chunksProcessed++;

                progCnt.textContent = `${offset} processed`;
                progBar.style.width = hasNextPage ? Math.min(100, (chunksProcessed * 15)) + '%' : '100%';
            } else {
                throw new Error(data.error);
            }
        }
        
        await fetch(`${window.BASE_URL}api/lazada/sync_complete.php`, {
            method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({queue_id: queueId, status: 'completed'})
        });
        
        progBar.className = 'progress-bar bg-success';
        progLbl.textContent = 'Sync Completed Successfully!';
        logText.className = 'small fw-bold text-success mt-2 d-block';
        logText.innerHTML = `<i class="fa-solid fa-check-circle me-2"></i>New: ${totalInserted} · Updated: ${totalUpdated}`;
        EllaToast.success(`Smart Sync (${mode}) completed successfully!`);
        
    } catch (e) {
        if (queueId) {
            fetch(`${window.BASE_URL}api/lazada/sync_complete.php`, {
                method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({queue_id: queueId, status: 'failed', error: e.message})
            });
        }
        progBar.className = 'progress-bar bg-danger';
        progLbl.textContent = 'Sync Failed';
        logText.className = 'small fw-bold text-danger mt-2 d-block';
        logText.innerHTML = `<i class="fa-solid fa-xmark me-2"></i>${e.message}`;
        EllaToast.error('Sync error: ' + e.message);
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-play me-2"></i>Execute Manual Sync';
    }
}

// ── Load Current Status ──
async function loadStatus() {
    try {
        const res = await fetch(`${window.BASE_URL}api/lazada/get_config.php`);
        const data = await res.json();

        const banner = document.getElementById('statusBanner');
        const authSection = document.getElementById('authSection');
        const tokenCard = document.getElementById('tokenCard');
        const importCard = document.getElementById('importCard');

        if (!data.success || !data.configured) {
            banner.style.display = 'flex';
            document.getElementById('statusIcon').className = 'lz-status-icon-wrapper bg-warning text-white';
            document.getElementById('statusIcon').innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i>';
            document.getElementById('statusTitle').textContent = 'Configuration Required';
            document.getElementById('statusSub').textContent = 'Please enter your API credentials in the "API Access" tab.';
            document.getElementById('statusBadge').className = 'badge bg-warning text-dark';
            document.getElementById('statusBadge').textContent = 'Setup Needed';

            authSection.innerHTML = '<div class="text-center py-5 border rounded bg-light text-secondary"><i class="fa-solid fa-plug d-block mb-3" style="font-size:3rem;opacity:0.2"></i><h5 class="fw-bold">No Credentials</h5><p class="mb-0">Save your Partner ID and Key in the API tab first.</p></div>';
            return;
        }

        // Configured
        banner.style.display = 'flex';
        document.getElementById('partnerId').value = data.partner_id;
        if (data.partner_key) {
            document.getElementById('partnerKey').value = data.partner_key;
        }
        if (data.shop_region) {
            document.getElementById('shopRegion').value = data.shop_region;
        }
        if (document.getElementById('lowStockThreshold')) {
            document.getElementById('lowStockThreshold').value = data.low_stock_threshold || 5;
        }
        if (document.getElementById('oosAlerts')) {
            document.getElementById('oosAlerts').checked = data.out_of_stock_alerts == 1;
        }
        if (document.getElementById('bufferStock')) {
            document.getElementById('bufferStock').value = data.buffer_stock || 0;
        }
        setEnv(data.environment);

        if (data.authorized) {
            document.getElementById('statusIcon').className = 'lz-status-icon-wrapper bg-success text-white';
            document.getElementById('statusIcon').innerHTML = '<i class="fa-solid fa-circle-check"></i>';
            document.getElementById('statusTitle').textContent = 'Store is Connected';
            document.getElementById('statusSub').textContent = `Shop ID: ${data.shop_id} — Operating in ${data.environment.toUpperCase()} mode.`;
            document.getElementById('statusBadge').className = 'badge bg-success';
            document.getElementById('statusBadge').innerHTML = 'Operational';

            authSection.innerHTML = `
                <div class="p-4 rounded d-flex align-items-center gap-4" style="background: #f8fafc; border: 1px solid #e2e8f0;">
                    <div style="width: 60px; height: 60px; border-radius: 14px; background: white; display: flex; align-items: center; justify-content: center; font-size: 1.8rem; color: var(--lazada-primary); box-shadow: 0 4px 10px rgba(0,0,0,0.05); border: 1px solid #e2e8f0;">
                        <i class="fa-solid fa-shop"></i>
                    </div>
                    <div class="flex-grow-1">
                        <div class="text-uppercase small fw-bold text-secondary mb-1">Active Store ID</div>
                        <h3 class="fw-bold mb-0" style="color: #1e293b;">${data.shop_id}</h3>
                    </div>
                    <div class="text-end">
                        <span class="badge bg-success px-3 py-2" style="font-size: 0.9rem; border-radius: 8px;"><i class="fa-solid fa-check me-2"></i>Authorized</span>
                    </div>
                </div>
                <div class="row g-3 mt-1">
                    <div class="col-md-4">
                        <div class="p-3 border rounded text-center">
                            <div class="small text-secondary fw-bold">Total Products</div>
                            <div class="fs-4 fw-bold mt-1">${data.products_total}</div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="p-3 border rounded text-center">
                            <div class="small text-secondary fw-bold">Mapped</div>
                            <div class="fs-4 fw-bold text-success mt-1">${data.products_mapped}</div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="p-3 border rounded text-center">
                            <div class="small text-secondary fw-bold">Unmapped</div>
                            <div class="fs-4 fw-bold text-warning mt-1">${data.products_unmapped}</div>
                        </div>
                    </div>
                </div>
            `;

            tokenCard.style.display = 'block';
            
            const tokenVal = data.access_token || '';
            document.getElementById('tokenValue').value = tokenVal;
            
            const activeStatusEl = document.getElementById('tokenActiveStatus');
            
            if (data.token_status === 'valid' && tokenVal) {
                activeStatusEl.className = 'fw-bold text-success';
                activeStatusEl.textContent = 'Active & Valid';
            } else {
                activeStatusEl.className = 'fw-bold text-danger';
                activeStatusEl.textContent = 'Expired / Invalid';
            }

            if (data.token_expires) {
                rawTokenExpiresStr = data.token_expires;
                tokenExpiresTime = new Date(data.token_expires.replace(/-/g, "/")).getTime();
                startExpiryCountdown();
            } else {
                tokenExpiresTime = null;
                rawTokenExpiresStr = "";
                startExpiryCountdown();
            }

        } else {
            document.getElementById('statusIcon').className = 'lz-status-icon-wrapper bg-info text-white';
            document.getElementById('statusIcon').innerHTML = '<i class="fa-solid fa-key"></i>';
            document.getElementById('statusTitle').textContent = 'Awaiting Authorization';
            document.getElementById('statusSub').textContent = 'Please link your store using the "Shop Authorization" tab.';
            document.getElementById('statusBadge').className = 'badge bg-info text-dark';
            document.getElementById('statusBadge').textContent = 'Pending Auth';

            authSection.innerHTML = `
                <div class="text-center py-5 border rounded" style="background: #f8fafc;">
                    <i class="fa-solid fa-shop-lock mb-4 d-block" style="font-size:4rem; color: #cbd5e1;"></i>
                    <h4 class="fw-bold" style="color: #1e293b;">Link Your Store</h4>
                    <p class="text-secondary mb-4 mx-auto" style="max-width: 400px;">Click the button below to log in to Lazada Seller Centre and securely grant this POS application access to your store's inventory.</p>
                    <button class="btn-lazada-solid px-5" onclick="authorizeShop()">
                        <i class="fa-solid fa-right-to-bracket me-2"></i>Authorize (${data.environment.toUpperCase()})
                    </button>
                </div>
            `;
            
            if(document.getElementById('importCard')) document.getElementById('importCard').style.display = 'none';
        }

    } catch (e) {
        console.error('Failed to load Lazada config:', e);
    }
}

async function refreshTokens(btnElement = null, isAuto = false) {
    const btn = btnElement || (typeof event !== 'undefined' && event ? event.currentTarget : null);
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>';
    }

    try {
        if (isAuto) EllaToast.warning('Auto-refreshing Lazada Access Token...');

        const res = await fetch(`${window.BASE_URL}api/lazada/refresh_token.php`);
        const data = await res.json();
        
        if (data.success) {
            if (isAuto) EllaToast.success('Token automatically refreshed!');
            else EllaToast.success(data.message);
            isAutoRefreshing = false;
            loadStatus();
        } else {
            EllaToast.error(data.error || 'Failed to refresh tokens');
            if (isAuto) setTimeout(() => { isAutoRefreshing = false; }, 120000);
        }
    } catch (e) {
        EllaToast.error('Network error: ' + e.message);
        if (isAuto) setTimeout(() => { isAutoRefreshing = false; }, 120000);
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-rotate me-1"></i> Force Refresh';
        }
    }
}

async function startCleanupSync() {
    if (!confirm('Are you sure you want to run the Ghost Product Cleanup? This will permanently delete any products and variations from the POS that have been removed from your Lazada Seller Centre.')) {
        return;
    }
    
    const btn = document.getElementById('btnCleanup');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-2"></i>Initializing...';
    
    const status = document.getElementById('cleanupStatus');
    const logText = document.getElementById('cleanupLogText');
    const progBar = document.getElementById('cleanupProgressBar');
    const progLbl = document.getElementById('cleanupProgressLabel');
    const detailsDiv = document.getElementById('cleanupDetails');
    
    status.style.display = 'block';
    if(detailsDiv) detailsDiv.style.display = 'none';
    
    progBar.className = 'progress-bar progress-bar-striped progress-bar-animated bg-primary';
    progBar.style.width = '20%';
    progLbl.textContent = 'Connecting...';
    logText.className = 'small fw-semibold text-primary mb-2 d-block';
    logText.innerHTML = '<i class="fa-solid fa-satellite-dish me-2"></i>Establishing secure connection...';
    
    await new Promise(r => setTimeout(r, 800));
    
    progBar.style.width = '50%';
    progLbl.textContent = 'Fetching Data...';
    logText.innerHTML = '<i class="fa-solid fa-cloud-arrow-down me-2"></i>Downloading active product lists...';
    
    await new Promise(r => setTimeout(r, 1200));

    progBar.style.width = '80%';
    progLbl.textContent = 'Cross-referencing...';
    logText.innerHTML = '<i class="fa-solid fa-magnifying-glass me-2"></i>Comparing local database against live data...';
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-2"></i>Cleaning up...';

    try {
        const res = await fetch(`${window.BASE_URL}api/lazada/cleanup_deleted.php`);
        const data = await res.json();
        if (data.success) {
            progBar.className = 'progress-bar bg-success';
            progBar.style.width = '100%';
            progLbl.textContent = 'Cleanup Complete!';
            logText.className = 'small fw-bold text-success mb-2 d-block';
            logText.innerHTML = `<i class="fa-solid fa-check-circle me-2"></i>${data.message}`;
            
            if (detailsDiv && data.details) {
                document.getElementById('cTotalChecked').textContent = data.details.totalChecked;
                document.getElementById('cGhostItems').textContent = data.details.deletedItems;
                document.getElementById('cGhostVars').textContent = data.details.deletedVariations;
                detailsDiv.style.display = 'flex';
            }
            if(typeof EllaToast !== 'undefined') EllaToast.success(data.message);
        } else {
            progBar.className = 'progress-bar bg-danger';
            progBar.style.width = '100%';
            progLbl.textContent = 'Cleanup Failed';
            logText.className = 'small fw-bold text-danger mb-2 d-block';
            logText.innerHTML = `<i class="fa-solid fa-circle-xmark me-2"></i>${data.error}`;
            if(typeof EllaToast !== 'undefined') EllaToast.error(data.error || 'Cleanup failed');
        }
    } catch (e) {
        progBar.className = 'progress-bar bg-danger';
        progBar.style.width = '100%';
        progLbl.textContent = 'Network Error';
        logText.className = 'small fw-bold text-danger mb-2 d-block';
        logText.innerHTML = `<i class="fa-solid fa-circle-xmark me-2"></i>${e.message}`;
        if(typeof EllaToast !== 'undefined') EllaToast.error('Network error: ' + e.message);
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-broom me-2"></i>Run Ghost Cleanup';
        }
    }
}

document.addEventListener('DOMContentLoaded', loadStatus);
</script>

<script src="../../views/lazada/laz_alerts.js"></script>
<?php require_once '../../includes/footer.php'; ?>

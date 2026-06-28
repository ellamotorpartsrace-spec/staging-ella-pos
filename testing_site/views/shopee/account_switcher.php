<?php
// testing_site/views/shopee/account_switcher.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['shopee_active_platform'])) {
    $_SESSION['shopee_active_platform'] = 'shopee_main';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['switch_shopee_account'])) {
    $_SESSION['shopee_active_platform'] = $_POST['switch_shopee_account'];
    // Prevent resubmission
    header("Location: " . $_SERVER['REQUEST_URI']);
    exit;
}

$dbSwitcher = new Database();
$connSwitcher = $dbSwitcher->getConnection();
$platformsStmt = $connSwitcher->query("SELECT platform_name, account_label FROM api_platforms WHERE platform_name LIKE 'shopee%'");
$shopee_platforms = $platformsStmt->fetchAll(PDO::FETCH_ASSOC);

$activePlatform = $_SESSION['shopee_active_platform'];
?>
<div class="d-flex justify-content-end mb-3 mt-n2 position-relative" style="z-index: 10;">
    <form method="POST" class="d-flex align-items-center gap-2 m-0 bg-white p-2 rounded-pill shadow-sm border" style="border-color: rgba(238,77,45,0.2) !important;">
        <label class="small fw-bold text-secondary mb-0 ps-2" style="white-space:nowrap"><i class="fa-solid fa-shop me-1 text-shopee"></i>Active Shop:</label>
        <select name="switch_shopee_account" class="form-select form-select-sm fw-bold border-0 shadow-none text-shopee" style="width: auto; background-color: var(--shopee-light); cursor:pointer; border-radius: 20px;" onchange="this.form.submit()">
            <?php foreach ($shopee_platforms as $p): ?>
                <option value="<?= $p['platform_name'] ?>" <?= $p['platform_name'] === $activePlatform ? 'selected' : '' ?>>
                    <?= htmlspecialchars($p['account_label'] ?: $p['platform_name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </form>
</div>

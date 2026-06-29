<?php
/**
 * views/lazada/account_switcher.php - Switch between Lazada stores
 */
require_once '../../config/config.php';
require_once '../../config/database.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$db = new Database();
$conn = $db->getConnection();

// Fetch all active Lazada platforms
$stmt = $conn->query("SELECT platform_name, account_name FROM lazada_config WHERE is_active = 1 ORDER BY id ASC");
$platforms = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($platforms)) {
    $platforms = [['platform_name' => 'lazada_main', 'account_name' => 'Main Account']];
}

$active_platform = $_SESSION['lazada_active_platform'] ?? $platforms[0]['platform_name'];

// Handle switch request via AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'switch_platform') {
    $target = $_POST['platform_name'];
    // Validate target
    $valid = false;
    foreach ($platforms as $p) {
        if ($p['platform_name'] === $target) {
            $valid = true;
            break;
        }
    }
    if ($valid) {
        $_SESSION['lazada_active_platform'] = $target;
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Invalid platform']);
    }
    exit;
}
?>

<div class="account-switcher-container" style="display: flex; align-items: center; gap: 10px; background: rgba(255, 255, 255, 0.1); padding: 5px 15px; border-radius: 20px; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">
    <i class="fas fa-store" style="color: #f36f21;"></i>
    <select id="lazadaPlatformSwitcher" class="form-select form-select-sm" style="background: transparent; border: none; color: white; font-weight: 600; cursor: pointer;">
        <?php foreach ($platforms as $p): ?>
            <?php 
                $label = !empty($p['account_name']) ? $p['account_name'] : ucwords(str_replace('_', ' ', $p['platform_name'])); 
            ?>
            <option value="<?= htmlspecialchars($p['platform_name']) ?>" 
                    style="color: black;"
                    <?= $active_platform === $p['platform_name'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($label) ?>
            </option>
        <?php endforeach; ?>
    </select>
</div>

<script>
document.getElementById('lazadaPlatformSwitcher').addEventListener('change', function() {
    const platform = this.value;
    fetch('account_switcher.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=switch_platform&platform_name=' + encodeURIComponent(platform)
    })
    .then(r => r.json())
    .then(data => {
        if(data.success) {
            window.location.reload();
        } else {
            alert('Error switching account');
        }
    });
});
</script>

<?php
// views/shopee/shopee_token_warning.php

// Ensure $_hconn is available
if (isset($_hconn)) {
    $stmtToken = $_hconn->prepare("SELECT token_expires_at FROM shopee_config WHERE is_active = 1 LIMIT 1");
    $stmtToken->execute();
    $configData = $stmtToken->fetch(PDO::FETCH_ASSOC);
    $tokenWarning = false;
    $daysLeft = 0;
    if ($configData && !empty($configData['token_expires_at'])) {
        $expires = strtotime($configData['token_expires_at']);
        $daysLeft = round(($expires - time()) / 86400);
        if ($daysLeft <= 3) {
            $tokenWarning = true;
        }
    }

    if ($tokenWarning): ?>
        <div class="alert alert-danger d-flex align-items-center mb-4 shadow-sm" style="border-radius: 12px; border-left: 5px solid #dc3545;">
            <div class="me-3 fs-3">
                <i class="fa-solid fa-triangle-exclamation"></i>
            </div>
            <div>
                <h6 class="fw-bold mb-1">CRITICAL WARNING: Shopee Authorization Expiring Soon</h6>
                <p class="mb-0 small">Your Shopee store connection will expire in <strong><?= $daysLeft ?> days</strong>. The automated background sync will completely fail once it expires. Please click "Re-authorize" in your Shopee settings to prevent dashboard outages.</p>
            </div>
        </div>
    <?php endif;
}
?>

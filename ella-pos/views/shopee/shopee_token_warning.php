<?php
// views/shopee/shopee_token_warning.php

// Ensure $_hconn is available
if (isset($_hconn)) {
    $stmtToken = $_hconn->prepare("SELECT token_expires_at FROM shopee_config WHERE is_active = 1 LIMIT 1");
    $stmtToken->execute();
    $configData = $stmtToken->fetch(PDO::FETCH_ASSOC);
    $tokenWarning = false;
    if ($configData && !empty($configData['token_expires_at'])) {
        $expires = strtotime($configData['token_expires_at']);
        // If the token is already expired, it means the auto-refresh cron job failed
        if ($expires < time()) {
            $tokenWarning = true;
        }
    }

    if ($tokenWarning): ?>
        <div class="alert alert-danger d-flex align-items-center mb-4 shadow-sm" style="border-radius: 12px; border-left: 5px solid #dc3545;">
            <div class="me-3 fs-3">
                <i class="fa-solid fa-triangle-exclamation"></i>
            </div>
            <div>
                <h6 class="fw-bold mb-1">CRITICAL ERROR: Shopee Authorization Expired</h6>
                <p class="mb-0 small">Your Shopee store connection has expired because the background auto-refresh was interrupted. The automated background sync is currently failing. Please click "Re-authorize" in your Shopee settings immediately to resume operations.</p>
            </div>
        </div>
    <?php endif;
}
?>

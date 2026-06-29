<?php
// views/lazada/lazada_token_warning.php

// Ensure $conn is available (or $_hconn)
$dbConn = $conn ?? ($_hconn ?? null);

if ($dbConn) {
    try {
        $stmtToken = $dbConn->prepare("SELECT access_token, refresh_token FROM lazada_config WHERE is_active = 1 LIMIT 1");
        $stmtToken->execute();
        $configData = $stmtToken->fetch(PDO::FETCH_ASSOC);
        $tokenWarning = false;
        
        if (!$configData || empty($configData['access_token']) || empty($configData['refresh_token'])) {
            $tokenWarning = true;
        }

        if ($tokenWarning): ?>
            <div class="alert alert-danger d-flex align-items-center mb-4 shadow-sm" style="border-radius: 12px; border-left: 5px solid #dc3545;">
                <div class="me-3 fs-3">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                </div>
                <div>
                    <h6 class="fw-bold mb-1">WARNING: Lazada API Not Connected</h6>
                    <p class="mb-2 small">Your Lazada store is not connected. The automated background sync and mapping will not work. Please configure your API settings immediately.</p>
                    <a href="<?= BASE_URL ?>views/lazada/settings.php" class="btn btn-sm btn-danger fw-bold" style="border-radius: 6px; padding: 4px 12px; font-size: 0.8rem;">
                        <i class="fa-solid fa-plug me-1"></i> Go to Settings to Connect
                    </a>
                </div>
            </div>
        <?php endif;
    } catch (Exception $e) {
        // Table might not exist yet or connection error, ignore warning rendering
    }
}
?>

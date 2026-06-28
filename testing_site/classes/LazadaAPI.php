<?php
class LazadaAPI {
    private $partner_id;
    private $partner_key;
    private $is_test;

    public function __construct($partner_id, $partner_key, $is_test = false) {
        $this->partner_id = $partner_id;
        $this->partner_key = $partner_key;
        $this->is_test = $is_test;
    }

    public function getAuthUrl($redirect_url) {
        // TODO: Implement actual Lazada Open Platform auth URL logic
        return "https://auth.lazada.com/oauth/authorize?response_type=code&force_auth=true&redirect_uri=" . urlencode($redirect_url) . "&client_id=" . $this->partner_id;
    }

    public function getAccessToken($code, $shop_id = null) {
        // TODO: Implement actual token exchange
        return ['access_token' => 'dummy', 'refresh_token' => 'dummy', 'expire_in' => 2592000];
    }
}

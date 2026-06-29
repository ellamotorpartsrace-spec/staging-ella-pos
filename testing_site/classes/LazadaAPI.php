<?php
/**
 * classes/LazadaAPI.php — Lazada Open Platform API Wrapper
 */
class LazadaAPI {
    private $app_key;
    private $app_secret;
    private $region;
    private $is_sandbox;
    private $access_token;
    
    public function __construct($app_key, $app_secret, $region = 'PH', $is_sandbox = false) {
        $this->app_key = $app_key;
        $this->app_secret = $app_secret;
        $this->region = strtoupper($region);
        $this->is_sandbox = $is_sandbox;
    }

    public function setAccessToken($token) {
        $this->access_token = $token;
    }

    private function getEndpoint() {
        // Lazada endpoints are regionalized
        $endpoints = [
            'PH' => 'https://api.lazada.com.ph/rest',
            'SG' => 'https://api.lazada.sg/rest',
            'MY' => 'https://api.lazada.com.my/rest',
            'TH' => 'https://api.lazada.co.th/rest',
            'ID' => 'https://api.lazada.co.id/rest',
            'VN' => 'https://api.lazada.vn/rest',
        ];
        return $endpoints[$this->region] ?? $endpoints['PH'];
    }

    public function getAuthUrl($redirect_uri) {
        $authUrl = "https://auth.lazada.com/oauth/authorize?";
        $params = [
            'response_type' => 'code',
            'force_auth' => 'true',
            'redirect_uri' => $redirect_uri,
            'client_id' => $this->app_key
        ];
        return $authUrl . http_build_query($params);
    }

    public function getAccessToken($code) {
        $params = [
            'code' => $code
        ];
        return $this->call('/auth/token/create', $params, 'POST', true);
    }

    public function refreshAccessToken($refresh_token) {
        $params = [
            'refresh_token' => $refresh_token
        ];
        return $this->call('/auth/token/refresh', $params, 'POST', true);
    }

    /**
     * Generate HMAC-SHA256 signature for Lazada
     */
    private function generateSignature($apiPath, $params) {
        ksort($params);
        $signString = $apiPath;
        foreach ($params as $key => $val) {
            $signString .= $key . $val;
        }
        return strtoupper(hash_hmac('sha256', $signString, $this->app_secret));
    }

    /**
     * Make an API Call to Lazada Open Platform
     * $isAuthCall indicates if it's a token creation/refresh call.
     */
    public function call($apiPath, $params = [], $method = 'GET', $isAuthCall = false) {
        $sysParams = [
            'app_key' => $this->app_key,
            'timestamp' => round(microtime(true) * 1000),
            'sign_method' => 'sha256'
        ];

        // Do not pass access_token for auth token calls
        if ($this->access_token && !$isAuthCall) {
            $sysParams['access_token'] = $this->access_token;
        }

        $allParams = array_merge($sysParams, $params);
        $allParams['sign'] = $this->generateSignature($apiPath, $allParams);

        // Lazada uses a common gateway auth endpoint regardless of region for some auth calls.
        // /auth/token/create and /auth/token/refresh must hit https://auth.lazada.com/rest 
        $baseUrl = $isAuthCall ? 'https://auth.lazada.com/rest' : $this->getEndpoint();
        $url = $baseUrl . $apiPath;
        
        if (strtoupper($method) === 'GET') {
            $url .= '?' . http_build_query($allParams);
            $ch = curl_init($url);
        } else {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($allParams));
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return ['code' => 'CURL_ERROR', 'message' => $error];
        }

        return json_decode($response, true);
    }
}


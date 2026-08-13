<?php

declare(strict_types=1);

namespace BYD\Controllers;

use BYD\Models\RedisClient;
use BYD\Security\Security;
use BYD\Security\AuthMiddleware;
use BYD\Services\AuthService;
use Exception;

/**
 * SessionController
 *
 * Coordinates captcha challenge verification, secure session initialization,
 * and Vapi call authorization.
 */
final class SessionController
{
    private RedisClient $redis;
    private AuthService $authService;

    public function __construct()
    {
        $this->redis = RedisClient::getInstance();
        $this->authService = new AuthService();
    }

    /**
     * POST /api/init-session
     *
     * Entry point: Validates Captcha and issues secure JWT cookie + CSRF token.
     */
    public function initSession(): void
    {
        $raw = file_get_contents('php://input');
        $body = json_decode($raw, true) ?? [];

        $captchaToken = $body['captcha_token'] ?? '';
        if (empty($captchaToken)) {
            Security::jsonError('Captcha token is required', 400);
        }

        $ip = Security::getClientIp();

        // Verify Captcha Challenge
        if (!Security::verifyCaptcha($captchaToken, $ip)) {
            Security::jsonError('Captcha verification failed. Please try again.', 400);
        }

        // Generate a cryptographically secure session ID
        $sessionId = 'sess_' . bin2hex(random_bytes(16));

        // Initialize session context in Redis
        $context = [
            'session_id' => $sessionId,
            'ip'         => $ip,
            'started_at' => time(),
            'status'     => 'initialized',
            'query_count'=> 0
        ];
        $this->redis->setContext($sessionId, $context, 3600); // 1 hour session

        // Generate secure JWT (Access Token)
        $tokenPayload = [
            'session_id' => $sessionId,
            'ip'         => $ip,
        ];
        $jwt = $this->authService->generateToken($tokenPayload);

        // Save JWT inside HttpOnly Cookie
        Security::setJwtCookie($jwt, 3600);

        // Generate Refresh Token (7 days = 604800 seconds)
        $rawRefreshToken = bin2hex(random_bytes(32));
        $refreshHash = hash('sha256', $rawRefreshToken);
        $this->redis->set("refresh_token:{$refreshHash}", $sessionId, 604800);
        Security::setRefreshCookie($rawRefreshToken, 604800);

        // Generate one-time CSRF Token mapped to this session
        $csrfToken = Security::generateCsrfToken($sessionId);

        // Respond to Frontend
        header('Content-Type: application/json');
        echo json_encode([
            'success'      => true,
            'session_id'   => $sessionId,
            'csrf_token'   => $csrfToken,
            'message'      => 'Session initialized successfully'
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    /**
     * POST /api/vapi-auth
     *
     * Protected by AuthMiddleware. Grants permission to start Vapi call.
     *
     * Strategy: Vapi strips server.url from tools when a web call is started
     * from the browser (security restriction). To work around this, we update
     * the saved Vapi Assistant via Vapi Management API (server-side) with the
     * latest webhook URL and tool configs, then return just the assistantId to
     * the frontend. Frontend calls vapi.start(assistantId) which uses the
     * server-stored config, preserving all tool server URLs.
     */
    public function authorizeVapi(): void
    {
        $session = AuthMiddleware::getSession();
        if (!$session) {
            Security::jsonError('Unauthorized', 401);
        }

        $sessionId = $session->session_id;

        // Retrieve the current session context from Redis
        $context = $this->redis->getContext($sessionId);
        if (!$context) {
            Security::jsonError('Session expired or invalid', 400);
        }

        // Retrieve gender from request body
        $raw = file_get_contents('php://input');
        $body = json_decode($raw, true) ?? [];
        $gender = $body['gender'] ?? 'male';

        // Mark session as authorized for Vapi
        $context['status'] = 'vapi_authorized';
        $context['gender'] = $gender;
        $this->redis->setContext($sessionId, $context, 3600);

        $vapiPublicKey   = $_ENV['VAPI_PUBLIC_KEY']    ?? (string)(getenv('VAPI_PUBLIC_KEY')    ?: 'dev-public-key');
        $vapiAssistantId = $_ENV['VAPI_ASSISTANT_ID']  ?? (string)(getenv('VAPI_ASSISTANT_ID')  ?: '0a142edb-7150-4769-9da1-45b6751f54f6');
        $vapiApiKey      = $_ENV['VAPI_API_KEY']       ?? (string)(getenv('VAPI_API_KEY')       ?: '');

        if (empty($vapiApiKey)) {
            $vapiApiKey = $_ENV['VAPI_WEBHOOK_SECRET'] ?? (string)(getenv('VAPI_WEBHOOK_SECRET') ?: '098f1582-db4c-4d9c-9140-2cbf5253e926');
        }

        // Build the full assistant config (server-side, safe to include tool server URLs)
        $webhookController = new \BYD\Controllers\VapiWebhookController();
        $assistantConfig   = $webhookController->getAssistantConfig($sessionId, $gender);

        // ── Update the saved Vapi assistant via Vapi Management API ──────────
        // This is the key fix: we push the config (including tool server URLs)
        // server-side. The frontend will only use assistantId, so Vapi reads
        // the server-saved config with all URLs intact.
        $updateSuccess = false;
        $updateError   = null;
        if (!empty($vapiAssistantId) && !empty($vapiApiKey)) {
            [$updateSuccess, $updateError] = $this->updateVapiAssistant(
                $vapiAssistantId, $vapiApiKey, $assistantConfig
            );
        }

        if ($updateSuccess) {
            // ✅ Return assistantId — frontend uses vapi.start(assistantId)
            header('Content-Type: application/json');
            echo json_encode([
                'success'         => true,
                'publicKey'       => $vapiPublicKey,
                'sessionId'       => $sessionId,
                'status'          => 'authorized',
                'assistantId'     => $vapiAssistantId,   // ← frontend uses this
                'assistantConfig' => null,               // ← not needed anymore
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        } else {
            // ⚠️ Fallback: return full config (tool URLs will be stripped by Vapi,
            //    but at least the assistant will start with the correct system prompt)
            error_log("[SessionController] Vapi assistant update failed: {$updateError} — falling back to full config");
            header('Content-Type: application/json');
            echo json_encode([
                'success'         => true,
                'publicKey'       => $vapiPublicKey,
                'sessionId'       => $sessionId,
                'status'          => 'authorized',
                'assistantId'     => null,
                'assistantConfig' => $assistantConfig,
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        }
        exit;
    }

    /**
     * Update the Vapi assistant via Vapi Management API (PATCH /assistant/{id}).
     * Pushes the full config including tool server URLs server-side.
     *
     * @return array{bool, string|null}  [success, error_message]
     */
    private function updateVapiAssistant(string $assistantId, string $apiKey, array $config): array
    {
        $url  = "https://api.vapi.ai/assistant/{$assistantId}";
        $body = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        // Try cURL first if available (standard & reliable on Render Linux)
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST  => 'PATCH',
                CURLOPT_POSTFIELDS     => $body,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 10,
                CURLOPT_HTTPHEADER     => [
                    "Authorization: Bearer {$apiKey}",
                    'Content-Type: application/json',
                    'Accept: application/json',
                ],
            ]);

            $result   = curl_exec($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr  = curl_error($ch);
            curl_close($ch);

            if ($result === false) {
                return [false, "cURL error: {$curlErr}"];
            }

            if ($httpCode >= 200 && $httpCode < 300) {
                error_log("[SessionController] Vapi assistant updated successfully via cURL (HTTP {$httpCode})");
                return [true, null];
            }

            $decoded = json_decode((string)$result, true);
            $errMsg  = $decoded['message'] ?? $decoded['error'] ?? $result;
            return [false, "HTTP {$httpCode}: {$errMsg}"];
        }

        // Stream context fallback
        $caFile = __DIR__ . '/../../cacert.pem';
        $sslOpts = [
            'verify_peer'      => true,
            'verify_peer_name' => true,
        ];
        if (file_exists($caFile)) {
            $sslOpts['cafile'] = $caFile;
        }

        $opts = [
            'http' => [
                'method'  => 'PATCH',
                'header'  => implode("\r\n", [
                    "Authorization: Bearer {$apiKey}",
                    'Content-Type: application/json',
                    'Accept: application/json',
                    'Content-Length: ' . strlen($body),
                ]),
                'content' => $body,
                'timeout' => 10,
                'ignore_errors' => true,
            ],
            'ssl' => $sslOpts,
        ];

        $ctx    = stream_context_create($opts);
        $result = @file_get_contents($url, false, $ctx);

        if ($result === false) {
            return [false, 'HTTP request failed'];
        }

        $httpCode = 0;
        if (!empty($http_response_header)) {
            foreach ($http_response_header as $h) {
                if (preg_match('#HTTP/\S+\s+(\d+)#', $h, $m)) {
                    $httpCode = (int)$m[1];
                }
            }
        }

        if ($httpCode >= 200 && $httpCode < 300) {
            error_log("[SessionController] Vapi assistant updated successfully via stream (HTTP {$httpCode})");
            return [true, null];
        }

        $decoded = json_decode($result, true);
        $errMsg  = $decoded['message'] ?? $decoded['error'] ?? $result;
        return [false, "HTTP {$httpCode}: {$errMsg}"];
    }

    /**
     * POST /api/restore-session
     *
     * Protected by AuthMiddleware (which handles transparent refresh if needed).
     * Returns the session ID and a valid CSRF token without requiring Captcha.
     */
    public function restoreSession(): void
    {
        $session = AuthMiddleware::getSession();
        if (!$session) {
            Security::jsonError('Unauthorized', 401);
        }

        $sessionId = $session->session_id;

        // Ensure context still exists
        $context = $this->redis->getContext($sessionId);
        if (!$context) {
            Security::jsonError('Session expired', 400);
        }

        // Generate a valid CSRF token for the restored session
        $csrfToken = Security::generateCsrfToken($sessionId);

        header('Content-Type: application/json');
        echo json_encode([
            'success'      => true,
            'session_id'   => $sessionId,
            'csrf_token'   => $csrfToken,
            'message'      => 'Session restored successfully'
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }
}
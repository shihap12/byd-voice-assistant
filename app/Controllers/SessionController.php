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

        // Prepare authorization parameters for the Vapi SDK
        $vapiPublicKey  = $_ENV['VAPI_PUBLIC_KEY'] ?? (string) (getenv('VAPI_PUBLIC_KEY') ?: 'dev-public-key');
        
        // Get the full dynamic assistant configuration from the Webhook Controller, passing gender
        $webhookController = new \BYD\Controllers\VapiWebhookController();
        $assistantConfig = $webhookController->getAssistantConfig($sessionId, $gender);

        header('Content-Type: application/json');
        echo json_encode([
            'success'         => true,
            'publicKey'       => $vapiPublicKey,
            'sessionId'       => $sessionId,
            'status'          => 'authorized',
            'assistantConfig' => $assistantConfig
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
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
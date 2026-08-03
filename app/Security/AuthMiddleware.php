<?php

declare(strict_types=1);

namespace BYD\Security;

use BYD\Services\AuthService;
use BYD\Security\Security;
use stdClass;
use Exception;

/**
 * AuthMiddleware - Gatekeeper for all API requests
 */
final class AuthMiddleware
{
    private static ?stdClass $sessionData = null;

    // Paths that do not require authentication or CSRF checks
    private const EXCLUDED_PATHS = [
        '/api/init-session',
        '/api/vapi/webhook',
        '/health',
        '/api/upload/pdf',
        'api/chat/webhook',
        '/api/cars',
        '/api/car-images',
        '/api/settings/public',
        '/api/whatsapp/webhook',
    ];

    // Paths that DO require JWT/refresh validation, but are exempt from CSRF checks
    private const CSRF_EXEMPT_PATHS = [
        '/api/restore-session', // Uses refresh-token cookie — no CSRF token available at this stage
    ];

    /**
     * Handle the incoming request through the security gate
     */
    public static function handle(string $method, string $uri): void
{
    $path = self::normalizePath((string) parse_url($uri, PHP_URL_PATH));

    // 1. Bypass excluded paths
    if (self::isExcludedPath($path)) {
        return;
    }

    // 2. Global Rate Limiting by IP (already handled in index.php, but let's ensure session limit too)
    $ip = Security::getClientIp();

    // 3. Validate JWT (Access Token)
    $authService = new AuthService();
    $token = Security::getJwtFromCookie() ?? $authService->extractBearerToken();

    if (!$token) {
        Security::jsonError('Unauthorized: Missing access token', 401);
    }

    try {
        self::$sessionData = $authService->validateToken($token);
    } catch (Exception $e) {
        // Transparent Refresh Logic
        $refreshToken = Security::getRefreshFromCookie();
        if ($refreshToken) {
            $refreshHash = hash('sha256', $refreshToken);
            $redis = \BYD\Models\RedisClient::getInstance();
            $sessionId = (string) $redis->get("refresh_token:{$refreshHash}");
            
            if ($sessionId !== '') {
                // Refresh token is valid! Generate new access token
                $ip = Security::getClientIp();
                $tokenPayload = [
                    'session_id' => $sessionId,
                    'ip'         => $ip,
                ];
                $newJwt = $authService->generateToken($tokenPayload);
                Security::setJwtCookie($newJwt, 3600);
                
                // Set session data for this request
                self::$sessionData = (object) $tokenPayload;
            } else {
                Security::clearJwtCookie();
                Security::clearRefreshCookie();
                Security::jsonError('Unauthorized: Session expired', 401);
            }
        } else {
            Security::clearJwtCookie();
            Security::jsonError('Unauthorized: ' . $e->getMessage(), 401);
        }
    }

    $sessionId = self::$sessionData->session_id ?? '';
    if (empty($sessionId)) {
        Security::jsonError('Unauthorized: Invalid session format', 401);
    }

    // 4. Session-based Rate Limiting (Token Bucket)
    Security::checkRateLimit("session:{$sessionId}");

    // 5. CSRF protection for state-changing POST requests
    if ($method === 'POST' && !self::isCsrfExempt($path)) {
        $csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (empty($csrfToken)) {
            $rawBody = file_get_contents('php://input');
            $body = json_decode($rawBody, true);
            $csrfToken = $body['csrf_token'] ?? '';
        }

        if (!Security::validateCsrfToken($sessionId, $csrfToken)) {
            Security::jsonError('Forbidden: Invalid or expired CSRF token', 403);
        }
    }
}

    /**
     * Retrieve authenticated session data
     */
    public static function getSession(): ?stdClass
    {
        return self::$sessionData;
    }

    /**
     * Generate a fresh CSRF token for the current session and store it in Redis.
     * Called after each successful POST to provide the next token to the frontend.
     */
    public static function getFreshCsrfToken(): ?string
    {
        $session = self::$sessionData;
        if (!$session || empty($session->session_id)) {
            return null;
        }

        return Security::generateCsrfToken($session->session_id);
    }

    private static function isCsrfExempt(string $path): bool
    {
        foreach (self::CSRF_EXEMPT_PATHS as $exempt) {
            if (str_ends_with($path, $exempt)) {
                return true;
            }
        }
        return false;
    }

    private static function isExcludedPath(string $path): bool
{
    foreach (self::EXCLUDED_PATHS as $excluded) {
        if (str_ends_with($path, $excluded)) {
            return true;
        }
    }

    if (str_ends_with($path, '/login/admin')) {
        return true;
    }

    // Admin panel uses a separate cookie session auth flow.
    if (str_contains($path, '/admin')) {
        return true;
    }

    // Car API routes are admin-only — AdminController handles its own auth.
    // Use str_starts_with because paths like /api/cars/44/images won't match with str_ends_with.
    if (str_starts_with($path, '/api/cars') || str_starts_with($path, '/api/car-images')) {
        return true;
    }

   if (str_starts_with($path, '/storage/car_images/') || str_starts_with($path, '/storage/car_images_jpg/')) {
        return true;
    }

    return false;
}

    private static function normalizePath(string $path): string
    {
        $scriptName = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
        $baseDir = str_replace('\\', '/', dirname($scriptName));

        if ($baseDir !== '/' && $baseDir !== '.' && str_starts_with($path, $baseDir)) {
            $path = substr($path, strlen($baseDir)) ?: '/';
        }

        if ($path === '') {
            return '/';
        }

        return $path[0] === '/' ? $path : '/' . $path;
    }
}
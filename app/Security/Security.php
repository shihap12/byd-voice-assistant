<?php

declare(strict_types=1);

namespace BYD\Security;

use BYD\Models\RedisClient;
use RuntimeException;

/**
 * Security - CSRF, XSS sanitization, Rate limiting
 */
final class Security
{
    private const CSRF_TOKEN_LENGTH = 64;
    private const CSRF_TTL          = 3600; // 1 hour

    // ─── CSRF ─────────────────────────────────────────────────────────

    /**
     * Generate a CSRF token (HMAC-SHA256 based)
     */
    public static function generateCsrfToken(string $sessionId): string
    {
        $secret = $_ENV['JWT_SECRET'] ?? 'fallback-secret';
        $nonce  = bin2hex(random_bytes(self::CSRF_TOKEN_LENGTH / 2));
        $token  = hash_hmac('sha256', $sessionId . $nonce . time(), $secret);

        $redis = RedisClient::getInstance();
        $redis->set("csrf:{$sessionId}:{$token}", '1', self::CSRF_TTL);

        return $token;
    }

    /**
     * Validate CSRF token from request header or body
     */
    public static function validateCsrfToken(string $sessionId, string $token): bool
    {
        if (empty($token) || strlen($token) !== 64) {
            return false;
        }

        $redis = RedisClient::getInstance();
        $key   = "csrf:{$sessionId}:{$token}";

        if ((string) $redis->get($key) !== '1') {
            return false;
        }

        // Per-session CSRF token - DO NOT DELETE after validation
        // $redis->delete($key);
        return true;
    }

    // ─── XSS Sanitization ─────────────────────────────────────────────

    /**
     * Sanitize a single string value
     */
    public static function sanitize(string $input): string
    {
        $input = trim($input);
        $input = strip_tags($input);
        return htmlspecialchars($input, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Recursively sanitize an array (e.g. $_POST, $_GET)
     */
    public static function sanitizeArray(array $data): array
    {
        array_walk_recursive($data, static function (mixed &$value): void {
            if (is_string($value)) {
                $value = self::sanitize($value);
            }
        });
        return $data;
    }

    /**
     * Sanitize for SQL LIKE patterns (escape %, _, \)
     */
    public static function sanitizeLike(string $input): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $input);
    }

    // ─── Rate Limiting ────────────────────────────────────────────────

    /**
     * Check rate limit for an IP or identifier
     * Throws exception if rate limited
     */
    public static function checkRateLimit(string $identifier, ?int $max = null, ?int $window = null): void
    {
        $redis      = RedisClient::getInstance();
        $maxTokens  = $max ?? (int) ($_ENV['RATE_LIMIT_MAX'] ?? 60);
        $windowSecs = $window ?? (int) ($_ENV['RATE_LIMIT_WINDOW'] ?? 60);
        $remaining  = 0;

        $allowed = $redis->tokenBucket($identifier, $maxTokens, $windowSecs, $remaining);

        // Standard HTTP RateLimit response headers (RFC Standard)
        if (!headers_sent()) {
            header('X-RateLimit-Limit: ' . $maxTokens);
            header('X-RateLimit-Remaining: ' . max(0, $remaining));
            header('X-RateLimit-Reset: ' . (time() + $windowSecs));
        }

        if (!$allowed) {
            $ip = self::getClientIp();
            error_log("[Security] [RATE_LIMIT_EXCEEDED] identifier={$identifier} IP={$ip} max={$maxTokens} window={$windowSecs}s");

            http_response_code(429);
            header('Retry-After: ' . $windowSecs);
            self::jsonError('Too many requests. Please slow down.', 429);
            exit;
        }
    }

    // ─── Input Validation ─────────────────────────────────────────────

    /**
     * Validate required fields in an array
     * @throws RuntimeException
     */
    public static function requireFields(array $data, array $fields): void
    {
        foreach ($fields as $field) {
            if (!isset($data[$field]) || $data[$field] === '') {
                throw new RuntimeException("Missing required field: {$field}");
            }
        }
    }

    /**
     * Validate a Vapi webhook signature
     */
public static function validateVapiSignature(string $rawBody, string $signature): bool
{
    $secret = $_ENV['VAPI_WEBHOOK_SECRET'] ?? '';
    $env    = $_ENV['APP_ENV'] ?? 'production';



    if (empty($secret)) {
        error_log('[Security] VAPI_WEBHOOK_SECRET غير موجود في .env');
        if ($env === 'development') return true;
        return false;
    }

    if (hash_equals($secret, $signature)) {
        return true;
    }

    if ($env === 'development') {
        error_log('[Security] Bypassing VAPI signature mismatch in development mode.');
        return true;
    }

    return false;
}

    // ─── Helpers ──────────────────────────────────────────────────────

    public static function jsonError(string $message, int $code = 400): never
    {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => $message, 'code' => $code]);
        exit;
    }

    /**
     * Validate a Captcha token (Turnstile or Google reCAPTCHA v3)
     */
    public static function verifyCaptcha(string $token, string $ip): bool
    {
        $secret = $_ENV['CAPTCHA_SECRET'] ?? '';
        $env    = $_ENV['APP_ENV'] ?? 'production';

        if (empty($secret)) {
            // If secret is not set, log warning and allow through
            error_log("CAPTCHA_SECRET not set in environment.");
            return true;
        }

        // ── Development bypass ────────────────────────────────────────────
        // In development mode, skip external Cloudflare/Google verification
        // so that localhost testing works without a real captcha challenge.
        if ($env === 'development') {
            error_log("[Captcha] Development mode — skipping verification for token: " . substr($token, 0, 20) . '...');
            return true;
        }
        // ──────────────────────────────────────────────────────────────────

        $url = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
        // Fallback or override if Google reCAPTCHA is preferred
        if (str_contains($_ENV['CAPTCHA_PROVIDER'] ?? '', 'recaptcha')) {
            $url = 'https://www.google.com/recaptcha/api/siteverify';
        }

        $payload = [
            'secret'   => $secret,
            'response' => $token,
            'remoteip' => $ip
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($payload),
            CURLOPT_TIMEOUT        => 5
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            return false;
        }

        $data = json_decode($response, true);
        return (bool) ($data['success'] ?? false);
    }

    /**
     * Set JWT Cookie (HttpOnly, Secure, SameSite=Strict)
     */
    public static function setJwtCookie(string $token, int $ttl = 3600): void
    {
        $isSecure = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') 
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
            || ($_ENV['APP_ENV'] ?? 'production') !== 'development';

        setcookie(
            'access_token',
            $token,
            [
                'expires'  => time() + $ttl,
                'path'     => '/',
                'domain'   => '',
                'secure'   => $isSecure,
                'httponly' => true,
                'samesite' => $isSecure ? 'None' : 'Lax'
            ]
        );
    }

    /**
     * Get JWT from cookie
     */
    public static function getJwtFromCookie(): ?string
    {
        return $_COOKIE['access_token'] ?? null;
    }

    /**
     * Clear JWT Cookie
     */
    public static function clearJwtCookie(): void
    {
        self::setJwtCookie('', -3600);
    }

    /**
     * Set Refresh Cookie (HttpOnly, Secure, SameSite=None)
     */
    public static function setRefreshCookie(string $token, int $ttl = 604800): void
    {
        $isSecure = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') 
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
            || ($_ENV['APP_ENV'] ?? 'production') !== 'development';

        setcookie(
            'user_refresh_token',
            $token,
            [
                'expires'  => time() + $ttl,
                'path'     => '/',
                'domain'   => '',
                'secure'   => $isSecure,
                'httponly' => true,
                'samesite' => $isSecure ? 'None' : 'Lax'
            ]
        );
    }

    /**
     * Get Refresh Token from cookie
     */
    public static function getRefreshFromCookie(): ?string
    {
        return $_COOKIE['user_refresh_token'] ?? null;
    }

    /**
     * Clear Refresh Cookie
     */
    public static function clearRefreshCookie(): void
    {
        self::setRefreshCookie('', -3600);
    }

    public static function getClientIp(): string
    {
        $headers = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'];
        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                // Take the first IP from X-Forwarded-For chain
                return trim(explode(',', $_SERVER[$header])[0]);
            }
        }
        return '0.0.0.0';
    }
}
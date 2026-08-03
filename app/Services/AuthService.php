<?php

declare(strict_types=1);

namespace BYD\Services;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use BYD\Models\RedisClient;
use RuntimeException;
use stdClass;

/**
 * AuthService - JWT generation and validation
 */
final class AuthService
{
    private const ALGORITHM = 'HS256';

    /**
     * Generate a signed JWT token
     */
    public function generateToken(array $payload): string
    {
        $secret = $_ENV['JWT_SECRET'] ?? throw new RuntimeException('JWT_SECRET not set');
        $ttl    = (int) ($_ENV['JWT_TTL'] ?? 3600);
        $now    = time();

        $claims = array_merge($payload, [
            'iat' => $now,
            'exp' => $now + $ttl,
            'nbf' => $now,
            'iss' => 'byd-voice-assistant',
        ]);

        return JWT::encode($claims, $secret, self::ALGORITHM);
    }

    /**
     * Decode and validate a JWT token
     * @throws RuntimeException on invalid/expired token
     */
    public function validateToken(string $token): stdClass
    {
        $secret = $_ENV['JWT_SECRET'] ?? throw new RuntimeException('JWT_SECRET not set');

        try {
            $decoded = JWT::decode($token, new Key($secret, self::ALGORITHM));
        } catch (\Exception $e) {
            throw new RuntimeException('Invalid token: ' . $e->getMessage());
        }

        // Check if token is blacklisted (revoked)
        $redis = RedisClient::getInstance();
        if ($redis->exists("token:blacklist:{$token}")) {
            throw new RuntimeException('Token has been revoked');
        }

        return $decoded;
    }

    /**
     * Revoke a token (add to blacklist until expiry)
     */
    public function revokeToken(string $token): void
    {
        try {
            $decoded = $this->validateToken($token);
            $ttl     = max(0, $decoded->exp - time());
            $redis   = RedisClient::getInstance();
            $redis->set("token:blacklist:{$token}", '1', $ttl);
        } catch (RuntimeException) {
            // Already invalid, nothing to do
        }
    }

    /**
     * Extract Bearer token from Authorization header
     */
    public function extractBearerToken(): ?string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
            return $matches[1];
        }
        return null;
    }
}
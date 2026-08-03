<?php

declare(strict_types=1);

namespace BYD\Services;

use BYD\Models\Database;

final class AdminAuthService
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function verifyCredentials(string $email, string $password): ?array
    {
        $user = $this->db->queryOne(
            'SELECT id, email, password_hash, is_active FROM admin_users WHERE email = ? LIMIT 1',
            [$email]
        );

        if (!$user || (int) $user['is_active'] !== 1) {
            return null;
        }

        if (!password_verify($password, (string) $user['password_hash'])) {
            return null;
        }

        return $user;
    }

    public function issueToken(int $userId, string $ip, string $userAgent, ?int $ttlSeconds = null): string
    {
        $rawToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $rawToken);

        if ($ttlSeconds === null) {
            $ttlSeconds = (int) ($_ENV['ADMIN_TOKEN_TTL'] ?? 28800);
        }
        $expiresAt = date('Y-m-d H:i:s', time() + $ttlSeconds);

        $this->db->execute(
            'INSERT INTO admin_auth_tokens (user_id, token_hash, ip_address, user_agent, expires_at, last_used_at) VALUES (?, ?, ?, ?, ?, NOW())',
            [$userId, $tokenHash, $ip, mb_substr($userAgent, 0, 255), $expiresAt]
        );

        $this->db->execute('UPDATE admin_users SET last_login_at = NOW() WHERE id = ?', [$userId]);

        return $rawToken;
    }

    public function validateToken(string $rawToken): ?array
    {
        if ($rawToken === '') {
            return null;
        }

        $tokenHash = hash('sha256', $rawToken);

        $tokenRow = $this->db->queryOne(
            'SELECT t.id AS token_id, t.user_id, t.expires_at, t.revoked_at, u.email, u.is_active
             FROM admin_auth_tokens t
             INNER JOIN admin_users u ON u.id = t.user_id
             WHERE t.token_hash = ?
             LIMIT 1',
            [$tokenHash]
        );

        if (!$tokenRow) {
            return null;
        }

        if ($tokenRow['revoked_at'] !== null) {
            return null;
        }

        if ((int) $tokenRow['is_active'] !== 1) {
            return null;
        }

        $expiresTs = strtotime((string) $tokenRow['expires_at']);
        if ($expiresTs === false || $expiresTs < time()) {
            return null;
        }

        $this->db->execute('UPDATE admin_auth_tokens SET last_used_at = NOW() WHERE id = ?', [(int) $tokenRow['token_id']]);

        return [
            'token_id' => (int) $tokenRow['token_id'],
            'user_id' => (int) $tokenRow['user_id'],
            'email' => (string) $tokenRow['email'],
        ];
    }

    public function revokeToken(string $rawToken): void
    {
        if ($rawToken === '') {
            return;
        }

        $tokenHash = hash('sha256', $rawToken);
        $this->db->execute(
            'UPDATE admin_auth_tokens SET revoked_at = NOW() WHERE token_hash = ? AND revoked_at IS NULL',
            [$tokenHash]
        );
    }

    public function adminExists(): bool
    {
        $row = $this->db->queryOne('SELECT id FROM admin_users LIMIT 1');
        return (bool) $row;
    }

    public function createAdmin(string $email, string $password): void
    {
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $existing = $this->db->queryOne('SELECT id FROM admin_users WHERE email = ? LIMIT 1', [$email]);

        if ($existing) {
            $this->db->execute(
                'UPDATE admin_users SET password_hash = ?, is_active = 1, updated_at = NOW() WHERE id = ?',
                [$passwordHash, (int) $existing['id']]
            );
            return;
        }

        $this->db->execute(
            'INSERT INTO admin_users (email, password_hash, is_active) VALUES (?, ?, 1)',
            [$email, $passwordHash]
        );
    }

    public function setTokenCookie(string $cookieName, string $token, int $ttl): void
    {
        $isDev = ($_ENV['APP_ENV'] ?? 'production') === 'development';
        $isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        $secure = true; // Force true for SameSite=None

        setcookie($cookieName, $token, [
            'expires' => time() + $ttl,
            'path' => '/',
            'domain' => '',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'None',
        ]);
    }

    public function clearTokenCookie(string $cookieName): void
    {
        $isDev = ($_ENV['APP_ENV'] ?? 'production') === 'development';
        $isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        $secure = !$isDev && $isHttps;

        setcookie($cookieName, '', [
            'expires' => time() - 3600,
            'path' => '/',
            'domain' => '',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
    }

    public function authenticate(): ?array
    {
        $accessCookie = $_ENV['ADMIN_TOKEN_COOKIE'] ?? 'admin_access_token';
        $refreshCookie = $_ENV['ADMIN_REFRESH_COOKIE'] ?? 'admin_refresh_token';

        $accessToken = (string) ($_COOKIE[$accessCookie] ?? '');
        $admin = $this->validateToken($accessToken);

        if ($admin !== null) {
            return $admin;
        }

        // Access token invalid/expired, check refresh token
        $refreshToken = (string) ($_COOKIE[$refreshCookie] ?? '');
        if ($refreshToken !== '') {
            $admin = $this->validateToken($refreshToken);
            if ($admin !== null) {
                // Refresh token is valid! Issue a new access token (15 mins = 900 seconds)
                $ip = \BYD\Security\Security::getClientIp();
                $userAgent = (string) ($_SERVER['HTTP_USER_AGENT'] ?? 'unknown');
                $newAccessToken = $this->issueToken((int) $admin['user_id'], $ip, $userAgent, 900);

                $this->setTokenCookie($accessCookie, $newAccessToken, 900);
                return $admin;
            }
        }

        return null;
    }

    public function findById(int $id): ?array
    {
        return $this->db->queryOne(
            'SELECT id, email, password_hash FROM admin_users WHERE id = ? LIMIT 1',
            [$id]
        );
    }

    public function emailExists(string $email, int $excludeId): bool
    {
        $row = $this->db->queryOne(
            'SELECT id FROM admin_users WHERE email = ? AND id != ? LIMIT 1',
            [$email, $excludeId]
        );
        return (bool) $row;
    }

    public function updateCredentials(int $userId, ?string $newEmail, ?string $newPassword): void
    {
        if ($newEmail !== null && $newPassword !== null) {
            $hash = password_hash($newPassword, PASSWORD_DEFAULT);
            $this->db->execute(
                'UPDATE admin_users SET email = ?, password_hash = ?, updated_at = NOW() WHERE id = ?',
                [$newEmail, $hash, $userId]
            );
            return;
        }

        if ($newEmail !== null) {
            $this->db->execute(
                'UPDATE admin_users SET email = ?, updated_at = NOW() WHERE id = ?',
                [$newEmail, $userId]
            );
            return;
        }

        if ($newPassword !== null) {
            $hash = password_hash($newPassword, PASSWORD_DEFAULT);
            $this->db->execute(
                'UPDATE admin_users SET password_hash = ?, updated_at = NOW() WHERE id = ?',
                [$hash, $userId]
            );
        }
    }
}
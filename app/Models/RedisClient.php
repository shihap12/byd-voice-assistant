<?php

declare(strict_types=1);

namespace BYD\Models;

use Predis\Client;
use RuntimeException;

/**
 * RedisClient - Singleton wrapper around predis/predis
 * Handles caching, sessions, queue, and rate limiting
 *
 * تحديث Upstash: أضفنا دعم TLS (مطلوب إلزامياً للاتصال عن طريق TCP
 * مع Upstash) عن طريق REDIS_TLS بالـ .env. لو REDIS_TLS مش موجودة أو
 * false، بيشتغل بالضبط متل قبل (tcp عادي بدون تشفير) — يعني ما في أي
 * كسر لبيئة XAMPP المحلية.
 */
final class RedisClient
{
    private static ?self $instance = null;
    private Client $client;

    private function __construct()
    {
        $this->connect();
    }

    private function connect(): void
    {
        $isTls = filter_var($_ENV['REDIS_TLS'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $params = [
            'scheme'   => $isTls ? 'tls' : 'tcp',
            'host'     => $_ENV['REDIS_HOST']     ?? '127.0.0.1',
            'port'     => (int) ($_ENV['REDIS_PORT'] ?? 6379),
            'database' => (int) ($_ENV['REDIS_DB']   ?? 0),
        ];

        if ($isTls) {
            $params['ssl'] = [
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ];
        }

        $password = $_ENV['REDIS_PASSWORD'] ?? null;
        if ($password && $password !== 'null') {
            $params['password'] = $password;
        }

        $options = [
            'prefix'     => 'byd:',
            'exceptions' => true,
        ];

        try {
            $this->client = new Client($params, $options);
            $this->client->ping(); // Validate connection
        } catch (\Exception $e) {
            throw new RuntimeException('Redis connection failed: ' . $e->getMessage());
        }
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getClient(): Client
    {
        return $this->client;
    }

    // ─── Cache helpers ───────────────────────────────────────────────

    public function set(string $key, mixed $value, int $ttl = 3600): void
    {
        $serialized = is_array($value) ? json_encode($value) : (string) $value;
        $this->client->setex($key, $ttl, $serialized);
    }

    public function get(string $key): mixed
    {
        $value = $this->client->get($key);
        if ($value === null) return null;

        $decoded = json_decode($value, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
    }

    public function delete(string $key): void
    {
        $this->client->del([$key]);
    }

    public function exists(string $key): bool
    {
        return (bool) $this->client->exists($key);
    }

    // ─── Queue helpers (RPUSH/BLPOP pattern) ─────────────────────────

    /**
     * Push a job to the queue
     */
    public function pushJob(string $queue, array $payload): void
    {
        $payload['pushed_at'] = time();
        $payload['attempts']  = 0;
        $this->client->rpush("queue:{$queue}", [json_encode($payload)]);
    }

    /**
     * Pop a job from the queue (blocking, 2s timeout)
     * Returns null if no job available
     */
    public function popJob(string $queue): ?array
    {
        $result = $this->client->blpop(["queue:{$queue}"], 2);
        if (!$result) return null;

        return json_decode($result[1], true);
    }

    /**
     * Re-queue a failed job (with attempt count)
     */
    public function requeueJob(string $queue, array $payload, int $delay = 5): void
    {
        $payload['attempts']++;
        $payload['retry_at'] = time() + $delay;

        // Push to delayed queue, worker checks this periodically
        $this->client->zadd("queue:{$queue}:delayed", [$payload['retry_at'] => json_encode($payload)]);
    }

    /**
     * Promote delayed jobs that are ready
     */
    public function promoteDelayedJobs(string $queue): void
    {
        $now  = time();
        $jobs = $this->client->zrangebyscore("queue:{$queue}:delayed", '-inf', (string) $now);

        foreach ($jobs as $job) {
            $this->client->rpush("queue:{$queue}", [$job]);
            $this->client->zrem("queue:{$queue}:delayed", $job);
        }
    }

    // ─── Rate limiting (Token Bucket) ────────────────────────────────

    /**
     * Token bucket rate limiter using Atomic Redis Lua scripting
     * @return bool true = allowed, false = rate limited
     */
    public function tokenBucket(string $identifier, int $maxTokens = 60, int $windowSeconds = 60, ?int &$remaining = null): bool
    {
        $key        = "rate:{$identifier}";
        $now        = microtime(true);
        $refillRate = $maxTokens / $windowSeconds;

        $lua = <<<'LUA'
local key = KEYS[1]
local maxTokens = tonumber(ARGV[1])
local refillRate = tonumber(ARGV[2])
local now = tonumber(ARGV[3])
local ttl = tonumber(ARGV[4])

local data = redis.call('HMGET', key, 'tokens', 'last_refill')
local tokens = tonumber(data[1])
local last_refill = tonumber(data[2])

if not tokens or not last_refill then
    tokens = maxTokens - 1
    last_refill = now
    redis.call('HMSET', key, 'tokens', tokens, 'last_refill', last_refill)
    redis.call('EXPIRE', key, ttl)
    return {1, tokens}
end

local elapsed = now - last_refill
local newTokens = math.min(maxTokens, tokens + (elapsed * refillRate))

if newTokens < 1 then
    return {0, math.floor(newTokens)}
end

tokens = newTokens - 1
last_refill = now
redis.call('HMSET', key, 'tokens', tokens, 'last_refill', last_refill)
redis.call('EXPIRE', key, ttl)
return {1, math.floor(tokens)}
LUA;

        try {
            $res = $this->client->eval($lua, 1, $key, $maxTokens, $refillRate, $now, $windowSeconds * 2);
            if (is_array($res)) {
                $allowed   = (int) ($res[0] ?? 0) === 1;
                $remaining = max(0, (int) ($res[1] ?? 0));
                return $allowed;
            }
        } catch (\Throwable $e) {
            error_log("[RedisClient] Lua evaluation fallback: " . $e->getMessage());
        }

        // Fallback execution if Lua is disabled
        $data = $this->get($key);
        if ($data === null) {
            $this->set($key, ['tokens' => $maxTokens - 1, 'last_refill' => $now], $windowSeconds * 2);
            $remaining = $maxTokens - 1;
            return true;
        }

        $elapsed   = $now - $data['last_refill'];
        $newTokens = min($maxTokens, $data['tokens'] + ($elapsed * $refillRate));

        if ($newTokens < 1) {
            $remaining = 0;
            return false;
        }

        $remaining = (int) ($newTokens - 1);
        $this->set($key, ['tokens' => $remaining, 'last_refill' => $now], $windowSeconds * 2);
        return true;
    }

    // ─── Session context (for Vapi voice sessions) ────────────────────

    public function setContext(string $callId, array $context, int $ttl = 1800): void
    {
        $this->set("context:{$callId}", $context, $ttl);
    }

    public function getContext(string $callId): ?array
    {
        return $this->get("context:{$callId}");
    }

    public function extendContext(string $callId, int $ttl = 1800): void
    {
        $this->client->expire("byd:context:{$callId}", $ttl);
    }

    private function __clone() {}
    public function __wakeup(): void
    {
        throw new RuntimeException('Cannot unserialize singleton.');
    }
}
<?php

declare(strict_types=1);

namespace BYD\Models;

use PDO;
use PDOException;
use RuntimeException;

/**
 * Database - Singleton PDO wrapper
 * Thread-safe connection pool simulation with reconnect logic
 */
final class Database
{
    private static ?self $instance = null;
    private PDO $connection;

    private function __construct()
    {
        $this->connect();
    }
private function connect(): void
{
    $host    = $_ENV['DB_HOST']    ?? '127.0.0.1';
    $port    = $_ENV['DB_PORT']    ?? '3306';
    $dbname  = $_ENV['DB_NAME']    ?? 'byd_voice';
    $user    = $_ENV['DB_USER']    ?? 'root';
    $pass    = $_ENV['DB_PASS']    ?? '';
    $charset = $_ENV['DB_CHARSET'] ?? 'utf8mb4';

    $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset={$charset}";

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_PERSISTENT         => false,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$charset} COLLATE {$charset}_unicode_ci",
        PDO::ATTR_TIMEOUT            => 5,
        // NEW: TiDB Cloud بيرفض أي اتصال بدون TLS
        PDO::MYSQL_ATTR_SSL_CA                 => '/etc/ssl/certs/ca-certificates.crt',
        PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => true,
    ];

    try {
        $this->connection = new PDO($dsn, $user, $pass, $options);
    } catch (PDOException $e) {
        throw new RuntimeException('Database connection failed: ' . $e->getMessage());
    }
}
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getConnection(): PDO
    {
        // Reconnect if connection lost (long-running workers)
        try {
            $this->connection->query('SELECT 1');
        } catch (PDOException) {
            $this->connect();
        }
        return $this->connection;
    }

    /**
     * Execute a prepared statement and return rows
     */
    public function query(string $sql, array $params = []): array
    {
        $stmt = $this->getConnection()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Execute INSERT/UPDATE/DELETE, return affected rows
     */
    public function execute(string $sql, array $params = []): int
    {
        $stmt = $this->getConnection()->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /**
     * Fetch single row
     */
    public function queryOne(string $sql, array $params = []): array|false
    {
        $stmt = $this->getConnection()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch();
    }

    /**
     * Get last inserted ID
     */
    public function lastInsertId(): string
    {
        return $this->getConnection()->lastInsertId();
    }

    public function beginTransaction(): void
    {
        $this->getConnection()->beginTransaction();
    }

    public function commit(): void
    {
        $this->getConnection()->commit();
    }

    public function rollback(): void
    {
        $this->getConnection()->rollBack();
    }

    // Prevent cloning/unserialization of singleton
    private function __clone() {}
    public function __wakeup(): void
    {
        throw new RuntimeException('Cannot unserialize singleton.');
    }
}

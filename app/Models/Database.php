<?php

declare(strict_types=1);

namespace BYD\Models;

use PDO;
use PDOException;
use RuntimeException;

/**
 * Database - Singleton PDO wrapper
 * Thread-safe connection pool simulation with reconnect logic
 *
 * تعديل: إلغاء SELECT 1 قبل كل استعلام (كان بيضاعف الرحلات لقاعدة البيانات).
 * هلق كل دالة بتحاول تنفذ الاستعلام مباشرة، ولو فشل بسبب انقطاع اتصال
 * (PDOException) بتعيد الاتصال مرة وحدة بس وتعيد المحاولة.
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

        $caPath = '/etc/ssl/certs/ca-certificates.crt';
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $caPath = dirname(__DIR__, 2) . '/cacert.pem';
        }

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_PERSISTENT         => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$charset} COLLATE {$charset}_unicode_ci",
            PDO::ATTR_TIMEOUT            => 5,
            PDO::MYSQL_ATTR_SSL_CA                 => $caPath,
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

    /**
     * ملاحظة: عدنا نرجع الاتصال مباشرة بدون فحص SELECT 1.
     * فحص صحة الاتصال هلق بيصير فقط عند فشل فعلي (catch)، مش قبل كل استعلام.
     */
    public function getConnection(): PDO
    {
        return $this->connection;
    }

    /**
     * Execute a prepared statement and return rows
     */
    public function query(string $sql, array $params = []): array
    {
        try {
            $stmt = $this->connection->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            $this->connect(); // إعادة الاتصال مرة وحدة بس لو فعلاً انقطع
            $stmt = $this->connection->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        }
    }

    /**
     * Execute INSERT/UPDATE/DELETE, return affected rows
     */
    public function execute(string $sql, array $params = []): int
    {
        try {
            $stmt = $this->connection->prepare($sql);
            $stmt->execute($params);
            return $stmt->rowCount();
        } catch (PDOException $e) {
            $this->connect();
            $stmt = $this->connection->prepare($sql);
            $stmt->execute($params);
            return $stmt->rowCount();
        }
    }

    /**
     * Fetch single row
     */
    public function queryOne(string $sql, array $params = []): array|false
    {
        try {
            $stmt = $this->connection->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetch();
        } catch (PDOException $e) {
            $this->connect();
            $stmt = $this->connection->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetch();
        }
    }

    /**
     * Get last inserted ID
     */
    public function lastInsertId(): string
    {
        return $this->connection->lastInsertId();
    }

    public function beginTransaction(): void
    {
        $this->connection->beginTransaction();
    }

    public function commit(): void
    {
        $this->connection->commit();
    }

    public function rollback(): void
    {
        $this->connection->rollBack();
    }

    // Prevent cloning/unserialization of singleton
    private function __clone() {}
    public function __wakeup(): void
    {
        throw new RuntimeException('Cannot unserialize singleton.');
    }
}
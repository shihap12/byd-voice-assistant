#!/usr/bin/env php
<?php

/**
 * Migration Runner
 * Usage: php database/migrate.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

$host   = $_ENV['DB_HOST'] ?? '127.0.0.1';
$port   = $_ENV['DB_PORT'] ?? '3306';
$dbname = $_ENV['DB_NAME'] ?? 'byd_voice';
$user   = $_ENV['DB_USER'] ?? 'root';
$pass   = $_ENV['DB_PASS'] ?? '';

try {
    $pdo = new PDO(
        "mysql:host={$host};port={$port};charset=utf8mb4",
        $user,
        $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // Create database if not exists
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbname}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `{$dbname}`");

    // Track applied migrations
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `migrations` (
            `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `filename`   VARCHAR(200) NOT NULL UNIQUE,
            `applied_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Get already applied migrations
    $applied = $pdo->query("SELECT filename FROM migrations")->fetchAll(PDO::FETCH_COLUMN);

    // Run pending migrations
    $migrationDir = __DIR__ . '/migrations';
    $files        = glob($migrationDir . '/*.sql');
    sort($files);

    foreach ($files as $file) {
        $filename = basename($file);

        if (in_array($filename, $applied, true)) {
            echo "  [SKIP] {$filename}\n";
            continue;
        }

        echo "  [RUN]  {$filename} ... ";
        $sql = file_get_contents($file);

        // Execute statements one by one
        foreach (explode(';', $sql) as $statement) {
            $statement = trim($statement);
            if (!empty($statement)) {
                try {
                    $pdo->exec($statement);
                } catch (PDOException $e) {
                    // Ignore duplicate column or table already exists errors for idempotent migrations
                    if (str_contains($e->getMessage(), '1060 Duplicate column name') || 
                        str_contains($e->getMessage(), '1050 Table') ||
                        str_contains($e->getMessage(), 'already exists')) {
                        // Ignore and continue
                    } else {
                        throw $e;
                    }
                }
            }
        }

        $pdo->prepare("INSERT INTO migrations (filename) VALUES (?)")->execute([$filename]);
        echo "OK\n";
    }

    echo "\n✓ All migrations applied.\n";

} catch (PDOException $e) {
    echo "\n✗ Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}

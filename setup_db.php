<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

$host    = $_ENV['DB_HOST'] ?? '127.0.0.1';
$port    = $_ENV['DB_PORT'] ?? '3306';
$dbname  = $_ENV['DB_NAME'] ?? 'byd_voice';
$user    = $_ENV['DB_USER'] ?? 'root';
$pass    = $_ENV['DB_PASS'] ?? '';

try {
    $pdo = new PDO("mysql:host={$host};port={$port};dbname={$dbname}", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 1. Add warranty columns to cars table
    echo "Adding warranty columns to cars table...\n";
    try {
        $pdo->exec("ALTER TABLE cars ADD COLUMN warranty_years INT NULL DEFAULT NULL AFTER category");
        $pdo->exec("ALTER TABLE cars ADD COLUMN warranty_km INT NULL DEFAULT NULL AFTER warranty_years");
        echo "Successfully added columns.\n";
    } catch (PDOException $e) {
        echo "Columns might already exist: " . $e->getMessage() . "\n";
    }

    // 2. Create car_colors table
    echo "Creating car_colors table...\n";
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS car_colors (
            id INT AUTO_INCREMENT PRIMARY KEY,
            car_id INT UNSIGNED NOT NULL,
            color_name_en VARCHAR(255) NOT NULL,
            color_name_ar VARCHAR(255) NOT NULL,
            hex_code VARCHAR(10) NULL,
            FOREIGN KEY (car_id) REFERENCES cars(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
    echo "Successfully created car_colors table.\n";

    echo "Database setup complete!\n";

} catch (PDOException $e) {
    echo "Connection failed: " . $e->getMessage() . "\n";
}

<?php
require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use BYD\Models\Database;

$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

$db = Database::getInstance();
$migrations = [
    '001_create_tables.sql',
    '002_add_trims.sql',
    '003_admin_auth.sql',
    '004_add_description_to_cars.sql'
];

foreach ($migrations as $m) {
    try {
        $db->execute("INSERT IGNORE INTO migrations (filename) VALUES (?)", [$m]);
        echo "Inserted/Ignored: $m\n";
    } catch (Exception $e) {
        echo "Failed to insert $m: " . $e->getMessage() . "\n";
    }
}

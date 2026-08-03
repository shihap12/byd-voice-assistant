<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;
use BYD\Services\AdminAuthService;

$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

$email = $argv[1] ?? null;
$password = $argv[2] ?? null;

if ($email === null || $password === null) {
    echo "Usage: php create_admin_user.php <email> <password>\n";
    exit(1);
}

$email = mb_strtolower(trim($email));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo "Invalid email format.\n";
    exit(1);
}

if (strlen($password) < 8) {
    echo "Password must be at least 8 characters.\n";
    exit(1);
}

$auth = new AdminAuthService();

try {
    $auth->createAdmin($email, $password);
    echo "Admin user created successfully: {$email}\n";
} catch (Throwable $e) {
    echo "Failed to create admin user: " . $e->getMessage() . "\n";
    exit(1);
}

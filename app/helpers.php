<?php

declare(strict_types=1);

// Set default timezone globally to Palestine (Asia/Hebron)
date_default_timezone_set('Asia/Hebron');

/**
 * Global helper functions for BYD Voice Assistant
 */

if (!function_exists('env')) {
    function env(string $key, mixed $default = null): mixed
    {
        return $_ENV[$key] ?? getenv($key) ?: $default;
    }
}

if (!function_exists('dd')) {
    function dd(mixed ...$vars): never
    {
        foreach ($vars as $var) {
            echo '<pre>';
            var_dump($var);
            echo '</pre>';
        }
        exit;
    }
}

if (!function_exists('logger')) {
    function logger(string $message, string $level = 'INFO', array $context = []): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $ctx       = empty($context) ? '' : ' ' . json_encode($context);
        $line      = "[{$timestamp}] [{$level}] {$message}{$ctx}\n";

        $logFile = __DIR__ . '/../logs/app.log';
        file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    }
}

if (!function_exists('json_response')) {
    function json_response(array $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        exit;
    }
}

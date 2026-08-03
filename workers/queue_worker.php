#!/usr/bin/env php
<?php

/**
 * BYD Voice Assistant - Queue Worker Daemon
 *
 * Usage:
 *   php workers/queue_worker.php [queue_name]
 *   php workers/queue_worker.php pdf_processing
 *   php workers/queue_worker.php spec_sync
 *
 * Run as daemon (production):
 *   nohup php workers/queue_worker.php pdf_processing >> logs/worker.log 2>&1 &
 *
 * Or with supervisord (recommended):
 *   [program:byd-worker]
 *   command=php /var/www/byd/workers/queue_worker.php pdf_processing
 *   autostart=true
 *   autorestart=true
 */

declare(strict_types=1);

// Bootstrap
require_once __DIR__ . '/../vendor/autoload.php';

use BYD\Queue\Worker;
use BYD\Queue\Jobs\PdfProcessingJob;
use BYD\Queue\Jobs\SpecSyncJob;
use BYD\Queue\Jobs\NotificationJob;
use Dotenv\Dotenv;

// Load environment
$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

// Signal handling (graceful shutdown)
$shutdown = false;

if (extension_loaded('pcntl')) {
    pcntl_signal(SIGTERM, function () use (&$shutdown): void {
        echo "[" . date('Y-m-d H:i:s') . "] SIGTERM received. Shutting down gracefully...\n";
        $shutdown = true;
    });
    pcntl_signal(SIGINT, function () use (&$shutdown): void {
        echo "[" . date('Y-m-d H:i:s') . "] SIGINT received. Shutting down...\n";
        $shutdown = true;
    });
}

// Determine which queue to process
$queueName = $argv[1] ?? 'default';

// Register job handlers per queue
$handlers = [
    'pdf_processing' => PdfProcessingJob::class,
    'spec_sync'      => SpecSyncJob::class,
    'notifications'  => NotificationJob::class,
    'default'        => PdfProcessingJob::class,
];

if (!isset($handlers[$queueName])) {
    echo "Unknown queue: {$queueName}. Available: " . implode(', ', array_keys($handlers)) . "\n";
    exit(1);
}

echo "[" . date('Y-m-d H:i:s') . "] Worker started. Queue: {$queueName} | PID: " . getmypid() . "\n";

// Start the worker loop
$worker = new Worker($queueName, $handlers[$queueName]);
$worker->run($shutdown);

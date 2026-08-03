<?php

declare(strict_types=1);

namespace BYD\Queue;

use BYD\Models\RedisClient;
use BYD\Queue\Contracts\JobInterface;
use Throwable;

/**
 * Worker - The daemon loop that pulls and processes jobs from Redis
 */
final class Worker
{
    private RedisClient $redis;
    private string $queueName;
    private string $jobClass;

    private int $maxAttempts;
    private int $sleepSeconds;
    private int $processedCount = 0;

    public function __construct(string $queueName, string $jobClass)
    {
        $this->redis        = RedisClient::getInstance();
        $this->queueName    = $queueName;
        $this->jobClass     = $jobClass;
        $this->maxAttempts  = (int) ($_ENV['QUEUE_MAX_ATTEMPTS'] ?? 3);
        $this->sleepSeconds = (int) ($_ENV['QUEUE_SLEEP']        ?? 2);
    }

    /**
     * Main worker loop — runs until $shutdown is set to true via signal
     */
    public function run(bool &$shutdown): void
    {
        while (!$shutdown) {
            // Handle OS signals
            if (extension_loaded('pcntl')) {
                pcntl_signal_dispatch();
            }

            // Promote any delayed jobs that are now ready
            $this->redis->promoteDelayedJobs($this->queueName);

            // Try to get a job (blocking pop with 2s timeout)
            $payload = $this->redis->popJob($this->queueName);

            if ($payload === null) {
                // No job available, continue loop
                continue;
            }

            $this->processJob($payload);
        }

        $this->log("Worker shut down cleanly. Processed {$this->processedCount} jobs.");
    }

    /**
     * Process a single job with retry logic
     */
    private function processJob(array $payload): void
    {
        $jobId    = $payload['id']       ?? uniqid('job_');
        $attempts = $payload['attempts'] ?? 0;

        $this->log("Processing job [{$jobId}] attempt " . ($attempts + 1) . "/{$this->maxAttempts}");

        try {
            /** @var JobInterface $job */
            $job = new $this->jobClass();
            $job->handle($payload);

            $this->processedCount++;
            $this->log("Job [{$jobId}] completed successfully.");

        } catch (Throwable $e) {
            $this->log("Job [{$jobId}] FAILED: " . $e->getMessage(), 'ERROR');

            if ($attempts + 1 < $this->maxAttempts) {
                // Exponential backoff: 5s, 25s, 125s
                $delay = 5 ** ($attempts + 1);
                $this->redis->requeueJob($this->queueName, $payload, $delay);
                $this->log("Job [{$jobId}] re-queued with {$delay}s delay.");
            } else {
                // Move to dead letter queue
                $this->moveToDeadLetter($payload, $e->getMessage());
                $this->log("Job [{$jobId}] moved to dead letter queue after {$this->maxAttempts} attempts.");
            }
        }
    }

    /**
     * Dead letter queue — failed jobs stored for inspection
     */
    private function moveToDeadLetter(array $payload, string $error): void
    {
        $payload['failed_at'] = date('Y-m-d H:i:s');
        $payload['error']     = $error;

        $this->redis->getClient()->rpush(
            "byd:queue:{$this->queueName}:failed",
            [json_encode($payload)]
        );
    }

    private function log(string $message, string $level = 'INFO'): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $pid       = getmypid();
        echo "[{$timestamp}] [{$level}] [PID:{$pid}] [{$this->queueName}] {$message}\n";

        // Also append to log file
        $logFile = __DIR__ . '/../../logs/worker.log';
        file_put_contents(
            $logFile,
            "[{$timestamp}] [{$level}] [{$this->queueName}] {$message}\n",
            FILE_APPEND | LOCK_EX
        );
    }
}

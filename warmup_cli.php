<?php

declare(strict_types=1);

namespace BYD\Queue;

use BYD\Models\RedisClient;
use BYD\Queue\Contracts\JobInterface;
use Throwable;

/**
 * Worker - The daemon loop that pulls and processes jobs from Redis
 *
 * تحديث Upstash (مهم جداً):
 * قبل، كان الـ loop بينادي BLPOP + promoteDelayedJobs كل ما القائمة تكون
 * فاضية، وبيرجع يكرر فوراً بدون أي توقف — هاد كان يستهلك عشرات الآلاف
 * من الأوامر يومياً من كوطة Upstash الشهرية (500K على Free tier) حتى لو
 * ما في ولا job واحد شغال. هلق أضفنا sleep قصير لما القائمة فاضية، وصرنا
 * ننادي promoteDelayedJobs مرة كل كذا دورة بدل كل دورة.
 */
final class Worker
{
    private RedisClient $redis;
    private string $queueName;
    private string $jobClass;

    private int $maxAttempts;
    private int $sleepSeconds;
    private int $processedCount = 0;

    /** كل قد إيش دورة (لما القائمة فاضية) ننادي promoteDelayedJobs، بدل كل مرة */
    private const PROMOTE_EVERY_N_EMPTY_POLLS = 5;
    private int $emptyPollCount = 0;

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

            // Try to get a job (blocking pop with 2s timeout — this alone
            // already throttles the loop to at most 1 Redis round-trip
            // every 2s while the queue is empty)
            $payload = $this->redis->popJob($this->queueName);

            if ($payload === null) {
                // No job available. Only check for delayed jobs every N
                // empty polls instead of every single one, to cut Redis
                // command usage further (important on Upstash's monthly
                // command quota).
                $this->emptyPollCount++;
                if ($this->emptyPollCount >= self::PROMOTE_EVERY_N_EMPTY_POLLS) {
                    $this->redis->promoteDelayedJobs($this->queueName);
                    $this->emptyPollCount = 0;
                }

                // Extra breathing room on top of BLPOP's own 2s timeout.
                if ($this->sleepSeconds > 0) {
                    sleep($this->sleepSeconds);
                }
                continue;
            }

            // We got a job — check for delayed jobs now too, then reset counter
            $this->redis->promoteDelayedJobs($this->queueName);
            $this->emptyPollCount = 0;

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
     *
     * FIX: كان فيه هون "byd:" مكتوبة يدوياً بالمفتاح، بس getClient() أصلاً
     * بيرجع الـ Predis client اللي معه prefix "byd:" مضبوط من RedisClient
     * (شوف options['prefix'] بـ connect()). يعني كان عم يتولد مفتاح مكرر
     * "byd:byd:queue:...:failed" بدل "byd:queue:...:failed". صار محذوف.
     */
    private function moveToDeadLetter(array $payload, string $error): void
    {
        $payload['failed_at'] = date('Y-m-d H:i:s');
        $payload['error']     = $error;

        $this->redis->getClient()->rpush(
            "queue:{$this->queueName}:failed",
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
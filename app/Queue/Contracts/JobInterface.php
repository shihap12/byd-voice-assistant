<?php

declare(strict_types=1);

namespace BYD\Queue\Contracts;

interface JobInterface
{
    /**
     * Handle the job payload
     * @throws \Exception on failure (worker will retry)
     */
    public function handle(array $payload): void;
}

<?php

declare(strict_types=1);

namespace BYD\Queue\Jobs;

use BYD\Queue\Contracts\JobInterface;
use BYD\Models\CarModel;
use BYD\Models\RedisClient;

/**
 * SpecSyncJob - Syncs specifications from external BYD API into local DB
 *
 * Payload:
 * { "car_id": 5, "model_code": "BYD_SEAL_2024", "source": "byd_api" }
 */
final class SpecSyncJob implements JobInterface
{
    public function handle(array $payload): void
    {
        $carId     = (int) ($payload['car_id']     ?? 0);
        $modelCode = $payload['model_code'] ?? '';

        echo "  → Syncing specs for car_id={$carId}, model={$modelCode}\n";

        // TODO: Call BYD external API here
        // $apiData = $this->fetchFromBydApi($modelCode);

        // Invalidate cached specs so next request gets fresh data
        $redis = RedisClient::getInstance();
        $redis->delete("car:specs:{$carId}");
        $redis->delete("car:manual:{$carId}");

        echo "  ✓ SpecSyncJob completed. Cache invalidated.\n";
    }
}

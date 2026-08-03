<?php

declare(strict_types=1);

namespace BYD\Services;

use BYD\Models\Database;
use BYD\Models\RedisClient;
use BYD\Models\CarModel;

/**
 * RedisWarmupService
 *
 * إصلاح #3: استخدام normalizeModelName() عشان يكون الـ hash متوافق مع VapiWebhookController
 * يضمن إن مفاتيح Redis تتطابق سواء جاءت من warmup أو من VAPI
 */
final class RedisWarmupService
{
    private RedisClient $redis;
    private Database    $db;

    private const WARMUP_FLAG_KEY = 'warmup:done';
    private const WARMUP_FLAG_TTL = 3600;

    public function __construct()
    {
        $this->redis = RedisClient::getInstance();
        $this->db    = Database::getInstance();
    }

    public static function warmIfNeeded(): void
    {
        try {
            $service = new self();
            if (!$service->isWarmed()) {
                $service->warmAll();
            }
        } catch (\Exception $e) {
            error_log('[RedisWarmup] Failed: ' . $e->getMessage());
        }
    }

    public function isWarmed(): bool
    {
        return (bool) $this->redis->exists(self::WARMUP_FLAG_KEY);
    }

    public function warmAll(): array
    {
        $results = [];

        $results['models']   = $this->warmCarModels();
        $results['specs']    = $this->warmCarSpecs();
        $results['colors']   = $this->warmCarColors();
        $results['warranty'] = $this->warmWarrantyInfo();

        $this->redis->set(self::WARMUP_FLAG_KEY, date('Y-m-d H:i:s'), self::WARMUP_FLAG_TTL);

        error_log('[RedisWarmup] Completed: ' . json_encode($results));
        return ['status' => 'warmed', 'details' => $results];
    }

    /**
     * تحميل قائمة الموديلات
     */
    private function warmCarModels(): string
    {
        $models = $this->db->query(
            'SELECT id, model_name, model_name_ar, model_code, year, category, price_from,
                    warranty_years, warranty_km, passenger_count, cargo_liters
             FROM cars WHERE is_active = 1 ORDER BY year DESC, model_name ASC'
        );

        $this->redis->set('car:all_models', ['models' => $models], self::WARMUP_FLAG_TTL);
        return count($models) . ' models cached';
    }

    /**
     * تحميل المواصفات — بستخدم normalizeModelName() عشان الـ hash يتطابق مع VAPI
     *
     * FIX #3: قبل كان يعمل hash على model_name مباشرة
     * هلق بيعمل hash على normalizeModelName() — نفس الطريقة المستخدمة في VapiWebhookController
     */
    private function warmCarSpecs(): string
    {
        $cars  = $this->db->query('SELECT id, model_name, description FROM cars WHERE is_active = 1');
        $count = 0;

        foreach ($cars as $car) {
            $specs = $this->db->query(
                'SELECT spec_key, spec_value, spec_group, unit
                 FROM car_specifications WHERE car_id = ? ORDER BY spec_group, display_order',
                [$car['id']]
            );

            $result = [
                'car_id'      => $car['id'],
                'model_name'  => $car['model_name'],
                'description' => $car['description'] ?? '',
                'specs'       => $specs,
            ];

            // استخدام normalizeModelName() عشان يتطابق مع VAPI
            $normalizedName = CarModel::normalizeModelName($car['model_name']);
            $cacheKey       = 'car:specs:' . md5($normalizedName);

            $this->redis->set($cacheKey, $result, self::WARMUP_FLAG_TTL);
            $count++;
        }

        return "{$count} cars specs cached";
    }

    /**
     * تحميل الألوان — بنفس المنطق normalizeModelName()
     */
    private function warmCarColors(): string
    {
        $cars  = $this->db->query('SELECT id, model_name, model_name_ar FROM cars WHERE is_active = 1');
        $count = 0;

        foreach ($cars as $car) {
            $colors = $this->db->query(
                'SELECT color_name_ar, color_name_en, color_type FROM car_colors WHERE car_id = ? ORDER BY color_type',
                [$car['id']]
            );

            if (empty($colors)) {
                continue;
            }

            $exterior = array_values(array_filter($colors, fn($c) => $c['color_type'] === 'exterior'));
            $interior = array_values(array_filter($colors, fn($c) => $c['color_type'] === 'interior'));

            $result = [
                'model_name'      => $car['model_name_ar'] ?: $car['model_name'],
                'exterior_colors' => array_map(fn($c) => $c['color_name_ar'] ?: $c['color_name_en'], $exterior),
                'interior_colors' => array_map(fn($c) => $c['color_name_ar'] ?: $c['color_name_en'], $interior),
            ];

            $normalizedName = CarModel::normalizeModelName($car['model_name']);
            $cacheKey       = 'car:colors:' . md5($normalizedName);

            $this->redis->set($cacheKey, $result, self::WARMUP_FLAG_TTL);
            $count++;
        }

        return "{$count} cars colors cached";
    }

    /**
     * تحميل معلومات الكفالة — بنفس المنطق normalizeModelName()
     */
    private function warmWarrantyInfo(): string
    {
        $cars  = $this->db->query(
            'SELECT id, model_name, model_name_ar, warranty_years, warranty_km
             FROM cars WHERE is_active = 1'
        );
        $count = 0;

        foreach ($cars as $car) {
            $years = $car['warranty_years'];
            $km    = $car['warranty_km'];

            $result = [
                'model_name' => $car['model_name_ar'] ?: $car['model_name'],
                'warranty'   => ($years && $km)
                    ? "الكفالة لـ {$car['model_name']} بتوصل لـ {$years} سنين أو {$km} كيلومتر، أيهم بيجي أول"
                    : 'تواصل مع الوكالة عشان تاخد تفاصيل الكفالة',
            ];

            $normalizedName = CarModel::normalizeModelName($car['model_name']);
            $cacheKey       = 'car:warranty:' . md5($normalizedName);

            $this->redis->set($cacheKey, $result, self::WARMUP_FLAG_TTL);
            $count++;
        }

        return "{$count} cars warranty cached";
    }
}

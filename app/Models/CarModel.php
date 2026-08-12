<?php

declare(strict_types=1);

namespace BYD\Models;

/**
 * CarModel - Data access layer for BYD cars and specifications
 *
 * التحسينات المضافة:
 * - normalizeModelName(): يُعيَّر الاسم قبل البحث والـ hash — يحل مشكلة "سيل" vs "SEAL" vs "BYD SEAL"
 * - findByName(): fuzzy matching مع قاموس أسماء بديلة (aliases)
 * - باقي الـ methods ظلت كما هي
 */
final class CarModel
{
    private Database $db;

    /**
     * قاموس الأسماء البديلة
     * المفتاح = اسم مُعيَّر (بعد normalizeModelName)، القيمة = model_name الحقيقي في قاعدة البيانات
     */
    private const MODEL_ALIASES = [
        // SEAL
        'seal'              => 'BYD SEAL',
        'byd seal'          => 'BYD SEAL',
        'سيل'               => 'BYD SEAL',
        'byd سيل'           => 'BYD SEAL',

        // SEAL U EV
        'seal u'            => 'Seal U EV',
        'seal u dmi'        => 'Seal U EV',
        'seal u dm-i'       => 'Seal U EV',
        'seal u ev'         => 'Seal U EV',
        'byd seal u'        => 'Seal U EV',
        'سيل يو'            => 'Seal U EV',
        'سيل u'             => 'Seal U EV',
        'سيل يو اي في'      => 'Seal U EV',

        // HAN
        'han'               => 'BYD HAN',
        'byd han'           => 'BYD HAN',
        'هان'               => 'BYD HAN',
        'بي واي دي هان'     => 'BYD HAN',

        // ATTO 2
        'atto 2'            => 'BYD ATTO 2',
        'atto2'             => 'BYD ATTO 2',
        'byd atto 2'        => 'BYD ATTO 2',
        'أتو 2'             => 'BYD ATTO 2',
        'اتو2'              => 'BYD ATTO 2',

        // ATTO 3
        'atto 3'            => 'BYD ATTO 3',
        'atto3'             => 'BYD ATTO 3',
        'byd atto 3'        => 'BYD ATTO 3',
        'أتو 3'             => 'BYD ATTO 3',
        'اتو3'              => 'BYD ATTO 3',

        // DOLPHIN
        'dolphin'           => 'BYD DOLPHIN',
        'byd dolphin'       => 'BYD DOLPHIN',
        'دولفين'            => 'BYD DOLPHIN',
        'الدولفين'          => 'BYD DOLPHIN',

        // DOLPHIN SURF
        'dolphin surf'      => 'BYD DOLPHIN SURF',
        'byd dolphin surf'  => 'BYD DOLPHIN SURF',
        'دولفين سيرف'       => 'BYD DOLPHIN SURF',
        'دولفين surf'       => 'BYD DOLPHIN SURF',

        // SEALION 7
        'sealion 7'         => 'BYD SEALION 7',
        'sealion7'          => 'BYD SEALION 7',
        'byd sealion 7'     => 'BYD SEALION 7',
        'سيليون 7'          => 'BYD SEALION 7',
        'سيليون7'           => 'BYD SEALION 7',
        'سيلايون 7'         => 'BYD SEALION 7',

        // TANG
        'tang'              => 'BYD TANG',
        'byd tang'          => 'BYD TANG',
        'تانج'              => 'BYD TANG',
        'بي واي دي تانج'    => 'BYD TANG',
    ];

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * يُعيَّر اسم الموديل قبل البحث أو توليد Redis key
     * يحل مشكلة: warmup يخزن بـ hash مختلف عن اللي VAPI بيطلبه
     */
    public static function normalizeModelName(string $name): string
    {
        $name = mb_strtolower(trim($name));
        $name = preg_replace('/\s+/', ' ', $name);
        $name = str_replace(['byd ', 'بي واي دي '], '', $name);
        return trim($name);
    }

    /**
     * Find car by model name — مع fuzzy matching وأسماء بديلة
     *
     * الترتيب:
     * 1. قاموس aliases (سريع ودقيق)
     * 2. مطابقة كاملة case-insensitive
     * 3. مطابقة بالاسم العربي
     * 4. LIKE جزئي
     * 5. كلمة منفردة
     */
    public function findByName(string $name): ?array
    {
        $normalized = self::normalizeModelName($name);

        // 1. Alias lookup
        if (isset(self::MODEL_ALIASES[$normalized])) {
            $car = $this->db->queryOne(
                'SELECT c.*, COUNT(s.id) AS spec_count
                 FROM cars c
                 LEFT JOIN car_specifications s ON s.car_id = c.id
                 WHERE c.is_active = 1 AND c.model_name = ?
                 GROUP BY c.id LIMIT 1',
                [self::MODEL_ALIASES[$normalized]]
            );
            if ($car) return $car;
        }

        // 2. مطابقة كاملة (إنجليزي)
        $car = $this->db->queryOne(
            'SELECT c.*, COUNT(s.id) AS spec_count
             FROM cars c
             LEFT JOIN car_specifications s ON s.car_id = c.id
             WHERE c.is_active = 1 AND LOWER(c.model_name) = ?
             GROUP BY c.id LIMIT 1',
            [mb_strtolower($name)]
        );
        if ($car) return $car;

        // 3. مطابقة بالاسم العربي
        $car = $this->db->queryOne(
            'SELECT c.*, COUNT(s.id) AS spec_count
             FROM cars c
             LEFT JOIN car_specifications s ON s.car_id = c.id
             WHERE c.is_active = 1 AND c.model_name_ar = ?
             GROUP BY c.id LIMIT 1',
            [trim($name)]
        );
        if ($car) return $car;

        // 4. LIKE جزئي
        $like = '%' . $name . '%';
        $car  = $this->db->queryOne(
            'SELECT c.*, COUNT(s.id) AS spec_count
             FROM cars c
             LEFT JOIN car_specifications s ON s.car_id = c.id
             WHERE c.is_active = 1 AND (c.model_name LIKE ? OR c.model_name_ar LIKE ?)
             GROUP BY c.id LIMIT 1',
            [$like, $like]
        );
        if ($car) return $car;

        // 5. بحث بكلمة منفردة (لو بعت "سيل" يلاقي "BYD SEAL")
        $words = explode(' ', $normalized);
        foreach ($words as $word) {
            if (mb_strlen($word) < 2) continue;
            $wLike = '%' . $word . '%';
            $car   = $this->db->queryOne(
                'SELECT c.*, COUNT(s.id) AS spec_count
                 FROM cars c
                 LEFT JOIN car_specifications s ON s.car_id = c.id
                 WHERE c.is_active = 1 AND (LOWER(c.model_name) LIKE ? OR c.model_name_ar LIKE ?)
                 GROUP BY c.id LIMIT 1',
                [$wLike, $wLike]
            );
            if ($car) return $car;
        }

        return null;
    }

    /**
     * Get all specifications for a car
     */
    public function getSpecifications(int $carId): array
    {
        return $this->db->query(
            'SELECT spec_key, spec_value, spec_group, unit
             FROM car_specifications
             WHERE car_id = ?
             ORDER BY spec_group, display_order',
            [$carId]
        );
    }

    /**
     * Get specifications by group (e.g., "battery", "performance", "safety")
     */
    public function getSpecsByGroup(int $carId, string $group): array
    {
        return $this->db->query(
            'SELECT spec_key, spec_value, unit
             FROM car_specifications
             WHERE car_id = ? AND spec_group = ?',
            [$carId, $group]
        );
    }

    /**
     * Get all trims for a car
     */
    public function getTrims(int $carId): array
    {
        return $this->db->query(
            'SELECT * FROM car_trims
             WHERE car_id = ? AND is_active = 1
             ORDER BY price ASC',
            [$carId]
        );
    }

    /**
     * Get trim features for a car (optionally filtered by trim name)
     */
    public function getTrimFeatures(int $carId, ?string $trimName = null): array
    {
        if ($trimName !== null) {
            return $this->db->query(
                'SELECT * FROM car_trim_features
                 WHERE car_id = ? AND trim_name = ?
                 ORDER BY feature_group, feature_key',
                [$carId, $trimName]
            );
        }
        return $this->db->query(
            'SELECT * FROM car_trim_features
             WHERE car_id = ?
             ORDER BY trim_name, feature_group, feature_key',
            [$carId]
        );
    }

    /**
     * Get colors for a car
     */
    public function getColors(int $carId, string $type = ''): array
    {
        if ($type) {
            return $this->db->query(
                'SELECT * FROM car_colors WHERE car_id = ? AND color_type = ?',
                [$carId, $type]
            );
        }
        return $this->db->query(
            'SELECT * FROM car_colors WHERE car_id = ? ORDER BY color_type',
            [$carId]
        );
    }

    /**
     * Get images for a car
     */
    public function getImages(int $carId): array
    {
        return $this->db->query(
            'SELECT id, file_name, file_path, display_order
             FROM car_images
             WHERE car_id = ?
             ORDER BY display_order ASC, id ASC',
            [$carId]
        );
    }

    /**
     * Search across all cars and specs
     */
    public function search(string $query): array
    {
        $like = '%' . $query . '%';
        return $this->db->query(
            'SELECT c.id, c.model_name, c.model_name_ar, c.year, c.price_from,
                    s.spec_key, s.spec_value
             FROM cars c
             LEFT JOIN car_specifications s ON s.car_id = c.id
                   AND (s.spec_key LIKE ? OR s.spec_value LIKE ?)
             WHERE c.model_name LIKE ? OR c.model_name_ar LIKE ?
             ORDER BY c.year DESC
             LIMIT 20',
            [$like, $like, $like, $like]
        );
    }

    /**
     * Get all available models (for listing to caller)
     */
    public function getAllModels(): array
    {
        return $this->db->query(
            'SELECT id, model_name, model_name_ar, model_code, year, category, price_from,
                    warranty_years, warranty_km, passenger_count, cargo_liters
             FROM cars
             WHERE is_active = 1
             ORDER BY year DESC, model_name ASC'
        );
    }

    /**
     * Get full car data with specs, trims and colors (for AI prompt)
     */
    public function getCarFullData(int $carId): array
    {
        $car = $this->db->queryOne('SELECT * FROM cars WHERE id = ?', [$carId]);
        if (!$car) return [];

        $car['specifications'] = $this->getSpecifications($carId);
        $car['trims']          = $this->getTrims($carId);
        $car['colors']         = $this->getColors($carId);
        $car['trim_features']  = $this->getTrimFeatures($carId);
        $car['images']         = $this->getImages($carId);

        return $car;
    }

    /**
     * Upsert a specification (used by worker after PDF extraction)
     */
    public function upsertSpecification(int $carId, string $key, string $value, string $group = 'general'): void
    {
        $this->db->execute(
            'INSERT INTO car_specifications (car_id, spec_key, spec_value, spec_group)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE spec_value = VALUES(spec_value), updated_at = NOW()',
            [$carId, $key, $value, $group]
        );
    }

    /**
     * Log a user query for analytics
     */
    public function logQuery(string $callId, string $query, ?int $carId = null, ?string $intent = null): void
    {
        $this->db->execute(
            'INSERT INTO user_queries (call_id, query_text, car_id, intent, created_at)
             VALUES (?, ?, ?, ?, NOW())',
            [$callId, $query, $carId, $intent]
        );
    }
}

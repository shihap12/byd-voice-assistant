<?php

declare(strict_types=1);

namespace BYD\Services;

use RuntimeException;
use BYD\Models\Database;
use BYD\Models\RedisClient;

final class GeminiVisionService
{
    private string $apiKey;
    private string $apiUrl = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.1-flash-lite:generateContent';

    public function __construct()
    {
        $this->apiKey = $_ENV['GEMINI_API_KEY'] ?? '';
        if (empty($this->apiKey)) {
            throw new RuntimeException('GEMINI_API_KEY is not set in the .env file');
        }
    }

    public function processPdf(string $filePath, int $carId): array
    {
        if (!file_exists($filePath)) {
            throw new RuntimeException("File not found: {$filePath}");
        }

        $fileName  = basename($filePath);
        $base64Pdf = base64_encode(file_get_contents($filePath));

        $parsedData = $this->callGemini($base64Pdf);
        // Web enrichment removed: it depended on the google_search grounding tool,
        // which has no free quota on the Gemini 3.x model family and was returning
        // unreliable guessed values anyway. We now rely on the brochure PDF only.
        $parsedData = $this->normalizeParsedDataForStorage($parsedData);

        // We must resolve the real car_id first (upsertCar) before creating a record
        // in the pdf_documents table, because there is a foreign key on cars(id) —
        // if we set car_id before the car exists, we'd get error 1452
        // (Integrity constraint violation).
        $db = Database::getInstance();
        $resolvedCarId = $this->upsertCar($db, $carId, $parsedData);

        if ($resolvedCarId <= 0) {
            throw new RuntimeException('Failed to resolve the car in the database.');
        }

        $docId = $this->createPdfRecord($resolvedCarId, $fileName, $filePath);

        try {
            $this->updatePdfStatus($docId, 'processing');

            // The remaining storage tables (warranty/colors/specs/trims/features) now
            // all run inside a single transaction. If any step fails, they all roll
            // back together instead of leaving some saved and some not.
            $this->storeRelatedData($resolvedCarId, $parsedData);

            $this->updatePdfStatus($docId, 'done', json_encode($parsedData, JSON_UNESCAPED_UNICODE));
            $this->invalidateCarCache($resolvedCarId);

            $trimsCount  = count($parsedData['trims'] ?? []);
            $specsCount  = $this->countSpecs($parsedData['specifications'] ?? []);
            $colorsCount = count($parsedData['colors']['exterior'] ?? $parsedData['colors'] ?? []);

            return [
                'success'      => true,
                'car_id'       => $resolvedCarId,
                'specs_count'  => $specsCount,
                'colors_count' => $colorsCount,
                'trims_count'  => $trimsCount,
                'warranty'     => $parsedData['warranty'] ?? null,
            ];

        } catch (\Exception $e) {
            // On any failure, the status is always updated to failed instead of
            // remaining stuck at pending.
            $this->updatePdfStatus($docId, 'failed');
            throw $e;
        }
    }

    // ══════════════════════════════════════════════════════════════════
    // Gemini API
    // ══════════════════════════════════════════════════════════════════

    private function callGemini(string $base64Pdf): array
    {
        $fileUri = $this->uploadToGoogleFiles($base64Pdf);

        $prompt = <<<'PROMPT'
You are an expert automotive data analyst. Analyze this BYD car brochure PDF thoroughly.
Extract EVERY single piece of data and return ONLY a raw JSON object (no markdown, no backticks, no explanation).

Arabic transliteration rules for "car_name_ar" (and any other Arabic name field):
- car_name_ar must be written ENTIRELY in Arabic script. Never leave English words or Latin digits as-is, and never just copy car_name_en into car_name_ar.
- Numbers inside a model name are transliterated phonetically by how they are PRONOUNCED, not translated to Arabic number words. Example: "Sealion 7" -> "سيليون سڤن" (NOT "سيليون 7", NOT "سيليون سبعة").
- The English letter combination "ph" is written as the Arabic letter ف. Example: "Dolphin" -> "دولفين".
- The English letter "v" (and any "v" sound inside a word) is written using the three-dot ف variant "ڤ" — never plain ف, never any other letter. Example: "Seal U EV" -> "سيل يو إي ڤي".

Color hex codes: brochures rarely print the hex code as text, but each color name has a small colored swatch image next to it. Look at that swatch visually and estimate the closest matching hex code for "hex". If a color has no visible swatch to judge from, leave "hex" as an empty string — do not guess a hex code purely from the color's name (e.g. do not assume "White" = #FFFFFF without looking at the actual swatch).

Filling gaps not present in the PDF:
First, search this brochure's text and tables thoroughly for every field in the schema below. Only AFTER you have confirmed a specific field is genuinely absent from the PDF (not stated anywhere, in any table, page, or footnote) may you fill it using your own trained knowledge as an automotive expert about this EXACT model/trim/year. Do NOT use any external search tool (no google_search, no web browsing) — use only your own pretrained knowledge, and only as a last resort. If the field IS present in the PDF, always use the PDF's value — never override it with your own knowledge, even if you think it's more accurate. If you are not confident about a missing field, leave it null/empty rather than guessing.

Return this exact JSON structure (include only fields that exist in the PDF):

{
  "car_name_en": "Seal U DM",
  "car_name_ar": "سيل يو دي إم",
  "model_code": "BYD_SEAL_U_DM_2024",
  "year": 2024,
  "category": "suv",
  "price_from": 120000,
  "passenger_count": 5,
  "cargo_liters": 440,
  "towing_kg": 1500,
  "warranty": {
    "years": 6,
    "km": 150000,
    "battery_years": 8,
    "battery_km": 160000,
    "notes": "any warranty notes"
  },
  "colors": {
    "exterior": [
      { "en": "White", "ar": "أبيض", "hex": "#FFFFFF" },
      { "en": "Black", "ar": "أسود", "hex": "#000000" }
    ],
    "interior": [
      { "en": "Black", "ar": "أسود", "hex": "#1A1A1A" },
      { "en": "Brown", "ar": "بني", "hex": "#8B4513" }
    ]
  },
  "trims": [
    {
      "name": "Comfort",
      "name_ar": "كومفورت",
      "price": 119000,
      "drivetrain": "4x2",
      "power_hp": 218,
      "torque_nm": 310,
      "acceleration_0_100": 9.3,
      "top_speed_kmh": 180,
      "battery_capacity_kwh": 71.8,
      "battery_type": "BYD Blade Battery",
      "range_km": 420,
      "charge_ac_kw": 11,
      "charge_dc_kw": 80
    },
    {
      "name": "Design",
      "name_ar": "ديزاين",
      "price": 139000,
      "drivetrain": "4x2",
      "power_hp": 218,
      "torque_nm": 330,
      "acceleration_0_100": 9.6,
      "top_speed_kmh": 180,
      "battery_capacity_kwh": 87,
      "battery_type": "BYD Blade Battery",
      "range_km": 500,
      "charge_ac_kw": 11,
      "charge_dc_kw": 80
    }
  ],
  "specifications": {
    "battery": {
      "battery_type": "BYD Blade Battery",
      "obc_kw": "11 KW"
    },
    "performance": {
      "drivetrain": "4x2",
      "motor_type": "Electric"
    },
    "dimensions": {
      "length_mm": "4785 mm",
      "width_mm": "1890 mm",
      "height_mm": "1668 mm",
      "wheelbase_mm": "2765 mm",
      "weight_kg": "1925 kg",
      "tire_size": "235/50 R19"
    },
    "safety": {
      "airbags": "6",
      "blind_spot_monitoring": "yes",
      "heads_up_display": "yes",
      "ipb_integrated_power_brake": "yes",
      "esc_vdc": "yes",
      "ebd": "yes",
      "hill_start_control": "yes",
      "traction_control": "yes",
      "roll_movement_intervention": "yes",
      "door_open_warning": "yes",
      "driver_fatigue_monitoring": "yes",
      "adaptive_cruise_control": "yes",
      "rear_collision_warning": "yes",
      "reverse_warning": "yes",
      "traffic_jam_assist": "yes",
      "tpms": "yes",
      "camera_360": "yes",
      "child_lock": "yes",
      "anti_theft": "yes",
      "auto_high_beam": "yes",
      "speed_limit_recognition": "yes",
      "forward_collision_warning": "yes",
      "auto_emergency_braking": "yes",
      "lane_departure_warning": "yes",
      "lane_keep_assist": "yes"
    },
    "interior": {
      "leather_steering_wheel": "yes",
      "electric_front_seats": "yes",
      "seat_memory": "yes",
      "heated_ventilated_seats": "yes",
      "wireless_charger": "yes",
      "usb_type_c": "yes",
      "v2l_vehicle_to_load": "yes",
      "ambient_lighting": "Multi Color"
    },
    "exterior": {
      "panoramic_roof": "yes",
      "electric_tailgate": "yes",
      "wheel_size": "19 inches",
      "leather_upholstery": "yes",
      "auto_led_headlights": "yes",
      "led_drl": "yes",
      "led_taillights": "yes",
      "rain_sensor": "yes"
    },
    "technology": {
      "hud_screen_size": "12.3 inches",
      "infotainment_screen_size": "15.6 inches",
      "carplay_android_auto": "yes",
      "speaker_count": "10 speakers"
    }
  },
  "trim_features": [
    {
      "trim": "Comfort",
      "group": "interior",
      "key": "ambient_lighting",
      "value": "Single Color",
      "label": "إضاءة داخلية"
    },
    {
      "trim": "Design",
      "group": "interior",
      "key": "ambient_lighting",
      "value": "Multi Color",
      "label": "إضاءة داخلية"
    },
    {
      "trim": "Design",
      "group": "exterior",
      "key": "electric_tailgate",
      "value": "yes",
      "label": "صندوق خلفي كهربائي"
    },
    {
      "trim": "Comfort",
      "group": "technology",
      "key": "infotainment_screen_size",
      "value": "12.8 inches",
      "label": "شاشة ملونة"
    },
    {
      "trim": "Design",
      "group": "technology",
      "key": "infotainment_screen_size",
      "value": "15.6 inches",
      "label": "شاشة ملونة"
    },
    {
      "trim": "Comfort",
      "group": "technology",
      "key": "speaker_count",
      "value": "9 speakers",
      "label": "نظام صوتي"
    },
    {
      "trim": "Design",
      "group": "technology",
      "key": "speaker_count",
      "value": "10 speakers",
      "label": "نظام صوتي"
    }
  ],
  "additional_notes": "أي معلومة أو تفصيل موجود فعلياً بالبروشور وما بينطبق على أي حقل تاني بهاد الـ JSON (مثلاً عروض خاصة، ملاحظات تسويقية، شروط أو تفاصيل إضافية عن الموديل). اكتبها بالعربي بشكل مختصر وواضح. اتركها نص فاضي \"\" لو ما في أي شي زيادة."
}

CRITICAL RULES:
1. Extract ALL trims/variants you find in the brochure with their EXACT specs
2. Extract ALL colors (exterior AND interior separately)
3. Extract ALL features for each trim - if a feature differs between trims, add it to trim_features
4. Only include a spec/feature key if it is actually PRESENT in this trim (marked with a dot/checkmark/"yes" in the brochure table). Do NOT include a key with a "no" value for features that are absent — simply omit that key entirely, exactly like before. If a table cell contains multiple systems joined by "+" or "/" (e.g. "EPB + ABS + ESC"), split each one into its own key (each still only added if actually present). This applies to ALL sections, not just safety — never skip a row that IS marked as present in the brochure table just because it seems minor (wheel size, mirror type, camera, sensors, etc.).
5. For specifications, use the FIRST trim's values as default, put differences in trim_features
6. Use actual numbers from the PDF, not examples
7. "additional_notes" is ONLY for real technical/product info found in the PDF that has no other field to go into. Do NOT include company name, phone numbers, website, or generic legal/marketing disclaimers (e.g. "printed before vehicle inspection") — these add no value. Do not invent or guess anything for it.
8. Return ONLY the JSON object, nothing else
9. Always search the PDF first for every field. Only if a field is genuinely missing from the PDF may you fill it using your own pretrained knowledge about this exact car model — never use an external search tool, and never override a value that IS present in the PDF. Prefer leaving a field null/empty over inventing a value you are not confident about.
10. TTS-safe Arabic text: Any Arabic text field meant to be read aloud or shown to an end user (label, feature names, additional_notes, warranty notes, color names, trim names) must be pure Arabic — no Latin letters, no English abbreviations or acronyms embedded inside the Arabic sentence (e.g. never write "V2L", "V TO L", "ESC", "TPMS"... inside an Arabic label). Always explain the actual meaning/function of the feature in a full, clear Arabic phrase instead of the abbreviation.
   Example: instead of "نظام V2L" write "نظام شحن من السيارة لتشغيل الأجهزة الكهربائية الخارجية".
   Example: instead of "نظام TPMS" write "نظام مراقبة ضغط الإطارات".
   This text will be read aloud by a voice assistant, so it must sound natural when spoken — never leave an English acronym unexplained.
PROMPT;

        $payload = [
            'contents' => [[
                'parts' => [
                    [
                        'fileData' => [
                            'mimeType' => 'application/pdf',
                            'fileUri'  => $fileUri,
                        ]
                    ],
                    ['text' => $prompt]
                ]
            ]],
            'generationConfig' => [
                'temperature'     => 0.05,
                'maxOutputTokens' => 8192,
            ]
        ];

        $ch = curl_init("{$this->apiUrl}?key={$this->apiKey}");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 120,
        ]);

        $response = curl_exec($ch);
        $curlErr  = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($curlErr) {
            throw new RuntimeException("cURL error: {$curlErr}");
        }
        if ($httpCode !== 200) {
            throw new RuntimeException("Gemini API error. HTTP: {$httpCode}. Response: {$response}");
        }

        $responseData = json_decode($response, true);
        $textOutput   = $responseData['candidates'][0]['content']['parts'][0]['text'] ?? '';

        if (empty($textOutput)) {
            // Check for finish reason
            $finishReason = $responseData['candidates'][0]['finishReason'] ?? 'UNKNOWN';
            throw new RuntimeException("Gemini returned empty response. Finish reason: {$finishReason}");
        }

        // Clean JSON output
        $cleaned = trim($textOutput);
        $start   = strpos($cleaned, '{');
        $end     = strrpos($cleaned, '}');
        if ($start === false || $end === false) {
            throw new RuntimeException("Gemini did not return valid JSON: " . substr($textOutput, 0, 200));
        }
        $cleaned = substr($cleaned, $start, $end - $start + 1);

        $parsedData = json_decode($cleaned, true);
        if (!$parsedData) {
            throw new RuntimeException("Failed to parse JSON: " . substr($textOutput, 0, 200));
        }

        return $parsedData;
    }

    /**
     * Convert the final payload into DB-friendly native types before any write.
     * Numeric DB columns stay numeric; text columns stay strings.
     */
    private function normalizeParsedDataForStorage(array $data): array
    {
        $data['car_name_en'] = trim((string)($data['car_name_en'] ?? 'Unknown'));
        $data['car_name_ar'] = trim((string)($data['car_name_ar'] ?? 'Not specified'));

        // Safety net: flag it in logs if car_name_ar comes back with no Arabic
        // characters at all (e.g. Gemini just echoed the English name). We can't
        // auto-fix the transliteration from PHP, but this makes the bad case
        // visible instead of silently saving English text into the Arabic column.
        if ($data['car_name_ar'] !== '' && !preg_match('/[\x{0600}-\x{06FF}]/u', $data['car_name_ar'])) {
            logger('[GeminiVision] car_name_ar has no Arabic characters — likely mistranslation', 'WARN', [
                'car_name_en' => $data['car_name_en'] ?? null,
                'car_name_ar' => $data['car_name_ar'],
            ]);
        }

        $data['model_code']   = trim((string)($data['model_code'] ?? ''));
        $data['category']     = trim((string)($data['category'] ?? 'sedan'));
        $data['year']         = $this->toNullableInt($data['year'] ?? null) ?? 2024;
        $data['price_from']   = $this->toNullableFloat($data['price_from'] ?? null);
        $data['passenger_count'] = $this->toNullableInt($data['passenger_count'] ?? null);
        $data['cargo_liters']    = $this->toNullableInt($data['cargo_liters'] ?? null);
        $data['towing_kg']       = $this->toNullableInt($data['towing_kg'] ?? null);

        // Any info Gemini extracted from the PDF that has no dedicated field in the
        // schema lands here, and gets stored in cars.description (the admin/AI notes column).
        $data['description'] = trim((string)($data['additional_notes'] ?? ''));

        if (isset($data['warranty']) && is_array($data['warranty'])) {
            $data['warranty']['years']        = $this->toNullableInt($data['warranty']['years'] ?? null);
            $data['warranty']['km']           = $this->toNullableInt($data['warranty']['km'] ?? null);
            $data['warranty']['battery_years'] = $this->toNullableInt($data['warranty']['battery_years'] ?? null);
            $data['warranty']['battery_km']    = $this->toNullableInt($data['warranty']['battery_km'] ?? null);
            $data['warranty']['notes']        = isset($data['warranty']['notes']) ? trim((string)$data['warranty']['notes']) : null;
        }

        if (isset($data['trims']) && is_array($data['trims'])) {
            $data['trims'] = array_values(array_map(function (array $trim): array {
                return [
                    'name'                 => trim((string)($trim['name'] ?? '')),
                    'name_ar'              => trim((string)($trim['name_ar'] ?? '')),
                    'drivetrain'           => trim((string)($trim['drivetrain'] ?? '')),
                    'power_hp'             => $this->toNullableInt($trim['power_hp'] ?? null),
                    'torque_nm'            => $this->toNullableInt($trim['torque_nm'] ?? null),
                    'acceleration_0_100'   => $this->toNullableFloat($trim['acceleration_0_100'] ?? null),
                    'top_speed_kmh'        => $this->toNullableInt($trim['top_speed_kmh'] ?? null),
                    'battery_capacity_kwh' => $this->toNullableFloat($trim['battery_capacity_kwh'] ?? null),
                    'battery_type'         => trim((string)($trim['battery_type'] ?? '')),
                    'range_km'             => $this->toNullableInt($trim['range_km'] ?? null),
                    'charge_ac_kw'         => $this->toNullableFloat($trim['charge_ac_kw'] ?? null),
                    'charge_dc_kw'         => $this->toNullableInt($trim['charge_dc_kw'] ?? null),
                    'price'                => $this->toNullableFloat($trim['price'] ?? null),
                ];
            }, $data['trims']));
        }

        if (isset($data['colors']) && is_array($data['colors'])) {
            foreach (['exterior', 'interior'] as $type) {
                if (!isset($data['colors'][$type]) || !is_array($data['colors'][$type])) {
                    continue;
                }

                $data['colors'][$type] = array_values(array_map(function (array $color): array {
                    return [
                        'en'  => trim((string)($color['en'] ?? '')),
                        'ar'  => trim((string)($color['ar'] ?? '')),
                        'hex' => trim((string)($color['hex'] ?? '')),
                    ];
                }, $data['colors'][$type]));
            }
        }

        if (isset($data['trim_features']) && is_array($data['trim_features'])) {
            $data['trim_features'] = array_values(array_map(function (array $feature): array {
                return [
                    'trim'  => trim((string)($feature['trim'] ?? '')),
                    'group' => trim((string)($feature['group'] ?? 'general')),
                    'key'   => trim((string)($feature['key'] ?? '')),
                    'value' => trim((string)($feature['value'] ?? 'yes')),
                    'label' => trim((string)($feature['label'] ?? '')),
                ];
            }, $data['trim_features']));
        }

        if (isset($data['specifications']) && is_array($data['specifications'])) {
            foreach ($data['specifications'] as $group => $groupSpecs) {
                if (!is_array($groupSpecs)) {
                    continue;
                }
                foreach ($groupSpecs as $key => $value) {
                    if (is_string($value)) {
                        $data['specifications'][$group][$key] = trim($value);
                    }
                }
            }
        }

        return $data;
    }

    private function isMissingScalar(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }
        if (is_string($value) && trim($value) === '') {
            return true;
        }
        return false;
    }

    private function toNullableInt(mixed $value): ?int
    {
        if ($this->isMissingScalar($value)) {
            return null;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return (int) round($value);
        }

        if (is_string($value)) {
            if (preg_match('/\d+/', $value, $matches)) {
                return (int) $matches[0];
            }
        }

        return null;
    }

    private function toNullableFloat(mixed $value): ?float
    {
        if ($this->isMissingScalar($value)) {
            return null;
        }

        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (is_string($value)) {
            $normalized = str_replace([',', ' '], '', $value);
            if (preg_match('/-?\d+(?:\.\d+)?/', $normalized, $matches)) {
                return (float) $matches[0];
            }
        }

        return null;
    }

    private function uploadToGoogleFiles(string $base64Pdf): string
    {
        $pdfContent = base64_decode($base64Pdf);
        $fileSize   = strlen($pdfContent);

        $ch = curl_init("https://generativelanguage.googleapis.com/upload/v1beta/files?key={$this->apiKey}");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $pdfContent,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/pdf',
                'X-Goog-Upload-Command: upload, finalize',
                'X-Goog-Upload-Header-Content-Length: ' . $fileSize,
                'X-Goog-Upload-Header-Content-Type: application/pdf',
            ],
            CURLOPT_TIMEOUT => 60,
        ]);

        $response = curl_exec($ch);
        $curlErr  = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($curlErr) {
            throw new RuntimeException("File upload cURL error: {$curlErr}");
        }
        if ($httpCode !== 200) {
            throw new RuntimeException("File upload error. HTTP: {$httpCode}. Response: {$response}");
        }

        $fileData = json_decode($response, true);
        $fileUri  = $fileData['file']['uri'] ?? '';

        if (empty($fileUri)) {
            throw new RuntimeException("Failed to get file URI: {$response}");
        }

        return $fileUri;
    }

    // ══════════════════════════════════════════════════════════════════
    // Database Operations
    // ══════════════════════════════════════════════════════════════════

    private function upsertCar(Database $db, int $carId, array $data): int
    {
        $modelNameEn = trim($data['car_name_en'] ?? 'Unknown');
        $modelNameAr = trim($data['car_name_ar'] ?? 'Not specified');

        // Normalize model_code: uppercase + replace spaces/dashes with underscores
        $modelCode = strtoupper(trim(str_replace([' ', '-'], '_', $data['model_code'] ?? '')));
        if (empty($modelCode)) {
            $modelCode = 'BYD_' . strtoupper(str_replace(' ', '_', $modelNameEn)) . '_' . ($data['year'] ?? 2024);
        }

        $year     = (int) ($data['year'] ?? 2024);
        $category = strtolower(trim($data['category'] ?? 'sedan'));

        $validCategories = ['sedan', 'suv', 'hatchback', 'mpv', 'pickup'];
        if (!in_array($category, $validCategories, true)) {
            $category = 'sedan';
        }

        $priceFrom       = isset($data['price_from']) && $data['price_from'] > 0 ? (float)$data['price_from'] : null;
        $passengerCount  = isset($data['passenger_count']) ? (int)$data['passenger_count'] : null;
        $cargoLiters     = isset($data['cargo_liters']) ? (int)$data['cargo_liters'] : null;
        $towingKg        = isset($data['towing_kg']) ? (int)$data['towing_kg'] : null;

        // Anything from the PDF that didn't fit a dedicated column lands here
        // (see normalizeParsedDataForStorage -> additional_notes).
        $description = trim((string)($data['description'] ?? ''));

        // Atomic: insert or update if model_code already exists
        $db->execute(
            'INSERT INTO cars (model_name, model_name_ar, model_code, year, category, price_from, passenger_count, cargo_liters, towing_kg, description, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
             ON DUPLICATE KEY UPDATE
                 model_name      = VALUES(model_name),
                 model_name_ar   = VALUES(model_name_ar),
                 year            = VALUES(year),
                 category        = VALUES(category),
                 price_from      = COALESCE(VALUES(price_from), price_from),
                 passenger_count = COALESCE(VALUES(passenger_count), passenger_count),
                 cargo_liters    = COALESCE(VALUES(cargo_liters), cargo_liters),
                 towing_kg       = COALESCE(VALUES(towing_kg), towing_kg),
                 description     = COALESCE(NULLIF(VALUES(description), \'\'), description),
                 updated_at      = NOW()',
            [$modelNameEn, $modelNameAr, $modelCode, $year, $category,
             $priceFrom, $passengerCount, $cargoLiters, $towingKg, $description]
        );

        // Fetch the ID (lastInsertId returns 0 on DUPLICATE KEY UPDATE)
        $row = $db->queryOne('SELECT id FROM cars WHERE model_code = ?', [$modelCode]);

        if (!$row || (int) $row['id'] === 0) {
            throw new \RuntimeException(
                "Failed to resolve the car record after saving. model_code: {$modelCode}"
            );
        }

        return (int) $row['id'];
    }

    /**
     * Store warranty + colors + specifications + trims + trim features inside a
     * single transaction. The car_id here must already be resolved and confirmed
     * to actually exist in the cars table before this function is called
     * (see upsertCar inside processPdf).
     * If any step fails, all the dependent tables roll back together.
     */
    private function storeRelatedData(int $carId, array $data): void
    {
        $db = Database::getInstance();
        $db->beginTransaction();

        try {
            // ── 1. Warranty ──────────────────────────────────────────────────
            if (!empty($data['warranty'])) {
                $w = $data['warranty'];
                $db->execute(
                    'UPDATE cars SET
                         warranty_years   = ?,
                         warranty_km      = ?,
                         updated_at       = NOW()
                     WHERE id = ?',
                    [
                        $w['years']    ?? null,
                        $w['km']       ?? null,
                        $carId,
                    ]
                );
            }

            // ── 2. Colors (exterior + interior) ──────────────────────────────
            $this->storeColors($db, $carId, $data['colors'] ?? []);

            // ── 3. Specifications (shared / default values) ───────────────────
            $this->storeSpecifications($db, $carId, $data['specifications'] ?? []);

            // ── 4. Trims (car variants) ────────────────────────────────────────
            $this->storeTrims($db, $carId, $data['trims'] ?? []);

            // ── 5. Trim Features (features of each variant) ────────────────────────────
            $this->storeTrimFeatures($db, $carId, $data['trim_features'] ?? []);

            $db->commit();

        } catch (\Exception $e) {
            $db->rollback();
            throw new RuntimeException('DB error: ' . $e->getMessage());
        }
    }

    private function storeColors(Database $db, int $carId, array $colors): void
    {
        $db->execute('DELETE FROM car_colors WHERE car_id = ?', [$carId]);

        // Support both: {"exterior": [...], "interior": [...]} and flat array [...]
        if (isset($colors['exterior']) || isset($colors['interior'])) {
            $types = ['exterior' => $colors['exterior'] ?? [], 'interior' => $colors['interior'] ?? []];
        } else {
            $types = ['exterior' => $colors]; // legacy flat list
        }

        foreach ($types as $type => $list) {
            foreach ($list as $color) {
                $en  = trim($color['en']  ?? '');
                $ar  = trim($color['ar']  ?? '');
                $hex = trim($color['hex'] ?? '');
                if ($en || $ar) {
                    $db->execute(
                        'INSERT INTO car_colors (car_id, color_type, color_name_en, color_name_ar, hex_code)
                         VALUES (?, ?, ?, ?, ?)',
                        [$carId, $type, $en, $ar, $hex ?: null]
                    );
                }
            }
        }
    }

    private function storeSpecifications(Database $db, int $carId, array $specs): void
    {
        $db->execute('DELETE FROM car_specifications WHERE car_id = ?', [$carId]);
        $order = 0;

        foreach ($specs as $group => $groupSpecs) {
            if (!is_array($groupSpecs)) continue;
            foreach ($groupSpecs as $key => $value) {
                if ($value === null || $value === '') continue;
                $db->execute(
                    'INSERT INTO car_specifications
                         (car_id, spec_key, spec_value, spec_group, display_order)
                     VALUES (?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE
                         spec_value    = VALUES(spec_value),
                         display_order = VALUES(display_order)',
                    [$carId, $key, (string) $value, $group, $order++]
                );
            }
        }
    }

    private function storeTrims(Database $db, int $carId, array $trims): void
    {
        if (empty($trims)) return;

        $db->execute('DELETE FROM car_trims WHERE car_id = ?', [$carId]);

        foreach ($trims as $trim) {
            $name = trim($trim['name'] ?? '');
            if (empty($name)) continue;

            $db->execute(
                'INSERT INTO car_trims
                     (car_id, trim_name, trim_name_ar, drivetrain, power_hp, torque_nm,
                      acceleration_0_100, top_speed_kmh, battery_capacity_kwh, battery_type,
                      range_km, charge_ac_kw, charge_dc_kw, price)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                     trim_name_ar        = VALUES(trim_name_ar),
                     drivetrain          = VALUES(drivetrain),
                     power_hp            = VALUES(power_hp),
                     torque_nm           = VALUES(torque_nm),
                     acceleration_0_100  = VALUES(acceleration_0_100),
                     top_speed_kmh       = VALUES(top_speed_kmh),
                     battery_capacity_kwh = VALUES(battery_capacity_kwh),
                     battery_type        = VALUES(battery_type),
                     range_km            = VALUES(range_km),
                     charge_ac_kw        = VALUES(charge_ac_kw),
                     charge_dc_kw        = VALUES(charge_dc_kw),
                     price               = COALESCE(VALUES(price), price),
                     updated_at          = NOW()',
                [
                    $carId,
                    $name,
                    trim($trim['name_ar']             ?? ''),
                    trim($trim['drivetrain']           ?? ''),
                    isset($trim['power_hp'])           ? (int)$trim['power_hp']           : null,
                    isset($trim['torque_nm'])          ? (int)$trim['torque_nm']           : null,
                    isset($trim['acceleration_0_100']) ? (float)$trim['acceleration_0_100'] : null,
                    isset($trim['top_speed_kmh'])      ? (int)$trim['top_speed_kmh']       : null,
                    isset($trim['battery_capacity_kwh']) ? (float)$trim['battery_capacity_kwh'] : null,
                    trim($trim['battery_type']         ?? ''),
                    isset($trim['range_km'])           ? (int)$trim['range_km']            : null,
                    isset($trim['charge_ac_kw'])       ? (float)$trim['charge_ac_kw']      : null,
                    isset($trim['charge_dc_kw'])       ? (int)$trim['charge_dc_kw']        : null,
                    isset($trim['price']) && $trim['price'] > 0 ? (float)$trim['price']   : null,
                ]
            );
        }
    }

    private function storeTrimFeatures(Database $db, int $carId, array $features): void
    {
        if (empty($features)) return;

        $db->execute('DELETE FROM car_trim_features WHERE car_id = ?', [$carId]);

        foreach ($features as $f) {
            $trimName = trim($f['trim'] ?? '');
            $key      = trim($f['key']  ?? '');
            if (!$trimName || !$key) continue;

            $db->execute(
                'INSERT INTO car_trim_features
                     (car_id, trim_name, feature_key, feature_value, feature_group, feature_label)
                 VALUES (?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                     feature_value = VALUES(feature_value),
                     feature_label = VALUES(feature_label)',
                [
                    $carId,
                    $trimName,
                    $key,
                    trim((string)($f['value'] ?? 'yes')),
                    trim($f['group'] ?? 'general'),
                    trim($f['label'] ?? ''),
                ]
            );
        }
    }

    private function createPdfRecord(int $carId, string $fileName, string $filePath): int
    {
        $db = Database::getInstance();
        $db->execute(
            'INSERT INTO pdf_documents (car_id, file_name, file_path, status) VALUES (?, ?, ?, ?)',
            [$carId, $fileName, $filePath, 'pending']
        );
        return (int) $db->lastInsertId();
    }

    private function updatePdfStatus(int $docId, string $status, string $text = ''): void
    {
        $db = Database::getInstance();
        if ($status === 'done') {
            $db->execute(
                'UPDATE pdf_documents SET status = ?, extracted_text = ?, processed_at = NOW() WHERE id = ?',
                [$status, $text, $docId]
            );
        } else {
            $db->execute(
                'UPDATE pdf_documents SET status = ? WHERE id = ?',
                [$status, $docId]
            );
        }
    }

    private function invalidateCarCache(int $carId): void
    {
        try {
            $redis = RedisClient::getInstance();
            $redis->getClient()->ping();
        } catch (\Exception $e) {
            error_log("[GeminiVision] Redis unavailable, skipping cache clear");
            return;
        }

        $db  = Database::getInstance();
        $car = $db->queryOne('SELECT model_name FROM cars WHERE id = ?', [$carId]);
        if (!$car) return;

        $hash = md5(\BYD\Models\CarModel::normalizeModelName($car['model_name']));
        $redis->delete("car:specs:{$hash}");
        $redis->delete("car:colors:{$hash}");
        $redis->delete("car:warranty:{$hash}");
        $redis->delete("car:trims:{$hash}");
        $redis->delete('car:all_models');
        $redis->delete('warmup:done');

        error_log("[GeminiVision] Cache cleared for car_id={$carId}");
    }

    private function countSpecs(array $specifications): int
    {
        return array_sum(array_map(
            fn($g) => is_array($g) ? count($g) : 0,
            $specifications
        ));
    }
}
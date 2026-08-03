<?php

declare(strict_types=1);

namespace BYD\Queue\Jobs;

use BYD\Queue\Contracts\JobInterface;
use BYD\Services\PdfService;
use BYD\Models\CarModel;
use RuntimeException;

/**
 * PdfProcessingJob
 *
 * Triggered when a new PDF (car manual / spec sheet) is uploaded.
 * Extracts text and specifications, stores in DB.
 *
 * Payload example:
 * {
 *   "id": "job_abc123",
 *   "file_path": "/storage/pdfs/byd_seal_2024.pdf",
 *   "car_id": 5,
 *   "car_name": "BYD Seal 2024",
 *   "uploaded_by": 1,
 *   "type": "spec_sheet"
 * }
 */
final class PdfProcessingJob implements JobInterface
{
    private PdfService $pdfService;
    private CarModel   $carModel;

    public function __construct()
    {
        $this->pdfService = new PdfService();
        $this->carModel   = new CarModel();
    }

    public function handle(array $payload): void
    {
        $this->validate($payload);

        $filePath = $payload['file_path'];
        $carId    = (int) $payload['car_id'];
        $type     = $payload['type'] ?? 'manual';

        echo "  → Processing PDF: {$filePath}\n";

        // Step 1: Extract full text
        $fullText = $this->pdfService->extractText($filePath);
        echo "  → Extracted " . strlen($fullText) . " characters\n";

        // Step 2: Extract structured specs (if it's a spec sheet)
        if ($type === 'spec_sheet') {
            $specs = $this->pdfService->extractCarSpecs($filePath);
            echo "  → Found " . count($specs) . " specifications\n";

            foreach ($specs as $key => $value) {
                $group = $this->classifySpecGroup($key);
                $this->carModel->upsertSpecification($carId, $key, $value, $group);
            }
            echo "  → Specifications saved to database\n";
        }

        // Step 3: Cache the full text in Redis for fast RAG retrieval
        $redis   = \BYD\Models\RedisClient::getInstance();
        $cacheKey = "car:manual:{$carId}";
        $redis->set($cacheKey, [
            'text'       => mb_substr($fullText, 0, 50000), // Cap at 50k chars for Redis
            'file'       => basename($filePath),
            'processed'  => date('Y-m-d H:i:s'),
            'char_count' => strlen($fullText),
        ], 86400 * 7); // 7 days

        echo "  → Cached in Redis: {$cacheKey}\n";

        // Step 4: Save cache file to disk for very large docs
        $cacheFile = __DIR__ . '/../../../storage/pdf_cache/' . md5($filePath) . '.txt';
        file_put_contents($cacheFile, $fullText);
        echo "  → Disk cache written: {$cacheFile}\n";

        echo "  ✓ PdfProcessingJob completed for car_id={$carId}\n";
    }

    private function validate(array $payload): void
    {
        if (empty($payload['file_path'])) {
            throw new RuntimeException('Missing file_path in payload');
        }
        if (empty($payload['car_id'])) {
            throw new RuntimeException('Missing car_id in payload');
        }
        if (!file_exists($payload['file_path'])) {
            throw new RuntimeException("File not found: {$payload['file_path']}");
        }
    }

    /**
     * Classify a spec key into a logical group
     */
    private function classifySpecGroup(string $key): string
    {
        $key = mb_strtolower($key);

        $groups = [
            'battery'     => ['battery', 'kwh', 'charge', 'range', 'مدى', 'بطارية'],
            'performance' => ['power', 'torque', 'acceleration', '0-100', 'top speed', 'قوة', 'عزم'],
            'dimensions'  => ['length', 'width', 'height', 'weight', 'wheelbase', 'أبعاد', 'وزن'],
            'safety'      => ['airbag', 'abs', 'esp', 'ncap', 'rating', 'أمان'],
            'comfort'     => ['screen', 'display', 'audio', 'seats', 'climate', 'مقاعد'],
        ];

        foreach ($groups as $group => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($key, $keyword)) {
                    return $group;
                }
            }
        }

        return 'general';
    }
}

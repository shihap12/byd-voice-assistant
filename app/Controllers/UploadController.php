<?php

declare(strict_types=1);

namespace BYD\Controllers;

use BYD\Models\RedisClient;
use BYD\Security\Security;

final class UploadController
{
    private const ALLOWED_TYPES = ['application/pdf'];
    private const MAX_SIZE_MB   = 20;
    private const STORAGE_PATH  = __DIR__ . '/../../storage/pdf_cache/';

    public function handle(): void
    {
        Security::checkRateLimit(Security::getClientIp());

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            Security::jsonError('Method not allowed', 405);
        }

        if (empty($_FILES['pdf'])) {
            Security::jsonError('No file uploaded');
        }

        $file  = $_FILES['pdf'];
        $type  = Security::sanitize($_POST['type'] ?? 'brochure');

        // Validate file
        $this->validateFile($file);

        // Generate safe filename
        $filename = sprintf('car_%s_%d.pdf', $type, time());
        $destPath = self::STORAGE_PATH . $filename;

        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
            Security::jsonError('Failed to save file', 500);
        }

        try {
            $gemini = new \BYD\Services\GeminiVisionService();
            $result = $gemini->processPdf($destPath, 0); // 0 = auto insert

            header('Content-Type: application/json');
            echo json_encode([
                'success'      => true,
                'message'      => 'تم الرفع والتحليل بنجاح',
                'filename'     => $filename,
                'car_id'       => $result['car_id'],
                'specs_count'  => $result['specs_count'],
                'colors_count' => $result['colors_count'],
                'warranty'     => $result['warranty'],
            ]);
        } catch (\Exception $e) {
            Security::jsonError('AI Processing failed: ' . $e->getMessage(), 500);
        }
    }

    private function validateFile(array $file): void
    {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            Security::jsonError('Upload error: ' . $file['error']);
        }

        $maxBytes = self::MAX_SIZE_MB * 1024 * 1024;
        if ($file['size'] > $maxBytes) {
            Security::jsonError('File too large. Max ' . self::MAX_SIZE_MB . 'MB.');
        }

        $finfo    = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($file['tmp_name']);

        if (!in_array($mimeType, self::ALLOWED_TYPES, true)) {
            Security::jsonError("Invalid file type: {$mimeType}. Only PDF allowed.");
        }
    }
}
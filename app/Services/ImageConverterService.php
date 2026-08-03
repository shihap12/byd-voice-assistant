<?php

declare(strict_types=1);

namespace BYD\Services;

/**
 * ImageConverterService - يحوّل صور car_images من .webp لـ .jpg
 * ويخزنها بمسار مطابق تحت car_images_jpg، لأنه واتساب/Green API
 * بيتعامل مع .webp كستيكر مش كصورة عادية.
 */
final class ImageConverterService
{
    private string $projectRoot;

    public function __construct()
    {
        // app/Services -> جذر المشروع
        $this->projectRoot = dirname(__DIR__, 2);
    }

    /**
     * ياخد مسار الصورة الأصلية (مثلاً /storage/car_images/23/img.webp)
     * ويرجع مسار نسخة الـ jpg المكافئة بعد ما يتأكد إنها موجودة فعلياً
     * (يحولها لو أول مرة، وبعدها بيرجع نفس النسخة المخزنة).
     */
    public function ensureJpgVersion(string $originalUrlPath): ?string
    {
        $jpgUrlPath = preg_replace('#^/storage/car_images/#', '/storage/car_images_jpg/', $originalUrlPath);
        $jpgUrlPath = preg_replace('/\.\w+$/', '.jpg', $jpgUrlPath);

        $jpgAbsPath = $this->projectRoot . $jpgUrlPath;

        if (is_file($jpgAbsPath)) {
            return $jpgUrlPath;
        }

        $sourceAbsPath = $this->projectRoot . $originalUrlPath;
        if (!is_file($sourceAbsPath)) {
            error_log("[ImageConverterService] Source not found: {$sourceAbsPath}");
            return null;
        }

        if (!function_exists('imagecreatefromwebp')) {
            error_log('[ImageConverterService] GD/webp support not available on this PHP install');
            return null;
        }

        $image = @imagecreatefromwebp($sourceAbsPath);
        if ($image === false) {
            error_log("[ImageConverterService] Failed to read webp: {$sourceAbsPath}");
            return null;
        }

        $width  = imagesx($image);
        $height = imagesy($image);
        $bg     = imagecreatetruecolor($width, $height);
        imagefill($bg, 0, 0, imagecolorallocate($bg, 255, 255, 255));
        imagecopy($bg, $image, 0, 0, 0, 0, $width, $height);

        $destDir = dirname($jpgAbsPath);
        if (!is_dir($destDir)) {
            mkdir($destDir, 0755, true);
        }

        $ok = imagejpeg($bg, $jpgAbsPath, 85);
        imagedestroy($image);
        imagedestroy($bg);

        if (!$ok) {
            error_log("[ImageConverterService] Failed to write jpg: {$jpgAbsPath}");
            return null;
        }

        return $jpgUrlPath;
    }
}
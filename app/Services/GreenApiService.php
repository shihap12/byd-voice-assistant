<?php

declare(strict_types=1);

namespace BYD\Services;

/**
 * GreenApiService - طبقة تواصل بسيطة مع Green API (واتساب)
 */
final class GreenApiService
{
    private string $idInstance;
    private string $apiTokenInstance;
    private string $apiUrl;

    public function __construct()
    {
        $this->idInstance = (string) ($_ENV['GREENAPI_ID_INSTANCE'] ?? '');
        $this->apiTokenInstance = (string) ($_ENV['GREENAPI_API_TOKEN'] ?? '');
        $this->apiUrl = rtrim((string) ($_ENV['GREENAPI_API_URL'] ?? 'https://api.green-api.com'), '/');
    }

    public function isConfigured(): bool
    {
        return $this->idInstance !== '' && $this->apiTokenInstance !== '';
    }

    /**
     * إرسال رسالة نصية
     */
    public function sendMessage(string $chatId, string $message): bool
    {
        if (!$this->isConfigured()) {
            error_log('[GreenApiService] Missing GREENAPI_ID_INSTANCE / GREENAPI_API_TOKEN');
            return false;
        }

        $url = "{$this->apiUrl}/waInstance{$this->idInstance}/sendMessage/{$this->apiTokenInstance}";
        $payload = json_encode(['chatId' => $chatId, 'message' => $message], JSON_UNESCAPED_UNICODE);

        return $this->post($url, $payload);
    }
    /**
     * فحص إذا رقم معين (بصيغة دولية بدون +) مسجل فعلياً على واتساب.
     */
    public function checkWhatsapp(string $internationalPhone): bool
    {
        if (!$this->isConfigured()) {
            return false;
        }

        $url = "{$this->apiUrl}/waInstance{$this->idInstance}/checkWhatsapp/{$this->apiTokenInstance}";
        $payload = json_encode(['phoneNumber' => $internationalPhone], JSON_UNESCAPED_UNICODE);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 15,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        error_log("[GreenApiService] checkWhatsapp phone={$internationalPhone} httpCode={$httpCode} response=" . substr((string) $response, 0, 300));

        if ($httpCode < 200 || $httpCode >= 300 || $response === false) {
            return false;
        }

        $data = json_decode($response, true);
        return (bool) ($data['existsWhatsapp'] ?? false);
    }

    /**
     * يحول رقم محلي فلسطيني (05XXXXXXXX) إلى chatId صحيح لواتساب،
     * بتجربة بادئة 972 ثم 970 (الأرقام الفلسطينية ممكن تكون مسجلة
     * على واتساب تحت أي منهم حسب الشركة/الشبكة).
     * بيرجع null إذا الرقم مش موجود على واتساب بأي من الصيغتين.
     */
    public function resolveChatId(string $localPhone): ?string
    {
        $digitsOnly = preg_replace('/\D/', '', $localPhone);
        $withoutLeadingZero = preg_replace('/^0/', '', $digitsOnly);

        foreach (['972', '970'] as $countryCode) {
            $candidate = $countryCode . $withoutLeadingZero;
            if ($this->checkWhatsapp($candidate)) {
                return $candidate . '@c.us';
            }
        }

        return null;
    }

    /**
     * إرسال ملف (صورة) عبر رابط عام (URL)
     */
    public function sendFileByUrl(string $chatId, string $fileUrl, string $fileName, string $caption = ''): bool
    {
        if (!$this->isConfigured()) {
            error_log('[GreenApiService] Missing GREENAPI_ID_INSTANCE / GREENAPI_API_TOKEN');
            return false;
        }

        $url = "{$this->apiUrl}/waInstance{$this->idInstance}/sendFileByUrl/{$this->apiTokenInstance}";
        $payload = json_encode([
            'chatId'   => $chatId,
            'urlFile'  => $fileUrl,
            'fileName' => $fileName,
            'caption'  => $caption,
        ], JSON_UNESCAPED_UNICODE);

        return $this->post($url, $payload);
    }

private function post(string $url, string $payload): bool
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 15,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error    = curl_error($ch);
    curl_close($ch);

    // DEBUG مؤقت — بيسجل كل استدعاء (نجح أو فشل) عشان نشوف شو فعلياً بيرجع من Green API
    error_log("[GreenApiService] DEBUG httpCode={$httpCode} url={$url} response=" . substr((string) $response, 0, 500) . ($error ? " curlError={$error}" : ''));

    if ($httpCode < 200 || $httpCode >= 300) {
        error_log("[GreenApiService] Request failed httpCode={$httpCode} url={$url} curlError={$error} response=" . substr((string) $response, 0, 500));
        return false;
    }

    return true;
}
public function sendFileByUpload(string $chatId, string $filePath, string $fileName, string $caption = ''): bool
{
    if (!$this->isConfigured()) {
        error_log('[GreenApiService] Missing GREENAPI_ID_INSTANCE / GREENAPI_API_TOKEN');
        return false;
    }

    if (!is_file($filePath)) {
        error_log("[GreenApiService] File not found for upload: {$filePath}");
        return false;
    }

    $url = "{$this->apiUrl}/waInstance{$this->idInstance}/sendFileByUpload/{$this->apiTokenInstance}";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => [
            'chatId'  => $chatId,
            'file'    => new \CURLFile($filePath, 'image/jpeg', $fileName),
            'caption' => $caption,
        ],
        CURLOPT_TIMEOUT => 30,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error    = curl_error($ch);
    curl_close($ch);

    error_log("[GreenApiService] DEBUG sendFileByUpload httpCode={$httpCode} response=" . substr((string) $response, 0, 500) . ($error ? " curlError={$error}" : ''));

    if ($httpCode < 200 || $httpCode >= 300) {
        error_log("[GreenApiService] sendFileByUpload failed httpCode={$httpCode} curlError={$error}");
        return false;
    }

    return true;
}

}

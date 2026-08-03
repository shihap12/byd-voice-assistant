<?php

declare(strict_types=1);

namespace BYD\Services;

use RuntimeException;

/**
 * FeedbackScoringService
 * يحلل رأي العميل النصي (اللي انقال آخر المكالمة) عن طريق Gemini
 * ويرجع درجة رضا من ٠ لـ ١٠٠ مع ملخص قصير لسبب الدرجة.
 */
final class FeedbackScoringService
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

    /**
     * @return array{score:int, summary:string}
     */
    public function score(string $feedbackText): array
    {
        $feedbackText = trim($feedbackText);
        if ($feedbackText === '') {
            return ['score' => 0, 'summary' => 'لا يوجد رأي مسجل'];
        }

        $prompt = <<<PROMPT
أنت محلل مشاعر متخصص بخدمة العملاء. حلل الرأي التالي لعميل بعد مكالمة مع مساعد صوتي لوكالة سيارات BYD بفلسطين.
الرأي مكتوب بلهجة فلسطينية عامية (ممكن يكون قصير جداً أو حتى كلمة وحدة).

أعطِ درجة رضا من ٠ إلى ١٠٠ حسب مدى رضا العميل عن تجربته (١٠٠ = رضا تام، ٠ = استياء تام).
وأعطِ ملخص عربي قصير جداً (جملة واحدة، أقل من عشرين كلمة) يوضح سبب الدرجة.

أعد فقط JSON خام بدون أي markdown أو شرح، بهذا الشكل بالضبط:
{"score": 85, "summary": "العميل عبر عن رضاه عن سرعة الرد ووضوح المعلومات"}

رأي العميل:
"{$feedbackText}"
PROMPT;

        $payload = [
            'contents' => [[
                'parts' => [['text' => $prompt]]
            ]],
            'generationConfig' => [
                'temperature'     => 0.1,
                'maxOutputTokens' => 300,
            ]
        ];

        $ch = curl_init("{$this->apiUrl}?key={$this->apiKey}");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 30,
        ]);

        $response = curl_exec($ch);
        $curlErr  = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($curlErr || $httpCode !== 200) {
            error_log("[FeedbackScoring] Gemini call failed. HTTP: {$httpCode}. Err: {$curlErr}");
            // ما بدنا نوقع المكالمة إذا فشل التحليل — رجع قيمة افتراضية آمنة
            return ['score' => 50, 'summary' => 'تعذر تحليل الرأي تلقائياً'];
        }

        $responseData = json_decode($response, true);
        $textOutput   = $responseData['candidates'][0]['content']['parts'][0]['text'] ?? '';

        $start = strpos($textOutput, '{');
        $end   = strrpos($textOutput, '}');
        if ($start === false || $end === false) {
            return ['score' => 50, 'summary' => 'تعذر تحليل الرأي تلقائياً'];
        }

        $parsed = json_decode(substr($textOutput, $start, $end - $start + 1), true);
        if (!is_array($parsed) || !isset($parsed['score'])) {
            return ['score' => 50, 'summary' => 'تعذر تحليل الرأي تلقائياً'];
        }

        $score = max(0, min(100, (int) $parsed['score']));
        $summary = trim((string) ($parsed['summary'] ?? ''));

        return ['score' => $score, 'summary' => $summary];
    }
}
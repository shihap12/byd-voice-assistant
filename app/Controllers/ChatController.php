<?php

declare(strict_types=1);

namespace BYD\Controllers;

use BYD\Models\RedisClient;
use BYD\Security\Security;
use BYD\Security\AuthMiddleware;

/**
 * ChatController - الشات النصي
 *
 * إعادة بناء كاملة (مقارنة بالنسخة القديمة اللي كانت تثق بـ session_id
 * جاي من الفرونت إند وتستخدم Gemini بدون function calling):
 *
 * 1. الجلسة صارت تُؤخذ حصراً من AuthMiddleware::getSession() — نفس الـ JWT
 *    اللي انعمل إصداره بـ SessionController::initSession() بعد الكابتشا.
 *    ما في وجود بعد الآن لـ session_id قادم من جسم الطلب.
 * 2. المحادثة (chat_history) بتنخزن جوا نفس سياق الجلسة بـ Redis
 *    (نفس المفتاح اللي بيستخدمه SessionController: context:{sessionId})
 *    بدل مفتاح منفصل (webchat:history:*) كان قبل هيك.
 *    هيك صار عندك سياق واحد موحّد للجلسة (car_focus، query_count،
 *    المحادثة النصية)، وبينتهي تلقائياً مع نفس صلاحية الجلسة (JWT_TTL).
 * 3. بدل ما نبعت للـ AI نص ثابت فيه بيانات السيارات، صرنا نستخدم
 *    Gemini function calling مع نفس تعريفات الأدوات وتنفيذها بالضبط
 *    اللي Vapi بيستخدمها (VapiWebhookController::getGeminiToolDeclarations
 *    و executeTool). صفر تكرار منطق بين الصوت والنص.
 */
final class ChatController
{
    private RedisClient $redis;
    private VapiWebhookController $tools;

    /** أقصى عدد جولات استدعاء أدوات متتالية قبل ما نرجع جواب افتراضي (حماية من لوب لا نهائي) */
    private const MAX_TOOL_HOPS = 4;

    /** أقصى عدد رسائل (لا أزواج) نحتفظ فيها بتاريخ المحادثة */
    private const HISTORY_LIMIT = 20;

    public function __construct()
    {
        $this->redis = RedisClient::getInstance();
        $this->tools = new VapiWebhookController();
    }

    public function handle(): void
    {
        // AuthMiddleware اشتغل قبل ما نوصل هون (من index.php) وتحقق من JWT + CSRF.
        // هون بس بناخد الجلسة اللي صادق عليها.
        $session = AuthMiddleware::getSession();
        if (!$session) {
            Security::jsonError('Unauthorized', 401);
        }
        $sessionId = $session->session_id;

        // Rate limiting خاص بالشات، بالإضافة لللي بيصير أصلاً على مستوى الجلسة بالميدلوير
        Security::checkRateLimit("chat:{$sessionId}");

        $raw     = file_get_contents('php://input');
        $body    = json_decode($raw, true) ?? [];
        $message = Security::sanitize($body['message'] ?? '');

        if (!$message) {
            Security::jsonError('Missing message');
        }

        // نفس سياق الجلسة الموحّد (اتعمل إنشاؤه بـ SessionController::initSession)
        $context = $this->redis->getContext($sessionId) ?? [
            'session_id'  => $sessionId,
            'started_at'  => time(),
            'car_focus'   => null,
            'query_count' => 0,
        ];
        $history = $context['chat_history'] ?? [];
        $now = time();
        $history = array_filter($history, function ($msg) use ($now) {
            return !isset($msg['timestamp']) || ($now - $msg['timestamp']) <= 1800;
        });

        $reply = $this->runConversation($message, $history, $sessionId, $context);

        $history[] = ['role' => 'user',  'text' => $message, 'timestamp' => $now];
        $history[] = ['role' => 'model', 'text' => $reply,   'timestamp' => $now];
        if (count($history) > self::HISTORY_LIMIT) {
            $history = array_slice($history, -self::HISTORY_LIMIT);
        }
        $context['chat_history'] = array_values($history);
        $context['last_chat_at'] = $now;

        $latestImages = $context['latest_images'] ?? null;
        unset($context['latest_images']);

        // صلاحية سياق الشات 30 دقيقة (1800 ثانية) لمتابعة الصوت
        $ttl = 1800;
        $this->redis->setContext($sessionId, $context, $ttl);

        // الميدلوير بيكون ولّد CSRF token جديد بعد ما استهلك القديم (one-time use)
        $newCsrf = AuthMiddleware::getFreshCsrfToken();

        header('Content-Type: application/json');
        echo json_encode([
            'reply'      => $reply,
            'session_id' => $sessionId,
            'csrf_token' => $newCsrf,
            'images'     => $latestImages['images'] ?? null,
            'car_model'  => $latestImages['model_name'] ?? null,
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * يشغّل حلقة Gemini function-calling باستخدام نفس أدوات Vapi بالضبط،
     * لحد ما يوصل لجواب نهائي (بدون استدعاء أداة) أو ينتهي عدد الجولات.
     */
    private function runConversation(string $message, array $history, string $sessionId, array &$context): string
    {
        $systemPrompt = $this->tools->buildChatSystemPrompt($sessionId);
        $toolsPayload = $this->tools->getGeminiToolDeclarations();

        $contents = [];
        foreach ($history as $h) {
            $contents[] = ['role' => $h['role'], 'parts' => [['text' => $h['text']]]];
        }
        $contents[] = ['role' => 'user', 'parts' => [['text' => $message]]];

        $apiKey = $_ENV['GEMINI_API_KEY'] ?? '';
        $url    = "https://generativelanguage.googleapis.com/v1beta/models/gemini-3.1-flash-lite:generateContent?key={$apiKey}";

        for ($hop = 0; $hop < self::MAX_TOOL_HOPS; $hop++) {
            $payload = json_encode([
                'system_instruction' => ['parts' => [['text' => $systemPrompt]]],
                'contents'           => $contents,
                'tools'              => $toolsPayload,
                'generationConfig'   => [
                    'temperature'     => 0.15,
                    'maxOutputTokens' => 1500,
                    'thinkingConfig'  => ['thinkingLevel' => 'low'],
                ],
            ]);

            $response = $this->callGemini($url, $payload);

            $parts = $response['candidates'][0]['content']['parts'] ?? [];

            $functionCall     = null;
            $thoughtSignature = null;
            $textOut          = '';
            foreach ($parts as $part) {
                if (isset($part['functionCall'])) {
                    $functionCall     = $part['functionCall'];
                    $thoughtSignature = $part['thoughtSignature'] ?? null;
                } elseif (isset($part['text'])) {
                    $textOut .= $part['text'];
                }
            }

            if ($functionCall === null) {
                $textOut = trim($textOut);
                if ($textOut === '') {
                    $finishReason = $response['candidates'][0]['finishReason'] ?? 'unknown';
                    error_log("[ChatController] Empty response from Gemini. finishReason={$finishReason} raw=" . json_encode($response, JSON_UNESCAPED_UNICODE));
                }
                return $textOut !== '' ? $textOut : 'عذراً، ما قدرت أجاوب هلق. جرب تسأل بطريقة تانية.';
            }

            $fnName = $functionCall['name'] ?? '';
            $fnArgs = $functionCall['args'] ?? [];

            // نفس منطق الأعمال بالضبط اللي Vapi بيستخدمه
            $result = $this->tools->executeTool($fnName, $fnArgs, $sessionId, $context);

            // args فاضية لازم تترمّز كـ {} مش [] عشان Gemini يقبلها بالجولة الجاية
            $argsForReplay = $functionCall['args'] ?? [];
            if (empty($argsForReplay)) {
                $argsForReplay = new \stdClass();
            }

            // ثبّت استدعاء الأداة ونتيجتها بالمحادثة قبل الجولة الجاية.
            // موديلات Gemini 3.x بترجع thoughtSignature مرفقة مع الـ functionCall،
            // ولازم نرجّعها بالضبط متل ما اجت وإلا Gemini بيرفض الطلب بـ 400
            // ("Function call is missing a thought_signature in functionCall parts").
            $modelPart = [
                'functionCall' => [
                    'name' => $fnName,
                    'args' => $argsForReplay,
                ],
            ];
            if ($thoughtSignature !== null) {
                $modelPart['thoughtSignature'] = $thoughtSignature;
            }

            $contents[] = [
                'role'  => 'model',
                'parts' => [$modelPart],
            ];
            $contents[] = [
                'role'  => 'function',
                'parts' => [[
                    'functionResponse' => [
                        'name'     => $fnName,
                        'response' => $result,
                    ],
                ]],
            ];
        }

        return 'في ضغط على النظام هلق، جرب تسأل مرة تانية بعد شوي.';
    }

    private function callGemini(string $url, string $payload, int $retries = 2): array
    {
        $response = null;
        $httpCode = 0;

        for ($i = 0; $i <= $retries; $i++) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $payload,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                CURLOPT_TIMEOUT        => 20,
            ]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode === 200) {
                break;
            }

            // إذا كان الخطأ 429 (Rate Limit) أو 503 (Overloaded)، ننتظر قليلاً ونحاول مرة أخرى
            if (in_array($httpCode, [429, 503]) && $i < $retries) {
                error_log("[ChatController] Gemini rate limit or overloaded ($httpCode). Retrying in 2 seconds... (Attempt " . ($i + 1) . ")");
                sleep(2);
                continue;
            }
            break;
        }

        if ($httpCode !== 200 || !$response) {
            $debugBody = substr((string) $response, 0, 800);
            error_log("[ChatController] Gemini error httpCode={$httpCode} body=" . $debugBody);
            Security::jsonError("DEBUG httpCode={$httpCode} body={$debugBody}", 503);
        }

        return json_decode($response, true) ?? [];
    }
}
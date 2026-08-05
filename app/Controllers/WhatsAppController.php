<?php

declare(strict_types=1);

namespace BYD\Controllers;

use BYD\Models\RedisClient;
use BYD\Security\Security;
use BYD\Services\GreenApiService;

/**
 * WhatsAppController - يستقبل رسائل واتساب عبر Green API webhook
 * ويرد بنفس شخصية وأدوات الشات النصي (ChatController).
 *
 * مكان هذا الملف: app/Controllers/WhatsAppController.php
 *
 * FIX (متعدد الأدوات بنفس الرد): كان الكود يتعامل مع أول functionCall فقط
 * لو Gemini رجع أكثر من طلب أداة بنفس الرد (مثلاً get_car_images +
 * get_car_specifications بنفس الرسالة)، فباقي الطلبات كانت تضيع وبيصير
 * mismatch بمحادثة Gemini، وبيرجع رد فاضي → "عذراً ما قدرت أجاوب".
 * الحل: تجميع كل الـ functionCalls الموجودة بنفس الرد، تنفيذهم كلهم،
 * وإرجاع functionResponse لكل واحد فيهم بنفس الدور.
 */
final class WhatsAppController
{
    private RedisClient $redis;
    private VapiWebhookController $tools;
    private GreenApiService $greenApi;

    private const MAX_TOOL_HOPS = 4;
    private const HISTORY_LIMIT = 20;
    private const CONTEXT_TTL   = 21600; // 6 ساعات — محادثات واتساب أطول عمراً من الشات

    /** حد أقصى لحجم أي وسائط (صورة/صوت) نحمّلها من Green API — حماية من ملفات ضخمة */
    private const MAX_MEDIA_BYTES = 15 * 1024 * 1024; // 15 MB

    public function __construct()
    {
        $this->redis    = RedisClient::getInstance();
        $this->tools    = new VapiWebhookController();
        $this->tools->channel = 'whatsapp';
        $this->greenApi = new GreenApiService();
    }

    public function handle(): void
    {
        // حماية بسيطة: رابط الـ webhook لازم يحتوي ?token=السر المتفق عليه
        $expectedToken = (string) ($_ENV['GREENAPI_WEBHOOK_TOKEN'] ?? '');
        if ($expectedToken !== '' && (string) ($_GET['token'] ?? '') !== $expectedToken) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['status' => 'unauthorized']);
            return;
        }

        $raw     = file_get_contents('php://input');
        $payload = json_decode($raw, true);

        error_log("[WhatsAppController] DEBUG entry. rawLength=" . strlen($raw) . " validJson=" . (is_array($payload) ? 'yes' : 'NO'));

        if (!is_array($payload)) {
            $this->jsonResponse(['status' => 'ignored']);
            return;
        }

        $webhookType = $payload['typeWebhook'] ?? '';
        error_log("[WhatsAppController] DEBUG webhookType={$webhookType}");

        if ($webhookType !== 'incomingMessageReceived') {
            error_log("[WhatsAppController] DEBUG STOPPED HERE: webhookType is not incomingMessageReceived");
            $this->jsonResponse(['status' => 'ignored']);
            return;
        }

        // Idempotency — تجنب معالجة نفس الرسالة مرتين
        $messageId = (string) ($payload['idMessage'] ?? md5($raw));
        $lockKey   = "wa_webhook_processed:{$messageId}";
        if ($this->redis->exists($lockKey)) {
            error_log("[WhatsAppController] DEBUG STOPPED HERE: already_processed. messageId={$messageId}");
            $this->jsonResponse(['status' => 'already_processed']);
            return;
        }
        $this->redis->set($lockKey, '1', 3600);

        $senderData = $payload['senderData'] ?? [];
        $chatId     = (string) ($senderData['chatId'] ?? '');
        $senderName = (string) ($senderData['senderName'] ?? '');

        error_log("[WhatsAppController] DEBUG chatId={$chatId} senderName={$senderName}");

        // تجاهل رسائل المجموعات ورسائل بدون chatId
        if ($chatId === '' || str_ends_with($chatId, '@g.us')) {
            error_log("[WhatsAppController] DEBUG STOPPED HERE: ignored_group_or_empty");
            $this->jsonResponse(['status' => 'ignored_group_or_empty']);
            return;
        }

        $messageData = $payload['messageData'] ?? [];
        $messageType = $messageData['typeMessage'] ?? '';

        error_log("[WhatsAppController] DEBUG messageType={$messageType}");

        $text               = '';
        $mediaPart           = null; // part بصيغة Gemini inlineData (صورة أو صوت)، لو موجود
        $historyPlaceholder = null;  // النص البديل يلي بينخزن بتاريخ المحادثة بدل محتوى الوسائط الخام

        if ($messageType === 'textMessage') {
            $text = trim((string) ($messageData['textMessageData']['textMessage'] ?? ''));
        } elseif ($messageType === 'extendedTextMessage') {
            $text = trim((string) ($messageData['extendedTextMessageData']['text'] ?? ''));
        } elseif ($messageType === 'imageMessage') {
            $fileData    = $messageData['fileMessageData'] ?? [];
            $downloadUrl = (string) ($fileData['downloadUrl'] ?? '');
            $caption     = trim((string) ($fileData['caption'] ?? ''));
            $mimeType    = (string) ($fileData['mimeType'] ?? 'image/jpeg');

            $bytes = $this->downloadMedia($downloadUrl);
            if ($bytes === null) {
                error_log("[WhatsAppController] DEBUG STOPPED HERE: image_download_failed url={$downloadUrl}");
                $this->greenApi->sendMessage(
                    $chatId,
                    'وصلتني صورتك بس ما قدرت أفتحها، ممكن تبعتها مرة ثانية؟'
                );
                $this->jsonResponse(['status' => 'image_download_failed']);
                return;
            }

            $mediaPart = [
                'inlineData' => [
                    'mimeType' => $mimeType,
                    'data'     => base64_encode($bytes),
                ],
            ];

            $text = $caption !== ''
                ? $caption
                : 'بعتلك صورة سيارة. حددي اسم موديل BYD الأقرب من شكل السيارة بالصورة، وتأكدي من وجوده فعلياً عن طريق الأداة المناسبة قبل ما تجاوبيني هل هي موجودة عندكم أو لأ.';

            $historyPlaceholder = $caption !== '' ? "[صورة] {$caption}" : '[صورة أرسلها العميل]';
        } elseif ($messageType === 'audioMessage') {
            $fileData    = $messageData['fileMessageData'] ?? [];
            $downloadUrl = (string) ($fileData['downloadUrl'] ?? '');
            $mimeType    = (string) ($fileData['mimeType'] ?? 'audio/ogg');
            // Gemini ما بيقبل باراميترات إضافية زي "; codecs=opus" جوا الـ mimeType
            $mimeType = trim(explode(';', $mimeType)[0]);

            $bytes = $this->downloadMedia($downloadUrl);
            if ($bytes === null) {
                error_log("[WhatsAppController] DEBUG STOPPED HERE: audio_download_failed url={$downloadUrl}");
                $this->greenApi->sendMessage(
                    $chatId,
                    'وصلتني رسالتك الصوتية بس ما قدرت أسمعها، ممكن تبعتها مرة ثانية أو تكتبلي سؤالك نصي؟'
                );
                $this->jsonResponse(['status' => 'audio_download_failed']);
                return;
            }

            $mediaPart = [
                'inlineData' => [
                    'mimeType' => $mimeType,
                    'data'     => base64_encode($bytes),
                ],
            ];

            $text = 'استمعي للرسالة الصوتية المرفقة وجاوبي العميل عادي متل لو كتب نفس الكلام نصاً، بدون أي إشارة إنك سمعتِ رسالة صوتية.';
            $historyPlaceholder = '[رسالة صوتية من العميل]';
        }

        if ($text === '' && $mediaPart === null) {
            error_log("[WhatsAppController] DEBUG STOPPED HERE: unsupported_message_type messageType={$messageType}");
            // نوع رسالة غير مدعوم حالياً (فيديو/ملف/موقع/جهة اتصال...)
            $this->greenApi->sendMessage(
                $chatId,
                'وصلتني رسالتك، بس حالياً بقدر أساعدك بالرسائل النصية أو الصور أو الرسائل الصوتية. اكتبلي سؤالك، أو ابعتلي صورة سيارة أو رسالة صوتية وأنا جاهزة أساعدك.'
            );
            $this->jsonResponse(['status' => 'unsupported_message_type']);
            return;
        }

        error_log("[WhatsAppController] DEBUG passed all early checks. text=" . mb_substr($text, 0, 100) . " hasMedia=" . ($mediaPart !== null ? 'yes' : 'no'));

        $text        = Security::sanitize($text);
        $phoneNumber = explode('@', $chatId)[0];
        $sessionId   = "whatsapp:{$phoneNumber}";

        // تأكيد وجود سجل عميل + سجل "مكالمة" منطقي، عشان نقدر نستخدم نفس
        // أدوات save_customer_note / save_customer_feedback الموجودة أصلاً بدون أي تعديل عليهم
        $this->ensureCustomerAndCallRecord($phoneNumber, $senderName, $sessionId);

        $context = $this->redis->getContext($sessionId) ?? [
            'session_id'  => $sessionId,
            'started_at'  => time(),
            'car_focus'   => null,
            'query_count' => 0,
        ];

        $history = $context['chat_history'] ?? [];
        $now     = time();
        $history = array_filter($history, function ($msg) use ($now) {
            return !isset($msg['timestamp']) || ($now - $msg['timestamp']) <= self::CONTEXT_TTL;
        });

        error_log("[WhatsAppController] DEBUG calling Gemini now...");
        $reply = $this->runConversation($text, $history, $sessionId, $context, $mediaPart);
        error_log("[WhatsAppController] DEBUG Gemini returned reply: " . mb_substr($reply, 0, 200));

        $history[] = ['role' => 'user',  'text' => $historyPlaceholder ?? $text, 'timestamp' => $now];
        $history[] = ['role' => 'model', 'text' => $reply,                       'timestamp' => $now];
        if (count($history) > self::HISTORY_LIMIT) {
            $history = array_slice($history, -self::HISTORY_LIMIT);
        }
        $context['chat_history'] = array_values($history);
        $context['last_chat_at'] = $now;

        $latestImages = $context['latest_images'] ?? null;
        unset($context['latest_images']);

        $this->redis->setContext($sessionId, $context, self::CONTEXT_TTL);

        // إرسال الرد النصي
        error_log("[WhatsAppController] DEBUG about to call greenApi->sendMessage");
        $sendResult = $this->greenApi->sendMessage($chatId, $reply);
        error_log("[WhatsAppController] DEBUG sendMessage result=" . ($sendResult ? 'true' : 'FALSE'));

        // إرسال الصور (لو الأداة get_car_images رجعت صور) كملفات وسائط منفصلة
        if (!empty($latestImages['images'])) {
            $projectRoot = dirname(__DIR__, 2);
            $modelName   = $latestImages['model_name'] ?? '';
            foreach (array_slice($latestImages['images'], 0, 5) as $img) {
                $originalPath = $img['url'] ?? '';
                $jpgUrlPath = (new \BYD\Services\ImageConverterService())->ensureJpgVersion($originalPath);
                if ($jpgUrlPath === null) {
                    error_log("[WhatsAppController] Skipping image, conversion failed: {$originalPath}");
                    continue;
                }

                $jpgAbsPath = $projectRoot . $jpgUrlPath;
                $baseName   = pathinfo($img['file_name'] ?? 'car', PATHINFO_FILENAME);
                $fileName   = $baseName . '.jpg';

                $this->greenApi->sendFileByUpload($chatId, $jpgAbsPath, $fileName, $modelName);
            }
        }

        $this->jsonResponse(['status' => 'replied']);
    }

    /**
     * يحمّل ملف وسائط (صورة أو صوت) من رابط Green API ويرجع بايتس الملف الخام.
     * يرجع null عند أي فشل (شبكة، حجم زايد عن الحد، كود HTTP غير ناجح).
     */
    private function downloadMedia(string $url): ?string
    {
        if ($url === '') {
            return null;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 20,
        ]);
        $data     = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($error || $data === false || $httpCode < 200 || $httpCode >= 300) {
            error_log("[WhatsAppController] Media download failed url={$url} httpCode={$httpCode} curlError={$error}");
            return null;
        }

        if (strlen($data) > self::MAX_MEDIA_BYTES) {
            error_log('[WhatsAppController] Media too large, skipping. size=' . strlen($data));
            return null;
        }

        return $data;
    }

    /**
     * ينشئ/يحدّث سجل customers + سجل calls منطقي بربط call_id = sessionId
     * عشان أدوات save_customer_note و save_customer_feedback (اللي بتعتمد
     * على جدول calls لجلب بيانات العميل) تشتغل بدون أي تعديل عليها.
     */
    private function ensureCustomerAndCallRecord(string $phoneNumber, string $senderName, string $sessionId): void
    {
        $db = \BYD\Models\Database::getInstance();

        $customer = $db->queryOne('SELECT id FROM customers WHERE phone_number = ?', [$phoneNumber]);
        if (!$customer) {
            $db->execute(
                'INSERT INTO customers (phone_number, name) VALUES (?, ?)',
                [$phoneNumber, $senderName !== '' ? $senderName : null]
            );
            $customer = $db->queryOne('SELECT id FROM customers WHERE phone_number = ?', [$phoneNumber]);
        }

        $customerId = $customer ? (int) $customer['id'] : null;

        $db->execute(
            "INSERT INTO calls (call_id, customer_id, session_id, status, started_at)
             VALUES (?, ?, ?, 'whatsapp', NOW())
             ON DUPLICATE KEY UPDATE status = 'whatsapp', updated_at = NOW()",
            [$sessionId, $customerId, $sessionId]
        );
    }

    /**
     * @param array|null $mediaPart إذا موجود، بيتضاف كـ inline data (صورة/صوت)
     *                              لجولة المحادثة الحالية فقط، قبل نص $message.
     */
    private function runConversation(
        string $message,
        array $history,
        string $sessionId,
        array &$context,
        ?array $mediaPart = null
    ): string {
        $systemPrompt = $this->tools->buildWhatsAppSystemPrompt($sessionId);
        $toolsPayload = $this->tools->getGeminiToolDeclarations();

        $contents = [];
        foreach ($history as $h) {
            $contents[] = ['role' => $h['role'], 'parts' => [['text' => $h['text']]]];
        }

        // الجولة الحالية: لو في وسائط (صورة/صوت)، تنضاف قبل النص كـ part منفصل
        $userParts = [];
        if ($mediaPart !== null) {
            $userParts[] = $mediaPart;
        }
        $userParts[] = ['text' => $message];
        $contents[]  = ['role' => 'user', 'parts' => $userParts];

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
            $geminiHttpCode = $response['_httpCode'] ?? 0;
            unset($response['_httpCode']);

            // ─────────────────────────────────────────────────────────
            // FALLBACK: لو Gemini رجع 400 (INVALID_ARGUMENT) بأول hop
            // (يعني مع التاريخ)، غالباً السبب تاريخ محادثة فاسد بـ Redis.
            // نمسح التاريخ ونعيد المحاولة بالرسالة الحالية فقط.
            // ─────────────────────────────────────────────────────────
            if ($geminiHttpCode === 400 && $hop === 0 && !empty($history)) {
                error_log("[WhatsAppController] Got 400 with history, retrying WITHOUT history...");
                $context['chat_history'] = [];
                $contents = [];
                $contents[] = ['role' => 'user', 'parts' => $userParts];
                continue;
            }

            $parts = $response['candidates'][0]['content']['parts'] ?? [];

            // ─────────────────────────────────────────────────────────
            // FIX: تجميع كل الـ functionCalls الموجودة بنفس الرد (مش أول
            // وحدة بس). لو Gemini طلب أكثر من أداة بنفس الرسالة (مثلاً
            // العميل قال "بدي صور السيارة وأبعادها")، لازم ننفذهم كلهم
            // ونرجع functionResponse لكل واحد فيهم، وإلا بيصير mismatch
            // بمحادثة Gemini وبيرجع رد فاضي بالدورة الجاية.
            // ─────────────────────────────────────────────────────────
            $functionCallParts = [];
            $textOut            = '';

            foreach ($parts as $part) {
                if (isset($part['functionCall'])) {
                    // Gemini 3 بيطلب thoughtSignature إلزامياً بكل functionCall —
                    // لو مش راجعة (بيصير أحياناً بـ flash-lite)، نحط placeholder
                    // ثابت بدل ما نتركها فاضية، لأنه غيابها بيسبب 400 INVALID_ARGUMENT.
                    if (!isset($part['thoughtSignature'])) {
                        $part['thoughtSignature'] = 'context_engine_is_ok_to_proceed_without_signature';
                    }
                    $functionCallParts[] = $part;
                } elseif (isset($part['text'])) {
                    $textOut .= $part['text'];
                }
            }

            if (empty($functionCallParts)) {
                $textOut = trim($textOut);
                if ($textOut === '') {
                    $finishReason = $response['candidates'][0]['finishReason'] ?? 'unknown';
                    error_log("[WhatsAppController] Empty response from Gemini. finishReason={$finishReason} fullResponse=" . json_encode($response, JSON_UNESCAPED_UNICODE));
                }
                return $textOut !== '' ? $textOut : 'عذراً، ما قدرت أجاوب هلق. جرب تسأل بطريقة تانية.';
            }

            // رد الموديل الكامل بكل الـ functionCalls يلي طلبها بنفس الدور،
            // كتلة parts واحدة (مش عدة أدوار model منفصلة).
            // مهم: لازم ننظف الـ args عشان PHP json_encode بيحول
            // الـ array الفاضي [] لـ JSON array بدل object {},
            // وهاد بيسبب خطأ 400 من Gemini API.
            $cleanModelParts = [];
            foreach ($functionCallParts as $fcPart) {
                $argsForReplay = $fcPart['functionCall']['args'] ?? [];
                if (empty($argsForReplay) || $argsForReplay === []) {
                    $argsForReplay = new \stdClass();
                }
                $cleanPart = [
                    'functionCall' => [
                        'name' => $fcPart['functionCall']['name'] ?? '',
                        'args' => $argsForReplay,
                    ],
                ];
                if (isset($fcPart['thoughtSignature'])) {
                    $cleanPart['thoughtSignature'] = $fcPart['thoughtSignature'];
                }
                $cleanModelParts[] = $cleanPart;
            }
            $contents[] = ['role' => 'model', 'parts' => $cleanModelParts];

            // ننفذ كل أداة بالترتيب، ونبني functionResponse مطابق لكل واحدة
            $functionResponseParts = [];
            foreach ($functionCallParts as $fcPart) {
                $fc     = $fcPart['functionCall'];
                $fnName = $fc['name'] ?? '';
                $fnArgs = $fc['args'] ?? [];

                $result = $this->tools->executeTool($fnName, $fnArgs, $sessionId, $context);

                $functionResponseParts[] = [
                    'functionResponse' => [
                        'name'     => $fnName,
                        'response' => $result,
                    ],
                ];
            }

            $contents[] = [
                'role'  => 'function',
                'parts' => $functionResponseParts,
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

            if (in_array($httpCode, [429, 503], true) && $i < $retries) {
                error_log("[WhatsAppController] Gemini rate limit/overloaded ($httpCode). Retrying...");
                sleep(2);
                continue;
            }
            break;
        }

        error_log("[WhatsAppController] DEBUG callGemini httpCode={$httpCode}");

        if ($httpCode !== 200 || !$response) {
            error_log("[WhatsAppController] Gemini error httpCode={$httpCode} body=" . substr((string) $response, 0, 800));
            return ['_httpCode' => $httpCode];
        }

        $parsed = json_decode($response, true) ?? [];
        $parsed['_httpCode'] = $httpCode;
        return $parsed;
    }

    private function jsonResponse(array $data): void
    {
        header('Content-Type: application/json');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
    }
}
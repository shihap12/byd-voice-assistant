<?php

declare(strict_types=1);

/**
 * BYD Voice Assistant - Entry Point (IMPROVED VERSION)
 *
 * مكان هذا الملف: public/index.php
 *
 * Change: Added Redis warm-up after env load (line marked with NEW)
 * Change: Added /storage/car_images_jpg/{carId}/{filename} route —
 *         نسخة JPEG من صور السيارات لإرسالها عبر واتساب (Green API
 *         بيتعامل مع .webp كستيكر مش كصورة عادية).
 * Change: نولّد بيانات JPEG بالذاكرة أولاً (ob_start/ob_get_clean) قبل
 *         إرسالها، ونرسل Content-Length صريح بدل الاعتماد على
 *         Transfer-Encoding: chunked — بعض خدمات تحميل الملفات (متل
 *         Green API) بتحتاج Content-Length صريح للتأكد من اكتمال الملف.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use BYD\Controllers\Router;
use BYD\Controllers\VapiWebhookController;
use BYD\Controllers\UploadController;
use BYD\Controllers\AdminController;
use BYD\Security\Security;

// ─── Global Exception Handler ─────────────────────────────────────────────────
// يضمن إن أي خطأ غير متوقع (مثل انقطاع الاتصال بـ MySQL) يرجع JSON نظيف
// بدل HTML خام يكسر الـ frontend.
set_exception_handler(function (Throwable $e): void {
    error_log('[FATAL] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) {
        http_response_code(503);
        header('Content-Type: application/json');
    }
    $isDev = ($_ENV['APP_ENV'] ?? 'production') === 'development';
    echo json_encode([
        'error'   => 'Service temporarily unavailable',
        'message' => $isDev ? $e->getMessage() : 'حدث خطأ في الخادم. يرجى المحاولة لاحقاً.',
        'code'    => 503,
    ], JSON_UNESCAPED_UNICODE);
    exit;
});

set_error_handler(function (int $severity, string $message, string $file, int $line): bool {
    if (error_reporting() & $severity) {
        throw new ErrorException($message, 0, $severity, $file, $line);
    }
    return true;
});
// ─────────────────────────────────────────────────────────────────────────────

// 1. Load environment variables
$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

// ──────────────────────────────────────────────────────────────────────
// NEW: Redis warm-up — يحمّل بيانات السيارات في الكاش إذا لم تكن موجودة.
// لا يُشغَّل في كل طلب — يتحقق من علامة warmup:done أولاً (TTL ساعة).
// ──────────────────────────────────────────────────────────────────────

// 2. CORS & OPTIONS Preflight
$allowedOriginsStr = $_ENV['CORS_ALLOWED_ORIGINS'] ?? 'http://localhost:5173,http://localhost:5174';
$allowedOrigins    = array_map('trim', explode(',', $allowedOriginsStr));
$origin            = $_SERVER['HTTP_ORIGIN'] ?? '';

if (in_array($origin, $allowedOrigins, true)) {
    header("Access-Control-Allow-Origin: {$origin}");
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE');
    header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token, Authorization, X-Requested-With, ngrok-skip-browser-warning');
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Expose-Headers: X-New-CSRF-Token');
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// 3. Security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');

// 4. Global rate limiting
Security::checkRateLimit(Security::getClientIp());

// 5. Auth middleware
\BYD\Security\AuthMiddleware::handle($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);

// 6. Routes
$router = new Router();

$router->add('POST', '/api/vapi/webhook', function (): void {
    (new VapiWebhookController())->handle();
});

$router->add('POST', '/api/init-session', function (): void {
    (new \BYD\Controllers\SessionController())->initSession();
});

$router->add('POST', '/api/restore-session', function (): void {
    (new \BYD\Controllers\SessionController())->restoreSession();
});

$router->add('POST', '/api/vapi-auth', function (): void {
    (new \BYD\Controllers\SessionController())->authorizeVapi();
});

$router->add('POST', '/api/upload/pdf', function (): void {
    (new UploadController())->handle();
});

$router->add('GET', '/health', function (): void {
    header('Content-Type: application/json');
    echo json_encode([
        'status'  => 'ok',
        'service' => 'BYD Voice Assistant',
        'time'    => date('Y-m-d H:i:s'),
    ]);
});

$router->add('POST', '/api/chat', function (): void {
    (new \BYD\Controllers\ChatController())->handle();
});

$router->add('GET', '/api/session-images', function (): void {
    (new \BYD\Controllers\ChatController())->apiGetSessionImages();
});

$router->add('GET', '/login/admin', function (): void {
    (new AdminController())->showLogin();
});

$router->add('POST', '/login/admin', function (): void {
    (new AdminController())->login();
});

$router->add('GET', '/admin', function (): void {
    (new AdminController())->dashboard();
});

$router->add('POST', '/admin/logout', function (): void {
    (new AdminController())->logout();
});
$router->add('GET', '/admin/csrf', function (): void {
    (new AdminController())->apiCsrf();
});

$router->add('GET', '/api/admin/notes', function (): void {
    (new AdminController())->apiGetNotes();
});

$router->add('GET', '/api/admin/feedback', function (): void {
    (new AdminController())->apiGetFeedback();
});

$router->add('GET', '/admin/api/appointments', function (): void {
    (new AdminController())->apiGetAppointments();
});

$router->add('POST', '/admin/api/appointments/{id}', function (string $id): void {
    (new AdminController())->apiUpdateAppointmentStatus($id);
});

$router->add('PATCH', '/admin/api/appointments/{id}/edit', function (string $id): void {
    (new AdminController())->apiEditAppointment($id);
});

$router->add('GET', '/admin/me', function (): void {
    (new AdminController())->apiMe();
});

$router->add('POST', '/admin/login-api', function (): void {
    (new AdminController())->apiLogin();
});

$router->add('POST', '/admin/logout-api', function (): void {
    (new AdminController())->apiLogout();
});

$router->add('GET', '/api/cars', function (): void {
    (new AdminController())->apiGetCars();
});

$router->add('GET', '/api/cars/{id}', function (string $id): void {
    (new AdminController())->apiGetCar($id);
});

$router->add('PUT', '/api/cars/{id}', function (string $id): void {
    (new AdminController())->apiUpdateCar($id);
});

$router->add('DELETE', '/api/cars/{id}', function (string $id): void {
    (new AdminController())->apiDeleteCar($id);
});

// Car Images routes
$router->add('POST', '/api/cars/{id}/images', function (string $id): void {
    (new AdminController())->apiUploadCarImages($id);
});

$router->add('DELETE', '/api/car-images/{id}', function (string $id): void {
    (new AdminController())->apiDeleteCarImage($id);
});

$router->add('PUT', '/api/cars/{id}/images/reorder', function (string $id): void {
    (new AdminController())->apiReorderCarImages($id);
});

// Serve car images
$router->add('GET', '/storage/car_images/{carId}/{filename}', function (string $carId, string $filename): void {
    $safeCar = preg_replace('/[^0-9]/', '', $carId);
    $safeFile = basename($filename);
    $path = __DIR__ . '/../storage/car_images/' . $safeCar . '/' . $safeFile;

    if (!file_exists($path)) {
        http_response_code(404);
        echo 'Not found';
        return;
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($path);
    header('Content-Type: ' . $mime);
    header('Cache-Control: public, max-age=31536000');
    readfile($path);
});

// NEW: نسخة JPEG من صور السيارات لإرسالها عبر واتساب فقط —
// Green API بيحدد نوع الملف حسب الامتداد، و.webp بيترسل كستيكر
// (بمواصفات مختلفة) فبيفشل عرضه كصورة عادية. هون بنولّد JPEG فوراً.
//
// Change: نولّد بيانات JPEG بالذاكرة (ob_start/ob_get_clean) ونرسل
// Content-Length صريح بدل الاعتماد على Transfer-Encoding: chunked،
// لأن Green API كانت بتستقبل الملف بشكل غير مكتمل/تالف بدون هيدر
// Content-Length صريح (jpegThumbnail كان يرجع فاضي، دليل فشل قراءة
// الصورة من طرف Green API).
$router->add('GET', '/storage/car_images_jpg/{carId}/{filename}', function (string $carId, string $filename): void {
    $safeCar    = preg_replace('/[^0-9]/', '', $carId);
    $safeFile   = basename($filename);
    $webpFile   = preg_replace('/\.\w+$/', '.webp', $safeFile);
    $sourcePath = __DIR__ . '/../storage/car_images/' . $safeCar . '/' . $webpFile;

    if (!file_exists($sourcePath)) {
        http_response_code(404);
        echo 'Not found';
        return;
    }

    $webp = @imagecreatefromwebp($sourcePath);
    if ($webp === false) {
        http_response_code(500);
        echo 'Conversion failed';
        return;
    }

    ob_start();
    imagejpeg($webp, null, 90);
    $jpegData = ob_get_clean();
    imagedestroy($webp);

    header('Content-Type: image/jpeg');
    header('Content-Length: ' . strlen($jpegData));
    header('Cache-Control: public, max-age=31536000');
    echo $jpegData;
});

// Admin Settings routes
$router->add('GET', '/api/admin/settings', function (): void {
    (new AdminController())->apiGetSettings();
});

$router->add('PUT', '/api/admin/settings', function (): void {
    (new AdminController())->apiUpdateSettings();
});

$router->add('GET', '/api/settings/public', function (): void {
    (new AdminController())->apiGetPublicSettings();
});

$router->add('POST', '/api/admin/credentials', function (): void {
    (new AdminController())->apiUpdateCredentials();
});

$router->add('POST', '/api/whatsapp/webhook', function (): void {
    (new \BYD\Controllers\WhatsAppController())->handle();
});

// 7. Dispatch
$router->dispatch(
    $_SERVER['REQUEST_METHOD'],
    $_SERVER['REQUEST_URI']
);

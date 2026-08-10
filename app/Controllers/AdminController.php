<?php

declare(strict_types=1);

namespace BYD\Controllers;

use BYD\Models\RedisClient;
use BYD\Security\Security;
use BYD\Services\AdminAuthService;

final class AdminController
{
    private RedisClient $redis;
    private AdminAuthService $adminAuth;

    public function __construct()
    {
        $this->redis = RedisClient::getInstance();
        $this->adminAuth = new AdminAuthService();
    }

    public function showLogin(): void
    {
        $this->startSession();

        if ($this->getAuthenticatedAdmin() !== null) {
            $this->redirect($this->url('/admin'));
        }

        $csrfToken = Security::generateCsrfToken(session_id());
        $this->renderLoginPage($csrfToken);
    }

    public function login(): void
    {
        $this->startSession();

        $csrfToken = (string) ($_POST['csrf_token'] ?? '');
        if (!Security::validateCsrfToken(session_id(), $csrfToken)) {
            http_response_code(403);
            $newToken = Security::generateCsrfToken(session_id());
            $this->renderLoginPage($newToken, 'Invalid or expired CSRF token. Please try again.');
            return;
        }

        $rawEmail = (string) ($_POST['email'] ?? '');
        $email = mb_strtolower(trim($rawEmail));
        $password = (string) ($_POST['password'] ?? '');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
            http_response_code(422);
            $newToken = Security::generateCsrfToken(session_id());
            $this->renderLoginPage($newToken, 'Please provide a valid email and password.');
            return;
        }

        $ip = Security::getClientIp();
        $emailHash = hash('sha256', $email !== '' ? $email : 'unknown');
        $maxAttempts = (int) ($_ENV['ADMIN_LOGIN_RATE_LIMIT_MAX'] ?? 5);
        $windowSeconds = (int) ($_ENV['ADMIN_LOGIN_RATE_LIMIT_WINDOW'] ?? 300);

        $allowed = $this->redis->tokenBucket("admin-login:{$ip}:{$emailHash}", $maxAttempts, $windowSeconds);
        if (!$allowed) {
            http_response_code(429);
            header('Retry-After: ' . $windowSeconds);
            $newToken = Security::generateCsrfToken(session_id());
            $this->renderLoginPage($newToken, 'Too many login attempts. Please try again later.');
            return;
        }

        if (!$this->adminAuth->adminExists()) {
            http_response_code(500);
            $newToken = Security::generateCsrfToken(session_id());
            $this->renderLoginPage($newToken, 'No admin user found in database.');
            return;
        }

        $user = $this->adminAuth->verifyCredentials($email, $password);
        if ($user === null) {
            http_response_code(401);
            $newToken = Security::generateCsrfToken(session_id());
            $this->renderLoginPage($newToken, 'Invalid email or password.');
            return;
        }

        session_regenerate_id(true);

        $ip = Security::getClientIp();
        $userAgent = (string) ($_SERVER['HTTP_USER_AGENT'] ?? 'unknown');
        $this->issueAndSetAdminCookies((int) $user['id'], $ip, $userAgent);

        $this->redirect($this->url('/admin'));
    }

    public function apiGetNotes(): void
    {
        $this->startSession();
        if ($this->getAuthenticatedAdmin() === null) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            return;
        }

        $cacheKey = 'cache:admin:notes';
        $cached = $this->redis->get($cacheKey);
        if ($cached !== null) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'notes' => $cached], JSON_UNESCAPED_UNICODE);
            return;
        }

        $db = \BYD\Models\Database::getInstance();
        $notes = $db->query(
            'SELECT n.id, n.call_id, n.note_text, n.created_at,
                    COALESCE(n.customer_name, c.name) AS customer_name,
                    COALESCE(n.phone_number, c.phone_number) AS phone_number
             FROM customer_notes n
             LEFT JOIN customers c ON c.id = n.customer_id
             ORDER BY n.created_at DESC
             LIMIT 200'
        );

        $this->redis->set($cacheKey, $notes, 31536000); // Cache for 1 year

        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'notes' => $notes], JSON_UNESCAPED_UNICODE);
    }

    public function apiGetFeedback(): void
    {
        $this->startSession();
        if ($this->getAuthenticatedAdmin() === null) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            return;
        }

        $cacheKey = 'cache:admin:feedback';
        $cached = $this->redis->get($cacheKey);
        if ($cached !== null) {
            header('Content-Type: application/json');
            echo json_encode($cached, JSON_UNESCAPED_UNICODE);
            return;
        }

        $db = \BYD\Models\Database::getInstance();

        $feedback = $db->query(
            'SELECT f.id, f.call_id, f.feedback_text, f.sentiment_score, f.sentiment_summary, f.created_at,
                    c.name AS customer_name, c.phone_number
             FROM call_feedback f
             LEFT JOIN customers c ON c.id = f.customer_id
             ORDER BY f.created_at DESC
             LIMIT 200'
        );

        $stats = $db->queryOne(
            'SELECT
                 COUNT(*) AS total_feedback,
                 ROUND(AVG(sentiment_score), 1) AS avg_score,
                 SUM(CASE WHEN sentiment_score >= 70 THEN 1 ELSE 0 END) AS positive_count,
                 SUM(CASE WHEN sentiment_score < 40 THEN 1 ELSE 0 END) AS negative_count
             FROM call_feedback'
        );

        $result = [
            'success'  => true,
            'feedback' => $feedback,
            'stats'    => $stats ?: ['total_feedback' => 0, 'avg_score' => 0, 'positive_count' => 0, 'negative_count' => 0],
        ];

        $this->redis->set($cacheKey, $result, 31536000); // Cache for 1 year

        header('Content-Type: application/json');
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
    }

    // ─────────────────────────────────────────────────────────
    // Appointments (تبويب المواعيد)
    // ─────────────────────────────────────────────────────────

    /**
     * قائمة كل المواعيد مع فلاتر اختيارية عبر query string:
     * ?status=scheduled|cancelled|completed  &from=YYYY-MM-DD  &to=YYYY-MM-DD
     */
public function apiGetAppointments(): void
{
    $this->startSession();
    if ($this->getAuthenticatedAdmin() === null) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        return;
    }

    header('Content-Type: application/json');

    $appointmentModel = new \BYD\Models\AppointmentModel();

    // تحويل أي موعد فات وقته من scheduled → missed قبل ما نرجع القائمة
    $missedCount = $appointmentModel->autoMarkMissed();
    if ($missedCount > 0) {
        $this->redis->delete('cache:admin:appointments');
    }

    $status = trim((string) ($_GET['status'] ?? ''));
    $from   = trim((string) ($_GET['from'] ?? ''));
    $to     = trim((string) ($_GET['to'] ?? ''));

    $filters = [];
    if ($status !== '' && in_array($status, ['scheduled', 'cancelled', 'completed', 'missed'], true)) {
        $filters['status'] = $status;
    }
        if ($from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            $filters['from'] = $from;
        }
        if ($to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $filters['to'] = $to;
        }

        // الكاش فقط للاستعلام الافتراضي (بدون فلاتر) — نفس نمط باقي تبويبات الأدمن
        $cacheKey = 'cache:admin:appointments';
        if (empty($filters)) {
            $cached = $this->redis->get($cacheKey);
            if ($cached !== null) {
                $cached = array_map(function ($appt) {
                    if (isset($appt['id'])) {
                        $appt['id'] = (string) $appt['id'];
                    }
                    return $appt;
                }, $cached);
                echo json_encode(['success' => true, 'appointments' => $cached], JSON_UNESCAPED_UNICODE);
                return;
            }
        }

        $appointments = $appointmentModel->getAll($filters);

        $appointments = array_map(function ($appt) {
            if (isset($appt['id'])) {
                $appt['id'] = (string) $appt['id'];
            }
            return $appt;
        }, $appointments);

        if (empty($filters)) {
            $this->redis->set($cacheKey, $appointments, 31536000);
        }

        echo json_encode(['success' => true, 'appointments' => $appointments], JSON_UNESCAPED_UNICODE);
    }

    /**
     * تعديل حالة موعد (تأكيد/إلغاء/إتمام) من صفحة الأدمن.
     * POST محمي بـ CSRF token (نفس آلية جلسة الأدمن — session_id).
     */
    public function apiUpdateAppointmentStatus(string $id): void
    {
        $this->startSession();
        if ($this->getAuthenticatedAdmin() === null) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            return;
        }

        header('Content-Type: application/json');

        $raw  = file_get_contents('php://input');
        $body = json_decode($raw, true) ?? [];

        $csrfToken = (string) ($body['csrf_token'] ?? '');
        if (!Security::validateCsrfToken(session_id(), $csrfToken)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'رمز الحماية غير صالح أو منتهي، حاول مرة أخرى.']);
            return;
        }

        $status = trim((string) ($body['status'] ?? ''));
        if (!in_array($status, ['scheduled', 'cancelled', 'completed', 'missed'], true)) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'حالة غير صالحة.']);
            return;
        }

        $appointmentModel = new \BYD\Models\AppointmentModel();
        $updated = $appointmentModel->updateStatus((int) $id, $status);

        if (!$updated) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'الموعد غير موجود.']);
            return;
        }

        $this->redis->delete('cache:admin:appointments');

        echo json_encode(['success' => true]);
    }

    /**
     * تعديل تفاصيل موعد كاملة (التاريخ، الوقت، الاسم، الجوال، الملاحظة، الحالة).
     * PATCH /admin/api/appointments/{id}/edit — محمي بـ CSRF.
     */
    public function apiEditAppointment(string $id): void
    {
        $this->startSession();
        if ($this->getAuthenticatedAdmin() === null) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            return;
        }

        header('Content-Type: application/json');

        $raw  = file_get_contents('php://input');
        $body = json_decode($raw, true) ?? [];

        $csrfToken = (string) ($body['csrf_token'] ?? '');
        if (!Security::validateCsrfToken(session_id(), $csrfToken)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'رمز الحماية غير صالح أو منتهي، حاول مرة أخرى.']);
            return;
        }

        $allowedStatuses = ['scheduled', 'cancelled', 'completed', 'missed'];
        $data = [];

        if (!empty($body['appointment_date'])) $data['appointment_date'] = $body['appointment_date'];
        if (!empty($body['appointment_time'])) $data['appointment_time'] = $body['appointment_time'];
        if (!empty($body['customer_name']))    $data['customer_name']    = $body['customer_name'];
        if (!empty($body['phone_number']))     $data['phone_number']     = $body['phone_number'];
        if (array_key_exists('notes', $body))  $data['notes']            = $body['notes'];
        if (!empty($body['status']) && in_array($body['status'], $allowedStatuses, true)) {
            $data['status'] = $body['status'];
        }

        if (empty($data)) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'لا توجد بيانات للتحديث.']);
            return;
        }

        $appointmentModel = new \BYD\Models\AppointmentModel();
        $updated = $appointmentModel->updateDetails((int) $id, $data);

        if (!$updated) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'الموعد غير موجود أو لم يتغير شيء.']);
            return;
        }

        // مسح كاش Redis للمواعيد عشان التحديثات تظهر فوراً
        $this->redis->delete('cache:admin:appointments');

        echo json_encode(['success' => true]);
    }

    // ─────────────────────────────────────────────────────────
    // Specialist Contact Requests (تبويب طلبات التواصل مع مختص)
    // ─────────────────────────────────────────────────────────

    public function apiGetContactRequests(): void
    {
        $this->startSession();
        if ($this->getAuthenticatedAdmin() === null) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            return;
        }

        header('Content-Type: application/json');

        $status = trim((string) ($_GET['status'] ?? ''));
        $filters = [];
        if ($status !== '' && in_array($status, ['pending', 'contacted'], true)) {
            $filters['status'] = $status;
        }

        $model = new \BYD\Models\ContactRequestModel();

        $cacheKey = 'cache:admin:contact_requests';
        if (empty($filters)) {
            $cached = $this->redis->get($cacheKey);
            if ($cached !== null) {
                $cached = array_map(function ($r) {
                    if (isset($r['id'])) $r['id'] = (string) $r['id'];
                    return $r;
                }, $cached);
                echo json_encode(['success' => true, 'requests' => $cached], JSON_UNESCAPED_UNICODE);
                return;
            }
        }

        $requests = $model->getAll($filters);
        $requests = array_map(function ($r) {
            if (isset($r['id'])) $r['id'] = (string) $r['id'];
            return $r;
        }, $requests);

        if (empty($filters)) {
            $this->redis->set($cacheKey, $requests, 31536000);
        }

        echo json_encode(['success' => true, 'requests' => $requests], JSON_UNESCAPED_UNICODE);
    }

    public function apiUpdateContactRequestStatus(string $id): void
    {
        $this->startSession();
        if ($this->getAuthenticatedAdmin() === null) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            return;
        }

        header('Content-Type: application/json');

        $raw  = file_get_contents('php://input');
        $body = json_decode($raw, true) ?? [];

        $csrfToken = (string) ($body['csrf_token'] ?? '');
        if (!Security::validateCsrfToken(session_id(), $csrfToken)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'رمز الحماية غير صالح أو منتهي، حاول مرة أخرى.']);
            return;
        }

        $status = trim((string) ($body['status'] ?? ''));
        if (!in_array($status, ['pending', 'contacted'], true)) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'حالة غير صالحة.']);
            return;
        }

        $model = new \BYD\Models\ContactRequestModel();
        $updated = $model->updateStatus((int) $id, $status);

        if (!$updated) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'الطلب غير موجود.']);
            return;
        }

        $this->redis->delete('cache:admin:contact_requests');

        echo json_encode(['success' => true]);
    }

    public function dashboard(): void
    {
        $this->startSession();

        $admin = $this->getAuthenticatedAdmin();
        if ($admin === null) {
            $this->redirect($this->url('/login/admin'));
        }

        $csrfToken = Security::generateCsrfToken(session_id());
        $email = (string) ($admin['email'] ?? 'admin');
        $safeEmail = htmlspecialchars($email, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        header('Content-Type: text/html; charset=UTF-8');
        echo '<!doctype html>';
        echo '<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">';
        echo '<title>BYD Admin</title>';
        echo '<style>';
        echo 'body{font-family:Segoe UI,Tahoma,sans-serif;background:#f4f6f8;margin:0;padding:24px;color:#1f2937;}';
        echo '.card{max-width:760px;margin:0 auto;background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:24px;box-shadow:0 8px 24px rgba(0,0,0,.06);}';
        echo 'h1{margin-top:0}';
        echo '.meta{color:#6b7280;margin-bottom:24px;}';
        echo 'button{background:#0f766e;color:#fff;border:0;border-radius:8px;padding:10px 14px;font-size:14px;cursor:pointer;}';
        echo '</style></head><body>';
        echo '<div class="card">';
        echo '<h1>Admin Dashboard</h1>';
        echo '<p class="meta">Logged in as ' . $safeEmail . '</p>';
        echo '<form method="POST" action="' . $this->url('/admin/logout') . '">';
        echo '<input type="hidden" name="csrf_token" value="' . $csrfToken . '">';
        echo '<button type="submit">Logout</button>';
        echo '</form>';
        echo '</div></body></html>';
    }

    public function logout(): void
    {
        $this->startSession();

        $csrfToken = (string) ($_POST['csrf_token'] ?? '');
        if (!Security::validateCsrfToken(session_id(), $csrfToken)) {
            http_response_code(403);
            echo 'Invalid CSRF token.';
            return;
        }

        $accessCookie = $_ENV['ADMIN_TOKEN_COOKIE'] ?? 'admin_access_token';
        $refreshCookie = $_ENV['ADMIN_REFRESH_COOKIE'] ?? 'admin_refresh_token';
        $accessToken = (string) ($_COOKIE[$accessCookie] ?? '');
        $refreshToken = (string) ($_COOKIE[$refreshCookie] ?? '');

        $this->adminAuth->revokeToken($accessToken);
        $this->adminAuth->revokeToken($refreshToken);
        $this->clearAdminTokenCookie();

        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();

        $this->redirect($this->url('/'));
    }

    private function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $isHttps = true; // Force true for SameSite=None to work across origins, since ngrok uses HTTPS
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'None',
        ]);

        session_start();
    }

    private function getAuthenticatedAdmin(): ?array
    {
        return $this->adminAuth->authenticate();
    }

    private function issueAndSetAdminCookies(int $userId, string $ip, string $userAgent): void
    {
        $accessCookie = $_ENV['ADMIN_TOKEN_COOKIE'] ?? 'admin_access_token';
        $refreshCookie = $_ENV['ADMIN_REFRESH_COOKIE'] ?? 'admin_refresh_token';

        // Issue Access Token (15 minutes = 900 seconds)
        $accessToken = $this->adminAuth->issueToken($userId, $ip, $userAgent, 900);
        $this->adminAuth->setTokenCookie($accessCookie, $accessToken, 900);

        // Issue Refresh Token (7 days = 604800 seconds)
        $refreshToken = $this->adminAuth->issueToken($userId, $ip, $userAgent, 604800);
        $this->adminAuth->setTokenCookie($refreshCookie, $refreshToken, 604800);
    }

    private function clearAdminTokenCookie(): void
    {
        $accessCookie = $_ENV['ADMIN_TOKEN_COOKIE'] ?? 'admin_access_token';
        $refreshCookie = $_ENV['ADMIN_REFRESH_COOKIE'] ?? 'admin_refresh_token';

        $this->adminAuth->clearTokenCookie($accessCookie);
        $this->adminAuth->clearTokenCookie($refreshCookie);
    }

    private function renderLoginPage(string $csrfToken, string $errorMessage = ''): void
    {
        $safeError = htmlspecialchars($errorMessage, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        header('Content-Type: text/html; charset=UTF-8');
        echo '<!doctype html>';
        echo '<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">';
        echo '<title>BYD Admin Login</title>';
        echo '<style>';
        echo 'body{font-family:Segoe UI,Tahoma,sans-serif;background:linear-gradient(130deg,#f8fafc,#e2e8f0);min-height:100vh;margin:0;display:flex;align-items:center;justify-content:center;padding:18px;}';
        echo '.card{width:100%;max-width:390px;background:#fff;padding:24px;border-radius:14px;box-shadow:0 16px 30px rgba(15,23,42,.12);border:1px solid #e5e7eb;}';
        echo 'h1{margin:0 0 6px;font-size:24px;color:#111827;}';
        echo 'p{margin:0 0 18px;color:#6b7280;font-size:14px;}';
        echo 'label{display:block;margin-bottom:6px;color:#374151;font-size:13px;font-weight:600;}';
        echo 'input{width:100%;box-sizing:border-box;padding:10px 12px;margin-bottom:14px;border:1px solid #d1d5db;border-radius:8px;font-size:14px;}';
        echo 'button{width:100%;background:#0f766e;color:#fff;border:0;border-radius:8px;padding:10px 14px;font-size:14px;font-weight:600;cursor:pointer;}';
        echo '.error{background:#fef2f2;color:#b91c1c;padding:10px 12px;border-radius:8px;margin-bottom:12px;font-size:13px;border:1px solid #fecaca;}';
        echo '</style></head><body><div class="card">';
        echo '<h1>Admin Login</h1><p>Sign in to continue.</p>';
        if ($safeError !== '') {
            echo '<div class="error">' . $safeError . '</div>';
        }
        echo '<form method="POST" action="' . $this->url('/login/admin') . '" autocomplete="off">';
        echo '<input type="hidden" name="csrf_token" value="' . $csrfToken . '">';
        echo '<label for="email">Email</label>';
        echo '<input id="email" name="email" type="email" required maxlength="190">';
        echo '<label for="password">Password</label>';
        echo '<input id="password" name="password" type="password" required maxlength="190">';
        echo '<button type="submit">Login</button>';
        echo '</form></div></body></html>';
    }

    private function redirect(string $path): never
    {
        header('Location: ' . $path);
        exit;
    }

    private function url(string $path): string
    {
        $base = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php')));
        if ($base === '.' || $base === '/') {
            $base = '';
        }

        return $base . $path;
    }

    // ─────────────────────────────────────────────────────────
    // JSON API endpoints for the React frontend
    // ─────────────────────────────────────────────────────────

    public function apiCsrf(): void
    {
        $this->startSession();
        $csrfToken = Security::generateCsrfToken(session_id());

        header('Content-Type: application/json');
        echo json_encode(['csrf_token' => $csrfToken]);
    }

    public function apiMe(): void
    {
        $this->startSession();

        $admin = $this->getAuthenticatedAdmin();
        header('Content-Type: application/json');

        if ($admin === null) {
            http_response_code(401);
            echo json_encode(['authenticated' => false]);
            return;
        }

        echo json_encode([
            'authenticated' => true,
            'email' => $admin['email'],
        ]);
    }

    public function apiLogin(): void
    {
        $this->startSession();

        $raw = file_get_contents('php://input');
        $body = json_decode($raw, true) ?? [];

        header('Content-Type: application/json');

        $csrfToken = (string) ($body['csrf_token'] ?? '');
        if (!Security::validateCsrfToken(session_id(), $csrfToken)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Invalid or expired CSRF token.']);
            return;
        }

        $rawEmail = (string) ($body['email'] ?? '');
        $email = mb_strtolower(trim($rawEmail));
        $password = (string) ($body['password'] ?? '');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Please provide a valid email and password.']);
            return;
        }

        $ip = Security::getClientIp();
        $emailHash = hash('sha256', $email !== '' ? $email : 'unknown');
        $maxAttempts = (int) ($_ENV['ADMIN_LOGIN_RATE_LIMIT_MAX'] ?? 5);
        $windowSeconds = (int) ($_ENV['ADMIN_LOGIN_RATE_LIMIT_WINDOW'] ?? 300);

        $allowed = $this->redis->tokenBucket("admin-login:{$ip}:{$emailHash}", $maxAttempts, $windowSeconds);
        if (!$allowed) {
            http_response_code(429);
            echo json_encode(['success' => false, 'message' => 'Too many login attempts. Please try again later.']);
            return;
        }

        $user = $this->adminAuth->verifyCredentials($email, $password);
        if ($user === null) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Invalid email or password.']);
            return;
        }

        session_regenerate_id(true);

        $userAgent = (string) ($_SERVER['HTTP_USER_AGENT'] ?? 'unknown');
        $this->issueAndSetAdminCookies((int) $user['id'], $ip, $userAgent);

        echo json_encode(['success' => true, 'email' => $user['email']]);
    }

    public function apiLogout(): void
    {
        $this->startSession();

        $raw = file_get_contents('php://input');
        $body = json_decode($raw, true) ?? [];
        $csrfToken = (string) ($body['csrf_token'] ?? '');

        header('Content-Type: application/json');

        if (!Security::validateCsrfToken(session_id(), $csrfToken)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Invalid CSRF token.']);
            return;
        }

        $accessCookie = $_ENV['ADMIN_TOKEN_COOKIE'] ?? 'admin_access_token';
        $refreshCookie = $_ENV['ADMIN_REFRESH_COOKIE'] ?? 'admin_refresh_token';
        $accessToken = (string) ($_COOKIE[$accessCookie] ?? '');
        $refreshToken = (string) ($_COOKIE[$refreshCookie] ?? '');

        $this->adminAuth->revokeToken($accessToken);
        $this->adminAuth->revokeToken($refreshToken);
        $this->clearAdminTokenCookie();

        session_destroy();
        echo json_encode(['success' => true]);
    }

    public function apiGetCars(): void
    {
        $this->startSession();
        if ($this->getAuthenticatedAdmin() === null) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            return;
        }

        $cacheKey = 'cache:admin:cars';
        $cached = $this->redis->get($cacheKey);
        if ($cached !== null) {
            header('Content-Type: application/json');
            echo json_encode(['cars' => $cached]);
            return;
        }

        $carModel = new \BYD\Models\CarModel();
        $cars = $carModel->getAllModels();

        $this->redis->set($cacheKey, $cars, 31536000); // Cache for 1 year

        header('Content-Type: application/json');
        echo json_encode(['cars' => $cars]);
    }

    public function apiGetCar(string $id): void
    {
        $this->startSession();
        if ($this->getAuthenticatedAdmin() === null) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            return;
        }

        $carModel = new \BYD\Models\CarModel();
        $car = $carModel->getCarFullData((int) $id);

        header('Content-Type: application/json');
        if (empty($car)) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Car not found']);
        } else {
            echo json_encode(['success' => true, 'car' => $car]);
        }
    }

    public function apiUpdateCar(string $id): void
    {
        $this->startSession();
        if ($this->getAuthenticatedAdmin() === null) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            return;
        }

        $raw = file_get_contents('php://input');
        $body = json_decode($raw, true) ?? [];

        $modelName = trim((string) ($body['model_name'] ?? ''));
        $modelNameAr = trim((string) ($body['model_name_ar'] ?? ''));
        $modelCode = trim((string) ($body['model_code'] ?? ''));
        $year = (int) ($body['year'] ?? 0);
        $category = trim((string) ($body['category'] ?? ''));

        if ($modelName === '' || $modelCode === '' || $year <= 0) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Invalid data provided.']);
            return;
        }

        $priceFrom = isset($body['price_from']) && $body['price_from'] !== '' ? (float) $body['price_from'] : null;
        $warrantyYears = isset($body['warranty_years']) && $body['warranty_years'] !== '' ? (int) $body['warranty_years'] : null;
        $warrantyKm = isset($body['warranty_km']) && $body['warranty_km'] !== '' ? (int) $body['warranty_km'] : null;
        $passengerCount = isset($body['passenger_count']) && $body['passenger_count'] !== '' ? (int) $body['passenger_count'] : null;
        $cargoLiters = isset($body['cargo_liters']) && $body['cargo_liters'] !== '' ? (int) $body['cargo_liters'] : null;
        $towingKg = isset($body['towing_kg']) && $body['towing_kg'] !== '' ? (int) $body['towing_kg'] : null;
        $description = isset($body['description']) ? trim((string) $body['description']) : null;

        $db = \BYD\Models\Database::getInstance();
        $db->execute(
            'UPDATE cars SET 
                model_name = ?, 
                model_name_ar = ?, 
                model_code = ?, 
                year = ?, 
                category = ?, 
                price_from = ?,
                warranty_years = ?,
                warranty_km = ?,
                passenger_count = ?,
                cargo_liters = ?,
                towing_kg = ?,
                description = ?,
                updated_at = NOW() 
            WHERE id = ?',
            [
                $modelName, 
                $modelNameAr, 
                $modelCode, 
                $year, 
                $category, 
                $priceFrom,
                $warrantyYears,
                $warrantyKm,
                $passengerCount,
                $cargoLiters,
                $towingKg,
                $description,
                (int) $id
            ]
        );

        // Update colors
        $db->execute('DELETE FROM car_colors WHERE car_id = ?', [(int) $id]);
        $colors = $body['colors'] ?? [];
        foreach ($colors as $color) {
            $db->execute(
                'INSERT INTO car_colors (car_id, color_type, color_name_en, color_name_ar, hex_code) VALUES (?, ?, ?, ?, ?)',
                [
                    (int) $id,
                    $color['color_type'] ?? 'exterior',
                    trim((string) ($color['color_name_en'] ?? '')),
                    trim((string) ($color['color_name_ar'] ?? '')),
                    trim((string) ($color['hex_code'] ?? ''))
                ]
            );
        }

        // Update specifications
        $db->execute('DELETE FROM car_specifications WHERE car_id = ?', [(int) $id]);
        $specs = $body['specifications'] ?? [];
        foreach ($specs as $spec) {
            $db->execute(
                'INSERT INTO car_specifications (car_id, spec_key, spec_value, spec_group, unit) VALUES (?, ?, ?, ?, ?)',
                [
                    (int) $id,
                    trim((string) ($spec['spec_key'] ?? '')),
                    trim((string) ($spec['spec_value'] ?? '')),
                    trim((string) ($spec['spec_group'] ?? 'general')),
                    isset($spec['unit']) && $spec['unit'] !== '' ? trim((string) $spec['unit']) : null
                ]
            );
        }

        $redis = \BYD\Models\RedisClient::getInstance();
        $redis->delete('warmup:done');
        $redis->delete('cache:admin:cars');

        header('Content-Type: application/json');
        echo json_encode(['success' => true]);
    }

    public function apiDeleteCar(string $id): void
    {
        $this->startSession();
        if ($this->getAuthenticatedAdmin() === null) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            return;
        }

        $db = \BYD\Models\Database::getInstance();
        $db->execute('DELETE FROM cars WHERE id = ?', [(int) $id]);

        $redis = \BYD\Models\RedisClient::getInstance();
        $redis->delete('warmup:done');
        $redis->delete('cache:admin:cars');

        header('Content-Type: application/json');
        echo json_encode(['success' => true]);
    }

    // ─────────────────────────────────────────────────────────
    // Car Images API
    // ─────────────────────────────────────────────────────────

    public function apiUploadCarImages(string $id): void
    {
        $this->startSession();
        if ($this->getAuthenticatedAdmin() === null) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            return;
        }

        header('Content-Type: application/json');

        $carId = (int) $id;
        $db = \BYD\Models\Database::getInstance();

        // Verify car exists
        $car = $db->queryOne('SELECT id FROM cars WHERE id = ?', [$carId]);
        if (!$car) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Car not found']);
            return;
        }

        // Check current image count
        $countRow = $db->queryOne('SELECT COUNT(*) AS cnt FROM car_images WHERE car_id = ?', [$carId]);
        $currentCount = (int) ($countRow['cnt'] ?? 0);

        if (!isset($_FILES['images'])) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'No images provided']);
            return;
        }

        $files = $_FILES['images'];
        $allowedTypes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
        $maxFileSize = 5 * 1024 * 1024; // 5MB
        $maxImagesPerCar = 10;

        // Normalize $_FILES array for multiple uploads
        $fileList = [];
        if (is_array($files['name'])) {
            for ($i = 0; $i < count($files['name']); $i++) {
                if ($files['error'][$i] === UPLOAD_ERR_OK) {
                    $fileList[] = [
                        'name'     => $files['name'][$i],
                        'type'     => $files['type'][$i],
                        'tmp_name' => $files['tmp_name'][$i],
                        'error'    => $files['error'][$i],
                        'size'     => $files['size'][$i],
                    ];
                }
            }
        } else {
            if ($files['error'] === UPLOAD_ERR_OK) {
                $fileList[] = $files;
            }
        }

        if (empty($fileList)) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'No valid files uploaded']);
            return;
        }

        if ($currentCount + count($fileList) > $maxImagesPerCar) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => "الحد الأقصى $maxImagesPerCar صور لكل سيارة. عندك حالياً $currentCount صور."]);
            return;
        }

        // Create directory
        $storageDir = __DIR__ . '/../../storage/car_images/' . $carId;
        if (!is_dir($storageDir)) {
            mkdir($storageDir, 0755, true);
        }

        $uploaded = [];
        $nextOrder = $currentCount;

        foreach ($fileList as $file) {
            // Validate type
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mimeType = $finfo->file($file['tmp_name']);
            if (!in_array($mimeType, $allowedTypes, true)) {
                continue; // Skip invalid files
            }

            // Validate size
            if ($file['size'] > $maxFileSize) {
                continue;
            }

            // Generate unique filename
            $ext = pathinfo($file['name'], PATHINFO_EXTENSION) ?: 'jpg';
            $uniqueName = uniqid('img_', true) . '.' . strtolower($ext);
            $destPath = $storageDir . '/' . $uniqueName;
            $relativePath = 'storage/car_images/' . $carId . '/' . $uniqueName;

            if (move_uploaded_file($file['tmp_name'], $destPath)) {
                $nextOrder++;
                $db->execute(
                    'INSERT INTO car_images (car_id, file_name, file_path, display_order) VALUES (?, ?, ?, ?)',
                    [$carId, $file['name'], $relativePath, $nextOrder]
                );
                $lastId = $db->queryOne('SELECT LAST_INSERT_ID() AS id');
                $uploaded[] = [
                    'id'            => (int) ($lastId['id'] ?? 0),
                    'file_name'     => $file['name'],
                    'file_path'     => $relativePath,
                    'display_order' => $nextOrder,
                ];
            }
        }

        // Clear caches
        $this->redis->delete('cache:admin:cars');
        $this->redis->delete('warmup:done');

        echo json_encode([
            'success'        => true,
            'uploaded_count' => count($uploaded),
            'images'         => $uploaded,
        ]);
    }

    public function apiDeleteCarImage(string $imageId): void
    {
        $this->startSession();
        if ($this->getAuthenticatedAdmin() === null) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            return;
        }

        header('Content-Type: application/json');

        $db = \BYD\Models\Database::getInstance();
        $image = $db->queryOne('SELECT id, file_path FROM car_images WHERE id = ?', [(int) $imageId]);

        if (!$image) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Image not found']);
            return;
        }

        // Delete file from disk
        $fullPath = __DIR__ . '/../../' . $image['file_path'];
        if (file_exists($fullPath)) {
            unlink($fullPath);
        }

        // Delete from DB
        $db->execute('DELETE FROM car_images WHERE id = ?', [(int) $imageId]);

        // Clear caches
        $this->redis->delete('cache:admin:cars');
        $this->redis->delete('warmup:done');

        echo json_encode(['success' => true]);
    }

    public function apiReorderCarImages(string $id): void
    {
        $this->startSession();
        if ($this->getAuthenticatedAdmin() === null) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            return;
        }

        header('Content-Type: application/json');

        $raw = file_get_contents('php://input');
        $body = json_decode($raw, true) ?? [];
        $order = $body['order'] ?? []; // Array of image IDs in desired order

        if (empty($order) || !is_array($order)) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Invalid order data']);
            return;
        }

        $db = \BYD\Models\Database::getInstance();
        foreach ($order as $index => $imgId) {
            $db->execute(
                'UPDATE car_images SET display_order = ? WHERE id = ? AND car_id = ?',
                [$index + 1, (int) $imgId, (int) $id]
            );
        }

        $this->redis->delete('cache:admin:cars');
        $this->redis->delete('warmup:done');

        echo json_encode(['success' => true]);
    }

    // ─────────────────────────────────────────────────────────
    // Admin Settings API
    // ─────────────────────────────────────────────────────────

    public function apiGetSettings(): void
    {
        $this->startSession();
        if ($this->getAuthenticatedAdmin() === null) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            return;
        }

        header('Content-Type: application/json');

        $cacheKey = 'cache:settings';
        $cached = $this->redis->get($cacheKey);
        if ($cached !== null) {
            echo json_encode(['success' => true, 'settings' => $cached]);
            return;
        }

        $db = \BYD\Models\Database::getInstance();
        $rows = $db->query('SELECT setting_key, setting_value FROM admin_settings');

        $settings = [];
        foreach ($rows as $row) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }

        $this->redis->set($cacheKey, $settings, 31536000); // 1 year

        echo json_encode(['success' => true, 'settings' => $settings]);
    }

    public function apiUpdateSettings(): void
    {
        $this->startSession();
        if ($this->getAuthenticatedAdmin() === null) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            return;
        }

        header('Content-Type: application/json');

        $raw = file_get_contents('php://input');
        $body = json_decode($raw, true) ?? [];
        $settings = $body['settings'] ?? [];

        if (empty($settings) || !is_array($settings)) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'No settings provided']);
            return;
        }

        $db = \BYD\Models\Database::getInstance();
        $allowedKeys = [
            'bot_name', 'bot_name_en',
            // دوام الفرع ومدى الحجز المسموح لنظام المواعيد — قابلة للتحكم من هون
            'appointment_start_time', 'appointment_end_time',
            'appointment_slot_minutes', 'appointment_booking_days_ahead',
        ];

        foreach ($settings as $key => $value) {
            if (!in_array($key, $allowedKeys, true)) {
                continue;
            }
            $value = trim((string) $value);
            if ($value === '') {
                continue;
            }

            // تحقق بسيط من صيغة القيم الخاصة بدوام المواعيد قبل الحفظ
            if (in_array($key, ['appointment_start_time', 'appointment_end_time'], true)
                && !preg_match('/^\d{2}:\d{2}$/', $value)) {
                continue;
            }
            if (in_array($key, ['appointment_slot_minutes', 'appointment_booking_days_ahead'], true)
                && (!ctype_digit($value) || (int) $value <= 0)) {
                continue;
            }

            $db->execute(
                'INSERT INTO admin_settings (setting_key, setting_value) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)',
                [$key, $value]
            );
        }

        // Clear settings cache (بيأثر كمان على AppointmentModel::getWorkingHours لأنها بتقرا من نفس الكاش)
        $this->redis->delete('cache:settings');

        echo json_encode(['success' => true]);
    }

    /**
     * Public endpoint — returns only non-sensitive settings (bot name)
     * No authentication required
     */

    public function apiGetPublicCars(): void
    {
        header('Content-Type: application/json');

        $cacheKey = 'cache:public:cars';
        $cached = $this->redis->get($cacheKey);
        if ($cached !== null) {
            echo json_encode(['cars' => $cached]);
            return;
        }

        $carModel = new \BYD\Models\CarModel();
        $cars = $carModel->getAllModels();

        $cars = array_values(array_filter($cars, function ($c) {
            return !isset($c['is_active']) || (int) $c['is_active'] === 1;
        }));

        $this->redis->set($cacheKey, $cars, 3600);

        echo json_encode(['cars' => $cars]);
    }

    public function apiGetPublicCarDetail(string $id): void
    {
        header('Content-Type: application/json');

        $carModel = new \BYD\Models\CarModel();
        $car = $carModel->getCarFullData((int) $id);

        if (empty($car) || (isset($car['is_active']) && (int) $car['is_active'] !== 1)) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Car not found']);
            return;
        }

        echo json_encode(['success' => true, 'car' => $car]);
    }


    public function apiGetPublicSettings(): void
{
    header('Content-Type: application/json; charset=utf-8');

    $settings = self::loadSettings($this->redis);

    echo json_encode([
        'success'  => true,
        'settings' => [
            'bot_name'    => $settings['bot_name'] ?? 'ميرا',
            'bot_name_en' => $settings['bot_name_en'] ?? 'Mira',
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}
    /**
     * Static helper — loads settings from Redis cache or DB
     * Used by AdminController and VapiWebhookController
     */
    public static function loadSettings(?\BYD\Models\RedisClient $redis = null): array
    {
        $redis = $redis ?? \BYD\Models\RedisClient::getInstance();

        $cacheKey = 'cache:settings';
        $cached = $redis->get($cacheKey);
        if ($cached !== null && is_array($cached)) {
            return $cached;
        }

        $db = \BYD\Models\Database::getInstance();
        $rows = $db->query('SELECT setting_key, setting_value FROM admin_settings');

        $settings = [];
        foreach ($rows as $row) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }

        $redis->set($cacheKey, $settings, 31536000);

        return $settings;
    }

    // ─────────────────────────────────────────────────────────
    // Admin Credentials (Email / Password) API
    // ─────────────────────────────────────────────────────────

    public function apiUpdateCredentials(): void
    {
        $this->startSession();
        $admin = $this->getAuthenticatedAdmin();
        header('Content-Type: application/json');

        if ($admin === null) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            return;
        }

        $raw = file_get_contents('php://input');
        $body = json_decode($raw, true) ?? [];

        $csrfToken = (string) ($body['csrf_token'] ?? '');
        if (!Security::validateCsrfToken(session_id(), $csrfToken)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'رمز الحماية غير صالح أو منتهي، حاول مرة أخرى.']);
            return;
        }

        $currentPassword = (string) ($body['current_password'] ?? '');
        $newEmailRaw = trim((string) ($body['new_email'] ?? ''));
        $newPassword = (string) ($body['new_password'] ?? '');
        $newPasswordConfirm = (string) ($body['new_password_confirm'] ?? '');

        if ($currentPassword === '') {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'يجب إدخال كلمة المرور الحالية.']);
            return;
        }

        if ($newEmailRaw === '' && $newPassword === '') {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'لم يتم إدخال أي تغيير.']);
            return;
        }

        $userId = (int) ($admin['user_id'] ?? 0);
        $userRow = $this->adminAuth->findById($userId);

        if ($userRow === null || !password_verify($currentPassword, (string) $userRow['password_hash'])) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'كلمة المرور الحالية غير صحيحة.']);
            return;
        }

        $newEmail = null;
        if ($newEmailRaw !== '') {
            $newEmail = mb_strtolower($newEmailRaw);
            if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'صيغة البريد الإلكتروني غير صحيحة.']);
                return;
            }
            if ($this->adminAuth->emailExists($newEmail, $userId)) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'هذا البريد الإلكتروني مستخدم بالفعل.']);
                return;
            }
        }

        $newPasswordValue = null;
        if ($newPassword !== '') {
            if (strlen($newPassword) < 8) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'كلمة المرور الجديدة يجب أن تكون 8 أحرف على الأقل.']);
                return;
            }
            if ($newPassword !== $newPasswordConfirm) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'تأكيد كلمة المرور غير مطابق.']);
                return;
            }
            $newPasswordValue = $newPassword;
        }

        $this->adminAuth->updateCredentials($userId, $newEmail, $newPasswordValue);

        // If the email changed, all outstanding admin tokens for this user become
        // stale in the sense the "identity" changed — but tokens are keyed by user_id,
        // not email, so existing sessions remain valid. No token revocation needed here.

        echo json_encode([
            'success' => true,
            'email' => $newEmail ?? $userRow['email'],
        ]);
    }
}
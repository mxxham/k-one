<?php
/**
 * K-one JSON API — single gateway dispatcher (parity with v2 gateway.controller.ts).
 *
 * Token-based auth. The SPA calls:
 *   GET/POST api/index.php?module=<module>&action=<action>
 * With header: Authorization: Bearer <token>
 * (except PUBLIC_ACTIONS: auth::login, import::tpl_inbound/outbound/stock)
 *
 * Gateway dispatch order (mirrors v2 exactly):
 *   1. banner if module/action empty
 *   2. Unknown module → 404 'Unknown module: X'
 *   3. handler function exists? → 404 'Module handler missing'
 *   4. PUBLIC_ACTIONS skip auth
 *   5. Auth → 401 'Unauthorized'
 *   6. Permission write/admin → role check → 403
 *   7. Department check → 403
 *   8. Call handler → _binary / _html / {success:true, ...}
 */

/* ------------------------------------------------------------------ */
/* CORS headers — must precede session_start and all includes          */
/* ------------------------------------------------------------------ */
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Allow-Credentials: true');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

error_reporting(0);
ini_set('display_errors', '0');
date_default_timezone_set('Asia/Jakarta');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/database.php';
foreach (glob(__DIR__ . '/../classes/*.php') as $classFile) {
    require_once $classFile;
}
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/registry.php';

// API contract validation middleware (optional — opt-in via constant or ?_validate=1)
require_once __DIR__ . '/middleware/ApiException.php';
require_once __DIR__ . '/middleware/ValidateRequest.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

/* ------------------------------------------------------------------ */
/* Response helpers                                                    */
/* ------------------------------------------------------------------ */

function json_out($payload) {
    echo json_encode(array_merge(['success' => true], $payload));
    exit;
}

function json_err($message, $code = 400) {
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

function body(): array {
    $raw = file_get_contents('php://input');
    if ($raw) {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) return $decoded;
    }
    return $_POST;
}

function query($key, $default = null) {
    return $_GET[$key] ?? $default;
}

/* ------------------------------------------------------------------ */
/* Rate limiting — brute-force protection for login                    */
/* ------------------------------------------------------------------ */

function checkRateLimit(string $ip): bool {
    $file = sys_get_temp_dir() . '/kone_login_' . md5($ip);
    $attempts = 0;
    $window = 60; // 1 minute

    if (file_exists($file)) {
        $data = json_decode(file_get_contents($file), true);
        if (time() - $data['first'] < $window) {
            $attempts = $data['attempts'];
        }
    }

    if ($attempts >= 5) return false;

    $data = [
        'first' => $data['first'] ?? time(),
        'attempts' => $attempts + 1,
    ];
    file_put_contents($file, json_encode($data));
    return true;
}

/* ------------------------------------------------------------------ */
/* Token auth — SHA-256 at rest (approved security fix per plan).       */
/* Wire contract unchanged: client receives raw 64-hex token.          */
/* ------------------------------------------------------------------ */

function api_ensure_tokens_table(): void {
    db()->exec(
        "CREATE TABLE IF NOT EXISTS `auth_tokens` (
            `id` INT(11) AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT(11) NOT NULL,
            `token` VARCHAR(64) NOT NULL UNIQUE,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `expires_at` DATETIME NOT NULL,
            KEY `idx_user` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function api_issue_token(int $userId): string {
    api_ensure_tokens_table();
    $token = bin2hex(random_bytes(32));
    $stmt = db()->prepare(
        "INSERT INTO auth_tokens (user_id, token, expires_at)
         VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 12 HOUR))"
    );
    $stmt->execute([$userId, hash('sha256', $token)]);
    return $token;
}

function api_current_user(): ?array {
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!$auth && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        $auth = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    }
    if (!$auth && isset($_GET['token'])) {
        $auth = 'Bearer ' . $_GET['token'];
    }
    if (!$auth) {
        if (!Auth::check()) return null;
        return Auth::user();
    }
    if (stripos($auth, 'Bearer ') === 0) {
        $token = substr($auth, 7);
    } else {
        $token = $auth;
    }
    api_ensure_tokens_table();
    $stmt = db()->prepare(
        "SELECT u.id, u.username, u.full_name, u.email, u.role, u.department
         FROM auth_tokens t JOIN users u ON u.id = t.user_id
         WHERE t.token = ? AND t.expires_at > NOW() LIMIT 1"
    );
    $stmt->execute([hash('sha256', $token)]);
    $user = $stmt->fetch();
    if ($user) {
        $_SESSION['user_id']    = (int)$user['id'];
        $_SESSION['username']   = $user['username'];
        $_SESSION['full_name']  = $user['full_name'];
        $_SESSION['email']      = $user['email'];
        $_SESSION['role']       = $user['role'];
        $_SESSION['department'] = $user['department'] ?? 'all';
    }
    return $user ?: null;
}

function api_require_auth(): array {
    $user = api_current_user();
    if (!$user) json_err('Unauthorized', 401);
    return $user;
}

function api_require_write(): array {
    $user = api_require_auth();
    $writeRoles = ['admin', 'operator', 'warehouse', 'supervisor', 'staff'];
    if (!in_array($user['role'], $writeRoles)) {
        json_err('Akses ditolak. Role Anda tidak memiliki izin untuk mengubah data.', 403);
    }
    return $user;
}

function api_require_admin(): array {
    $user = api_require_auth();
    if (($user['role'] ?? '') !== 'admin') json_err('Akses ditolak. Khusus admin.', 403);
    return $user;
}

/* ------------------------------------------------------------------ */
/* Routing — gateway dispatch chain (parity with gateway.controller.ts) */
/* ------------------------------------------------------------------ */

$module = $_GET['module'] ?? $_POST['module'] ?? '';
$action = $_GET['action'] ?? $_POST['action'] ?? '';

$safeModule = preg_replace('/[^a-z0-9_]/', '', strtolower($module));

// 1. Banner: empty module/action → version, no success field (v2 parity)
if ($module === '' || $action === '') {
    http_response_code(200);
    echo json_encode([
        'message' => 'K-one API',
        'version' => '2.0.0',
        'time'    => gmdate('Y-m-d\TH:i:s.v\Z'),
    ]);
    exit;
}

// 2. Unknown module → 404
$handlerFile = __DIR__ . '/handlers/' . $safeModule . '.php';
if (!is_file($handlerFile)) {
    json_err('Unknown module: ' . $module, 404);
}

// 3. Load handler, verify function exists
require $handlerFile;

$fn = 'handle_' . $safeModule;
if (!function_exists($fn)) {
    json_err('Invalid action: ' . $action, 404);
}

// 4–7. Auth + role + department chain (mirrors gateway.controller.ts lines 74–97)
$publicKey = $module . '::' . $action;
$user = null;
if (!in_array($publicKey, PUBLIC_ACTIONS, true)) {
    $user = api_require_auth();

    $level = get_permission($module, $action);
    if ($level === 'write' || $level === 'admin') {
        $writeRoles = ['admin', 'operator', 'warehouse', 'supervisor', 'staff'];
        if (!in_array($user['role'], $writeRoles)) {
            json_err('Akses ditolak. Role Anda tidak memiliki izin untuk mengubah data.', 403);
        }
    }
    if ($level === 'admin' && ($user['role'] ?? '') !== 'admin') {
        json_err('Akses ditolak. Khusus admin.', 403);
    }

    $depts = get_departments($module, $action);
    if ($depts && ($user['department'] ?? 'all') !== 'all' && !in_array($user['department'], $depts)) {
        json_err('Akses ditolak. Department Anda tidak memiliki izin untuk modul ini.', 403);
    }
}

// 8. Rate limit check for login
if ($publicKey === 'auth::login') {
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    if (!checkRateLimit($clientIp)) {
        http_response_code(429);
        echo json_encode(['success' => false, 'message' => 'Too many login attempts. Please try again later.']);
        exit;
    }
}

// 9. Contract validation — optional, opt-in via API_CONTRACT_VALIDATION or ?_validate=1
if (ValidateRequest::isEnabled()) {
    try {
        ValidateRequest::validate($module, $action, body());
    } catch (ContractValidationException $e) {
        http_response_code($e->getCode());
        echo json_encode($e->toJson());
        exit;
    }
}

// 10. Call handler — wrap so uncaught exceptions return v2 parity 500 shape
try {
    $result = $fn($action);
} catch (Throwable $e) {
    json_err($e->getMessage(), 500);
}

// Post-dispatch: if handler returned an array, apply _binary / _html markers
if (is_array($result)) {
    if (!empty($result['_binary'])) {
        header('Content-Type: ' . ($result['contentType'] ?? 'application/octet-stream'));
        header('Content-Disposition: attachment; filename="' . ($result['filename'] ?? 'download') . '"');
        header('Cache-Control: max-age=0');
        echo $result['buffer'] ?? '';
        exit;
    }
    if (!empty($result['_html'])) {
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        echo $result['html'] ?? '';
        exit;
    }
    // Strip marker keys before wrapping in success
    unset($result['_binary'], $result['_html'], $result['buffer'], $result['filename'], $result['contentType'], $result['html']);
    json_out($result);
}
// If handler echoed + exited (existing v1 style), we never reach here.

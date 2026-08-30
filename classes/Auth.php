<?php

class Auth {
    public static function check() {
        return isset($_SESSION['user_id']);
    }

    public static function user() {
        if (!self::check()) {
            return null;
        }
        return [
            'id' => $_SESSION['user_id'],
            'username' => $_SESSION['username'] ?? '',
            'full_name' => $_SESSION['full_name'] ?? '',
            'email' => $_SESSION['email'] ?? '',
            'role' => $_SESSION['role'] ?? 'viewer',
            'department' => $_SESSION['department'] ?? 'all'
        ];
    }

    public static function login($username, $password) {
        $db = db();
        $stmt = $db->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['email'] = $user['email'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['department'] = $user['department'] ?? 'all';
            $_SESSION['must_change_password'] = (int)($user['must_change_password'] ?? 0);
            return true;
        }
        return false;
    }

    /**
     * Check if the current user must change their password.
     */
    public static function mustChangePassword(): bool {
        return isset($_SESSION['must_change_password']) && $_SESSION['must_change_password'] === 1;
    }

    /**
     * Force the current user to change password. Sets must_change_password=0.
     */
    public static function markPasswordChanged(): void {
        $user = self::user();
        if ($user) {
            $db = db();
            $db->prepare("UPDATE users SET must_change_password = 0 WHERE id = ?")->execute([$user['id']]);
            $_SESSION['must_change_password'] = 0;
        }
    }

    public static function logout() {
        session_unset();
        session_destroy();
    }

    public static function requireAuth() {
        if (!self::check()) {
            // Try token auth (for SPA print/export pages opened in new tabs)
            $token = $_GET['token'] ?? null;
            if ($token && self::loginByToken($token)) {
                return; // session established via token
            }
            header('Location: ' . BASE_URL . '/login.php');
            exit;
        }
    }

    /**
     * Validate a bearer/token and populate the session.
     * Returns true on success, false on failure.
     */
    public static function loginByToken(string $token): bool {
        try {
            $db = db();
            // Ensure table exists
            $db->exec(
                "CREATE TABLE IF NOT EXISTS `auth_tokens` (
                    `id` INT(11) AUTO_INCREMENT PRIMARY KEY,
                    `user_id` INT(11) NOT NULL,
                    `token` VARCHAR(64) NOT NULL UNIQUE,
                    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `expires_at` DATETIME NOT NULL,
                    KEY `idx_user` (`user_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
            $stmt = $db->prepare(
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
                return true;
            }
        } catch (\Throwable $e) {
            // silently fail — will redirect to login
        }
        return false;
    }

    public static function hasRole($role) {
        $user = self::user();
        return $user && $user['role'] === $role;
    }

    public static function requireRole($roles) {
        self::requireAuth();
        $user = self::user();
        if (!in_array($user['role'], (array)$roles)) {
            header('Location: ' . BASE_URL . '/dashboard.php?error=unauthorized');
            exit;
        }
    }

    public static function canWrite() {
        $user = self::user();
        return $user && in_array($user['role'], ['admin', 'operator']);
    }

    public static function canAdmin() {
        return self::hasRole('admin');
    }

    public static function requireAdmin() {
        self::requireRole(['admin']);
    }

    public static function requireWrite() {
        self::requireAuth();
        if (!self::canWrite()) {
            if (self::_isJsonRequest()) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Akses ditolak. Role Anda tidak memiliki izin untuk melakukan perubahan data.']);
                exit;
            }
            header('Location: ' . BASE_URL . '/dashboard.php?error=unauthorized');
            exit;
        }
    }

    /**
     * Department guard (Phase 0 additive access layer).
     * $allowed: string|array of department codes (inbound|outbound|inventory|ops|all).
     * Users with department 'all' always pass. admin always passes.
     */
    public static function requireDepartment($allowed) {
        self::requireAuth();
        $user = self::user();
        if (in_array($user['role'], ['admin', 'viewer'])) {
            return; // admin bypasses dept gates; viewer is read-only everywhere
        }
        $dept = $user['department'] ?? 'all';
        $allowed = (array)$allowed;
        if ($dept === 'all' || in_array($dept, $allowed)) {
            return;
        }
        if (self::_isJsonRequest()) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Akses ditolak. Departemen Anda tidak memiliki izin untuk modul ini.']);
            exit;
        }
        header('Location: ' . BASE_URL . '/dashboard.php?error=unauthorized');
        exit;
    }

    /** True when caller expects a JSON response */
    private static function _isJsonRequest(): bool {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) return true;
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        return strpos($accept, 'application/json') !== false;
    }
}
?>
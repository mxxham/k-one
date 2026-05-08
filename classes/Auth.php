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
            'role' => $_SESSION['role'] ?? 'viewer'
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
            return true;
        }
        return false;
    }

    public static function logout() {
        session_unset();
        session_destroy();
    }

    public static function requireAuth() {
        if (!self::check()) {
            header('Location: ' . BASE_URL . '/login.php');
            exit;
        }
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

    /** True when caller expects a JSON response */
    private static function _isJsonRequest(): bool {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) return true;
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        return strpos($accept, 'application/json') !== false;
    }
}
?>

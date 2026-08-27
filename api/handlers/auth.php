<?php

function handle_auth($action) {
    switch ($action) {
        case 'login':
            $data = body();
            $username = trim($data['username'] ?? '');
            $password = (string)($data['password'] ?? '');
            if ($username === '' || $password === '') {
                json_err('Username dan password wajib diisi.');
            }

            $db = db();
            $stmt = $db->prepare("SELECT * FROM users WHERE username = ? LIMIT 1");
            $stmt->execute([$username]);
            $user = $stmt->fetch();

            if (!$user || !password_verify($password, $user['password'])) {
                json_err('Username atau password salah.', 401);
            }
            if ((int)($user['is_active'] ?? 1) !== 1) {
                json_err('Akun nonaktif. Hubungi administrator.', 403);
            }

            $token = api_issue_token((int)$user['id']);
            ActivityLogger::log('LOGIN', 'auth', 'User', (int)$user['id'],
                null, 'User login: ' . $user['username']);

            json_out([
                'token' => $token,
                'user' => [
                    'id' => (int)$user['id'],
                    'username' => $user['username'],
                    'full_name' => $user['full_name'],
                    'email' => $user['email'],
                    'role' => $user['role'],
                    'department' => $user['department'] ?? 'all',
                ],
            ]);
            break;

        case 'logout':
            $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
            if ($auth && stripos($auth, 'Bearer ') === 0) {
                $token = substr($auth, 7);
                api_ensure_tokens_table();
                db()->prepare("DELETE FROM auth_tokens WHERE token = ?")->execute([hash('sha256', $token)]);
            }
            Auth::logout();
            json_out(['message' => 'Logged out']);
            break;

        case 'me':
            $user = api_require_auth();
            json_out(['user' => $user]);
            break;

        default:
            json_err('Invalid action: ' . $action, 404);
    }
}

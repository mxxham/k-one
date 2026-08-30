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
            $db = db();
            $stmt = $db->prepare("SELECT must_change_password FROM users WHERE id = ?");
            $stmt->execute([$user['id']]);
            $mustChange = (int)($stmt->fetchColumn() ?: 0);
            $user['must_change_password'] = $mustChange;
            json_out(['user' => $user]);
            break;

        case 'change_password':
            $data = body();
            $oldPassword = (string)($data['old_password'] ?? '');
            $newPassword = (string)($data['new_password'] ?? '');

            if ($oldPassword === '' || $newPassword === '') {
                json_err('Password lama dan baru wajib diisi.');
            }
            if (strlen($newPassword) < 8) {
                json_err('Password baru minimal 8 karakter.');
            }

            $auth = api_require_auth();
            $db = db();
            $stmt = $db->prepare("SELECT password FROM users WHERE id = ?");
            $stmt->execute([$auth['id']]);
            $hash = $stmt->fetchColumn();

            if (!password_verify($oldPassword, $hash)) {
                json_err('Password lama salah.', 401);
            }

            $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
            $db->prepare("UPDATE users SET password = ?, must_change_password = 0 WHERE id = ?")
               ->execute([$newHash, $auth['id']]);
            Auth::markPasswordChanged();

            ActivityLogger::log('CHANGE_PASSWORD', 'auth', 'User', (int)$auth['id'],
                $auth['username'], 'Password diubah: ' . $auth['username']);

            json_out(['message' => 'Password berhasil diubah.']);
            break;

        default:
            json_err('Invalid action: ' . $action, 404);
    }
}

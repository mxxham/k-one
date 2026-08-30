<?php

function handle_system($action) {
    switch ($action) {
        case 'reset_operational_data':
            api_require_auth();
            $user = api_current_user();
            if (($user['role'] ?? '') !== 'admin') {
                json_err('Akses ditolak: hanya admin yang dapat mereset data operasional', 403);
            }
            Report::resetOperationalData();
            ActivityLogger::log(
                'RESET_OPERATIONAL_DATA', 'system', 'System', 0, null,
                'Reset semua data operasional', null, null
            );
            json_out(['message' => 'Reset berhasil. Semua data transaksi/log telah dibersihkan. Master data tetap aman.']);
            break;

        case 'security_audit':
            api_require_admin();
            $db = db();

            // Failed logins last 24h
            $stmt = $db->prepare("
                SELECT COUNT(*) AS cnt FROM security_audit_log
                WHERE event_type = 'login' AND success = 0
                  AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
            ");
            try {
                $stmt->execute();
                $failedLogins = (int)$stmt->fetch()['cnt'];
            } catch (\Throwable $e) {
                $failedLogins = 0;
            }

            // Active sessions
            $stmt = $db->prepare("SELECT COUNT(DISTINCT user_id) AS cnt FROM auth_tokens WHERE expires_at > NOW()");
            $stmt->execute();
            $activeSessions = (int)$stmt->fetch()['cnt'];

            // Users with write access
            $stmt = $db->prepare("SELECT COUNT(*) AS cnt FROM users WHERE role IN ('admin','operator','warehouse','supervisor','staff') AND is_active = 1");
            $stmt->execute();
            $writeUsers = (int)$stmt->fetch()['cnt'];

            // Recent sensitive actions
            $stmt = $db->prepare("
                SELECT al.action, al.created_at, u.username
                FROM activity_log al
                LEFT JOIN users u ON al.user_id = u.id
                WHERE al.action IN ('STOCK_ADJUST','STOCK_RECONCILIATION','RESET_OPERATIONAL_DATA','DELETE')
                ORDER BY al.created_at DESC
                LIMIT 20
            ");
            $stmt->execute();
            $sensitiveActions = $stmt->fetchAll();

            json_out([
                'audit' => [
                    'failed_logins_24h'   => $failedLogins,
                    'active_sessions'     => $activeSessions,
                    'write_users'         => $writeUsers,
                    'sensitive_actions'   => $sensitiveActions,
                    'audited_at'          => date('Y-m-d H:i:s'),
                ],
            ]);
            break;

        default:
            json_err('Invalid action: ' . $action, 404);
    }
}
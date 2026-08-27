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

        default:
            json_err('Invalid action: ' . $action, 404);
    }
}
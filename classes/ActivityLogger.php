<?php

class ActivityLogger {

    

    private static function ensureTable(\PDO $db): void {
        $db->exec("CREATE TABLE IF NOT EXISTS `activity_log` (
            `id`             INT AUTO_INCREMENT PRIMARY KEY,
            `user_id`        INT          DEFAULT NULL,
            `username`       VARCHAR(100) DEFAULT NULL,
            `full_name`      VARCHAR(100) DEFAULT NULL,
            `action`         VARCHAR(100) NOT NULL,
            `module`         VARCHAR(50)  NOT NULL,
            `reference_type` VARCHAR(50)  DEFAULT NULL,
            `reference_id`   INT          DEFAULT NULL,
            `reference_no`   VARCHAR(100) DEFAULT NULL,
            `description`    TEXT         DEFAULT NULL,
            `old_value`      TEXT         DEFAULT NULL,
            `new_value`      TEXT         DEFAULT NULL,
            `ip_address`     VARCHAR(45)  DEFAULT NULL,
            `created_at`     TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_user_id` (`user_id`),
            INDEX `idx_module`  (`module`),
            INDEX `idx_ref`     (`reference_type`, `reference_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public static function log(
        string  $action,
        string  $module,
        ?string $referenceType = null,
        ?int    $referenceId   = null,
        ?string $referenceNo   = null,
        ?string $description   = null,
        $oldValue = null,
        $newValue = null
    ): void {
        try {
            $db = db();
            self::ensureTable($db);

            $userId   = $_SESSION['user_id']   ?? null;
            $username = $_SESSION['username']  ?? null;
            $fullName = $_SESSION['full_name'] ?? null;
            $ip       = $_SERVER['REMOTE_ADDR'] ?? null;

            $stmt = $db->prepare("
                INSERT INTO activity_log
                    (user_id, username, full_name, action, module,
                     reference_type, reference_id, reference_no,
                     description, old_value, new_value, ip_address)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $userId,
                $username,
                $fullName,
                strtoupper($action),
                strtolower($module),
                $referenceType,
                $referenceId,
                $referenceNo,
                $description,
                $oldValue !== null ? json_encode($oldValue, JSON_UNESCAPED_UNICODE) : null,
                $newValue !== null ? json_encode($newValue, JSON_UNESCAPED_UNICODE) : null,
                $ip,
            ]);
        } catch (\Throwable $e) {
            $msg = date('Y-m-d H:i:s') . ' [ActivityLogger::log] ' . $e->getMessage()
                 . ' | action=' . $action . ' module=' . $module . "\n";
            error_log($msg);
            @file_put_contents(__DIR__ . '/../activity_log_debug.txt', $msg, FILE_APPEND);
        }
    }

    

    public static function getRecent(
        int     $limit   = 50,
        int     $offset  = 0,
        ?string $module  = null,
        ?int    $userId  = null,
        ?string $refType = null,
        ?int    $refId   = null
    ): array {
        try {
        $db     = db();
        self::ensureTable($db);
        $where  = ['1=1'];
        $params = [];

        if ($module)  { $where[] = 'module = ?';          $params[] = $module;  }
        if ($userId)  { $where[] = 'user_id = ?';         $params[] = $userId;  }
        if ($refType) { $where[] = 'reference_type = ?';  $params[] = $refType; }
        if ($refId)   { $where[] = 'reference_id = ?';    $params[] = $refId;   }

        $sql = "SELECT al.*,
                u.full_name AS user_full_name,
                u.role      AS user_role
                FROM activity_log al
                LEFT JOIN users u ON al.user_id = u.id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY al.created_at DESC
                LIMIT " . intval($limit) . " OFFSET " . intval($offset);

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
        } catch (\Throwable $e) {
            error_log('[ActivityLogger] getRecent error: ' . $e->getMessage());
            return [];
        }
    }



    public static function getForReference(string $refType, int $refId): array {
        return self::getRecent(200, 0, null, null, $refType, $refId);
    }

    

    public static function countRecent(
        ?string $module  = null,
        ?int    $userId  = null,
        ?string $refType = null,
        ?int    $refId   = null
    ): int {
        try {
        $db     = db();
        self::ensureTable($db);
        $where  = ['1=1'];
        $params = [];

        if ($module)  { $where[] = 'module = ?';          $params[] = $module;  }
        if ($userId)  { $where[] = 'user_id = ?';         $params[] = $userId;  }
        if ($refType) { $where[] = 'reference_type = ?';  $params[] = $refType; }
        if ($refId)   { $where[] = 'reference_id = ?';    $params[] = $refId;   }

        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM activity_log WHERE " . implode(' AND ', $where)
        );
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
        } catch (\Throwable $e) {
            error_log('[ActivityLogger] countRecent error: ' . $e->getMessage());
            return 0;
        }
    }



    public static function actionLabel(string $action): string {
        $labels = [
            'CREATE_INBOUND'     => 'Buat Inbound',
            'UPDATE_INBOUND'     => 'Edit Inbound',
            'DELETE_INBOUND'     => 'Hapus Inbound',
            'ADD_INBOUND_ITEM'   => 'Tambah Item Inbound',
            'DELETE_INBOUND_ITEM'=> 'Hapus Item Inbound',
            'UPDATE_ITEM_STATUS' => 'Update Status Item',
            'RECEIVE_INBOUND'    => 'Terima Inbound',
            'CREATE_OUTBOUND'    => 'Buat Outbound',
            'UPDATE_OUTBOUND'    => 'Edit Outbound',
            'DELETE_OUTBOUND'    => 'Hapus Outbound',
            'ADD_OUTBOUND_ITEM'  => 'Tambah Item Outbound',
            'DELETE_OUTBOUND_ITEM'=> 'Hapus Item Outbound',
            'PICK_OUTBOUND'      => 'Pick Outbound',
            'SHIP_OUTBOUND'      => 'Kirim Outbound',
            'COMPLETE_OUTBOUND'  => 'Selesai Outbound',
            'BIN_TRANSFER'       => 'Transfer Bin-to-Bin',
            'COMPLETE_BIN_TRANSFER' => 'Selesai Bin Transfer',
            'CANCEL_BIN_TRANSFER'   => 'Batal Bin Transfer',
            'SCAN_OVERRIDE'      => 'Override Scan (Mismatch)',
            'CREATE_ASN'         => 'Buat ASN',
            'UPDATE_ASN'         => 'Edit ASN',
            'CANCEL_ASN'         => 'Batal ASN',
            'RECOMPUTE_ABC'      => 'Recompute Analisis ABC',
            'CREATE_CYCLECOUNT'  => 'Buat Jadwal Cycle Count',
            'UPDATE_CYCLECOUNT'  => 'Edit Jadwal Cycle Count',
            'DELETE_CYCLECOUNT'  => 'Hapus Jadwal Cycle Count',
            'RUN_CYCLECOUNT'     => 'Run Cycle Count',
            'CREATE_PUTAWAY_BLOCK'      => 'Blokir Lokasi Putaway',
            'DEACTIVATE_PUTAWAY_BLOCK'  => 'Nonaktifkan Blokir Lokasi Putaway',
            'TASK_ASSIGN'        => 'Ambil Putaway Task',
            'TASK_UPDATE_PALLET' => 'Ubah Lokasi Pallet (Putaway)',
            'TASK_COMPLETE_PALLET' => 'Selesai Putaway Pallet',
            'TASK_COMPLETE'      => 'Selesaikan Putaway Task',
            'TASK_CANCEL'        => 'Batalkan Putaway Task',
            'TASK_TEAM_ASSIGN'   => 'Tugaskan Tim Putaway',
            'TASK_TEAM_UNASSIGN' => 'Hapus Penugasan Tim Putaway',
            'PRINT_LPN_LABEL'    => 'Cetak Label LPN',
            'RESET_OPERATIONAL_DATA' => 'Reset Data Operasional',
            'GENERATE_REPLENISHMENT'  => 'Generate Replenishment',
            'DEMAND_REPLENISHMENT'    => 'Demand Replenishment',
            'SAVE_PICK_FACE_TARGET'   => 'Simpan Target Pick-Face',
            'DELETE_PICK_FACE_TARGET' => 'Hapus Target Pick-Face',
        ];
        return $labels[$action] ?? ucwords(strtolower(str_replace('_', ' ', $action)));
    }

    public static function moduleIcon(string $module): string {
        $icons = [
            'inbound'       => 'fas fa-arrow-down',
            'outbound'      => 'fas fa-arrow-up',
            'bin_transfer'  => 'fas fa-exchange-alt',
            'stock'         => 'fas fa-boxes',
            'user'          => 'fas fa-user',
            'abc'           => 'fas fa-chart-pie',
            'cyclecount'    => 'fas fa-calendar-check',
            'system'        => 'fas fa-cog',
            'replenishment' => 'fas fa-sync-alt',
        ];
        return $icons[$module] ?? 'fas fa-circle';
    }

    public static function moduleColor(string $module): string {
        $colors = [
            'inbound'       => '#014f4e',
            'outbound'      => '#026766',
            'bin_transfer'  => '#026766',
            'stock'         => '#e65100',
            'user'          => '#37474f',
            'replenishment' => '#6a1b9a',
        ];
        return $colors[$module] ?? '#607d8b';
    }
}
?>

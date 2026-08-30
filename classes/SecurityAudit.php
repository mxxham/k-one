<?php
/**
 * Security Audit — logs sensitive operations for compliance and forensics.
 * Separate from ActivityLogger for security-specific events.
 */
class SecurityAudit {

    /**
     * Log a login attempt (success or failure).
     */
    public static function logLogin(string $username, bool $success, string $ipAddress, string $userAgent = ''): void {
        try {
            $db = db();
            $stmt = $db->prepare("
                INSERT INTO security_audit_log 
                (event_type, username, success, ip_address, user_agent, created_at)
                VALUES ('login', ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$username, $success ? 1 : 0, $ipAddress, $userAgent]);
            
            // Also log to activity_log for visibility
            ActivityLogger::log(
                $success ? 'LOGIN_SUCCESS' : 'LOGIN_FAILED',
                'auth',
                'User',
                null,
                null,
                "Login attempt for {$username} from {$ipAddress}: " . ($success ? 'SUCCESS' : 'FAILED')
            );
        } catch (\Throwable $e) {
            error_log('[SecurityAudit] logLogin error: ' . $e->getMessage());
        }
    }

    /**
     * Log a logout event.
     */
    public static function logLogout(int $userId, string $username, string $ipAddress): void {
        try {
            $db = db();
            $stmt = $db->prepare("
                INSERT INTO security_audit_log 
                (event_type, user_id, username, success, ip_address, action, created_at)
                VALUES ('logout', ?, ?, 1, ?, 'logout', NOW())
            ");
            $stmt->execute([$userId, $username, $ipAddress]);

            ActivityLogger::log(
                'LOGOUT',
                'auth',
                'User',
                $userId,
                $username,
                "User logout from {$ipAddress}"
            );
        } catch (\Throwable $e) {
            error_log('[SecurityAudit] logLogout error: ' . $e->getMessage());
        }
    }

    /**
     * Log a configuration change.
     */
    public static function logConfigChange(int $userId, string $configKey, $oldValue, $newValue): void {
        try {
            $db = db();
            $stmt = $db->prepare("
                INSERT INTO security_audit_log 
                (event_type, user_id, config_key, old_value, new_value, created_at)
                VALUES ('config_change', ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$userId, $configKey, json_encode($oldValue), json_encode($newValue)]);
            
            ActivityLogger::log(
                'CONFIG_CHANGE',
                'system',
                'Config',
                null,
                null,
                "Config '{$configKey}' changed from " . json_encode($oldValue) . " to " . json_encode($newValue)
            );
        } catch (\Throwable $e) {
            error_log('[SecurityAudit] logConfigChange error: ' . $e->getMessage());
        }
    }

    /**
     * Log a data export (Excel, PDF, CSV).
     */
    public static function logExport(int $userId, string $exportType, string $module, int $recordCount, string $format = 'xlsx'): void {
        try {
            $db = db();
            $stmt = $db->prepare("
                INSERT INTO security_audit_log 
                (event_type, user_id, export_type, module, record_count, format, created_at)
                VALUES ('data_export', ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$userId, $exportType, $module, $recordCount, $format]);
            
            ActivityLogger::log(
                'DATA_EXPORT',
                'export',
                ucfirst($module),
                null,
                null,
                "Exported {$recordCount} records from {$module} as {$format}"
            );
        } catch (\Throwable $e) {
            error_log('[SecurityAudit] logExport error: ' . $e->getMessage());
        }
    }

    /**
     * Log sensitive data access (viewing passwords, API keys, etc.).
     */
    public static function logDataAccess(int $userId, string $dataType, string $action, string $details = ''): void {
        try {
            $db = db();
            $stmt = $db->prepare("
                INSERT INTO security_audit_log 
                (event_type, user_id, data_type, action, details, created_at)
                VALUES ('data_access', ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$userId, $dataType, $action, $details]);
        } catch (\Throwable $e) {
            error_log('[SecurityAudit] logDataAccess error: ' . $e->getMessage());
        }
    }

    /**
     * Log privilege/role changes.
     */
    public static function logPrivilegeChange(int $adminUserId, int $targetUserId, string $oldRole, string $newRole): void {
        try {
            $db = db();
            $stmt = $db->prepare("
                INSERT INTO security_audit_log 
                (event_type, user_id, target_user_id, old_role, new_role, created_at)
                VALUES ('privilege_change', ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$adminUserId, $targetUserId, $oldRole, $newRole]);
            
            ActivityLogger::log(
                'PRIVILEGE_CHANGE',
                'auth',
                'User',
                $targetUserId,
                null,
                "User #{$targetUserId} role changed from {$oldRole} to {$newRole} by user #{$adminUserId}"
            );
        } catch (\Throwable $e) {
            error_log('[SecurityAudit] logPrivilegeChange error: ' . $e->getMessage());
        }
    }

    /**
     * Log password changes.
     */
    public static function logPasswordChange(int $userId, string $method = 'manual'): void {
        try {
            $db = db();
            $stmt = $db->prepare("
                INSERT INTO security_audit_log 
                (event_type, user_id, action, created_at)
                VALUES ('password_change', ?, ?, NOW())
            ");
            $stmt->execute([$userId, $method]);
        } catch (\Throwable $e) {
            error_log('[SecurityAudit] logPasswordChange error: ' . $e->getMessage());
        }
    }

    /**
     * Log API key usage.
     */
    public static function logApiKeyUsage(string $apiKeyId, string $endpoint, string $method): void {
        try {
            $db = db();
            $stmt = $db->prepare("
                INSERT INTO security_audit_log 
                (event_type, api_key_id, endpoint, http_method, created_at)
                VALUES ('api_key_usage', ?, ?, ?, NOW())
            ");
            $stmt->execute([$apiKeyId, $endpoint, $method]);
        } catch (\Throwable $e) {
            error_log('[SecurityAudit] logApiKeyUsage error: ' . $e->getMessage());
        }
    }

    /**
     * Get audit log with filtering.
     */
    public static function getAuditLog(array $filters = [], int $limit = 100, int $offset = 0): array {
        $db = db();
        $where = ['1=1'];
        $params = [];
        
        if (!empty($filters['event_type'])) {
            $where[] = 'event_type = ?';
            $params[] = $filters['event_type'];
        }
        if (!empty($filters['user_id'])) {
            $where[] = 'sal.user_id = ?';
            $params[] = $filters['user_id'];
        }
        if (!empty($filters['date_from'])) {
            $where[] = 'sal.created_at >= ?';
            $params[] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'sal.created_at <= ?';
            $params[] = $filters['date_to'];
        }
        if (!empty($filters['search'])) {
            $where[] = '(sal.username LIKE ? OR sal.config_key LIKE ? OR sal.endpoint LIKE ? OR sal.details LIKE ?)';
            $searchTerm = '%' . $filters['search'] . '%';
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }
        
        $sql = "SELECT sal.*, u.full_name AS user_full_name
                FROM security_audit_log sal
                LEFT JOIN users u ON sal.user_id = u.id
                WHERE " . implode(' AND ', $where) 
             . " ORDER BY sal.created_at DESC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Count audit log entries matching filters (for pagination).
     */
    public static function countAuditLog(array $filters = []): int {
        try {
            $db = db();
            $where = ['1=1'];
            $params = [];
            
            if (!empty($filters['event_type'])) {
                $where[] = 'event_type = ?';
                $params[] = $filters['event_type'];
            }
            if (!empty($filters['user_id'])) {
                $where[] = 'sal.user_id = ?';
                $params[] = $filters['user_id'];
            }
            if (!empty($filters['date_from'])) {
                $where[] = 'sal.created_at >= ?';
                $params[] = $filters['date_from'];
            }
            if (!empty($filters['date_to'])) {
                $where[] = 'sal.created_at <= ?';
                $params[] = $filters['date_to'];
            }
            if (!empty($filters['search'])) {
                $where[] = '(sal.username LIKE ? OR sal.config_key LIKE ? OR sal.endpoint LIKE ? OR sal.details LIKE ?)';
                $searchTerm = '%' . $filters['search'] . '%';
                $params[] = $searchTerm;
                $params[] = $searchTerm;
                $params[] = $searchTerm;
                $params[] = $searchTerm;
            }
            
            $sql = "SELECT COUNT(*) FROM security_audit_log sal WHERE " . implode(' AND ', $where);
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            return (int) $stmt->fetchColumn();
        } catch (\Throwable $e) {
            error_log('[SecurityAudit] countAuditLog error: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Get distinct event types for filter dropdown.
     */
    public static function getDistinctEventTypes(): array {
        try {
            $db = db();
            $stmt = $db->query("SELECT DISTINCT event_type, COUNT(*) AS cnt FROM security_audit_log GROUP BY event_type ORDER BY cnt DESC");
            return $stmt->fetchAll();
        } catch (\Throwable $e) {
            error_log('[SecurityAudit] getDistinctEventTypes error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get security audit summary stats.
     */
    public static function getAuditStats(): array {
        try {
            $db = db();
            $stats = [];

            // Total entries
            $stats['total'] = (int) $db->query("SELECT COUNT(*) FROM security_audit_log")->fetchColumn();

            // Failed logins last 24h
            $stmt = $db->prepare("
                SELECT COUNT(*) FROM security_audit_log
                WHERE event_type = 'login' AND success = 0
                  AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
            ");
            $stmt->execute();
            $stats['failed_logins_24h'] = (int) $stmt->fetchColumn();

            // Successful logins last 24h
            $stmt = $db->prepare("
                SELECT COUNT(*) FROM security_audit_log
                WHERE event_type = 'login' AND success = 1
                  AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
            ");
            $stmt->execute();
            $stats['successful_logins_24h'] = (int) $stmt->fetchColumn();

            // Exports last 24h
            $stmt = $db->prepare("
                SELECT COUNT(*) FROM security_audit_log
                WHERE event_type = 'data_export'
                  AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
            ");
            $stmt->execute();
            $stats['exports_24h'] = (int) $stmt->fetchColumn();

            // Privilege changes last 24h
            $stmt = $db->prepare("
                SELECT COUNT(*) FROM security_audit_log
                WHERE event_type = 'privilege_change'
                  AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
            ");
            $stmt->execute();
            $stats['privilege_changes_24h'] = (int) $stmt->fetchColumn();

            // Entries by event type
            $stats['by_type'] = $db->query("
                SELECT event_type, COUNT(*) AS cnt
                FROM security_audit_log
                GROUP BY event_type
                ORDER BY cnt DESC
            ")->fetchAll();

            return $stats;
        } catch (\Throwable $e) {
            error_log('[SecurityAudit] getAuditStats error: ' . $e->getMessage());
            return ['total' => 0, 'failed_logins_24h' => 0, 'successful_logins_24h' => 0, 'exports_24h' => 0, 'privilege_changes_24h' => 0, 'by_type' => []];
        }
    }

    /**
     * Export audit log to Excel.
     */
    public static function exportAuditLog(array $filters = []): void {
        $logs = self::getAuditLog($filters, 10000, 0);
        
        if (empty($logs)) {
            die('Tidak ada data audit log untuk di-export.');
        }

        $db = db();
        $user = $_SESSION['username'] ?? 'system';
        $total = count($logs);

        // Log this export event itself
        self::logExport(
            $_SESSION['user_id'] ?? 0,
            'security_audit',
            'audit_log',
            $total,
            'xlsx'
        );

        require_once __DIR__ . '/ExcelExport.php';

        $sp    = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $sp->getActiveSheet();
        $sheet->setTitle('Security Audit Log');

        $headerColor = '013D3C';
        $subColor    = '026766';

        $headers = [
            'No', 'Timestamp', 'Event Type', 'Username', 'Full Name',
            'Success', 'IP Address', 'User Agent',
            'Config Key', 'Old Value', 'New Value',
            'Export Type', 'Module', 'Record Count', 'Format',
            'Data Type', 'Action', 'Details',
            'Old Role', 'New Role', 'Target User ID',
            'API Key ID', 'Endpoint', 'HTTP Method'
        ];
        $totalCols     = count($headers);
        $lastColLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($totalCols);

        // Title row
        $sheet->mergeCells("A1:{$lastColLetter}1");
        $sheet->setCellValue('A1', 'K-one — Security Audit Log   |   Dicetak: ' . date('d F Y H:i') . ' WIB   |   ' . $total . ' entri');
        $sheet->getStyle('A1')->applyFromArray([
            'font'      => ['bold' => true, 'size' => 12, 'color' => ['rgb' => 'FFFFFF']],
            'fill'      => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => $headerColor]],
            'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER, 'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(26);

        // Header row
        foreach ($headers as $c => $h) {
            $sheet->setCellValue(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c + 1) . '2', $h);
        }
        $sheet->getStyle("A2:{$lastColLetter}2")->applyFromArray([
            'font'      => ['bold' => true, 'size' => 9, 'color' => ['rgb' => 'FFFFFF']],
            'fill'      => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['rgb' => $subColor]],
            'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER, 'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER, 'wrapText' => true],
            'borders'   => ['allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN, 'color' => ['rgb' => '014F4E']]],
        ]);
        $sheet->getRowDimension(2)->setRowHeight(24);
        $sheet->freezePane('A3');

        // Data rows
        $row = 3;
        foreach ($logs as $i => $log) {
            $data = [
                $i + 1,
                date('d M Y H:i:s', strtotime($log['created_at'])),
                $log['event_type'] ?? '',
                $log['username'] ?? '',
                $log['user_full_name'] ?? '',
                ($log['success'] ?? null) === 1 ? 'Yes' : (($log['success'] ?? null) === 0 ? 'No' : ''),
                $log['ip_address'] ?? '',
                $log['user_agent'] ?? '',
                $log['config_key'] ?? '',
                $log['old_value'] ?? '',
                $log['new_value'] ?? '',
                $log['export_type'] ?? '',
                $log['module'] ?? '',
                $log['record_count'] ?? '',
                $log['format'] ?? '',
                $log['data_type'] ?? '',
                $log['action'] ?? '',
                $log['details'] ?? '',
                $log['old_role'] ?? '',
                $log['new_role'] ?? '',
                $log['target_user_id'] ?? '',
                $log['api_key_id'] ?? '',
                $log['endpoint'] ?? '',
                $log['http_method'] ?? '',
            ];

            foreach ($data as $c => $val) {
                $sheet->setCellValue(
                    \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c + 1) . $row, $val
                );
            }

            $rangeFull = "A{$row}:{$lastColLetter}{$row}";
            $sheet->getStyle($rangeFull)->applyFromArray([
                'borders' => ['allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN, 'color' => ['rgb' => 'D1D5DB']]],
                'font'    => ['size' => 9],
            ]);
            if ($row % 2 === 0) {
                $sheet->getStyle($rangeFull)->getFill()
                    ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                    ->getStartColor()->setRGB('F0FDFC');
            }

            // Color-code failed logins
            if (($log['event_type'] ?? '') === 'login' && ($log['success'] ?? 1) == 0) {
                $sheet->getStyle("A{$row}:{$lastColLetter}{$row}")->getFill()
                    ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                    ->getStartColor()->setRGB('FEE2E2');
            }

            $row++;
        }

        // Auto-size columns
        foreach (range(1, $totalCols) as $c) {
            $sheet->getColumnDimension(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c))->setAutoSize(true);
        }

        // Download
        if (ob_get_length()) ob_end_clean();
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="Security_Audit_Log_' . date('Y-m-d_His') . '.xlsx"');
        header('Cache-Control: max-age=0');
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($sp))->save('php://output');
        exit;
    }
}

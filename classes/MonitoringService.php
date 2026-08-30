<?php

class MonitoringService {

    /**
     * System health check — DB connectivity, disk space, memory, PHP/MySQL versions.
     */
    public static function getHealthCheck(): array {
        $checks = [];

        // --- Database connectivity ---
        $dbStart = microtime(true);
        try {
            $db = db();
            $db->query("SELECT 1");
            $dbMs = round((microtime(true) - $dbStart) * 1000, 2);
            $checks['database'] = [
                'status'  => 'ok',
                'message' => 'Connected',
                'latency_ms' => $dbMs,
            ];
        } catch (\Throwable $e) {
            $checks['database'] = [
                'status'  => 'error',
                'message' => $e->getMessage(),
            ];
        }

        // --- MySQL version ---
        try {
            $checks['mysql_version'] = db()->query("SELECT VERSION()")->fetchColumn();
        } catch (\Throwable $e) {
            $checks['mysql_version'] = 'unknown';
        }

        // --- PHP version ---
        $checks['php_version'] = PHP_VERSION;

        // --- Disk space ---
        $freeBytes = @disk_free_space(__DIR__ . '/..');
        $totalBytes = @disk_total_space(__DIR__ . '/..');
        if ($freeBytes !== false && $totalBytes !== false && $totalBytes > 0) {
            $usedPct = round((($totalBytes - $freeBytes) / $totalBytes) * 100, 1);
            $checks['disk'] = [
                'status'     => $usedPct >= 90 ? 'warning' : 'ok',
                'free_gb'    => round($freeBytes / (1024 ** 3), 2),
                'total_gb'   => round($totalBytes / (1024 ** 3), 2),
                'used_pct'   => $usedPct,
                'message'    => $usedPct >= 90 ? 'Disk usage critical' : 'OK',
            ];
        } else {
            $checks['disk'] = ['status' => 'unknown', 'message' => 'Unable to determine disk space'];
        }

        // --- Memory ---
        $memLimit = @ini_get('memory_limit');
        $memUsed  = memory_get_usage(true);
        $memPeak  = memory_get_peak_usage(true);
        $memLimitBytes = self::convertBytes($memLimit);
        $checks['memory'] = [
            'status'     => 'ok',
            'used_mb'    => round($memUsed / (1024 ** 2), 2),
            'peak_mb'    => round($memPeak / (1024 ** 2), 2),
            'limit'      => $memLimit,
            'used_pct'   => $memLimitBytes > 0 ? round(($memUsed / $memLimitBytes) * 100, 1) : 0,
        ];

        // --- Uptime ---
        $checks['uptime_sec'] = round(microtime(true) - $_SERVER['REQUEST_TIME_FLOAT'], 3);

        // Overall status
        $hasError = in_array('error', array_column($checks, 'status'), true);
        $hasWarning = in_array('warning', array_column($checks, 'status'), true);

        return [
            'status' => $hasError ? 'error' : ($hasWarning ? 'warning' : 'healthy'),
            'checks' => $checks,
            'timestamp' => gmdate('c'),
        ];
    }

    /**
     * Key operational metrics — users, orders, stock movements, warehouse occupancy.
     */
    public static function getSystemMetrics(): array {
        $db = db();
        $today = date('Y-m-d');

        // --- Active users (logged in today via activity_log) ---
        $activeUsers = $db->query(
            "SELECT COUNT(DISTINCT user_id) as count
             FROM activity_log
             WHERE DATE(created_at) = CURDATE()"
        )->fetch()['count'] ?? 0;

        // --- Orders today ---
        $inboundToday = $db->query(
            "SELECT COUNT(*) as count FROM inbound_orders
             WHERE DATE(order_date) = CURDATE() OR DATE(created_at) = CURDATE()"
        )->fetch()['count'] ?? 0;

        $outboundToday = $db->query(
            "SELECT COUNT(*) as count FROM outbound_orders
             WHERE DATE(order_date) = CURDATE() OR DATE(created_at) = CURDATE()"
        )->fetch()['count'] ?? 0;

        // --- Stock movements today (stock_ledger) ---
        $stockMovements = $db->query(
            "SELECT COUNT(*) as count,
                    COALESCE(SUM(quantity_in), 0) as total_in,
                    COALESCE(SUM(quantity_out), 0) as total_out
             FROM stock_ledger
             WHERE transaction_date = CURDATE()"
        )->fetch();

        // --- Warehouse occupancy ---
        $totalLocations = $db->query(
            "SELECT COUNT(*) FROM location_master WHERE is_active = 1"
        )->fetchColumn() ?? 0;

        $occupiedLocations = $db->query(
            "SELECT COUNT(DISTINCT location) FROM stock
             WHERE quantity > 0 AND location IS NOT NULL AND location != ''"
        )->fetchColumn() ?? 0;

        // --- Total stock ---
        $stockSummary = $db->query(
            "SELECT
                COUNT(DISTINCT product_id) as total_products,
                COALESCE(SUM(quantity), 0) as total_qty,
                COUNT(CASE WHEN stock_status = 'Available' THEN 1 END) as available,
                COUNT(CASE WHEN stock_status = 'Reserved' THEN 1 END) as reserved,
                COUNT(CASE WHEN stock_status = 'Expired' THEN 1 END) as expired
             FROM stock
             WHERE quantity > 0"
        )->fetch();

        return [
            'date' => $today,
            'active_users' => (int)$activeUsers,
            'orders' => [
                'inbound_today' => (int)$inboundToday,
                'outbound_today' => (int)$outboundToday,
            ],
            'stock_movements' => [
                'count'   => (int)($stockMovements['count'] ?? 0),
                'qty_in'  => (float)($stockMovements['total_in'] ?? 0),
                'qty_out' => (float)($stockMovements['total_out'] ?? 0),
            ],
            'warehouse' => [
                'total_locations'  => (int)$totalLocations,
                'occupied'         => (int)$occupiedLocations,
                'occupancy_pct'    => $totalLocations > 0
                    ? round(($occupiedLocations / $totalLocations) * 100, 1)
                    : 0,
            ],
            'stock' => [
                'total_products' => (int)($stockSummary['total_products'] ?? 0),
                'total_qty'      => (float)($stockSummary['total_qty'] ?? 0),
                'available'      => (int)($stockSummary['available'] ?? 0),
                'reserved'       => (int)($stockSummary['reserved'] ?? 0),
                'expired'        => (int)($stockSummary['expired'] ?? 0),
            ],
            'timestamp' => gmdate('c'),
        ];
    }

    /**
     * Active alerts — low stock, expiring items, pending orders, held stock.
     */
    public static function getAlerts(): array {
        $db = db();
        $alerts = [];

        // --- Expiring soon (≤30 days) ---
        $expiring = $db->query(
            "SELECT s.id, s.product_id, s.batch_number, s.expiry_date, s.location, s.quantity,
                    p.product_code, p.product_name,
                    DATEDIFF(s.expiry_date, CURDATE()) as days_left
             FROM stock s
             JOIN products p ON s.product_id = p.id
             WHERE s.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
               AND s.quantity > 0 AND s.stock_status = 'Available'
             ORDER BY s.expiry_date ASC
             LIMIT 20"
        )->fetchAll();

        foreach ($expiring as $item) {
            $severity = $item['days_left'] <= 7 ? 'critical' : 'warning';
            $alerts[] = [
                'type'     => 'expiring_soon',
                'severity' => $severity,
                'message'  => "{$item['product_name']} ({$item['batch_number']}) expires in {$item['days_left']} days",
                'details'  => [
                    'product_code' => $item['product_code'],
                    'product_name' => $item['product_name'],
                    'batch_number' => $item['batch_number'],
                    'expiry_date'  => $item['expiry_date'],
                    'days_left'    => (int)$item['days_left'],
                    'location'     => $item['location'],
                    'quantity'     => (float)$item['quantity'],
                ],
            ];
        }

        // --- Already expired ---
        $expiredCount = $db->query(
            "SELECT COUNT(*) as count FROM stock
             WHERE expiry_date < CURDATE() AND quantity > 0"
        )->fetch()['count'] ?? 0;

        if ($expiredCount > 0) {
            $alerts[] = [
                'type'     => 'expired',
                'severity' => 'critical',
                'message'  => "{$expiredCount} stock batch(es) already expired",
                'details'  => ['count' => (int)$expiredCount],
            ];
        }

        // --- Pending inbound orders (not yet received) ---
        $pendingInbound = $db->query(
            "SELECT COUNT(*) as count FROM inbound_orders
             WHERE status IN ('Draft', 'Dues In', 'Receiving')"
        )->fetch()['count'] ?? 0;

        if ($pendingInbound > 0) {
            $alerts[] = [
                'type'     => 'pending_inbound',
                'severity' => 'info',
                'message'  => "{$pendingInbound} inbound order(s) awaiting receive",
                'details'  => ['count' => (int)$pendingInbound],
            ];
        }

        // --- Pending outbound orders (open/picking) ---
        $pendingOutbound = $db->query(
            "SELECT COUNT(*) as count FROM outbound_orders
             WHERE status IN ('Open', 'Picking', 'Picked')"
        )->fetch()['count'] ?? 0;

        if ($pendingOutbound > 0) {
            $alerts[] = [
                'type'     => 'pending_outbound',
                'severity' => 'info',
                'message'  => "{$pendingOutbound} outbound order(s) in progress",
                'details'  => ['count' => (int)$pendingOutbound],
            ];
        }

        // --- Held / quarantined stock ---
        $heldStock = $db->query(
            "SELECT COUNT(*) as count FROM stock
             WHERE hold_status IN ('on_hold', 'quarantine', 'damaged')
               AND quantity > 0"
        )->fetch()['count'] ?? 0;

        if ($heldStock > 0) {
            $alerts[] = [
                'type'     => 'held_stock',
                'severity' => 'warning',
                'message'  => "{$heldStock} stock batch(es) on hold/quarantine",
                'details'  => ['count' => (int)$heldStock],
            ];
        }

        // --- Overdue picklists ---
        $overduePicklists = $db->query(
            "SELECT COUNT(*) as count FROM picklists
             WHERE status IN ('Draft', 'Confirmed', 'Picking')
               AND due_date < CURDATE()"
        )->fetch()['count'] ?? 0;

        if ($overduePicklists > 0) {
            $alerts[] = [
                'type'     => 'overdue_picklists',
                'severity' => 'warning',
                'message'  => "{$overduePicklists} picklist(s) past due date",
                'details'  => ['count' => (int)$overduePicklists],
            ];
        }

        // Sort: critical first, then warning, then info
        $severityOrder = ['critical' => 0, 'warning' => 1, 'info' => 2];
        usort($alerts, function ($a, $b) use ($severityOrder) {
            return ($severityOrder[$a['severity']] ?? 3) <=> ($severityOrder[$b['severity']] ?? 3);
        });

        return [
            'alerts' => $alerts,
            'count'  => count($alerts),
            'timestamp' => gmdate('c'),
        ];
    }

    /**
     * Performance stats — activity log rates, error counts, recent throughput.
     */
    public static function getPerformanceStats(): array {
        $db = db();

        // --- Activity log volume (last 24h, per hour) ---
        $hourlyActivity = $db->query(
            "SELECT DATE_FORMAT(created_at, '%Y-%m-%d %H:00') as hour_bucket,
                    COUNT(*) as total,
                    COUNT(CASE WHEN action LIKE '%ERROR%' OR action LIKE '%FAIL%' THEN 1 END) as errors
             FROM activity_log
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
             GROUP BY hour_bucket
             ORDER BY hour_bucket ASC"
        )->fetchAll();

        // --- Total actions last 24h ---
        $total24h = array_sum(array_column($hourlyActivity, 'total'));
        $errors24h = array_sum(array_column($hourlyActivity, 'errors'));

        // --- Actions by type (last 24h) ---
        $actionsByType = $db->query(
            "SELECT action, COUNT(*) as count
             FROM activity_log
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
             GROUP BY action
             ORDER BY count DESC
             LIMIT 10"
        )->fetchAll();

        // --- Inbound throughput (last 7 days) ---
        $inboundWeekly = $db->query(
            "SELECT DATE(COALESCE(received_date, order_date, created_at)) as day,
                    COUNT(*) as orders
             FROM inbound_orders
             WHERE COALESCE(received_date, order_date, created_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
             GROUP BY day
             ORDER BY day ASC"
        )->fetchAll();

        // --- Outbound throughput (last 7 days) ---
        $outboundWeekly = $db->query(
            "SELECT DATE(COALESCE(shipped_date, order_date, created_at)) as day,
                    COUNT(*) as orders
             FROM outbound_orders
             WHERE COALESCE(shipped_date, order_date, created_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
             GROUP BY day
             ORDER BY day ASC"
        )->fetchAll();

        // --- Average response time (uptime check) ---
        $startTime = microtime(true);
        db()->query("SELECT 1");
        $dbLatencyMs = round((microtime(true) - $startTime) * 1000, 2);

        return [
            'activity_24h' => [
                'total'  => (int)$total24h,
                'errors' => (int)$errors24h,
                'error_rate_pct' => $total24h > 0
                    ? round(($errors24h / $total24h) * 100, 2)
                    : 0,
            ],
            'hourly_activity'  => $hourlyActivity,
            'actions_by_type'  => $actionsByType,
            'throughput' => [
                'inbound_7d'  => $inboundWeekly,
                'outbound_7d' => $outboundWeekly,
            ],
            'db_latency_ms' => $dbLatencyMs,
            'timestamp'     => gmdate('c'),
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Helpers                                                             */
    /* ------------------------------------------------------------------ */

    /**
     * Convert PHP ini memory string (e.g. "256M", "1G") to bytes.
     */
    private static function convertBytes(string $value): int {
        $value = trim($value);
        $unit = strtolower(substr($value, -1));
        $number = (int)$value;
        return match ($unit) {
            'g' => $number * (1024 ** 3),
            'm' => $number * (1024 ** 2),
            'k' => $number * 1024,
            default => $number,
        };
    }
}

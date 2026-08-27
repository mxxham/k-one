<?php

class Report {
    public static function getDailyReport($date = null, $dateTo = null) {
        if (!$date) $date = date('Y-m-d');
        if (!$dateTo) $dateTo = $date;

        $db = db();

        $report = [
            'date'              => $date,
            'date_to'           => $dateTo,
            'stock_summary'     => self::getStockSummary($date, $dateTo),
            'inbound_activity'  => self::getInboundActivity($date, $dateTo),
            'outbound_activity' => self::getOutboundActivity($date, $dateTo),
            'expiring_items'    => self::getExpiringItems(),
            'low_stock'         => self::getLowStock(),
            'ledger_summary'    => self::getLedgerSummary($date, $dateTo),
        ];

        return $report;
    }

    private static function getStockSummary($dateFrom = null, $dateTo = null) {
        $db = db();
        // Always return current stock position — date range is for activity sections only
        return $db->query("SELECT
                p.product_code, p.product_name,
                COALESCE(p.uom_type, 'Drum') as uom_type,
                COUNT(s.id) as batches,
                SUM(s.quantity) as total_qty, SUM(s.quantity) as total_drums,
                SUM(CEILING(s.quantity / GREATEST(p.uom_per_pallet,1))) as total_pallets,
                MIN(s.expiry_date) as nearest_expiry
                FROM stock s
                JOIN products p ON s.product_id = p.id
                WHERE s.quantity > 0 AND s.stock_status = 'Available'
                GROUP BY p.id
                ORDER BY p.product_name")->fetchAll();
    }

    private static function getInboundActivity($dateFrom, $dateTo) {
        $db = db();
        // Match on order_date (planned), received_date (actual receive), or created_at
        $stmt = $db->prepare("SELECT io.*, COUNT(ii.id) as item_count,
                SUM(COALESCE(ii.actual_qty, ii.quantity, 0)) as total_drums
                FROM inbound_orders io
                LEFT JOIN inbound_items ii ON io.id = ii.inbound_order_id
                WHERE (DATE(io.order_date) BETWEEN ? AND ?)
                   OR (DATE(io.received_date) BETWEEN ? AND ?)
                   OR (DATE(io.created_at) BETWEEN ? AND ?)
                GROUP BY io.id
                ORDER BY COALESCE(io.received_date, io.order_date) DESC, io.id DESC");
        $stmt->execute([$dateFrom, $dateTo, $dateFrom, $dateTo, $dateFrom, $dateTo]);
        return $stmt->fetchAll();
    }

    private static function getOutboundActivity($dateFrom, $dateTo) {
        $db = db();
        // Match on order_date (planned), shipped_date if available, or created_at
        $stmt = $db->prepare("SELECT oo.*, COUNT(oi.id) as item_count,
                SUM(COALESCE(oi.actual_qty, oi.quantity, 0)) as total_drums
                FROM outbound_orders oo
                LEFT JOIN outbound_items oi ON oo.id = oi.outbound_order_id
                WHERE (DATE(oo.order_date) BETWEEN ? AND ?)
                   OR (DATE(oo.created_at) BETWEEN ? AND ?)
                GROUP BY oo.id
                ORDER BY oo.order_date DESC, oo.id DESC");
        $stmt->execute([$dateFrom, $dateTo, $dateFrom, $dateTo]);
        return $stmt->fetchAll();
    }

    private static function getExpiringItems() {
        $db = db();
        return $db->query("SELECT s.*, p.product_code, p.product_name,
                DATEDIFF(s.expiry_date, CURDATE()) as days_until_expiry
                FROM stock s
                JOIN products p ON s.product_id = p.id
                WHERE s.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 180 DAY)
                AND s.quantity > 0 AND s.stock_status = 'Available'
                ORDER BY s.expiry_date ASC
                LIMIT 50")->fetchAll();
    }

    private static function getLowStock() {
        $db = db();
        
        return $db->query("SELECT p.product_code, p.product_name,
                SUM(s.quantity) as total_drums
                FROM stock s
                JOIN products p ON s.product_id = p.id
                WHERE s.quantity > 0 AND s.stock_status = 'Available'
                GROUP BY p.id
                HAVING total_drums < 16
                ORDER BY total_drums ASC")->fetchAll();
    }

    private static function getLedgerSummary($dateFrom, $dateTo) {
        $db = db();
        $stmt = $db->prepare("SELECT
                COUNT(CASE WHEN transaction_type = 'IN' THEN 1 END) as transactions_in,
                COUNT(CASE WHEN transaction_type = 'OUT' THEN 1 END) as transactions_out,
                SUM(CASE WHEN transaction_type = 'IN' THEN COALESCE(quantity_in, 0) ELSE 0 END) as qty_in,
                SUM(CASE WHEN transaction_type = 'OUT' THEN COALESCE(quantity_out, 0) ELSE 0 END) as qty_out
                FROM stock_ledger
                WHERE (transaction_date BETWEEN ? AND ?) OR (DATE(created_at) BETWEEN ? AND ?)");
        $stmt->execute([$dateFrom, $dateTo, $dateFrom, $dateTo]);
        $ledgerResult = $stmt->fetch();

        if (($ledgerResult['qty_in'] ?? 0) == 0 && ($ledgerResult['qty_out'] ?? 0) == 0) {
            $stmtIn = $db->prepare("SELECT
                COUNT(DISTINCT io.id) as transactions_in,
                COALESCE(SUM(ii.actual_qty), 0) as qty_in
                FROM inbound_orders io
                LEFT JOIN inbound_items ii ON io.id = ii.inbound_order_id
                WHERE (DATE(io.order_date) BETWEEN ? AND ?) OR (DATE(io.created_at) BETWEEN ? AND ?)");
            $stmtIn->execute([$dateFrom, $dateTo, $dateFrom, $dateTo]);
            $inResult = $stmtIn->fetch();

            $stmtOut = $db->prepare("SELECT
                COUNT(DISTINCT oo.id) as transactions_out,
                COALESCE(SUM(oi.actual_qty), 0) as qty_out
                FROM outbound_orders oo
                LEFT JOIN outbound_items oi ON oo.id = oi.outbound_order_id
                WHERE (DATE(oo.order_date) BETWEEN ? AND ?) OR (DATE(oo.created_at) BETWEEN ? AND ?)");
            $stmtOut->execute([$dateFrom, $dateTo, $dateFrom, $dateTo]);
            $outResult = $stmtOut->fetch();

            return [
                'transactions_in'  => $inResult['transactions_in'] ?? 0,
                'transactions_out' => $outResult['transactions_out'] ?? 0,
                'qty_in'           => $inResult['qty_in'] ?? 0,
                'qty_out'          => $outResult['qty_out'] ?? 0,
            ];
        }

        return $ledgerResult;
    }

    // =========================================================================
    // Dashboard — v2 parity
    // =========================================================================

    public static function dashboardStats(): array {
        $db = db();

        // Get active rack prefixes dynamically from location_master
        $activeRacks = self::getActiveRackPrefixes($db);

        $stockByUom = $db->query(
            "SELECT p.uom_type, p.uom_per_pallet,
                    SUM(s.quantity) as total_qty
             FROM stock s JOIN products p ON s.product_id = p.id
             WHERE s.stock_status = 'Available' GROUP BY p.id, p.uom_type, p.uom_per_pallet"
        )->fetchAll();
        $totalQty = 0;
        $totalPallets = 0;
        foreach ($stockByUom as $u) {
            $totalQty += $u['total_qty'];
            $upp = (int)($u['uom_per_pallet'] ?: 4);
            $totalPallets += (int)ceil($u['total_qty'] / $upp);
        }

        $prevWeek = $db->query(
            "SELECT COALESCE(SUM(balance), 0) as prev_total
             FROM stock_ledger
             WHERE transaction_date = CURDATE() - INTERVAL 7 DAY
             AND id IN (
               SELECT MAX(id) FROM stock_ledger
               WHERE transaction_date <= CURDATE() - INTERVAL 7 DAY
               GROUP BY product_id, batch_number
             )"
        )->fetch();
        $prevTotal = (float)($prevWeek['prev_total'] ?? $totalQty);
        $stockTrend = $prevTotal > 0 ? round((($totalQty - $prevTotal) / $prevTotal) * 100, 2) : 0;

        $expiringSoon = (int)$db->query(
            "SELECT COUNT(*) as c FROM stock
             WHERE expiry_date <= DATE_ADD(CURDATE(), INTERVAL 120 DAY)
             AND expiry_date > CURDATE() AND stock_status = 'Available'"
        )->fetch()['c'];
        $expiredCount = (int)$db->query(
            "SELECT COUNT(*) as c FROM stock
             WHERE expiry_date < CURDATE() AND stock_status = 'Available'"
        )->fetch()['c'];
        $duesInCount = (int)$db->query(
            "SELECT COUNT(*) as c FROM inbound_orders WHERE status = 'Dues In'"
        )->fetch()['c'];
        $receivingNow = (int)$db->query(
            "SELECT COUNT(*) as c FROM inbound_orders WHERE status = 'Receiving'"
        )->fetch()['c'];
        $pendingOutboundCount = (int)$db->query(
            "SELECT COUNT(*) as c FROM outbound_orders WHERE status IN ('Open','Picking')"
        )->fetch()['c'];
        $dispatchedToday = (int)$db->query(
            "SELECT COUNT(*) as c FROM outbound_orders
             WHERE status = 'Completed' AND DATE(updated_at) = CURDATE()"
        )->fetch()['c'];
        $receivedToday = (int)$db->query(
            "SELECT COUNT(*) as c FROM inbound_orders
             WHERE status IN ('Goods Received','Good Received') AND DATE(updated_at) = CURDATE()"
        )->fetch()['c'];
        $todayInbound = (int)$db->query(
            "SELECT COUNT(*) as c FROM inbound_orders WHERE order_date = CURDATE()"
        )->fetch()['c'];
        $todayOutbound = (int)$db->query(
            "SELECT COUNT(*) as c FROM outbound_orders WHERE order_date = CURDATE()"
        )->fetch()['c'];

        $expiredDetail = [];
        if ($expiredCount > 0) {
            $expiredDetail = $db->query(
                "SELECT p.product_code, p.product_name, s.batch_number, s.expiry_date,
                        SUM(s.quantity) as qty, SUM(s.pallet) as pallet
                 FROM stock s JOIN products p ON s.product_id = p.id
                 WHERE s.expiry_date < CURDATE() AND s.stock_status = 'Available'
                 GROUP BY p.id, p.product_code, p.product_name, s.batch_number, s.expiry_date
                 ORDER BY s.expiry_date ASC LIMIT 5"
            )->fetchAll();
        }

        $stockSummary = $db->query(
            "SELECT p.id, p.product_code, p.product_name, p.uom_type, p.uom_per_pallet,
                    COUNT(DISTINCT s.batch_number) as batches,
                    SUM(s.quantity) as total_qty, SUM(s.pallet) as total_pallet,
                    MIN(s.expiry_date) as nearest_expiry,
                    SUM(CASE WHEN s.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 120 DAY)
                             AND s.expiry_date > CURDATE() THEN 1 ELSE 0 END) as expiring_count
             FROM stock s JOIN products p ON s.product_id = p.id
             WHERE s.stock_status = 'Available'
             GROUP BY p.id HAVING SUM(s.quantity) > 0
             ORDER BY nearest_expiry ASC, total_qty DESC LIMIT 25"
        )->fetchAll();

        $monthlyActivity = $db->query(
            "SELECT DATE_FORMAT(transaction_date, '%Y-%m') as month,
                    SUM(CASE WHEN transaction_type = 'IN' THEN quantity_in ELSE 0 END) as inbound_qty,
                    SUM(CASE WHEN transaction_type = 'OUT' THEN quantity_out ELSE 0 END) as outbound_qty
             FROM stock_ledger WHERE transaction_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
             GROUP BY DATE_FORMAT(transaction_date, '%Y-%m') ORDER BY month DESC"
        )->fetchAll();

        $stockByLocation = $db->query(
            "SELECT lm.aisle,
                    COUNT(DISTINCT lm.location_code) as total_locs,
                    COUNT(DISTINCT CASE WHEN s1.quantity > 0 OR s2.quantity > 0 THEN lm.location_code END) as occupied_locs,
                    COALESCE(SUM(CASE WHEN s1.quantity > 0 THEN s1.quantity ELSE s2.quantity END), 0) as total_qty,
                    CEIL(COALESCE(SUM(CASE WHEN s1.quantity > 0 THEN s1.pallet ELSE s2.pallet END), 0)) as total_pallet
             FROM location_master lm
             LEFT JOIN stock_locations sl ON sl.location_code COLLATE utf8mb4_general_ci = lm.location_code COLLATE utf8mb4_general_ci
                 AND sl.status IN ('Available','Reserved')
             LEFT JOIN stock s1 ON sl.stock_id = s1.id AND s1.quantity > 0
             LEFT JOIN stock s2 ON s2.location COLLATE utf8mb4_general_ci = lm.location_code COLLATE utf8mb4_general_ci
                 AND s2.quantity > 0 AND s2.stock_status = 'Available' AND s1.id IS NULL
             WHERE lm.is_active = 1
               AND LEFT(lm.location_code, 2) IN ('" . implode("','", $activeRacks) . "')
             GROUP BY lm.aisle ORDER BY lm.aisle"
        )->fetchAll();

        $recentActivity = $db->query(
            "SELECT sl.*, p.product_code, p.product_name
             FROM stock_ledger sl JOIN products p ON sl.product_id = p.id
             ORDER BY sl.id DESC LIMIT 8"
        )->fetchAll();

        $pendingInbound = $db->query(
            "SELECT io.id, io.order_number, io.status, io.order_date, io.shipment_no, io.carrier_name,
                    COUNT(ii.id) AS line_count, COALESCE(SUM(ii.quantity), 0) AS total_qty
             FROM inbound_orders io
             LEFT JOIN inbound_items ii ON ii.inbound_order_id = io.id
             WHERE io.status IN ('Dues In','Receiving')
             GROUP BY io.id, io.order_number, io.status, io.order_date, io.shipment_no, io.carrier_name
             ORDER BY FIELD(io.status,'Receiving','Dues In'), io.order_date ASC LIMIT 10"
        )->fetchAll();

        $pendingOutbound = $db->query(
            "SELECT oo.id, oo.order_number, oo.status, oo.order_date, oo.shipment_number,
                    COUNT(oi.id) AS line_count, COALESCE(SUM(oi.quantity), 0) AS total_qty
             FROM outbound_orders oo
             LEFT JOIN outbound_items oi ON oi.outbound_order_id = oo.id
             WHERE oo.status IN ('Open','Picking')
             GROUP BY oo.id, oo.order_number, oo.status, oo.order_date, oo.shipment_number
             ORDER BY FIELD(oo.status,'Picking','Open'), oo.order_date ASC LIMIT 10"
        )->fetchAll();

        $pickAccuracy = $db->query(
            "SELECT
              COUNT(*) as total_lines,
              COUNT(CASE WHEN oi.actual_qty IS NULL OR ABS(oi.actual_qty - oi.quantity) < 0.01 THEN 1 END) as accurate_lines
             FROM outbound_orders oo
             JOIN outbound_items oi ON oi.outbound_order_id = oo.id
             WHERE oo.status IN ('Shipped', 'Completed')
             AND (oo.shipped_date = CURDATE() OR DATE(oo.updated_at) = CURDATE())"
        )->fetch();
        $totalLines = (int)($pickAccuracy['total_lines'] ?? 0);
        $accurateLines = (int)($pickAccuracy['accurate_lines'] ?? 0);
        $pickAccuracyRate = $totalLines > 0 ? round(($accurateLines / $totalLines) * 100, 2) : 100;

        $agingInventory = $db->query(
            "SELECT
              COUNT(DISTINCT s.id) as aging_batch_count,
              SUM(s.quantity) as aging_quantity
             FROM stock s
             LEFT JOIN stock_ledger sl ON sl.product_id = s.product_id
               AND sl.batch_number <=> s.batch_number
               AND sl.transaction_type = 'IN'
               AND sl.reference_type = 'Inbound'
             WHERE s.stock_status = 'Available'
             AND s.quantity > 0
             AND (sl.transaction_date IS NULL OR sl.transaction_date <= CURDATE() - INTERVAL 90 DAY)"
        )->fetch();
        $agingQty = (float)($agingInventory['aging_quantity'] ?? 0);

        $shippedToday = $db->query(
            "SELECT
              COUNT(DISTINCT oo.id) as orders,
              COALESCE(SUM(oi.quantity), 0) as total_quantity
             FROM outbound_orders oo
             LEFT JOIN outbound_items oi ON oi.outbound_order_id = oo.id
             WHERE oo.status IN ('Shipped', 'Completed')
             AND (oo.shipped_date = CURDATE() OR DATE(oo.updated_at) = CURDATE())"
        )->fetch();

        $totalLocations = 0;
        $occupiedLocations = 0;
        foreach ($stockByLocation as $loc) {
            $totalLocations += (int)($loc['total_locs'] ?? 0);
            $occupiedLocations += (int)($loc['occupied_locs'] ?? 0);
        }

        return [
            'kpi' => [
                'total_qty'                 => (float)$totalQty,
                'total_drums_trend'         => (float)$stockTrend,
                'total_pallets'             => (float)$totalPallets,
                'total_pallets_utilization' => 0,
                'expiring_soon'             => $expiringSoon,
                'expired_items'             => $expiredCount,
                'dues_in'                   => $duesInCount,
                'receiving_now'             => $receivingNow,
                'pending_outbound'          => $pendingOutboundCount,
                'dispatched_today'          => $dispatchedToday,
                'received_today'            => $receivedToday,
                'today_inbound'             => $todayInbound,
                'today_outbound'            => $todayOutbound,
                'stock_by_uom'              => $stockByUom,
                'pick_accuracy_percent'     => (float)$pickAccuracyRate,
                'pick_accurate_lines'       => $accurateLines,
                'pick_total_lines'          => $totalLines,
                'shipped_today_orders'      => (int)($shippedToday['orders'] ?? 0),
                'shipped_today_quantity'    => (float)($shippedToday['total_quantity'] ?? 0),
                'aging_batch_count'         => (int)($agingInventory['aging_batch_count'] ?? 0),
                'aging_quantity'            => $agingQty,
                'aging_pallets'             => (float)0,
                'total_locations'           => $totalLocations,
                'occupied_locations'        => $occupiedLocations,
            ],
            'expired_detail'    => $expiredDetail,
            'stock_summary'     => $stockSummary,
            'monthly_activity'  => $monthlyActivity,
            'stock_by_location' => $stockByLocation,
            'recent_activity'   => $recentActivity,
            'pending_inbound'   => $pendingInbound,
            'pending_outbound'  => $pendingOutbound,
        ];
    }

    public static function aisleDetail(string $aisle): array {
        $db = db();
        $stmt = $db->prepare("
            SELECT
                lm.location_code AS code, lm.rack, lm.row_name, lm.zone,
                COALESCE(s1.quantity, s2.quantity, 0) AS qty,
                COALESCE(s1.pallet, s2.pallet, 0) AS pallet,
                COALESCE(s1.uom, s2.uom) AS uom,
                COALESCE(s1.batch_number, s2.batch_number) AS batch,
                COALESCE(s1.expiry_date, s2.expiry_date) AS expiry,
                COALESCE(p1.product_name, p2.product_name) AS product,
                COALESCE(p1.product_code, p2.product_code) AS product_code,
                COALESCE(p1.uom_per_pallet, p2.uom_per_pallet) AS uom_per_pallet
            FROM location_master lm
            LEFT JOIN stock_locations sl
                ON sl.location_code COLLATE utf8mb4_general_ci = lm.location_code COLLATE utf8mb4_general_ci
                AND sl.status IN ('Available','Reserved')
            LEFT JOIN stock s1 ON sl.stock_id = s1.id AND s1.quantity > 0
            LEFT JOIN products p1 ON s1.product_id = p1.id
            LEFT JOIN stock s2
                ON s2.location COLLATE utf8mb4_general_ci = lm.location_code COLLATE utf8mb4_general_ci
                AND s2.quantity > 0 AND s2.stock_status = 'Available' AND s1.id IS NULL
            LEFT JOIN products p2 ON s2.product_id = p2.id
            WHERE lm.aisle = ? AND lm.is_active = 1
            ORDER BY lm.rack, lm.row_name, lm.position");
        $stmt->execute([$aisle]);
        $locations = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($locations as &$l) {
            $l['qty'] = (float)$l['qty'];
            $l['pallet'] = (float)$l['pallet'];
            $l['is_eceran'] = ($l['row_name'] === 'A');
            $upp = (int)($l['uom_per_pallet'] ?? 4);
            $l['is_partial'] = $l['is_eceran'] || (!$l['is_eceran'] && $l['qty'] > 0 && $upp > 0 && $l['qty'] < $upp);
            if (!$l['is_eceran'] && $l['pallet'] > 0) $l['pallet'] = (int)ceil($l['pallet']);
            $l['expiry'] = $l['expiry'] ? self::formatDayMonthYear($l['expiry']) : $l['expiry'];
        }
        unset($l);
        $total = count($locations);
        $occupied = count(array_filter($locations, fn($l) => $l['qty'] > 0));
        $totalQty = array_sum(array_column($locations, 'qty'));
        $totalPlt = (int)ceil(array_sum(array_column($locations, 'pallet')));
        return [
            'locations' => $locations,
            'stats'     => [
                'aisle'       => $aisle,
                'total'       => $total,
                'occupied'    => $occupied,
                'total_qty'   => number_format($totalQty, 0),
                'total_pallet'=> $totalPlt,
            ],
        ];
    }

    public static function checkExpiryAlerts(): array {
        $db = db();
        $expiring = $db->query(
            "SELECT p.product_code, p.product_name, s.batch_number, s.expiry_date, s.location,
                    s.quantity, s.pallet, s.uom,
                    DATEDIFF(s.expiry_date, CURDATE()) as days_until_expiry
             FROM stock s
             JOIN products p ON s.product_id = p.id
             WHERE s.stock_status = 'Available'
             AND s.quantity > 0
             AND s.expiry_date IS NOT NULL
             AND s.expiry_date > CURDATE()
             AND s.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
             ORDER BY s.expiry_date ASC, p.product_name"
        )->fetchAll();
        $count = count($expiring);
        $totalQty = 0;
        $totalPallets = 0;
        foreach ($expiring as $r) { $totalQty += $r['quantity']; $totalPallets += $r['pallet']; }
        return [
            'alert_count'     => $count,
            'total_quantity'  => round($totalQty, 2),
            'total_pallets'   => (int)ceil($totalPallets),
            'items'           => $expiring,
            'message'         => $count === 0
                ? 'Tidak ada stock yang akan expired dalam 30 hari'
                : "Ditemukan $count batch stock yang akan expired dalam 30 hari (" . round($totalQty) . " units, " . (int)ceil($totalPallets) . " pallets)",
        ];
    }

    public static function fefoQueue(int $limit = 50): array {
        $db = db();
        $queue = $db->prepare(
            "SELECT s.id, s.product_id, p.product_code, p.product_name,
                    s.batch_number, s.expiry_date, s.location,
                    s.quantity, s.pallet, s.uom,
                    CASE
                      WHEN s.expiry_date IS NULL THEN 'safe'
                      WHEN s.expiry_date < CURDATE() THEN 'expired'
                      WHEN s.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN 'critical'
                      WHEN s.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 90 DAY) THEN 'warning'
                      ELSE 'safe'
                    END as priority_level,
                    CASE
                      WHEN s.expiry_date IS NULL THEN 9999
                      ELSE DATEDIFF(s.expiry_date, CURDATE())
                    END as days_remaining
             FROM stock s
             JOIN products p ON s.product_id = p.id
             WHERE s.stock_status = 'Available'
             AND s.quantity > 0
             ORDER BY s.expiry_date IS NULL ASC, s.expiry_date ASC, p.product_name ASC
             LIMIT ?");
        $queue->execute([$limit]);
        $queueRows = $queue->fetchAll();

        $summary = $db->query(
            "SELECT YEAR(s.expiry_date) as year,
                    COUNT(*) as count,
                    COALESCE(SUM(s.quantity),0) as quantity
             FROM stock s
             WHERE s.stock_status = 'Available'
             AND s.quantity > 0
             GROUP BY YEAR(COALESCE(s.expiry_date, '9999-12-31'))
             ORDER BY YEAR(COALESCE(s.expiry_date, '9999-12-31')) ASC"
        )->fetchAll();

        $yearRows = [];
        foreach ($summary as $r) {
            $yearRows[] = ['year' => (int)$r['year'], 'count' => (int)$r['count'], 'quantity' => (float)$r['quantity']];
        }

        $mappedQueue = [];
        foreach ($queueRows as $r) {
            $mappedQueue[] = [
                'id'              => (int)$r['id'],
                'product_id'      => (int)$r['product_id'],
                'product_code'    => $r['product_code'],
                'product_name'    => $r['product_name'],
                'batch_number'    => $r['batch_number'],
                'expiry_date'     => $r['expiry_date'],
                'location'        => $r['location'],
                'quantity'        => (float)$r['quantity'],
                'pallet'          => (float)$r['pallet'],
                'uom'             => $r['uom'],
                'priority_level'  => $r['priority_level'],
                'days_remaining'  => (int)($r['days_remaining'] ?? 0),
            ];
        }

        return [
            'summary' => [
                'total' => array_sum(array_column($yearRows, 'count')),
                'years' => $yearRows,
            ],
            'queue' => $mappedQueue,
        ];
    }

    public static function dashboardAlerts(): array {
        $db = db();
        $expired = $db->query(
            "SELECT COUNT(*) as count, SUM(quantity) as qty
             FROM stock WHERE stock_status = 'Available' AND quantity > 0 AND expiry_date < CURDATE()"
        )->fetch();
        $critical = $db->query(
            "SELECT COUNT(*) as count, SUM(quantity) as qty
             FROM stock WHERE stock_status = 'Available' AND quantity > 0
             AND expiry_date >= CURDATE() AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)"
        )->fetch();
        $stocktakeLocks = $db->query(
            "SELECT COUNT(DISTINCT location) as locked_locations
             FROM stock_take_items sti
             JOIN stock_take st ON st.id = sti.stock_take_id
             WHERE st.status IN ('Counting', 'Review') AND sti.location IS NOT NULL"
        )->fetch();
        $stalePicks = $db->query(
            "SELECT COUNT(*) as count FROM outbound_orders
             WHERE status = 'Picking' AND updated_at < CURDATE() - INTERVAL 1 DAY"
        )->fetch();
        $overdueInbound = $db->query(
            "SELECT COUNT(*) as count FROM inbound_orders
             WHERE status = 'Dues In' AND order_date < CURDATE() - INTERVAL 7 DAY"
        )->fetch();
        $alerts = [];
        if ((int)$expired['count'] > 0) {
            $alerts[] = [
                'level' => 'critical', 'type' => 'expired',
                'message' => $expired['count'] . ' batch expired (' . round((float)($expired['qty'] ?? 0)) . ' units)',
                'count' => (int)$expired['count'], 'action_link' => '/stock?filter=expired',
            ];
        }
        if ((int)$critical['count'] > 0) {
            $alerts[] = [
                'level' => 'warning', 'type' => 'critical_expiry',
                'message' => $critical['count'] . ' batch expiring dalam 30 hari (' . round((float)($critical['qty'] ?? 0)) . ' units)',
                'count' => (int)$critical['count'], 'action_link' => '/stock?filter=expiring',
            ];
        }
        if ((int)($stocktakeLocks['locked_locations'] ?? 0) > 0) {
            $alerts[] = [
                'level' => 'info', 'type' => 'stocktake_lock',
                'message' => $stocktakeLocks['locked_locations'] . ' lokasi terkunci (stock take aktif)',
                'count' => (int)$stocktakeLocks['locked_locations'], 'action_link' => '/stocktake',
            ];
        }
        if ((int)$stalePicks['count'] > 0) {
            $alerts[] = [
                'level' => 'warning', 'type' => 'stale_picks',
                'message' => $stalePicks['count'] . ' outbound stuck di status Picking',
                'count' => (int)$stalePicks['count'], 'action_link' => '/outbound?status=Picking',
            ];
        }
        if ((int)$overdueInbound['count'] > 0) {
            $alerts[] = [
                'level' => 'info', 'type' => 'overdue_inbound',
                'message' => $overdueInbound['count'] . ' inbound overdue (> 7 hari)',
                'count' => (int)$overdueInbound['count'], 'action_link' => '/inbound?status=Dues In',
            ];
        }
        return ['alerts' => $alerts];
    }

    public static function dashboardInsights(): array {
        $db = db();

        // Get active rack prefixes dynamically from location_master
        $activeRacks = self::getActiveRackPrefixes($db);

        $fastMovers = $db->query(
            "SELECT p.id, p.product_code, p.product_name,
                    COUNT(DISTINCT sl.id) as transaction_count,
                    SUM(sl.quantity_out) as total_shipped,
                    SUM(sl.quantity_out) / 30.0 as avg_daily_qty
             FROM stock_ledger sl JOIN products p ON sl.product_id = p.id
             WHERE sl.transaction_type = 'OUT' AND sl.transaction_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
             GROUP BY p.id ORDER BY total_shipped DESC LIMIT 5"
        )->fetchAll();
        $slowMovers = $db->query(
            "SELECT p.id, p.product_code, p.product_name,
                    COUNT(DISTINCT s.id) as batch_count,
                    SUM(s.quantity) as total_qty,
                    MIN(sl.transaction_date) as oldest_receipt
             FROM stock s JOIN products p ON s.product_id = p.id
             LEFT JOIN stock_ledger sl ON sl.product_id = s.product_id
               AND sl.batch_number <=> s.batch_number AND sl.transaction_type = 'IN'
             WHERE s.stock_status = 'Available' AND s.quantity > 0
             AND (sl.transaction_date IS NULL OR sl.transaction_date <= CURDATE() - INTERVAL 90 DAY)
             GROUP BY p.id ORDER BY total_qty DESC LIMIT 5"
        )->fetchAll();
        $lowStock = $db->query(
            "SELECT p.id, p.product_code, p.product_name, p.uom_type,
                    SUM(s.quantity) as current_qty, 100 as reorder_point
             FROM stock s JOIN products p ON s.product_id = p.id
             WHERE s.stock_status = 'Available' AND s.quantity > 0
             GROUP BY p.id HAVING SUM(s.quantity) < 100
             ORDER BY current_qty ASC LIMIT 5"
        )->fetchAll();
$locationUtil = $db->query(
            "SELECT 
                CASE 
                    WHEN lm.location_code = 'STAGING' THEN 'STAGING'
                    WHEN lm.location_code = 'QUA_SHELL' THEN 'QUARANTINE'
                    WHEN lm.location_code = 'UNALLOCATED' THEN 'UNALLOCATED'
                    WHEN lm.location_code REGEXP '^[A-Z]-[0-9]' THEN SUBSTRING_INDEX(lm.location_code, '-', 1)
                    WHEN lm.location_code REGEXP '^[A-Z]{2}[0-9]' THEN LEFT(lm.location_code, 2)
                    ELSE lm.aisle
                END AS aisle,
                COUNT(DISTINCT lm.location_code) as total_locations,
                COUNT(DISTINCT CASE WHEN s1.quantity > 0 OR s2.quantity > 0 THEN lm.location_code END) as occupied,
                ROUND(100.0 * COUNT(DISTINCT CASE WHEN s1.quantity > 0 OR s2.quantity > 0 THEN lm.location_code END) / NULLIF(COUNT(DISTINCT lm.location_code), 0), 1) as utilization_percent
            FROM location_master lm
            LEFT JOIN stock_locations sl ON sl.location_code COLLATE utf8mb4_general_ci = lm.location_code COLLATE utf8mb4_general_ci
                AND sl.status IN ('Available','Reserved')
            LEFT JOIN stock s1 ON sl.stock_id = s1.id AND s1.quantity > 0
            LEFT JOIN stock s2 ON s2.location COLLATE utf8mb4_general_ci = lm.location_code COLLATE utf8mb4_general_ci
                AND s2.quantity > 0 AND s2.stock_status = 'Available' AND s1.id IS NULL
            WHERE lm.is_active = 1
              AND lm.location_code NOT IN ('STAGING','QUA_SHELL','UNALLOCATED')
              AND LEFT(lm.location_code, 2) IN ('" . implode("','", $activeRacks) . "')
            GROUP BY aisle ORDER BY utilization_percent DESC"
        )->fetchAll();
        return [
            'fast_movers' => array_map(fn($r) => [
                'id' => (int)$r['id'], 'product_code' => $r['product_code'], 'product_name' => $r['product_name'],
                'transaction_count' => (int)$r['transaction_count'], 'total_shipped' => (float)($r['total_shipped'] ?? 0),
                'avg_daily_qty' => round((float)($r['avg_daily_qty'] ?? 0), 2),
            ], $fastMovers),
            'slow_movers' => array_map(fn($r) => [
                'id' => (int)$r['id'], 'product_code' => $r['product_code'], 'product_name' => $r['product_name'],
                'batch_count' => (int)$r['batch_count'], 'total_qty' => (float)($r['total_qty'] ?? 0),
                'oldest_receipt' => $r['oldest_receipt'],
            ], $slowMovers),
            'low_stock' => array_map(fn($r) => [
                'id' => (int)$r['id'], 'product_code' => $r['product_code'], 'product_name' => $r['product_name'],
                'uom_type' => $r['uom_type'], 'current_qty' => (float)($r['current_qty'] ?? 0),
                'reorder_point' => (int)($r['reorder_point'] ?? 100),
            ], $lowStock),
            'location_utilization' => array_map(fn($r) => [
                'aisle' => $r['aisle'], 'total_locations' => (int)$r['total_locations'],
                'occupied' => (int)$r['occupied'], 'utilization_percent' => (float)($r['utilization_percent'] ?? 0),
            ], $locationUtil),
        ];
    }

    // =========================================================================
    // Report — flat list actions (v2 parity)
    // =========================================================================

    public static function reportProducts(): array {
        $db = db();
        $r = $db->query(
            "SELECT p.*,
                    COALESCE(SUM(s.quantity), 0) as total_drums,
                    COALESCE(SUM(s.quantity), 0) as total_qty,
                    COALESCE(SUM(CEILING(s.quantity / GREATEST(p.uom_per_pallet, 1))), 0) as total_pallets
             FROM products p
             LEFT JOIN stock s ON p.id = s.product_id
               AND (s.stock_status IN ('Available','Dues In') OR s.stock_status IS NULL OR s.stock_status = '')
               AND s.quantity > 0
               AND (s.location IS NULL OR s.location NOT IN ('QUA_SHELL','STAGING'))
             GROUP BY p.id ORDER BY p.product_name"
        )->fetchAll();
        return array_map(fn($p) => array_merge($p, ['id' => (int)$p['id']]), $r);
    }

    public static function reportStock(): array {
        $db = db();
        return $db->query(
            "SELECT s.*, p.product_code, p.product_name, p.category, p.uom_type, p.uom_per_pallet
             FROM stock s JOIN products p ON s.product_id = p.id
             WHERE s.quantity > 0 ORDER BY p.product_name, s.expiry_date ASC"
        )->fetchAll();
    }

    public static function reportLedger($start, $end): array {
        $db = db();
        $where = [];
        $params = [];
        if ($start) { $params[] = $start; $where[] = 'sl.transaction_date >= ?'; }
        if ($end)   { $params[] = $end;   $where[] = 'sl.transaction_date <= ?'; }
        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $sql = "SELECT sl.*, p.product_code, p.product_name
                FROM stock_ledger sl JOIN products p ON sl.product_id = p.id
                $whereSql ORDER BY sl.transaction_date DESC, sl.created_at DESC LIMIT 5000";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    // =========================================================================
    // Activity Log
    // =========================================================================

    public static function activityLogList($module, $action, int $limit): array {
        $db = db();
        $sql = 'SELECT al.* FROM activity_log al WHERE 1=1';
        $params = [];
        if ($module) { $sql .= ' AND al.module = ?'; $params[] = $module; }
        if ($action) { $sql .= ' AND al.action = ?'; $params[] = $action; }
        $sql .= ' ORDER BY al.id DESC LIMIT ' . max(0, (int)$limit);
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) {
            $row['id'] = (int)$row['id'];
            $row['action_label'] = ActivityLogger::actionLabel($row['action']);
            $row['module_icon']  = ActivityLogger::moduleIcon($row['module']);
        }
        unset($row);
        return $rows;
    }

    public static function activityModules(): array {
        return db()->query('SELECT DISTINCT module FROM activity_log ORDER BY module')->fetchAll(PDO::FETCH_COLUMN);
    }

    // =========================================================================
    // System
    // =========================================================================

    public static function resetOperationalData(): void {
        $db = db();
        $tables = [
            'activity_log', 'stock_ledger', 'outbound_item_locations',
            'wave_orders', 'waves', 'picklist_items', 'picklists',
            'location_allocations', 'stock_take_items', 'stock_take',
            'bin_transfers', 'outbound_destinations', 'outbound_items', 'outbound_orders',
            'inbound_items', 'inbound_orders', 'putaway_task_items', 'putaway_tasks',
            'stock_locations', 'stock',
        ];
        $db->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($tables as $t) {
            $db->exec("TRUNCATE TABLE `$t`");
        }
        $db->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private static function formatDayMonthYear(string $dateStr): string {
        $m = [];
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $dateStr, $m)) return $dateStr;
        $monthsShort = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
        return sprintf('%02d %s %d', (int)$m[3], $monthsShort[(int)$m[2] - 1] ?? $m[2], (int)$m[1]);
    }

    /**
     * Get active rack prefixes from location_master (excludes blocked aisles)
     * Uses putaway_location_blocks table to dynamically determine active racks
     * Returns array of 2-char rack codes (e.g., ['CA','CB','CC','CD','CE','CF'])
     * Only includes racks that have at least one active location AND are not blocked
     */
    private static function getActiveRackPrefixes(PDO $db): array {
        // Get blocked aisle prefixes
        $blockedStmt = $db->query(
            "SELECT aisle_prefix FROM putaway_location_blocks
             WHERE is_active = 1 AND scope_type = 'aisle'"
        );
        $blockedAisles = $blockedStmt->fetchAll(PDO::FETCH_COLUMN);
        $blockedClause = '';
        if (!empty($blockedAisles)) {
            // Build placeholders for parameterized query
            $placeholders = implode(',', array_fill(0, count($blockedAisles), '?'));
            $blockedClause = "AND lm.aisle NOT IN ($placeholders)";
        }

        $sql = "SELECT DISTINCT LEFT(location_code, 2) as rack_prefix
                FROM location_master lm
                WHERE lm.is_active = 1
                  AND lm.location_code NOT IN ('STAGING','QUA_SHELL','UNALLOCATED')
                  AND LENGTH(lm.location_code) >= 2
                  $blockedClause
                ORDER BY rack_prefix";

        if (!empty($blockedAisles)) {
            $stmt = $db->prepare($sql);
            $stmt->execute($blockedAisles);
        } else {
            $stmt = $db->query($sql);
        }
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
        return array_filter($rows, fn($r) => $r !== false && $r !== '');
    }

    public static function exportToCSV($data, $filename) {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $output = fopen('php://output', 'w');

        if (!empty($data)) {
            fputcsv($output, array_keys($data[0]));
            foreach ($data as $row) {
                fputcsv($output, $row);
            }
        }

        fclose($output);
        exit;
    }
}
?>

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

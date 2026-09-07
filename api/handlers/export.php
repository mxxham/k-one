<?php
/**
 * Export module (module=export) — Excel XLSX binary downloads.
 * Mirror of v2 export/actions.ts → ExcelExportService.
 */

function handle_export($action) {
    try {
        switch ($action) {
            case 'inbound':
                api_require_write();
                $status = query('status') ?: null;
                $orders = Inbound::getAll($status, 5000, 0, null);
                ExcelExport::exportInbound($orders);
                exit;

            case 'outbound':
                api_require_write();
                $status = query('status') ?: null;
                $orders = Outbound::getAll($status, 5000, 0, null);
                ExcelExport::exportOutbound($orders);
                exit;

            case 'customers':
                api_require_write();
                $rows = db()->query("SELECT * FROM customers ORDER BY customer_name")->fetchAll();
                ExcelExport::exportCustomers($rows);
                exit;

            case 'products':
                api_require_write();
                $rows = db()->query("SELECT * FROM products ORDER BY product_code")->fetchAll();
                ExcelExport::exportProducts($rows);
                exit;

            case 'ledger':
                api_require_write();
                $start = query('start_date') ?: null;
                $end = query('end_date') ?: null;
                $rows = Stock::getMovement(null, $start, $end, 10000);
                ExcelExport::exportLedger($rows);
                exit;

            case 'stock':
                api_require_write();
                $rows = Stock::getAll();
                ExcelExport::exportStock($rows);
                exit;

            case 'stocktake':
                api_require_write();
                $id = (int)(query('id') ?: 0);
                if (!$id) json_err('id wajib diisi.', 400);
                $stockTake = StockTake::getById($id);
                if (!$stockTake) json_err('Stock take tidak ditemukan.', 404);
                $items = StockTake::getItems($id);
                $accuracy = StockTake::getAccuracy($id);
                ExcelExport::exportStockTake($stockTake, $items, $accuracy);
                exit;

            case 'asn':
                api_require_write();
                $status = query('status') ?: null;
                $sql = "SELECT a.*, u.full_name AS created_by_name,
                               COUNT(ai.id) AS item_count,
                               COALESCE(SUM(ai.expected_qty),0) AS expected_total
                        FROM asn a
                        LEFT JOIN users u ON a.created_by = u.id
                        LEFT JOIN asn_items ai ON ai.asn_id = a.id";
                $args = [];
                if ($status) { $sql .= " WHERE a.status = ?"; $args[] = $status; }
                $sql .= " GROUP BY a.id ORDER BY a.created_at DESC";
                $stmt = db()->prepare($sql);
                $stmt->execute($args);
                $rows = $stmt->fetchAll();
                foreach ($rows as &$r) {
                    $r['item_count'] = (int)$r['item_count'];
                    $r['expected_total'] = floatval($r['expected_total']);
                }
                unset($r);
                // Reuse CSV export for ASN (no dedicated ExcelExport method)
                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename="asn_export_' . date('Ymd_His') . '.csv"');
                $out = fopen('php://output', 'w');
                if (!empty($rows)) {
                    fputcsv($out, array_keys($rows[0]));
                    foreach ($rows as $row) fputcsv($out, $row);
                }
                fclose($out);
                exit;

            case 'report':
                api_require_write();
                $type = query('type') ?: 'daily';
                $date = query('date') ?: null;
                $dateTo = query('date_to') ?: null;
                $report = Report::getDailyReport($date, $dateTo);
                ExcelExport::exportDailyReport($report);
                exit;

            default:
                json_err('Invalid action: ' . $action, 404);
        }
    } catch (Throwable $e) {
        json_err($e->getMessage(), 400);
    }
}

<?php
declare(strict_types=1);

/**
 * GiExportService — export Good Issue data to Excel.
 * Uses PhpSpreadsheet (already in composer.json as phpspreadsheet ^5.5).
 */
class GiExportService
{
    /**
     * Export dispatch records to an Excel file and return the file path.
     */
    public static function export(array $filters = []): array
    {
        $db = db();
        $sql = "SELECT g.*, p.product_name, c.customer_name
                FROM gi_exports g
                LEFT JOIN stock_locations sl ON sl.lpn_code = g.lpn_code
                LEFT JOIN stock s ON s.id = sl.stock_id
                LEFT JOIN products p ON p.id = s.product_id
                LEFT JOIN outbound_orders o ON o.do_number = g.do_number
                LEFT JOIN customers c ON c.id = o.customer_id
                WHERE 1=1";

        $params = [];
        if (!empty($filters['status'])) {
            $sql .= " AND g.status = ?";
            $params[] = $filters['status'];
        }
        if (!empty($filters['from_date'])) {
            $sql .= " AND g.dispatched_at >= ?";
            $params[] = $filters['from_date'];
        }
        if (!empty($filters['to_date'])) {
            $sql .= " AND g.dispatched_at <= ?";
            $params[] = $filters['to_date'] . ' 23:59:59';
        }
        $sql .= " ORDER BY g.dispatched_at DESC";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $exportDir = __DIR__ . '/../exports';
        if (!is_dir($exportDir)) mkdir($exportDir, 0755, true);

        $fileName = 'gi_export_' . date('Y-m-d_His') . '.xlsx';
        $filePath = $exportDir . '/' . $fileName;

        // Use PhpSpreadsheet
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('GI Export');

        // Headers
        $headers = ['GI Number', 'LPN Code', 'DO Number', 'Truck No', 'Driver', 'Status', 'Dispatched At', 'Product', 'Customer'];
        $colLetters = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I'];
        foreach ($headers as $idx => $header) {
            $sheet->setCellValue($colLetters[$idx] . '1', $header);
            $sheet->getStyle($colLetters[$idx] . '1')->getFont()->setBold(true);
        }

        // Data rows
        foreach ($rows as $rowIdx => $row) {
            $r = $rowIdx + 2;
            $sheet->setCellValue('A' . $r, $row['gi_number'] ?? '');
            $sheet->setCellValue('B' . $r, $row['lpn_code'] ?? '');
            $sheet->setCellValue('C' . $r, $row['do_number'] ?? '');
            $sheet->setCellValue('D' . $r, $row['truck_no'] ?? '');
            $sheet->setCellValue('E' . $r, $row['driver_name'] ?? '');
            $sheet->setCellValue('F' . $r, $row['status'] ?? '');
            $sheet->setCellValue('G' . $r, $row['dispatched_at'] ?? '');
            $sheet->setCellValue('H' . $r, $row['product_name'] ?? '');
            $sheet->setCellValue('I' . $r, $row['customer_name'] ?? '');
        }

        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save($filePath);

        return [
            'file_path' => $filePath,
            'file_name' => $fileName,
            'row_count' => count($rows),
        ];
    }
}

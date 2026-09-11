<?php
/**
 * Picklist Generator
 * Generates picklist Excel from allocation results
 */

require_once __DIR__ . '/../../vendor/autoload.php';

class PicklistGenerator
{
    private $spreadsheet;

    public function __construct()
    {
        $this->spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    }

    /**
     * Generate picklist Excel file
     */
    public function generate(array $allocationResult): string
    {
        // Remove default sheet
        $this->spreadsheet->removeSheetByIndex(0);

        // Create sheets
        $this->createSummarySheet($allocationResult['summary']);
        $this->createPicksSheet($allocationResult['picks']);
        $this->createReplenishmentsSheet($allocationResult['replenishments']);
        $this->createErrorsSheet($allocationResult['errors']);

        // Set active sheet to summary
        $this->spreadsheet->setActiveSheetIndex(0);

        // Save to temp file
        $tempFile = tempnam(sys_get_temp_dir(), 'picklist_');
        $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($this->spreadsheet, 'Xlsx');
        $writer->save($tempFile);

        return $tempFile;
    }

    /**
     * Create summary sheet
     */
    private function createSummarySheet(array $summary): void
    {
        $sheet = $this->spreadsheet->createSheet();
        $sheet->setTitle('Summary');

        // Headers
        $sheet->setCellValue('A1', 'Picklist Summary');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);

        $sheet->setCellValue('A3', 'Metric');
        $sheet->setCellValue('B3', 'Value');
        $sheet->getStyle('A3:B3')->getFont()->setBold(true);

        // Data
        $row = 4;
        $data = [
            'Total Orders' => $summary['total_orders'],
            'Total Items' => $summary['total_items'],
            'Full Pallet Picks' => $summary['full_pallet_picks'],
            'Pickface Picks' => $summary['pickface_picks'],
            'Replenishments' => $summary['replenishments'],
        ];

        foreach ($data as $metric => $value) {
            $sheet->setCellValue("A{$row}", $metric);
            $sheet->setCellValue("B{$row}", $value);
            $row++;
        }

        // Auto-width
        $sheet->getColumnDimension('A')->setAutoSize(true);
        $sheet->getColumnDimension('B')->setAutoSize(true);
    }

    /**
     * Create picks sheet
     */
    private function createPicksSheet(array $picks): void
    {
        $sheet = $this->spreadsheet->createSheet();
        $sheet->setTitle('Picks');

        // Headers
        $headers = ['Order No', 'Item Code', 'Location', 'Quantity', 'Type', 'Batch', 'Expiry Date'];
        foreach ($headers as $col => $header) {
            $colLetter = chr(65 + $col);
            $sheet->setCellValue("{$colLetter}1", $header);
            $sheet->getStyle("{$colLetter}1")->getFont()->setBold(true);
        }

        // Data
        $row = 2;
        foreach ($picks as $pick) {
            $sheet->setCellValue("A{$row}", $pick['order_no']);
            $sheet->setCellValue("B{$row}", $pick['item_code']);
            $sheet->setCellValue("C{$row}", $pick['location']);
            $sheet->setCellValue("D{$row}", $pick['quantity']);
            $sheet->setCellValue("E{$row}", $pick['type']);
            $sheet->setCellValue("F{$row}", $pick['batch_number'] ?? '');
            $sheet->setCellValue("G{$row}", $pick['expiry_date'] ?? '');
            $row++;
        }

        // Auto-width
        foreach (range('A', 'G') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
    }

    /**
     * Create replenishments sheet
     */
    private function createReplenishmentsSheet(array $replenishments): void
    {
        $sheet = $this->spreadsheet->createSheet();
        $sheet->setTitle('Replenishments');

        // Headers
        $headers = ['Item Code', 'From Location', 'To Location', 'Quantity', 'UOM Type'];
        foreach ($headers as $col => $header) {
            $colLetter = chr(65 + $col);
            $sheet->setCellValue("{$colLetter}1", $header);
            $sheet->getStyle("{$colLetter}1")->getFont()->setBold(true);
        }

        // Data
        $row = 2;
        foreach ($replenishments as $replenishment) {
            $sheet->setCellValue("A{$row}", $replenishment['item_code']);
            $sheet->setCellValue("B{$row}", $replenishment['from_location']);
            $sheet->setCellValue("C{$row}", $replenishment['to_location']);
            $sheet->setCellValue("D{$row}", $replenishment['quantity']);
            $sheet->setCellValue("E{$row}", $replenishment['uom_type']);
            $row++;
        }

        // Auto-width
        foreach (range('A', 'E') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
    }

    /**
     * Create errors sheet
     */
    private function createErrorsSheet(array $errors): void
    {
        $sheet = $this->spreadsheet->createSheet();
        $sheet->setTitle('Errors');

        // Headers
        $sheet->setCellValue('A1', 'Error');
        $sheet->getStyle('A1')->getFont()->setBold(true);

        // Data
        $row = 2;
        foreach ($errors as $error) {
            $sheet->setCellValue("A{$row}", $error);
            $row++;
        }

        // Auto-width
        $sheet->getColumnDimension('A')->setAutoSize(true);
    }

    /**
     * Download the generated file
     */
    public function download(string $tempFile, string $filename = 'picklist.xlsx'): void
    {
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="' . $filename . '"');
        header('Cache-Control: max-age=0');
        
        readfile($tempFile);
        unlink($tempFile);
        exit;
    }
}

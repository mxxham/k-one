<?php
/** Excel template generation for the import module. */

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

function import_col_letter(int $n): string {
    return Coordinate::stringFromColumnIndex($n);
}

function import_tpl_header(Spreadsheet $sp, array $headers, string $headerColor): void {
    $sheet = $sp->getActiveSheet();
    $col = 1;
    foreach ($headers as $label => $cfg) {
        $coord = import_col_letter($col) . '1';
        $sheet->getCell($coord)->setValue(($cfg['required'] ?? false) ? "* {$label}" : $label);
        $sheet->getStyle($coord)->getFont()->setBold(true)->setSize(10)->getColor()->setARGB('FFFFFFFF');
        $sheet->getStyle($coord)->getFill()->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setARGB('FF' . $headerColor);
        $sheet->getStyle($coord)->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER)
            ->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getStyle($coord)->getBorders()->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN)
            ->getColor()->setARGB('FF9E9E9E');
        $sheet->getColumnDimension(import_col_letter($col))->setWidth($cfg['width'] ?? 18);
        $col++;
    }
    $sheet->getRowDimension(1)->setRowHeight(24);
    $sheet->freezePane('A2');
}

function import_tpl_sample_row(Spreadsheet $sp, array $values): void {
    $sheet = $sp->getActiveSheet();
    $rowNum = $sheet->getHighestRow() + 1;
    foreach ($values as $ci => $val) {
        $sheet->getCell(import_col_letter($ci + 1) . $rowNum)->setValue($val);
    }
    $sheet->getStyle(import_col_letter(1) . $rowNum . ':' . import_col_letter(count($values)) . $rowNum)
        ->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)
        ->getColor()->setARGB('FFE0E0E0');
}

function import_tpl_note(Spreadsheet $sp, string $label, string $value = ''): void {
    $sheet = $sp->getActiveSheet();
    $rowNum = $sheet->getHighestRow() + 1;
    $sheet->getCell('A' . $rowNum)->setValue($label);
    $sheet->getStyle('A' . $rowNum)->getFont()->setItalic(true)->getColor()->setARGB('FF6B7280');
    if ($value !== '') {
        $sheet->getCell('B' . $rowNum)->setValue($value);
        $sheet->getStyle('B' . $rowNum)->getFont()->setItalic(true)->getColor()->setARGB('FF1565C0');
    }
}

function import_tpl_inbound(): void {
    $sp = new Spreadsheet();
    $sheet = $sp->getActiveSheet();
    $sheet->setTitle('Inbound Data');
    $sheet->setCellValue('D1', '');

    $headers = [
        'Shipment No'      => ['width' => 20, 'required' => false],
        'OD No'            => ['width' => 18, 'required' => false],
        'SO No'            => ['width' => 18, 'required' => false],
        'Item Code'        => ['width' => 22, 'required' => true],
        'Uom'              => ['width' => 10, 'required' => true],
        'ACTUAL QTY'       => ['width' => 12, 'required' => true],
        'QTY ORDER'        => ['width' => 12, 'required' => false],
        'Pallet'           => ['width' => 10, 'required' => false],
        'Batch No'         => ['width' => 18, 'required' => false],
        'Manufacture date' => ['width' => 16, 'required' => true],
        'Exp Date'         => ['width' => 14, 'required' => true],
        'Location'         => ['width' => 14, 'required' => false],
        'Remarks'          => ['width' => 20, 'required' => false],
    ];
    import_tpl_header($sp, $headers, '1565C0');

    $samples = [
        ['SHP-2026-001', '530870001', '4549106001', 'ADVANCE-AX7', 'Carton', 12, 12, '', 'LOT/2026/001', '2024-06-01', '2028-06-01', 'CA05B01', ''],
        ['SHP-2026-001', '530870002', '4549106002', 'HELIX-HX7',   'Drum',   4,  4, 1, 'LOT/2026/002', '2024-07-01', '2028-07-01', 'CB03A01', ''],
        ['SHP-2026-002', '530870003', '4549106003', 'GADUS-S2',    'Pail',   1,  1, '', 'LOT/2026/003', '2025-01-01', '2029-01-01', 'CC08E01', 'Fragile'],
    ];
    foreach ($samples as $r) import_tpl_sample_row($sp, $r);

    import_tpl_note($sp, '* = Kolom wajib diisi', 'Drum | Carton | Pail | EA | Bags');
    import_tpl_note($sp, 'Uom values:', 'Drum | Carton | Pail | EA | Bags');
    import_tpl_note($sp, 'Date format:', 'YYYY-MM-DD atau DD/MM/YYYY');
    import_tpl_note($sp, 'Item Code:', 'Harus sesuai Product Code di database K-one');
    import_tpl_note($sp, 'Shipment No:', 'WAJIB untuk grouping — baris dengan Shipment No sama = satu inbound order');

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="Inbound_Import_Template_K-one.xlsx"');
    header('Cache-Control: max-age=0');
    (new Xlsx($sp))->save('php://output');
    exit;
}

function import_tpl_outbound(): void {
    $sp = new Spreadsheet();
    $sheet = $sp->getActiveSheet();
    $sheet->setTitle('Outbound Data');

    $headers = [
        'Plan Date'                     => ['width' => 14, 'required' => false],
        'Shipment Number'               => ['width' => 18, 'required' => true],
        'Order No (OD)'                 => ['width' => 16, 'required' => false],
        'Purchasing Document'           => ['width' => 18, 'required' => false],
        'Ship-to Party Code'            => ['width' => 14, 'required' => false],
        'Material'                      => ['width' => 16, 'required' => true],
        'Description'                   => ['width' => 32, 'required' => false],
        'Delivery quantity'             => ['width' => 12, 'required' => true],
        'Sales Unit'                    => ['width' => 10, 'required' => true],
        'Name of Ship-To Party'         => ['width' => 28, 'required' => false],
        'Location of Ship-To Party'     => ['width' => 22, 'required' => false],
        'Street / Address'              => ['width' => 30, 'required' => false],
        'Goods Issue Date'              => ['width' => 16, 'required' => false],
        'SO Number'                     => ['width' => 22, 'required' => false],
        'TRANSPORT'                     => ['width' => 12, 'required' => false],
    ];
    import_tpl_header($sp, $headers, '4A148C');

    $samples = [
        ['2026-03-31', '109294012', '531746742', '13004218', 'PO/001', 'ADVANCE-AX7', 'Shell Advance 4T AX7', 100, 'CAR', 'CV MULTI SARANA BAN', 'SURABAYA', 'Jl. Raya Darmo No. 1', '2026-03-31', '', 'LF'],
        ['2026-03-31', '109294012', '531746741', '12529551', 'PO/002', 'HELIX-HX7',   'Shell Helix HX7',       100, 'CAR', 'CV MULTI SARANA BAN', 'SURABAYA', 'Jl. Raya Darmo No. 1', '2026-03-31', '', 'LF'],
        ['2026-03-31', '109294013', '531746801', '10000001', 'PO/003', 'ADVANCE-AX7', 'Shell Advance 4T AX7',   50, 'CAR', 'PT SUMBER BARU BAN',  'MALANG',   'Jl. Soekarno Hatta 88', '2026-03-31', '', 'LF'],
    ];
    foreach ($samples as $r) import_tpl_sample_row($sp, $r);

    import_tpl_note($sp, 'PENTING — Struktur Order:', '1 Shipment Number = 1 Order Outbound di sistem WMS');
    import_tpl_note($sp, 'Name/Location/Street per item:', 'Boleh berbeda tiap baris dalam 1 shipment (multi-tujuan)');
    import_tpl_note($sp, 'UOM values:', 'Drum (DRM) | Carton (CAR) | Pail (PAL/PAIL) | Bags | EA');
    import_tpl_note($sp, 'Material:', 'Gunakan Product Code dari database K-one');

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="Outbound_Import_Template_K-one.xlsx"');
    header('Cache-Control: max-age=0');
    (new Xlsx($sp))->save('php://output');
    exit;
}

function import_tpl_stock(): void {
    $sp = new Spreadsheet();
    $sheet = $sp->getActiveSheet();
    $sheet->setTitle('Stock Import');

    $headers = [
        'A1' => 'product_code*', 'B1' => 'batch_number', 'C1' => 'location',
        'D1' => 'quantity*',     'E1' => 'uom',          'F1' => 'manufacture_date',
        'G1' => 'expiry_date',   'H1' => 'stock_status', 'I1' => 'notes',
    ];
    foreach ($headers as $cell => $val) $sheet->setCellValue($cell, $val);
    $sheet->getStyle('A1:I1')->applyFromArray([
        'font'      => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF'], 'size' => 11],
        'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF1B5E20']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFFFFFFF']]],
    ]);

    $examples = ['A3' => 'SHE-001', 'B3' => 'BT2024001', 'C3' => 'A-01-01', 'D3' => 20,
                 'E3' => 'Drum', 'F3' => '01/01/2024', 'G3' => '01/01/2026', 'H3' => 'Available', 'I3' => 'Opening stock'];
    foreach ($examples as $cell => $val) $sheet->setCellValue($cell, $val);

    $descriptions = ['A2' => 'Kode produk (wajib)', 'B2' => 'No batch / lot', 'C2' => 'Lokasi bin (mis: A-01-01)',
                     'D2' => 'Jumlah qty (wajib)', 'E2' => 'Drum/Carton/Pail/Bags/EA (default: Drum)',
                     'F2' => 'Tgl produksi', 'G2' => 'Tgl exp', 'H2' => 'Available/Dues In/Reserved', 'I2' => 'Catatan opsional'];
    foreach ($descriptions as $cell => $val) $sheet->setCellValue($cell, $val);

    foreach (range('A', 'I') as $col) $sheet->getColumnDimension($col)->setAutoSize(true);
    $sheet->freezePane('A4');

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="template_import_stock.xlsx"');
    header('Cache-Control: max-age=0');
    (new Xlsx($sp))->save('php://output');
    exit;
}
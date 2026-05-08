<?php
session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/classes/Auth.php';
require_once __DIR__ . '/vendor/autoload.php';
Auth::requireRole(['admin', 'operator']);

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

function colLO(int $n): string { return Coordinate::stringFromColumnIndex($n); }

function fillCell(object $sheet, string $coord, string $hexColor): void {
    $sheet->getStyle($coord)->getFill()
          ->setFillType(Fill::FILL_SOLID)
          ->getStartColor()->setARGB('FF' . strtoupper($hexColor));
}

function fillRange(object $sheet, string $range, string $hexColor): void {
    $sheet->getStyle($range)->getFill()
          ->setFillType(Fill::FILL_SOLID)
          ->getStartColor()->setARGB('FF' . strtoupper($hexColor));
}

$spreadsheet = new Spreadsheet();
$spreadsheet->getProperties()->setCreator('K-one')->setTitle('Outbound Import Template');
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Outbound Data');

$headers = [
    'Plan Date'                     => ['width' => 14, 'required' => false, 'note' => 'Tanggal rencana pengiriman'],
    'Shipment Number'               => ['width' => 18, 'required' => true,  'note' => '1 Shipment = 1 Order di sistem. WAJIB.'],
    'Order No (OD)'                 => ['width' => 16, 'required' => false, 'note' => 'OD Number per item dari SAP'],
    'Purchasing Document'           => ['width' => 18, 'required' => false, 'note' => 'Purchase Order Number'],
    'Ship-to Party Code'            => ['width' => 14, 'required' => false, 'note' => 'Kode customer/ship-to party'],
    'Material'                      => ['width' => 16, 'required' => true,  'note' => 'Product code di database. WAJIB.'],
    'Description'                   => ['width' => 32, 'required' => false, 'note' => 'Nama produk (opsional)'],
    'Delivery quantity'             => ['width' => 12, 'required' => true,  'note' => 'Jumlah qty. WAJIB.'],
    'Sales Unit'                    => ['width' => 10, 'required' => true,  'note' => 'CAR / DRM / PAL / PAIL. WAJIB.'],
    'Name of Ship-To Party'         => ['width' => 28, 'required' => false, 'note' => 'Nama tujuan per item — boleh berbeda tiap baris dalam 1 shipment'],
    'Location of Ship-To Party'     => ['width' => 22, 'required' => false, 'note' => 'Kota/lokasi tujuan per item'],
    'Street / Address'              => ['width' => 30, 'required' => false, 'note' => 'Alamat lengkap tujuan per item (opsional)'],
    'Goods Issue Date'              => ['width' => 16, 'required' => false, 'note' => 'Tanggal ship / GI date'],
    'SO Number'                     => ['width' => 22, 'required' => false, 'note' => 'Sales Order Number dari SAP (opsional)'],
    'TRANSPORT'                     => ['width' => 12, 'required' => false, 'note' => 'Kode transport (opsional)'],
];

$col = 1;
foreach ($headers as $label => $cfg) {
    $coord = colLO($col) . '1';
    $sheet->getCell($coord)->setValue($cfg['required'] ? "* {$label}" : $label);
    $sheet->getStyle($coord)->getFont()->setBold(true)->setSize(10)->getColor()->setARGB('FFFFFFFF');
    fillCell($sheet, $coord, $cfg['required'] ? '4A148C' : '7B1FA2');
    $sheet->getStyle($coord)->getAlignment()
          ->setHorizontal(Alignment::HORIZONTAL_CENTER)
          ->setVertical(Alignment::VERTICAL_CENTER);
    $sheet->getStyle($coord)->getBorders()->getAllBorders()
          ->setBorderStyle(Border::BORDER_THIN)
          ->getColor()->setARGB('FF6A1B9A');
    $sheet->getColumnDimension(colLO($col))->setWidth($cfg['width']);
    $col++;
}
$sheet->getRowDimension(1)->setRowHeight(26);
$sheet->freezePane('A2');

$samples = [
    
    ['2026-03-31','531746742','923971869','13004218',  '109294012', 'CV MULTI SARANA BAN',  'SURABAYA', 'ADVANCE-AX7', 'Shell Advance 4T AX7',  100,'CAR','960', '939.79','',            '2026-03-23','LF'],
    ['2026-03-31','531746741','923225649','12529551',  '109294012', 'CV MULTI SARANA BAN',  'SURABAYA', 'ADVANCE-AX7', 'Shell Advance 4T AX7',  100,'CAR','960', '939.79','',            '2026-03-09','LF'],
    ['2026-03-31','531746735','924448403','10054235',  '109294012', 'SHELL YONOSOEWOYO-1',  'SURABAYA', 'ADVANCE-AX7', 'Shell Advance 4T AX7',    1,'CAR','9.6',  '9.40', 'PO/YONO/26', '2026-04-04','LF'],
    ['2026-03-31','531746735','924448403','10054235',  '109294012', 'SHELL YONOSOEWOYO-1',  'SURABAYA', 'HELIX-HX7',   'Shell Helix HX7',         1,'CAR','12',   '11.48','PO/YONO/26', '2026-04-04','LF'],
    ['2026-03-31','531746801','925001001','10000001',  '109294013', 'PT SUMBER BARU BAN',   'MALANG',   'ADVANCE-AX7', 'Shell Advance 4T AX7',   50,'CAR','480', '469.9', 'PO/MAL/001', '2026-03-31','LF'],
];

$samples = [
    ['2026-03-31', '109294012', '531746742', '923971869', '13004218', 'ADVANCE-AX7',  'Shell Advance 4T AX7',    100, 'CAR', 'CV MULTI SARANA BAN',   'SURABAYA', 'Jl. Raya Darmo No. 1',  '2026-03-31', '',            'LF'],
    ['2026-03-31', '109294012', '531746741', '923225649', '12529551', 'HELIX-HX7',    'Shell Helix HX7',         100, 'CAR', 'CV MULTI SARANA BAN',   'SURABAYA', 'Jl. Raya Darmo No. 1',  '2026-03-31', '',            'LF'],
    ['2026-03-31', '109294012', '531746735', '924448403', '10054235', 'ADVANCE-AX7',  'Shell Advance 4T AX7',      1, 'CAR', 'SHELL YONOSOEWOYO-1',   'SURABAYA', 'Jl. Pemuda No. 7',      '2026-04-04', 'PO/YONO/26',  'LF'],
    ['2026-03-31', '109294012', '531746735', '924448403', '10054235', 'GADUS-S2',     'Shell Gadus S2',            1, 'CAR', 'SHELL YONOSOEWOYO-1',   'SURABAYA', 'Jl. Pemuda No. 7',      '2026-04-04', 'PO/YONO/26',  'LF'],
    ['2026-03-31', '109294013', '531746801', '925001001', '10000001', 'ADVANCE-AX7',  'Shell Advance 4T AX7',     50, 'CAR', 'PT SUMBER BARU BAN',    'MALANG',   'Jl. Soekarno Hatta 88', '2026-03-31', 'PO/MAL/001',  'LF'],
];

foreach ($samples as $ri => $row) {
    $rn = $ri + 2;
    foreach ($row as $ci => $val) {
        $sheet->getCell(colLO($ci + 1) . $rn)->setValue($val);
    }
    $range = colLO(1) . $rn . ':' . colLO(count($headers)) . $rn;
    fillRange($sheet, $range, $ri % 2 === 0 ? 'FAFAFA' : 'F3E5F5');
    $sheet->getStyle($range)->getBorders()->getAllBorders()
          ->setBorderStyle(Border::BORDER_THIN)
          ->getColor()->setARGB('FFE0E0E0');
    
    foreach ([2, 6, 8, 9] as $reqCol) {
        fillCell($sheet, colLO($reqCol) . $rn, 'FFF8E1');
    }
    $sheet->getRowDimension($rn)->setRowHeight(18);
}

$noteRow = count($samples) + 3;
$noteData = [
    ['* = Kolom wajib diisi',            ''],
    ['PENTING — Struktur Order:',         '1 Shipment Number = 1 Order Outbound di sistem WMS'],
    ['Name/Location/Street per item:',    'Boleh berbeda tiap baris dalam 1 shipment (multi-tujuan per shipment)'],
    ['UOM values:',                       'Drum (DRM) | Carton (CAR) | Pail (PAL/PAIL) | Bags'],
    ['Date format:',                      'YYYY-MM-DD  or  DD/MM/YYYY'],
    ['Material:',                         'Gunakan Product Code dari database K-one'],
    ['Order No (OD):',                    'Nomor Outbound Delivery dari SAP — per item, bisa berbeda tiap baris'],
    ['Baris dengan Shipment No sama:',    '→ digabung menjadi 1 outbound order. Tujuan bisa berbeda per item.'],
];
foreach ($noteData as $ni => $note) {
    $c1 = colLO(1) . ($noteRow + $ni);
    $sheet->getCell($c1)->setValue($note[0]);
    $sheet->getStyle($c1)->getFont()->setItalic(true)->getColor()->setARGB('FF6B7280');
    if ($note[1]) {
        $c2 = colLO(2) . ($noteRow + $ni);
        $sheet->getCell($c2)->setValue($note[1]);
        $sheet->getStyle($c2)->getFont()->setItalic(true)->getColor()->setARGB('FF4A148C');
    }
}

$spreadsheet->setActiveSheetIndex(0);
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="Outbound_Import_Template_' . date('Ymd') . '.xlsx"');
header('Cache-Control: max-age=0');
(new Xlsx($spreadsheet))->save('php://output');
exit;

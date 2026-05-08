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

function colL(int $n): string { return Coordinate::stringFromColumnIndex($n); }

$spreadsheet = new Spreadsheet();
$spreadsheet->getProperties()
    ->setCreator('K-one')
    ->setTitle('Inbound Import Template')
    ->setCompany('K-one');

$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Inbound Data');

$headers = [
    'Shipment No'      => ['width' => 20, 'required' => false, 'note' => 'Nomor shipment — baris dengan Shipment No sama = satu inbound order'],
    'OD No'            => ['width' => 18, 'required' => false, 'note' => 'Outbound Delivery No — per item, bisa berbeda tiap baris'],
    'SO No'            => ['width' => 18, 'required' => false, 'note' => 'Sales Order No — per item'],
    'Item Code'        => ['width' => 22, 'required' => true,  'note' => 'Harus sesuai product_code di database'],
    'Uom'              => ['width' => 10, 'required' => true,  'note' => 'Drum / Carton / Pail / Bags / EA'],
    'ACTUAL QTY'       => ['width' => 12, 'required' => true,  'note' => 'Jumlah aktual diterima'],
    'QTY ORDER'        => ['width' => 12, 'required' => false, 'note' => 'Jumlah order (opsional)'],
    'Pallet'           => ['width' => 10, 'required' => false, 'note' => 'Jumlah pallet (auto jika kosong)'],
    'Batch No'         => ['width' => 18, 'required' => false, 'note' => 'Nomor batch / lot'],
    'Manufacture date' => ['width' => 16, 'required' => true,  'note' => 'WAJIB — Tanggal produksi (YYYY-MM-DD)'],
    'Exp Date'         => ['width' => 14, 'required' => true,  'note' => 'WAJIB — Tanggal kedaluwarsa (YYYY-MM-DD)'],
    'Location'         => ['width' => 14, 'required' => false, 'note' => 'Kode lokasi awal (opsional)'],
    'Remarks'          => ['width' => 20, 'required' => false, 'note' => 'Catatan'],
];

$headerStyle = [
    'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 10],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1565C0']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => false],
    'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '0D47A1']]],
];
$reqHeaderStyle = array_replace_recursive($headerStyle, [
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'B71C1C']],
]);
$dataStyle = [
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'E5E7EB']]],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FAFAFA']],
];
$reqDataStyle = array_replace_recursive($dataStyle, [
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FFF8E1']],
]);

$col = 1;
foreach ($headers as $label => $cfg) {
    $cell = $sheet->getCell(colL($col) . '1');
    $cell->setValue($cfg['required'] ? "* {$label}" : $label);
    $sheet->getStyle(colL($col) . '1')->applyFromArray($cfg['required'] ? $reqHeaderStyle : $headerStyle);
    $sheet->getColumnDimension(colL($col))->setWidth($cfg['width']);
    $col++;
}
$sheet->getRowDimension(1)->setRowHeight(22);
$sheet->freezePane('A2');

$samples = [
    ['SHP-2026-001', '530870001', '4549106001', 'ADVANCE-AX7', 'Carton', 12,  12, '',  'LOT/2026/001', '2024-06-01', '2028-06-01', 'CA05B01', ''],
    ['SHP-2026-001', '530870002', '4549106002', 'HELIX-HX7',   'Drum',   4,   4,  1,   'LOT/2026/002', '2024-07-01', '2028-07-01', 'CB03A01', ''],
    ['SHP-2026-002', '530870003', '4549106003', 'GADUS-S2',    'Pail',   1,   1,  '',  'LOT/2026/003', '2025-01-01', '2029-01-01', 'CC08E01', 'Fragile'],
    ['SHP-2026-002', '530870004', '4549106004', 'RIMULA-R4',   'Drum',   6,   6,  '',  'LOT/2026/004', '2024-09-01', '2028-09-01', 'CB05A01', ''],
];

$reqCols = [4, 5, 6, 10, 11]; 
foreach ($samples as $ri => $row) {
    $rowNum = $ri + 2;
    foreach ($row as $ci => $val) {
        $sheet->getCell(colL($ci + 1) . $rowNum)->setValue($val);
    }
    $isReq = in_array($ri + 1, []);
    $sheet->getStyle(colL(1) . $rowNum . ':' . colL(count($headers)) . $rowNum)
          ->applyFromArray($dataStyle);
    foreach ($reqCols as $rc) {
        $sheet->getStyle(colL($rc) . $rowNum)->applyFromArray($reqDataStyle);
    }
    $sheet->getRowDimension($rowNum)->setRowHeight(18);
}

$noteRow = count($samples) + 3;
$notes = [
    ['* = Kolom wajib diisi',             ''],
    ['Uom values:',                        'Drum | Carton | Pail | EA | Bags'],
    ['Date format:',                       'YYYY-MM-DD  or  DD/MM/YYYY'],
    ['Item Code:',                         'Harus sesuai Product Code di database K-one'],
    ['OD No / SO No:',                     'Per item — bisa berbeda tiap baris sesuai Planning Excel'],
    ['Manufacture date:',                  'WAJIB. Tanggal produksi harus diisi'],
    ['Exp Date:',                          'WAJIB. Tanggal kedaluwarsa harus diisi — tidak boleh kosong'],
    ['Shipment No:',                       'WAJIB untuk grouping — baris dengan Shipment No sama = satu inbound order'],
    ['Pallet:',                            'Kosongkan untuk auto-hitung'],
];
foreach ($notes as $ni => $note) {
    $sheet->getCell(colL(1) . ($noteRow + $ni))->setValue($note[0]);
    $sheet->getStyle(colL(1) . ($noteRow + $ni))->getFont()->setItalic(true)->getColor()->setRGB('6B7280');
    if ($note[1]) {
        $sheet->getCell(colL(2) . ($noteRow + $ni))->setValue($note[1]);
        $sheet->getStyle(colL(2) . ($noteRow + $ni))->getFont()->setItalic(true)->getColor()->setRGB('1565C0');
    }
}

$info = $spreadsheet->createSheet();
$info->setTitle('Instructions');

$iHeaders = ['Column', 'Required?', 'Description', 'Example / Valid Values'];
$iWidths  = [22, 10, 38, 52];
foreach ($iHeaders as $i => $h) {
    $info->getCell(colL($i + 1) . '1')->setValue($h);
    $info->getStyle(colL($i + 1) . '1')->applyFromArray([
        'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '374151']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
    ]);
    $info->getColumnDimension(colL($i + 1))->setWidth($iWidths[$i]);
}

$iRows = [
    ['Month',            'No',  'Bulan order (untuk grouping)',             'MM/YYYY  e.g.  03/2026'],
    ['PO NO',            'No',  'Purchase Order Number / GR Number',       'e.g. GR-2026-001'],
    ['Shipment No',      'No',  'Nomor shipment — rows sama = 1 inbound',  'e.g. SHP-2026-001'],
    ['OD No',            'No',  'Outbound Delivery No — PER ITEM',         'e.g. 530870992'],
    ['SO No',            'No',  'Sales Order No — PER ITEM',               'e.g. 4549106526'],
    ['Item Code',        'YES', 'Product code dari K-one',             'e.g.  ADVANCE-AX7'],
    ['QTY ORDER',        'No',  'Quantity yang dipesan (opsional)',         'Angka  e.g.  4'],
    ['Uom',              'YES', 'Unit of Measure',                         'Drum | Carton | Pail | EA | Bags'],
    ['ACTUAL QTY',       'YES', 'Quantity aktual yang diterima',           'Angka  e.g.  4'],
    ['Pallet',           'No',  'Jumlah pallet (auto jika kosong)',        'Angka  e.g.  1'],
    ['Batch No',         'No',  'Batch / lot number',                     'e.g.  LOT/2026/001'],
    ['Manufacture date', 'YES', 'Tanggal produksi — WAJIB',                          'YYYY-MM-DD  e.g.  2024-06-01'],
    ['Exp Date',         'YES', 'Tanggal kedaluwarsa — WAJIB, harus diisi di Excel', 'YYYY-MM-DD  e.g.  2028-06-01'],
    ['Location',         'No',  'Lokasi bin/rack',                        'e.g.  CA05B01'],
    ['Remarks',          'No',  'Catatan opsional',                       'Free text'],
];
foreach ($iRows as $ri => $row) {
    $rn = $ri + 2;
    foreach ($row as $ci => $val) {
        $info->getCell(colL($ci + 1) . $rn)->setValue($val);
    }
    $bg = $row[1] === 'YES' ? 'FEF9C3' : 'FFFFFF';
    $info->getStyle(colL(1) . $rn . ':' . colL(4) . $rn)->applyFromArray([
        'fill'    => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $bg]],
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'E5E7EB']]],
    ]);
    $info->getRowDimension($rn)->setRowHeight(18);
}

$spreadsheet->setActiveSheetIndex(0);
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="Inbound_Import_Template_K-one.xlsx"');
header('Cache-Control: max-age=0');
(new Xlsx($spreadsheet))->save('php://output');
exit;

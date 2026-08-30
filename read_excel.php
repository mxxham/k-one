<?php
require 'vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\IOFactory;

$sheet = IOFactory::load('c:/Users/asust/Downloads/kode sku SHELL_updated 19 agustus 2026 (1).xlsx');
$ws = $sheet->getActiveSheet();
$rows = $ws->toArray(null, true, true, true);

echo "Total rows: " . count($rows) . PHP_EOL;
echo "Sheets: " . implode(', ', array_keys($sheet->getAllSheets())) . PHP_EOL;

// Print header row
echo PHP_EOL . "=== HEADER ===" . PHP_EOL;
foreach ($rows[1] as $col => $val) {
    echo "  $col: $val" . PHP_EOL;
}

// Print first 10 data rows
echo PHP_EOL . "=== FIRST 10 ROWS ===" . PHP_EOL;
for ($i = 2; $i <= min(11, count($rows)); $i++) {
    echo PHP_EOL . "Row $i:" . PHP_EOL;
    foreach ($rows[$i] as $col => $val) {
        if ($val !== null && $val !== '') {
            echo "  $col: $val" . PHP_EOL;
        }
    }
}

<?php
require_once 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

$file = 'c:\Users\asust\Downloads\Warehouse Management System_28 Agustus 2026_.xlsx';

$spreadsheet = IOFactory::load($file);

echo "=== Sheet Names ===\n";
foreach ($spreadsheet->getSheetNames() as $name) {
    echo "- $name\n";
}

// Look for WMS sheet
$wmsSheet = null;
foreach ($spreadsheet->getSheetNames() as $name) {
    if (stripos($name, 'WMS') !== false || stripos($name, 'quantity') !== false || stripos($name, 'qty') !== false) {
        echo "\n>>> Found sheet: $name\n";
        $wmsSheet = $spreadsheet->getSheetByName($name);
        break;
    }
}

if (!$wmsSheet) {
    echo "\nNo WMS sheet found. Checking all sheets...\n";
    foreach ($spreadsheet->getSheetNames() as $name) {
        $sheet = $spreadsheet->getSheetByName($name);
        $highestRow = $sheet->getHighestRow();
        $highestCol = $sheet->getHighestColumn();
        echo "\n=== Sheet: $name (Rows: $highestRow, Cols: $highestCol) ===\n";
        
        for ($row = 1; $row <= min($highestRow, 20); $row++) {
            $rowData = [];
            for ($col = 'A'; $col <= $highestCol; $col++) {
                $cell = $sheet->getCell($col . $row);
                $value = $cell->getValue();
                if ($value !== null && $value !== '') {
                    $rowData[$col] = $value;
                }
            }
            if (!empty($rowData)) {
                echo "Row $row: " . json_encode($rowData, JSON_UNESCAPED_UNICODE) . "\n";
            }
        }
    }
} else {
    $highestRow = $wmsSheet->getHighestRow();
    $highestCol = $wmsSheet->getHighestColumn();
    echo "\n=== WMS Sheet (Rows: $highestRow, Cols: $highestCol) ===\n";
    
    // Show first 30 rows
    for ($row = 1; $row <= min($highestRow, 30); $row++) {
        $rowData = [];
        for ($col = 'A'; $col <= $highestCol; $col++) {
            $cell = $wmsSheet->getCell($col . $row);
            $value = $cell->getValue();
            if ($value !== null && $value !== '') {
                $rowData[$col] = $value;
            }
        }
        if (!empty($rowData)) {
            echo "Row $row: " . json_encode($rowData, JSON_UNESCAPED_UNICODE) . "\n";
        }
    }
    
    // Specifically check N4 and V4
    echo "\n=== SPECIFIC CELLS ===\n";
    echo "N4: " . var_export($wmsSheet->getCell('N4')->getValue(), true) . "\n";
    echo "V4: " . var_export($wmsSheet->getCell('V4')->getValue(), true) . "\n";
    
    // Check row 4 in full
    echo "\n=== Row 4 (full) ===\n";
    for ($col = 'A'; $col <= 'Z'; $col++) {
        $val = $wmsSheet->getCell($col . '4')->getValue();
        if ($val !== null && $val !== '') {
            echo "$col 4: $val\n";
        }
    }
    
    // Check column N and V headers
    echo "\n=== Column N (rows 1-10) ===\n";
    for ($row = 1; $row <= 10; $row++) {
        $val = $wmsSheet->getCell('N' . $row)->getValue();
        echo "N$row: " . var_export($val, true) . "\n";
    }
    
    echo "\n=== Column V (rows 1-10) ===\n";
    for ($row = 1; $row <= 10; $row++) {
        $val = $wmsSheet->getCell('V' . $row)->getValue();
        echo "V$row: " . var_export($val, true) . "\n";
    }
}
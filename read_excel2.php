<?php
require 'vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\IOFactory;

$sheet = IOFactory::load('c:/Users/asust/Downloads/kode sku SHELL_updated 19 agustus 2026 (1).xlsx');
$ws = $sheet->getActiveSheet();
$rows = $ws->toArray(null, true, true, true);

// Collect all unique levels
$levels = [];
$products = [];
for ($i = 2; $i <= count($rows); $i++) {
    $sku = trim((string)($rows[$i]['A'] ?? ''));
    $name = trim((string)($rows[$i]['B'] ?? ''));
    $qty = intval($rows[$i]['C'] ?? 0);
    $uom = trim((string)($rows[$i]['D'] ?? ''));
    $level = trim((string)($rows[$i]['E'] ?? ''));
    
    if ($sku === '') continue;
    
    $levels[$level] = ($levels[$level] ?? 0) + 1;
    $products[] = ['sku' => $sku, 'name' => $name, 'qty' => $qty, 'uom' => $uom, 'level' => $level];
}

echo "Total products in Excel: " . count($products) . PHP_EOL;
echo PHP_EOL . "Unique LEVEL values:" . PHP_EOL;
foreach ($levels as $lvl => $cnt) {
    echo "  '$lvl': $cnt products" . PHP_EOL;
}

// Show products with non-ALL LEVEL
echo PHP_EOL . "Products with specific level restrictions:" . PHP_EOL;
foreach ($products as $p) {
    if ($p['level'] !== 'ALL LEVEL') {
        echo "  {$p['sku']} - {$p['name']} - Level: {$p['level']}" . PHP_EOL;
    }
}

// Show first 20 for reference
echo PHP_EOL . "First 20 products:" . PHP_EOL;
for ($i = 0; $i < min(20, count($products)); $i++) {
    $p = $products[$i];
    echo "  {$p['sku']} | {$p['name']} | {$p['qty']} {$p['uom']} | {$p['level']}" . PHP_EOL;
}

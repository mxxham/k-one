<?php

session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/classes/Auth.php';
require_once __DIR__ . '/classes/ActivityLogger.php';
$phpSpreadsheetError = null;
try {
    require_once __DIR__ . '/vendor/autoload.php';
} catch (\Exception $autoloadErr) {
    $phpSpreadsheetError = $autoloadErr->getMessage();
}

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;

Auth::requireAuth();
Auth::requireRole('admin'); 

$pageTitle   = 'Import Stock';
$currentPage = 'import_stock';

$error        = null;
$success      = null;
$previewRows  = null;
$previewStats = null;
$importResult = null;

function parseStockDate($val): ?string {
    if (empty($val) || $val === '0') return null;
    if (is_numeric($val) && $val > 40000) {
        $dt = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float)$val);
        return $dt->format('Y-m-d');
    }
    $val = trim((string)$val);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)) return $val;
    if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $val, $m)) return "{$m[3]}-{$m[2]}-{$m[1]}";
    if (preg_match('/^(\d{1,2})-(\d{1,2})-(\d{4})$/', $val, $m)) return "{$m[3]}-{$m[2]}-{$m[1]}";
    try { return (new DateTime($val))->format('Y-m-d'); } catch (Exception $e) { return null; }
}

function uomPerPallet(string $uom, int $productUpp = 4): int {
    switch (strtolower(trim($uom))) {
        case 'drum':   return 4;
        case 'carton': return $productUpp ?: 44;
        case 'pail':   return 24;
        case 'bags':   return 1;
        case 'ea':     return 4;
        default:       return $productUpp ?: 4;
    }
}

if (isset($_GET['download_template'])) {
    if ($phpSpreadsheetError) { die('PhpSpreadsheet tidak tersedia: ' . $phpSpreadsheetError); }
    $sp = new Spreadsheet();
    $ws = $sp->getActiveSheet();
    $ws->setTitle('Stock Import');

    $headers = [
        'A1' => 'product_code*',
        'B1' => 'batch_number',
        'C1' => 'location',
        'D1' => 'quantity*',
        'E1' => 'uom',
        'F1' => 'manufacture_date',
        'G1' => 'expiry_date',
        'H1' => 'stock_status',
        'I1' => 'notes',
    ];
    $descriptions = [
        'A2' => 'Kode produk (wajib)',
        'B2' => 'No batch / lot',
        'C2' => 'Lokasi bin (mis: A-01-01)',
        'D2' => 'Jumlah qty (wajib)',
        'E2' => 'Drum/Carton/Pail/Bags/EA (default: Drum)',
        'F2' => 'Tgl produksi (dd/mm/yyyy)',
        'G2' => 'Tgl exp (dd/mm/yyyy)',
        'H2' => 'Available/Dues In (default: Available)',
        'I2' => 'Catatan opsional',
    ];
    $examples = [
        'A3' => 'SHE-001', 'B3' => 'BT2024001', 'C3' => 'A-01-01',
        'D3' => 20, 'E3' => 'Drum', 'F3' => '01/01/2024',
        'G3' => '01/01/2026', 'H3' => 'Available', 'I3' => 'Opening stock',
    ];

    
    $ws->getStyle('A1:I1')->applyFromArray([
        'font'      => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF'], 'size' => 11],
        'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF1B5E20']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFFFFFFF']]],
    ]);
    
    $ws->getStyle('A2:I2')->applyFromArray([
        'font' => ['italic' => true, 'color' => ['argb' => 'FF546E7A'], 'size' => 9],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFE8F5E9']],
    ]);
    
    $ws->getStyle('A3:I3')->applyFromArray([
        'font' => ['color' => ['argb' => 'FF1B5E20']],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFF1F8E9']],
    ]);

    foreach ($headers     as $cell => $val) $ws->setCellValue($cell, $val);
    foreach ($descriptions as $cell => $val) $ws->setCellValue($cell, $val);
    foreach ($examples    as $cell => $val) $ws->setCellValue($cell, $val);

    foreach (range('A','I') as $col) $ws->getColumnDimension($col)->setAutoSize(true);
    $ws->freezePane('A4');

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="template_import_stock.xlsx"');
    header('Cache-Control: max-age=0');
    IOFactory::createWriter($sp, 'Xlsx')->save('php://output');
    exit;
}

function parseStockExcel(string $filePath, string $ext): array {
    if ($ext === 'csv') {
        $handle = fopen($filePath, 'r');
        if (!$handle) throw new Exception("Tidak bisa membuka file CSV");
        $allRows = [];
        while (($row = fgetcsv($handle)) !== false) $allRows[] = $row;
        fclose($handle);
    } else {
        $sp      = IOFactory::load($filePath);
        $allRows = $sp->getActiveSheet()->toArray(null, true, true, false);
    }
    if (empty($allRows)) throw new Exception("File kosong");

    
    $headerIdx = 0;
    $colMap = [
        'product_code'     => ['product_code','product code','kode produk','item code','material','material no','sku'],
        'batch_number'     => ['batch_number','batch number','batch no','batch','lot','no batch'],
        'location'         => ['location','lokasi','bin','warehouse location'],
        'quantity'         => ['quantity','qty','jumlah','actual qty'],
        'uom'              => ['uom','unit','satuan','unit of measure'],
        'manufacture_date' => ['manufacture_date','manufacture date','mfg date','tgl produksi','production date'],
        'expiry_date'      => ['expiry_date','expiry date','exp date','tgl exp','expiration date','best before'],
        'stock_status'     => ['stock_status','stock status','status'],
        'notes'            => ['notes','catatan','keterangan','remarks'],
    ];

    $headers = [];
    foreach ($allRows as $idx => $row) {
        $rowStr = strtolower(implode(' ', array_map(fn($v) => (string)($v ?? ''), $row)));
        if (str_contains($rowStr, 'product') || str_contains($rowStr, 'qty') || str_contains($rowStr, 'batch')) {
            $headerIdx = $idx;
            $headers   = array_map(fn($h) => strtolower(trim((string)($h ?? ''))), $row);
            break;
        }
    }
    if (empty($headers)) {
        $headers = array_map(fn($h) => strtolower(trim((string)($h ?? ''))), $allRows[0]);
    }

    
    $resolved = [];
    foreach ($colMap as $key => $aliases) {
        foreach ($headers as $ci => $h) {
            foreach ($aliases as $alias) {
                if ($h === $alias || str_contains($h, $alias)) {
                    $resolved[$key] = $ci;
                    break 2;
                }
            }
        }
    }

    if (!isset($resolved['product_code'])) throw new Exception("Kolom 'product_code' tidak ditemukan. Pastikan header sesuai template.");
    if (!isset($resolved['quantity']))     throw new Exception("Kolom 'quantity' tidak ditemukan.");

    
    $rows = [];
    for ($i = $headerIdx + 1; $i < count($allRows); $i++) {
        $row = $allRows[$i];
        
        $productCode = trim((string)($row[$resolved['product_code']] ?? ''));
        if (empty($productCode) || strtolower($productCode) === 'kode produk (wajib)') continue;

        $qty = floatval($row[$resolved['quantity']] ?? 0);
        if ($qty <= 0) continue; 

        $rows[] = [
            'product_code'     => $productCode,
            'batch_number'     => trim((string)($row[$resolved['batch_number'] ?? -1] ?? '')),
            'location'         => trim((string)($row[$resolved['location'] ?? -1]     ?? '')),
            'quantity'         => $qty,
            'uom'              => trim((string)($row[$resolved['uom'] ?? -1]          ?? 'Drum')) ?: 'Drum',
            'manufacture_date' => parseStockDate($row[$resolved['manufacture_date'] ?? -1] ?? null),
            'expiry_date'      => parseStockDate($row[$resolved['expiry_date']      ?? -1] ?? null),
            'stock_status'     => trim((string)($row[$resolved['stock_status'] ?? -1] ?? 'Available')) ?: 'Available',
            'notes'            => trim((string)($row[$resolved['notes']         ?? -1] ?? '')),
            '_row_num'         => $i + 1,
        ];
    }
    if (empty($rows)) throw new Exception("Tidak ada data valid yang ditemukan di file.");
    return $rows;
}

function validateStockRows(array $rows): array {
    $db = db();
    $productCache = [];

    foreach ($rows as &$row) {
        $row['_errors'] = [];
        $row['_warnings'] = [];

        
        $code = $row['product_code'];
        if (!isset($productCache[$code])) {
            $st = $db->prepare("SELECT id, product_name, uom_type, uom_per_pallet FROM products WHERE product_code = ? AND is_active = 1 LIMIT 1");
            $st->execute([$code]);
            $productCache[$code] = $st->fetch() ?: null;
        }
        $product = $productCache[$code];

        if (!$product) {
            $row['_errors'][] = "Produk '$code' tidak ditemukan";
            $row['product_id']   = null;
            $row['product_name'] = '—';
        } else {
            $row['product_id']   = $product['id'];
            $row['product_name'] = $product['product_name'];
            
            if (empty($row['uom'])) $row['uom'] = $product['uom_type'] ?? 'Drum';
            $row['uom_per_pallet'] = uomPerPallet($row['uom'], $product['uom_per_pallet'] ?? 4);
            $row['pallet']         = ceil($row['quantity'] / $row['uom_per_pallet']);
        }

        
        $validStatuses = ['Available', 'Reserved', 'Dues In', 'Expired'];
        if (!in_array($row['stock_status'], $validStatuses)) {
            $row['_warnings'][] = "Status '{$row['stock_status']}' tidak dikenal, akan diset ke 'Available'";
            $row['stock_status'] = 'Available';
        }

        
        if ($row['expiry_date'] && $row['expiry_date'] < date('Y-m-d')) {
            $row['_warnings'][] = "Produk sudah expired ({$row['expiry_date']})";
        }
    }
    unset($row);
    return $rows;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['stock_file'])) {
    try {
        $file = $_FILES['stock_file'];
        if ($file['error'] !== UPLOAD_ERR_OK) throw new Exception("Upload gagal (kode error: {$file['error']})");

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['xlsx','xls','csv'])) throw new Exception("Format file tidak didukung. Gunakan .xlsx, .xls, atau .csv");

        $tmpPath = sys_get_temp_dir() . '/import_stock_' . uniqid() . '.' . $ext;
        move_uploaded_file($file['tmp_name'], $tmpPath);

        $rawRows     = parseStockExcel($tmpPath, $ext);
        $previewRows = validateStockRows($rawRows);
        @unlink($tmpPath);

        $errorCount   = count(array_filter($previewRows, fn($r) => !empty($r['_errors'])));
        $warningCount = count(array_filter($previewRows, fn($r) => !empty($r['_warnings'])));
        $validCount   = count($previewRows) - $errorCount;

        $previewStats = [
            'total'    => count($previewRows),
            'valid'    => $validCount,
            'errors'   => $errorCount,
            'warnings' => $warningCount,
        ];

        
        $_SESSION['stock_import_preview'] = $previewRows;
        $_SESSION['stock_import_file']    = $file['name'];

    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

function calcPreviewStats(array $rows): array {
    $errorCount   = count(array_filter($rows, fn($r) => !empty($r['_errors'])));
    $warningCount = count(array_filter($rows, fn($r) => !empty($r['_warnings'])));
    return [
        'total'    => count($rows),
        'valid'    => count($rows) - $errorCount,
        'errors'   => $errorCount,
        'warnings' => $warningCount,
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_import'])) {
    $previewRows  = $_SESSION['stock_import_preview'] ?? [];
    $previewStats = !empty($previewRows) ? calcPreviewStats($previewRows) : null;
    if (empty($previewRows)) {
        $error = "Session expired. Silakan upload ulang file.";
    } else {
        $db = db();
        try {
            $db->beginTransaction();
            $imported = 0;
            $skipped  = 0;
            $refPrefix = 'IST-' . date('Ymd') . '-';

            foreach ($previewRows as $row) {
                if (!empty($row['_errors']) || !$row['product_id']) { $skipped++; continue; }

                $mode = $_POST['duplicate_mode'] ?? 'add'; 

                if ($mode === 'replace') {
                    
                    $chk = $db->prepare(
                        "SELECT id FROM stock WHERE product_id=? AND batch_number<=>? AND location<=>? AND stock_status=? LIMIT 1"
                    );
                    $chk->execute([$row['product_id'], $row['batch_number'] ?: null, $row['location'] ?: null, $row['stock_status']]);
                    $existing = $chk->fetch();

                    if ($existing) {
                        $db->prepare("UPDATE stock SET quantity=?, pallet=?, manufacture_date=?, expiry_date=?, uom=?, updated_at=NOW() WHERE id=?")
                           ->execute([$row['quantity'], $row['pallet'], $row['manufacture_date'], $row['expiry_date'], $row['uom'], $existing['id']]);
                        $imported++;
                        continue;
                    }
                }

                
                if ($mode === 'add') {
                    $chk = $db->prepare(
                        "SELECT id FROM stock WHERE product_id=? AND batch_number<=>? AND location<=>? AND stock_status=? LIMIT 1"
                    );
                    $chk->execute([$row['product_id'], $row['batch_number'] ?: null, $row['location'] ?: null, $row['stock_status']]);
                    $existing = $chk->fetch();

                    if ($existing) {
                        $newPallet = ceil(($existing['quantity'] ?? 0) + $row['quantity']) / ($row['uom_per_pallet'] ?? 4);
                        $db->prepare("UPDATE stock SET quantity=quantity+?, pallet=?, updated_at=NOW() WHERE id=?")
                           ->execute([$row['quantity'], ceil($newPallet), $existing['id']]);
                        $imported++;
                        continue;
                    }
                }

                $db->prepare("INSERT INTO stock
                    (product_id, batch_number, location, quantity, uom, pallet,
                     manufacture_date, expiry_date, stock_status, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())")
                   ->execute([
                       $row['product_id'],
                       $row['batch_number']     ?: null,
                       $row['location']         ?: null,
                       $row['quantity'],
                       $row['uom'],
                       $row['pallet'],
                       $row['manufacture_date'] ?: null,
                       $row['expiry_date']      ?: null,
                       $row['stock_status'],
                   ]);

                
                $refNo  = $refPrefix . str_pad($imported + 1, 4, '0', STR_PAD_LEFT);
                $balSt  = $db->prepare("SELECT COALESCE(SUM(quantity),0) as bal FROM stock WHERE product_id=? AND stock_status='Available'");
                $balSt->execute([$row['product_id']]);
                $balance = $balSt->fetch()['bal'];

                $db->prepare("INSERT INTO stock_ledger
                    (transaction_date, product_id, batch_number, transaction_type,
                     quantity_in, quantity_out, uom, pallet,
                     reference_number, reference_type, balance, location, notes)
                    VALUES (CURDATE(), ?, ?, 'IN', ?, 0, ?, ?, ?, 'Stock Import', ?, ?, ?)")
                   ->execute([
                       $row['product_id'],
                       $row['batch_number'] ?: null,
                       $row['quantity'],
                       $row['uom'],
                       $row['pallet'],
                       $refNo,
                       $balance,
                       $row['location'] ?: null,
                       $row['notes'] ?: 'Direct stock import',
                   ]);

                $imported++;
            }

            $db->commit();

            ActivityLogger::log(
                'IMPORT_STOCK', 'stock', null, null,
                null,
                "Import stock langsung: $imported baris berhasil, $skipped baris dilewati. File: " . ($_SESSION['stock_import_file'] ?? 'unknown')
            );

            unset($_SESSION['stock_import_preview'], $_SESSION['stock_import_file']);
            $importResult = ['imported' => $imported, 'skipped' => $skipped];
            $success = "Import berhasil! $imported item ditambahkan ke stok, $skipped item dilewati.";
            $previewRows = null;

        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            $error = "Import gagal: " . $e->getMessage();
        }
    }
}

if (isset($_POST['cancel_preview'])) {
    unset($_SESSION['stock_import_preview'], $_SESSION['stock_import_file']);
    header('Location: import_stock.php'); exit;
}

if (!empty($_SESSION['stock_import_preview'])) {
    if (empty($previewRows)) {
        $previewRows = $_SESSION['stock_import_preview'];
    }
    if (empty($previewStats)) {
        $previewStats = calcPreviewStats($previewRows);
    }
}

require_once __DIR__ . '/includes/header.php';
?>
<?php if ($phpSpreadsheetError): ?>
<div style="margin:24px;background:#fff3cd;border:1px solid #ffc107;border-radius:10px;padding:20px;color:#856404">
  <strong><i class="fas fa-exclamation-triangle"></i> Library Error</strong><br>
  Tidak bisa memuat library PhpSpreadsheet: <code><?= htmlspecialchars($phpSpreadsheetError) ?></code><br><br>
  Pastikan PHP versi &ge;8.2 sudah aktif di Laragon, lalu reload halaman ini.
</div>
<?php require_once __DIR__ . '/includes/footer.php'; exit; endif; ?>
<style>
:root{--is-green:#013d3c;--is-green-light:#e8f5e9;--is-yellow:#f9a825;--is-red:#014f4e;--is-blue:#013d3c}
.is-page{max-width:1200px;margin:0 auto;padding:20px}
.is-hero{background:linear-gradient(135deg,#013d3c 0%,#026766 50%,#388e3c 100%);border-radius:12px;padding:24px 28px;color:#fff;display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:20px;box-shadow:0 4px 20px rgba(27,94,32,.25)}
.is-hero-title{font-size:1.3rem;font-weight:700;display:flex;align-items:center;gap:10px}
.is-hero-sub{font-size:.82rem;opacity:.8;margin-top:3px}
.is-card{background:#fff;border-radius:12px;border:1px solid #e0e0e0;box-shadow:0 2px 8px rgba(0,0,0,.05);overflow:hidden;margin-bottom:18px}
.is-card-head{padding:14px 20px;border-bottom:1px solid #e0e0e0;background:#fafafa;display:flex;align-items:center;justify-content:space-between}
.is-card-head h2{font-size:.95rem;font-weight:700;color:#37474f;display:flex;align-items:center;gap:8px}
.is-card-body{padding:20px}
.is-btn{display:inline-flex;align-items:center;gap:7px;padding:9px 18px;border-radius:8px;font-size:.85rem;font-weight:600;cursor:pointer;border:none;text-decoration:none;transition:.15s}
.is-btn-green{background:var(--is-green);color:#fff}.is-btn-green:hover{background:#145214}
.is-btn-blue{background:var(--is-blue);color:#fff}.is-btn-blue:hover{background:#0a3580}
.is-btn-gray{background:#78909c;color:#fff}.is-btn-gray:hover{background:#607d8b}
.is-btn-red{background:var(--is-red);color:#fff}.is-btn-red:hover{background:#8e1111}
.is-btn-outline{background:#fff;border:1.5px solid #90a4ae;color:#546e7a}.is-btn-outline:hover{background:#f5f5f5}
.is-drop-zone{border:2.5px dashed #a5d6a7;border-radius:10px;padding:40px;text-align:center;background:#f1f8e9;cursor:pointer;transition:.2s}
.is-drop-zone:hover,.is-drop-zone.dragover{border-color:var(--is-green);background:#e8f5e9}
.is-drop-zone i{font-size:2.5rem;color:#81c784;margin-bottom:10px;display:block}
.is-stat-row{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:16px}
.is-stat{flex:1;min-width:110px;background:#f5f5f5;border-radius:8px;padding:12px 16px;text-align:center;border:1px solid #e0e0e0}
.is-stat.ok{background:#e8f5e9;border-color:#a5d6a7;color:#013d3c}
.is-stat.warn{background:#fff8e1;border-color:#ffe082;color:#e65100}
.is-stat.err{background:#ffebee;border-color:#80d2d2;color:#014f4e}
.is-stat .n{font-size:1.6rem;font-weight:700}
.is-stat .l{font-size:.72rem;font-weight:600;margin-top:2px}
.is-tbl{width:100%;border-collapse:collapse;font-size:.81rem}
.is-tbl thead tr{background:#eceff1}
.is-tbl th{padding:8px 10px;text-align:left;font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#607d8b;border-bottom:2px solid #cfd8dc;white-space:nowrap}
.is-tbl td{padding:7px 10px;border-bottom:1px solid #f5f5f5;vertical-align:middle}
.is-tbl tbody tr:hover{background:#fafafa}
.is-tbl tr.has-error{background:#fff8f8}
.is-tbl tr.has-warn{background:#fffde7}
.is-badge{display:inline-block;padding:2px 8px;border-radius:12px;font-size:.68rem;font-weight:700}
.is-badge-ok{background:#e8f5e9;color:#013d3c}
.is-badge-warn{background:#fff8e1;color:#e65100}
.is-badge-err{background:#ffebee;color:#014f4e}
.is-alert{padding:12px 16px;border-radius:8px;font-size:.86rem;margin-bottom:16px;display:flex;align-items:flex-start;gap:10px}
.is-alert-ok{background:#e8f5e9;border-left:4px solid #43a047;color:#013d3c}
.is-alert-err{background:#ffebee;border-left:4px solid #026766;color:#014f4e}
.is-mode-select{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px}
.is-mode-opt{flex:1;min-width:200px;border:2px solid #e0e0e0;border-radius:8px;padding:12px 14px;cursor:pointer;transition:.15s}
.is-mode-opt:hover{border-color:#a5d6a7;background:#f1f8e9}
.is-mode-opt input[type=radio]{accent-color:var(--is-green)}
.is-mode-opt.selected{border-color:var(--is-green);background:#e8f5e9}
.is-step{display:flex;gap:0;margin-bottom:20px}
.is-step-item{flex:1;display:flex;align-items:center;gap:8px;padding:10px 14px;background:#eceff1;font-size:.8rem;font-weight:600;color:#90a4ae;position:relative}
.is-step-item:not(:last-child)::after{content:'›';position:absolute;right:-8px;font-size:1.2rem;color:#90a4ae;z-index:1}
.is-step-item.active{background:var(--is-green);color:#fff}
.is-step-item.active::after{color:#388e3c}
.is-step-item.done{background:#a5d6a7;color:#013d3c}
.is-step-item:first-child{border-radius:8px 0 0 8px}
.is-step-item:last-child{border-radius:0 8px 8px 0}
.is-tag{display:inline-block;padding:1px 6px;border-radius:4px;font-size:.7rem;background:#e0f7f7;color:#013d3c;font-weight:600;font-family:monospace}
</style>

<div class="is-page">

<div class="is-hero">
  <div>
    <div class="is-hero-title"><i class="fas fa-boxes"></i> Import Stock Langsung</div>
    <div class="is-hero-sub">Upload Excel / CSV untuk menambah stok gudang tanpa melalui proses Inbound</div>
  </div>
  <a href="?download_template=1" class="is-btn" style="background:rgba(255,255,255,.2);color:#fff;border:1px solid rgba(255,255,255,.4)">
    <i class="fas fa-download"></i> Download Template
  </a>
</div>

<?php
$step = $previewRows ? 2 : ($success ? 3 : 1);
?>
<div class="is-step">
  <div class="is-step-item <?= $step >= 1 ? ($step > 1 ? 'done' : 'active') : '' ?>">
    <i class="fas fa-upload"></i> 1. Upload File
  </div>
  <div class="is-step-item <?= $step >= 2 ? ($step > 2 ? 'done' : 'active') : '' ?>">
    <i class="fas fa-search"></i> 2. Preview & Validasi
  </div>
  <div class="is-step-item <?= $step >= 3 ? 'active' : '' ?>">
    <i class="fas fa-check-circle"></i> 3. Selesai
  </div>
</div>

<?php if ($error): ?>
<div class="is-alert is-alert-err"><i class="fas fa-exclamation-circle" style="font-size:1.1rem;flex-shrink:0"></i><?= htmlspecialchars($error) ?></div>
<?php endif; ?>
<?php if ($success): ?>
<div class="is-alert is-alert-ok"><i class="fas fa-check-circle" style="font-size:1.1rem;flex-shrink:0"></i>
  <div><?= htmlspecialchars($success) ?>
  <div style="margin-top:6px"><a href="stock.php" class="is-btn is-btn-green" style="padding:5px 14px;font-size:.8rem"><i class="fas fa-boxes"></i> Lihat Stock</a>
  <a href="import_stock.php" class="is-btn is-btn-outline" style="padding:5px 14px;font-size:.8rem;margin-left:8px"><i class="fas fa-redo"></i> Import Lagi</a></div>
  </div>
</div>
<?php endif; ?>

<?php if ($previewRows && !$success): ?>
<div class="is-card">
  <div class="is-card-head">
    <h2><i class="fas fa-table"></i> Preview Data (<?= $_SESSION['stock_import_file'] ?? 'file' ?>)</h2>
  </div>
  <div class="is-card-body">
    
    <div class="is-stat-row">
      <div class="is-stat"><div class="n"><?= $previewStats['total'] ?></div><div class="l">Total Baris</div></div>
      <div class="is-stat ok"><div class="n"><?= $previewStats['valid'] ?></div><div class="l">Siap Import</div></div>
      <?php if ($previewStats['warnings']): ?>
      <div class="is-stat warn"><div class="n"><?= $previewStats['warnings'] ?></div><div class="l">Ada Warning</div></div>
      <?php endif; ?>
      <?php if ($previewStats['errors']): ?>
      <div class="is-stat err"><div class="n"><?= $previewStats['errors'] ?></div><div class="l">Ada Error</div></div>
      <?php endif; ?>
    </div>

    <?php if ($previewStats['errors'] > 0): ?>
    <div class="is-alert is-alert-err" style="margin-bottom:14px">
      <i class="fas fa-exclamation-triangle"></i>
      <div><?= $previewStats['errors'] ?> baris memiliki error dan akan <strong>dilewati</strong> saat import. Baris lain yang valid tetap akan diimport.</div>
    </div>
    <?php endif; ?>

    
    <form method="POST">
      <div style="margin-bottom:12px;font-size:.85rem;font-weight:700;color:#37474f">Mode duplikasi (jika batch+lokasi sudah ada di stok):</div>
      <div class="is-mode-select" id="modeSelect">
        <label class="is-mode-opt selected" onclick="setMode(this)">
          <input type="radio" name="duplicate_mode" value="add" checked style="margin-right:6px">
          <strong>Tambah (Add)</strong><br>
          <span style="font-size:.78rem;color:#546e7a">Qty ditambahkan ke stok yang sudah ada</span>
        </label>
        <label class="is-mode-opt" onclick="setMode(this)">
          <input type="radio" name="duplicate_mode" value="replace" style="margin-right:6px">
          <strong>Ganti (Replace)</strong><br>
          <span style="font-size:.78rem;color:#546e7a">Qty diganti sesuai file (stok lama di-overwrite)</span>
        </label>
        <label class="is-mode-opt" onclick="setMode(this)">
          <input type="radio" name="duplicate_mode" value="skip" style="margin-right:6px">
          <strong>Skip Duplikat</strong><br>
          <span style="font-size:.78rem;color:#546e7a">Baris yang sudah ada akan dilewati</span>
        </label>
      </div>

      
      <div style="overflow-x:auto;margin-bottom:16px">
      <table class="is-tbl">
        <thead><tr>
          <th>#</th><th>Baris</th><th>Status</th>
          <th>Kode Produk</th><th>Nama Produk</th>
          <th>Batch</th><th>Lokasi</th>
          <th style="text-align:right">Qty</th><th>UOM</th>
          <th>Tgl Produksi</th><th>Tgl Exp</th>
          <th>Stock Status</th><th>Catatan / Error</th>
        </tr></thead>
        <tbody>
        <?php foreach ($previewRows as $idx => $row):
          $hasErr  = !empty($row['_errors']);
          $hasWarn = !empty($row['_warnings']);
          $rowClass = $hasErr ? 'has-error' : ($hasWarn ? 'has-warn' : '');
        ?>
        <tr class="<?= $rowClass ?>">
          <td style="color:#b0bec5;font-size:.72rem"><?= $idx + 1 ?></td>
          <td style="color:#90a4ae;font-size:.72rem"><?= $row['_row_num'] ?></td>
          <td>
            <?php if ($hasErr): ?>
              <span class="is-badge is-badge-err"><i class="fas fa-times"></i> Error</span>
            <?php elseif ($hasWarn): ?>
              <span class="is-badge is-badge-warn"><i class="fas fa-exclamation"></i> Warning</span>
            <?php else: ?>
              <span class="is-badge is-badge-ok"><i class="fas fa-check"></i> OK</span>
            <?php endif; ?>
          </td>
          <td><span class="is-tag"><?= htmlspecialchars($row['product_code']) ?></span></td>
          <td style="font-size:.8rem"><?= htmlspecialchars($row['product_name'] ?? '—') ?></td>
          <td style="font-family:monospace;font-size:.78rem"><?= htmlspecialchars($row['batch_number'] ?: '—') ?></td>
          <td style="font-family:monospace;font-size:.78rem"><?= htmlspecialchars($row['location'] ?: '—') ?></td>
          <td style="text-align:right;font-weight:600"><?= number_format($row['quantity'], 0) ?></td>
          <td><?= htmlspecialchars($row['uom']) ?></td>
          <td style="font-size:.76rem;color:#546e7a"><?= $row['manufacture_date'] ? date('d M Y', strtotime($row['manufacture_date'])) : '—' ?></td>
          <td style="font-size:.76rem;<?= ($row['expiry_date'] && $row['expiry_date'] < date('Y-m-d')) ? 'color:#014f4e;font-weight:600' : 'color:#546e7a' ?>">
            <?= $row['expiry_date'] ? date('d M Y', strtotime($row['expiry_date'])) : '—' ?>
          </td>
          <td>
            <span class="is-badge" style="<?= $row['stock_status'] === 'Available' ? 'background:#e8f5e9;color:#013d3c' : 'background:#fff8e1;color:#e65100' ?>">
              <?= htmlspecialchars($row['stock_status']) ?>
            </span>
          </td>
          <td style="font-size:.76rem;max-width:220px">
            <?php foreach ($row['_errors'] as $e): ?>
              <div style="color:#014f4e"><i class="fas fa-times-circle"></i> <?= htmlspecialchars($e) ?></div>
            <?php endforeach; ?>
            <?php foreach ($row['_warnings'] as $w): ?>
              <div style="color:#e65100"><i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($w) ?></div>
            <?php endforeach; ?>
            <?= $row['notes'] ? '<div style="color:#90a4ae">'.htmlspecialchars($row['notes']).'</div>' : '' ?>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>

      
      <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
        <?php if ($previewStats['valid'] > 0): ?>
        <button type="submit" name="confirm_import" class="is-btn is-btn-green" style="padding:10px 24px">
          <i class="fas fa-cloud-upload-alt"></i>
          Import <?= $previewStats['valid'] ?> Item Sekarang
        </button>
        <?php endif; ?>
        <button type="submit" name="cancel_preview" class="is-btn is-btn-outline">
          <i class="fas fa-times"></i> Batal / Upload Ulang
        </button>
        <span style="margin-left:auto;font-size:.78rem;color:#90a4ae">
          <i class="fas fa-info-circle"></i>
          <?= $previewStats['errors'] > 0 ? "{$previewStats['errors']} baris error akan dilewati. " : '' ?>
          <?= $previewStats['valid'] ?> baris siap diimport.
        </span>
      </div>
    </form>
  </div>
</div>

<?php else: ?>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:18px">

<div class="is-card">
  <div class="is-card-head">
    <h2><i class="fas fa-upload"></i> Upload File Excel / CSV</h2>
  </div>
  <div class="is-card-body">
    <form method="POST" enctype="multipart/form-data" id="uploadForm">
      <div class="is-drop-zone" id="dropZone" onclick="document.getElementById('stockFile').click()">
        <i class="fas fa-file-excel"></i>
        <div style="font-weight:700;color:#026766;margin-bottom:4px">Klik atau drag & drop file di sini</div>
        <div style="font-size:.8rem;color:#78909c">Format: .xlsx / .xls / .csv</div>
        <div id="fileName" style="margin-top:10px;font-size:.82rem;color:#013d3c;font-weight:600;display:none"></div>
      </div>
      <input type="file" name="stock_file" id="stockFile" accept=".xlsx,.xls,.csv" style="display:none" onchange="showFileName(this)">
      <div style="margin-top:14px;display:flex;gap:10px">
        <button type="submit" class="is-btn is-btn-green" style="flex:1;justify-content:center" id="uploadBtn" disabled>
          <i class="fas fa-eye"></i> Preview & Validasi
        </button>
        <a href="?download_template=1" class="is-btn is-btn-outline">
          <i class="fas fa-download"></i> Template
        </a>
      </div>
    </form>
  </div>
</div>

<div class="is-card">
  <div class="is-card-head">
    <h2><i class="fas fa-table"></i> Panduan Kolom Excel</h2>
  </div>
  <div class="is-card-body" style="font-size:.82rem">
    <table style="width:100%;border-collapse:collapse">
      <thead><tr style="background:#eceff1">
        <th style="padding:6px 10px;text-align:left;font-size:.72rem;text-transform:uppercase;color:#607d8b">Kolom</th>
        <th style="padding:6px 10px;text-align:left;font-size:.72rem;text-transform:uppercase;color:#607d8b">Keterangan</th>
        <th style="padding:6px 10px;text-align:center;font-size:.72rem;text-transform:uppercase;color:#607d8b">Wajib</th>
      </tr></thead>
      <tbody>
      <?php
      $cols = [
          ['product_code',     'Kode produk (harus terdaftar di master produk)',   true],
          ['batch_number',     'Nomor batch / lot produk',                         false],
          ['location',         'Kode lokasi bin (mis: A-01-01)',                   false],
          ['quantity',         'Jumlah stok',                                      true],
          ['uom',              'Drum / Carton / Pail / Bags / EA (default: Drum)', false],
          ['manufacture_date', 'Tanggal produksi (dd/mm/yyyy atau yyyy-mm-dd)',    false],
          ['expiry_date',      'Tanggal kedaluwarsa (dd/mm/yyyy atau yyyy-mm-dd)', false],
          ['stock_status',     'Available / Dues In / Reserved (default: Available)', false],
          ['notes',            'Catatan tambahan',                                 false],
      ];
      foreach ($cols as [$col, $desc, $required]): ?>
      <tr style="border-bottom:1px solid #f5f5f5">
        <td style="padding:6px 10px"><span class="is-tag"><?= $col ?></span></td>
        <td style="padding:6px 10px;color:#546e7a"><?= $desc ?></td>
        <td style="padding:6px 10px;text-align:center">
          <?= $required ? '<i class="fas fa-asterisk" style="color:#026766;font-size:.65rem"></i>' : '<span style="color:#b0bec5">—</span>' ?>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>

    <div style="margin-top:14px;padding:10px 14px;background:#fff8e1;border-radius:7px;border-left:3px solid #fbc02d;font-size:.79rem;color:#5d4037">
      <i class="fas fa-lightbulb" style="color:#f9a825"></i>
      <strong>Tips:</strong> Nama kolom fleksibel — sistem akan mengenali variasi seperti
      <em>Qty, Quantity, Actual Qty</em> atau <em>Exp Date, Expiry Date, Best Before</em>.
      Gunakan template untuk hasil terbaik.
    </div>
  </div>
</div>

</div>
<?php endif; ?>

</div>

<script>
function showFileName(input) {
  const fn = document.getElementById('fileName');
  const btn = document.getElementById('uploadBtn');
  if (input.files.length > 0) {
    fn.textContent = '📄 ' + input.files[0].name;
    fn.style.display = 'block';
    btn.disabled = false;
  }
}

const dz = document.getElementById('dropZone');
if (dz) {
  dz.addEventListener('dragover', e => { e.preventDefault(); dz.classList.add('dragover'); });
  dz.addEventListener('dragleave', () => dz.classList.remove('dragover'));
  dz.addEventListener('drop', e => {
    e.preventDefault(); dz.classList.remove('dragover');
    const fi = document.getElementById('stockFile');
    const dt = new DataTransfer();
    dt.items.add(e.dataTransfer.files[0]);
    fi.files = dt.files;
    showFileName(fi);
  });
}

function setMode(label) {
  document.querySelectorAll('.is-mode-opt').forEach(l => l.classList.remove('selected'));
  label.classList.add('selected');
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

<?php
error_reporting(0);
@ini_set('display_errors', 0);
ob_start();
session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/classes/Auth.php';
require_once __DIR__ . '/classes/Inbound.php';
require_once __DIR__ . '/classes/LocationManager.php';

$phpSpreadsheetLoaded = false;
$phpSpreadsheetError  = null;
try {
    require_once __DIR__ . '/vendor/autoload.php';
    $phpSpreadsheetLoaded = true;
} catch (\Exception $autoloadErr) {
    $phpSpreadsheetError = $autoloadErr->getMessage();
}

use PhpOffice\PhpSpreadsheet\IOFactory;

Auth::requireRole(['admin', 'operator']);

$pageTitle = 'Import Inbound Excel';
$currentPage = 'import';

$error   = null;
$success = null;
$previewData  = null;
$previewStats = null;

function parseInboundDate($val) {
    if (empty($val) || $val === '0') return null;

    
    if (is_numeric($val) && (float)$val > 40000) {
        try {
            $dt = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float)$val);
            return $dt->format('Y-m-d');
        } catch (Exception $e) {
            error_log("DEBUG parseInboundDate: Excel serial conversion failed for " . var_export($val, true));
        }
    }

    $val = trim((string)$val);
    if (empty($val)) return null;

    
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)) return $val;

    
    
    if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $val, $m)) {
        $d = (int)$m[1]; $mo = (int)$m[2]; $y = (int)$m[3];
        
        if ($d > 12 && $mo <= 12) return sprintf('%04d-%02d-%02d', $y, $mo, $d);
        if ($mo > 12 && $d <= 12) return sprintf('%04d-%02d-%02d', $y, $d, $mo); 
        
        return sprintf('%04d-%02d-%02d', $y, $mo, $d);
    }

    
    if (preg_match('/^(\d{1,2})-(\d{1,2})-(\d{4})$/', $val, $m)) {
        return sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[2], (int)$m[1]);
    }

    
    if (preg_match('/^(\d{4})\/(\d{1,2})\/(\d{1,2})$/', $val, $m)) {
        return sprintf('%04d-%02d-%02d', (int)$m[1], (int)$m[2], (int)$m[3]);
    }

    
    try {
        $dt = new DateTime($val);
        $result = $dt->format('Y-m-d');
        
        $y = (int)$dt->format('Y');
        if ($y < 1990 || $y > 2100) {
            error_log("DEBUG parseInboundDate: Year out of range for value " . var_export($val, true) . " year: " . $y);
            return null;
        }
        return $result;
    } catch (Exception $e) { 
        error_log("DEBUG parseInboundDate: DateTime parse failed for " . var_export($val, true) . " error: " . $e->getMessage());
        return null; 
    }
}

function resolveUomPerPallet($uom, $productUomPerPallet = 4) {
    switch (strtolower(trim($uom))) {
        case 'drum':   return 4;
        case 'carton': return intval($productUomPerPallet ?: 44);
        case 'pail':   return 24;
        case 'bags':   return 1;
        default:       return intval($productUomPerPallet ?: 4);
    }
}

function readInboundExcel($filePath, $ext) {
    if ($ext === 'csv') {
        $handle  = fopen($filePath, 'r');
        if (!$handle) throw new Exception("Tidak bisa membuka file CSV");
        $allRows = [];
        while (($row = fgetcsv($handle)) !== false) $allRows[] = $row;
        fclose($handle);
    } else {
        $spreadsheet = IOFactory::load($filePath);
        $sheet   = $spreadsheet->getActiveSheet();
        
        $allRows = $sheet->toArray(null, true, false, false);
    }
    if (empty($allRows)) throw new Exception("File kosong");

    $headerRowIndex = 0;
    $headers = [];
    $expectedKeywords = ['item code','actual qty','uom','material','od no','so no','shipment'];

    foreach ($allRows as $idx => $row) {
        $rowStr = strtolower(implode(' ', array_map(fn($v) => (string)($v ?? ''), $row)));
        $matchCount = 0;
        foreach ($expectedKeywords as $kw) {
            if (strpos($rowStr, $kw) !== false) $matchCount++;
        }
        if ($matchCount >= 2) {
            $headerRowIndex = $idx;
            
            $headers = array_map(fn($h) => strtolower(trim(ltrim(trim((string)($h ?? '')), '*'))), $row);
            break;
        }
        if ($idx === 0) $headers = array_map(fn($h) => strtolower(trim(ltrim(trim((string)($h ?? '')), '*'))), $row);
    }

    $col    = array_flip($headers);
    $colMap = [
        'month'            => ['month','bulan'],
        'po_no'            => ['po no','po number','po_no','gr number'],
        'shipment_no'      => ['shipment no','shipment number','shipment_no','shipment no.','shipment'],
        'od_number'        => ['od no','od no.','od number','od_number','od_no','outbound delivery','od'],
        'so_number'        => ['so no','so no.','so number','so_number','so_no','sales order','so'],
        'item_code'        => ['item code','material','material no','material no.','product code','sku','kode produk','item no'],
        'inbound_order_no' => ['inbound order no','inbound order number','order no','inbound_order_no','io number','inbound order'],
        'qty_order'        => ['qty order','order qty','qty_order','quantity order'],
        'uom'              => ['uom','unit','sales unit','bun','satuan'],
        'actual_qty'       => ['actual qty','actual_qty','received qty','quantity','qty terima','qty'],
        'pallet'           => ['pallet','pallet qty','jumlah pallet','plt'],
        'batch_no'         => ['batch no','batch number','batch_no','batch','lot'],
        'manufacture_date' => ['manufacture date','production date','mfg date','tgl produksi','mfg','tanggal produksi','tanggal manufacture','tgl manufacture','production','mfgdate','manufacturing date','tgl mfg','tanggal mfg','prod date','mfg. date','tgl produksi','tgl produksi (yyyy-mm-dd)','manufacture'],
        'exp_date'         => ['exp date','expiry date','expiration date','best before','tgl exp','exp','tanggal exp','tanggal expiry','tanggal kadaluarsa','kadaluarsa','expiry','exp.date','expiration','best by','best before date','expired date','tanggal expired','tgl expired','tgl kadaluarsa','exp date (yyyy-mm-dd)','expired','kadaluwarsa','tgl kadaluwarsa','tanggal kadaluwarsa'],
        'location'         => ['location','lokasi','warehouse','bin'],
        'remarks'          => ['remarks','notes','keterangan','catatan'],
    ];

    $resolvedCol = [];
    foreach ($colMap as $field => $aliases) {
        foreach ($aliases as $alias) {
            if (isset($col[$alias])) { 
                $resolvedCol[$field] = $col[$alias]; 
                break; 
            }
        }
        
        
        if (!isset($resolvedCol[$field])) {
            foreach ($aliases as $alias) {
                $aliasWords = explode(' ', $alias);
                foreach ($col as $colName => $colIdx) {
                    foreach ($aliasWords as $word) {
                        if (strlen($word) > 2 && stripos($colName, $word) !== false) {
                            $resolvedCol[$field] = $colIdx;
                            break 3;
                        }
                    }
                }
            }
        }
    }
    
    
    $getField = function($row, $field) use ($resolvedCol) {
        if (!isset($resolvedCol[$field])) return '';
        return trim((string)($row[$resolvedCol[$field]] ?? ''));
    };

    $data     = [];
    $dataRows = array_slice($allRows, $headerRowIndex + 1);

    foreach ($dataRows as $rowNum => $row) {
        if (empty(array_filter($row, fn($v) => $v !== null && $v !== ''))) continue;
        $itemCode = $getField($row, 'item_code');
        if (empty($itemCode)) continue;
        if (strpos($itemCode, '*') === 0 || stripos($itemCode, 'note') !== false
            || stripos($itemCode, 'keterangan') !== false) continue;
        $actualQty = floatval($getField($row, 'actual_qty'));
        $qtyOrder  = floatval($getField($row, 'qty_order'));
        
        if ($actualQty <= 0 && $qtyOrder > 0) $actualQty = $qtyOrder;
        if ($actualQty <= 0) continue;

        
        $odNo = trim($getField($row, 'od_number'));
        $soNo = trim($getField($row, 'so_number'));

        $data[] = [
            'row_num'          => $rowNum + $headerRowIndex + 2,
            'month'            => $getField($row, 'month'),
            'po_no'            => $getField($row, 'po_no'),
            'shipment_no'      => $getField($row, 'shipment_no'),
            'od_number'        => $odNo,
            'so_number'        => $soNo,
            'item_code'        => $itemCode,
            'inbound_order_no' => $getField($row, 'inbound_order_no'),
            'qty_order'        => $qtyOrder,
            'uom'              => $getField($row, 'uom') ?: 'Drum',
            'actual_qty'       => $actualQty,
            'pallet'           => floatval($getField($row, 'pallet')),
            'batch_no'         => $getField($row, 'batch_no'),
            'manufacture_date' => parseInboundDate($getField($row, 'manufacture_date')),
            'exp_date'         => parseInboundDate($getField($row, 'exp_date')),
            'location'         => $getField($row, 'location'),
            'remarks'          => $getField($row, 'remarks'),
        ];
    }
    return $data;
}

function enrichInboundRows($rawData, $db) {
    $enrichedData = [];
    foreach ($rawData as $rowData) {
        $stmt = $db->prepare("SELECT id, uom_type, uom_per_pallet FROM products WHERE product_code = ?");
        $stmt->execute([$rowData['item_code']]);
        $product = $stmt->fetch();
        if (!$product) {
            $stmt = $db->prepare("SELECT id, uom_type, uom_per_pallet FROM products WHERE product_code LIKE ? LIMIT 1");
            $stmt->execute(['%' . $rowData['item_code'] . '%']);
            $product = $stmt->fetch();
        }
        if (!$product) throw new Exception("Baris {$rowData['row_num']}: Produk '{$rowData['item_code']}' tidak ditemukan");

         
         if (empty($rowData['manufacture_date'])) {
              throw new Exception("Baris {$rowData['row_num']} ({$rowData['item_code']}): Kolom <b>Manufacture Date</b> wajib diisi di Excel (format: YYYY-MM-DD). Gunakan nama kolom seperti: 'Manufacture Date', 'Production Date', 'Tgl Produksi', atau 'Mfg Date'.");
         }
         
         $mfgCheck = date_parse($rowData['manufacture_date']);
         if (!empty($mfgCheck['error_count']) || !checkdate($mfgCheck['month'], $mfgCheck['day'], $mfgCheck['year'])) {
             throw new Exception("Baris {$rowData['row_num']} ({$rowData['item_code']}): <b>Manufacture Date</b> tidak valid: '{$rowData['manufacture_date']}'. Pastikan format YYYY-MM-DD (contoh: 2025-01-15).");
         }
         
         if (empty($rowData['exp_date'])) {
             try {
                 $d = new DateTime($rowData['manufacture_date']);
                 $d->modify('+4 years');
                 $rowData['exp_date'] = $d->format('Y-m-d');
                 $rowData['exp_date_auto'] = true;
             } catch (Exception $e) {
                 throw new Exception("Baris {$rowData['row_num']} ({$rowData['item_code']}): <b>Exp Date</b> tidak ditemukan di Excel dan gagal dihitung otomatis dari Manufacture Date. Isi kolom 'Exp Date' di Excel.");
             }
         } else {
             $expCheck = date_parse($rowData['exp_date']);
             if (!empty($expCheck['error_count']) || !checkdate($expCheck['month'], $expCheck['day'], $expCheck['year'])) {
                 throw new Exception("Baris {$rowData['row_num']} ({$rowData['item_code']}): <b>Exp Date</b> tidak valid: '{$rowData['exp_date']}'. Pastikan format YYYY-MM-DD (contoh: 2029-01-15).");
             }
             $rowData['exp_date_auto'] = false;
         }

        $rowData['product_id']    = $product['id'];
        $uom = (!empty($rowData['uom']) && $rowData['uom'] !== '0') ? $rowData['uom'] : $product['uom_type'];
        $uomPerPallet             = resolveUomPerPallet($uom, $product['uom_per_pallet']);
        $rowData['uom']           = $uom;
        $rowData['uom_per_pallet'] = $uomPerPallet;

        $dist                     = Inbound::calculatePalletDistribution($rowData['actual_qty'], $uomPerPallet);
        $rowData['pallet_count']  = count($dist);
        $rowData['pallet_dist']   = $dist;
        $rowData['location_hint'] = strtoupper(trim($rowData['location'] ?? ''));

        $enrichedData[] = $rowData;
    }
    return $enrichedData;
}

if (isset($_GET['action']) && $_GET['action'] === 'get_locations') {
    ob_clean();
    header('Content-Type: application/json');
    $qty         = floatval($_GET['qty'] ?? 0);
    $uom         = $_GET['uom'] ?? 'Drum';
    $uomPP       = max(1, intval($_GET['uom_per_pallet'] ?? 4));
    $exclude     = json_decode($_GET['exclude'] ?? '[]', true) ?: [];

    $db   = db();
    $stmt = $db->prepare("
        SELECT lm.location_code, lm.row_name, lm.zone, lm.aisle, lm.rack, lm.position
        FROM location_master lm
        WHERE lm.is_active = 1
          AND lm.location_code NOT IN ('QUA_SHELL','STAGING')
          AND lm.location_code NOT IN (
              SELECT DISTINCT location_code FROM stock_locations
              WHERE status IN ('Available','Reserved')
          )
        ORDER BY
          CASE WHEN lm.row_name IN ('B','C','D','E') THEN 0 ELSE 1 END,
          lm.aisle, lm.rack, lm.row_name, lm.position
        LIMIT 300
    ");
    $stmt->execute();
    $all = $stmt->fetchAll(PDO::FETCH_ASSOC);

    
    $filtered = array_values(array_filter($all, fn($l) => !in_array($l['location_code'], $exclude)));

    
    $fullPallets = intdiv((int)$qty, (int)$uomPP);
    $remainder   = fmod($qty, $uomPP);
    $dist        = [];
    $seq = 1;
    for ($i = 0; $i < $fullPallets; $i++) {
        $dist[] = ['pallet_seq' => $seq++, 'quantity' => $uomPP, 'is_full' => true];
    }
    if ($remainder > 0) {
        $dist[] = ['pallet_seq' => $seq, 'quantity' => $remainder, 'is_full' => false];
    }

    $levelBE = array_values(array_filter($filtered, fn($l) => in_array($l['row_name'], ['B','C','D','E'])));
    $levelA  = array_values(array_filter($filtered, fn($l) => $l['row_name'] === 'A'));
    $anyPool = array_merge($levelBE, $levelA); 

    $suggested = [];
    foreach ($dist as $p) {
        if ($p['is_full']) {
            $loc = array_shift($levelBE) ?? array_shift($anyPool) ?? null;
        } else {
            $loc = array_shift($levelA) ?? array_shift($anyPool) ?? null;
        }
        $suggested[] = [
            'pallet_seq'    => $p['pallet_seq'],
            'quantity'      => $p['quantity'],
            'is_full'       => $p['is_full'],
            'location_code' => $loc['location_code'] ?? '',
            'row_name'      => $loc['row_name'] ?? '',
        ];
    }

    
    $usedInSuggested = array_filter(array_column($suggested, 'location_code'));
    $allFiltered2 = array_values(array_filter($filtered, fn($l) => !in_array($l['location_code'], $usedInSuggested)));
    $extras = array_slice($allFiltered2, 0, 8);

    echo json_encode(['suggested' => $suggested, 'extras' => $extras]);
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'all_locations') {
    ob_clean();
    header('Content-Type: application/json');
    $db   = db();
    $stmt = $db->query("SELECT location_code, row_name, zone
                        FROM location_master
                        WHERE is_active=1 AND location_code NOT IN ('QUA_SHELL','STAGING')
                        ORDER BY aisle,rack,row_name,position LIMIT 500");
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    Auth::requireWrite();
    try {
        if (!$phpSpreadsheetLoaded) throw new Exception("Library PhpSpreadsheet tidak tersedia. Pastikan PHP versi ≥8.2 aktif di Laragon, lalu reload halaman. Detail: " . $phpSpreadsheetError);
        $file = $_FILES['csv_file'];
        if ($file['error'] !== UPLOAD_ERR_OK) throw new Exception("Upload error: kode " . $file['error']);
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['xlsx','xls','csv'])) throw new Exception("Format harus .xlsx, .xls, atau .csv");

        $rawData = readInboundExcel($file['tmp_name'], $ext);
        if (empty($rawData)) throw new Exception("Tidak ada data. Cek format template.");

        $db = db();
        $previewData  = enrichInboundRows($rawData, $db);
        $previewStats = [
            'total_rows'    => count($previewData),
            'total_pallets' => array_sum(array_column($previewData, 'pallet_count')),
        ];
    } catch (\Throwable $e) {
        $error = $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_import'])) {
    Auth::requireWrite();
    try {
        $carrierName = trim($_POST['carrier_name'] ?? '') ?: null;
        $items       = json_decode($_POST['items_json'] ?? '[]', true);

        if (empty($items)) throw new Exception("Tidak ada item");
        
        
        foreach ($items as $idx => &$itemData) {
            if (empty($itemData['manufacture_date'])) {
                throw new Exception("Item #{$idx} ({$itemData['item_code']}): Manufacture Date tidak boleh kosong. Isi kolom 'Manufacture Date' di Excel.");
            }
            
            if (empty($itemData['exp_date'])) {
                try {
                    $d = new DateTime($itemData['manufacture_date']);
                    $d->modify('+4 years');
                    $itemData['exp_date'] = $d->format('Y-m-d');
                } catch (Exception $e) {
                    throw new Exception("Item #{$idx} ({$itemData['item_code']}): Exp Date tidak ditemukan dan gagal dihitung otomatis. Isi kolom 'Exp Date' di Excel.");
                }
            }
        }
        unset($itemData); 

        $db = db();
        $db->beginTransaction();
        try {
            $importedCount    = 0;
            $totalPallets     = 0;
            $shipmentOrderMap = []; 

            foreach ($items as $itemData) {
                $stmt = $db->prepare("SELECT id, uom_type, uom_per_pallet FROM products WHERE product_code = ?");
                $stmt->execute([$itemData['item_code']]);
                $product = $stmt->fetch();
                if (!$product) {
                    $stmt = $db->prepare("SELECT id, uom_type, uom_per_pallet FROM products WHERE product_code LIKE ? LIMIT 1");
                    $stmt->execute(['%' . $itemData['item_code'] . '%']);
                    $product = $stmt->fetch();
                }
                if (!$product) throw new Exception("Produk '{$itemData['item_code']}' tidak ditemukan");

                $uom        = $itemData['uom'] ?? $product['uom_type'];
                $palletLocs = $itemData['pallet_locations'] ?? [];
                $firstLoc   = !empty($palletLocs) ? ($palletLocs[0]['location_code'] ?? null) : null;

                
                $inboundId = null;
                $groupKey  = !empty($itemData['shipment_no'])
                    ? 'SHP_' . $itemData['shipment_no']
                    : null;

                if ($groupKey && isset($shipmentOrderMap[$groupKey])) {
                    $inboundId = $shipmentOrderMap[$groupKey];
                }
                if (!$inboundId) {
                    
                    $inboundNumber = !empty($itemData['shipment_no'])
                        ? $itemData['shipment_no']
                        : Inbound::generateNumber();
                    
                    $stmt = $db->prepare("SELECT id FROM inbound_orders WHERE order_number = ? OR shipment_no = ? LIMIT 1");
                    $stmt->execute([$inboundNumber, $itemData['shipment_no'] ?: null]);
                    $existing = $stmt->fetch();
                    if ($existing) {
                        $inboundId = $existing['id'];
                    } else {
                        $stmt = $db->prepare("INSERT INTO inbound_orders
                                (order_number, order_date, carrier_name, status, shipment_no, created_by)
                                VALUES (?, CURDATE(), ?, 'Draft', ?, ?)");
                        $stmt->execute([
                            $inboundNumber, $carrierName,
                            $itemData['shipment_no'] ?: null,
                            $_SESSION['user_id']
                        ]);
                        $inboundId = $db->lastInsertId();
                    }
                    if ($groupKey) $shipmentOrderMap[$groupKey] = $inboundId;
                }

                Inbound::addItem($inboundId, [
                    'product_id'        => $product['id'],
                    'batch_number'      => $itemData['batch_no'] ?: null,
                    'batch_no'          => $itemData['batch_no'] ?: null,
                    'od_number'         => $itemData['od_number'] ?: null,
                    'so_number'         => $itemData['so_number'] ?: null,
                    'location'          => $firstLoc,
                    'pallet_locations'  => $palletLocs,
                    'quantity'          => floatval($itemData['actual_qty']),
                    'uom'               => $uom,
                    'actual_qty'        => floatval($itemData['actual_qty']),
                    'manufacture_date'  => $itemData['manufacture_date'] ?: null,
                    'exp_date'          => $itemData['exp_date'] ?: null,
                    'in_process_status' => 'Dues In',
                    'notes'             => $itemData['remarks'] ?: null,
                ]);

                $totalPallets += count($palletLocs);
                $importedCount++;
            }

            $db->commit();
            $success = "Berhasil import {$importedCount} item ({$totalPallets} pallet) dengan lokasi per pallet.";
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<style>
.grad-blue  { background: linear-gradient(135deg,#026766 0%,#014f4e 100%); }

.plt-card {
    display:flex; align-items:center; gap:6px;
    padding:5px 8px; border-radius:8px;
    border:1.5px solid #e2e8f0; background:#f8faff;
    margin-bottom:5px; transition: border-color .15s, background .15s;
}
.plt-card.is-set   { border-color:#86efac; background:#e6f7f7; }
.plt-card.is-empty { border-color:#b2e5e5; background:#e6f7f7; }
.plt-card.is-open  { border-color:#a5b4fc; background:#e6f7f7; }

.plt-seq   { font-size:.72rem; font-weight:700; color:#6366f1; min-width:24px; flex-shrink:0; }
.plt-qty   { font-size:.7rem; color:#64748b; white-space:nowrap; min-width:70px; text-align:right; flex-shrink:0; }
.plt-badge { margin-left:3px; }

.plt-assign-btn {
    width:100%; display:flex; align-items:center; justify-content:center; gap:5px;
    padding:5px 10px; border-radius:6px; font-size:.78rem; font-weight:600;
    cursor:pointer; border:1.5px dashed #f97316; color:#ea580c; background:#fff7ed;
    transition:all .15s;
}
.plt-assign-btn:hover { background:#fed7aa; border-color:#ea580c; }

.plt-assigned-view {
    display:flex; align-items:center; gap:5px; width:100%;
}
.plt-loc-badge {
    flex:1; font-family:monospace; font-size:.82rem; font-weight:700;
    color:#026766; background:#e0f7f7; border:1.5px solid #86efac;
    border-radius:5px; padding:3px 9px; letter-spacing:.03em;
}
.plt-edit-btn {
    padding:3px 7px; border-radius:5px; border:1px solid #a5b4fc;
    color:#6366f1; background:#e6f7f7; cursor:pointer; font-size:.72rem;
    transition:all .12s; flex-shrink:0;
}
.plt-edit-btn:hover { background:#6366f1; color:#fff; }
.plt-clear-btn {
    padding:3px 7px; border-radius:5px; border:1px solid #b2e5e5;
    color:#026766; background:#e6f7f7; cursor:pointer; font-size:.72rem;
    transition:all .12s; flex-shrink:0;
}
.plt-clear-btn:hover { background:#026766; color:#fff; }

.plt-assign-panel {
    position:absolute; top:calc(100% + 4px); left:0; right:0; z-index:9999;
    background:#fff; border:1.5px solid #a5b4fc;
    border-radius:10px; box-shadow:0 10px 30px rgba(99,102,241,.18);
    padding:10px; min-width:260px;
}
.panel-section-label {
    font-size:.65rem; font-weight:700; letter-spacing:.06em;
    color:#9ca3af; text-transform:uppercase; margin-bottom:6px;
}
.sugg-chip {
    display:inline-flex; align-items:center; gap:4px;
    padding:4px 10px; border-radius:20px; font-size:.75rem;
    font-family:monospace; font-weight:700; cursor:pointer;
    border:1.5px solid; transition:all .12s; margin:2px;
}
.sugg-chip.chip-be {
    color:#026766; border-color:#e0f7f7; background:#e6f7f7;
}
.sugg-chip.chip-be:hover { background:#026766; color:#fff; border-color:#026766; }
.sugg-chip.chip-a {
    color:#b45309; border-color:#fde68a; background:#fffbeb;
}
.sugg-chip.chip-a:hover { background:#d97706; color:#fff; border-color:#d97706; }
.sugg-chip.chip-rec {
    color:#026766; border-color:#86efac; background:#e6f7f7;
}
.sugg-chip.chip-rec:hover { background:#026766; color:#fff; border-color:#026766; }

.panel-divider { border:none; border-top:1px solid #e5e7eb; margin:8px 0; }

.plt-input {
    width:100%; font-family:monospace; font-size:.8rem; font-weight:600;
    border:1px solid #cbd5e1; border-radius:5px; padding:4px 8px;
    text-transform:uppercase; color:#1e293b; background:#fff; outline:none;
    box-sizing:border-box;
}
.plt-input:focus { border-color:#6366f1; box-shadow:0 0 0 2px rgba(99,102,241,.15); }

.loc-dropdown {
    position:absolute; z-index:10000; background:#fff;
    border:1px solid #cbd5e1; border-radius:8px;
    box-shadow:0 8px 24px rgba(0,0,0,.12);
    max-height:180px; overflow-y:auto; min-width:200px;
}
.loc-dropdown .loc-opt {
    padding:6px 10px; font-size:.78rem; font-family:monospace;
    cursor:pointer; display:flex; align-items:center; justify-content:space-between;
}
.loc-dropdown .loc-opt:hover, .loc-dropdown .loc-opt.active { background:#e6f7f7; }
.loc-dropdown .loc-opt .lvl-tag {
    font-size:.65rem; padding:1px 5px; border-radius:4px;
    font-weight:700; margin-left:6px;
}
.tag-BE { background:#e0f7f7; color:#026766; }
.tag-A  { background:#fef3c7; color:#92400e; }

.btn-suggest {
    display:inline-flex; align-items:center; gap:5px;
    padding:5px 12px; border-radius:7px; font-size:.78rem; font-weight:600;
    cursor:pointer; border:1.5px solid #6366f1; color:#6366f1; background:#fff;
    transition:all .15s; white-space:nowrap;
}
.btn-suggest:hover { background:#6366f1; color:#fff; }
.btn-suggest-all {
    background:#6366f1; color:#fff; border-color:#6366f1;
}
.btn-suggest-all:hover { background:#4f46e5; }
</style>

<div class="space-y-6">

<div class="grad-blue rounded-2xl shadow-lg p-8 text-white">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px">
      <div>
        <h1 class="text-2xl font-bold mb-1"><i class="fas fa-file-import mr-2"></i>Import Inbound</h1>
        <p style="opacity:.75;font-size:.875rem">Upload Excel &rarr; Assign lokasi per pallet &rarr; Import</p>
      </div>
      <a href="inbound.php" style="background:rgba(255,255,255,.15);color:#fff;padding:7px 16px;border-radius:8px;font-size:.82rem;font-weight:600;text-decoration:none;display:inline-flex;align-items:center;gap:6px;border:1px solid rgba(255,255,255,.25)">
        <i class="fas fa-arrow-left"></i> Kembali
      </a>
    </div>
</div>

<?php if ($success): ?>
<div class="wms-alert-success">
  <i class="fas fa-check-circle mr-2"></i><?= htmlspecialchars($success) ?>
  <a href="inbound.php" style="margin-left:12px;font-weight:700;text-decoration:underline">Lihat Inbound →</a>
</div>
<?php endif; ?>
<?php if ($error): ?>
<div class="wms-alert-error">
  <i class="fas fa-exclamation-circle mr-2"></i><span><?= $error ?></span>
</div>
<?php endif; ?>

<?php if (!$previewData): ?>

<div style="background:#fff;border-radius:14px;box-shadow:0 1px 8px rgba(0,0,0,.08);padding:28px">
  <h2 style="font-size:1rem;font-weight:700;color:#111827;margin-bottom:20px;display:flex;align-items:center;gap:10px">
    <span style="background:#026766;color:#fff;width:26px;height:26px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:.8rem;font-weight:800;flex-shrink:0">1</span>
    Upload File Excel
  </h2>

  <!-- Template download -->
  <div style="margin-bottom:20px">
    <a href="import_template_excel.php?type=inbound" download
       style="background:#0369a1;color:#fff;padding:10px 20px;border-radius:8px;font-size:.875rem;font-weight:600;text-decoration:none;display:inline-flex;align-items:center;gap:8px">
      <i class="fas fa-file-excel"></i> Download Template
    </a>
  </div>

  <form method="POST" enctype="multipart/form-data" style="display:grid;gap:14px" autocomplete="off">
    <div>
      <label class="wms-label">Carrier / Transporter</label>
      <input type="text" name="carrier_name" class="wms-input"
             placeholder="e.g. PT Maju Jaya Logistics"
             value="<?= htmlspecialchars($_POST['carrier_name'] ?? '') ?>">
    </div>
    <div>
      <label class="wms-label">File Excel (.xlsx / .xls / .csv) <span class="wms-req">*</span></label>
      <input type="file" name="csv_file" accept=".xlsx,.xls,.csv" required class="wms-input" style="padding:8px 12px">
    </div>
    <button type="submit"
            style="width:100%;padding:13px;background:linear-gradient(135deg,#026766,#014f4e);color:#fff;border:none;border-radius:10px;font-size:.95rem;font-weight:700;cursor:pointer">
      <i class="fas fa-arrow-right mr-2"></i>Lanjut: Preview &amp; Assign Lokasi
    </button>
  </form>
</div>

<?php else: ?>

<script>
var IMPORT_ITEMS = <?= json_encode(array_map(function($r) {
    return [
        'item_code'        => $r['item_code'],
        'shipment_no'      => $r['shipment_no'],
        'od_number'        => $r['od_number'] ?? '',
        'so_number'        => $r['so_number'] ?? '',
        'batch_no'         => $r['batch_no'],
        'actual_qty'       => $r['actual_qty'],
        'uom'              => $r['uom'],
        'uom_per_pallet'   => $r['uom_per_pallet'],
        'manufacture_date' => $r['manufacture_date'],
        'exp_date'         => $r['exp_date'],
        'exp_date_auto'    => $r['exp_date_auto'] ?? false,
        'remarks'          => $r['remarks'],
        'pallet_dist'      => $r['pallet_dist'],
        'location_hint'    => $r['location_hint'],
        'pallet_locations' => [],
    ];
}, $previewData), JSON_UNESCAPED_UNICODE) ?>;
</script>

<div style="background:#fff;border-radius:14px;box-shadow:0 1px 8px rgba(0,0,0,.08);overflow:visible">

  <!-- Step 2 header -->
  <div style="padding:20px 24px;border-bottom:1px solid #f3f4f6;background:#fffbeb;border-radius:14px 14px 0 0">
    <div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:12px">
      <div style="flex:1;min-width:0">
        <h2 style="font-size:1rem;font-weight:700;color:#111827;display:flex;align-items:center;gap:10px">
          <span style="background:#d97706;color:#fff;width:26px;height:26px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:.8rem;font-weight:800;flex-shrink:0">2</span>
          Assign Lokasi Per Pallet
        </h2>
        <p style="font-size:.82rem;color:#92400e;margin-top:6px">
          <i class="fas fa-info-circle mr-1"></i>Klik <b>Assign Lokasi</b> tiap pallet, atau gunakan <b>Suggest Semua</b> untuk auto-assign.
        </p>
        <div style="margin-top:12px;display:flex;align-items:center;gap:10px;flex-wrap:wrap">
          <label style="font-size:.82rem;font-weight:600;color:#374151;white-space:nowrap">
            <i class="fas fa-truck-moving mr-1" style="color:#6366f1"></i>Carrier:
          </label>
          <input type="text" id="carrierNameStep2"
                 value="<?= htmlspecialchars($_POST['carrier_name'] ?? '') ?>"
                 placeholder="e.g. PT Maju Jaya Logistics"
                 style="border:1px solid #d1d5db;border-radius:8px;padding:6px 12px;font-size:.82rem;font-weight:600;color:#111827;background:#fff;min-width:220px">
        </div>
      </div>
      <a href="import_inbound.php"
         style="background:#f3f4f6;color:#374151;padding:8px 14px;border-radius:8px;font-size:.8rem;font-weight:600;text-decoration:none;display:inline-flex;align-items:center;gap:6px;white-space:nowrap;align-self:flex-start">
        <i class="fas fa-arrow-left"></i> Upload Ulang
      </a>
    </div>
  </div>

  <!-- Status bar -->
  <div style="padding:10px 24px;background:#f9fafb;border-bottom:1px solid #f3f4f6;display:flex;align-items:center;gap:12px;flex-wrap:wrap;font-size:.82rem">
    <span style="color:#374151"><?= count($previewData) ?> item &nbsp;·&nbsp; <?= $previewStats['total_pallets'] ?> pallet total</span>
    <span id="cntAssigned" style="color:#16a34a;font-weight:600">—</span>
    <span id="cntMissing"  style="color:#dc2626;font-weight:600"></span>
    <div style="margin-left:auto;display:flex;gap:8px">
      <button onclick="clearAll()" class="btn-suggest" style="color:#6b7280;border-color:#d1d5db;font-size:.75rem">
        <i class="fas fa-times"></i> Clear Semua
      </button>
      <button onclick="suggestAll()" class="btn-suggest btn-suggest-all">
        <i class="fas fa-magic"></i> Suggest Semua
      </button>
    </div>
  </div>

  <!-- Items -->
  <div style="border-top:none" id="itemsContainer">
  <?php foreach ($previewData as $idx => $row): ?>
  <div style="padding:18px 24px;border-bottom:1px solid #f3f4f6" id="irow-<?= $idx ?>">

    <!-- Item header -->
    <div style="display:flex;align-items:flex-start;gap:12px;flex-wrap:wrap;margin-bottom:12px">
      <div style="flex:1;min-width:0">
        <!-- Badges row -->
        <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;margin-bottom:4px">
          <span style="font-weight:700;color:#111827;font-size:.88rem"><?= htmlspecialchars($row['item_code']) ?></span>
          <?php if ($row['batch_no']): ?>
          <span style="font-size:.72rem;font-family:monospace;background:#f3f4f6;color:#374151;padding:1px 7px;border-radius:4px"><?= htmlspecialchars($row['batch_no']) ?></span>
          <?php endif; ?>
          <?php if (!empty($row['od_number'])): ?>
          <span style="font-size:.72rem;font-family:monospace;background:#dcfce7;color:#166534;border:1px solid #bbf7d0;padding:1px 7px;border-radius:4px;font-weight:600">OD: <?= htmlspecialchars($row['od_number']) ?></span>
          <?php endif; ?>
          <?php if (!empty($row['so_number'])): ?>
          <span style="font-size:.72rem;font-family:monospace;background:#e0e7ff;color:#4338ca;border:1px solid #c7d2fe;padding:1px 7px;border-radius:4px;font-weight:600">SO: <?= htmlspecialchars($row['so_number']) ?></span>
          <?php endif; ?>
          <span style="font-size:.72rem;background:#ede9fe;color:#6d28d9;padding:2px 8px;border-radius:4px;font-weight:700"><?= number_format($row['actual_qty']) ?> <?= htmlspecialchars($row['uom']) ?></span>
          <span style="font-size:.72rem;background:#dbeafe;color:#1d4ed8;padding:2px 8px;border-radius:4px;font-weight:700"><?= $row['pallet_count'] ?> pallet</span>
          <?php if ($row['location_hint']): ?>
          <span style="font-size:.72rem;background:#fef9c3;color:#854d0e;border:1px solid #fde68a;padding:1px 7px;border-radius:4px">
            <i class="fas fa-map-marker-alt mr-1"></i><?= htmlspecialchars($row['location_hint']) ?>
          </span>
          <?php endif; ?>
        </div>
        <!-- Sub info -->
        <div style="font-size:.72rem;color:#9ca3af">
          <?= $row['uom_per_pallet'] ?> <?= strtolower($row['uom']) ?>/pallet<?php if ($row['shipment_no']): ?> · Shipment: <span style="font-family:monospace"><?= htmlspecialchars($row['shipment_no']) ?></span><?php endif; ?>
        </div>
        <!-- Dates row -->
        <div style="display:flex;flex-wrap:wrap;gap:10px;margin-top:8px;align-items:center">
          <input type="hidden" id="mfg-<?= $idx ?>" value="<?= htmlspecialchars($row['manufacture_date']) ?>">
          <input type="hidden" id="exp-<?= $idx ?>" value="<?= htmlspecialchars($row['exp_date']) ?>">
          <div>
            <div style="font-size:.68rem;font-weight:600;color:#9ca3af;margin-bottom:2px">Mfg Date</div>
            <div style="font-size:.78rem;font-family:monospace;background:#f9fafb;border:1px solid #e5e7eb;border-radius:5px;padding:3px 8px;color:#374151"><?= htmlspecialchars($row['manufacture_date'] ?: '—') ?></div>
          </div>
          <div>
            <div style="font-size:.68rem;font-weight:600;color:#9ca3af;margin-bottom:2px">
              Exp Date<?php if (!empty($row['exp_date_auto'])): ?> <span style="color:#d97706;font-weight:400">(auto)</span><?php endif; ?>
            </div>
            <?php if (!empty($row['exp_date_auto'])): ?>
            <div style="font-size:.78rem;font-family:monospace;background:#fffbeb;border:1px solid #fde68a;border-radius:5px;padding:3px 8px;color:#92400e;font-weight:600"><?= htmlspecialchars($row['exp_date']) ?></div>
            <?php else: ?>
            <div style="font-size:.78rem;font-family:monospace;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:5px;padding:3px 8px;color:#166534;font-weight:600"><?= htmlspecialchars($row['exp_date']) ?></div>
            <?php endif; ?>
          </div>
          <?php if (!empty($row['exp_date_auto'])): ?>
          <span style="font-size:.68rem;color:#d97706"><i class="fas fa-calculator mr-1"></i>+4 tahun dari Mfg</span>
          <?php endif; ?>
        </div>
      </div>
      <!-- Action buttons -->
      <div style="display:flex;gap:8px;flex-shrink:0">
        <button type="button" onclick="suggestItem(<?= $idx ?>)" class="btn-suggest">
          <i class="fas fa-magic"></i> Suggest
        </button>
        <button type="button" onclick="clearItem(<?= $idx ?>)"
                style="padding:5px 10px;border-radius:6px;border:1px solid #e5e7eb;color:#9ca3af;background:#fff;cursor:pointer;font-size:.78rem;transition:all .12s"
                onmouseover="this.style.color='#dc2626';this.style.borderColor='#fca5a5'"
                onmouseout="this.style.color='#9ca3af';this.style.borderColor='#e5e7eb'">
          <i class="fas fa-times"></i>
        </button>
      </div>
    </div>

    <!-- Pallet grid -->
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:6px"
         id="pallets-<?= $idx ?>">
        <?php foreach ($row['pallet_dist'] as $pi => $p): ?>
            <?php $initLoc = $row['location_hint'] ?: ''; ?>
            <div class="plt-card <?= $initLoc ? 'is-set' : 'is-empty' ?>"
                 id="pcard-<?= $idx ?>-<?= $pi ?>">

                
                <span class="plt-seq">P<?= $p['pallet_seq'] ?></span>

                
                <div class="flex-1 relative" style="min-width:0; overflow:visible">

                    
                    <button class="plt-assign-btn"
                            id="abtn-<?= $idx ?>-<?= $pi ?>"
                            style="<?= $initLoc ? 'display:none' : '' ?>"
                            onclick="openAssignPanel(<?= $idx ?>, <?= $pi ?>)">
                        <i class="fas fa-map-marker-alt"></i> Assign Lokasi
                    </button>

                    
                    <div class="plt-assigned-view"
                         id="aview-<?= $idx ?>-<?= $pi ?>"
                         style="<?= $initLoc ? '' : 'display:none' ?>">
                        <span class="plt-loc-badge"
                              id="acode-<?= $idx ?>-<?= $pi ?>"><?= htmlspecialchars($initLoc) ?></span>
                        <button class="plt-edit-btn"
                                title="Ubah lokasi"
                                onclick="openAssignPanel(<?= $idx ?>, <?= $pi ?>)">
                            <i class="fas fa-pencil-alt"></i>
                        </button>
                        <button class="plt-clear-btn"
                                title="Hapus lokasi"
                                onclick="clearPallet(<?= $idx ?>, <?= $pi ?>)">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>

                    
                    <div class="plt-assign-panel"
                         id="apanel-<?= $idx ?>-<?= $pi ?>"
                         style="display:none">

                        
                        <div class="panel-section-label">
                            <i class="fas fa-lightbulb mr-1"></i>Saran Lokasi
                        </div>
                        <div id="asugg-<?= $idx ?>-<?= $pi ?>" class="mb-1">
                            <span class="text-xs text-gray-400 italic">Memuat...</span>
                        </div>

                        <hr class="panel-divider">

                        
                        <div class="panel-section-label">
                            <i class="fas fa-keyboard mr-1"></i>Ketik Manual
                        </div>
                        <div class="relative">
                            <input type="text"
                                   id="loc-<?= $idx ?>-<?= $pi ?>"
                                   class="plt-input"
                                   placeholder="Ketik kode lokasi…"
                                   autocomplete="off"
                                   oninput="onLocInput(this,<?= $idx ?>,<?= $pi ?>)"
                                   onblur="onManualBlur(<?= $idx ?>,<?= $pi ?>)"
                                   onkeydown="onLocKey(event,<?= $idx ?>,<?= $pi ?>)">
                            <div class="loc-dropdown"
                                 id="drop-<?= $idx ?>-<?= $pi ?>"
                                 style="display:none"></div>
                        </div>

                        
                        <div class="text-right mt-2">
                            <button onclick="closeAssignPanel(<?= $idx ?>,<?= $pi ?>)"
                                    class="text-xs text-gray-400 hover:text-gray-600 px-2 py-1 rounded border border-gray-200">
                                <i class="fas fa-times mr-1"></i>Tutup
                            </button>
                        </div>
                    </div>

                </div>

                
                <span class="plt-qty">
                    <?= number_format($p['quantity']) ?> <?= strtolower($row['uom']) ?>
                    <span id="badge-<?= $idx ?>-<?= $pi ?>" class="plt-badge">
                        <?= $initLoc
                            ? '<span style="color:#026766;font-weight:700">✓</span>'
                            : '<span style="color:#9ca3af;font-weight:700">—</span>' ?>
                    </span>
                </span>

            </div>
        <?php endforeach; ?>
    </div>
  </div>
  <?php endforeach; ?>
  </div>

  <!-- Import footer -->
  <div style="padding:18px 24px;background:#f9fafb;border-top:1px solid #f3f4f6;border-radius:0 0 14px 14px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px">
    <div id="importStatus" style="font-size:.85rem;color:#6b7280"></div>
    <button id="btnImport" onclick="doImport()" disabled
            style="padding:12px 32px;background:linear-gradient(135deg,#026766,#014f4e);color:#fff;border:none;border-radius:10px;font-size:.95rem;font-weight:700;cursor:pointer;opacity:.4;transition:opacity .15s">
      <i class="fas fa-file-import mr-2"></i>Konfirmasi &amp; Import
    </button>
  </div>
</div>

<form id="importForm" method="POST" style="display:none">
    <input type="hidden" name="do_import"    value="1">
    <input type="hidden" name="carrier_name" id="fCarrierName">
    <input type="hidden" name="items_json"   id="fItemsJson">
</form>

<script>

var ALL_LOCS = [];   

var assignments = {};
<?php foreach ($previewData as $idx => $row): ?>
assignments[<?= $idx ?>] = {};
<?php foreach ($row['pallet_dist'] as $pi => $p): ?>
assignments[<?= $idx ?>][<?= $pi ?>] = <?= json_encode($row['location_hint']) ?>;
<?php endforeach; ?>
<?php endforeach; ?>

var suggCache = {};

var openPanel = null; 

fetch('import_inbound.php?action=all_locations')
    .then(function(r){ return r.json(); })
    .then(function(d){ ALL_LOCS = d; updateCounters(); });

document.addEventListener('DOMContentLoaded', function(){ updateCounters(); });

document.addEventListener('click', function(e) {
    if (!openPanel) return;
    var panelEl = document.getElementById('apanel-' + openPanel.itemIdx + '-' + openPanel.pi);
    var btnEl   = document.getElementById('abtn-'   + openPanel.itemIdx + '-' + openPanel.pi);
    var editEl  = document.getElementById('aview-'  + openPanel.itemIdx + '-' + openPanel.pi);
    if (panelEl && !panelEl.contains(e.target) &&
        btnEl   && !btnEl.contains(e.target)   &&
        editEl  && !editEl.contains(e.target)) {
        closeAssignPanel(openPanel.itemIdx, openPanel.pi);
    }
});

function openAssignPanel(itemIdx, pi) {
    
    if (openPanel && (openPanel.itemIdx !== itemIdx || openPanel.pi !== pi)) {
        closeAssignPanel(openPanel.itemIdx, openPanel.pi);
    }

    var card    = document.getElementById('pcard-'  + itemIdx + '-' + pi);
    var panel   = document.getElementById('apanel-' + itemIdx + '-' + pi);
    var abtn    = document.getElementById('abtn-'   + itemIdx + '-' + pi);
    var aview   = document.getElementById('aview-'  + itemIdx + '-' + pi);
    var inp     = document.getElementById('loc-'    + itemIdx + '-' + pi);

    if (!panel) return;

    
    card.classList.remove('is-set', 'is-empty');
    card.classList.add('is-open');
    if (abtn)  abtn.style.display  = 'none';
    if (aview) aview.style.display = 'none';
    panel.style.display = 'block';

    
    if (inp) {
        inp.value = assignments[itemIdx][pi] || '';
        setTimeout(function(){ inp.focus(); }, 80);
    }

    openPanel = { itemIdx: itemIdx, pi: pi };

    
    loadSuggestions(itemIdx, pi);
}

function closeAssignPanel(itemIdx, pi) {
    var panel = document.getElementById('apanel-' + itemIdx + '-' + pi);
    var drop  = document.getElementById('drop-'   + itemIdx + '-' + pi);
    if (panel) panel.style.display = 'none';
    if (drop)  drop.style.display  = 'none';
    if (openPanel && openPanel.itemIdx === itemIdx && openPanel.pi === pi) {
        openPanel = null;
    }
    refreshCard(itemIdx, pi);
}

function loadSuggestions(itemIdx, pi) {
    var suggEl = document.getElementById('asugg-' + itemIdx + '-' + pi);
    if (!suggEl) return;

    var item = IMPORT_ITEMS[itemIdx];

    
    if (suggCache[itemIdx]) {
        renderSuggestions(itemIdx, pi, suggCache[itemIdx]);
        return;
    }

    
    suggEl.innerHTML = '<span class="text-xs text-gray-400 italic"><i class="fas fa-spinner fa-spin mr-1"></i>Memuat saran lokasi…</span>';

    var exclude = getUsedCodes(itemIdx);

    fetch('import_inbound.php?action=get_locations'
        + '&qty='            + item.actual_qty
        + '&uom='            + encodeURIComponent(item.uom)
        + '&uom_per_pallet=' + item.uom_per_pallet
        + '&exclude='        + encodeURIComponent(JSON.stringify(exclude)))
    .then(function(r){ return r.json(); })
    .then(function(data) {
        suggCache[itemIdx] = data;
        renderSuggestions(itemIdx, pi, data);
    })
    .catch(function() {
        if (suggEl) suggEl.innerHTML = '<span class="text-xs text-red-400">Gagal memuat saran.</span>';
    });
}

function renderSuggestions(itemIdx, pi, data) {
    var suggEl = document.getElementById('asugg-' + itemIdx + '-' + pi);
    if (!suggEl) return;

    var palletSeq = pi + 1; 

    
    var rec = null;
    if (data.suggested) {
        for (var i = 0; i < data.suggested.length; i++) {
            if (data.suggested[i].pallet_seq === palletSeq) {
                rec = data.suggested[i];
                break;
            }
        }
    }

    var html = '';

    
    if (rec && rec.location_code) {
        var isBE = ['B','C','D','E'].indexOf(rec.row_name) !== -1;
        html += '<div class="mb-1">'
             + '<span class="text-xs text-green-600 font-semibold mr-1"><i class="fas fa-star"></i> Rekomendasi:</span>'
             + makeSuggChip(itemIdx, pi, rec.location_code, rec.row_name, true)
             + '</div>';
    }

    
    var extras = (data.extras || []).filter(function(l) {
        return !rec || l.location_code !== rec.location_code;
    }).slice(0, 6);

    if (extras.length) {
        html += '<div><span class="text-xs text-gray-400 font-semibold mr-1">Lainnya:</span>';
        extras.forEach(function(l) {
            html += makeSuggChip(itemIdx, pi, l.location_code, l.row_name, false);
        });
        html += '</div>';
    }

    if (!html) {
        html = '<span class="text-xs text-gray-400 italic">Tidak ada lokasi tersedia.</span>';
    }

    suggEl.innerHTML = html;
}

function makeSuggChip(itemIdx, pi, code, rowName, isRec) {
    var isBE = ['B','C','D','E'].indexOf(rowName) !== -1;
    var cls  = isRec ? 'chip-rec' : (isBE ? 'chip-be' : 'chip-a');
    var tag  = isBE
        ? '<span class="lvl-tag tag-BE" style="font-size:.6rem;padding:1px 4px;border-radius:3px;margin-left:3px">B-E</span>'
        : '<span class="lvl-tag tag-A"  style="font-size:.6rem;padding:1px 4px;border-radius:3px;margin-left:3px">A</span>';
    return '<span class="sugg-chip ' + cls + '" '
         + 'onclick="pickLocation(' + itemIdx + ',' + pi + ',\'' + code + '\')">'
         + code + tag
         + '</span>';
}

function pickLocation(itemIdx, pi, code) {
    setAssignment(itemIdx, pi, code);
    closeAssignPanel(itemIdx, pi);
    updateCounters();
}

function clearPallet(itemIdx, pi) {
    setAssignment(itemIdx, pi, '');
    updateCounters();
}

function suggestItem(itemIdx) {
    
    delete suggCache[itemIdx];

    var item    = IMPORT_ITEMS[itemIdx];
    var exclude = getUsedCodes(itemIdx);

    fetch('import_inbound.php?action=get_locations'
        + '&qty='            + item.actual_qty
        + '&uom='            + encodeURIComponent(item.uom)
        + '&uom_per_pallet=' + item.uom_per_pallet
        + '&exclude='        + encodeURIComponent(JSON.stringify(exclude)))
    .then(function(r){ return r.json(); })
    .then(function(data) {
        suggCache[itemIdx] = data;
        data.suggested.forEach(function(s) {
            var piIdx = s.pallet_seq - 1;
            setAssignment(itemIdx, piIdx, s.location_code);
        });
        updateCounters();
    });
}

function suggestAll() {
    
    suggCache = {};
    var usedGlobal = [];
    function next(idx) {
        if (idx >= IMPORT_ITEMS.length) { updateCounters(); return; }
        var item = IMPORT_ITEMS[idx];
        fetch('import_inbound.php?action=get_locations'
            + '&qty='            + item.actual_qty
            + '&uom='            + encodeURIComponent(item.uom)
            + '&uom_per_pallet=' + item.uom_per_pallet
            + '&exclude='        + encodeURIComponent(JSON.stringify(usedGlobal)))
        .then(function(r){ return r.json(); })
        .then(function(data) {
            suggCache[idx] = data;
            data.suggested.forEach(function(s) {
                var piIdx = s.pallet_seq - 1;
                setAssignment(idx, piIdx, s.location_code);
                if (s.location_code && usedGlobal.indexOf(s.location_code) === -1) {
                    usedGlobal.push(s.location_code);
                }
            });
            next(idx + 1);
        });
    }
    next(0);
}

function clearItem(itemIdx) {
    for (var pi in assignments[itemIdx]) { setAssignment(itemIdx, parseInt(pi), ''); }
    delete suggCache[itemIdx];
    updateCounters();
}
function clearAll() {
    for (var ii in assignments) { clearItem(parseInt(ii)); }
    suggCache = {};
}

function setAssignment(itemIdx, pi, code) {
    assignments[itemIdx][pi] = code;

    
    var inp = document.getElementById('loc-' + itemIdx + '-' + pi);
    if (inp && inp.value !== code) inp.value = code;

    refreshCard(itemIdx, pi);
}

function onLocInput(inp, itemIdx, pi) {
    var val = inp.value.toUpperCase();
    inp.value = val;
    assignments[itemIdx][pi] = val;
    
    showDropdown(inp, itemIdx, pi, val);
}

function onManualBlur(itemIdx, pi) {
    
    setTimeout(function() {
        var drop = document.getElementById('drop-' + itemIdx + '-' + pi);
        if (drop && drop.style.display === 'block') return; 
        var inp = document.getElementById('loc-' + itemIdx + '-' + pi);
        if (inp) {
            var val = inp.value.trim().toUpperCase();
            if (val) {
                setAssignment(itemIdx, pi, val);
                closeAssignPanel(itemIdx, pi);
                updateCounters();
            }
        }
        hideDropdown(itemIdx, pi);
    }, 200);
}

function onLocKey(e, itemIdx, pi) {
    var drop  = document.getElementById('drop-' + itemIdx + '-' + pi);
    var opts  = drop.querySelectorAll('.loc-opt');
    var aIdx  = -1;
    opts.forEach(function(o, i) { if (o.classList.contains('active')) aIdx = i; });

    if (e.key === 'ArrowDown') {
        e.preventDefault();
        if (aIdx >= 0) opts[aIdx].classList.remove('active');
        var ni = Math.min(aIdx + 1, opts.length - 1);
        if (opts[ni]) opts[ni].classList.add('active');
    } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        if (aIdx >= 0) opts[aIdx].classList.remove('active');
        var ni = Math.max(aIdx - 1, 0);
        if (opts[ni]) opts[ni].classList.add('active');
    } else if (e.key === 'Enter') {
        e.preventDefault();
        var active = drop.querySelector('.loc-opt.active');
        if (active) {
            selectLoc(itemIdx, pi, active.dataset.code);
        } else {
            
            var inp = document.getElementById('loc-' + itemIdx + '-' + pi);
            if (inp && inp.value.trim()) {
                selectLoc(itemIdx, pi, inp.value.trim().toUpperCase());
            }
        }
    } else if (e.key === 'Escape') {
        drop.style.display = 'none';
        closeAssignPanel(itemIdx, pi);
    }
}

function showDropdown(inp, itemIdx, pi, val) {
    var drop = document.getElementById('drop-' + itemIdx + '-' + pi);
    if (!val || val.length < 1) { drop.style.display = 'none'; return; }

    var matches = ALL_LOCS.filter(function(l) {
        return l.location_code.indexOf(val) !== -1;
    }).slice(0, 10);

    if (!matches.length) { drop.style.display = 'none'; return; }

    drop.innerHTML = matches.map(function(l) {
        var isBE  = ['B','C','D','E'].indexOf(l.row_name) !== -1;
        var tag   = isBE
            ? '<span class="lvl-tag tag-BE">B-E</span>'
            : '<span class="lvl-tag tag-A">A</span>';
        return '<div class="loc-opt" data-code="' + l.location_code + '"'
             + ' onmousedown="event.preventDefault();selectLoc(' + itemIdx + ',' + pi + ',\'' + l.location_code + '\')">'
             + '<span>' + l.location_code + tag + '</span>'
             + '</div>';
    }).join('');
    drop.style.display = 'block';
}

function hideDropdown(itemIdx, pi) {
    setTimeout(function() {
        var d = document.getElementById('drop-' + itemIdx + '-' + pi);
        if (d) d.style.display = 'none';
    }, 180);
}

function selectLoc(itemIdx, pi, code) {
    var drop = document.getElementById('drop-' + itemIdx + '-' + pi);
    if (drop) drop.style.display = 'none';
    setAssignment(itemIdx, pi, code);
    closeAssignPanel(itemIdx, pi);
    updateCounters();
}

function refreshCard(itemIdx, pi) {
    var card  = document.getElementById('pcard-'  + itemIdx + '-' + pi);
    var badge = document.getElementById('badge-'  + itemIdx + '-' + pi);
    var abtn  = document.getElementById('abtn-'   + itemIdx + '-' + pi);
    var aview = document.getElementById('aview-'  + itemIdx + '-' + pi);
    var acode = document.getElementById('acode-'  + itemIdx + '-' + pi);
    var panel = document.getElementById('apanel-' + itemIdx + '-' + pi);

    if (!card) return;

    var val         = assignments[itemIdx][pi];
    var isPanelOpen = panel && panel.style.display === 'block';

    if (isPanelOpen) {
        
        card.classList.remove('is-set', 'is-empty');
        card.classList.add('is-open');
        if (abtn)  abtn.style.display  = 'none';
        if (aview) aview.style.display = 'none';
    } else if (val) {
        
        card.classList.remove('is-empty', 'is-open');
        card.classList.add('is-set');
        if (abtn)  abtn.style.display  = 'none';
        if (aview) aview.style.display = 'flex';
        if (acode) acode.textContent   = val;
    } else {
        
        card.classList.remove('is-set', 'is-open');
        card.classList.add('is-empty');
        if (abtn)  abtn.style.display  = '';
        if (aview) aview.style.display = 'none';
    }

    
    if (badge) {
        badge.innerHTML = val
            ? '<span style="color:#026766;font-weight:700">✓</span>'
            : '<span style="color:#9ca3af;font-weight:700">—</span>';
    }
}

function updateCounters() {
    var total = 0, assigned = 0;
    for (var ii in assignments) {
        for (var pi in assignments[ii]) {
            total++;
            if (assignments[ii][pi]) assigned++;
        }
    }
    var missing = total - assigned;

    var e1  = document.getElementById('cntAssigned');
    var e2  = document.getElementById('cntMissing');
    var st  = document.getElementById('importStatus');
    var btn = document.getElementById('btnImport');

    if (e1) e1.textContent = assigned + ' pallet ter-assign ✓';
    if (e2) e2.textContent = missing > 0 ? missing + ' pallet belum' : '';

    var ready = (missing === 0 && total > 0);
    if (btn) { btn.disabled = !ready; btn.style.opacity = ready ? '1' : '.4'; btn.style.cursor = ready ? 'pointer' : 'not-allowed'; }

    if (st) {
        if (ready) {
            st.innerHTML = '<span style="color:#026766;font-weight:600"><i class="fas fa-check-circle mr-1"></i>Semua ' + total + ' pallet ter-assign. Siap import!</span>';
        } else if (missing > 0) {
            st.innerHTML = '<span style="color:#d97706"><i class="fas fa-exclamation-triangle mr-1"></i>' + missing + ' pallet belum punya lokasi.</span>';
        }
    }

    
    for (var ii2 in assignments) {
        for (var pi2 in assignments[ii2]) {
            refreshCard(parseInt(ii2), parseInt(pi2));
        }
    }
}

function getUsedCodes(skipItemIdx) {
    var used = [];
    for (var ii in assignments) {
        if (parseInt(ii) === skipItemIdx) continue;
        for (var pi in assignments[ii]) {
            var c = assignments[ii][pi];
            if (c && used.indexOf(c) === -1) used.push(c);
        }
    }
    return used;
}

function doImport() {
    var finalItems = JSON.parse(JSON.stringify(IMPORT_ITEMS));
    finalItems.forEach(function(item, ii) {
        var locs = [];
        item.pallet_dist.forEach(function(p, pi) {
            locs.push({
                pallet_seq:    p.pallet_seq,
                location_code: assignments[ii][pi] || 'STAGING',
                quantity:      p.quantity,
                is_full:       p.is_full,
                uom:           item.uom,
            });
        });
        item.pallet_locations = locs;
        
        
        var mfgEl = document.getElementById('mfg-' + ii);
        var expEl = document.getElementById('exp-' + ii);
        
        
        
        
        if (mfgEl) {
            var mfgVal = (mfgEl.value || '').trim();
            item.manufacture_date = mfgVal || item.manufacture_date;
        }
        if (expEl) {
            var expVal = (expEl.value || '').trim();
            item.exp_date = expVal || item.exp_date;
        }
        
    });

    var carrierInp = document.getElementById('carrierNameStep2');
    var finalCarrierName = carrierInp ? carrierInp.value.trim() : '';
    document.getElementById('fCarrierName').value = finalCarrierName;
    document.getElementById('fItemsJson').value  = JSON.stringify(finalItems);
    document.getElementById('importForm').submit();
}
</script>
<?php endif; ?>


</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>

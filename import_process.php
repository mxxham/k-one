<?php
ob_start();
error_reporting(0);
ini_set('display_errors', '0');

session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/classes/Auth.php';
require_once __DIR__ . '/classes/Inbound.php';
require_once __DIR__ . '/classes/Product.php';
try {
    require_once __DIR__ . '/vendor/autoload.php';
} catch (\Exception $autoloadErr) {
    ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $autoloadErr->getMessage()]);
    exit;
}

use PhpOffice\PhpSpreadsheet\IOFactory;

Auth::requireAuth();
if (!Auth::canWrite()) {
    ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Akses ditolak. Role Anda tidak memiliki izin untuk import data.']);
    exit;
}

ob_end_clean();
header('Content-Type: application/json');

try {
    
    $fileKey = isset($_FILES['excel_file']) ? 'excel_file' : 'csv_file';

    if (!isset($_FILES[$fileKey]) || $_FILES[$fileKey]['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('No file uploaded or upload error');
    }

    $file = $_FILES[$fileKey];
    $fileExt = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    if (!in_array($fileExt, ['xlsx', 'xls', 'csv'])) {
        throw new Exception('Please upload an Excel (.xlsx/.xls) or CSV file');
    }

    
    $spreadsheet = IOFactory::load($file['tmp_name']);
    $sheet = $spreadsheet->getActiveSheet();
    
    
    $allRows = $sheet->toArray(null, true, false, false);

    if (empty($allRows)) {
        throw new Exception('File is empty');
    }

    
    
    $headers = array_map(fn($h) => ltrim(strtolower(trim((string)$h)), '* '), array_shift($allRows));

    
    $col = array_flip($headers);

    
    $getAlt = function($row, ...$keys) use (&$col) {
        foreach ($keys as $key) {
            if (isset($col[$key])) {
                $v = trim((string)($row[$col[$key]] ?? ''));
                if ($v !== '') return $v;
            }
        }
        return '';
    };
    $get = fn($row, $key) => isset($col[$key]) ? trim((string)($row[$col[$key]] ?? '')) : '';

    
    $requiredHeaders = ['item code', 'actual qty', 'uom'];
    $missingHeaders = array_filter($requiredHeaders, fn($h) => !isset($col[$h]));
    if (!empty($missingHeaders)) {
        throw new Exception('Invalid format. Missing columns: ' . implode(', ', $missingHeaders));
    }

    $db = db();
    $db->beginTransaction();

    $successCount = 0;
    $errorMessages = [];
    $rowNumber = 1;

    foreach ($allRows as $data) {
        $rowNumber++;
        if (empty(array_filter($data))) continue;

        try {
            $month          = $getAlt($data, 'month', 'bulan');
            $odNumber       = $getAlt($data, 'od no', 'od number', 'od_no', 'outbound delivery');
            $soNumber       = $getAlt($data, 'so no', 'so number', 'so_no', 'sales order');
            $poNumber       = $getAlt($data, 'po no', 'po number', 'po_no', 'gr number'); 
            $shipmentNo     = $getAlt($data, 'shipment no', 'shipment number', 'shipment_no', 'shipment');
            $itemCode       = $getAlt($data, 'item code', 'material', 'product code', 'sku');
            $inboundOrderNo = $getAlt($data, 'inbound order no', 'inbound order number', 'inbound_order_no', 'io number');
            $qtyOrder       = floatval($getAlt($data, 'qty order', 'order qty'));
            $uom            = $getAlt($data, 'uom', 'unit', 'sales unit') ?: 'Drum';
            $actualQty      = floatval($getAlt($data, 'actual qty', 'actual_qty', 'received qty', 'quantity'));
            $pallet         = floatval($getAlt($data, 'pallet', 'pallet qty'));
            $batchNo        = $getAlt($data, 'batch no', 'batch number', 'batch', 'lot');
            $manufactureDate= $getAlt($data, 'manufacture date', 'production date', 'mfg date', 'manufacturing date', 'tgl produksi', 'tanggal produksi', 'mfgdate', 'tgl mfg', 'manufacture');
            $expDate        = $getAlt($data, 'exp date', 'expiry date', 'expiration date', 'best before', 'tgl exp', 'tanggal exp', 'kadaluarsa', 'kadaluwarsa', 'tgl kadaluarsa', 'tgl kadaluwarsa', 'expired date', 'expiry', 'expiration');
            $location       = $getAlt($data, 'location', 'lokasi', 'bin');
            $remarks        = $getAlt($data, 'remarks', 'notes', 'keterangan');

            
            $firstCell = trim((string)($data[0] ?? ''));
            if (str_starts_with($firstCell, '*') || str_starts_with($itemCode, '*') ||
                stripos($firstCell, 'kolom') !== false || stripos($firstCell, 'values') !== false ||
                stripos($firstCell, 'format') !== false ||
                (stripos($firstCell, 'pallet') !== false && stripos($firstCell, 'jumlah') !== false) ||
                (stripos($firstCell, 'uom values') !== false) ||
                (stripos($firstCell, 'date format') !== false) ||
                (stripos($firstCell, 'item code') !== false && stripos($firstCell, 'harus') !== false) ||
                (stripos($firstCell, 'inbound order') !== false && stripos($firstCell, 'opsional') !== false)) {
                continue;
            }
            
            if (empty($itemCode)) {
                continue;
            }
            if ($actualQty <= 0) {
                $errorMessages[] = "Row {$rowNumber}: Dilewati — Actual Qty kosong atau 0";
                continue;
            }

            
            $stmt = $db->prepare("SELECT id, product_name, uom_type, uom_per_pallet, max_sku_qty, max_trans_qty, liters_per_unit FROM products WHERE product_code = ?");
            $stmt->execute([$itemCode]);
            $product = $stmt->fetch();
            if (!$product) {
                throw new Exception("Row {$rowNumber}: Product not found: {$itemCode}");
            }


            static $carrierName = null;
            if ($carrierName === null) {
                $carrierName = trim($_POST['carrier_name'] ?? '') ?: null;
            }


            if ($shipmentNo !== '') {
                $orderNumber = $shipmentNo;
            } elseif ($inboundOrderNo !== '') {
                $orderNumber = $inboundOrderNo;
            } else {
                $orderNumber = Inbound::generateNumber();
            }

            $stmt = $db->prepare("SELECT id FROM inbound_orders WHERE order_number = ? OR (shipment_no IS NOT NULL AND shipment_no <> '' AND shipment_no = ?) LIMIT 1");
            $stmt->execute([$orderNumber, $shipmentNo !== '' ? $shipmentNo : $orderNumber]);
            $existing = $stmt->fetch();

            if (!$existing) {
                $shipVal = $shipmentNo !== '' ? $shipmentNo : null;
                $ins = $db->prepare("INSERT INTO inbound_orders (order_number, order_date, carrier_name, status, shipment_no, notes, created_by) VALUES (?, CURDATE(), ?, 'Dues In', ?, 'Imported from Excel', ?)");
                $ins->execute([$orderNumber, $carrierName, $shipVal, $_SESSION['user_id']]);
                $inboundId = $db->lastInsertId();
            } else {
                $inboundId = $existing['id'];
            }

            
            
            $uomPerPallet = match(strtolower($uom)) {
                'drum'   => 4,
                'carton' => $product['uom_per_pallet'] ?? 44, 
                'pail'   => 24,
                'ea'     => 4,
                'bags'   => 1,
                default  => $product['uom_per_pallet'] ?? 4,
            };
            $calculatedPallet = $actualQty > 0 ? ceil($actualQty / $uomPerPallet) : 0;
            $finalPallet = $pallet > 0 ? $pallet : $calculatedPallet;

            if ($pallet > 0 && abs($pallet - $calculatedPallet) > 1) {
                $errorMessages[] = "Row {$rowNumber}: Pallet mismatch (calc: {$calculatedPallet}, given: {$pallet})";
            }

            
            
            $parseDateVal = function($val) {
                if ($val === null || $val === '' || $val === '0') return null;
                
                if (is_numeric($val) && (float)$val > 40000) {
                    try {
                        $dt = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float)$val);
                        $y = (int)$dt->format('Y');
                        if ($y < 1990 || $y > 2100) return null;
                        return $dt->format('Y-m-d');
                    } catch (\Exception $e) {}
                }
                
                $val = trim((string)$val);
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
                
                $ts = strtotime($val);
                if ($ts && $ts > 0) {
                    $y = (int)date('Y', $ts);
                    if ($y >= 1990 && $y <= 2100) return date('Y-m-d', $ts);
                }
                return null;
            };

            
            $parsedMfgDate = $parseDateVal($manufactureDate);
            $parsedExpDate = $parseDateVal($expDate);

            
            if (empty($parsedExpDate) && !empty($parsedMfgDate)) {
                try {
                    $d = new DateTime($parsedMfgDate);
                    $d->modify('+4 years');
                    $parsedExpDate = $d->format('Y-m-d');
                } catch (\Exception $e) {}
            }

            
            static $batchColImport = null;
            if ($batchColImport === null) {
                $chk = $db->query("SHOW COLUMNS FROM inbound_items LIKE 'batch_number'");
                $batchColImport = ($chk->rowCount() > 0) ? 'batch_number' : 'batch_no';
            }

            
            static $hasInProcessCol = null;
            if ($hasInProcessCol === null) {
                $chk2 = $db->query("SHOW COLUMNS FROM inbound_items LIKE 'in_process_status'");
                $hasInProcessCol = ($chk2->rowCount() > 0);
            }

            if ($hasInProcessCol) {
                $stmt = $db->prepare("INSERT INTO inbound_items (inbound_order_id, product_id, od_number, so_number, {$batchColImport}, location, quantity, uom, actual_qty, pallet, manufacture_date, exp_date, stock_status, in_process_status, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending', 'Dues In', ?)");
            } else {
                $stmt = $db->prepare("INSERT INTO inbound_items (inbound_order_id, product_id, od_number, so_number, {$batchColImport}, location, quantity, uom, actual_qty, pallet, manufacture_date, exp_date, stock_status, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending', ?)");
            }
            $stmt->execute([
                $inboundId,
                $product['id'],
                $odNumber ?: null,
                $soNumber ?: null,
                $batchNo ?: null,
                $location ?: null,
                $qtyOrder > 0 ? $qtyOrder : $actualQty,
                $uom,
                $actualQty,
                $finalPallet,
                $parsedMfgDate,
                $parsedExpDate,
                $remarks ?: null,
            ]);

            $successCount++;

        } catch (Exception $e) {
            $errorMessages[] = $e->getMessage();
        }
    }

    $db->commit();

    echo json_encode([
        'success'    => true,
        'message'    => "Import completed! {$successCount} items imported successfully.",
        'processed'  => $successCount,
        'errors'     => $errorMessages,
        'has_errors' => count($errorMessages) > 0,
    ]);

} catch (\Throwable $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

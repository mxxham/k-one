<?php

error_reporting(0);
@ini_set('display_errors', 0);
ob_start();

session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/classes/Auth.php';
require_once __DIR__ . '/classes/Outbound.php';
require_once __DIR__ . '/classes/Customer.php';
try {
    require_once __DIR__ . '/vendor/autoload.php';
} catch (\Exception $autoloadErr) {
    ob_clean();
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $autoloadErr->getMessage(), 'stats' => ['orders_created'=>0,'items_imported'=>0,'rows_skipped'=>0,'errors'=>1], 'log' => []]);
    exit;
}

use PhpOffice\PhpSpreadsheet\IOFactory;

if (!Auth::check()) {
    ob_clean();
    header('Content-Type: application/json');
    echo json_encode(['success'=>false,'message'=>'Session expired. Please login.','stats'=>[],'log'=>[]]);
    exit;
}
if (!Auth::canWrite()) {
    ob_clean();
    header('Content-Type: application/json');
    echo json_encode(['success'=>false,'message'=>'Akses ditolak. Role Anda tidak memiliki izin untuk import data.','stats'=>[],'log'=>[]]);
    exit;
}

ob_clean();
header('Content-Type: application/json');

try {
    if (!isset($_FILES['excel_file']) || $_FILES['excel_file']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('File tidak diupload atau error upload. Kode: '.($_FILES['excel_file']['error']??'N/A'));
    }

    $file = $_FILES['excel_file'];
    $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['xlsx','xls'])) throw new Exception('Format file harus .xlsx atau .xls');

    $skipUnknown     = !empty($_POST['skip_unknown']);
    $groupByShipment = !empty($_POST['group_by_shipment']);

    $spreadsheet = IOFactory::load($file['tmp_name']);

    
    $sheet = null;
    $sheetKeywords = ['schedule','plan','outbound','delivery','maret','april','mei','juni',
                      'juli','agustus','september','oktober','november','desember','januari','februari'];
    foreach ($spreadsheet->getSheetNames() as $sName) {
        $sLower = strtolower($sName);
        foreach ($sheetKeywords as $kw) {
            if (str_contains($sLower, $kw)) { $sheet = $spreadsheet->getSheetByName($sName); break; }
        }
        if ($sheet) break;
    }
    if (!$sheet) $sheet = $spreadsheet->getActiveSheet();

    $allRows = $sheet->toArray(null, true, true, false);
    if (empty($allRows)) throw new Exception('File kosong atau tidak bisa dibaca');

    
    $headerRowIndex = null;
    $headers = [];
    $headerKeywords = ['shipment','material','delivery quantity','ship-to','order no','plan date',
                       'destination','location of the ship','name of ship','street'];
    foreach ($allRows as $idx => $row) {
        $rowStr = strtolower(implode(' ', array_map(fn($v) => (string)($v ?? ''), $row)));
        $matches = 0;
        foreach ($headerKeywords as $kw) { if (str_contains($rowStr, $kw)) $matches++; }
        if ($matches >= 2) {
            $headerRowIndex = $idx;
            $headers = array_map(fn($h) => strtolower(trim(ltrim(trim((string)($h??'')), '* '))), $row);
            break;
        }
    }
    if ($headerRowIndex === null) throw new Exception('Header row tidak ditemukan. Pastikan file menggunakan format Planning Outbound.');

    $col = array_flip($headers);

    
    $getAlt = function(array $row, ...$keys) use ($col): string {
        foreach ($keys as $key) {
            $key = strtolower(trim($key));
            if (isset($col[$key])) {
                $v = trim((string)($row[$col[$key]] ?? ''));
                if ($v !== '' && $v !== '0') return $v;
            }
        }
        return '';
    };

    
    $parseDate = function($val): ?string {
        if (empty($val) || $val === '0') return null;
        if (is_numeric($val) && $val > 40000) {
            $dt = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float)$val);
            return $dt->format('Y-m-d');
        }
        $val = trim((string)$val);
        
        if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $val, $m)) return $m[1];
        if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $val, $m)) return "{$m[3]}-{$m[2]}-{$m[1]}";
        try { return (new DateTime($val))->format('Y-m-d'); } catch(Exception $e) { return null; }
    };

    
    $normalizeUom = function(string $raw, string $fallback = 'Drum'): string {
        $map = ['car'=>'Carton','ctn'=>'Carton','carton'=>'Carton',
                'drm'=>'Drum','drum'=>'Drum',
                'pail'=>'Pail','pal'=>'Pail',
                'bag'=>'Bags','bags'=>'Bags',
                'ea'=>'EA','each'=>'EA','pcs'=>'EA'];
        $k = strtolower(trim($raw));
        return $map[$k] ?? ($k ? ucfirst($k) : $fallback);
    };

    
    $resolveUpp = function(string $uom, int $productUpp = 4): int {
        switch(strtolower(trim($uom))) {
            case 'drum':               return 4;
            case 'carton': case 'car': return max(1, $productUpp ?: 44);
            case 'pail':               return 24;
            case 'bags':               return 1;
            default:                   return max(1, $productUpp ?: 4);
        }
    };

    $db = db();

    
    $productCache = [];
    foreach ($db->query("SELECT id, product_code, product_name, uom_type, uom_per_pallet FROM products WHERE is_active=1")->fetchAll() as $p) {
        $productCache[strtolower($p['product_code'])] = $p;
        
        if (preg_match('/(\d{7,})/', $p['product_code'], $m)) {
            $productCache[$m[1]] = $p;
        }
    }

    
    $customerCache = [];
    foreach ($db->query("SELECT id, customer_code, customer_name FROM customers")->fetchAll() as $c) {
        $customerCache[strtolower(trim($c['customer_code']))] = $c;
        $customerCache[strtolower(trim($c['customer_name']))] = $c;
    }

    
    
    
    
    
    
    $shipmentData = [];
    $dataRows = array_slice($allRows, $headerRowIndex + 1);

    foreach ($dataRows as $rIdx => $row) {
        if (empty(array_filter($row, fn($v) => $v !== null && $v !== ''))) continue;

        $shipmentNum = $getAlt($row, 'shipment number', 'shipment no', 'shipment');
        $materialRaw = $getAlt($row, 'material');
        $deliveryQty = floatval($getAlt($row, 'delivery quantity'));

        if (empty($materialRaw) || $deliveryQty <= 0) continue;
        if (empty($shipmentNum)) $shipmentNum = 'NO_SHIPMENT_'.($rIdx+1);

        if (!isset($shipmentData[$shipmentNum])) {
            $shipmentData[$shipmentNum] = ['rows' => [], 'plan_date' => null, 'customer_id' => null];
        }
        $planDate = $parseDate($getAlt($row, 'plan date', 'first delivery date', 'goods issue date'));
        if ($planDate && !$shipmentData[$shipmentNum]['plan_date']) {
            $shipmentData[$shipmentNum]['plan_date'] = $planDate;
        }
        $shipmentData[$shipmentNum]['rows'][] = $row;
    }

    $ordersCreated = 0;
    $itemsImported = 0;
    $rowsSkipped   = 0;
    $log           = [];

    $db->beginTransaction();
    try {
        foreach ($shipmentData as $shipmentNum => $sData) {
            $rows      = $sData['rows'];
            $planDate  = $sData['plan_date'] ?? date('Y-m-d');

            
            $firstRow    = $rows[0];
            $shipToParty = $getAlt($firstRow, 'ship-to party', 'ship-to party code');
            $shipToName  = $getAlt($firstRow, 'name of ship-to party', 'name of the ship-to party', 'destination');
            $shipToLoc   = $getAlt($firstRow, 'location of ship-to party', 'location of the ship-to party');
            $customerId  = null;

            foreach ([strtolower($shipToParty), strtolower($shipToName)] as $ck) {
                if (!empty($ck) && isset($customerCache[$ck])) {
                    $customerId = $customerCache[$ck]['id']; break;
                }
            }
            if (!$customerId && !empty($shipToName)) {
                $code = 'OUT-' . strtoupper(substr(preg_replace('/[^A-Z0-9]/', '', strtoupper($shipToName)), 0, 8));
                try {
                    $stmt = $db->prepare("INSERT INTO customers (customer_code, customer_name, city) VALUES (?,?,?) ON DUPLICATE KEY UPDATE customer_name=customer_name");
                    $stmt->execute([$code, $shipToName, $shipToLoc ?: null]);
                    $customerId = $db->lastInsertId() ?: null;
                    if (!$customerId) {
                        $customerId = $db->query("SELECT id FROM customers WHERE customer_code=".db()->quote($code))->fetchColumn();
                    }
                    $customerCache[strtolower($shipToName)] = ['id' => $customerId];
                    $log[] = "➕ Customer: {$shipToName}";
                } catch (\PDOException $e) {  }
            }
            if (!$customerId) {
                $defCust = $db->query("SELECT id FROM customers LIMIT 1")->fetch();
                $customerId = $defCust ? $defCust['id'] : null;
                if (!$customerId) throw new Exception("Tidak ada customer di database. Tambahkan customer terlebih dahulu.");
            }

            
            $outboundData = [
                'order_date'       => $planDate,
                'customer_id'      => $customerId,
                'shipment_number'  => str_starts_with($shipmentNum, 'NO_SHIPMENT') ? null : $shipmentNum,
                'ship_to_name'     => $shipToName ?: null,
                'ship_to_location' => $shipToLoc ?: null,
                'kota'             => $shipToLoc ?: null,
                'expected_date'    => $planDate,
                'status'           => 'Open',
                'notes'            => 'Imported from Excel | Shipment: ' . (str_starts_with($shipmentNum, 'NO_SHIPMENT') ? '—' : $shipmentNum),
            ];
            $outboundId = Outbound::create($outboundData);
            $ordersCreated++;
            $log[] = "✅ Order #{$outboundId} — Shipment: {$shipmentNum} ({$shipToName}, ".count($rows)." items)";

            
            
            
            
            
            $primaryDestKey = strtolower(trim($shipToName.'|'.$shipToLoc));
            $destMap        = []; 
            $destCustomerMap = [$primaryDestKey => $customerId]; 
            $destSeq = 2;  

            foreach ($rows as $row) {
                $dName   = $getAlt($row, 'name of ship-to party', 'name of the ship-to party', 'destination');
                $dLoc    = $getAlt($row, 'location of ship-to party', 'location of the ship-to party');
                $dStreet = $getAlt($row, 'street / address', 'street', 'address');
                $dShipParty = $getAlt($row, 'ship-to party', 'ship-to party code');
                $dKey    = strtolower(trim($dName.'|'.$dLoc));

                
                if (empty($dName) || $dKey === $primaryDestKey) continue;

                if (!isset($destMap[$dKey])) {
                    
                    try {
                        $db->prepare("INSERT INTO outbound_destinations
                                (outbound_id, seq, ship_to_name, ship_to_location, kota, street_address, notes)
                                VALUES (?, ?, ?, ?, ?, ?, ?)")
                           ->execute([$outboundId, $destSeq++, $dName, $dLoc, $dLoc, $dStreet ?: null, null]);
                    } catch (\PDOException $eCol) {
                        $db->prepare("INSERT INTO outbound_destinations
                                (outbound_id, seq, ship_to_name, ship_to_location, kota, notes)
                                VALUES (?, ?, ?, ?, ?, ?)")
                           ->execute([$outboundId, $destSeq++, $dName, $dLoc, $dLoc, null]);
                    }
                    $destMap[$dKey] = $db->lastInsertId();
                }

                
                if (!isset($destCustomerMap[$dKey]) && !empty($dName)) {
                    $dCustId = null;
                    $dNameLower = strtolower($dName);
                    $dPartyLower = strtolower($dShipParty);
                    foreach ([$dPartyLower, $dNameLower] as $ck) {
                        if (!empty($ck) && isset($customerCache[$ck])) {
                            $dCustId = $customerCache[$ck]['id']; break;
                        }
                    }
                    if (!$dCustId) {
                        $dCode = 'OUT-' . strtoupper(substr(preg_replace('/[^A-Z0-9]/', '', strtoupper($dName)), 0, 8));
                        try {
                            $db->prepare("INSERT INTO customers (customer_code, customer_name, city) VALUES (?,?,?) ON DUPLICATE KEY UPDATE customer_name=customer_name")
                               ->execute([$dCode, $dName, $dLoc ?: null]);
                            $dCustId = $db->lastInsertId() ?: null;
                            if (!$dCustId) {
                                $dCustId = $db->query("SELECT id FROM customers WHERE customer_code=".$db->quote($dCode))->fetchColumn();
                            }
                            $customerCache[$dNameLower] = ['id' => $dCustId];
                            $log[] = "➕ Customer (dest): {$dName}";
                        } catch (\PDOException $e) {  }
                    }
                    $destCustomerMap[$dKey] = $dCustId;
                }
            }
            if (!empty($destMap)) {
                $log[] = "  📍 " . (1 + count($destMap)) . " tujuan: " . $shipToName . ', ' . implode(', ', array_map(fn($k) => explode('|', $k)[0], array_keys($destMap)));
            }

            
            foreach ($rows as $row) {
                $odNo        = $getAlt($row, 'order no (od)', 'order no', 'od no', 'od number');
                $soNo        = $getAlt($row, 'purchase order number', 'so no', 'so number');
                $destName    = $getAlt($row, 'name of ship-to party', 'name of the ship-to party', 'destination');
                $destLoc     = $getAlt($row, 'location of ship-to party', 'location of the ship-to party');
                $materialRaw = $getAlt($row, 'material');
                $description = $getAlt($row, 'description');
                $deliveryQty = floatval($getAlt($row, 'delivery quantity'));
                $salesUnit   = $getAlt($row, 'sales unit', 'uom', 'type');
                $goodsDate   = $parseDate($getAlt($row, 'goods issue date', 'first delivery date'));
                $mfgDate     = $parseDate($getAlt($row, 'manufacture date', 'mfg date', 'production date'));
                $expDate     = $parseDate($getAlt($row, 'exp date', 'expiry date', 'best before'));

                
                if (empty($expDate) && !empty($mfgDate)) {
                    try { $d = new DateTime($mfgDate); $d->modify('+4 years'); $expDate = $d->format('Y-m-d'); } catch(Exception $e) {}
                }

                if (empty($materialRaw) || $deliveryQty <= 0) { $rowsSkipped++; continue; }

                
                $matKey = strtolower(trim($materialRaw));
                $matNum = preg_replace('/[^0-9]/', '', $materialRaw);
                $product = $productCache[$matKey]
                    ?? $productCache[$matNum]
                    ?? $productCache[(string)(int)$materialRaw]
                    ?? null;

                
                if (!$product && !empty($description)) {
                    foreach ($productCache as $p) {
                        if (!is_array($p)) continue;
                        if (stripos($p['product_name'], substr($description, 0, 15)) !== false) {
                            $product = $p; break;
                        }
                    }
                }

                if (!$product) {
                    $log[] = "⚠️ Material '{$materialRaw}' tidak ditemukan di database → dilewati";
                    $rowsSkipped++;
                    continue;
                }

                $uom          = $normalizeUom($salesUnit, $product['uom_type']);
                $uomPerPallet = $resolveUpp($uom, (int)$product['uom_per_pallet']);
                $pallet       = (int)ceil($deliveryQty / $uomPerPallet);

                
                
                
                $dKey      = strtolower(trim($destName.'|'.$destLoc));
                $destId    = ($dKey === $primaryDestKey) ? null : ($destMap[$dKey] ?? null);
                $itemCustId = $destCustomerMap[$dKey] ?? $customerId;


                $fefo         = Outbound::getFEFOAllocation($product['id'], $deliveryQty);
                $firstBatch   = null;
                $firstLoc     = null;
                $firstExpDate = $expDate;

                // Skip items with no stock BEFORE inserting anything
                if ($fefo['total_available'] <= 0) {
                    $log[] = "  ⛔ SKIP (stok 0): {$product['product_name']} [{$materialRaw}] — {$deliveryQty} {$uom}";
                    $rowsSkipped++;
                    continue;
                }

                if (!empty($fefo['allocation'])) {
                    $firstBatch   = $fefo['allocation'][0]['batch_number'] ?? null;
                    $firstLoc     = $fefo['allocation'][0]['location']     ?? null;
                    $firstExpDate = $fefo['allocation'][0]['expiry_date']  ?? $expDate;
                }


                try {
                    $stmt = $db->prepare("INSERT INTO outbound_items
                            (outbound_order_id, product_id, quantity, actual_qty, uom, pallet,
                             batch_no, exp_date, location, notes, od_number, so_number,
                             destination_id, customer_id)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([
                        $outboundId, $product['id'], $deliveryQty, $deliveryQty,
                        $uom, $pallet, $firstBatch, $firstExpDate, $firstLoc,
                        $description ?: $product['product_name'],
                        $odNo ?: null, $soNo ?: null, $destId, $itemCustId,
                    ]);
                } catch (\PDOException $colErr) {

                    $stmt = $db->prepare("INSERT INTO outbound_items
                            (outbound_order_id, product_id, quantity, actual_qty, uom, pallet,
                             batch_no, exp_date, location, notes)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([
                        $outboundId, $product['id'], $deliveryQty, $deliveryQty,
                        $uom, $pallet, $firstBatch, $firstExpDate, $firstLoc,
                        $description ?: $product['product_name'],
                    ]);
                }
                $outboundItemId = $db->lastInsertId();


                if (!empty($fefo['allocation'])) {
                    $palletSeq = 1;
                    foreach ($fefo['allocation'] as $alloc) {
                        if (empty($alloc['location'])) continue;
                        try {
                            $slRow = $db->prepare("
                                SELECT sl.id FROM stock_locations sl
                                JOIN stock s ON sl.stock_id = s.id
                                WHERE s.product_id=? AND sl.location_code=?
                                  AND sl.status IN ('Available','Reserved')
                                ORDER BY sl.id ASC LIMIT 1");
                            $slRow->execute([$product['id'], $alloc['location']]);
                            $sl = $slRow->fetch();
                            if ($sl) {
                                $db->prepare("INSERT IGNORE INTO outbound_item_locations
                                        (outbound_item_id, stock_location_id, quantity)
                                        VALUES (?,?,?)")
                                   ->execute([$outboundItemId, $sl['id'], $alloc['required_qty'] ?? $alloc['quantity'] ?? 0]);
                                $palletSeq++;
                            }
                        } catch (\PDOException $e) {  }
                    }
                }

                $fefoInfo = $fefo['sufficient']
                    ? "FEFO OK ({$firstBatch}@{$firstLoc})"
                    : "⚠️ Stok kurang (tersedia {$fefo['total_available']}, diminta {$deliveryQty})";
                $destDisplay = $destName ? " → {$destName}" : '';
                $log[] = "  ├ OD:{$odNo} | {$product['product_name']} | {$deliveryQty} {$uom}{$destDisplay} | {$fefoInfo}";
                $itemsImported++;
            }

            // If the order was created but ended up with 0 valid items, roll back and delete it
            $orderItemCount = $db->prepare("SELECT COUNT(*) FROM outbound_items WHERE outbound_order_id=?");
            $orderItemCount->execute([$outboundId]);
            if ((int)$orderItemCount->fetchColumn() === 0) {
                $db->prepare("DELETE FROM outbound_orders WHERE id=?")->execute([$outboundId]);
                $ordersCreated--;
                $log[] = "❌ Order #{$outboundId} ({$shipmentNum}) dihapus — semua item tidak ada di stok";
            }
        }

        $db->commit();

    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }

    ob_clean();
    echo json_encode([
        'success' => true,
        'message' => "Import selesai: {$ordersCreated} orders, {$itemsImported} items dari ".count($shipmentData)." shipments",
        'stats'   => [
            'orders_created' => $ordersCreated,
            'items_imported' => $itemsImported,
            'rows_skipped'   => $rowsSkipped,
            'errors'         => 0,
        ],
        'log' => $log,
    ]);

} catch (\Throwable $e) {
    ob_clean();
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'stats'   => ['orders_created'=>0,'items_imported'=>0,'rows_skipped'=>0,'errors'=>1],
        'log'     => [],
    ]);
}

<?php
/** Outbound Excel import (action: import/outbound). */

function import_run_outbound(): void {
    require_once __DIR__ . '/../../classes/Outbound.php';
    import_raise_memory_limit();

    $skipUnknown     = !empty($_POST['skip_unknown']);
    $groupByShipment = !empty($_POST['group_by_shipment']);

    $file = $_FILES['excel_file'] ?? null;
    if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('File tidak diupload atau error upload.');
    }
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['xlsx', 'xls'])) throw new Exception('Format file harus .xlsx atau .xls');

    $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($file['tmp_name']);

    $sheet = null;
    $sheetKeywords = ['schedule', 'plan', 'outbound', 'delivery', 'maret', 'april', 'mei', 'juni',
                      'juli', 'agustus', 'september', 'oktober', 'november', 'desember', 'januari', 'februari'];
    foreach ($spreadsheet->getSheetNames() as $sName) {
        $sLower = strtolower($sName);
        foreach ($sheetKeywords as $kw) {
            if (str_contains($sLower, $kw)) { $sheet = $spreadsheet->getSheetByName($sName); break 2; }
        }
    }
    if (!$sheet) $sheet = $spreadsheet->getActiveSheet();

    $allRows = $sheet->toArray(null, true, true, false);
    if (empty($allRows)) throw new Exception('File kosong atau tidak bisa dibaca');

    $result = import_outbound_process_sheet($allRows, $skipUnknown, $groupByShipment);
    json_out($result);
}

/**
 * Reusable outbound processor: groups rows by shipment and creates outbound
 * orders + items with FEFO allocation. Respects an already-open transaction.
 *
 * @param array $allRows Raw grid of cells (header + data rows).
 * @return array JSON-ready payload ['success', 'message', 'stats', 'log'].
 */
function import_outbound_process_sheet(array $allRows, bool $skipUnknown, bool $groupByShipment): array {
    require_once __DIR__ . '/../../classes/Outbound.php';

    $headerRowIndex = null;
    $headers = [];
    $headerKeywords = ['shipment', 'material', 'delivery quantity', 'ship-to', 'order no', 'plan date',
                       'destination', 'location of the ship', 'name of ship', 'street'];
    foreach ($allRows as $idx => $row) {
        $rowStr = strtolower(implode(' ', array_map(fn($v) => (string)($v ?? ''), $row)));
        $matches = 0;
        foreach ($headerKeywords as $kw) { if (str_contains($rowStr, $kw)) $matches++; }
        if ($matches >= 2) {
            $headerRowIndex = $idx;
            $headers = array_map(fn($h) => strtolower(trim(ltrim(trim((string)($h ?? '')), '* '))), $row);
            break;
        }
    }
    if ($headerRowIndex === null) throw new Exception('Header row tidak ditemukan. Pastikan file menggunakan format Planning Outbound.');

    $col = array_flip($headers);

    $db = db();
    $productCache = [];
    foreach ($db->query("SELECT id, product_code, product_name, uom_type, uom_per_pallet FROM products WHERE is_active=1")->fetchAll() as $p) {
        $productCache[strtolower($p['product_code'])] = $p;
        if (preg_match('/(\d{7,})/', $p['product_code'], $m)) $productCache[$m[1]] = $p;
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
        $getAlt = import_getter($col, $row);
        $shipmentNum = $getAlt('shipment number', 'shipment no', 'shipment');
        $materialRaw = $getAlt('material');
        $deliveryQty = floatval($getAlt('delivery quantity'));
        if (empty($materialRaw) || $deliveryQty <= 0) continue;
        if (empty($shipmentNum)) $shipmentNum = 'NO_SHIPMENT_' . ($rIdx + 1);
        if (!isset($shipmentData[$shipmentNum])) {
            $shipmentData[$shipmentNum] = ['rows' => [], 'plan_date' => null, 'customer_id' => null];
        }
        $planDate = import_parse_date($getAlt('plan date', 'first delivery date', 'goods issue date'));
        if ($planDate && !$shipmentData[$shipmentNum]['plan_date']) {
            $shipmentData[$shipmentNum]['plan_date'] = $planDate;
        }
        $shipmentData[$shipmentNum]['rows'][] = $row;
    }

    $ordersCreated = 0;
    $itemsImported = 0;
    $rowsSkipped   = 0;
    $log           = [];

    $ownsTransaction = !$db->inTransaction();
    if ($ownsTransaction) $db->beginTransaction();
    try {
        foreach ($shipmentData as $shipmentNum => $sData) {
            $rows     = $sData['rows'];
            $planDate = $sData['plan_date'] ?? date('Y-m-d');

            $firstRow    = $rows[0];
            $getAlt      = import_getter($col, $firstRow);
            $shipToName  = $getAlt('name of ship-to party', 'name of the ship-to party', 'ship-to party', 'destination');
            $shipToLoc   = $getAlt('location of ship-to party', 'location of the ship-to party', 'destination');
            $customerId  = null;

            if (!$customerId && !empty($shipToName)) {
                $code = 'OUT-' . strtoupper(substr(preg_replace('/[^A-Z0-9]/', '', strtoupper($shipToName)), 0, 8));
                try {
                    $stmt = $db->prepare("INSERT INTO customers (customer_code, customer_name, city) VALUES (?,?,?) ON DUPLICATE KEY UPDATE customer_name=customer_name");
                    $stmt->execute([$code, $shipToName, $shipToLoc ?: null]);
                    $customerId = (int)($db->lastInsertId() ?: $db->query("SELECT id FROM customers WHERE customer_code=" . $db->quote($code))->fetchColumn());
                    $log[] = "Customer: {$shipToName}";
                } catch (\PDOException $e) {}
            }
            if (!$customerId) {
                $defCust = $db->query("SELECT id FROM customers LIMIT 1")->fetch();
                $customerId = $defCust ? (int)$defCust['id'] : null;
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
            $outboundId = (int)Outbound::create($outboundData);
            $ordersCreated++;
            $log[] = "Order #{$outboundId} — Shipment: {$shipmentNum} ({$shipToName}, " . count($rows) . " items)";

            $primaryDestKey = strtolower(trim($shipToName . '|' . $shipToLoc));
            $destMap = [];
            $destSeq = 2;

            foreach ($rows as $row) {
                $ga = import_getter($col, $row);
                $dName   = $ga('name of ship-to party', 'name of the ship-to party', 'ship-to party', 'destination');
                $dLoc    = $ga('location of ship-to party', 'location of the ship-to party', 'destination');
                $dStreet = $ga('street / address', 'street', 'address');
                $dKey    = strtolower(trim($dName . '|' . $dLoc));
                if (empty($dName) || $dKey === $primaryDestKey) continue;
                if (!isset($destMap[$dKey])) {
                    try {
                        $db->prepare("INSERT INTO outbound_destinations (outbound_id, seq, ship_to_name, ship_to_location, kota, street_address, notes) VALUES (?, ?, ?, ?, ?, ?, ?)")
                           ->execute([$outboundId, $destSeq++, $dName, $dLoc, $dLoc, $dStreet ?: null, null]);
                    } catch (\PDOException $eCol) {
                        $db->prepare("INSERT INTO outbound_destinations (outbound_id, seq, ship_to_name, ship_to_location, kota, notes) VALUES (?, ?, ?, ?, ?, ?)")
                           ->execute([$outboundId, $destSeq++, $dName, $dLoc, $dLoc, null]);
                    }
                    $destMap[$dKey] = (int)$db->lastInsertId();
                }
            }

            foreach ($rows as $row) {
                $ga    = import_getter($col, $row);
                $odNo  = $ga('order no (od)', 'order no', 'od no', 'od number');
                $soNo  = $ga('purchase order number', 'so no', 'so number');
                $destName = $ga('name of ship-to party', 'name of the ship-to party', 'ship-to party', 'destination');
                $destLoc  = $ga('location of ship-to party', 'location of the ship-to party', 'destination');
                $materialRaw = $ga('material');
                $description = $ga('description');
                $deliveryQty = floatval($ga('delivery quantity'));
                $salesUnit   = $ga('sales unit', 'uom', 'type');
                $expDate     = import_parse_date($ga('exp date', 'expiry date', 'best before'));

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
                        if (stripos($p['product_name'], substr($description, 0, 15)) !== false) { $product = $p; break; }
                    }
                }

                if (!$product) {
                    if ($skipUnknown) {
                        $log[] = "Material '{$materialRaw}' tidak ditemukan di database → dilewati";
                        $rowsSkipped++;
                        continue;
                    }
                    throw new Exception("Material '{$materialRaw}' tidak ditemukan di database.");
                }

                $uom          = import_normalize_uom($salesUnit, $product['uom_type']);
                $uomPerPallet = import_uom_per_pallet($uom, (int)$product['uom_per_pallet']);
                $pallet       = (int)ceil($deliveryQty / $uomPerPallet);

                $dKey   = strtolower(trim($destName . '|' . $destLoc));
                $destId = ($dKey === $primaryDestKey) ? null : ($destMap[$dKey] ?? null);

                $fefo         = Outbound::getFEFOAllocation($product['id'], $deliveryQty);
                $firstBatch   = null;
                $firstLoc     = null;
                $firstExpDate = $expDate;

                if ($fefo['total_available'] <= 0) {
                    $log[] = "SKIP (stok 0): {$product['product_name']} [{$materialRaw}] — {$deliveryQty} {$uom}";
                    $rowsSkipped++;
                    continue;
                }
                if (!empty($fefo['allocation'])) {
                    $firstBatch   = $fefo['allocation'][0]['batch_number'] ?? null;
                    $firstLoc     = $fefo['allocation'][0]['location']     ?? null;
                    $firstExpDate = $fefo['allocation'][0]['expiry_date']  ?? $expDate;
                }

                try {
                    $stmt = $db->prepare("INSERT INTO outbound_items (outbound_order_id, product_id, quantity, actual_qty, uom, pallet, batch_no, exp_date, location, notes, od_number, so_number, destination_id, customer_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$outboundId, $product['id'], $deliveryQty, $deliveryQty, $uom, $pallet, $firstBatch, $firstExpDate, $firstLoc, $description ?: $product['product_name'], $odNo ?: null, $soNo ?: null, $destId, $customerId]);
                } catch (\PDOException $colErr) {
                    $stmt = $db->prepare("INSERT INTO outbound_items (outbound_order_id, product_id, quantity, actual_qty, uom, pallet, batch_no, exp_date, location, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$outboundId, $product['id'], $deliveryQty, $deliveryQty, $uom, $pallet, $firstBatch, $firstExpDate, $firstLoc, $description ?: $product['product_name']]);
                }
                $outboundItemId = (int)$db->lastInsertId();

                if (!empty($fefo['allocation'])) {
                    foreach ($fefo['allocation'] as $alloc) {
                        if (empty($alloc['location'])) continue;
                        try {
                            $slRow = $db->prepare("SELECT sl.id FROM stock_locations sl JOIN stock s ON sl.stock_id = s.id WHERE s.product_id=? AND sl.location_code=? AND sl.status IN ('Available','Reserved') ORDER BY sl.id ASC LIMIT 1");
                            $slRow->execute([$product['id'], $alloc['location']]);
                            $sl = $slRow->fetch();
                            if ($sl) {
                                $db->prepare("INSERT IGNORE INTO outbound_item_locations (outbound_item_id, stock_location_id, quantity) VALUES (?,?,?)")
                                   ->execute([$outboundItemId, (int)$sl['id'], $alloc['required_qty'] ?? $alloc['quantity'] ?? 0]);
                            }
                        } catch (\PDOException $e) {}
                    }
                }

                $fefoInfo = $fefo['sufficient']
                    ? "FEFO OK ({$firstBatch}@{$firstLoc})"
                    : "Stok kurang (tersedia {$fefo['total_available']}, diminta {$deliveryQty})";
                $destDisplay = $destName ? " → {$destName}" : '';
                $log[] = "OD:{$odNo} | {$product['product_name']} | {$deliveryQty} {$uom}{$destDisplay} | {$fefoInfo}";
                $itemsImported++;
            }

            $orderItemCount = $db->prepare("SELECT COUNT(*) FROM outbound_items WHERE outbound_order_id=?");
            $orderItemCount->execute([$outboundId]);
            if ((int)$orderItemCount->fetchColumn() === 0) {
                $db->prepare("DELETE FROM outbound_orders WHERE id=?")->execute([$outboundId]);
                $ordersCreated--;
                $log[] = "Order #{$outboundId} ({$shipmentNum}) dihapus — semua item tidak ada di stok";
            }
        }

        if ($ownsTransaction) $db->commit();
    } catch (Exception $e) {
        if ($ownsTransaction && $db->inTransaction()) $db->rollBack();
        throw $e;
    }

    return [
        'success' => true,
        'message' => "Import selesai: {$ordersCreated} orders, {$itemsImported} items dari " . count($shipmentData) . " shipments",
        'stats'   => ['orders_created' => $ordersCreated, 'items_imported' => $itemsImported, 'rows_skipped' => $rowsSkipped, 'errors' => 0],
        'log'     => $log,
    ];
}

<?php
/** Inbound Excel import (action: import/inbound) — optimized. */

function import_run_inbound(): void {
    require_once __DIR__ . '/../../classes/Inbound.php';

    $carrierName = trim($_POST['carrier_name'] ?? '') ?: null;
    $fileKey = isset($_FILES['excel_file']) ? 'excel_file' : 'csv_file';
    $allRows = import_read_sheet($fileKey);
    if (empty($allRows)) throw new Exception('File kosong atau tidak bisa dibaca');

    $detect = import_detect_header($allRows);
    $headerIdx = $detect['index'];
    $rawHeaders = array_map(fn($h) => trim((string)($h ?? '')), $detect['row']);
    $colMap = import_header_index($rawHeaders);
    $resolve = import_get_cached_resolver($rawHeaders);

    $itemCol   = $resolve(['item code', 'item', 'material no', 'material', 'sku', 'product code', 'product']);
    $qtyCol    = $resolve(['actual qty', 'actual_qty', 'received qty', 'quantity', 'qty', 'on hand', 'remain qty']);
    $qtyOrderCol = $resolve(['qty order', 'order qty', 'quantity ordered', 'so qty']);
    $uomCol    = $resolve(['uom', 'unit', 'sales unit', 'unit of measure']);
    $skuCol    = $resolve(['sku', 'sku code', 'sku_number']);
    $palletCol = $resolve(['pallet', 'pallet qty']);

    if ($itemCol === null) throw new Exception('Invalid format. Kolom item (Item Code / Material / SKU / Item) tidak ditemukan.');
    if ($qtyCol === null)  throw new Exception('Invalid format. Kolom qty (Actual Qty / Quantity / Qty / on hand) tidak ditemukan.');

    $db = db();

    // ── Pass 1: collect all rows + item codes ─────────────────────────────
    $rows = [];
    $itemCodes = [];
    $skuCodes = [];
    $rowNumber = $headerIdx;

    foreach ($allRows as $data) {
        $rowNumber++;
        if ($rowNumber <= $headerIdx + 1) continue;
        if (empty(array_filter($data))) continue;
        if (import_is_meta_row($data)) continue;

        $getCol = fn(int $ci) => isset($data[$ci]) ? trim((string)$data[$ci]) : '';
        $get = function (string $key, array $aliases) use ($colMap, $data) {
            if (isset($colMap[$key])) {
                $v = trim((string)($data[$colMap[$key]] ?? ''));
                if ($v !== '' && $v !== '0') return $v;
            }
            foreach ($aliases as $a) {
                if (isset($colMap[$a])) {
                    $v = trim((string)($data[$colMap[$a]] ?? ''));
                    if ($v !== '' && $v !== '0') return $v;
                }
            }
            return '';
        };

        $odNumber       = $get('od no', ['od number', 'od_number', 'outbound delivery']);
        $soNumber       = $get('so no', ['so number', 'so_no', 'sales order']);
        $poNumber       = $get('gr number', ['po no', 'po number', 'po_no', 'purchase order']);
        $shipmentNo     = $get('shipment no', ['shipment number', 'shipment_no', 'shipment']);
        $inboundOrderNo = $get('inbound order no', ['inbound order number', 'inbound_order_no', 'io number']);

        $itemCode = $getCol($itemCol);
        $itemCodes[] = $itemCode;
        $skuCode  = $skuCol !== null ? $getCol($skuCol) : '';
        if ($skuCode) $skuCodes[] = $skuCode;

        $actualQty = floatval($getCol($qtyCol) ?: 0);
        $qtyOrder  = $qtyOrderCol !== null ? floatval($getCol($qtyOrderCol) ?: 0) : $actualQty;
        $uom       = import_normalize_uom($getCol($uomCol) ?: 'Drum');
        $pallet    = $palletCol !== null ? floatval($getCol($palletCol) ?: 0) : 0;

        $batchNo       = $get('batch no', ['batch number', 'batch', 'lot', 'no batch']);
        $grDate        = $get('gr date', ['goods receipt date', 'receipt date']);
        $manufactureDate = $get('manufacture date', ['mfg date', 'production date', 'tgl produksi', 'manufacturing date']) ?: $grDate;
        $expDate       = $get('exp date', ['expiry date', 'expired date', 'expiration date', 'best before', 'tgl exp', 'expiry']);
        $location      = $get('location', ['lokasi', 'bin']);
        $remarks       = $get('remarks', ['notes', 'keterangan', 'description']);

        if (empty($itemCode) || $actualQty <= 0) continue;

        $parsedMfgDate = import_parse_date($manufactureDate);
        $parsedExpDate = import_parse_date($expDate);
        if (empty($parsedExpDate) && !empty($parsedMfgDate)) {
            $d = new DateTime($parsedMfgDate);
            $d->modify('+4 years');
            $parsedExpDate = $d->format('Y-m-d');
        }

        $uomPerPallet = import_uom_per_pallet($uom, 0);
        $calculatedPallet = $actualQty > 0 ? (int)ceil($actualQty / $uomPerPallet) : 0;
        $finalPallet = $pallet > 0 ? (int)$pallet : $calculatedPallet;

        $rows[] = [
            'itemCode'        => $itemCode,
            'skuCode'         => $skuCode,
            'odNumber'        => $odNumber,
            'soNumber'        => $soNumber,
            'poNumber'        => $poNumber,
            'shipmentNo'      => $shipmentNo,
            'inboundOrderNo'  => $inboundOrderNo,
            'actualQty'       => $actualQty,
            'qtyOrder'        => $qtyOrder,
            'uom'             => $uom,
            'pallet'          => $finalPallet,
            'batchNo'         => $batchNo,
            'grDate'          => $grDate,
            'manufactureDate' => $parsedMfgDate,
            'expDate'         => $parsedExpDate,
            'location'        => $location,
            'remarks'         => $remarks,
            'rowNumber'       => $rowNumber,
        ];
    }

    // ── Pass 2: batch fetch products ──────────────────────────────────────
    $allCodes = array_merge($itemCodes, $skuCodes);
    $productMap = import_fetch_products($allCodes);

    // ── Pass 3: process with pre-fetched products + batch inserts ─────────
    $chk = $db->query("SHOW COLUMNS FROM inbound_items LIKE 'in_process_status'");
    $hasInProcessCol = ($chk->rowCount() > 0);

    $stmtInbound = $db->prepare("SELECT id FROM inbound_orders WHERE order_number = ? OR (shipment_no IS NOT NULL AND shipment_no <> '' AND shipment_no = ?) LIMIT 1");

    if ($hasInProcessCol) {
        $stmtItem = $db->prepare("INSERT INTO inbound_items (inbound_order_id, product_id, od_number, so_number, batch_number, location, quantity, uom, actual_qty, pallet, manufacture_date, exp_date, stock_status, in_process_status, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending', 'Dues In', ?)");
    } else {
        $stmtItem = $db->prepare("INSERT INTO inbound_items (inbound_order_id, product_id, od_number, so_number, batch_number, location, quantity, uom, actual_qty, pallet, manufacture_date, exp_date, stock_status, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending', ?)");
    }

    $db->beginTransaction();

    $successCount = 0;
    $errorMessages = [];
    $orderCache = [];
    $inboundOrderCache = [];

    foreach ($rows as $r) {
        try {
            $itemCode = $r['itemCode'];
            $skuCode  = $r['skuCode'];

            // Product lookup (pre-fetched)
            $product = $productMap[$itemCode] ?? ($skuCode ? $productMap[$skuCode] : null);
            $autoCreatedProduct = false;

            if (!$product && $skuCode !== '') {
                $product = $productMap[$skuCode] ?? null;
                if ($product) $errorMessages[] = "Row {$r['rowNumber']}: Item '{$itemCode}' cocok lewat SKU '{$skuCode}'";
            }
            if (!$product) {
                $newCode = $skuCode !== '' ? $skuCode : $itemCode;
                $newName = $r['remarks'] !== '' ? $r['remarks'] : $itemCode;
                $st = $db->prepare("INSERT INTO products (product_code, product_name, description, uom_type, uom_per_pallet, liters_per_pallet, max_sku_qty, max_trans_qty, reorder_level, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, 1)");
                $st->execute([$newCode, $newName, $newName, $r['uom'], import_uom_per_pallet($r['uom'], 0), 209.00, 44, 80]);
                $product = ['id' => (int)$db->lastInsertId(), 'product_name' => $newName, 'uom_type' => $r['uom'], 'uom_per_pallet' => import_uom_per_pallet($r['uom'], 0)];
                $autoCreatedProduct = true;
                $errorMessages[] = "Row {$r['rowNumber']}: Product '{$newCode}' dibuat otomatis";
            }

            // Recalculate UPP with actual product
            $uomPerPallet = import_uom_per_pallet($r['uom'], (int)($product['uom_per_pallet'] ?? 4));
            $calculatedPallet = $r['actualQty'] > 0 ? (int)ceil($r['actualQty'] / $uomPerPallet) : 0;
            $finalPallet = $r['pallet'] > 0 ? $r['pallet'] : $calculatedPallet;

            if ($r['pallet'] > 0 && abs($r['pallet'] - $calculatedPallet) > 1) {
                $errorMessages[] = "Row {$r['rowNumber']}: Pallet mismatch (calc: {$calculatedPallet}, given: {$r['pallet']})";
            }

            // Inbound order (cached per GR date / shipment)
            if ($r['shipmentNo'] !== '') {
                $orderNumber = $r['shipmentNo'];
            } elseif ($r['inboundOrderNo'] !== '') {
                $orderNumber = $r['inboundOrderNo'];
            } elseif ($r['grDate'] !== '') {
                $orderNumber = $orderCache[$r['grDate']] ??= Inbound::generateNumber();
            } else {
                $orderNumber = Inbound::generateNumber();
            }

            if (!isset($inboundOrderCache[$orderNumber])) {
                $stmtInbound->execute([$orderNumber, $r['shipmentNo'] !== '' ? $r['shipmentNo'] : $orderNumber]);
                $existing = $stmtInbound->fetch();
                if (!$existing) {
                    $shipVal = $r['shipmentNo'] !== '' ? $r['shipmentNo'] : null;
                    $ins = $db->prepare("INSERT INTO inbound_orders (order_number, order_date, carrier_name, status, shipment_no, notes, created_by) VALUES (?, ?, ?, 'Dues In', ?, 'Imported from Excel', ?)");
                    $ins->execute([$orderNumber, $r['grDate'] !== '' ? $r['grDate'] : date('Y-m-d'), $carrierName, $shipVal, $_SESSION['user_id']]);
                    $inboundOrderCache[$orderNumber] = (int)$db->lastInsertId();
                } else {
                    $inboundOrderCache[$orderNumber] = (int)$existing['id'];
                }
            }
            $inboundId = $inboundOrderCache[$orderNumber];

            // Batch insert item
            $stmtItem->execute([
                $inboundId,
                $product['id'],
                $r['odNumber'] ?: null,
                $r['soNumber'] ?: null,
                $r['batchNo'] ?: null,
                $r['location'] ?: null,
                $r['qtyOrder'] > 0 ? $r['qtyOrder'] : $r['actualQty'],
                $r['uom'],
                $r['actualQty'],
                $finalPallet,
                $r['manufactureDate'],
                $r['expDate'],
                $r['remarks'] ?: null,
            ]);

            $successCount++;
        } catch (Exception $e) {
            $errorMessages[] = $e->getMessage();
        }
    }

    $db->commit();

    json_out([
        'success'    => true,
        'message'    => "Import completed! {$successCount} items imported successfully.",
        'processed'  => $successCount,
        'stats'      => ['items_imported' => $successCount, 'rows_skipped' => count($errorMessages), 'errors' => count($errorMessages)],
        'errors'     => $errorMessages,
        'has_errors' => count($errorMessages) > 0,
    ]);
}
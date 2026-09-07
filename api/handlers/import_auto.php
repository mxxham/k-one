<?php
/**
 * Auto import (action: import/auto) — optimized.
 *
 * Reads a multi-sheet Excel workbook, classifies each sheet by its name and
 * processes everything in one go (one transaction):
 *   - master data  → auto-create / update products
 *   - WMS          → stock (on hand) + inbound orders grouped by GR date
 *   - data putaway → stock (putaway bins)
 *   - schedule     → outbound orders grouped by shipment (FEFO)
 *   - everything else (level A, summary, SAP vs unrest, picking) → skipped
 */

require_once __DIR__ . '/../../classes/Inbound.php';
require_once __DIR__ . '/../../classes/Outbound.php';
require_once __DIR__ . '/../../classes/Product.php';

/** Classify a sheet name into a processing type. */
function import_auto_classify_sheet(string $name): string {
    $n = strtolower($name);
    if (str_contains($n, 'master')) return 'master';
    if (str_contains($n, 'wms'))    return 'wms';
    if (str_contains($n, 'putaway'))return 'putaway';
    if (str_contains($n, 'schedule'))return 'schedule';
    // Also recognize "data level A" style sheets as WMS (stock data)
    if (str_contains($n, 'data level') || str_contains($n, 'level a')) return 'wms';
    return 'skip';
}

/** Infer a product uom_type from UPP (units per pallet). */
function import_auto_infer_uom(int $upp): string {
    if ($upp <= 1) return 'Bags';
    if ($upp <= 8) return 'Drum';
    if ($upp <= 28) return 'Pail';
    return 'Carton';
}

/**
 * Read "Master SKU" sheet and build product_code → UOM lookup from TYPE column (col L).
 * Master SKU layout: col B = Material, col L (index 11) = TYPE (DRUM/CAR/PAIL/IBC/FLUID BAG).
 * Returns [product_code => normalized_uom_type].
 */
function import_auto_read_master_sku_uom(array $sheets): array {
    $map = [];
    foreach ($sheets as $sheet) {
        if (!str_contains(strtolower($sheet['name']), 'master sku')) continue;
        $detect = import_detect_header($sheet['rows']);
        $headers = array_map(fn($h) => trim((string)($h ?? '')), $detect['row']);
        $resolve = import_get_cached_resolver($headers);

        $colMaterial = $resolve(['material', 'item', 'sku', 'product code']);
        $colType     = $resolve(['type', 'material type', 'packaging type']);

        // Fallback: if header detection fails, use fixed column indices
        // B=Material (index 1), L=TYPE (index 11)
        if ($colMaterial === null) $colMaterial = 1;
        if ($colType === null)     $colType = 11;

        for ($i = $detect['index'] + 1; $i < count($sheet['rows']); $i++) {
            $row = $sheet['rows'][$i];
            $code = trim((string)($row[$colMaterial] ?? ''));
            $type = strtoupper(trim((string)($row[$colType] ?? '')));
            if ($code === '' || $type === '') continue;

            $map[$code] = match ($type) {
                'DRUM'      => 'Drum',
                'CAR', 'CARTON' => 'CAR',
                'PAIL'      => 'Pail',
                'IBC'       => 'IBC',
                'FLUID BAG', 'FLUIDBAG' => 'Fluidbag',
                default     => ucfirst(strtolower($type)),
            };
        }
        break;  // only first Master SKU sheet
    }
    return $map;
}

/** Create missing products for rows flagged _auto_create (returns count). */
function import_auto_ensure_products(array &$rows): int {
    $db = db();

    // Pre-fetch all auto-create codes
    $codes = [];
    foreach ($rows as $r) {
        if (!empty($r['_auto_create']) && empty($r['product_id'])) {
            $codes[] = !empty($r['sku_code']) ? $r['sku_code'] : $r['product_code'];
        }
    }
    $productMap = import_fetch_products($codes);

    $created = 0;
    foreach ($rows as &$row) {
        if (empty($row['_auto_create']) || !empty($row['product_id'])) continue;
        $code = !empty($row['sku_code']) ? $row['sku_code'] : $row['product_code'];

        // Check if product already exists (from batch fetch or created earlier)
        if (isset($productMap[$code])) {
            $row['product_id'] = (int)$productMap[$code]['id'];
            $row['uom_per_pallet'] = import_uom_per_pallet($row['uom'] ?? 'Drum', (int)($productMap[$code]['uom_per_pallet'] ?? 0));
            $row['pallet'] = (int)ceil($row['quantity'] / max(1, $row['uom_per_pallet']));
            $row['_auto_create'] = false;
            continue;
        }

        $name = !empty($row['description']) ? $row['description'] : ($row['product_name'] ?: $row['product_code']);
        $uomType = $row['uom'] ?? 'Drum';
        $st = $db->prepare("INSERT INTO products (product_code, product_name, description, uom_type, uom_per_pallet, liters_per_unit, max_sku_qty, max_trans_qty, reorder_level, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $st->execute([$code, $name, $row['description'] ?: null, $uomType, import_uom_per_pallet($uomType, 0), 209.00, 44, 80, 0, 1]);
        $row['product_id'] = (int)$db->lastInsertId();
        $row['uom_per_pallet'] = import_uom_per_pallet($uomType, 0);
        $row['pallet'] = (int)ceil($row['quantity'] / max(1, $row['uom_per_pallet']));
        $row['_auto_create'] = false;
        $productMap[$code] = ['id' => $row['product_id'], 'uom_per_pallet' => $row['uom_per_pallet']];
        $created++;
    }
    unset($row);
    return $created;
}

/** Process "master data" sheet → upsert products (batch optimized with UPSERT). */
function import_auto_process_master(array $allRows, array &$log): array {
    $detect = import_detect_header($allRows);
    $headers = array_map(fn($h) => trim((string)($h ?? '')), $detect['row']);
    $resolve = import_get_cached_resolver($headers);

    $col = [
        'code' => $resolve(['material', 'item', 'item code', 'product code', 'sku']),
        'name' => $resolve(['material description', 'description', 'product name']),
        'loc'  => $resolve(['storage location', 'location', 'lokasi', 'bin']),
        'upp'  => $resolve(['upp', 'uom per pallet', 'units per pallet']),
        'vol'  => $resolve(['volume', 'vol', 'liters per unit']),
    ];

    if ($col['code'] === null) {
        $log[] = 'Master data: kolom Material tidak ditemukan → sheet dilewati';
        return ['created' => 0, 'updated' => 0];
    }

    $db = db();

    $created = 0;
    $updated = 0;

    // Use INSERT ... ON DUPLICATE KEY UPDATE (works in MariaDB/MySQL)
    $upsertSql = "INSERT INTO products 
        (product_code, product_name, description, uom_type, uom_per_pallet, liters_per_unit, 
         default_location, max_sku_qty, max_trans_qty, reorder_level, is_active)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            product_name = VALUES(product_name),
            uom_type = VALUES(uom_type),
            uom_per_pallet = VALUES(uom_per_pallet),
            liters_per_unit = VALUES(liters_per_unit),
            default_location = COALESCE(VALUES(default_location), default_location),
            description = VALUES(description),
            updated_at = NOW()";

    $stmt = $db->prepare($upsertSql);

    for ($i = $detect['index'] + 1; $i < count($allRows); $i++) {
        $row = $allRows[$i];
        $code = trim((string)($row[$col['code']] ?? ''));
        if ($code === '') continue;

        $name = $col['name'] !== null ? trim((string)($row[$col['name']] ?? '')) : '';
        if ($name === '') $name = $code;
        $loc = $col['loc'] !== null ? trim((string)($row[$col['loc']] ?? '')) : '';
        $upp = $col['upp'] !== null ? (int)($row[$col['upp']] ?? 0) : 0;
        $vol = $col['vol'] !== null ? (float)($row[$col['vol']] ?? 0) : 0;
        // Derive UPP from product name when sheet UPP is blank (e.g. "12*0.8L" → 48)
        if ($upp <= 0 && $name !== '') {
            $derived = \PalletHelper::deriveUppFromPackSize($name);
            if ($derived !== null && $derived > 0) $upp = $derived;
        }
        $uomType = import_auto_infer_uom($upp);

        // Check if product existed before to count correctly
        $checkSt = $db->prepare("SELECT id FROM products WHERE product_code = ? LIMIT 1");
        $checkSt->execute([$code]);
        $existed = $checkSt->fetch() !== false;

        $stmt->execute([
            $code, $name, $name, $uomType, $upp ?: 4, $vol ?: 209, $loc ?: null,
            max(1, $upp ?: 4), max(2, ($upp ?: 4) * 2), 0, 1
        ]);

        if ($existed) {
            $updated++;
        } else {
            $created++;
        }
    }
    $log[] = "Master data: {$created} produk baru, {$updated} diperbarui";
    return ['created' => $created, 'updated' => $updated];
}

/** Create inbound orders from WMS stock rows, grouped by GR date (batch inserts). */
function import_auto_create_inbound(array $rows, array &$log): array {
    $db = db();
    $groups = [];
    foreach ($rows as $row) {
        if (!empty($row['_errors']) || empty($row['product_id'])) continue;
        $gr = $row['manufacture_date'] ?: 'NO_GR_DATE';
        $groups[$gr][] = $row;
    }

    $orders = 0;
    $items = 0;

    // Pre-prepare statements
    $stmtOrder = $db->prepare("INSERT INTO inbound_orders (order_number, order_date, carrier_name, status, notes, created_by) VALUES (?, ?, NULL, 'Dues In', ?, ?)");
    $stmtItem = $db->prepare("INSERT INTO inbound_items (inbound_order_id, product_id, batch_number, location, quantity, uom, actual_qty, pallet, manufacture_date, exp_date, stock_status, in_process_status, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending', 'Dues In', ?)");

    foreach ($groups as $gr => $groupRows) {
        $orderDate = $gr === 'NO_GR_DATE' ? date('Y-m-d') : $gr;
        $orderNumber = Inbound::generateNumber();
        $stmtOrder->execute([$orderNumber, $orderDate, 'Auto import (WMS) — GR: ' . $gr, $_SESSION['user_id']]);
        $inboundId = (int)$db->lastInsertId();

        $i = 0;
        foreach ($groupRows as $row) {
            $uomPerPallet = $row['uom_per_pallet'] ?? import_uom_per_pallet($row['uom'], 0);
            $pallet = (int)ceil($row['quantity'] / max(1, $uomPerPallet));
            $stmtItem->execute([$inboundId, $row['product_id'], $row['batch_number'] ?: null, $row['location'] ?: null, $row['quantity'], $row['uom'], $row['quantity'], $pallet, $row['manufacture_date'] ?: null, $row['expiry_date'] ?: null, 'Auto import']);
            $i++;
        }
        $orders++;
        $items += $i;
        $log[] = "Inbound #{$orderNumber} (GR: {$gr}) — {$i} item";
    }
    return ['orders' => $orders, 'items' => $items];
}

/**
 * Populate location_master + stock from the WMS sheet's col H (Lokasi) + cached col V (Remain Qty).
 *
 * The WMS sheet is the complete warehouse layout — every row is a bin location.
 * Col H = location code (e.g. CA01A01), col V = cached Remain Qty formula result.
 *
 * This function:
 *   1. Parses the xlsx XML to extract location codes + cached remain qty (fast, no formula eval)
 *   2. Upserts all bins into location_master (aisle, rack, level, position parsed from code)
 *   3. Creates stock records for bins with remain qty > 0
 */
function _import_enrich_wms_locations(string $tmpPath, array $sheets): array {
    $db = db();
    $stats = ['locations_upserted' => 0, 'stock_created' => 0];

    // ── 1. Extract locations + remain qty from xlsx XML ──
    $zip = new \ZipArchive();
    if ($zip->open($tmpPath) !== true) return $stats;

    // Load shared strings
    $sharedStrings = [];
    $ssContent = $zip->getFromName('xl/sharedStrings.xml');
    if ($ssContent) {
        $ss = simplexml_load_string($ssContent);
        if ($ss) {
            $idx = 0;
            foreach ($ss->children() as $si) {
                if (isset($si->t)) {
                    $sharedStrings[$idx] = (string)$si->t;
                } else {
                    $text = '';
                    foreach ($si->r as $r) { $text .= (string)($r->t ?? ''); }
                    $sharedStrings[$idx] = $text;
                }
                $idx++;
            }
        }
    }

    // Find WMS sheet file
    $sheetFile = null;
    $wbXml = simplexml_load_string($zip->getFromName('xl/workbook.xml'));
    $relsXml = simplexml_load_string($zip->getFromName('xl/_rels/workbook.xml.rels'));
    if ($wbXml && $relsXml) {
        $ns = $wbXml->getNamespaces(true);
        $sheetMap = [];
        foreach ($relsXml->Relationship as $rel) {
            $sheetMap[(string)$rel['Id']] = 'xl/' . ltrim((string)$rel['Target'], '/');
        }
        foreach ($wbXml->sheets->sheet as $sheet) {
            if (strtolower((string)$sheet['name']) === 'wms') {
                $attrs = $sheet->attributes($ns['r']);
                $rid = $attrs ? (string)$attrs['id'] : null;
                $sheetFile = $sheetMap[$rid] ?? null;
                break;
            }
        }
    }

    if (!$sheetFile || !$content = $zip->getFromName($sheetFile)) {
        $zip->close();
        return $stats;
    }

    $xml = simplexml_load_string($content);
    if (!$xml) { $zip->close(); return $stats; }

    // Excel serial date → Y-m-d (Excel epoch = 1899-12-30)
    $excelEpoch = new \DateTime('1899-12-30');
    $_excelSerialToDate = function($serial) use ($excelEpoch) {
        $s = intval($serial);
        if ($s < 1) return null;
        $dt = clone $excelEpoch;
        $dt->modify("+{$s} days");
        return $dt->format('Y-m-d');
    };

    // Parse rows: col H = location, col AE = on hand, col L = item, col I = batch, col AL = uom, col K = expired date
    $binData = []; // location_code => ['qty' => ..., 'item_code' => ..., 'batch' => ..., 'uom' => ..., 'expiry_date' => ...]
    foreach ($xml->sheetData->row as $row) {
        $rowNum = (int)$row['r'];
        if ($rowNum < 5) continue; // skip header rows (rows 1-4)
        $hVal = null; $aeVal = null; $vVal = null;
        $lVal = null; $iVal = null; $alVal = null; $kVal = null;
        foreach ($row->c as $c) {
            $ref = (string)$c['r'];
            $col = preg_replace('/\d+/', '', $ref);
            $type = (string)($c['t'] ?? 'n');
            if (isset($c->v)) {
                $raw = (string)$c->v;
                if ($type === 's') {
                    $val = $sharedStrings[(int)$raw] ?? null;
                } else {
                    $val = is_numeric($raw) ? floatval($raw) : $raw;
                }
                if ($col === 'H')  $hVal = $val;
                if ($col === 'V')  $vVal = $val;  // formula col (stale)
                if ($col === 'AE') $aeVal = $val; // on hand (correct cached value)
                if ($col === 'L')  $lVal = $val;  // item code
                if ($col === 'I')  $iVal = $val;  // batch
                if ($col === 'AL') $alVal = $val; // uom
                if ($col === 'K')  $kVal = $val;  // expired date (Excel serial)
            }
        }
        if (is_string($hVal) && preg_match('/^[A-Z]{2}\d+[A-E]\d{2}$/', $hVal)) {
            // Prefer AE (on hand) over V (formula) — V often has stale cached 0
            $qty = floatval($aeVal ?? 0);
            if ($qty <= 0 && floatval($vVal ?? 0) > 0) $qty = floatval($vVal);
            $binData[strtoupper($hVal)] = [
                'qty'          => $qty,
                'item_code'    => is_numeric($lVal) ? strval(intval($lVal)) : ($lVal ?? null),
                'batch'        => is_numeric($iVal) ? strval(intval($iVal)) : ($iVal ?? null),
                'uom'          => !empty($alVal) ? trim($alVal) : null,
                'expiry_date'  => is_numeric($kVal) ? $_excelSerialToDate($kVal) : null,
            ];
        }
    }
    $zip->close();

    if (empty($binData)) return $stats;

    // ── 2. Parse location code → aisle, rack, level, position ──
    // Format: CA12D01 → aisle=CA, rack=CA12, level=D, position=01
    function _parseLocationCode(string $code): array {
        preg_match('/^([A-Z]{2})(\d+)([A-E])(\d{2})$/', $code, $m);
        if (!$m) return [];
        return [
            'aisle'    => $m[1],
            'rack'     => $m[1] . $m[2],
            'level'    => $m[3],
            'position' => $m[4],
        ];
    }

    // ── 3. Upsert all bins into location_master ──
    $stmtLoc = $db->prepare("
        INSERT INTO location_master (location_code, aisle, rack, row_name, position, zone_code, is_active, equipment_accessible, is_pick_face)
        VALUES (?, ?, ?, ?, ?, ?, 1, 1, 0)
        ON DUPLICATE KEY UPDATE
            is_active = 1,
            aisle = VALUES(aisle),
            rack = VALUES(rack),
            row_name = VALUES(row_name),
            position = VALUES(position)
    ");

    $db->beginTransaction();
    try {
        foreach ($binData as $locCode => $remainQty) {
            $parts = _parseLocationCode($locCode);
            if (empty($parts)) continue;

            // Zone assignment: level A = PICK_FAST, B-E = RESERVE
            $zone = ($parts['level'] === 'A') ? 'PICK_FAST' : 'RESERVE';

            $stmtLoc->execute([
                $locCode,
                $parts['aisle'],
                $parts['rack'],
                $parts['level'],
                $parts['position'],
                $zone,
            ]);
            $stats['locations_upserted']++;
        }

        // ── 4. Create stock records for bins with remain qty > 0 ──
        // Clear old Located stock + stock_locations (full re-import from WMS replaces everything)
        $db->exec("DELETE FROM stock_locations WHERE location_code IS NOT NULL AND location_code != '' AND status = 'Available'");
        $db->exec("DELETE FROM stock WHERE location IS NOT NULL AND location != '' AND stock_status = 'Available'");

        $stmtStock = $db->prepare("
            INSERT INTO stock (product_id, batch_number, location, quantity, uom, pallet, expiry_date, stock_status, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'Available', NOW(), NOW())
        ");
        $stmtSL = $db->prepare("
            INSERT INTO stock_locations (stock_id, location_code, quantity, original_quantity, uom, batch_number, lpn_code, status, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'Available', NOW(), NOW())
        ");

        // We need product_id for each location — get from data putaway sheet + WMS item codes
        // Build location → product mapping from putaway data
        $putawayMap = []; // location => ['product_id' => ..., 'batch' => ..., 'uom' => ...]
        foreach ($sheets as $sheet) {
            if (strtolower($sheet['name']) !== 'data putaway') continue;
            $rows = import_stock_parse($sheet['rows']);
            $rows = import_stock_validate($rows);
            foreach ($rows as $r) {
                if (empty($r['_errors']) && !empty($r['product_id']) && $r['product_id'] > 0) {
                    $putawayMap[strtoupper(trim($r['location'] ?? ''))] = [
                        'product_id' => (int)$r['product_id'],
                        'batch'      => $r['batch_number'] ?? null,
                        'uom'        => $r['uom'] ?? 'CAR',
                        'uom_per_pallet' => $r['uom_per_pallet'] ?? 4,
                    ];
                }
            }
            break;
        }

        // Build item_code → product_id lookup from products table
        $prodMap = []; // item_code_string => product_id
        $prodRows = $db->query("SELECT id, product_code FROM products")->fetchAll();
        foreach ($prodRows as $p) {
            $prodMap[strval($p['product_code'])] = (int)$p['id'];
        }

        // Get UPP from products table for pallet calc
        $uppMap = []; // product_id => uom_per_pallet
        $uppRows = $db->query("SELECT id, uom_per_pallet FROM products WHERE uom_per_pallet > 0")->fetchAll();
        foreach ($uppRows as $u) {
            $uppMap[(int)$u['id']] = (int)$u['uom_per_pallet'];
        }

        foreach ($binData as $locCode => $binInfo) {
            $qty = $binInfo['qty'];
            if ($qty <= 0) continue;

            // Resolve product_id: first from putawayMap, then from WMS item code
            $product_id = null;
            $batch = $binInfo['batch'];
            $uom = $binInfo['uom'] ?? 'CAR';

            $pw = $putawayMap[$locCode] ?? null;
            if ($pw) {
                $product_id = $pw['product_id'];
                if (!$batch) $batch = $pw['batch'];
                if ($uom === 'CAR') $uom = $pw['uom'];
            }
            if (!$product_id && !empty($binInfo['item_code'])) {
                $product_id = $prodMap[$binInfo['item_code']] ?? null;
            }
            if (!$product_id) continue; // can't create stock without product

            $upp = $uppMap[$product_id] ?? 0;
            $pallet = (int)ceil($qty / max(1, $upp));
            $expiryDate = $binInfo['expiry_date'] ?? null;
            // Set stock_status to Expired if expiry_date has passed
            $stockStatus = 'Available';
            if ($expiryDate && $expiryDate < date('Y-m-d')) {
                $stockStatus = 'Expired';
            }
            $stmtStock->execute([
                $product_id,
                $batch,
                $locCode,
                $qty,
                $uom,
                $pallet,
                $expiryDate,
            ]);
            // Update stock_status if expired
            if ($stockStatus === 'Expired') {
                $stockIdTmp = $db->lastInsertId();
                $db->prepare("UPDATE stock SET stock_status = 'Expired' WHERE id = ?")->execute([$stockIdTmp]);
            }
            $stockId = $db->lastInsertId();
            $stmtSL->execute([
                $stockId,
                $locCode,
                $qty,
                $qty,
                $uom,
                $batch,
                'WMS-' . $locCode,
            ]);
            $stats['stock_created']++;
        }

        $db->commit();
    } catch (\Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }

    return $stats;
}

/** Main auto-import runner — chunked reading, batch operations. */
function import_auto_run(): void {
    import_raise_memory_limit();
    $file = $_FILES['excel_file'] ?? null;
    if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('File tidak diupload atau error upload.');
    }
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['xlsx', 'xls'])) throw new Exception('Format file harus .xlsx atau .xls');

// ── Chunked sheet reading (no formula eval; replace formula strings with blank) ──────
    $sheets = [];
    $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReader($ext === 'xlsx' ? 'Xlsx' : 'Xls');
    $reader->setReadDataOnly(true);
    $spreadsheet = $reader->load($file['tmp_name']);

    foreach ($spreadsheet->getSheetNames() as $sName) {
        $sheetData = [];
        $sheet = $spreadsheet->getSheetByName($sName);
        foreach ($sheet->getRowIterator() as $row) {
            $cellIterator = $row->getCellIterator();
            $cellIterator->setIterateOnlyExistingCells(false);
            $rowData = [];
            foreach ($cellIterator as $cell) {
                $val = $cell->getValue();
                // Replace formula strings with blank — fast, no evaluation
                if (is_string($val) && strlen($val) > 0 && $val[0] === '=') {
                    $val = '';
                }
                $rowData[] = $val;
            }
            $sheetData[] = $rowData;
        }
        if (!empty($sheetData)) {
            $sheets[] = array('name' => $sName, 'rows' => $sheetData);
        }
    }
    if (empty($sheets)) throw new Exception('File kosong atau tidak bisa dibaca');

    // ── Populate location_master + stock from WMS sheet (col H = bins, col V = remain qty) ──
    if ($ext === 'xlsx') {
        $wmsStats = _import_enrich_wms_locations($file['tmp_name'], $sheets);
        $log[] = "WMS: {$wmsStats['locations_upserted']} lokasi, {$wmsStats['stock_created']} stok dari remain qty";
    }

    $report = [
        'products_created' => 0,
        'products_updated' => 0,
        'stock_imported'   => 0,
        'stock_skipped'    => 0,
        'stock_auto_created' => 0,
        'inbound_orders'   => 0,
        'inbound_items'    => 0,
        'outbound_orders'  => 0,
        'outbound_items'   => 0,
        'outbound_skipped' => 0,
        'skipped_sheets'   => [],
    ];
    $log = [];

    $db = db();
    $ownsTransaction = !$db->inTransaction();
    if ($ownsTransaction) $db->beginTransaction();
    try {
        // Pass 1 — master data → products
        foreach ($sheets as $sheet) {
            if (import_auto_classify_sheet($sheet['name']) !== 'master') continue;
            $r = import_auto_process_master($sheet['rows'], $log);
            $report['products_created'] += $r['created'];
            $report['products_updated'] += $r['updated'];
        }

        // Build UOM lookup from Master SKU sheet (TYPE column → UOM)
        $masterSkuUom = import_auto_read_master_sku_uom($sheets);
        if (!empty($masterSkuUom)) {
            $log[] = "Master SKU: " . count($masterSkuUom) . " produk UOM lookup";
        }

        // Correct product UOM from Master SKU after processMaster() overwrites with inferUom()
        if (!empty($masterSkuUom)) {
            $uomCorrected = 0;
            // Fetch current uom_per_pallet for each product to preserve correct UPP
            $currentUpps = [];
            $placeholders = implode(',', array_fill(0, count($masterSkuUom), '?'));
            $codes = array_keys($masterSkuUom);
            if (!empty($codes)) {
                $stmtUpp = $db->prepare("SELECT product_code, uom_per_pallet FROM products WHERE product_code IN ($placeholders)");
                $stmtUpp->execute($codes);
                while ($rowUpp = $stmtUpp->fetch(PDO::FETCH_ASSOC)) {
                    $currentUpps[$rowUpp['product_code']] = (int)$rowUpp['uom_per_pallet'];
                }
            }
            $updUom = $db->prepare("UPDATE products SET uom_type = ?, uom_per_pallet = ? WHERE product_code = ? AND (uom_type != ? OR uom_per_pallet != ?)");
            foreach ($masterSkuUom as $code => $uom) {
                // Use product's current UPP instead of hardcoded default
                $productUpp = $currentUpps[$code] ?? 0;
                $uppVal = import_uom_per_pallet($uom, $productUpp);
                $updUom->execute([$uom, $uppVal, $code, $uom, $uppVal]);
                $uomCorrected += $updUom->rowCount();
            }
            if ($uomCorrected > 0) {
                $log[] = "UOM correction: {$uomCorrected} produk diperbarui dari Master SKU";
            }
        }

        // Pass 2 — WMS + putaway → stock; WMS → inbound (GR date groups)
        foreach ($sheets as $sheet) {
            $type = import_auto_classify_sheet($sheet['name']);
            if ($type !== 'wms' && $type !== 'putaway') continue;

            // WMS sheet may have non-standard headers (cols 7+) — _import_enrich_wms_locations()
            // already handles stock creation via raw XML, so parse failure is non-fatal for WMS.
            try {
                $rows = import_stock_parse($sheet['rows']);
            } catch (\Throwable $e) {
                if ($type === 'wms') {
                    $log[] = "Sheet '{$sheet['name']}' — parse skipped (WMS stock via XML): {$e->getMessage()}";
                    continue;
                }
                throw $e;
            }

            // Fill blank UOM from Master SKU lookup; flag unknown as 'UNKNOWN'
            if (!empty($masterSkuUom)) {
                $uomFilled = 0;
                $uomUnknown = 0;
                foreach ($rows as &$row) {
                    $code = !empty($row['sku_code']) ? $row['sku_code'] : $row['product_code'];
                    $curUom = trim($row['uom'] ?? '');
                    // Only fill if current UOM is the default 'Drum' (from blank in Excel)
                    // or explicitly empty — don't overwrite correct values already in WMS
                    if ($curUom === '' || $curUom === 'Drum') {
                        if (isset($masterSkuUom[$code])) {
                            $row['uom'] = $masterSkuUom[$code];
                            $uomFilled++;
                        } elseif ($curUom === '') {
                            $row['uom'] = 'UNKNOWN';
                            $uomUnknown++;
                        }
                        // If curUom === 'Drum' and not in lookup, keep as Drum (might be correct)
                    }
                }
                unset($row);
                if ($uomFilled > 0 || $uomUnknown > 0) {
                    $log[] = "UOM fill: {$uomFilled} dari Master SKU, {$uomUnknown} UNKNOWN";
                }
            }

            if ($type === 'wms') {
                foreach ($rows as &$r) $r['stock_status'] = 'Available';
                unset($r);
            }
            $rows = import_stock_validate($rows);
            $report['stock_auto_created'] += import_auto_ensure_products($rows);

            // Skip stock_commit for WMS + putaway: _import_enrich_wms_locations() already handles stock from cached remain qty
            // Only create inbound orders from WMS sheet
            if ($type === 'wms') {
                $inb = import_auto_create_inbound($rows, $log);
                $report['inbound_orders'] += $inb['orders'];
                $report['inbound_items']  += $inb['items'];
            }
            $log[] = "Sheet '{$sheet['name']}' — " . ($type === 'wms' ? 'inbound' : 'produk') . " diproses";
        }

        // Pass 3 — schedule → outbound
        foreach ($sheets as $sheet) {
            if (import_auto_classify_sheet($sheet['name']) !== 'schedule') continue;
            $result = import_outbound_process_sheet($sheet['rows'], true, true);
            $report['outbound_orders'] += $result['stats']['orders_created'];
            $report['outbound_items']  += $result['stats']['items_imported'];
            $report['outbound_skipped'] += $result['stats']['rows_skipped'];
            $log[] = "Sheet '{$sheet['name']}' — outbound {$result['stats']['orders_created']} orders, {$result['stats']['items_imported']} items";
            $log = array_merge($log, $result['log']);
        }

        // Pass 4 — collect skipped sheets
        foreach ($sheets as $sheet) {
            if (import_auto_classify_sheet($sheet['name']) === 'skip') {
                $report['skipped_sheets'][] = $sheet['name'];
            }
        }

        if ($ownsTransaction) $db->commit();
    } catch (\Throwable $e) {
        if ($ownsTransaction && $db->inTransaction()) $db->rollBack();
        throw $e;
    }

    json_out([
        'success' => true,
        'message' => 'Auto import selesai. Semua sheet diproses dalam satu aksi.',
        'stats'   => $report,
        'log'     => $log,
    ]);
}
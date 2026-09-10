<?php
/** Stock import (actions: import/stock_preview, import/stock_commit) — optimized. */

function import_stock_parse(array $allRows): array {
    $detect = import_detect_header($allRows);
    $headerIdx = $detect['index'];
    $headers   = array_map(fn($h) => trim((string)($h ?? '')), $detect['row']);
    $resolve = import_get_cached_resolver($headers);

    $fields = [
        'product_code'    => ['item', 'item code', 'material no', 'material', 'sku', 'product code', 'kode produk', 'product', 'product_code'],
        'sku_code'        => ['sku', 'sku code', 'sku_number', 'sku no'],
        'batch_number'    => ['batch number', 'batch no', 'batch', 'lot', 'no batch', 'batch_number', 'lot number', 'batch', 'batch no'],
        'location'        => ['lokasi', 'location', 'bin', 'warehouse location', 'storage bin', 'zone'],
        'quantity'        => ['remain qty', 'remaining qty', 'available qty', 'Qty', 'on hand', 'on-hand', 'onhand', 'quantity', 'actual qty', 'stock qty', 'qty', 'jumlah', 'total', 'sum of remain qty'],
        'uom'             => ['uom', 'unit', 'satuan', 'unit of measure', 'sales unit', 'uom code'],
        'manufacture_date'=> ['gr date', 'goods receipt date', 'receipt date', 'mfg date', 'manufacture date', 'production date', 'tgl produksi', 'manufacturing date', 'production_date', 'GR date'],
        'expiry_date'     => ['expired date', 'expiry date', 'exp date', 'expiration date', 'best before', 'tgl exp', 'expiry', 'exp_date', 'Expired Date'],
        'stock_status'    => ['stock status', 'status', 'stock_status', 'state', 'quality'],
        'notes'           => ['notes', 'catatan', 'keterangan', 'remarks', 'remark'],
        'description'     => ['description', 'product description', 'item description', 'deskripsi', 'material description', 'Description'],
    ];

    $resolved = [];
    foreach ($fields as $key => $patterns) {
        $resolved[$key] = $resolve($patterns);
    }

    if ($resolved['product_code'] === null && $resolved['sku_code'] !== null) {
        $resolved['product_code'] = $resolved['sku_code'];
    }
    if ($resolved['product_code'] === null) throw new Exception("Kolom produk (Item / SKU / product code) tidak ditemukan.");
    if ($resolved['quantity'] === null)     throw new Exception("Kolom qty ('on hand' / 'Qty') tidak ditemukan.");

    $rows = [];
    $lastProductCode = '';
    for ($i = $headerIdx + 1; $i < count($allRows); $i++) {
        $row = $allRows[$i];
        $productCode = trim((string)($row[$resolved['product_code']] ?? ''));
        // Pivot table format: carry forward product code from previous row
        if (empty($productCode) || strtolower($productCode) === 'kode produk (wajib)') {
            $productCode = $lastProductCode;
        }
        if (empty($productCode)) continue;
        $lastProductCode = $productCode;
        $qty = floatval($row[$resolved['quantity']] ?? 0);
        if ($qty <= 0) continue;
        $uomRaw = trim((string)($row[$resolved['uom'] ?? -1] ?? 'Drum')) ?: 'Drum';
        $rows[] = [
            'product_code'     => $productCode,
            'sku_code'         => $resolved['sku_code'] !== null ? trim((string)($row[$resolved['sku_code']] ?? '')) : '',
            'batch_number'     => trim((string)($row[$resolved['batch_number'] ?? -1] ?? '')),
            'location'         => trim((string)($row[$resolved['location'] ?? -1] ?? '')),
            'quantity'         => $qty,
            'uom'              => import_normalize_uom($uomRaw),
            'manufacture_date' => import_parse_date($row[$resolved['manufacture_date'] ?? -1] ?? null),
            'expiry_date'      => import_parse_date($row[$resolved['expiry_date'] ?? -1] ?? null),
            'stock_status'     => trim((string)($row[$resolved['stock_status'] ?? -1] ?? 'Available')) ?: 'Available',
            'notes'            => trim((string)($row[$resolved['notes'] ?? -1] ?? '')),
            'description'      => trim((string)($row[$resolved['description'] ?? -1] ?? '')),
            '_row_num'         => $i + 1,
        ];
    }
    if (empty($rows)) throw new Exception("Tidak ada data valid yang ditemukan di file.");
    return $rows;
}

function import_stock_validate(array $rows): array {
    $db = db();

    // Batch fetch all products at once
    $allCodes = [];
    foreach ($rows as $r) {
        $allCodes[] = $r['product_code'];
        if (!empty($r['sku_code'])) $allCodes[] = $r['sku_code'];
    }
    $productMap = import_fetch_products($allCodes);

    foreach ($rows as &$row) {
        $row['_errors']   = [];
        $row['_warnings'] = [];

        $candidates = array_values(array_unique(array_filter([
            $row['product_code'],
            $row['sku_code'] ?? '',
        ])));
        $product = null;
        $usedCode = null;
        foreach ($candidates as $code) {
            if (isset($productMap[$code])) {
                $product = $productMap[$code];
                $usedCode = $code;
                break;
            }
        }

        if (!$product) {
            $row['_auto_create'] = true;
            $row['product_id']   = null;
            $row['product_name'] = $row['description'] ?: $row['product_code'];
            $autoCode = !empty($row['sku_code']) ? $row['sku_code'] : $row['product_code'];
            $row['_warnings'][] = "Produk '{$row['product_code']}' tidak ditemukan — akan dibuat otomatis sebagai '{$autoCode}'";
        } else {
            if ($usedCode !== $row['product_code']) {
                $row['_warnings'][] = "Produk dicocokkan lewat SKU '{$usedCode}' (Item '{$row['product_code']}' tidak ditemukan)";
                $row['product_code'] = $usedCode;
            }
            $row['product_id']   = (int)$product['id'];
            $row['product_name'] = $product['product_name'];
            if (empty($row['uom'])) $row['uom'] = $product['uom_type'] ?? 'Drum';
            $row['uom_per_pallet'] = import_uom_per_pallet($row['uom'], (int)($product['uom_per_pallet'] ?? 4));
            $row['pallet'] = (int)ceil($row['quantity'] / $row['uom_per_pallet']);
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

function import_stock_commit(array $rows, string $mode): array {
    $db = db();
    $ownsTransaction = !$db->inTransaction();
    if ($ownsTransaction) $db->beginTransaction();
    try {
        $imported = 0;
        $skipped  = 0;
        $autoCreated = 0;
        $autoLocations = 0;
        $importErrors = [];
        $refPrefix = 'IST-' . date('Ymd') . '-';
        $productCache = [];
        $locationCache = [];

        $findProduct = function (string $code) use ($db, &$productCache): ?array {
            if (isset($productCache[$code])) return $productCache[$code];
            $st = $db->prepare("SELECT id, product_name, uom_type, uom_per_pallet FROM products WHERE product_code = ? AND is_active = 1 LIMIT 1");
            $st->execute([$code]);
            $productCache[$code] = $st->fetch() ?: null;
            return $productCache[$code];
        };

        $ensureLocation = function (string $loc) use ($db, &$locationCache, &$autoLocations): void {
            $loc = trim($loc);
            if ($loc === '' || isset($locationCache[$loc])) return;
            $st = $db->prepare("SELECT id FROM location_master WHERE location_code = ? LIMIT 1");
            $st->execute([$loc]);
            if ($st->fetch()) { $locationCache[$loc] = true; return; }
            $db->prepare("INSERT INTO location_master (location_code, zone, is_active) VALUES (?, 'Bulk', 1)")->execute([$loc]);
            $locationCache[$loc] = true;
            $autoLocations++;
        };

        // Pre-fetch all existing stock records in one query
        $stockKeys = [];
        foreach ($rows as $row) {
            if (!empty($row['_errors']) || $row['product_id'] <= 0) continue;
            $key = $row['product_id'] . '|' . ($row['batch_number'] ?? '') . '|' . ($row['location'] ?? '') . '|' . $row['stock_status'];
            $stockKeys[$key] = [$row['product_id'], $row['batch_number'] ?? null, $row['location'] ?? null, $row['stock_status']];
        }
        $existingStock = [];
        if ($stockKeys) {
            $placeholders = implode(',', array_fill(0, count($stockKeys), '(?,?,?,?)'));
            $params = [];
            foreach ($stockKeys as $v) { $params = array_merge($params, $v); }
            $st = $db->prepare("SELECT id, product_id, batch_number, location, stock_status, quantity, pallet FROM stock WHERE (product_id, batch_number, location, stock_status) IN ($placeholders)");
            $st->execute($params);
            foreach ($st->fetchAll() as $s) {
                $key = $s['product_id'] . '|' . ($s['batch_number'] ?? '') . '|' . ($s['location'] ?? '') . '|' . $s['stock_status'];
                $existingStock[$key] = $s;
            }
        }

        // Pre-fetch balances for all products at once
        $balanceMap = [];
        $productIds = array_values(array_unique(array_column(array_filter($rows, fn($r) => $r['product_id'] > 0), 'product_id')));
        if ($productIds) {
            $ph = implode(',', array_fill(0, count($productIds), '?'));
            $st = $db->prepare("SELECT product_id, COALESCE(SUM(quantity),0) as bal FROM stock WHERE product_id IN ($ph) AND stock_status='Available' GROUP BY product_id");
            $st->execute($productIds);
            foreach ($st->fetchAll() as $b) $balanceMap[$b['product_id']] = $b['bal'];
        }

        // Prepare batch statements
        $stmtStockInsert = $db->prepare("INSERT INTO stock (product_id, batch_number, location, quantity, uom, pallet, manufacture_date, expiry_date, stock_status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
        $stmtStockUpdate = $db->prepare("UPDATE stock SET quantity=?, pallet=?, manufacture_date=?, expiry_date=?, uom=?, updated_at=NOW() WHERE id=?");
        $stmtLedger = $db->prepare("INSERT INTO stock_ledger (transaction_date, product_id, batch_number, transaction_type, quantity_in, quantity_out, uom, pallet, reference_number, reference_type, balance, location, notes) VALUES (CURDATE(), ?, ?, 'IN', ?, 0, ?, ?, ?, 'Stock Import', ?, ?, ?)");

        foreach ($rows as $row) {
            if (!empty($row['_errors'])) { $skipped++; continue; }

            $row['product_id'] = (int)($row['product_id'] ?? 0);

            if ($row['product_id'] <= 0 && !empty($row['_auto_create'])) {
                $code = !empty($row['sku_code']) ? $row['sku_code'] : $row['product_code'];
                $name = !empty($row['description']) ? $row['description'] : $row['product_name'];
                $uomType = $row['uom'] ?? 'Drum';
                $st = $db->prepare("INSERT INTO products (product_code, product_name, description, uom_type, uom_per_pallet, liters_per_unit, max_sku_qty, max_trans_qty, reorder_level, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, 1)");
                $st->execute([$code, $name, $row['description'] ?: null, $uomType, import_uom_per_pallet($uomType, 0), 209.00, 44, 80]);
                $row['product_id'] = (int)$db->lastInsertId();
                $row['uom_per_pallet'] = import_uom_per_pallet($uomType, 0);
                $row['pallet'] = (int)ceil($row['quantity'] / $row['uom_per_pallet']);
                $autoCreated++;
            }

            if ($row['product_id'] <= 0) { $skipped++; continue; }

            if (!empty($row['location'])) $ensureLocation((string)$row['location']);

            $key = $row['product_id'] . '|' . ($row['batch_number'] ?? '') . '|' . ($row['location'] ?? '') . '|' . $row['stock_status'];
            $existing = $existingStock[$key] ?? null;

            $uomPerPallet = $row['uom_per_pallet'] ?? 4;
            $pallet = $row['pallet'] ?? (int)ceil($row['quantity'] / $uomPerPallet);

            if ($mode === 'replace' && $existing) {
                $stmtStockUpdate->execute([$row['quantity'], $pallet, $row['manufacture_date'], $row['expiry_date'], $row['uom'], (int)$existing['id']]);
                $imported++;
                continue;
            }

            if ($mode === 'add' && $existing) {
                $newQty  = (float)($existing['quantity'] ?? 0) + $row['quantity'];
                $newPlt  = (int)ceil($newQty / $uomPerPallet);
                $stmtStockUpdate->execute([$newQty, $newPlt, $row['manufacture_date'], $row['expiry_date'], $row['uom'], (int)$existing['id']]);
                $imported++;
                continue;
            }

            if ($mode === 'skip' && $existing) { $skipped++; continue; }

            // Pickface collision check: reject stock targeting a bin claimed by another SKU's pickface
            if (!empty($row['location'])) {
                $conflictStmt = $db->prepare(
                    "SELECT sku_id, 'outbound' AS bin_type FROM sku_pickface_config
                     WHERE pickface_bin_id = (SELECT id FROM location_master WHERE location_code = ?) AND sku_id != ?
                     UNION ALL
                     SELECT sku_id, 'inbound' AS bin_type FROM sku_pickface_config
                     WHERE inbound_pickface_bin_id = (SELECT id FROM location_master WHERE location_code = ?) AND sku_id != ?"
                );
                $conflictStmt->execute([$row['location'], $row['product_id'], $row['location'], $row['product_id']]);
                $conflict = $conflictStmt->fetch();

                if ($conflict) {
                    $importErrors[] = [
                        'product_id' => $row['product_id'],
                        'location'   => $row['location'],
                        'error'      => "Bin {$row['location']} is already the {$conflict['bin_type']} pickface for SKU #{$conflict['sku_id']} — this row was skipped, not imported.",
                    ];
                    $skipped++;
                    continue;
                }
            }

            $stmtStockInsert->execute([
                $row['product_id'],
                $row['batch_number'] ?: null,
                $row['location'] ?: null,
                $row['quantity'],
                $row['uom'],
                $pallet,
                $row['manufacture_date'] ?: null,
                $row['expiry_date'] ?: null,
                $row['stock_status'],
            ]);
            $stockId = (int)$db->lastInsertId();

            $refNo  = $refPrefix . str_pad($imported + 1, 4, '0', STR_PAD_LEFT);
            $balance = $balanceMap[$row['product_id']] ?? 0;

            $stmtLedger->execute([
                $row['product_id'],
                $row['batch_number'] ?: null,
                $row['quantity'],
                $row['uom'],
                $pallet,
                $refNo,
                $balance,
                $row['location'] ?: null,
                $row['notes'] ?: 'Direct stock import',
            ]);

            $imported++;
        }

        if ($ownsTransaction) $db->commit();
        return ['imported' => $imported, 'skipped' => $skipped, 'auto_created' => $autoCreated, 'auto_locations' => $autoLocations, 'import_errors' => $importErrors];
    } catch (\Throwable $e) {
        if ($ownsTransaction && $db->inTransaction()) $db->rollBack();
        throw $e;
    }
}
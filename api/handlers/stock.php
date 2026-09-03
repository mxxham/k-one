<?php

function handle_stock($action) {
    switch ($action) {
        case 'list':
            api_require_auth();
            $status = query('status') ?: null;
            $expiring = query('expiring') === '1' || query('expiring') === 'true';
            $search = trim(query('q') ?: '');
            $location = trim(query('location') ?: '');
            $yearRaw = trim(query('year') ?: '');
            $year = preg_match('/^\d{4}$/', $yearRaw) ? (int)$yearRaw : null;
            $allRows = Stock::getAll($status, $expiring, $year);
            if ($search) {
                $allRows = array_values(array_filter($allRows, function ($r) use ($search) {
                    $needle = strtolower($search);
                    return strpos(strtolower($r['product_code'] ?? ''), $needle) !== false
                        || strpos(strtolower($r['product_name'] ?? ''), $needle) !== false
                        || strpos(strtolower($r['batch_number'] ?? ''), $needle) !== false
                        || strpos(strtolower($r['location'] ?? ''), $needle) !== false;
                }));
            }
            if ($location) {
                $allRows = array_values(array_filter($allRows, fn($r) => strcasecmp($r['location'] ?? '', $location) === 0));
            }
            $total = count($allRows);
            [$page, $perPage] = page_params(50);
            $offset = ($page - 1) * $perPage;
            $rows = array_slice($allRows, $offset, $perPage);
            json_out(['rows' => $rows, 'summary' => Stock::getSummary()] + paginationMeta($total, $page, $perPage));
            break;

        case 'list_grouped':
            api_require_auth();
            $status = query('status') ?: null;
            $expiring = query('expiring') === '1' || query('expiring') === 'true';
            $search = trim(query('q') ?: '');
            $location = trim(query('location') ?: '');
            $yearRaw = trim(query('year') ?: '');
            $year = preg_match('/^\d{4}$/', $yearRaw) ? (int)$yearRaw : null;
            $allRows = Stock::getAll($status, $expiring, $year);
            if ($search) {
                $allRows = array_values(array_filter($allRows, function ($r) use ($search) {
                    $needle = strtolower($search);
                    return strpos(strtolower($r['product_code'] ?? ''), $needle) !== false
                        || strpos(strtolower($r['product_name'] ?? ''), $needle) !== false
                        || strpos(strtolower($r['batch_number'] ?? ''), $needle) !== false;
                }));
            }
            if ($location) {
                $allRows = array_values(array_filter($allRows, fn($r) => stripos($r['location'] ?? '', $location) === 0));
            }
            // Group by product_code
            $grouped = [];
            foreach ($allRows as $r) {
                $key = $r['product_code'] ?? '';
                if (!isset($grouped[$key])) {
                    $grouped[$key] = [
                        'product_id'       => $r['product_id'],
                        'product_code'     => $r['product_code'],
                        'product_name'     => $r['product_name'],
                        'category'         => $r['category'] ?? null,
                        'uom_type'         => $r['uom_type'] ?? null,
                        'uom_per_pallet'   => $r['uom_per_pallet'] ?? null,
                        'velocity_class'   => $r['velocity_class'] ?? null,
                        'total_qty'        => 0,
                        'total_pallet'     => 0,
                        'location_count'   => 0,
                        'batch_count'      => 0,
                        'batches'          => [],
                        'earliest_expiry'  => null,
                        'latest_expiry'    => null,
                        'statuses'         => [],
                        'has_hold'         => false,
                    ];
                }
                $g = &$grouped[$key];
                $g['total_qty']    += floatval($r['quantity'] ?? 0);
                $g['total_pallet'] += floatval($r['pallet'] ?? 0);
                $g['location_count']++;
                $batch = $r['batch_number'] ?? '';
                if ($batch && !in_array($batch, $g['batches'])) {
                    $g['batches'][] = $batch;
                    $g['batch_count'] = count($g['batches']);
                }
                $exp = $r['expiry_date'] ?? null;
                if ($exp) {
                    if (!$g['earliest_expiry'] || $exp < $g['earliest_expiry']) $g['earliest_expiry'] = $exp;
                    if (!$g['latest_expiry'] || $exp > $g['latest_expiry']) $g['latest_expiry'] = $exp;
                }
                $st = $r['stock_status'] ?? '';
                if ($st && !in_array($st, $g['statuses'])) $g['statuses'][] = $st;
                if (!empty($r['hold_status']) && $r['hold_status'] !== 'available') $g['has_hold'] = true;
                unset($g);
            }
            $grouped = array_values($grouped);
            $total = count($grouped);
            [$page, $perPage] = page_params(50);
            $offset = ($page - 1) * $perPage;
            $rows = array_slice($grouped, $offset, $perPage);
            json_out(['rows' => $rows, 'summary' => Stock::getSummary()] + paginationMeta($total, $page, $perPage));
            break;

        case 'summary':
            api_require_auth();
            json_out(['summary' => Stock::getSummary()]);
            break;

        case 'expiring':
            api_require_auth();
            $days = (int)query('days', 90);
            json_out(['rows' => Stock::getExpiringSoon($days)]);
            break;

        case 'by_location':
            api_require_auth();
            json_out(['rows' => Stock::getStockByLocation()]);
            break;

        case 'detail':
            api_require_auth();
            $id = (int)query('id');
            json_out(['stock' => (Stock::getById($id) ?: null)]);
            break;

        case 'locations':
            api_require_auth();
            $db = db();
            $rows = $db->query("SELECT DISTINCT location FROM stock WHERE location IS NOT NULL AND location != '' AND quantity > 0 ORDER BY location")->fetchAll();
            json_out(['rows' => array_map(fn($r) => $r['location'], $rows)]);
            break;

        case 'transfer':
            api_require_write();
            $data = body();
            $stockId = (int)($data['stock_id'] ?? 0);
            $newLocation = strtoupper(trim($data['to_location'] ?? ''));
            $quantity = isset($data['quantity']) && $data['quantity'] !== '' ? (float)$data['quantity'] : null;
            if (!$stockId || !$newLocation) json_err('stock_id dan to_location wajib diisi.');
            $st = Stock::getById($stockId);
            if (!$st) json_err('Stock tidak ditemukan.');
            Stock::transfer($stockId, $newLocation, $quantity);
            ActivityLogger::log('STOCK_TRANSFER', 'stock', 'Stock', $stockId, null,
                "Transfer stok ID $stockId → $newLocation qty " . ($quantity ?? 'all'));
            json_out(['ok' => true]);
            break;

        case 'adjust':
            api_require_admin();
            $data = body();
            $stockId = (int)($data['stock_id'] ?? 0);
            $newQty = (float)($data['quantity'] ?? 0);
            $reason = trim($data['reason'] ?? '');
            if (!$stockId) json_err('stock_id wajib diisi.');
            if ($newQty < 0) json_err('Quantity tidak boleh negatif.');
            $st = Stock::getById($stockId);
            if (!$st) json_err('Stock tidak ditemukan.');
            Stock::adjust($stockId, $newQty, $reason);
            ActivityLogger::log('STOCK_ADJUST', 'stock', 'Stock', $stockId, null,
                "Adjust stok ID $stockId → $newQty (" . ($reason ?: 'no reason') . ')');
            json_out(['ok' => true]);
            break;

        case 'hold':
            api_require_write();
            $data = body();
            $stockId = (int)($data['stock_id'] ?? 0);
            $status = strtolower(trim($data['status'] ?? ''));
            $reason = trim($data['reason'] ?? '');
            $holdStatuses = ['on_hold', 'quarantine', 'damaged'];
            if (!$stockId) json_err('stock_id wajib diisi.');
            if (!in_array($status, $holdStatuses, true)) {
                json_err('status harus salah satu dari: ' . implode(', ', $holdStatuses));
            }
            if ($reason === '') json_err('Alasan hold wajib diisi.');
            $stock = Stock::getById($stockId);
            if (!$stock) json_err('Stock tidak ditemukan.');
            if (($stock['hold_status'] ?? 'available') === $status) {
                json_err("Stock sudah berstatus {$status}.");
            }

            $db = db();
            $db->beginTransaction();
            try {
                $db->prepare("UPDATE stock SET hold_status = ?, hold_reason = ?, hold_by = ?, hold_at = NOW(), updated_at = NOW() WHERE id = ?")
                   ->execute([$status, $reason, $_SESSION['user_id'] ?? null, $stockId]);

                $stmt = $db->prepare("SELECT COALESCE(SUM(quantity_in),0) - COALESCE(SUM(quantity_out),0) AS running_balance
                        FROM stock_ledger WHERE product_id = ?");
                $stmt->execute([$stock['product_id']]);
                $balance = (float)($stmt->fetch()['running_balance'] ?? 0);

                $db->prepare("INSERT INTO stock_ledger
                        (transaction_date, product_id, transaction_type, reference_type,
                         reference_id, reference_number, batch_number, quantity_in,
                         quantity_out, uom, balance, location, notes)
                        VALUES (CURDATE(), ?, 'HOLD', 'Stock', ?, NULL, ?, 0, 0, ?, ?, ?, ?)")
                   ->execute([
                       $stock['product_id'],
                       $stockId,
                       $stock['batch_number'] ?? null,
                       $stock['uom_type'] ?? $stock['uom'] ?? 'Drum',
                       $balance,
                       $stock['location'] ?? null,
                       "Stock {$stockId} di-hold ({$status})" . ($reason !== '' ? ": {$reason}" : '') . " oleh " . ($_SESSION['username'] ?? ''),
                   ]);
                $db->commit();
            } catch (Throwable $e) {
                if ($db->inTransaction()) $db->rollBack();
                json_err($e->getMessage());
            }
            ActivityLogger::log('STOCK_HOLD', 'stock', 'Stock', $stockId, null,
                "Hold stok ID {$stockId} → {$status} ({$reason})",
                ['hold_status' => $stock['hold_status'] ?? 'available'],
                ['hold_status' => $status]);
            json_out(['ok' => true]);
            break;

        case 'release':
            api_require_write();
            $data = body();
            $stockId = (int)($data['stock_id'] ?? 0);
            $reason = trim($data['reason'] ?? '');
            if (!$stockId) json_err('stock_id wajib diisi.');
            $stock = Stock::getById($stockId);
            if (!$stock) json_err('Stock tidak ditemukan.');
            $curStatus = $stock['hold_status'] ?? 'available';
            if ($curStatus === 'available') json_err('Stock tidak sedang di-hold.');

            $db = db();
            $db->beginTransaction();
            try {
                $db->prepare("UPDATE stock SET hold_status = 'available', hold_reason = NULL, hold_by = NULL, hold_at = NULL, updated_at = NOW() WHERE id = ?")
                   ->execute([$stockId]);

                $stmt = $db->prepare("SELECT COALESCE(SUM(quantity_in),0) - COALESCE(SUM(quantity_out),0) AS running_balance
                        FROM stock_ledger WHERE product_id = ?");
                $stmt->execute([$stock['product_id']]);
                $balance = (float)($stmt->fetch()['running_balance'] ?? 0);

                $db->prepare("INSERT INTO stock_ledger
                        (transaction_date, product_id, transaction_type, reference_type,
                         reference_id, reference_number, batch_number, quantity_in,
                         quantity_out, uom, balance, location, notes)
                        VALUES (CURDATE(), ?, 'RELEASE', 'Stock', ?, NULL, ?, 0, 0, ?, ?, ?, ?)")
                   ->execute([
                       $stock['product_id'],
                       $stockId,
                       $stock['batch_number'] ?? null,
                       $stock['uom_type'] ?? $stock['uom'] ?? 'Drum',
                       $balance,
                       $stock['location'] ?? null,
                       "Stock {$stockId} di-release" . ($reason !== '' ? ": {$reason}" : '') . " oleh " . ($_SESSION['username'] ?? ''),
                   ]);
                $db->commit();
            } catch (Throwable $e) {
                if ($db->inTransaction()) $db->rollBack();
                json_err($e->getMessage());
            }
            ActivityLogger::log('STOCK_RELEASE', 'stock', 'Stock', $stockId, null,
                "Release stok ID {$stockId}" . ($reason !== '' ? " ({$reason})" : ''),
                ['hold_status' => $curStatus],
                ['hold_status' => 'available']);
            json_out(['ok' => true]);
            break;

        case 'scan':
            api_require_auth();
            $code = trim(query('code') ?: (body()['code'] ?? ''));
            if ($code === '') json_err('Kode wajib diisi.');
            $db = db();
            $stmt = $db->prepare("SELECT p.id, p.product_code, p.product_name, p.uom_type, p.uom_per_pallet,
                        p.default_location,
                        COALESCE((SELECT s.location FROM stock s
                                  WHERE s.product_id = p.id AND s.stock_status = 'Available'
                                    AND (s.hold_status = 'available' OR s.hold_status IS NULL)
                                    AND s.quantity > 0 AND s.location NOT IN ('QUA_SHELL','STAGING')
                                  ORDER BY (s.expiry_date IS NULL) ASC, s.expiry_date ASC, s.id ASC
                                  LIMIT 1), p.default_location) AS expected_location
                    FROM products p
                    WHERE p.product_code = ? AND p.is_active = 1
                    LIMIT 1");
            $stmt->execute([$code]);
            $row = $stmt->fetch();
            if (!$row) json_out(['found' => false, 'code' => $code]);
            json_out([
                'found' => true,
                'code' => $code,
                'product' => [
                    'id' => (int)$row['id'],
                    'product_code' => $row['product_code'],
                    'product_name' => $row['product_name'],
                    'uom_type' => $row['uom_type'],
                    'uom_per_pallet' => (float)$row['uom_per_pallet'],
                    'default_location' => $row['default_location'] ?? null,
                ],
                'expected_location' => $row['expected_location'] ?? null,
            ]);
            break;

        case 'scan_override':
            api_require_write();
            $data = body();
            $code = trim($data['code'] ?? '');
            $reason = trim($data['reason'] ?? '');
            $context = trim($data['context'] ?? '');
            if ($code === '') json_err('Kode wajib diisi.');
            if ($reason === '') json_err('Alasan override wajib diisi.');
            ActivityLogger::log('SCAN_OVERRIDE', 'stock', 'Stock', null, null,
                "Scan mismatch di-override" . ($context !== '' ? " [{$context}]" : '') . ": '{$code}' — {$reason}",
                ['scanned' => $code, 'context' => $context !== '' ? $context : null],
                ['reason' => $reason]);
            json_out(['ok' => true]);
            break;

        case 'sync':
            api_require_auth();
            $lastSync = query('last_sync');
            $limit = min((int)query('limit', 100), 500);
            $db = db();

            if ($lastSync) {
                $stmt = $db->prepare("
                    SELECT s.*, p.product_name, p.product_code,
                           lm.location_code, lm.row_name as zone,
                           sl.lpn_code, sl.status as location_status
                    FROM stock s
                    JOIN products p ON p.id = s.product_id
                    JOIN location_master lm ON lm.location_code = s.location
                    LEFT JOIN stock_locations sl ON sl.stock_id = s.id
                    WHERE s.updated_at > ?
                    ORDER BY s.updated_at ASC
                    LIMIT ?
                ");
                $stmt->execute([$lastSync, $limit]);
            } else {
                $stmt = $db->prepare("
                    SELECT s.*, p.product_name, p.product_code,
                           lm.location_code, lm.row_name as zone,
                           sl.lpn_code, sl.status as location_status
                    FROM stock s
                    JOIN products p ON p.id = s.product_id
                    JOIN location_master lm ON lm.location_code = s.location
                    LEFT JOIN stock_locations sl ON sl.stock_id = s.id
                    ORDER BY s.updated_at DESC
                    LIMIT ?
                ");
                $stmt->execute([$limit]);
            }

            $stocks = $stmt->fetchAll();
            $currentSync = $db->query("SELECT NOW()")->fetchColumn();
            $total = $db->query("SELECT COUNT(*) FROM stock")->fetchColumn();

            json_out([
                'stocks' => $stocks,
                'last_sync' => $currentSync,
                'total' => (int)$total,
                'count' => count($stocks),
                'has_more' => count($stocks) >= $limit,
            ]);
            break;

        case 'reconcile':
            api_require_write();
            $data = body();
            $stockId = (int)($data['stock_id'] ?? 0);
            $actualQty = floatval($data['actual_qty'] ?? 0);
            $reason = $data['reason'] ?? '';

            if (!$stockId || !is_finite($actualQty)) {
                json_err('stock_id and actual_qty are required', 400);
            }

            $result = StockReconciliation::reconcile(
                $stockId,
                $actualQty,
                $_SESSION['user_id'] ?? 0,
                $reason
            );
            json_out($result);
            break;

        case 'reconcile_report':
            api_require_auth();
            $productId = query('product_id') ? (int)query('product_id') : null;
            $dateFrom = query('date_from');
            $dateTo = query('date_to');

            $result = StockReconciliation::getVarianceReport($productId, $dateFrom, $dateTo);
            json_out(['report' => $result]);
            break;

        case 'discrepancies':
            api_require_auth();
            $result = StockReconciliation::detectDiscrepancies();
            json_out(['discrepancies' => $result]);
            break;

        case 'zone_stats':
            api_require_auth();
            $productId = query('product_id') ? (int)query('product_id') : null;

            $result = ZoneAllocation::getZoneStats($productId);
            json_out(['zones' => $result]);
            break;

        case 'allocate_zone':
            api_require_write();
            $data = body();
            $productId = (int)($data['product_id'] ?? 0);
            $quantity = floatval($data['quantity'] ?? 0);
            $preferredZone = $data['zone'] ?? null;

            if (!$productId || !is_finite($quantity) || $quantity <= 0) {
                json_err('product_id and quantity are required', 400);
            }

            $result = ZoneAllocation::allocateByZone($productId, $quantity, $preferredZone);
            json_out(['allocation' => $result]);
            break;

        default:
            json_err('Invalid action: ' . $action, 404);
    }
}

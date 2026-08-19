<?php

function handle_stocktake($action) {
    switch ($action) {
        case 'list':
            api_require_auth();
            $limit = (int)query('limit', 200);
            $rows = StockTake::getAll($limit);
            foreach ($rows as &$r) {
                $r['id'] = (int)$r['id'];
                $r['total_items'] = (int)$r['total_items'];
                $r['plus_count'] = (int)$r['plus_count'];
                $r['minus_count'] = (int)$r['minus_count'];
                $r['clear_count'] = (int)$r['clear_count'];
            }
            unset($r);
            json_out(['rows' => $rows, 'stats' => StockTake::getStats()]);
            break;

        case 'detail':
            api_require_auth();
            $id = (int)query('id');
            $stockTake = StockTake::getById($id);
            if (!$stockTake) json_err('Stock take tidak ditemukan', 404);
            $items = StockTake::getItems($id);
            foreach ($items as &$it) $it['id'] = (int)$it['id'];
            unset($it);
            json_out([
                'stock_take' => $stockTake,
                'items' => $items,
                'accuracy' => StockTake::calculateAccuracy($id),
                'locked_locations' => StockTake::getActiveLockedLocations(),
            ]);
            break;

        case 'stats':
            api_require_auth();
            json_out(['stats' => StockTake::getStats()]);
            break;

        case 'get_locations':
            api_require_auth();
            $db = db();
            $rows = $db->query("SELECT DISTINCT location FROM stock WHERE location IS NOT NULL AND location != '' AND quantity > 0 ORDER BY location")->fetchAll();
            json_out(['rows' => array_map(fn($r) => $r['location'], $rows)]);
            break;

        case 'get_scope_locations':
            api_require_auth();
            $db = db();
            $locked = StockTake::getActiveLockedLocations();
            $rows = $db->query("SELECT DISTINCT location FROM stock WHERE location IS NOT NULL AND location NOT IN ('QUA_SHELL','STAGING') AND quantity > 0 ORDER BY location")->fetchAll();
            $available = array_values(array_filter(array_map(fn($r) => $r['location'], $rows), fn($l) => !in_array($l, $locked)));
            json_out(['locations' => $available, 'locked' => $locked]);
            break;

        case 'get_stock':
            api_require_auth();
            $productId = (int)query('product_id');
            $location = query('location') ?: null;
            $batch = query('batch') ?: null;
            if (!$productId) json_err('product_id wajib diisi.');
            $qty = StockTake::getSystemStock($productId, $location, $batch);
            $db = db();
            $stmt = $db->prepare("SELECT s.id, s.product_id, s.batch_number, s.location, s.quantity, s.uom, s.expiry_date,
                    p.product_code, p.product_name
                FROM stock s JOIN products p ON p.id = s.product_id
                WHERE s.product_id = ? AND s.quantity > 0 AND s.stock_status='Available'
                " . ($location ? "AND s.location = ?" : "") . "
                " . ($batch ? "AND s.batch_number <=> ?" : "") . "
                ORDER BY s.expiry_date");
            $params = [$productId];
            if ($location) $params[] = $location;
            if ($batch) $params[] = $batch;
            $stmt->execute($params);
            json_out(['total_qty' => $qty, 'rows' => $stmt->fetchAll()]);
            break;

        case 'create':
            api_require_write();
            $data = body();
            $id = StockTake::create($data);
            if (!$id) json_err('Gagal membuat stock take.', 500);
            if (!empty($data['scope_locations'])) {
                $locs = $data['scope_locations'];
                if (is_string($locs)) $locs = json_decode($locs, true);
                StockTake::autoLoadByLocations((int)$id, is_array($locs) ? $locs : null);
            } elseif (!empty($data['auto_load'])) {
                StockTake::autoLoadByLocations((int)$id, null);
            }
            ActivityLogger::log('CREATE_STOCKTAKE', 'stocktake', 'StockTake', (int)$id, null, 'Buat stock take ID ' . $id);
            json_out(['id' => (int)$id]);
            break;

        case 'add_item':
            api_require_write();
            $data = body();
            $stockTakeId = (int)($data['stock_take_id'] ?? 0);
            $item = $data['item'] ?? $data;
            $itemId = StockTake::addItemFull($stockTakeId, $item);
            if (!$itemId) json_err('Gagal menambah item.', 500);
            json_out(['item_id' => (int)$itemId]);
            break;

        case 'auto_load':
            api_require_write();
            $data = body();
            $stockTakeId = (int)($data['stock_take_id'] ?? query('stock_take_id'));
            $locs = $data['locations'] ?? null;
            StockTake::autoLoadByLocations($stockTakeId, is_array($locs) ? $locs : null);
            json_out(['ok' => true]);
            break;

        case 'update':
            api_require_write();
            $data = body();
            $id = (int)($data['id'] ?? 0);
            StockTake::update($id, $data);
            json_out(['id' => $id]);
            break;

        case 'delete_item':
            api_require_write();
            $data = body();
            $itemId = (int)($data['item_id'] ?? query('item_id'));
            db()->prepare("DELETE FROM stock_take_items WHERE id=?")->execute([$itemId]);
            json_out(['item_id' => $itemId]);
            break;

        case 'delete':
            api_require_write();
            $data = body();
            $id = (int)($data['id'] ?? query('id'));
            StockTake::delete($id);
            ActivityLogger::log('DELETE_STOCKTAKE', 'stocktake', 'StockTake', $id, null, 'Hapus stock take ID ' . $id);
            json_out(['id' => $id]);
            break;

        case 'start_counting':
            api_require_write();
            $data = body();
            $id = (int)($data['id'] ?? query('id'));
            StockTake::startCounting($id);
            json_out(['id' => $id]);
            break;

        case 'save_counters':
            api_require_write();
            $data = body();
            $id = (int)($data['id'] ?? 0);
            StockTake::saveCounters($id, $data['counters'] ?? []);
            json_out(['id' => $id]);
            break;

        case 'advance_to_c2':
            api_require_write();
            $data = body();
            $id = (int)($data['id'] ?? 0);
            StockTake::advanceToC2($id, $data['counters'] ?? []);
            json_out(['id' => $id]);
            break;

        case 'finish_counting':
            api_require_write();
            $data = body();
            $id = (int)($data['id'] ?? 0);
            StockTake::finishCounting($id, $data['counters'] ?? []);
            json_out(['id' => $id]);
            break;

        case 'save_review':
            api_require_write();
            $data = body();
            $id = (int)($data['id'] ?? 0);
            StockTake::saveReview($id, $data['physicals'] ?? []);
            json_out(['id' => $id]);
            break;

        case 'apply_adjustment':
            api_require_admin();
            $data = body();
            $id = (int)($data['id'] ?? query('id'));
            StockTake::applyAdjustment($id);
            ActivityLogger::log('APPLY_STOCKTAKE_ADJUSTMENT', 'stocktake', 'StockTake', $id, null, 'Apply adjustment stock take ID ' . $id);
            json_out(['id' => $id]);
            break;

        default:
            json_err('Invalid action: ' . $action, 404);
    }
}

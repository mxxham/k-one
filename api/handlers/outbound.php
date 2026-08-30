<?php

function handle_outbound($action) {
    switch ($action) {

        case 'list':
            api_require_auth();
            [$page, $perPage, $offset] = page_params(50);
            $status = query('status') ?: null;
            $odNo = trim(query('od_no') ?: '');
            $search = trim(query('search') ?: '');
            $total = Outbound::countAll($status, $odNo ?: null, $search ?: null);
            $rows = Outbound::getAll($status, $perPage, $offset, $odNo ?: null, $search ?: null);
            foreach ($rows as &$r) {
                $r['id'] = (int)$r['id'];
                $r['display_order_no'] = Outbound::displayOrderNo($r);
            }
            unset($r);
            json_out(['rows' => $rows, 'statuses' => statuses_for('outbound')] + paginationMeta($total, $page, $perPage));
            break;

        case 'detail':
            api_require_auth();
            $id = (int)query('id');
            $order = Outbound::getById($id);
            if (!$order) json_err('Outbound tidak ditemukan', 404);
            $order['display_order_no'] = Outbound::displayOrderNo($order);
            $items = Outbound::getItems($id);
            foreach ($items as &$it) {
                $it['id'] = (int)$it['id'];
                $it['picked_locations'] = Outbound::getItemPickedLocations($it['id']);
            }
            unset($it);
            json_out([
                'order' => $order,
                'items' => $items,
                'destinations' => outbound_destinations($id),
                'customers' => customers_options(),
                'products' => products_options(),
            ]);
            break;

        case 'stats':
            api_require_auth();
            json_out(['stats' => Outbound::getStats()]);
            break;

        case 'search_products':
            api_require_auth();
            json_out(['results' => search_products_json(query('q'))]);
            break;

        case 'check_stock':
            api_require_auth();
            $pid = (int)query('product_id');
            $qty = (float)query('quantity');
            $loc = query('location') ?: null;
            $avail = Outbound::getTotalAvailableQty($pid);
            $fefo = Outbound::getFEFOAllocation($pid, $qty, $loc);
            json_out(['available' => $avail, 'fefo' => $fefo]);
            break;

        case 'create':
            api_require_write();
            $data = body();
            $rawItems = $data['items'] ?? [];
            $validItems = [];
            $skippedItems = [];
            $dbChk = db();
            foreach ($rawItems as $item) {
                $pid = (int)($item['product_id'] ?? 0);
                if (!$pid) continue;
                $avail = Outbound::getTotalAvailableQty($pid);
                if ($avail <= 0) {
                    $pRow = $dbChk->prepare("SELECT product_code, product_name FROM products WHERE id=?");
                    $pRow->execute([$pid]);
                    $p = $pRow->fetch();
                    $skippedItems[] = ($p['product_code'] ?? 'ID:' . $pid) . ' – ' . ($p['product_name'] ?? 'Unknown');
                } else {
                    $validItems[] = $item;
                }
            }
            if (!empty($rawItems) && empty($validItems)) {
                json_err('Semua produk tidak ada di stok. Order tidak dibuat. Dilewati: ' . implode(', ', $skippedItems), 409);
            }
            $data['items'] = $validItems;
            $id = Outbound::create($data);
            if (!empty($data['destinations'])) {
                $d = $data['destinations'];
                Outbound::saveDestinations(
                    (int)$id,
                    array_column($d, 'ship_to_name'),
                    array_column($d, 'ship_to_location'),
                    array_column($d, 'ship_to_street'),
                    array_column($d, 'kota'),
                    array_column($d, 'notes')
                );
            }
            ActivityLogger::log('CREATE_OUTBOUND', 'outbound', 'Outbound', (int)$id,
                null, 'Buat outbound, customer ID ' . ($data['customer_id'] ?? '—') . ', SO: ' . ($data['so_number'] ?? '—'));
            json_out(['id' => (int)$id, 'warnings' => $skippedItems]);
            break;

        case 'update':
            api_require_write();
            $data = body();
            $id = (int)($data['id'] ?? 0);
            $obCheck = Outbound::getById($id);
            if (($obCheck['status'] ?? '') === 'Completed') json_err('Order sudah Completed dan tidak dapat diedit.', 409);
            Outbound::update($id, $data);
            if (!empty($data['destinations'])) {
                $d = $data['destinations'];
                Outbound::saveDestinations(
                    (int)$id,
                    array_column($d, 'ship_to_name'),
                    array_column($d, 'ship_to_location'),
                    array_column($d, 'ship_to_street'),
                    array_column($d, 'kota'),
                    array_column($d, 'notes')
                );
            }
            ActivityLogger::log('UPDATE_OUTBOUND', 'outbound', 'Outbound', $id, null, 'Edit outbound ID ' . $id);
            json_out(['id' => $id]);
            break;

        case 'add_item':
            api_require_write();
            $data = body();
            $outboundId = (int)($data['outbound_id'] ?? 0);
            $obCheck = Outbound::getById($outboundId);
            if (($obCheck['status'] ?? '') === 'Completed') json_err('Order sudah Completed dan tidak dapat diedit.', 409);
            $item = $data['item'] ?? $data;
            $item['manual_location'] = $data['manual_location'] ?? null;
            $item['manual_locs'] = $data['manual_locs'] ?? null;
            try {
                $newItemId = Outbound::addItemWithFEFO($outboundId, $item);
            } catch (Exception $e) {
                json_err($e->getMessage(), 409);
            }
            outbound_attach_destination($outboundId, $newItemId, $item);
            ActivityLogger::log('ADD_OUTBOUND_ITEM', 'outbound', 'Outbound', $outboundId,
                null, 'Tambah item produk ID ' . ($item['product_id'] ?? '?') . ' qty ' . ($item['quantity'] ?? 0));
            json_out(['item_id' => (int)$newItemId, 'outbound_id' => $outboundId]);
            break;

        case 'pick_items':
            api_require_write();
            $data = body();
            $id = (int)($data['id'] ?? query('id'));
            $ob = Outbound::getById($id);
            if (!$ob) json_err('Outbound tidak ditemukan', 404);
            if (empty($ob['expected_date'])) json_err('Expected Date wajib diisi sebelum Pick Items.', 409);
            try {
                Outbound::pickItems($id);
            } catch (Exception $e) {
                json_err($e->getMessage(), 409);
            }
            ActivityLogger::log('PICK_OUTBOUND', 'outbound', 'Outbound', $id, $ob['order_number'] ?? null, 'Pick outbound ' . ($ob['order_number'] ?? $id));
            json_out(['id' => $id]);
            break;

        case 'ship':
            api_require_write();
            $data = body();
            $id = (int)($data['id'] ?? query('id'));
            $ob = Outbound::getById($id);
            Outbound::ship($id);
            ActivityLogger::log('SHIP_OUTBOUND', 'outbound', 'Outbound', $id, $ob['order_number'] ?? null, 'Kirim outbound ' . ($ob['order_number'] ?? $id));
            json_out(['id' => $id]);
            break;

        case 'complete':
            api_require_write();
            $data = body();
            $id = (int)($data['id'] ?? query('id'));
            $ob = Outbound::getById($id);
            Outbound::complete($id);
            ActivityLogger::log('COMPLETE_OUTBOUND', 'outbound', 'Outbound', $id, $ob['order_number'] ?? null, 'Selesai outbound ' . ($ob['order_number'] ?? $id));
            json_out(['id' => $id]);
            break;

        case 'delete':
            api_require_write();
            $data = body();
            $id = (int)($data['id'] ?? query('id'));
            $ob = Outbound::getById($id);
            if (in_array($ob['status'] ?? '', ['Completed', 'Cancelled', 'Shipped', 'Delivered'])) {
                json_err('Order sudah ' . $ob['status'] . ' dan tidak dapat dihapus.', 409);
            }
            Outbound::delete($id);
            ActivityLogger::log('DELETE_OUTBOUND', 'outbound', 'Outbound', $id, $ob['order_number'] ?? null, 'Hapus outbound ' . ($ob['order_number'] ?? $id));
            json_out(['id' => $id]);
            break;

        case 'delete_item':
            api_require_write();
            $data = body();
            $outboundId = (int)($data['outbound_id'] ?? 0);
            $itemId = (int)($data['item_id'] ?? 0);
            $obCheck = Outbound::getById($outboundId);
            if (($obCheck['status'] ?? '') === 'Completed') json_err('Order sudah Completed dan tidak dapat diedit.', 409);
            Outbound::deleteItem($itemId);
            ActivityLogger::log('DELETE_OUTBOUND_ITEM', 'outbound', 'Outbound', $outboundId, null, "Hapus item ID $itemId dari outbound ID $outboundId");
            json_out(['item_id' => $itemId]);
            break;

        case 'update_item_status':
            api_require_write();
            $data = body();
            $iid = (int)($data['item_id'] ?? 0);
            $outboundId = (int)($data['outbound_id'] ?? 0);
            $newSt = trim($data['status'] ?? '');
            if (!in_array($newSt, ['Goods Received', 'ATP', 'Unserviceable'])) json_err('Status tidak valid.');
            outbound_change_item_status($iid, $outboundId, $newSt);
            json_out(['item_id' => $iid, 'status' => $newSt]);
            break;

        default:
            json_err('Invalid action: ' . $action, 404);
    }
}

function outbound_destinations(int $outboundId): array {
    $stmt = db()->prepare("SELECT * FROM outbound_destinations WHERE outbound_id=? ORDER BY seq");
    $stmt->execute([$outboundId]);
    return $stmt->fetchAll();
}

function outbound_attach_destination(int $outboundId, int $newItemId, array $item): void {
    $itemShipToName = trim($item['item_ship_to_name'] ?? $item['ship_to_name'] ?? '');
    $itemShipToLoc = trim($item['item_ship_to_location'] ?? $item['ship_to_location'] ?? '');
    $itemShipToStreet = trim($item['item_ship_to_street'] ?? $item['ship_to_street'] ?? '');
    if (empty($itemShipToName) && empty($itemShipToLoc)) return;

    $db2 = db();
    $lastItemStmt = $db2->prepare("SELECT id FROM outbound_items WHERE outbound_order_id=? ORDER BY id DESC LIMIT 1");
    $lastItemStmt->execute([$outboundId]);
    $lastItem = $lastItemStmt->fetch();

    $seqStmt = $db2->prepare("SELECT COALESCE(MAX(seq),0)+1 as next_seq FROM outbound_destinations WHERE outbound_id=?");
    $seqStmt->execute([$outboundId]);
    $nextSeq = $seqStmt->fetch()['next_seq'] ?? 1;

    $existsDest = $db2->prepare("SELECT id FROM outbound_destinations WHERE outbound_id=? AND ship_to_name=? LIMIT 1");
    $existsDest->execute([$outboundId, $itemShipToName]);
    $existingDest = $existsDest->fetch();
    if (!$existingDest) {
        try {
            $db2->prepare("INSERT INTO outbound_destinations (outbound_id, seq, ship_to_name, ship_to_location, kota, ship_to_street, notes) VALUES (?,?,?,?,?,?,?)")
                ->execute([$outboundId, $nextSeq, $itemShipToName, $itemShipToLoc, $itemShipToLoc, $itemShipToStreet, null]);
            $newDestId = (int)$db2->lastInsertId();
            if ($lastItem) $db2->prepare("UPDATE outbound_items SET destination_id=? WHERE id=?")->execute([$newDestId, $lastItem['id']]);
        } catch (PDOException $e) {}
    } elseif ($lastItem) {
        try {
            $db2->prepare("UPDATE outbound_items SET destination_id=? WHERE id=?")->execute([$existingDest['id'], $lastItem['id']]);
        } catch (PDOException $e) {}
    }
}

/** Ported from outbound.php POST update_ob_item_status */
function outbound_change_item_status(int $iid, int $outboundId, string $newSt): void {
    $db = db();
    $itRow = $db->prepare("SELECT oi.*, oo.status AS order_status
        FROM outbound_items oi JOIN outbound_orders oo ON oo.id = oi.outbound_order_id WHERE oi.id = ?");
    $itRow->execute([$iid]);
    $it = $itRow->fetch();
    if (!$it) json_err('Item tidak ditemukan', 404);

    if ($newSt === 'ATP' && ($it['in_process_status'] ?? '') !== 'ATP') {
        $stockCheck = $db->prepare(
            "SELECT COUNT(*) FROM stock
             WHERE product_id = ? AND batch_number <=> COALESCE(?, ?)
               AND stock_status = 'Available' AND quantity > 0
               AND (location IS NULL OR location NOT IN ('QUA_SHELL','STAGING'))"
        );
        $stockCheck->execute([$it['product_id'], $it['batch_number'] ?? null, $it['batch_no'] ?? null]);
        if ($stockCheck->fetchColumn() == 0) {
            json_err('Inbound belum ATP. Stock belum tersedia untuk item ini.', 409);
        }
    }

    $db->beginTransaction();
    try {
        if ($newSt === 'Unserviceable') {
            if (in_array($it['order_status'] ?? '', ['Picking', 'Shipped'])) {
                $batch = $it['batch_no'] ?? $it['batch_number'] ?? null;
                $qty = floatval($it['actual_qty'] ?? $it['quantity'] ?? 0);
                $loc = $it['location'] ?? null;
                if ($loc && $loc !== 'QUA_SHELL') {
                    $db->prepare("UPDATE stock SET quantity = GREATEST(0, quantity - ?)
                        WHERE product_id=? AND batch_number<=>? AND location=? AND stock_status='Available'")
                        ->execute([$qty, $it['product_id'], $batch, $loc]);
                    $db->prepare("DELETE FROM stock WHERE quantity<=0 AND location=? AND stock_status='Available'")->execute([$loc]);
                }
                $db->prepare("INSERT INTO stock (product_id, batch_number, location, quantity, uom, pallet, stock_status)
                    VALUES (?,?, 'QUA_SHELL', ?,?,?, 'Rejected')
                    ON DUPLICATE KEY UPDATE quantity=quantity+VALUES(quantity), pallet=pallet+VALUES(pallet)")
                    ->execute([$it['product_id'], $batch, $qty, $it['uom'] ?? 'Drum', floatval($it['pallet'] ?? 0)]);
                $db->prepare("UPDATE outbound_items SET location='QUA_SHELL' WHERE id=?")->execute([$iid]);
                $db->prepare("DELETE FROM outbound_item_locations WHERE outbound_item_id=?")->execute([$iid]);
            }
        }
        if ($newSt === 'ATP' && ($it['in_process_status'] ?? '') === 'Unserviceable') {
            $batch = $it['batch_no'] ?? $it['batch_number'] ?? null;
            $qty = floatval($it['actual_qty'] ?? $it['quantity'] ?? 0);
            $db->prepare("UPDATE stock SET quantity=GREATEST(0,quantity-?)
                WHERE product_id=? AND batch_number<=>? AND location='QUA_SHELL' AND stock_status='Rejected'")
                ->execute([$qty, $it['product_id'], $batch]);
            $db->prepare("DELETE FROM stock WHERE quantity<=0 AND stock_status='Rejected'")->execute();
            $db->prepare("UPDATE outbound_items SET location=NULL WHERE id=? AND location='QUA_SHELL'")->execute([$iid]);
        }
        $db->prepare("UPDATE outbound_items SET in_process_status=? WHERE id=?")->execute([$newSt, $iid]);
        $db->commit();
    } catch (Exception $e) {
        $db->rollBack();
        json_err($e->getMessage(), 500);
    }

    ActivityLogger::log('UPDATE_OB_ITEM_STATUS', 'outbound', 'Outbound', $outboundId, null,
        "Status outbound item ID $iid → $newSt");
}

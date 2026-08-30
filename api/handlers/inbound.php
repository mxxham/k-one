<?php

function handle_inbound($action) {
    switch ($action) {

        case 'list':
            api_require_auth();
            [$page, $perPage, $offset] = page_params(50);
            $status = query('status') ?: null;
            $odNo = trim(query('od_no') ?: '');
            $total = Inbound::countAll($status, $odNo ?: null);
            $rows = Inbound::getAll($status, $perPage, $offset, $odNo ?: null);
            foreach ($rows as &$r) {
                $r['id'] = (int)$r['id'];
            }
            unset($r);
            json_out(['rows' => $rows, 'statuses' => statuses_for('inbound')] + paginationMeta($total, $page, $perPage));
            break;

        case 'detail':
            api_require_auth();
            $id = (int)query('id');
            $order = Inbound::getById($id);
            if (!$order) json_err('Inbound tidak ditemukan', 404);
            $items = Inbound::getItems($id);
            $locations = Inbound::getOrderLocations($id);
            $itemPalletCounts = [];
            foreach ($items as &$it) {
                $locs = Inbound::getItemLocations($it['id']);
                $itemPalletCounts[(int)$it['id']] = !empty($locs) ? count($locs) : (int)ceil($it['pallet'] ?? 0);
                $it['pallet_locations'] = $locs;
                $it['id'] = (int)$it['id'];
            }
            unset($it);
            $crossDockOrders = db()->query("
                SELECT o.id, o.order_number, o.so_number, o.do_number, c.customer_name,
                       COALESCE(o.so_number, o.do_number, o.order_number) AS display_no
                FROM outbound_orders o
                LEFT JOIN customers c ON o.customer_id = c.id
                WHERE o.status IN ('Open','Picking','Picked')
                ORDER BY o.order_number")->fetchAll();
            json_out([
                'order' => $order,
                'items' => $items,
                'locations' => $locations,
                'item_pallet_counts' => $itemPalletCounts,
                'users' => active_users_list(),
                'products' => products_options(),
                'cross_dock_orders' => $crossDockOrders,
                'putaway_task' => Putaway::getInboundOpenTask($id),
            ]);
            break;

        case 'stats':
            api_require_auth();
            json_out(['stats' => Inbound::getStats()]);
            break;

        case 'search_products':
            api_require_auth();
            json_out(['results' => search_products_json(query('q'))]);
            break;

        case 'scan':
            api_require_auth();
            $code = trim(query('product_code') ?: query('code') ?: '');
            if ($code === '') json_err('product_code wajib diisi');
            $result = Stock::scan($code);
            if (!$result) json_err('Produk tidak ditemukan.', 404);
            $inboundId = (int)(query('inbound_id') ?: 0);
            $asnExpectedQty = null;
            $asnUom = null;
            $asnLinked = false;
            if ($inboundId > 0) {
                $asnSt = db()->prepare("SELECT asn_id FROM inbound_orders WHERE id=?");
                $asnSt->execute([$inboundId]);
                $asnId = (int)$asnSt->fetchColumn();
                if ($asnId > 0) {
                    $asnLinked = true;
                    $aiSt = db()->prepare("SELECT expected_qty, uom FROM asn_items WHERE asn_id=? AND product_id=? LIMIT 1");
                    $aiSt->execute([$asnId, $result['product']['id']]);
                    $asnRow = $aiSt->fetch();
                    if ($asnRow) {
                        $asnExpectedQty = (float)$asnRow['expected_qty'];
                        $asnUom = $asnRow['uom'];
                    }
                }
            }
            $result['asn_expected_qty'] = $asnExpectedQty;
            $result['asn_uom'] = $asnUom;
            $result['asn_linked'] = $asnLinked;
            json_out($result);
            break;

        case 'create':
            $user = api_require_write();
            $data = body();
            $data['items'] = $data['items'] ?? [];
            $data['created_by'] = $user['id'];
            try {
                $id = Inbound::create($data);
                ActivityLogger::log('CREATE_INBOUND', 'inbound', 'Inbound', (int)$id,
                    null, 'Buat inbound baru, PO: ' . ($data['po_number'] ?? '—'));
                json_out(['id' => (int)$id, 'order_number' => Inbound::getById($id)['order_number'] ?? null]);
            } catch (Throwable $e) {
                $code = $e->getCode() ?: 400;
                json_err($e->getMessage(), $code);
            }
            break;

        case 'update':
            api_require_write();
            $data = body();
            $id = (int)($data['id'] ?? 0);
            if (!$id) json_err('ID wajib diisi');
            $curStatus = db()->prepare("SELECT status FROM inbound_orders WHERE id=?");
            $curStatus->execute([$id]);
            $data['status'] = $curStatus->fetchColumn() ?: 'Draft';
            Inbound::update($id, $data);
            ActivityLogger::log('UPDATE_INBOUND', 'inbound', 'Inbound', $id, null, 'Edit inbound ID ' . $id);
            json_out(['id' => $id]);
            break;

        case 'delete':
            api_require_write();
            $id = (int)query('id');
            $delStatus = db()->prepare("SELECT status FROM inbound_orders WHERE id=?");
            $delStatus->execute([$id]);
            if (in_array($delStatus->fetchColumn(), ['Completed', 'Cancelled'])) {
                json_err('Order sudah selesai/dibatalkan dan tidak dapat dihapus.', 409);
            }
            Inbound::delete($id);
            ActivityLogger::log('DELETE_INBOUND', 'inbound', 'Inbound', $id, null, 'Hapus inbound ID ' . $id);
            json_out(['id' => $id]);
            break;

        case 'add_item':
            api_require_write();
            $data = body();
            $inboundId = (int)($data['inbound_id'] ?? 0);
            if (!$inboundId) json_err('inbound_id wajib diisi');
            $item = $data['item'] ?? $data;
            $inProcess = $item['in_process_status'] ?? 'Dues In';
            $stockStatusMap = [
                'Dues In' => 'Pending',
                'Goods Received' => 'Pending',
                'ATP' => 'Accepted',
                'Unserviceable' => 'Rejected',
            ];
            $item['stock_status'] = $stockStatusMap[$inProcess] ?? 'Pending';
            if ($inProcess === 'Unserviceable') $item['location'] = 'QUA_SHELL';
            $item['pallet_locations'] = $data['pallet_locations'] ?? $item['pallet_locations'] ?? [];
            $itemId = Inbound::addItem($inboundId, $item);
            ActivityLogger::log('ADD_INBOUND_ITEM', 'inbound', 'Inbound', $inboundId,
                null, 'Tambah item produk ID ' . ($item['product_id'] ?? '?') . ', qty ' . ($item['quantity'] ?? 0));
            json_out(['item_id' => (int)$itemId, 'inbound_id' => $inboundId]);
            break;

        case 'update_item':
            api_require_write();
            $data = body();
            $itemId = (int)($data['item_id'] ?? $data['id'] ?? 0);
            if (!$itemId) json_err('item_id wajib diisi');
            if (!empty($data['pallet_locations'])) $data['pallet_locations'] = $data['pallet_locations'];
            Inbound::updateItem($itemId, $data);
            json_out(['item_id' => $itemId]);
            break;

        case 'update_item_qty':
            api_require_write();
            $data = body();
            $itemId = (int)($data['item_id'] ?? 0);
            $qty = (float)($data['quantity'] ?? 0);
            if ($itemId && $qty > 0) {
                Inbound::updateItemQty($itemId, $qty);
                ActivityLogger::log('UPDATE_INBOUND_ITEM_QTY', 'inbound', 'Inbound', (int)($data['inbound_id'] ?? 0), null, "Edit qty item ID $itemId → $qty");
            }
            json_out(['item_id' => $itemId]);
            break;

        case 'update_item_dates':
            api_require_write();
            $data = body();
            $itemId = (int)($data['item_id'] ?? 0);
            Inbound::updateItemDates($itemId, $data['manufacture_date'] ?? null, $data['exp_date'] ?? null);
            json_out(['item_id' => $itemId]);
            break;

        case 'update_item_pallet_no':
            api_require_write();
            $data = body();
            $itemId = (int)($data['item_id'] ?? 0);
            Inbound::updateItemPalletNo($itemId, $data['pallet_no'] ?? null);
            json_out(['item_id' => $itemId]);
            break;

        case 'delete_item':
            api_require_write();
            $data = body();
            $itemId = (int)($data['item_id'] ?? query('item_id'));
            $inboundId = (int)($data['inbound_id'] ?? query('inbound_id'));
            $parentStatus = db()->prepare("SELECT status FROM inbound_orders WHERE id=?");
            $parentStatus->execute([$inboundId]);
            if (in_array($parentStatus->fetchColumn(), ['Completed', 'Cancelled'])) {
                json_err('Order sudah selesai/dibatalkan dan tidak dapat diedit.', 409);
            }
            $user = api_require_write();
            $delItem = db()->prepare("SELECT in_process_status FROM inbound_items WHERE id=?");
            $delItem->execute([$itemId]);
            if ($delItem->fetchColumn() === 'Goods Received' && $user['role'] !== 'admin') {
                json_err('Hanya Admin yang dapat menghapus item berstatus Goods Received.', 403);
            }
            Inbound::deleteItem($itemId);
            ActivityLogger::log('DELETE_INBOUND_ITEM', 'inbound', 'Inbound', $inboundId, null, "Hapus item ID $itemId dari inbound ID $inboundId");
            json_out(['item_id' => $itemId]);
            break;

        case 'update_item_status':
            $user = api_require_write();
            $data = body();
            $itemId = (int)($data['item_id'] ?? 0);
            $newProcess = trim($data['status'] ?? '');
            $allowed = ['Dues In', 'Goods Received', 'Unserviceable', 'ATP'];
            if (!in_array($newProcess, $allowed)) json_err('Status tidak valid.');
            inbound_change_item_status($itemId, $newProcess, (int)$user['id']);
            json_out(['item_id' => $itemId, 'status' => $newProcess]);
            break;

        case 'save_pallet_locations':
            $user = api_require_write();
            $data = body();
            $itemId = (int)($data['item_id'] ?? 0);
            $pallets = $data['pallet_locations'] ?? [];
            if (!$itemId || !is_array($pallets)) json_err('Data tidak valid.');
            Inbound::savePalletLocations($itemId, $pallets, (int)$user['id']);
            ActivityLogger::log('SAVE_PALLET_LOCATIONS', 'inbound', 'Inbound', (int)($data['inbound_id'] ?? 0), null,
                'Simpan ' . count($pallets) . ' pallet location untuk item ID ' . $itemId);
            json_out(['ok' => true]);
            break;

        case 'save_item_location':
            api_require_write();
            $data = body();
            $itemId = (int)($data['item_id'] ?? 0);
            $loc = strtoupper(trim($data['location'] ?? ''));
            Inbound::saveItemLocation($itemId, $loc);
            ActivityLogger::log('ASSIGN_LOCATION', 'inbound', 'Inbound', (int)($data['inbound_id'] ?? 0), null, "Assign lokasi item ID $itemId → $loc");
            json_out(['ok' => true]);
            break;

        case 'advance_status':
            api_require_write();
            $data = body();
            $advId = (int)($data['id'] ?? 0);
            $newStatus = $data['status'] ?? '';
            if (!in_array($newStatus, ['Dues In', 'Receiving'])) json_err('Status tidak valid.');
            if ($newStatus === 'Receiving') {
                $receivedById = (int)($data['received_by_id'] ?? 0);
                $receivedDate = trim($data['received_date'] ?? '');
                if (empty($receivedById) || empty($receivedDate)) {
                    json_err('Received By dan Received Date wajib diisi saat Start Receiving.');
                }
                db()->prepare("UPDATE inbound_orders SET status=?, received_by=?, received_date=? WHERE id=?")
                    ->execute([$newStatus, $receivedById, $receivedDate ?: null, $advId]);
            } else {
                db()->prepare("UPDATE inbound_orders SET status=? WHERE id=?")
                    ->execute([$newStatus, $advId]);
            }
            ActivityLogger::log('ADVANCE_INBOUND_STATUS', 'inbound', 'Inbound', $advId, null, "Status inbound → $newStatus");
            json_out(['id' => $advId, 'status' => $newStatus]);
            break;

        case 'complete':
            api_require_write();
            $data = body();
            $cid = (int)($data['id'] ?? query('id'));
            $pendingCheck = db()->prepare("
                SELECT COUNT(*) FROM inbound_items
                WHERE inbound_order_id = ? AND in_process_status NOT IN ('ATP','Unserviceable')");
            $pendingCheck->execute([$cid]);
            $pendingCount = (int)$pendingCheck->fetchColumn();
            if ($pendingCount > 0) {
                json_err("Tidak dapat complete: masih ada $pendingCount item yang belum ATP atau Unserviceable. Update status setiap item terlebih dahulu.", 409);
            }
            set_time_limit(300);
            try {
                Inbound::complete($cid);
            } catch (Throwable $e) {
                json_err($e->getMessage(), $e->getCode() ?: 500);
            }
            ActivityLogger::log('COMPLETE_INBOUND', 'inbound', 'Inbound', $cid, null, 'Inbound ID ' . $cid . ' diselesaikan');
            json_out(['id' => $cid]);
            break;

        case 'repair_ledger':
            api_require_write();
            $data = body();
            $rid = (int)($data['id'] ?? query('id'));
            Inbound::regenerateLedger($rid);
            ActivityLogger::log('REPAIR_LEDGER', 'inbound', 'Inbound', $rid, null, 'Regenerasi ledger inbound ID ' . $rid);
            json_out(['id' => $rid]);
            break;

        default:
            json_err('Invalid action: ' . $action, 404);
    }
}

/** Centralized in Inbound::changeItemStatus (v2 inbound workflow). */
function inbound_change_item_status(int $itemId, string $newProcess, ?int $createdBy = null): void {
    try {
        Inbound::changeItemStatus($itemId, $newProcess, $createdBy);
    } catch (Throwable $e) {
        json_err($e->getMessage(), $e->getCode() ?: 400);
    }
}

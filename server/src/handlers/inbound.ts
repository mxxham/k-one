import {
  body,
  jsonErr,
  jsonOut,
  pageParams,
  query,
  activeUsersList,
  productsOptions,
  searchProductsJson,
  statusesFor,
  todayYmd,
  apiRequireAuth,
  apiRequireWrite,
} from '../helpers';
import { dbExec, dbExecFirst, dbScalar } from '../db';
import { activityLog } from '../activityLog';
import { Inbound } from '../services/Inbound';

/** Port of api/handlers/inbound.php */
export async function handleInbound(action: string): Promise<void> {
  switch (action) {
    case 'list': {
      apiRequireAuth();
      const [page, perPage, offset] = pageParams(50);
      const status = query('status') || null;
      const odNo = String(query('od_no') ?? '').trim();
      const total = await Inbound.countAll(status, odNo || null);
      let rows = await Inbound.getAll(status, perPage, offset, odNo || null);
      rows = rows.map((r: any) => ({ ...r, id: Number(r.id) }));
      jsonOut({
        rows,
        total: Number(total),
        page,
        per_page: perPage,
        statuses: statusesFor('inbound'),
      });
    }

    case 'detail': {
      apiRequireAuth();
      const id = Number(query('id')) || 0;
      const order = await Inbound.getById(id);
      if (!order) jsonErr('Inbound tidak ditemukan', 404);
      const items = await Inbound.getItems(id);
      const locations = await Inbound.getOrderLocations(id);
      const itemPalletCounts: Record<string, number> = {};
      for (const it of items) {
        const locs = await Inbound.getItemLocations(it.id);
        itemPalletCounts[Number(it.id)] = locs.length ? locs.length : Math.ceil(Number(it.pallet ?? 0));
        it.pallet_locations = locs;
        it.id = Number(it.id);
      }
      jsonOut({
        order,
        items,
        locations,
        item_pallet_counts: itemPalletCounts,
        users: await activeUsersList(),
        products: await productsOptions(),
      });
    }

    case 'stats':
      apiRequireAuth();
      jsonOut({ stats: await Inbound.getStats() });

    case 'search_products':
      apiRequireAuth();
      jsonOut({ results: await searchProductsJson(query('q')) });

    case 'create': {
      const user = apiRequireWrite();
      const data = body();
      data.items = data.items ?? [];
      data.created_by = user.id;
      const id = await Inbound.create(data);
      const created = await Inbound.getById(id);
      await activityLog('CREATE_INBOUND', 'inbound', 'Inbound', Number(id), null, 'Buat inbound baru, PO: ' + (data.po_number ?? '—'));
      jsonOut({ id: Number(id), order_number: created?.order_number ?? null });
    }

    case 'update': {
      apiRequireWrite();
      const data = body();
      const id = Number(data.id ?? 0);
      if (!id) jsonErr('ID wajib diisi');
      const curStatus = await dbScalar('SELECT status FROM inbound_orders WHERE id=?', [id]);
      data.status = curStatus ?? 'Draft';
      await Inbound.update(id, data);
      await activityLog('UPDATE_INBOUND', 'inbound', 'Inbound', id, null, 'Edit inbound ID ' + id);
      jsonOut({ id });
    }

    case 'delete': {
      apiRequireWrite();
      const id = Number(query('id')) || 0;
      const delStatus = await dbScalar('SELECT status FROM inbound_orders WHERE id=?', [id]);
      if (['Completed', 'Cancelled'].includes(delStatus)) {
        jsonErr('Order sudah selesai/dibatalkan dan tidak dapat dihapus.', 409);
      }
      await Inbound.delete(id);
      await activityLog('DELETE_INBOUND', 'inbound', 'Inbound', id, null, 'Hapus inbound ID ' + id);
      jsonOut({ id });
    }

    case 'add_item': {
      apiRequireWrite();
      const data = body();
      const inboundId = Number(data.inbound_id ?? 0);
      if (!inboundId) jsonErr('inbound_id wajib diisi');
      const item = data.item ?? data;
      const inProcess = item.in_process_status ?? 'Dues In';
      const stockStatusMap: Record<string, string> = {
        'Dues In': 'Pending',
        'Goods Received': 'Pending',
        'ATP': 'Accepted',
        'Unserviceable': 'Rejected',
      };
      item.stock_status = stockStatusMap[inProcess] ?? 'Pending';
      if (inProcess === 'Unserviceable') item.location = 'QUA_SHELL';
      item.pallet_locations = data.pallet_locations ?? item.pallet_locations ?? [];
      const itemId = await Inbound.addItem(inboundId, item);
      await activityLog('ADD_INBOUND_ITEM', 'inbound', 'Inbound', inboundId, null,
        'Tambah item produk ID ' + (item.product_id ?? '?') + ', qty ' + (item.quantity ?? 0));
      jsonOut({ item_id: Number(itemId), inbound_id: inboundId });
    }

    case 'update_item': {
      apiRequireWrite();
      const data = body();
      const itemId = Number(data.item_id ?? data.id ?? 0);
      if (!itemId) jsonErr('item_id wajib diisi');
      await Inbound.updateItem(itemId, data);
      jsonOut({ item_id: itemId });
    }

    case 'update_item_qty': {
      apiRequireWrite();
      const data = body();
      const itemId = Number(data.item_id ?? 0);
      const qty = Number(data.quantity ?? 0);
      if (itemId && qty > 0) {
        await Inbound.updateItemQty(itemId, qty);
        await activityLog('UPDATE_INBOUND_ITEM_QTY', 'inbound', 'Inbound', Number(data.inbound_id ?? 0), null,
          `Edit qty item ID ${itemId} → ${qty}`);
      }
      jsonOut({ item_id: itemId });
    }

    case 'update_item_dates': {
      apiRequireWrite();
      const data = body();
      const itemId = Number(data.item_id ?? 0);
      await Inbound.updateItemDates(itemId, data.manufacture_date ?? null, data.exp_date ?? null);
      jsonOut({ item_id: itemId });
    }

    case 'update_item_pallet_no': {
      apiRequireWrite();
      const data = body();
      const itemId = Number(data.item_id ?? 0);
      await Inbound.updateItemPalletNo(itemId, data.pallet_no ?? null);
      jsonOut({ item_id: itemId });
    }

    case 'delete_item': {
      const data = body();
      const itemId = Number(data.item_id ?? query('item_id')) || 0;
      const inboundId = Number(data.inbound_id ?? query('inbound_id')) || 0;
      const parentStatus = await dbScalar('SELECT status FROM inbound_orders WHERE id=?', [inboundId]);
      if (['Completed', 'Cancelled'].includes(parentStatus)) {
        jsonErr('Order sudah selesai/dibatalkan dan tidak dapat diedit.', 409);
      }
      const user = apiRequireWrite();
      const delItem = await dbScalar('SELECT in_process_status FROM inbound_items WHERE id=?', [itemId]);
      if (delItem === 'Goods Received' && user.role !== 'admin') {
        jsonErr('Hanya Admin yang dapat menghapus item berstatus Goods Received.', 403);
      }
      await Inbound.deleteItem(itemId);
      await activityLog('DELETE_INBOUND_ITEM', 'inbound', 'Inbound', inboundId, null,
        `Hapus item ID ${itemId} dari inbound ID ${inboundId}`);
      jsonOut({ item_id: itemId });
    }

    case 'update_item_status': {
      apiRequireWrite();
      const data = body();
      const itemId = Number(data.item_id ?? 0);
      const inboundId = Number(data.inbound_id ?? 0);
      const newProcess = String(data.status ?? '').trim();
      const allowed = ['Dues In', 'Goods Received', 'Unserviceable', 'ATP'];
      if (!allowed.includes(newProcess)) jsonErr('Status tidak valid.');
      await inboundChangeItemStatus(itemId, inboundId, newProcess);
      jsonOut({ item_id: itemId, status: newProcess });
    }

    case 'save_pallet_locations': {
      apiRequireWrite();
      const data = body();
      await inboundSavePalletLocations(data);
      jsonOut({ ok: true });
    }

    case 'save_item_location': {
      apiRequireWrite();
      const data = body();
      await inboundSaveItemLocation(data);
      jsonOut({ ok: true });
    }

    case 'advance_status': {
      apiRequireWrite();
      const data = body();
      const advId = Number(data.id ?? 0);
      const newStatus = data.status ?? '';
      if (!['Dues In', 'Receiving'].includes(newStatus)) jsonErr('Status tidak valid.');
      if (newStatus === 'Receiving') {
        const receivedById = Number(data.received_by_id ?? 0);
        const receivedDate = String(data.received_date ?? '').trim();
        if (!receivedById || !receivedDate) {
          jsonErr('Received By dan Received Date wajib diisi saat Start Receiving.');
        }
        await dbExec(
          'UPDATE inbound_orders SET status=?, received_by=?, received_date=? WHERE id=?',
          [newStatus, receivedById, receivedDate || null, advId],
        );
      } else {
        await dbExec('UPDATE inbound_orders SET status=? WHERE id=?', [newStatus, advId]);
      }
      await activityLog('ADVANCE_INBOUND_STATUS', 'inbound', 'Inbound', advId, null, `Status inbound → ${newStatus}`);
      jsonOut({ id: advId, status: newStatus });
    }

    case 'complete': {
      apiRequireWrite();
      const data = body();
      const cid = Number(data.id ?? query('id')) || 0;
      const pendingCheck = await dbScalar(
        `SELECT COUNT(*) FROM inbound_items
         WHERE inbound_order_id = ? AND in_process_status NOT IN ('ATP','Unserviceable')`,
        [cid],
      );
      const pendingCount = Number(pendingCheck ?? 0);
      if (pendingCount > 0) {
        jsonErr(`Tidak dapat complete: masih ada ${pendingCount} item yang belum ATP atau Unserviceable. Update status setiap item terlebih dahulu.`, 409);
      }
      await Inbound.complete(cid);
      await activityLog('COMPLETE_INBOUND', 'inbound', 'Inbound', cid, null, 'Inbound ID ' + cid + ' diselesaikan');
      jsonOut({ id: cid });
    }

    case 'repair_ledger': {
      apiRequireWrite();
      const data = body();
      const rid = Number(data.id ?? query('id')) || 0;
      await Inbound.regenerateLedger(rid);
      await activityLog('REPAIR_LEDGER', 'inbound', 'Inbound', rid, null, 'Regenerasi ledger inbound ID ' + rid);
      jsonOut({ id: rid });
    }

    default:
      jsonErr('Invalid action: ' + action, 404);
  }
}

/** Ported from api/handlers/inbound.php function inbound_change_item_status */
async function inboundChangeItemStatus(itemId: number, inboundId: number, newProcess: string): Promise<void> {
  const stockBadge: Record<string, string> = {
    'Dues In': 'Pending',
    'Goods Received': 'Pending',
    'ATP': 'Accepted',
    'Unserviceable': 'Rejected',
  };
  const newBadge = stockBadge[newProcess] ?? 'Pending';

  const it = await dbExecFirst(
    `SELECT ii.*, p.uom_per_pallet, io.order_number, io.order_date, io.id AS io_id
     FROM inbound_items ii
     JOIN products p ON p.id = ii.product_id
     JOIN inbound_orders io ON io.id = ii.inbound_order_id
     WHERE ii.id = ?`,
    [itemId],
  );
  if (!it) jsonErr('Item tidak ditemukan', 404);

  const oldProcess = it.in_process_status ?? 'Dues In';
  const pid = Number(it.product_id);
  const batch = it.batch_number ?? it.batch_no ?? null;
  const totalQty = Number(it.actual_qty ?? it.quantity ?? 0);
  const uomPerPlt = Math.max(1, Number(it.uom_per_pallet ?? 4) || 1);

  if (newProcess === 'Unserviceable') {
    await dbExec(
      `UPDATE inbound_items SET in_process_status=?, stock_status=?, location='QUA_SHELL' WHERE id=?`,
      [newProcess, newBadge, itemId],
    );
  } else if (oldProcess === 'Unserviceable') {
    await dbExec(
      `UPDATE inbound_items SET in_process_status=?, stock_status=?, location=NULL WHERE id=?`,
      [newProcess, newBadge, itemId],
    );
  } else {
    await dbExec(
      `UPDATE inbound_items SET in_process_status=?, stock_status=? WHERE id=?`,
      [newProcess, newBadge, itemId],
    );
  }

  const plt = Math.ceil(totalQty / uomPerPlt);
  const delLedger = async (): Promise<void> => {
    await dbExec(
      `DELETE FROM stock_ledger WHERE reference_type='Inbound' AND reference_id=? AND product_id=? AND batch_number<=>?`,
      [it.io_id, pid, batch],
    );
  };
  const balQuery = async (): Promise<number> => {
    const b = await dbScalar(
      `SELECT COALESCE(SUM(quantity_in),0) - COALESCE(SUM(quantity_out),0) AS bal
       FROM stock_ledger WHERE product_id=? AND (location IS NULL OR location != 'QUA_SHELL')
         AND transaction_type NOT IN ('TRANSFER_IN','TRANSFER_OUT')`,
      [pid],
    );
    return Number(b ?? 0);
  };
  const ledgerInsert = async (cols: any[]): Promise<void> => {
    await dbExec(
      `INSERT INTO stock_ledger
         (transaction_date, product_id, transaction_type, reference_type, reference_id,
          reference_number, batch_number, quantity_in, quantity_out, uom, pallet, balance, location, notes)
       VALUES (?,?,'IN','Inbound',?,?,?,?,0,?,?,?,?,?)`,
      cols,
    );
  };

  if (newProcess === 'ATP') {
    await dbExec(
      `DELETE s FROM stock s JOIN stock_locations sl ON sl.stock_id = s.id WHERE sl.inbound_item_id=?`,
      [itemId],
    );
    await dbExec('UPDATE stock_locations SET stock_id=NULL WHERE inbound_item_id=?', [itemId]);
    if (oldProcess === 'Unserviceable') {
      await dbExec(
        `DELETE FROM stock WHERE product_id=? AND batch_number<=>? AND location='QUA_SHELL' AND stock_status='Rejected'`,
        [pid, batch],
      );
    }
    await delLedger();
    const cur = await balQuery();
    await ledgerInsert([
      todayYmd(), pid, it.io_id, it.order_number, batch,
      totalQty, it.uom, plt, cur + totalQty, it.location ?? null,
      '[Inbound] ATP | In-Process: ATP | ' + it.order_number,
    ]);
  } else if (newProcess === 'Goods Received') {
    if (oldProcess === 'ATP') {
      await dbExec(
        `DELETE s FROM stock s JOIN stock_locations sl ON sl.stock_id = s.id WHERE sl.inbound_item_id=?`,
        [itemId],
      );
      await dbExec('UPDATE stock_locations SET stock_id=NULL WHERE inbound_item_id=?', [itemId]);
      await dbExec(
        `DELETE FROM stock WHERE product_id=? AND batch_number<=>? AND stock_status='Available'`,
        [pid, batch],
      );
    }
    if (oldProcess === 'Unserviceable') {
      await dbExec(
        `DELETE FROM stock WHERE product_id=? AND batch_number<=>? AND location='QUA_SHELL' AND stock_status='Rejected'`,
        [pid, batch],
      );
    }
    await delLedger();
    const cur = await balQuery();
    await ledgerInsert([
      todayYmd(), pid, it.io_id, it.order_number, batch,
      totalQty, it.uom, plt, cur + totalQty, it.location ?? null,
      '[Inbound] Goods Received | In-Process: Goods Received | ' + it.order_number,
    ]);
  } else if (newProcess === 'Unserviceable') {
    await dbExec(
      `DELETE s FROM stock s JOIN stock_locations sl ON sl.stock_id = s.id WHERE sl.inbound_item_id=?`,
      [itemId],
    );
    await dbExec('DELETE FROM stock_locations WHERE inbound_item_id=?', [itemId]);
    await dbExec(
      `DELETE FROM stock WHERE product_id=? AND batch_number<=>? AND stock_status IN ('Available','Dues In','Pending')`,
      [pid, batch],
    );
    await dbExec(
      `INSERT INTO stock (product_id,batch_number,location,quantity,uom,pallet,manufacture_date,expiry_date,stock_status)
       VALUES (?,?,'QUA_SHELL',?,?,?,?,?,'Rejected')`,
      [pid, batch, totalQty, it.uom, plt, it.manufacture_date, it.exp_date],
    );
    await delLedger();
    const cur = await balQuery();
    await ledgerInsert([
      todayYmd(), pid, it.io_id, it.order_number, batch,
      totalQty, it.uom, plt, cur, 'QUA_SHELL',
      '[Inbound] Unserviceable (QUA_SHELL) | In-Process: Unserviceable | ' + it.order_number,
    ]);
  } else if (newProcess === 'Dues In') {
    await dbExec(
      `DELETE s FROM stock s JOIN stock_locations sl ON sl.stock_id = s.id WHERE sl.inbound_item_id=?`,
      [itemId],
    );
    await dbExec('UPDATE stock_locations SET stock_id=NULL WHERE inbound_item_id=?', [itemId]);
    await dbExec(
      `DELETE FROM stock WHERE product_id=? AND batch_number<=>? AND stock_status IN ('Available','Dues In','Pending','Rejected')`,
      [pid, batch],
    );
    await delLedger();
  }

  await activityLog('UPDATE_ITEM_STATUS', 'inbound', 'Inbound', inboundId, null,
    `Status item ID ${itemId}: ${oldProcess} → ${newProcess}`);
}

/** Ported from api/handlers/inbound.php function inbound_save_pallet_locations */
async function inboundSavePalletLocations(data: any): Promise<void> {
  const itemId = Number(data.item_id ?? 0);
  const inboundId = Number(data.inbound_id ?? 0);
  const pallets = data.pallet_locations ?? [];
  if (!itemId || !Array.isArray(pallets)) jsonErr('Data tidak valid.');

  const specialLocs = ['QUA_SHELL', 'STAGING'];
  const invalidLocs: string[] = [];
  for (const p of pallets) {
    const pLoc = String(p.location_code ?? '').trim().toUpperCase();
    if (pLoc && !specialLocs.includes(pLoc)) {
      const locCheck = await dbExecFirst('SELECT id FROM location_master WHERE location_code=? AND is_active=1 LIMIT 1', [pLoc]);
      if (!locCheck) invalidLocs.push(pLoc);
    }
  }
  if (invalidLocs.length) jsonErr('Lokasi tidak valid: ' + invalidLocs.join(', '));

  await dbExec('DELETE FROM stock_locations WHERE inbound_item_id=?', [itemId]);
  const itRow = await dbExecFirst('SELECT * FROM inbound_items WHERE id=?', [itemId]);
  const batch = itRow.batch_number ?? itRow.batch_no ?? null;
  const firstLoc = pallets[0]?.location_code ?? null;
  await dbExec('UPDATE inbound_items SET location=? WHERE id=?', [firstLoc, itemId]);

  for (const p of pallets) {
    const pQty = Number(p.quantity ?? 0);
    await dbExec(
      `INSERT INTO stock_locations
         (inbound_item_id, location_code, pallet_seq, quantity, original_quantity, uom, is_full_pallet, batch_number, status)
       VALUES (?,?,?,?,?,?,?,?,'Available')`,
      [
        itemId,
        String(p.location_code ?? '').trim().toUpperCase(),
        p.pallet_seq ?? 1,
        pQty,
        pQty,
        itRow.uom ?? 'Drum',
        p.is_full ? 1 : 0,
        batch,
      ],
    );
  }
  await activityLog('SAVE_PALLET_LOCATIONS', 'inbound', 'Inbound', inboundId, null,
    'Simpan ' + pallets.length + ' pallet location untuk item ID ' + itemId);
}

/** Ported from api/handlers/inbound.php function inbound_save_item_location */
async function inboundSaveItemLocation(data: any): Promise<void> {
  const loc = String(data.location ?? '').trim().toUpperCase();
  const itemId = Number(data.item_id ?? 0);
  const inboundId = Number(data.inbound_id ?? 0);
  const specialLocs = ['QUA_SHELL', 'STAGING'];
  if (!loc || !itemId) jsonErr('Lokasi dan item wajib diisi.');

  if (!specialLocs.includes(loc)) {
    const locCheck = await dbExecFirst('SELECT id FROM location_master WHERE location_code=? AND is_active=1 LIMIT 1', [loc]);
    if (!locCheck) jsonErr(`Lokasi '${loc}' tidak ditemukan di master lokasi.`);
  }

  await dbExec('UPDATE inbound_items SET location=? WHERE id=?', [loc, itemId]);
  await dbExec('UPDATE stock_locations SET location_code=? WHERE inbound_item_id=?', [loc, itemId]);
  const ibSt = await dbScalar('SELECT status FROM inbound_orders WHERE id=?', [inboundId]);
  if (ibSt === 'Completed') {
    const itRow = await dbExecFirst('SELECT * FROM inbound_items WHERE id=?', [itemId]);
    const batch = itRow.batch_number ?? itRow.batch_no ?? null;
    await dbExec('UPDATE stock SET location=? WHERE product_id=? AND batch_number<=>?', [loc, itRow.product_id, batch]);
  }
  await activityLog('ASSIGN_LOCATION', 'inbound', 'Inbound', inboundId, null,
    `Assign lokasi item ID ${itemId} → ${loc}`);
}

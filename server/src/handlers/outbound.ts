import {
  body,
  jsonErr,
  jsonOut,
  pageParams,
  query,
  productsOptions,
  customersOptions,
  searchProductsJson,
  statusesFor,
  apiRequireAuth,
  apiRequireWrite,
} from '../helpers';
import { dbExec, dbExecFirst, dbScalar, withTransaction } from '../db';
import { activityLog } from '../activityLog';
import { Outbound } from '../services/Outbound';

/** Port of api/handlers/outbound.php */
export async function handleOutbound(action: string): Promise<void> {
  switch (action) {
    case 'list': {
      apiRequireAuth();
      const [page, perPage, offset] = pageParams(50);
      const status = query('status') || null;
      const odNo = String(query('od_no') ?? '').trim();
      const total = await Outbound.countAll(status, odNo || null);
      let rows = await Outbound.getAll(status, perPage, offset, odNo || null);
      rows = rows.map((r: any) => ({ ...r, id: Number(r.id), display_order_no: Outbound.displayOrderNo(r) }));
      jsonOut({
        rows,
        total: Number(total),
        page,
        per_page: perPage,
        statuses: statusesFor('outbound'),
      });
    }

    case 'detail': {
      apiRequireAuth();
      const id = Number(query('id')) || 0;
      const order = await Outbound.getById(id);
      if (!order) jsonErr('Outbound tidak ditemukan', 404);
      order.display_order_no = Outbound.displayOrderNo(order);
      const items = await Outbound.getItems(id);
      for (const it of items) {
        it.id = Number(it.id);
        it.picked_locations = await Outbound.getItemPickedLocations(it.id);
      }
      jsonOut({
        order,
        items,
        destinations: await outboundDestinations(id),
        customers: await customersOptions(),
        products: await productsOptions(),
      });
    }

    case 'stats':
      apiRequireAuth();
      jsonOut({ stats: await Outbound.getStats() });

    case 'search_products':
      apiRequireAuth();
      jsonOut({ results: await searchProductsJson(query('q')) });

    case 'check_stock': {
      apiRequireAuth();
      const pid = Number(query('product_id')) || 0;
      const qty = Number(query('quantity')) || 0;
      const loc = query('location') || null;
      const avail = await Outbound.getTotalAvailableQty(pid);
      const fefo = await Outbound.getFEFOAllocation(pid, qty, loc);
      jsonOut({ available: avail, fefo });
    }

    case 'create': {
      apiRequireWrite();
      const data = body();
      const rawItems = data.items ?? [];
      const validItems: any[] = [];
      const skippedItems: string[] = [];
      for (const item of rawItems) {
        const pid = Number(item.product_id ?? 0);
        if (!pid) continue;
        const avail = await Outbound.getTotalAvailableQty(pid);
        if (avail <= 0) {
          const p = await dbExecFirst('SELECT product_code, product_name FROM products WHERE id=?', [pid]);
          skippedItems.push((p?.product_code ?? 'ID:' + pid) + ' – ' + (p?.product_name ?? 'Unknown'));
        } else {
          validItems.push(item);
        }
      }
      if (rawItems.length > 0 && validItems.length === 0) {
        jsonErr('Semua produk tidak ada di stok. Order tidak dibuat. Dilewati: ' + skippedItems.join(', '), 409);
      }
      data.items = validItems;
      const id = await Outbound.create(data);
      if (Array.isArray(data.destinations) && data.destinations.length) {
        const d = data.destinations;
        await Outbound.saveDestinations(
          Number(id),
          d.map((x: any) => x.ship_to_name),
          d.map((x: any) => x.ship_to_location),
          d.map((x: any) => x.ship_to_street),
          d.map((x: any) => x.kota),
          d.map((x: any) => x.notes),
        );
      }
      await activityLog('CREATE_OUTBOUND', 'outbound', 'Outbound', Number(id),
        null, 'Buat outbound, customer ID ' + (data.customer_id ?? '—') + ', SO: ' + (data.so_number ?? '—'));
      jsonOut({ id: Number(id), warnings: skippedItems });
    }

    case 'update': {
      apiRequireWrite();
      const data = body();
      const id = Number(data.id ?? 0);
      const obCheck = await Outbound.getById(id);
      if ((obCheck?.status ?? '') === 'Completed') jsonErr('Order sudah Completed dan tidak dapat diedit.', 409);
      await Outbound.update(id, data);
      if (Array.isArray(data.destinations) && data.destinations.length) {
        const d = data.destinations;
        await Outbound.saveDestinations(
          id,
          d.map((x: any) => x.ship_to_name),
          d.map((x: any) => x.ship_to_location),
          d.map((x: any) => x.ship_to_street),
          d.map((x: any) => x.kota),
          d.map((x: any) => x.notes),
        );
      }
      await activityLog('UPDATE_OUTBOUND', 'outbound', 'Outbound', id, null, 'Edit outbound ID ' + id);
      jsonOut({ id });
    }

    case 'add_item': {
      apiRequireWrite();
      const data = body();
      const outboundId = Number(data.outbound_id ?? 0);
      const obCheck = await Outbound.getById(outboundId);
      if ((obCheck?.status ?? '') === 'Completed') jsonErr('Order sudah Completed dan tidak dapat diedit.', 409);
      const item = data.item ?? data;
      item.manual_location = data.manual_location ?? null;
      item.manual_locs = data.manual_locs ?? null;
      let newItemId = 0;
      try {
        newItemId = await Outbound.addItemWithFEFO(outboundId, item);
      } catch (e: any) {
        jsonErr(e.message, 409);
      }
      await outboundAttachDestination(outboundId, newItemId, item);
      await activityLog('ADD_OUTBOUND_ITEM', 'outbound', 'Outbound', outboundId,
        null, 'Tambah item produk ID ' + (item.product_id ?? '?') + ' qty ' + (item.quantity ?? 0));
      jsonOut({ item_id: Number(newItemId), outbound_id: outboundId });
    }

    case 'pick_items': {
      apiRequireWrite();
      const data = body();
      const id = Number(data.id ?? query('id')) || 0;
      const ob = await Outbound.getById(id);
      if (!ob?.expected_date) jsonErr('Expected Date wajib diisi sebelum Pick Items.');
      await Outbound.pickItems(id);
      await activityLog('PICK_OUTBOUND', 'outbound', 'Outbound', id, ob?.order_number ?? null, 'Pick outbound ' + (ob?.order_number ?? id));
      jsonOut({ id });
    }

    case 'ship': {
      apiRequireWrite();
      const data = body();
      const id = Number(data.id ?? query('id')) || 0;
      const ob = await Outbound.getById(id);
      await Outbound.ship(id);
      await activityLog('SHIP_OUTBOUND', 'outbound', 'Outbound', id, ob?.order_number ?? null, 'Kirim outbound ' + (ob?.order_number ?? id));
      jsonOut({ id });
    }

    case 'complete': {
      apiRequireWrite();
      const data = body();
      const id = Number(data.id ?? query('id')) || 0;
      const ob = await Outbound.getById(id);
      await Outbound.complete(id);
      await activityLog('COMPLETE_OUTBOUND', 'outbound', 'Outbound', id, ob?.order_number ?? null, 'Selesai outbound ' + (ob?.order_number ?? id));
      jsonOut({ id });
    }

    case 'delete': {
      apiRequireWrite();
      const data = body();
      const id = Number(data.id ?? query('id')) || 0;
      const ob = await Outbound.getById(id);
      if (['Completed', 'Cancelled', 'Shipped', 'Delivered'].includes(ob?.status ?? '')) {
        jsonErr('Order sudah ' + ob.status + ' dan tidak dapat dihapus.', 409);
      }
      await Outbound.delete(id);
      await activityLog('DELETE_OUTBOUND', 'outbound', 'Outbound', id, ob?.order_number ?? null, 'Hapus outbound ' + (ob?.order_number ?? id));
      jsonOut({ id });
    }

    case 'delete_item': {
      apiRequireWrite();
      const data = body();
      const outboundId = Number(data.outbound_id ?? 0);
      const itemId = Number(data.item_id ?? 0);
      const obCheck = await Outbound.getById(outboundId);
      if ((obCheck?.status ?? '') === 'Completed') jsonErr('Order sudah Completed dan tidak dapat diedit.', 409);
      await Outbound.deleteItem(itemId);
      await activityLog('DELETE_OUTBOUND_ITEM', 'outbound', 'Outbound', outboundId, null, `Hapus item ID ${itemId} dari outbound ID ${outboundId}`);
      jsonOut({ item_id: itemId });
    }

    case 'update_item_status': {
      apiRequireWrite();
      const data = body();
      const iid = Number(data.item_id ?? 0);
      const outboundId = Number(data.outbound_id ?? 0);
      const newSt = String(data.status ?? '').trim();
      if (!['Goods Received', 'ATP', 'Unserviceable'].includes(newSt)) jsonErr('Status tidak valid.');
      await outboundChangeItemStatus(iid, outboundId, newSt);
      jsonOut({ item_id: iid, status: newSt });
    }

    default:
      jsonErr('Invalid action: ' + action, 404);
  }
}

/** Ported from api/handlers/outbound.php function outbound_destinations */
async function outboundDestinations(outboundId: number): Promise<any[]> {
  return dbExec('SELECT * FROM outbound_destinations WHERE outbound_id=? ORDER BY seq', [outboundId]);
}

/** Ported from api/handlers/outbound.php function outbound_attach_destination */
async function outboundAttachDestination(outboundId: number, newItemId: number, item: any): Promise<void> {
  const itemShipToName = String(item.item_ship_to_name ?? item.ship_to_name ?? '').trim();
  const itemShipToLoc = String(item.item_ship_to_location ?? item.ship_to_location ?? '').trim();
  const itemShipToStreet = String(item.item_ship_to_street ?? item.ship_to_street ?? '').trim();
  if (!itemShipToName && !itemShipToLoc) return;

  const lastItem = await dbExecFirst(
    'SELECT id FROM outbound_items WHERE outbound_order_id=? ORDER BY id DESC LIMIT 1',
    [outboundId],
  );
  const seqRow = await dbExecFirst(
    'SELECT COALESCE(MAX(seq),0)+1 as next_seq FROM outbound_destinations WHERE outbound_id=?',
    [outboundId],
  );
  const nextSeq = seqRow?.next_seq ?? 1;

  const existingDest = await dbExecFirst(
    'SELECT id FROM outbound_destinations WHERE outbound_id=? AND ship_to_name=? LIMIT 1',
    [outboundId, itemShipToName],
  );
  if (!existingDest) {
    try {
      const ins = await dbExec(
        `INSERT INTO outbound_destinations (outbound_id, seq, ship_to_name, ship_to_location, kota, ship_to_street, notes) VALUES (?,?,?,?,?,?,?)`,
        [outboundId, nextSeq, itemShipToName, itemShipToLoc, itemShipToLoc, itemShipToStreet, null],
      );
      const newDestId = Number((ins as any).insertId);
      if (lastItem) {
        await dbExec('UPDATE outbound_items SET destination_id=? WHERE id=?', [newDestId, lastItem.id]);
      }
    } catch (e) {
    }
  } else if (lastItem) {
    try {
      await dbExec('UPDATE outbound_items SET destination_id=? WHERE id=?', [existingDest.id, lastItem.id]);
    } catch (e) {
    }
  }
}

/** Ported from api/handlers/outbound.php function outbound_change_item_status */
async function outboundChangeItemStatus(iid: number, outboundId: number, newSt: string): Promise<void> {
  const it = await dbExecFirst(
    `SELECT oi.*, oo.status AS order_status
     FROM outbound_items oi JOIN outbound_orders oo ON oo.id = oi.outbound_order_id WHERE oi.id = ?`,
    [iid],
  );
  if (!it) jsonErr('Item tidak ditemukan', 404);

  if (newSt === 'ATP' && (it.in_process_status ?? '') !== 'ATP') {
    const cnt = await dbScalar(
      `SELECT COUNT(*) FROM stock
       WHERE product_id = ? AND batch_number <=> COALESCE(?, ?)
         AND stock_status = 'Available' AND quantity > 0
         AND (location IS NULL OR location NOT IN ('QUA_SHELL','STAGING'))`,
      [it.product_id, it.batch_number ?? null, it.batch_no ?? null],
    );
    if (Number(cnt ?? 0) === 0) {
      jsonErr('Inbound belum ATP. Stock belum tersedia untuk item ini.', 409);
    }
  }

  await withTransaction(async () => {
    if (newSt === 'Unserviceable') {
      if (['Picking', 'Shipped'].includes(it.order_status ?? '')) {
        const batch = it.batch_no ?? it.batch_number ?? null;
        const qty = Number(it.actual_qty ?? it.quantity ?? 0);
        const loc = it.location ?? null;
        if (loc && loc !== 'QUA_SHELL') {
          await dbExec(
            `UPDATE stock SET quantity = GREATEST(0, quantity - ?)
             WHERE product_id=? AND batch_number<=>? AND location=? AND stock_status='Available'`,
            [qty, it.product_id, batch, loc],
          );
          await dbExec(`DELETE FROM stock WHERE quantity<=0 AND location=? AND stock_status='Available'`, [loc]);
        }
        await dbExec(
          `INSERT INTO stock (product_id, batch_number, location, quantity, uom, pallet, stock_status)
           VALUES (?,?, 'QUA_SHELL', ?,?,?, 'Rejected')
           ON DUPLICATE KEY UPDATE quantity=quantity+VALUES(quantity), pallet=pallet+VALUES(pallet)`,
          [it.product_id, batch, qty, it.uom ?? 'Drum', Number(it.pallet ?? 0)],
        );
        await dbExec("UPDATE outbound_items SET location='QUA_SHELL' WHERE id=?", [iid]);
        await dbExec('DELETE FROM outbound_item_locations WHERE outbound_item_id=?', [iid]);
      }
    }
    if (newSt === 'ATP' && (it.in_process_status ?? '') === 'Unserviceable') {
      const batch = it.batch_no ?? it.batch_number ?? null;
      const qty = Number(it.actual_qty ?? it.quantity ?? 0);
      await dbExec(
        `UPDATE stock SET quantity=GREATEST(0,quantity-?)
         WHERE product_id=? AND batch_number<=>? AND location='QUA_SHELL' AND stock_status='Rejected'`,
        [qty, it.product_id, batch],
      );
      await dbExec(`DELETE FROM stock WHERE quantity<=0 AND stock_status='Rejected'`);
      await dbExec("UPDATE outbound_items SET location=NULL WHERE id=? AND location='QUA_SHELL'", [iid]);
    }
    await dbExec('UPDATE outbound_items SET in_process_status=? WHERE id=?', [newSt, iid]);
  });

  await activityLog('UPDATE_OB_ITEM_STATUS', 'outbound', 'Outbound', outboundId, null,
    `Status outbound item ID ${iid} → ${newSt}`);
}

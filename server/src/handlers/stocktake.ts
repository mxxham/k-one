import { body, jsonErr, jsonOut, query, apiRequireAuth, apiRequireWrite, apiRequireAdmin } from '../helpers';
import { dbExec } from '../db';
import { activityLog } from '../activityLog';
import { StockTake } from '../services/StockTake';

export async function handleStockTake(action: string): Promise<void> {
  switch (action) {
    case 'list': {
      apiRequireAuth();
      const limit = Number(query('limit', 200));
      let rows = await StockTake.getAll(limit);
      rows = rows.map((r: any) => ({ ...r, id: Number(r.id) }));
      jsonOut({ rows, stats: await StockTake.getStats() });
    }

    case 'detail': {
      apiRequireAuth();
      const id = Number(query('id'));
      const stockTake = await StockTake.getById(id);
      if (!stockTake) jsonErr('Stock take tidak ditemukan', 404);
      let items = await StockTake.getItems(id);
      items = items.map((it: any) => ({ ...it, id: Number(it.id) }));
      jsonOut({
        stock_take: stockTake,
        items,
        accuracy: await StockTake.calculateAccuracy(id),
        locked_locations: await StockTake.getActiveLockedLocations(),
      });
    }

    case 'stats':
      apiRequireAuth();
      jsonOut({ stats: await StockTake.getStats() });

    case 'get_locations': {
      apiRequireAuth();
      const rows = await dbExec(
        `SELECT DISTINCT location FROM stock
         WHERE location IS NOT NULL AND location != '' AND quantity > 0
         ORDER BY location`,
      );
      jsonOut({ rows: rows.map((r: any) => r.location) });
    }

    case 'get_scope_locations': {
      apiRequireAuth();
      const locked = await StockTake.getActiveLockedLocations();
      const rows = await dbExec(
        `SELECT DISTINCT location FROM stock
         WHERE location IS NOT NULL AND location NOT IN ('QUA_SHELL','STAGING')
           AND quantity > 0
         ORDER BY location`,
      );
      const available = rows.map((r: any) => r.location).filter((l: any) => !locked.includes(l));
      jsonOut({ locations: available, locked });
    }

    case 'get_stock': {
      apiRequireAuth();
      const productId = Number(query('product_id'));
      const location = query('location') || null;
      const batch = query('batch') || null;
      if (!productId) jsonErr('product_id wajib diisi.');
      const qty = await StockTake.getSystemStock(productId, location, batch);
      let sql = `SELECT s.id, s.product_id, s.batch_number, s.location, s.quantity, s.uom, s.expiry_date,
            p.product_code, p.product_name
        FROM stock s JOIN products p ON p.id = s.product_id
        WHERE s.product_id = ? AND s.quantity > 0 AND s.stock_status='Available'
        ` + (location ? 'AND s.location = ?' : '') + `
        ` + (batch ? 'AND s.batch_number <=> ?' : '') + `
        ORDER BY s.expiry_date`;
      const params: any[] = [productId];
      if (location) params.push(location);
      if (batch) params.push(batch);
      const rows = await dbExec(sql, params);
      jsonOut({ total_qty: qty, rows });
    }

    case 'create': {
      apiRequireWrite();
      const data = body();
      const id = await StockTake.create(data);
      if (!id) jsonErr('Gagal membuat stock take.', 500);
      if (data.scope_locations) {
        let locs = data.scope_locations;
        if (typeof locs === 'string') locs = JSON.parse(locs);
        await StockTake.autoLoadByLocations(Number(id), Array.isArray(locs) ? locs : null);
      } else if (data.auto_load) {
        await StockTake.autoLoadByLocations(Number(id), null);
      }
      await activityLog('CREATE_STOCKTAKE', 'stocktake', 'StockTake', Number(id), null, 'Buat stock take ID ' + id);
      jsonOut({ id: Number(id) });
    }

    case 'add_item': {
      apiRequireWrite();
      const data = body();
      const stockTakeId = Number(data.stock_take_id ?? 0);
      const item = data.item ?? data;
      const itemId = await StockTake.addItemFull(stockTakeId, item);
      if (!itemId) jsonErr('Gagal menambah item.', 500);
      jsonOut({ item_id: Number(itemId) });
    }

    case 'auto_load': {
      apiRequireWrite();
      const data = body();
      const stockTakeId = Number(data.stock_take_id ?? query('stock_take_id'));
      const locs = data.locations ?? null;
      await StockTake.autoLoadByLocations(stockTakeId, Array.isArray(locs) ? locs : null);
      jsonOut({ ok: true });
    }

    case 'update': {
      apiRequireWrite();
      const data = body();
      const id = Number(data.id ?? 0);
      await StockTake.update(id, data);
      jsonOut({ id });
    }

    case 'delete_item': {
      apiRequireWrite();
      const data = body();
      const itemId = Number(data.item_id ?? query('item_id'));
      await dbExec('DELETE FROM stock_take_items WHERE id=?', [itemId]);
      jsonOut({ item_id: itemId });
    }

    case 'delete': {
      apiRequireWrite();
      const data = body();
      const id = Number(data.id ?? query('id'));
      await StockTake.delete(id);
      await activityLog('DELETE_STOCKTAKE', 'stocktake', 'StockTake', id, null, 'Hapus stock take ID ' + id);
      jsonOut({ id });
    }

    case 'start_counting': {
      apiRequireWrite();
      const data = body();
      const id = Number(data.id ?? query('id'));
      await StockTake.startCounting(id);
      jsonOut({ id });
    }

    case 'save_counters': {
      apiRequireWrite();
      const data = body();
      const id = Number(data.id ?? 0);
      await StockTake.saveCounters(id, data.counters ?? {});
      jsonOut({ id });
    }

    case 'advance_to_c2': {
      apiRequireWrite();
      const data = body();
      const id = Number(data.id ?? 0);
      await StockTake.advanceToC2(id, data.counters ?? {});
      jsonOut({ id });
    }

    case 'finish_counting': {
      apiRequireWrite();
      const data = body();
      const id = Number(data.id ?? 0);
      await StockTake.finishCounting(id, data.counters ?? {});
      jsonOut({ id });
    }

    case 'save_review': {
      apiRequireWrite();
      const data = body();
      const id = Number(data.id ?? 0);
      await StockTake.saveReview(id, data.physicals ?? {});
      jsonOut({ id });
    }

    case 'apply_adjustment': {
      apiRequireAdmin();
      const data = body();
      const id = Number(data.id ?? query('id'));
      await StockTake.applyAdjustment(id);
      await activityLog('APPLY_STOCKTAKE_ADJUSTMENT', 'stocktake', 'StockTake', id, null, 'Apply adjustment stock take ID ' + id);
      jsonOut({ id });
    }

    default:
      jsonErr('Invalid action: ' + action, 404);
  }
}

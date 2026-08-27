import { dbExec, dbExecFirst, dbScalar, withTransaction } from '../db';
import { ctx, todayYmd } from '../helpers';

export class StockTake {
  static async getAll(limit: number | null = null): Promise<any[]> {
    try {
      let sql = `SELECT st.*, u.full_name as created_by_name,
            COUNT(sti.id) as total_items,
            SUM(CASE WHEN sti.status = 'Plus' THEN 1 ELSE 0 END) as plus_count,
            SUM(CASE WHEN sti.status = 'Minus' THEN 1 ELSE 0 END) as minus_count,
            SUM(CASE WHEN sti.status = 'Clear' THEN 1 ELSE 0 END) as clear_count
           FROM stock_take st
           LEFT JOIN users u ON st.created_by = u.id
           LEFT JOIN stock_take_items sti ON st.id = sti.stock_take_id
           GROUP BY st.id
           ORDER BY st.take_date DESC, st.created_at DESC`;
      if (limit !== null) {
        sql += ' LIMIT ' + Number(limit);
      }
      return await dbExec(sql);
    } catch (e: any) {
      console.error('Error getting stock takes: ' + e.message);
      return [];
    }
  }

  static async getById(id: number): Promise<any> {
    try {
      return await dbExecFirst(
        `SELECT st.*, u.full_name as created_by_name
         FROM stock_take st
         LEFT JOIN users u ON st.created_by = u.id
         WHERE st.id = ?`,
        [id],
      );
    } catch (e: any) {
      console.error('Error getting stock take: ' + e.message);
      return null;
    }
  }

  static async getItems(stockTakeId: number): Promise<any[]> {
    try {
      return await dbExec(
        `SELECT sti.*, p.product_code, p.product_name
         FROM stock_take_items sti
         LEFT JOIN products p ON sti.product_id = p.id
         WHERE sti.stock_take_id = ?
         ORDER BY p.product_code, sti.location`,
        [stockTakeId],
      );
    } catch (e: any) {
      console.error('Error getting stock take items: ' + e.message);
      return [];
    }
  }

  static async calculateAccuracy(stockTakeId: number): Promise<any> {
    try {
      const items = await StockTake.getItems(stockTakeId);

      if (!items.length) {
        return {
          total_stock_take: 0,
          plus: 0,
          minus: 0,
          clear: 0,
          accuracy: 100,
        };
      }

      let totalStockTake = 0;
      let plus = 0;
      let minus = 0;
      let clear = 0;

      for (const item of items) {
        totalStockTake += Number(item.qty_physical);

        if (item.status === 'Plus') {
          plus += Math.abs(Number(item.difference));
        } else if (item.status === 'Minus') {
          minus += Math.abs(Number(item.difference));
        } else {
          clear += Number(item.qty_physical);
        }
      }

      const accuracy = totalStockTake > 0 ? Math.round((clear / totalStockTake) * 100 * 100) / 100 : 100;

      return {
        total_stock_take: totalStockTake,
        plus,
        minus,
        clear,
        accuracy,
      };
    } catch (e: any) {
      console.error('Error calculating accuracy: ' + e.message);
      return {
        total_stock_take: 0,
        plus: 0,
        minus: 0,
        clear: 0,
        accuracy: 0,
      };
    }
  }

  static async create(data: any): Promise<number> {
    try {
      const takeNumber = 'ST-' + todayYmd().split('-').join('') + '-' + String(Math.floor(Math.random() * 10000)).padStart(4, '0');
      const scopeLocs = data.scope_locations ?? null;
      const scopeType = (scopeLocs !== null && scopeLocs !== '[]') ? 'location' : 'full';

      const ins = await dbExec(
        `INSERT INTO stock_take
           (take_number, take_date, status, notes, scope_locations, scope_type, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?)`,
        [
          takeNumber,
          data.take_date,
          data.status ?? 'Draft',
          data.notes ?? null,
          scopeLocs,
          scopeType,
          ctx().user?.id ?? null,
        ],
      );

      return Number((ins as any).insertId);
    } catch (e: any) {
      console.error('Error creating stock take: ' + e.message);
      return 0;
    }
  }

  static async autoLoadByLocations(stockTakeId: number, locations: any[] | null): Promise<void> {
    let sql = `SELECT s.product_id, s.batch_number, s.location, s.quantity, s.uom
               FROM stock s
               WHERE s.stock_status='Available' AND s.quantity>0
                 AND s.location IS NOT NULL
                 AND s.location NOT IN ('QUA_SHELL','STAGING')`;
    let params: any[] = [];

    if (locations && locations.length) {
      const ph = Array(locations.length).fill('?').join(',');
      sql += ' AND s.location IN (' + ph + ')';
      params = [...locations];
    }

    sql += ' ORDER BY s.location, s.product_id';
    const stocks = await dbExec(sql, params);

    for (const s of stocks) {
      await StockTake.addItemFull(stockTakeId, {
        product_id: s.product_id,
        batch_number: s.batch_number,
        location: s.location,
        uom: s.uom,
        qty_system: s.quantity,
        qty_physical: 0,
        counter_1: null,
        counter_2: null,
        counter_3: null,
      });
    }
  }

  static async getActiveLockedLocations(): Promise<any[]> {
    const rows = await dbExec(
      `SELECT DISTINCT sti.location
       FROM stock_take_items sti
       JOIN stock_take st ON st.id = sti.stock_take_id
       WHERE st.status IN ('Counting','Review')
         AND sti.location IS NOT NULL`,
    );
    return rows.map((r: any) => r[Object.keys(r)[0]]);
  }

  static async addItem(stockTakeId: number, data: any): Promise<number> {
    try {
      const qtySystem = Number(data.qty_system);
      const qtyPhysical = Number(data.qty_physical);
      const difference = qtyPhysical - qtySystem;

      let status: string;
      if (difference > 0) {
        status = 'Plus';
      } else if (difference < 0) {
        status = 'Minus';
      } else {
        status = 'Clear';
      }

      const ins = await dbExec(
        `INSERT INTO stock_take_items
           (stock_take_id, product_id, batch_number, location, qty_system, qty_physical, difference, status, notes)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)`,
        [
          stockTakeId,
          data.product_id,
          data.batch_number ?? null,
          data.location ?? null,
          qtySystem,
          qtyPhysical,
          difference,
          status,
          data.notes ?? null,
        ],
      );

      return Number((ins as any).insertId);
    } catch (e: any) {
      console.error('Error adding stock take item: ' + e.message);
      return 0;
    }
  }

  static async update(id: number, data: any): Promise<boolean> {
    try {
      await dbExec(
        `UPDATE stock_take
         SET take_date = ?, status = ?, notes = ?
         WHERE id = ?`,
        [
          data.take_date,
          data.status,
          data.notes ?? null,
          id,
        ],
      );
      return true;
    } catch (e: any) {
      console.error('Error updating stock take: ' + e.message);
      return false;
    }
  }

  static async delete(id: number): Promise<boolean> {
    try {
      await withTransaction(async () => {
        await dbExec('DELETE FROM stock_take_items WHERE stock_take_id = ?', [id]);
        await dbExec('DELETE FROM stock_take WHERE id = ?', [id]);
      });
      return true;
    } catch (e: any) {
      console.error('Error deleting stock take: ' + e.message);
      return false;
    }
  }

  static async getSystemStock(productId: number, location: any = null, batchNumber: any = null): Promise<number> {
    try {
      let sql = `SELECT COALESCE(SUM(quantity), 0) as total_qty
                 FROM stock
                 WHERE product_id = ?`;
      const params: any[] = [productId];

      if (location !== null && location !== '') {
        sql += ' AND location = ?';
        params.push(location);
      }

      if (batchNumber !== null && batchNumber !== '') {
        sql += ' AND batch_number = ?';
        params.push(batchNumber);
      }

      const total = await dbScalar(sql, params);
      return Number(total ?? 0);
    } catch (e: any) {
      console.error('Error getting system stock: ' + e.message);
      return 0;
    }
  }

  static async getStats(): Promise<any> {
    try {
      const stats: any = {};

      const total = await dbScalar('SELECT COUNT(*) as count FROM stock_take');
      stats.total = Number(total ?? 0);

      const thisMonth = await dbScalar(
        `SELECT COUNT(*) as count
         FROM stock_take
         WHERE MONTH(take_date) = MONTH(CURDATE())
           AND YEAR(take_date) = YEAR(CURDATE())`,
      );
      stats.this_month = Number(thisMonth ?? 0);

      const thisYear = await dbScalar(
        `SELECT COUNT(*) as count
         FROM stock_take
         WHERE YEAR(take_date) = YEAR(CURDATE())`,
      );
      stats.this_year = Number(thisYear ?? 0);

      const avgRow = await dbExecFirst(
        `SELECT AVG(
                CASE
                    WHEN (SELECT COUNT(*) FROM stock_take_items WHERE stock_take_id = st.id) > 0 THEN
                        ((SELECT SUM(qty_physical) FROM stock_take_items WHERE stock_take_id = st.id AND status = 'Clear') /
                         (SELECT SUM(qty_physical) FROM stock_take_items WHERE stock_take_id = st.id)) * 100
                    ELSE 100
                END
            ) as avg_accuracy
         FROM stock_take st
         WHERE YEAR(take_date) = YEAR(CURDATE())
           AND st.status = 'Adjusted'`,
      );
      stats.avg_accuracy = Math.round(Number(avgRow?.avg_accuracy ?? 100) * 100) / 100;

      return stats;
    } catch (e: any) {
      console.error('Error getting stock take stats: ' + e.message);
      return {
        total: 0,
        this_month: 0,
        this_year: 0,
        avg_accuracy: 100,
      };
    }
  }

  static async startCounting(id: number): Promise<void> {
    const st = await StockTake.getById(id);
    if (!st) throw new Error('Stock take tidak ditemukan');
    if (st.status !== 'Draft') throw new Error('Status harus Draft untuk memulai Counting');
    await dbExec("UPDATE stock_take SET status='Counting', counting_round='c1', updated_at=NOW() WHERE id=?", [id]);
  }

  static async saveC1(id: number, values: any): Promise<void> {
    const st = await StockTake.getById(id);
    if (!st) throw new Error('Stock take tidak ditemukan');
    if (st.status !== 'Counting' || st.counting_round !== 'c1')
      throw new Error('Tidak bisa simpan Counter 1 — bukan giliran C1');
    for (const itemId of Object.keys(values)) {
      const val = values[itemId];
      const c1 = (val !== '' && val !== null) ? Number(val) : null;
      await dbExec('UPDATE stock_take_items SET counter_1=? WHERE id=? AND stock_take_id=?', [c1, Number(itemId), id]);
    }
  }

  static async advanceToC2(id: number, c1Values: any): Promise<void> {
    const st = await StockTake.getById(id);
    if (!st) throw new Error('Stock take tidak ditemukan');
    if (st.status !== 'Counting' || st.counting_round !== 'c1')
      throw new Error('Harus di tahap Counter 1 untuk maju ke Counter 2');
    await StockTake.saveC1(id, c1Values);
    await dbExec("UPDATE stock_take SET counting_round='c2', updated_at=NOW() WHERE id=?", [id]);
  }

  static async saveC2(id: number, values: any): Promise<void> {
    const st = await StockTake.getById(id);
    if (!st) throw new Error('Stock take tidak ditemukan');
    if (st.status !== 'Counting' || st.counting_round !== 'c2')
      throw new Error('Tidak bisa simpan Counter 2 — bukan giliran C2');
    for (const itemId of Object.keys(values)) {
      const val = values[itemId];
      const c2 = (val !== '' && val !== null) ? Number(val) : null;
      await dbExec('UPDATE stock_take_items SET counter_2=? WHERE id=? AND stock_take_id=?', [c2, Number(itemId), id]);
    }
  }

  static async saveCounters(id: number, counters: any): Promise<void> {
    for (const itemId of Object.keys(counters)) {
      const v = counters[itemId];
      const c1 = (v.c1 !== '' && v.c1 !== null) ? Number(v.c1) : null;
      const c2 = (v.c2 !== '' && v.c2 !== null) ? Number(v.c2) : null;
      const c3 = (v.c3 !== '' && v.c3 !== null) ? Number(v.c3) : null;
      await dbExec('UPDATE stock_take_items SET counter_1=?, counter_2=?, counter_3=? WHERE id=? AND stock_take_id=?', [c1, c2, c3, Number(itemId), id]);
    }
  }

  static async finishCounting(id: number, c2Values: any = {}): Promise<void> {
    await withTransaction(async () => {
      const st = await StockTake.getById(id);
      if (!st) throw new Error('Stock take tidak ditemukan');
      if (st.status !== 'Counting') throw new Error('Status harus Counting');
      if (st.counting_round !== 'c2')
        throw new Error('Counter 1 belum selesai — selesaikan Counter 1 dulu');
      if (c2Values && Object.keys(c2Values).length) await StockTake.saveC2(id, c2Values);

      await dbExec(
        `UPDATE stock_take_items sti
         SET qty_system = (
             SELECT COALESCE(SUM(s.quantity), 0)
             FROM stock s
             WHERE s.product_id = sti.product_id
               AND (sti.location    IS NULL OR s.location     = sti.location)
               AND (sti.batch_number IS NULL OR s.batch_number <=> sti.batch_number)
               AND s.stock_status = 'Available'
         )
         WHERE stock_take_id = ?`,
        [id],
      );

      const items = await StockTake.getItems(id);
      for (const item of items) {
        const c1 = item.counter_1 !== null ? Number(item.counter_1) : null;
        const c2 = item.counter_2 !== null ? Number(item.counter_2) : null;
        const c3 = item.counter_3 !== null ? Number(item.counter_3) : null;

        let qtyPhysical: number;
        if (c1 !== null && c2 !== null && Math.abs(c1 - c2) < 0.001) {
          qtyPhysical = c1;
        } else if (c3 !== null) {
          qtyPhysical = c3;
        } else if (c2 !== null) {
          qtyPhysical = c2;
        } else if (c1 !== null) {
          qtyPhysical = c1;
        } else {
          qtyPhysical = 0;
        }

        const difference = qtyPhysical - Number(item.qty_system);
        const status = difference > 0.001 ? 'Plus' : (difference < -0.001 ? 'Minus' : 'Clear');
        await dbExec('UPDATE stock_take_items SET qty_physical=?, difference=?, status=? WHERE id=?', [qtyPhysical, difference, status, item.id]);
      }

      await dbExec("UPDATE stock_take SET status='Review', updated_at=NOW() WHERE id=?", [id]);
    });
  }

  static async saveReview(id: number, physicals: any): Promise<void> {
    const st = await StockTake.getById(id);
    if (!st) throw new Error('Stock take tidak ditemukan');
    if (st.status !== 'Review') throw new Error('Status harus Review');

    for (const itemId of Object.keys(physicals)) {
      const qtyPhysical = Number(physicals[itemId]);
      const row = await dbExecFirst('SELECT qty_system FROM stock_take_items WHERE id=? AND stock_take_id=?', [Number(itemId), id]);
      if (!row) continue;
      const difference = qtyPhysical - Number(row.qty_system);
      const status = difference > 0.001 ? 'Plus' : (difference < -0.001 ? 'Minus' : 'Clear');
      await dbExec('UPDATE stock_take_items SET qty_physical=?, difference=?, status=? WHERE id=? AND stock_take_id=?', [qtyPhysical, difference, status, Number(itemId), id]);
    }
  }

  static async applyAdjustment(id: number): Promise<void> {
    await withTransaction(async () => {
      const st = await StockTake.getById(id);
      if (!st) throw new Error('Stock take tidak ditemukan');
      if (st.status !== 'Review') throw new Error('Status harus Review untuk apply adjustment');

      const items = await StockTake.getItems(id);
      for (const item of items) {
        const diff = Number(item.difference);
        if (Math.abs(diff) < 0.001) continue;
        const productId = Number(item.product_id);
        const location = item.location;
        const batch = item.batch_number;
        const uom = item.uom ?? 'Drum';
        const qtyPhysical = Number(item.qty_physical);

        const stockRow = await dbExecFirst(
          `SELECT id FROM stock
           WHERE product_id=? AND location=? AND batch_number<=>?
             AND stock_status='Available' LIMIT 1`,
          [productId, location, batch],
        );

        if (stockRow) {
          if (qtyPhysical <= 0.001) {
            await dbExec('DELETE FROM stock WHERE id=?', [stockRow.id]);
          } else {
            await dbExec('UPDATE stock SET quantity=?, updated_at=NOW() WHERE id=?', [qtyPhysical, stockRow.id]);
          }
        } else if (qtyPhysical > 0.001) {
          await dbExec(
            `INSERT INTO stock (product_id, batch_number, location, quantity, uom, stock_status) VALUES (?,?,?,?,?,'Available')`,
            [productId, batch, location, qtyPhysical, uom],
          );
        }

        const balance = Number(await dbScalar('SELECT balance FROM stock_ledger WHERE product_id=? ORDER BY id DESC LIMIT 1', [productId]) ?? 0);
        const qIn = diff > 0 ? diff : 0;
        const qOut = diff < 0 ? Math.abs(diff) : 0;
        const newBalance = balance + qIn - qOut;

        await dbExec(
          `INSERT INTO stock_ledger
             (transaction_date, product_id, transaction_type, reference_type,
              reference_id, reference_number, batch_number,
              quantity_in, quantity_out, uom, balance, location, notes)
           VALUES (CURDATE(),?,?,?,?,?,?,?,?,?,?,?,?)`,
          [
            productId, 'ADJUSTMENT', 'StockTake', id, st.take_number,
            batch, qIn, qOut, uom, newBalance, location,
            'Stock Take Adjustment ' + (diff > 0 ? '+' + diff : '' + diff),
          ],
        );
      }

      await dbExec("UPDATE stock_take SET status='Adjusted', updated_at=NOW() WHERE id=?", [id]);
    });
  }

  static async addItemFull(stockTakeId: number, data: any): Promise<number> {
    try {
      const qtySystem = Number(data.qty_system);
      const qtyPhysical = Number(data.qty_physical);
      const difference = qtyPhysical - qtySystem;

      let status: string;
      if (difference > 0) status = 'Plus';
      else if (difference < 0) status = 'Minus';
      else status = 'Clear';

      const ins = await dbExec(
        `INSERT INTO stock_take_items
           (stock_take_id, product_id, batch_number, uom, location,
            qty_system, counter_1, counter_2, counter_3,
            qty_physical, difference, status, notes, counter_by)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)`,
        [
          stockTakeId,
          data.product_id,
          data.batch_number ?? null,
          data.uom ?? null,
          data.location ?? null,
          qtySystem,
          data.counter_1 ?? null,
          data.counter_2 ?? null,
          data.counter_3 ?? null,
          qtyPhysical,
          difference,
          status,
          data.notes ?? null,
          data.counter_by ?? null,
        ],
      );

      return Number((ins as any).insertId);
    } catch (e: any) {
      console.error('Error adding stock take item: ' + e.message);
      return 0;
    }
  }
}

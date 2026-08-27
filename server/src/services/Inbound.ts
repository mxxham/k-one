import { db, dbExec, dbExecFirst, dbScalar, withTransaction } from '../db';
import { ctx, todayYmd } from '../helpers';

/**
 * Port of classes/Inbound.php.
 * Queries go through dbExec/dbExecFirst so statements inside
 * `withTransaction` share the same connection, matching PHP's PDO singleton.
 */
export class Inbound {
  static toYmd(d: Date): string {
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return `${y}-${m}-${day}`;
  }

  static async generateNumber(): Promise<string> {
    const now = new Date();
    const prefix = `IN-${now.getFullYear()}${String(now.getMonth() + 1).padStart(2, '0')}-`;
    const rows = await dbExec(
      'SELECT order_number FROM inbound_orders WHERE order_number LIKE ? ORDER BY order_number DESC LIMIT 1',
      [prefix + '%'],
    );
    const last = rows[0]?.order_number ?? null;
    let seq = last ? parseInt(last.slice(last.lastIndexOf('-') + 1), 10) + 1 : 1;
    let maxTries = 20;
    while (maxTries-- > 0) {
      const number = prefix + String(seq).padStart(4, '0');
      const chk = await dbExecFirst('SELECT id FROM inbound_orders WHERE order_number = ? LIMIT 1', [number]);
      if (!chk) return number;
      seq++;
    }
    const hms = `${String(now.getHours()).padStart(2, '0')}${String(now.getMinutes()).padStart(2, '0')}${String(now.getSeconds()).padStart(2, '0')}`;
    return prefix + hms + Math.floor(Math.random() * 90 + 10);
  }

  static async getAll(status: string | null = null, limit: number | null = null, offset = 0, odNo: string | null = null): Promise<any[]> {
    const conn = await db().getConnection();
    try {
      await conn.query('SET SESSION group_concat_max_len = 65536');
      const conditions: string[] = [];
      const params: any[] = [];
      if (status) {
        conditions.push('io.status = ?');
        params.push(status);
      }
      if (odNo) {
        conditions.push('ii.od_number LIKE ?');
        params.push('%' + odNo + '%');
      }
      const where = conditions.length ? 'WHERE ' + conditions.join(' AND ') : '';
      let sql = `SELECT io.*,
            u.full_name as created_by_name,
            r.full_name as received_by_name,
            COUNT(DISTINCT ii.id) as total_items,
            SUM(ii.actual_qty) as total_qty,
            SUM(ii.pallet) as total_pallet,
            GROUP_CONCAT(DISTINCT ii.od_number ORDER BY ii.id SEPARATOR ', ') as od_numbers
            FROM inbound_orders io
            LEFT JOIN users u ON io.created_by = u.id
            LEFT JOIN users r ON io.received_by = r.id
            LEFT JOIN inbound_items ii ON io.id = ii.inbound_order_id
            ${where}
            GROUP BY io.id
            ORDER BY COALESCE(NULLIF(TRIM(io.shipment_no), ''), io.order_number) DESC,
                     io.order_date DESC, io.created_at DESC`;
      if (limit) sql += ' LIMIT ' + limit + ' OFFSET ' + offset;
      const [rows] = await conn.query(sql, params);
      return rows as any[];
    } finally {
      conn.release();
    }
  }

  static async countAll(status: string | null = null, odNo: string | null = null): Promise<number> {
    const conditions: string[] = [];
    const params: any[] = [];
    if (status) {
      conditions.push('io.status = ?');
      params.push(status);
    }
    if (odNo) {
      conditions.push('ii.od_number LIKE ?');
      params.push('%' + odNo + '%');
    }
    const where = conditions.length ? 'WHERE ' + conditions.join(' AND ') : '';
    const n = await dbScalar(
      `SELECT COUNT(DISTINCT io.id) FROM inbound_orders io LEFT JOIN inbound_items ii ON io.id = ii.inbound_order_id ${where}`,
      params,
    );
    return Number(n ?? 0);
  }

  static async getById(id: number): Promise<any> {
    return dbExecFirst(
      `SELECT io.*,
              u.full_name as created_by_name,
              r.full_name as received_by_name
       FROM inbound_orders io
       LEFT JOIN users u ON io.created_by = u.id
       LEFT JOIN users r ON io.received_by = r.id
       WHERE io.id = ?`,
      [id],
    );
  }

  static async getItems(inboundId: number): Promise<any[]> {
    return dbExec(
      `SELECT ii.*,
              p.product_code, p.product_name, p.uom_type, p.uom_per_pallet
       FROM inbound_items ii
       JOIN products p ON ii.product_id = p.id
       WHERE ii.inbound_order_id = ?
       ORDER BY ii.id`,
      [inboundId],
    );
  }

  static async getItemLocations(itemId: number): Promise<any[]> {
    return dbExec(
      `SELECT sl.*,
              COALESCE(sl.original_quantity, sl.quantity) AS display_quantity
       FROM stock_locations sl
       WHERE sl.inbound_item_id = ?
       ORDER BY sl.pallet_seq`,
      [itemId],
    );
  }

  static async getOrderLocations(inboundId: number): Promise<any[]> {
    return dbExec(
      `SELECT sl.*,
              p.product_code, p.product_name,
              ii.batch_number, ii.uom, ii.exp_date
       FROM stock_locations sl
       JOIN inbound_items ii ON sl.inbound_item_id = ii.id
       JOIN products p ON ii.product_id = p.id
       WHERE ii.inbound_order_id = ?
       ORDER BY sl.pallet_seq`,
      [inboundId],
    );
  }

  static async create(data: any): Promise<number> {
    return withTransaction(async () => {
      const inboundNumber = data.shipment_no ? data.shipment_no : await Inbound.generateNumber();

      let receivedBy: number | null = null;
      if (data.received_by) {
        if (typeof data.received_by === 'number' || /^\d+$/.test(String(data.received_by))) {
          receivedBy = Number(data.received_by);
        } else {
          const u = await dbExecFirst('SELECT id FROM users WHERE full_name = ? LIMIT 1', [data.received_by]);
          receivedBy = u?.id ?? null;
        }
      }

      const ins = await dbExec(
        `INSERT INTO inbound_orders
           (order_number, order_date, carrier_name, po_number, shipment_no, do_number,
            container_no, armada_no, production_date, expected_date,
            received_by, received_date, status, notes, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`,
        [
          inboundNumber,
          data.order_date,
          data.carrier_name ?? null,
          data.po_number ?? null,
          data.shipment_no ?? null,
          data.do_number ?? null,
          data.container_no ?? null,
          data.armada_no ?? null,
          data.production_date || null,
          data.expected_date || null,
          receivedBy,
          data.received_date || null,
          data.status ?? 'Draft',
          data.notes ?? null,
          ctx().user?.id ?? null,
        ],
      );
      const inboundId = Number((ins as any).insertId);

      if (Array.isArray(data.items)) {
        for (const item of data.items) {
          await Inbound.addItem(inboundId, item);
        }
      }
      return inboundId;
    });
  }

  static async addItem(inboundId: number, item: any): Promise<number> {
    const productInfo = await dbExecFirst(
      `SELECT uom_type, uom_per_pallet, liters_per_unit, max_sku_qty, max_trans_qty
       FROM products WHERE id = ?`,
      [item.product_id],
    );
    if (!productInfo) throw new Error('Product not found');

    const quantity = Number(item.quantity ?? 0);
    const uom = item.uom ?? productInfo.uom_type;
    const uomPerPallet = Math.max(1, Number(productInfo.uom_per_pallet ?? 4) || 1);
    const pallet = Inbound.calculatePallet(quantity, uomPerPallet);

    const inbound = await Inbound.getById(inboundId);
    const mfgDate = item.manufacture_date ?? null;
    let expDate = item.exp_date ?? null;
    if (mfgDate) {
      const d = new Date(mfgDate);
      if (!isNaN(d.getTime())) {
        d.setFullYear(d.getFullYear() + 4);
        expDate = Inbound.toYmd(d);
      }
    } else if (!expDate && inbound?.production_date) {
      expDate = Inbound.calculateExpiryDate(inbound.production_date);
    }

    const batchNumber = item.batch_number ?? item.batch_no ?? null;

    let firstLocation = item.location ?? null;
    if (Array.isArray(item.pallet_locations) && item.pallet_locations.length) {
      firstLocation = item.pallet_locations[0].location_code ?? firstLocation;
    }

    const ins = await dbExec(
      `INSERT INTO inbound_items
         (inbound_order_id, od_number, so_number, product_id, batch_number, location,
          quantity, uom, actual_qty, pallet, pallet_no,
          manufacture_date, exp_date, stock_status, in_process_status, notes)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`,
      [
        inboundId,
        item.od_number ?? null,
        item.so_number ?? null,
        item.product_id,
        batchNumber,
        firstLocation,
        quantity,
        uom,
        item.actual_qty ?? quantity,
        pallet,
        item.pallet_no ?? null,
        item.manufacture_date ?? null,
        expDate,
        item.stock_status ?? 'Pending',
        item.in_process_status ?? 'Dues In',
        item.notes ?? null,
      ],
    );
    const itemId = Number((ins as any).insertId);

    const skipPallets = (item.in_process_status ?? '') === 'Unserviceable'
      || (item.stock_status ?? '') === 'Rejected';

    if (!skipPallets) {
      if (Array.isArray(item.pallet_locations) && item.pallet_locations.length) {
        await Inbound.saveItemLocations(itemId, null, item.pallet_locations, batchNumber, uom);
      } else if (item.location) {
        const dist = Inbound.calculatePalletDistribution(quantity, uomPerPallet);
        const palletLocs = dist.map((p) => ({ ...p, location_code: item.location }));
        await Inbound.saveItemLocations(itemId, null, palletLocs, batchNumber, uom);
      }
    }
    return itemId;
  }

  static async saveItemLocations(itemId: number, stockId: number | null, palletLocs: any[], batchNumber: string | null, uom = 'EA'): Promise<void> {
    await dbExec('DELETE FROM stock_locations WHERE inbound_item_id = ?', [itemId]);
    if (!palletLocs.length) return;

    const placeholders: string[] = [];
    const values: any[] = [];
    for (const p of palletLocs) {
      const qty = Number(p.quantity ?? 0);
      placeholders.push("(?, ?, ?, ?, ?, ?, ?, ?, ?, 'Available')");
      values.push(
        stockId,
        p.location_code,
        p.pallet_seq ?? p.pallet_number ?? 1,
        qty,
        qty,
        uom,
        (p.is_full ?? true) ? 1 : 0,
        batchNumber,
        itemId,
      );
    }
    await dbExec(
      `INSERT INTO stock_locations
         (stock_id, location_code, pallet_seq, quantity, original_quantity, uom,
          is_full_pallet, batch_number, inbound_item_id, status)
       VALUES ${placeholders.join(',')}`,
      values,
    );
  }

  static calculatePallet(quantity: number, uomPerPallet: number): number {
    if (uomPerPallet === 0) return 0;
    return Math.ceil(quantity / uomPerPallet);
  }

  static calcPalletByLocation(quantity: number, uomPerPallet: number, locationCode: string): number {
    if (uomPerPallet <= 0) return 0;
    const level = locationCode[4] ? locationCode[4].toUpperCase() : 'B';
    if (level === 'A') return Math.round((quantity / uomPerPallet) * 100) / 100;
    return Math.ceil(quantity / uomPerPallet);
  }

  static calculateExpiryDate(productionDate: string | null, years = 4): string | null {
    if (!productionDate) return null;
    const date = new Date(productionDate);
    if (isNaN(date.getTime())) return null;
    date.setFullYear(date.getFullYear() + years);
    return Inbound.toYmd(date);
  }

  static calculatePalletDistribution(quantity: number, uomPerPallet: number): Array<{ pallet_seq: number; quantity: number; is_full: boolean }> {
    const fullPallets = Math.floor(Number(quantity) / Number(uomPerPallet));
    const remainder = Number(quantity) % Number(uomPerPallet);
    const dist: Array<{ pallet_seq: number; quantity: number; is_full: boolean }> = [];
    let palletNum = 1;
    for (let i = 0; i < fullPallets; i++) {
      dist.push({ pallet_seq: palletNum++, quantity: uomPerPallet, is_full: true });
    }
    if (remainder > 0) {
      dist.push({ pallet_seq: palletNum, quantity: remainder, is_full: false });
    }
    return dist;
  }

  static async update(id: number, data: any): Promise<boolean> {
    return withTransaction(async () => {
      await dbExec(
        `UPDATE inbound_orders SET
           order_date = ?, carrier_name = ?, po_number = ?,
           shipment_no = ?, do_number = ?,
           container_no = ?, armada_no = ?,
           production_date = ?, expected_date = ?, status = ?, notes = ?
         WHERE id = ?`,
        [
          data.order_date,
          data.carrier_name ?? null,
          data.po_number ?? null,
          data.shipment_no ?? null,
          data.do_number ?? null,
          data.container_no ?? null,
          data.armada_no ?? null,
          data.production_date || null,
          data.expected_date || null,
          data.status ?? 'Draft',
          data.notes ?? null,
          id,
        ],
      );

      if ('received_date' in data || 'received_by' in data) {
        let receivedBy: number | null = null;
        if (data.received_by) {
          if (typeof data.received_by === 'number' || /^\d+$/.test(String(data.received_by))) {
            receivedBy = Number(data.received_by);
          } else {
            const u = await dbExecFirst('SELECT id FROM users WHERE full_name = ? LIMIT 1', [data.received_by]);
            receivedBy = u?.id ?? ctx().user?.id ?? null;
          }
        }
        await dbExec('UPDATE inbound_orders SET received_by = ?, received_date = ? WHERE id = ?', [
          receivedBy,
          data.received_date || null,
          id,
        ]);
      }
      return true;
    });
  }

  static async updateItem(itemId: number, data: any): Promise<boolean> {
    await dbExec(
      `UPDATE inbound_items SET
         batch_number = ?, location = ?, quantity = ?, uom = ?, actual_qty = ?,
         manufacture_date = ?, exp_date = ?, stock_status = ?, notes = ?
       WHERE id = ?`,
      [
        data.batch_number ?? data.batch_no ?? null,
        data.location ?? null,
        data.quantity ?? 0,
        data.uom ?? 'EA',
        data.actual_qty ?? data.quantity ?? 0,
        data.manufacture_date ?? null,
        data.exp_date ?? null,
        data.stock_status ?? 'Accepted',
        data.notes ?? null,
        itemId,
      ],
    );

    if (Array.isArray(data.pallet_locations) && data.pallet_locations.length) {
      await Inbound.saveItemLocations(
        itemId,
        null,
        data.pallet_locations,
        data.batch_number ?? null,
        data.uom ?? 'EA',
      );
    }
    return true;
  }

  static async updateItemDates(itemId: number, manufactureDate: string | null, expDate: string | null): Promise<boolean> {
    const row = await dbExecFirst(
      `SELECT ii.*, io.status AS ord_status
       FROM inbound_items ii
       JOIN inbound_orders io ON ii.inbound_order_id = io.id
       WHERE ii.id = ?`,
      [itemId],
    );
    if (!row) return false;

    const mfg = manufactureDate !== null && manufactureDate !== '' ? manufactureDate : null;
    const exp = expDate !== null && expDate !== '' ? expDate : null;
    await dbExec('UPDATE inbound_items SET manufacture_date = ?, exp_date = ? WHERE id = ?', [mfg, exp, itemId]);

    if ((row.ord_status ?? '') === 'Completed') {
      const batch = row.batch_number ?? row.batch_no ?? null;
      const pid = Number(row.product_id);
      await dbExec(
        `UPDATE stock s
           JOIN stock_locations sl ON sl.stock_id = s.id
         SET s.manufacture_date = ?, s.expiry_date = ?
         WHERE sl.inbound_item_id = ?`,
        [mfg, exp, itemId],
      );
      await dbExec(
        'UPDATE stock SET manufacture_date = ?, expiry_date = ? WHERE product_id = ? AND batch_number <=> ?',
        [mfg, exp, pid, batch],
      );
    }
    return true;
  }

  static async updateItemPalletNo(itemId: number, palletNo: string | null): Promise<boolean> {
    const val = palletNo !== null && palletNo.trim() !== '' ? palletNo.trim().toUpperCase() : null;
    await dbExec('UPDATE inbound_items SET pallet_no = ? WHERE id = ?', [val, itemId]);
    return true;
  }

  static async updateItemQty(itemId: number, newQty: number): Promise<boolean> {
    return withTransaction(async () => {
      const item = await dbExecFirst(
        `SELECT ii.*, io.status AS ord_status, p.uom_per_pallet
         FROM inbound_items ii
         JOIN inbound_orders io ON io.id = ii.inbound_order_id
         JOIN products p ON p.id = ii.product_id
         WHERE ii.id = ?`,
        [itemId],
      );
      if (!item) throw new Error('Item tidak ditemukan');
      if (item.in_process_status !== 'Dues In') {
        throw new Error('Hanya item berstatus Dues In yang bisa diedit qty-nya');
      }
      if (newQty <= 0) throw new Error('Qty harus lebih dari 0');

      const uomPlt = Math.max(1, Number(item.uom_per_pallet ?? 4) || 1);
      const newPallet = Math.ceil(newQty / uomPlt);

      await dbExec('UPDATE inbound_items SET quantity=?, actual_qty=?, pallet=? WHERE id=?', [
        newQty, newQty, newPallet, itemId,
      ]);

      const batch = item.batch_number ?? item.batch_no ?? null;
      await dbExec(
        `UPDATE stock SET quantity=?, pallet=?, updated_at=NOW()
         WHERE product_id=? AND batch_number<=>? AND stock_status IN ('Dues In','Pending')`,
        [newQty, newPallet, item.product_id, batch],
      );

      const existingLocs = await dbExec(
        `SELECT pallet_seq, location_code
         FROM stock_locations
         WHERE inbound_item_id=? AND stock_id IS NULL
         ORDER BY pallet_seq ASC`,
        [itemId],
      );
      if (Array.isArray(existingLocs) && existingLocs.length) {
        const dist = Inbound.calculatePalletDistribution(newQty, uomPlt);
        const lastLoc = existingLocs[existingLocs.length - 1].location_code;
        const palletLocs = dist.map((p, i) => ({
          ...p,
          location_code: existingLocs[i]?.location_code ?? lastLoc,
        }));
        await Inbound.saveItemLocations(itemId, null, palletLocs, batch, item.uom);
      }
      return true;
    });
  }

  static async deleteItem(itemId: number): Promise<boolean> {
    return withTransaction(async () => {
      const itemData = await dbExecFirst(
        `SELECT ii.*, io.status as inbound_status
         FROM inbound_items ii
         JOIN inbound_orders io ON ii.inbound_order_id = io.id
         WHERE ii.id = ?`,
        [itemId],
      );

      if (itemData && itemData.inbound_status === 'Completed') {
        const batchVal = itemData.batch_number ?? itemData.batch_no ?? null;
        const location = itemData.location ?? null;
        const qty = Number(itemData.actual_qty || itemData.quantity || 0);

        if (batchVal && qty > 0) {
          const sql = `SELECT id, quantity, pallet FROM stock
                       WHERE product_id = ? AND batch_number = ?` + (location ? ' AND location = ?' : '') + ' LIMIT 1';
          const params: any[] = [itemData.product_id, batchVal];
          if (location) params.push(location);
          const stockRow = await dbExecFirst(sql, params);

          if (stockRow) {
            const newQty = Math.max(0, Number(stockRow.quantity) - qty);
            const palletRatio = Number(stockRow.quantity) > 0 ? qty / Number(stockRow.quantity) : 1;
            const newPlt = Math.max(0, Number(stockRow.pallet) - (Number(stockRow.pallet) * palletRatio));

            if (newQty <= 0) {
              await dbExec('DELETE FROM stock WHERE id = ?', [stockRow.id]);
            } else {
              await dbExec('UPDATE stock SET quantity = ?, pallet = ?, updated_at = NOW() WHERE id = ?', [
                newQty,
                Math.round(newPlt * 10000) / 10000,
                stockRow.id,
              ]);
            }
            await dbExec(
              `INSERT INTO stock_ledger
                 (transaction_date, product_id, transaction_type, reference_type,
                  reference_id, batch_number, quantity_in, quantity_out, uom, balance, notes)
               VALUES (NOW(), ?, 'OUT', 'Inbound-Reversal', ?, ?, 0, ?, ?, 0, 'Item deleted from completed inbound')`,
              [itemData.product_id, itemData.inbound_order_id, batchVal, qty, itemData.uom ?? 'Drum'],
            );
          }
        }
      }

      await dbExec('DELETE FROM stock_locations WHERE inbound_item_id = ?', [itemId]);
      await dbExec('DELETE FROM inbound_items WHERE id = ?', [itemId]);
      return true;
    });
  }

  static async complete(id: number): Promise<boolean> {
    return withTransaction(async () => {
      const inbound = await Inbound.getById(id);
      const items = await Inbound.getItems(id);

      const stockStatusMap: Record<string, string> = {
        'ATP': 'Available',
        'Picked': 'Available',
        'Dues In': 'Dues In',
        'Unserviceable': 'Rejected',
      };

      for (const item of items) {
        const batchVal = item.batch_number ?? item.batch_no ?? null;
        const inProcess = item.in_process_status ?? 'Dues In';
        if (inProcess === 'Dues In') continue;
        if (inProcess === 'Goods Received') continue;

        const stockTarget = stockStatusMap[inProcess] ?? 'Available';
        const pid = item.product_id;
        const totalQty = Number(item.actual_qty ?? item.quantity ?? 0);
        const uomPerPlt = Math.max(1, Number(item.uom_per_pallet ?? 4) || 1);

        await dbExec(
          `DELETE s FROM stock s JOIN stock_locations sl ON sl.stock_id = s.id WHERE sl.inbound_item_id = ?`,
          [item.id],
        );
        await dbExec('UPDATE stock_locations SET stock_id=NULL WHERE inbound_item_id=?', [item.id]);
        await dbExec(
          `DELETE s FROM stock s
           WHERE s.product_id = ? AND s.batch_number <=> ?
             AND (s.location IS NULL OR s.location = 'UNALLOCATED')
             AND NOT EXISTS (SELECT 1 FROM stock_locations sl2 WHERE sl2.stock_id = s.id)`,
          [pid, batchVal],
        );
        await dbExec(
          `DELETE FROM stock
           WHERE product_id = ? AND batch_number <=> ?
             AND stock_status IN ('Dues In', 'Pending')
             AND NOT EXISTS (SELECT 1 FROM stock_locations sl3 WHERE sl3.stock_id = stock.id)`,
          [pid, batchVal],
        );

        if (inProcess === 'Unserviceable') {
          await dbExec(
            `UPDATE inbound_items SET stock_status='Rejected', location='QUA_SHELL' WHERE id=?`,
            [item.id],
          );
          await dbExec('DELETE FROM stock_locations WHERE inbound_item_id=?', [item.id]);
          await dbExec(
            `DELETE FROM stock WHERE product_id=? AND batch_number<=>? AND location='QUA_SHELL' AND stock_status='Rejected'`,
            [pid, batchVal],
          );
          const plt = uomPerPlt > 0 ? Math.ceil(totalQty / uomPerPlt) : 1;
          await dbExec(
            `INSERT INTO stock
               (product_id, batch_number, location, quantity, uom,
                pallet, manufacture_date, expiry_date, stock_status)
             VALUES (?,?,'QUA_SHELL',?,?,?,?,?,'Rejected')`,
            [pid, batchVal, totalQty, item.uom, plt, item.manufacture_date, item.exp_date],
          );
          continue;
        }

        const palletRows = await Inbound.getItemLocations(item.id);

        if (palletRows.length) {
          const palletTotal = palletRows.reduce((s: number, p: any) => s + Number(p.quantity), 0);
          if (palletTotal > 0.001 && Math.abs(palletTotal - totalQty) > 0.001) {
            const scale = totalQty / palletTotal;
            for (const pr of palletRows) {
              pr.quantity = Math.round(Number(pr.quantity) * scale * 10000) / 10000;
            }
          }

          const locGroups: Record<string, { loc: string; batch: any; qty: number; rows: any[] }> = {};
          for (const pl of palletRows) {
            const loc = pl.location_code ?? 'UNALLOCATED';
            const qty = Number(pl.quantity);
            const batch = pl.batch_number ?? batchVal;
            const key = loc + '|' + batch;
            if (!locGroups[key]) locGroups[key] = { loc, batch, qty: 0, rows: [] };
            locGroups[key].qty += qty;
            locGroups[key].rows.push(pl.id);
          }

          let assignedQty = 0;

          for (const group of Object.values(locGroups)) {
            const loc = group.loc;
            const qty = group.qty;
            const batch = group.batch;
            assignedQty += qty;

            const level = loc[4] ? loc[4].toUpperCase() : 'B';
            let plt: number;
            if (level === 'A') {
              plt = uomPerPlt > 0 ? Math.round((qty / uomPerPlt) * 10000) / 10000 : 1;
            } else {
              plt = group.rows.length;
            }
            plt = Math.max(1, plt);

            const ins = await dbExec(
              `INSERT INTO stock
                 (product_id, batch_number, location, quantity, uom,
                  pallet, manufacture_date, expiry_date, stock_status)
               VALUES (?,?,?,?,?,?,?,?,?)`,
              [pid, batch, loc, qty, item.uom, plt, item.manufacture_date, item.exp_date, stockTarget],
            );
            const newId = Number((ins as any).insertId);
            for (const slId of group.rows) {
              await dbExec('UPDATE stock_locations SET stock_id=? WHERE id=?', [newId, slId]);
            }
          }

          const remainderQty = totalQty - assignedQty;
          if (remainderQty > 0.001) {
            const fallbackLoc = 'UNALLOCATED';
            const plt = Math.max(1, uomPerPlt > 0 ? Math.ceil(remainderQty / uomPerPlt) : 1);
            const ins2 = await dbExec(
              `INSERT INTO stock
                 (product_id, batch_number, location, quantity, uom,
                  pallet, manufacture_date, expiry_date, stock_status)
               VALUES (?,?,?,?,?,?,?,?,?)`,
              [pid, batchVal, fallbackLoc, remainderQty, item.uom, plt, item.manufacture_date, item.exp_date, stockTarget],
            );
            const remStockId = Number((ins2 as any).insertId);
            await dbExec(
              `INSERT INTO stock_locations
                 (stock_id, location_code, pallet_seq, quantity, original_quantity,
                  uom, is_full_pallet, batch_number, inbound_item_id, status)
               VALUES (?, ?, 999, ?, ?, ?, 0, ?, ?, 'Available')`,
              [remStockId, fallbackLoc, remainderQty, remainderQty, item.uom, batchVal, item.id],
            );
          }
        } else {
          const loc = item.location ?? 'UNALLOCATED';
          let plt = uomPerPlt > 0 ? Math.ceil(totalQty / uomPerPlt) : 1;
          plt = Math.max(1, plt);
          const ins = await dbExec(
            `INSERT INTO stock
               (product_id, batch_number, location, quantity, uom,
                pallet, manufacture_date, expiry_date, stock_status)
             VALUES (?,?,?,?,?,?,?,?,?)`,
            [pid, batchVal, loc, totalQty, item.uom, plt, item.manufacture_date, item.exp_date, stockTarget],
          );
          const newId = Number((ins as any).insertId);
          await dbExec('UPDATE stock_locations SET stock_id=? WHERE inbound_item_id=?', [newId, item.id]);
        }

        await dbExec('UPDATE inbound_items SET stock_status=? WHERE id=?', [
          stockTarget === 'Available' ? 'Accepted' : 'Pending',
          item.id,
        ]);

        if (stockTarget === 'Available') {
          await Inbound.syncBatchToOutbound(pid, batchVal, item.exp_date);
        }
      }

      await dbExec("UPDATE inbound_orders SET status='Completed' WHERE id=?", [id]);
      return true;
    });
  }

  private static async syncBatchToOutbound(productId: number, batchNumber: any, expDate: any): Promise<void> {
    if (!batchNumber) return;
    await dbExec(
      `UPDATE outbound_items oi
         JOIN outbound_orders oo ON oi.outbound_order_id = oo.id
       SET oi.batch_number = ?, oi.batch_no = ?, oi.exp_date = ?
       WHERE oi.product_id = ?
         AND (oi.batch_number IS NULL OR oi.batch_number = '')
         AND oo.status IN ('Open','Picking','Draft')`,
      [batchNumber, batchNumber, expDate, productId],
    );
    await dbExec(
      `UPDATE picklist_items pki
         JOIN picklists pkl ON pki.picklist_id = pkl.id
         JOIN outbound_orders oo ON pkl.outbound_order_id = oo.id
       SET pki.batch_number = ?, pki.batch_no = ?
       WHERE pki.product_id = ?
         AND (pki.batch_number IS NULL OR pki.batch_number = '')
         AND pkl.status IN ('Draft','Confirmed')
         AND oo.status IN ('Open','Picking','Draft')`,
      [batchNumber, batchNumber, productId],
    );
  }

  private static async addToLedger(item: any, inbound: any, batchVal: any = null): Promise<void> {
    const isRejected = (item.in_process_status ?? '') === 'Unserviceable'
      || (item.stock_status ?? '') === 'Rejected';

    let ledgerQty = Number(item.actual_qty ?? 0);
    if (ledgerQty <= 0) ledgerQty = Number(item.quantity ?? 0);

    const running = await dbScalar(
      `SELECT COALESCE(SUM(quantity_in),0) - COALESCE(SUM(quantity_out),0) AS running_balance
       FROM stock_ledger
       WHERE product_id = ?
         AND (location IS NULL OR location != 'QUA_SHELL')
         AND transaction_type NOT IN ('TRANSFER_IN','TRANSFER_OUT')`,
      [item.product_id],
    );
    const currentBalance = Number(running ?? 0);
    const balance = isRejected ? currentBalance : currentBalance + ledgerQty;

    const locForLedger = isRejected ? 'QUA_SHELL' : (item.location ?? null);
    const inProcessLabel = item.in_process_status ?? (isRejected ? 'Unserviceable' : 'ATP');
    const notes = isRejected
      ? `[Inbound] Unserviceable (QUA_SHELL) | In-Process: ${inProcessLabel} | ${inbound.order_number}`
      : `[Inbound] ${inProcessLabel} | In-Process: ${inProcessLabel} | ${inbound.order_number}`;

    const uomPP = Math.max(1, Number(item.uom_per_pallet ?? 4) || 1);
    const palletForLedger = uomPP > 0 ? Math.ceil(ledgerQty / uomPP) : Number(item.pallet ?? 0);

    await dbExec(
      `INSERT INTO stock_ledger
         (transaction_date, product_id, transaction_type, reference_type,
          reference_id, reference_number, batch_number, quantity_in,
          quantity_out, uom, pallet, balance, location, notes)
       VALUES (?, ?, 'IN', 'Inbound', ?, ?, ?, ?, 0, ?, ?, ?, ?, ?)`,
      [
        todayYmd(),
        item.product_id,
        inbound.id,
        inbound.order_number,
        batchVal ?? (item.batch_number ?? item.batch_no ?? null),
        ledgerQty,
        item.uom,
        palletForLedger,
        balance,
        locForLedger,
        notes,
      ],
    );
  }

  static async regenerateLedger(id: number): Promise<boolean> {
    return withTransaction(async () => {
      const inbound = await Inbound.getById(id);
      const items = await Inbound.getItems(id);

      await dbExec("DELETE FROM stock_ledger WHERE reference_type='Inbound' AND reference_id=?", [id]);

      for (const item of items) {
        const batchVal = item.batch_number ?? item.batch_no ?? null;
        const inProcess = item.in_process_status ?? 'Dues In';
        if (inProcess === 'Dues In') continue;
        await Inbound.addToLedger(item, inbound, batchVal);
      }
      return true;
    });
  }

  static async delete(id: number): Promise<boolean> {
    return withTransaction(async () => {
      const items = await Inbound.getItems(id);

      for (const item of items) {
        const batch = item.batch_number ?? item.batch_no ?? null;
        const qty = Number(item.actual_qty ?? item.quantity ?? 0);
        const pid = item.product_id;
        const isUnserv = (item.in_process_status ?? '') === 'Unserviceable'
          || (item.stock_status ?? '') === 'Rejected';

        const linked = await dbExec(
          'SELECT DISTINCT stock_id FROM stock_locations WHERE inbound_item_id=? AND stock_id IS NOT NULL',
          [item.id],
        );
        for (const lnk of linked) {
          await dbExec('DELETE FROM stock WHERE id=?', [lnk.stock_id]);
        }

        if (!isUnserv) {
          await dbExec(
            `DELETE FROM stock WHERE product_id=? AND batch_number<=>? AND stock_status IN ('Available','Dues In','Reserved')`,
            [pid, batch],
          );
        }
        if (isUnserv) {
          await dbExec(
            `DELETE FROM stock WHERE product_id=? AND batch_number<=>? AND location='QUA_SHELL' AND stock_status='Rejected'`,
            [pid, batch],
          );
        }

        await dbExec(
          `DELETE FROM stock_ledger WHERE reference_type='Inbound' AND reference_id=? AND product_id=?`,
          [id, pid],
        );
      }

      await dbExec(`DELETE FROM stock_ledger WHERE reference_type='Inbound' AND reference_id=?`, [id]);
      await dbExec(
        `DELETE sl FROM stock_locations sl JOIN inbound_items ii ON sl.inbound_item_id = ii.id WHERE ii.inbound_order_id=?`,
        [id],
      );
      await dbExec(`DELETE FROM location_allocations WHERE reference_type='Inbound' AND reference_id=?`, [id]);
      await dbExec('DELETE FROM inbound_items WHERE inbound_order_id=?', [id]);
      await dbExec('DELETE FROM inbound_orders WHERE id=?', [id]);
      return true;
    });
  }

  static async getStats(): Promise<any> {
    const stats: any = {};
    const m = await dbScalar(
      `SELECT COUNT(*) as count FROM inbound_orders
       WHERE YEAR(order_date) = YEAR(CURDATE()) AND MONTH(order_date) = MONTH(CURDATE())`,
    );
    stats.this_month = Number(m ?? 0);
    stats.by_status = await dbExec('SELECT status, COUNT(*) as count FROM inbound_orders GROUP BY status');
    const d = await dbScalar(`SELECT COUNT(*) as count FROM inbound_orders WHERE status = 'Dues In'`);
    stats.dues_in = Number(d ?? 0);
    stats.pending = stats.dues_in;
    const r = await dbScalar(`SELECT COUNT(*) as count FROM inbound_orders WHERE status = 'Receiving'`);
    stats.receiving = Number(r ?? 0);
    return stats;
  }
}

import { db, dbExec, dbExecFirst, dbScalar, withTransaction } from '../db';
import { ctx, todayYmd } from '../helpers';
import { getPickfaceConfig, splitOrderLine, checkReplenishment, createReplenTask } from './PickfaceSplitter';

function phpNumberFormat(n: number): string {
  return n.toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
}

function round6(x: number): number {
  return Math.round(x * 1e6) / 1e6;
}

export class Outbound {
  static displayOrderNo(outbound: any): string {
    const ship = String(outbound.shipment_number ?? '').trim();
    if (ship !== '') return ship;
    const ord = String(outbound.order_number ?? '').trim();
    return ord !== '' ? ord : '-';
  }

  static async saveDestinations(outboundId: number, names: any[], locations: any[], streets: any[], kotas: any[], notes: any[]): Promise<void> {
    await dbExec('DELETE FROM outbound_destinations WHERE outbound_id = ?', [outboundId]);
    for (let i = 0; i < names.length; i++) {
      const name = String(names[i] ?? '').trim();
      if (name === '') continue;
      await dbExec(
        `INSERT INTO outbound_destinations
           (outbound_id, seq, ship_to_name, ship_to_location, ship_to_street, kota, notes)
         VALUES (?, ?, ?, ?, ?, ?, ?)`,
        [
          outboundId,
          i + 1,
          name,
          String(locations[i] ?? '').trim(),
          String(streets[i] ?? '').trim(),
          String(kotas[i] ?? '').trim(),
          String(notes[i] ?? '').trim(),
        ],
      );
    }
  }

  static async generateNumber(): Promise<string> {
    const now = new Date();
    const prefix = `OUT-${now.getFullYear()}${String(now.getMonth() + 1).padStart(2, '0')}-`;
    const last = await dbScalar(
      'SELECT order_number FROM outbound_orders WHERE order_number LIKE ? ORDER BY order_number DESC LIMIT 1',
      [prefix + '%'],
    );
    let seq = last ? parseInt(String(last).slice(String(last).lastIndexOf('-') + 1), 10) + 1 : 1;
    let maxTries = 20;
    while (maxTries-- > 0) {
      const number = prefix + String(seq).padStart(4, '0');
      const chk = await dbExecFirst('SELECT id FROM outbound_orders WHERE order_number = ? LIMIT 1', [number]);
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
        conditions.push('o.status = ?');
        params.push(status);
      }
      if (odNo) {
        conditions.push('oi.od_number LIKE ?');
        params.push('%' + odNo + '%');
      }
      const where = conditions.length ? 'WHERE ' + conditions.join(' AND ') : '';
      let sql = `SELECT o.*,
              c.customer_name, c.customer_code, c.city,
              u.full_name as created_by_name,
              s.full_name as shipped_by_name,
              COUNT(DISTINCT oi.id) as total_items,
              SUM(oi.actual_qty) as total_qty,
              SUM(oi.pallet) as total_pallet,
              GROUP_CONCAT(DISTINCT oi.od_number ORDER BY oi.id SEPARATOR ', ') as od_numbers
              FROM outbound_orders o
              LEFT JOIN customers c ON o.customer_id = c.id
              LEFT JOIN users u ON o.created_by = u.id
              LEFT JOIN users s ON o.shipped_by = s.id
              LEFT JOIN outbound_items oi ON o.id = oi.outbound_order_id
              ${where}
              GROUP BY o.id
              ORDER BY o.order_date DESC, o.created_at DESC`;
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
      conditions.push('o.status = ?');
      params.push(status);
    }
    if (odNo) {
      conditions.push('oi.od_number LIKE ?');
      params.push('%' + odNo + '%');
    }
    const where = conditions.length ? 'WHERE ' + conditions.join(' AND ') : '';
    const n = await dbScalar(
      `SELECT COUNT(DISTINCT o.id) FROM outbound_orders o
       LEFT JOIN outbound_items oi ON o.id = oi.outbound_order_id
       ${where}`,
      params,
    );
    return Number(n ?? 0);
  }

  static async getById(id: number): Promise<any> {
    return dbExecFirst(
      `SELECT o.*,
              c.customer_name, c.customer_code, c.address, c.city,
              u.full_name as created_by_name,
              s.full_name as shipped_by_name
       FROM outbound_orders o
       LEFT JOIN customers c ON o.customer_id = c.id
       LEFT JOIN users u ON o.created_by = u.id
       LEFT JOIN users s ON o.shipped_by = s.id
       WHERE o.id = ?`,
      [id],
    );
  }

  static async getItems(outboundId: number): Promise<any[]> {
    const rows = await dbExec(
      `SELECT oi.*,
              p.product_code, p.product_name, p.uom_type, p.uom_per_pallet,
              COALESCE(oi.batch_number, oi.batch_no) AS resolved_batch,
              oi.location                            AS resolved_location,
              oi.exp_date                            AS resolved_expiry,
              COALESCE(NULLIF(od.ship_to_name,''), NULLIF(o.ship_to_name,'')) AS item_ship_to_name,
              COALESCE(NULLIF(od.ship_to_location,''), NULLIF(od.kota,''), NULLIF(o.ship_to_location,''), NULLIF(o.kota,'')) AS item_ship_to_location,
              NULLIF(od.ship_to_street,'') AS item_ship_to_street,
              COALESCE(NULLIF(od.kota,''), NULLIF(o.kota,'')) AS item_ship_to_kota,
              COALESCE(ci.customer_name, co.customer_name) AS order_customer_name,
              COALESCE(ci.customer_code, co.customer_code) AS order_customer_code
       FROM outbound_items oi
       JOIN products p ON oi.product_id = p.id
       LEFT JOIN outbound_destinations od ON oi.destination_id = od.id
       LEFT JOIN outbound_orders o ON oi.outbound_order_id = o.id
       LEFT JOIN customers ci ON oi.customer_id = ci.id
       LEFT JOIN customers co ON o.customer_id = co.id
       WHERE oi.outbound_order_id = ?
       ORDER BY oi.id`,
      [outboundId],
    );

    for (const row of rows) {
      if (!row.resolved_batch) {
        const f = await dbExecFirst(
          `SELECT batch_number, location, expiry_date
           FROM stock
           WHERE product_id = ? AND quantity > 0
             AND (stock_status IN ('Available','Dues In') OR stock_status IS NULL OR stock_status = '')
             AND (location IS NULL OR location NOT IN ('QUA_SHELL','STAGING'))
           ORDER BY CASE WHEN expiry_date IS NULL THEN 1 ELSE 0 END, expiry_date ASC
           LIMIT 1`,
          [row.product_id],
        );
        if (f) {
          row.resolved_batch = f.batch_number;
          row.resolved_location = f.location;
          row.resolved_expiry = f.expiry_date;
        }
      }

      row.batch_number = row.resolved_batch;
      row.location = row.resolved_location;
      row.expiry_date = row.resolved_expiry;
      row.exp_date = row.resolved_expiry;

      const currentStatus = row.in_process_status ?? 'Goods Received';
      if (currentStatus !== 'Unserviceable') {
        const batch = row.batch_number;
        const cnt = await dbScalar(
          `SELECT COUNT(*) FROM stock
           WHERE product_id = ?
             AND batch_number <=> ?
             AND stock_status = 'Available'
             AND quantity > 0
             AND (location IS NULL OR location NOT IN ('QUA_SHELL','STAGING'))`,
          [row.product_id, batch],
        );
        const inStock = Number(cnt ?? 0) > 0;

        if (inStock && currentStatus !== 'ATP') {
          await dbExec("UPDATE outbound_items SET in_process_status = 'ATP' WHERE id = ?", [row.id]);
          row.in_process_status = 'ATP';
        } else if (!inStock && currentStatus === 'ATP') {
          await dbExec("UPDATE outbound_items SET in_process_status = 'Goods Received' WHERE id = ?", [row.id]);
          row.in_process_status = 'Goods Received';
        }
      }
    }
    return rows;
  }

  static async getItemPickedLocations(outboundItemId: number): Promise<any[]> {
    return dbExec(
      `SELECT oil.quantity AS picked_qty,
              COALESCE(sl.location_code, oi.location) AS location_code,
              sl.pallet_seq,
              COALESCE(sl.batch_number, oi.batch_number, oi.batch_no) AS batch_number,
              COALESCE(sl.original_quantity, sl.quantity, oil.quantity) AS original_qty,
              sl.is_full_pallet,
              COALESCE(sl.uom, oi.uom) AS uom
       FROM outbound_item_locations oil
       JOIN outbound_items oi ON oi.id = oil.outbound_item_id
       LEFT JOIN stock_locations sl ON oil.stock_location_id = sl.id
       WHERE oil.outbound_item_id = ?
       ORDER BY COALESCE(sl.location_code, oi.location), sl.pallet_seq`,
      [outboundItemId],
    );
  }

  static async getAvailableStock(productId: number, quantity = 0, location: string | null = null): Promise<any[]> {
    let locClause = '';
    let params: any[] = [productId];
    const loc = location !== null ? String(location).trim() : '';
    if (loc !== '') {
      locClause = 'AND LOWER(TRIM(st.location)) = LOWER(?)';
      params = [productId, loc];
    }
    return dbExec(
      `SELECT
              st.id, st.product_id, st.batch_number, st.location,
              st.quantity, st.uom, st.pallet,
              st.manufacture_date,
              COALESCE(
                  (SELECT ii_exp.exp_date
                   FROM stock_locations sl_exp
                   JOIN inbound_items ii_exp ON ii_exp.id = sl_exp.inbound_item_id
                   WHERE sl_exp.stock_id = st.id
                   ORDER BY ii_exp.id DESC
                   LIMIT 1),
                  st.expiry_date
              ) AS expiry_date,
              st.stock_status,
              p.product_name, p.uom_type, p.uom_per_pallet
       FROM stock st
       JOIN products p ON st.product_id = p.id
       WHERE st.product_id = ?
       AND (st.stock_status IN ('Available','Dues In') OR st.stock_status IS NULL OR st.stock_status = '')
       AND st.quantity > 0
       AND (st.location IS NULL OR st.location NOT IN ('QUA_SHELL','STAGING'))
       ${locClause}
       ORDER BY
           CASE WHEN expiry_date IS NULL THEN 1 ELSE 0 END ASC,
           expiry_date ASC,
           st.id ASC`,
      params,
    );
  }

  static async getTotalAvailableQty(productId: number): Promise<number> {
    const v = await dbScalar(
      `SELECT COALESCE(SUM(quantity),0) FROM stock
       WHERE product_id = ?
       AND (stock_status IN ('Available','Dues In') OR stock_status IS NULL OR stock_status = '')
       AND quantity > 0
       AND (location IS NULL OR location NOT IN ('QUA_SHELL','STAGING'))`,
      [productId],
    );
    return Number(v ?? 0);
  }

  static async getFEFOAllocation(productId: number, requiredQty: number, location: string | null = null): Promise<any> {
    const availableStock = await Outbound.getAvailableStock(productId, 0, location);

    const allocation: any[] = [];
    let remainingQty = round6(Number(requiredQty));

    for (const stock of availableStock) {
      if (remainingQty <= 1e-9) break;
      const availableQty = Number(stock.quantity);
      const take = Math.min(remainingQty, availableQty);
      allocation.push({
        stock_id: stock.id,
        batch_number: stock.batch_number,
        location: stock.location,
        expiry_date: stock.expiry_date,
        required_qty: take,
        available_qty: availableQty,
        is_partial: take < availableQty,
      });
      remainingQty = round6(remainingQty - take);
    }

    let totalAvailable = 0.0;
    for (const row of availableStock) {
      totalAvailable += Number(row.quantity ?? 0);
    }

    return {
      allocation,
      sufficient: remainingQty <= 1e-5,
      shortage: Math.max(0, remainingQty),
      total_available: totalAvailable,
    };
  }

  static async addItemWithFEFO(outboundId: number, item: any): Promise<number> {
    const productInfo = await dbExecFirst(
      `SELECT uom_type, uom_per_pallet, max_sku_qty, max_trans_qty
       FROM products WHERE id = ?`,
      [item.product_id],
    );
    if (!productInfo) throw new Error('Product not found');

    const quantity = Number(item.quantity);
    const uom = item.uom ?? productInfo.uom_type;
    const uomPerPallet = Math.max(1, Number(productInfo.uom_per_pallet ?? 4));

    const manualLocs = item.manual_locs ?? null;
    const manualLoc = item.manual_location ? String(item.manual_location).trim() : null;

    const totalRow = await dbExecFirst(
      `SELECT COALESCE(SUM(quantity), 0) as total FROM stock WHERE product_id = ?
       AND (stock_status IN ('Available','Dues In') OR stock_status IS NULL OR stock_status = '')
       AND quantity > 0
       AND (location IS NULL OR location NOT IN ('QUA_SHELL','STAGING'))`,
      [item.product_id],
    );
    const totalAvailable = Number(totalRow?.total ?? 0);

    if (quantity > totalAvailable) {
      throw new Error(
        `Stok tidak mencukupi. Stok tersedia: ${phpNumberFormat(totalAvailable)}, Qty diminta: ${phpNumberFormat(quantity)}`,
      );
    }

    let fefo: any;
    if (Array.isArray(manualLocs) && manualLocs.length > 0) {
      const manualTotal = manualLocs.reduce((s: number, m: any) => s + Number(m.qty ?? 0), 0);
      if (Math.abs(manualTotal - quantity) > 0.01) {
        throw new Error(
          `Total qty bin manual (${phpNumberFormat(manualTotal)}) tidak sama dengan qty order (${phpNumberFormat(quantity)})`,
        );
      }
      const allAllocations: any[] = [];
      for (const binEntry of manualLocs) {
        const binLoc = String(binEntry.location ?? '').trim();
        const binQty = Number(binEntry.qty ?? 0);
        if (binQty <= 0) continue;
        const binFefo = await Outbound.getFEFOAllocation(item.product_id, binQty, binLoc ? binLoc : null);
        if (!binFefo.sufficient) {
          throw new Error(
            `Stok di bin '${binLoc}' tidak mencukupi. Diminta: ${phpNumberFormat(binQty)}, Tersedia: ${phpNumberFormat(binFefo.total_available)}`,
          );
        }
        for (const alloc of binFefo.allocation) {
          alloc._bin = binLoc;
        }
        allAllocations.push(...binFefo.allocation);
      }
      fefo = {
        sufficient: true,
        shortage: 0,
        total_available: totalAvailable,
        allocation: allAllocations,
      };
    } else {
      fefo = await Outbound.getFEFOAllocation(item.product_id, quantity, manualLoc ? manualLoc : null);
      if (!fefo.sufficient) {
        const scoped = (manualLoc !== null && manualLoc !== '')
          || (Array.isArray(manualLocs) && manualLocs.length > 0);
        let msg = `Stok tidak mencukupi untuk alokasi FEFO. Qty diminta: ${phpNumberFormat(quantity)}.`;
        if (scoped) {
          msg += ` Stok di lokasi/bin terpilih: ${phpNumberFormat(fefo.total_available)} (total pickable gudang: ${phpNumberFormat(totalAvailable)}).`;
        } else {
          msg += ` Tersedia (FEFO): ${phpNumberFormat(fefo.total_available)}.`;
        }
        throw new Error(msg);
      }
    }

    const pallet = Outbound.calculatePallet(quantity, uomPerPallet);
    const firstBatch = fefo.allocation[0];

    let locSummary = null;
    if (Array.isArray(manualLocs) && manualLocs.length > 1) {
      locSummary = manualLocs.map((m: any) => m.location).join(', ');
    } else {
      locSummary = firstBatch?.location ?? manualLoc;
    }

    const ins = await dbExec(
      `INSERT INTO outbound_items
         (outbound_order_id, product_id, quantity, uom,
          actual_qty, pallet, batch_no, exp_date, location, notes, od_number, so_number, destination_id, customer_id)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`,
      [
        outboundId,
        item.product_id,
        quantity,
        uom,
        item.actual_qty ?? quantity,
        pallet,
        firstBatch?.batch_number ?? null,
        firstBatch?.expiry_date ?? null,
        locSummary,
        item.notes ?? null,
        item.od_number ?? null,
        item.so_number ?? null,
        item.destination_id ?? null,
        item.customer_id ?? null,
      ],
    );
    const outboundItemId = Number((ins as any).insertId);

    // --- PickfaceSplitter hook: split qty into bulk + pickface, check replenishment ---
    try {
      const splitConfig = await getPickfaceConfig(Number(item.product_id));
      if (splitConfig) {
        const split = splitOrderLine(quantity, splitConfig.pickface_max);

        if (split.pickface_qty > 0) {
          const replenResult = await checkReplenishment(
            Number(item.product_id),
            split.pickface_qty,
          );

          if (replenResult.needs_replenishment) {
            const taskId = await createReplenTask(
              Number(item.product_id),
              split.pickface_qty,
              outboundId,
            );
            await dbExec(
              'UPDATE outbound_items SET blocked_on_replen_task_id = ? WHERE id = ?',
              [taskId, outboundItemId],
            );
          }
        }
      }
    } catch {
      // PickfaceSplitter failures should not block outbound item creation
    }

    return outboundItemId;
  }

  static async create(data: any): Promise<number> {
    return withTransaction(async () => {
      const outboundNumber = data.shipment_number ? data.shipment_number : await Outbound.generateNumber();

      const ins = await dbExec(
        `INSERT INTO outbound_orders
           (order_number, order_date, customer_id, so_number, do_number,
            shipment_number, ship_to_name, ship_to_location, ship_to_street,
            destination, kota, armada_no, container_no, jenis_armada,
            expected_date, status, notes, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`,
        [
          outboundNumber,
          data.order_date,
          data.customer_id ?? null,
          data.so_number ?? null,
          data.do_number ?? null,
          data.shipment_number ?? null,
          data.ship_to_name ?? null,
          data.ship_to_location ?? null,
          data.ship_to_street ?? null,
          data.destination ?? null,
          data.kota ?? null,
          data.armada_no ?? null,
          data.container_no ?? null,
          data.jenis_armada ?? null,
          data.expected_date ?? null,
          data.status ?? 'Open',
          data.notes ?? null,
          ctx().user?.id ?? null,
        ],
      );
      const outboundId = Number((ins as any).insertId);

      if (Array.isArray(data.items)) {
        for (const item of data.items) {
          await Outbound.addItemWithFEFO(outboundId, item);
        }
      }
      return outboundId;
    });
  }

  static calculatePallet(quantity: number, uomPerPallet: number): number {
    if (uomPerPallet == 0) return 0;
    return Math.ceil(quantity / uomPerPallet);
  }

  static calculatePalletDistribution(quantity: number, uomPerPallet: number): Array<{ pallet_number: number; quantity: number; is_full: boolean }> {
    const fullPallets = Math.floor(Number(quantity) / Number(uomPerPallet));
    const remainder = Number(quantity) % Number(uomPerPallet);

    const distribution: Array<{ pallet_number: number; quantity: number; is_full: boolean }> = [];
    let palletNumber = 1;

    for (let i = 0; i < fullPallets; i++) {
      distribution.push({ pallet_number: palletNumber++, quantity: uomPerPallet, is_full: true });
    }

    if (remainder > 0) {
      distribution.push({ pallet_number: palletNumber, quantity: remainder, is_full: false });
    }

    return distribution;
  }

  static async update(id: number, data: any): Promise<boolean> {
    return withTransaction(async () => {
      await dbExec(
        `UPDATE outbound_orders SET
               order_date = ?,
               customer_id = ?,
               so_number = ?,
               do_number = ?,
               shipment_number = ?,
               ship_to_name = ?,
               ship_to_location = ?,
               ship_to_street = ?,
               destination = ?,
               kota = ?,
               armada_no = ?,
               container_no = ?,
               jenis_armada = ?,
               expected_date = ?,
               status = ?,
               notes = ?
               WHERE id = ?`,
        [
          data.order_date,
          data.customer_id ?? null,
          data.so_number ?? null,
          data.do_number ?? null,
          data.shipment_number ?? null,
          data.ship_to_name ?? null,
          data.ship_to_location ?? null,
          data.ship_to_street ?? null,
          data.destination ?? null,
          data.kota ?? null,
          data.armada_no ?? null,
          data.container_no ?? null,
          data.jenis_armada ?? null,
          data.expected_date ?? null,
          data.status ?? 'Open',
          data.notes ?? null,
          id,
        ],
      );

      if ('shipped_date' in data || 'status' in data) {
        await dbExec(
          `UPDATE outbound_orders SET
                  shipped_by = ?,
                  status = ?
                  WHERE id = ?`,
          [ctx().user?.id ?? null, data.status ?? 'Open', id],
        );
      }
      return true;
    });
  }

  static async updateItem(itemId: number, data: any): Promise<boolean> {
    await dbExec(
      `UPDATE outbound_items SET
              quantity = ?,
              uom = ?,
              actual_qty = ?,
              batch_no = ?,
              exp_date = ?,
              location = ?,
              notes = ?
              WHERE id = ?`,
      [
        data.quantity ?? 0,
        data.uom ?? 'Drum',
        data.actual_qty ?? data.quantity ?? 0,
        data.batch_no ?? null,
        data.exp_date ?? null,
        data.location ?? null,
        data.notes ?? null,
        itemId,
      ],
    );
    return true;
  }

  static async deleteItem(itemId: number): Promise<boolean> {
    return withTransaction(async () => {
      const item = await dbExecFirst(
        `SELECT oi.*, oo.status AS order_status
         FROM outbound_items oi
         JOIN outbound_orders oo ON oo.id = oi.outbound_order_id
         WHERE oi.id = ?`,
        [itemId],
      );

      if (item) {
        const wasPicked = ['Picking', 'Shipped', 'Completed'].includes(item.order_status ?? '');
        const isUnserv = (item.in_process_status ?? '') === 'Unserviceable';
        const pid = item.product_id;
        const batch = item.batch_no ?? item.batch_number ?? null;
        const qty = Number(item.actual_qty ?? item.quantity ?? 0);

        if (wasPicked && qty > 0 && !isUnserv) {
          const pickRows = await dbExec(
            `SELECT oil.quantity AS restore_qty,
                   sl.id AS sl_id, sl.stock_id, sl.location_code AS loc,
                   sl.batch_number AS sl_batch, sl.original_quantity AS orig_qty
            FROM outbound_item_locations oil
            JOIN stock_locations sl ON sl.id = oil.stock_location_id
            WHERE oil.outbound_item_id = ?`,
            [itemId],
          );

          for (const pr of pickRows) {
            const rQty = Number(pr.restore_qty ?? 0);
            const rLoc = pr.loc ?? null;
            const rBatch = pr.sl_batch ?? batch;
            const slId = pr.sl_id ?? null;
            const sid = pr.stock_id ?? null;
            if (rQty <= 0) continue;

            let restored = false;
            if (sid) {
              const sr = await dbExecFirst('SELECT id, quantity FROM stock WHERE id=?', [sid]);
              if (sr) {
                await dbExec('UPDATE stock SET quantity=quantity+?, updated_at=NOW() WHERE id=?', [rQty, sid]);
                restored = true;
              }
            }
            if (!restored && rLoc) {
              const found = await dbExecFirst(
                `SELECT id FROM stock
                 WHERE product_id=? AND batch_number<=>? AND location=? AND stock_status='Available'`,
                [pid, rBatch, rLoc],
              );
              if (found) {
                await dbExec('UPDATE stock SET quantity=quantity+?, updated_at=NOW() WHERE id=?', [rQty, found.id]);
                if (slId) await dbExec("UPDATE stock_locations SET stock_id=?, status='Available' WHERE id=?", [found.id, slId]);
              } else {
                const uomPerPallet = Math.max(1, Number(item.uom_per_pallet ?? 4));
                const ins = await dbExec(
                  `INSERT INTO stock (product_id,batch_number,location,quantity,uom,pallet,stock_status) VALUES (?,?,?,?,?,?,'Available')`,
                  [pid, rBatch, rLoc, rQty, item.uom ?? 'Drum', Math.max(1, Math.ceil(rQty / uomPerPallet))],
                );
                const nid = Number((ins as any).insertId);
                if (slId) await dbExec("UPDATE stock_locations SET stock_id=?, status='Available' WHERE id=?", [nid, slId]);
              }
            }
            if (slId) {
              const origQty = Number(pr.orig_qty ?? rQty);
              await dbExec("UPDATE stock_locations SET status='Available', quantity=? WHERE id=?", [origQty, slId]);
            }
          }
        }
      }

      const destInfo = await dbExecFirst(
        'SELECT destination_id, outbound_order_id FROM outbound_items WHERE id=?',
        [itemId],
      );

      await dbExec('DELETE FROM outbound_item_locations WHERE outbound_item_id=?', [itemId]);
      await dbExec('DELETE FROM outbound_items WHERE id=?', [itemId]);

      if (destInfo?.destination_id) {
        const remaining = await dbScalar('SELECT COUNT(*) FROM outbound_items WHERE destination_id=?', [destInfo.destination_id]);
        if (Number(remaining ?? 0) === 0) {
          await dbExec('DELETE FROM outbound_destinations WHERE id=?', [destInfo.destination_id]);
        }
      }
      return true;
    });
  }

  static async pickItems(outboundId: number): Promise<boolean> {
    return withTransaction(async () => {
      const outbound = await Outbound.getById(outboundId);
      const items = await Outbound.getItems(outboundId);

      const pickLocs = Array.from(new Set(items.map((i: any) => i.location).filter((l: any) => l)));
      if (pickLocs.length > 0) {
        const ph = pickLocs.map(() => '?').join(',');
        const locked = await dbExec(
          `SELECT st.take_number, sti.location
           FROM stock_take_items sti
           JOIN stock_take st ON st.id = sti.stock_take_id
           WHERE st.status IN ('Counting','Review')
             AND sti.location IN (${ph})
           LIMIT 5`,
          pickLocs,
        );
        if (locked.length > 0) {
          const takeNo = locked[0].take_number;
          const locs = Array.from(new Set(locked.map((l: any) => l.location))).join(', ');
          throw new Error(
            `Picking diblokir — lokasi [${locs}] sedang dalam sesi Stock Take aktif (${takeNo}). Selesaikan atau batalkan stock take terlebih dahulu.`,
          );
        }
      }

      for (const item of items) {
        const itemProcessStatus = item.in_process_status ?? '';
        if (itemProcessStatus !== 'ATP') continue;

        const needed = Number(item.actual_qty || item.quantity);
        const productId = item.product_id;
        const preferBatch = item.batch_number ?? item.batch_no ?? null;

        let stockRows = await dbExec(
          `SELECT * FROM stock
           WHERE product_id = ?
           AND (stock_status IN ('Available','Dues In') OR stock_status IS NULL OR stock_status = '')
           AND quantity > 0
           AND location != 'QUA_SHELL'
           AND location != 'STAGING'
           ORDER BY
               CASE WHEN expiry_date IS NULL THEN 1 ELSE 0 END ASC,
               expiry_date ASC,
               id ASC`,
          [productId],
        );

        if (preferBatch) {
          stockRows = stockRows.sort((a: any, b: any) => {
            const aMatch = (a.batch_number === preferBatch) ? 0 : 1;
            const bMatch = (b.batch_number === preferBatch) ? 0 : 1;
            if (aMatch !== bMatch) return aMatch - bMatch;
            const aExp = Date.parse(a.expiry_date ?? '9999-12-31');
            const bExp = Date.parse(b.expiry_date ?? '9999-12-31');
            return aExp - bExp;
          });
        }

        if (stockRows.length === 0) {
          throw new Error(`Stok tidak tersedia untuk produk: ` + (item.product_name ?? productId));
        }

        const totalAvail = stockRows.reduce((s: number, r: any) => s + Number(r.quantity), 0);
        if (totalAvail < needed - 0.001) {
          throw new Error(
            `Stok kurang ${Math.round((needed - totalAvail) * 100) / 100} unit untuk produk: ` + (item.product_name ?? productId)
            + ` (tersedia: ${Math.round(totalAvail * 100) / 100}, dibutuhkan: ${Math.round(needed * 100) / 100})`,
          );
        }

        let remaining = needed;
        let usedBatch: any = null;
        let usedLocation: any = null;
        const pickedRows: any[] = [];

        for (const stock of stockRows) {
          if (remaining <= 0.001) break;
          const deduct = Math.min(remaining, Number(stock.quantity));
          const newQty = Number(stock.quantity) - deduct;

          const palletRatio = Number(stock.quantity) > 0 ? (deduct / Number(stock.quantity)) : 0;
          const newPlt = Math.max(0, Number(stock.pallet) - (Number(stock.pallet) * palletRatio));

          if (newQty <= 0.001) {
            await dbExec('DELETE FROM stock WHERE id = ?', [stock.id]);
          } else {
            await dbExec(
              `UPDATE stock SET
                      quantity     = ?,
                      pallet       = ?,
                      stock_status = 'Available',
                      updated_at   = NOW()
                      WHERE id = ?`,
              [newQty, Math.round(newPlt * 10000) / 10000, stock.id],
            );
          }

          let slId: any = null;
          const sl = await dbExecFirst(
            `SELECT id, quantity FROM stock_locations
             WHERE stock_id = ? AND status = 'Available'
             ORDER BY pallet_seq ASC LIMIT 1`,
            [stock.id],
          );
          if (sl) {
            slId = sl.id;
            const slNewQty = Math.max(0, Number(sl.quantity) - deduct);
            await dbExec(
              `UPDATE stock_locations SET quantity = ?, status = ? WHERE id = ?`,
              [slNewQty, slNewQty <= 0 ? 'Picked' : 'Available', slId],
            );
          } else {
            const slFind = await dbExecFirst(
              `SELECT sl.id, sl.quantity
               FROM stock_locations sl
               JOIN stock sx ON sx.id = sl.stock_id
               WHERE sx.product_id = ?
                 AND sx.batch_number <=> ?
                 AND sx.location <=> ?
                 AND sl.status IN ('Available','Picked')
               ORDER BY (sl.status='Available') DESC, sl.pallet_seq ASC
               LIMIT 1`,
              [productId, stock.batch_number ?? null, stock.location ?? null],
            );
            if (slFind) {
              slId = slFind.id;
              const slNewQty = Math.max(0, Number(slFind.quantity) - deduct);
              await dbExec(
                `UPDATE stock_locations SET quantity = ?, status = ? WHERE id = ?`,
                [slNewQty, slNewQty <= 0 ? 'Picked' : 'Available', slId],
              );
            } else {
              const insSl = await dbExec(
                `INSERT INTO stock_locations
                   (stock_id, location_code, pallet_seq, quantity, original_quantity,
                    uom, is_full_pallet, batch_number, inbound_item_id, status)
                 VALUES (NULL, ?, 999, 0, ?, ?, 0, ?, NULL, 'Picked')`,
                [
                  stock.location ?? 'UNALLOCATED',
                  deduct,
                  stock.uom ?? (item.uom ?? 'EA'),
                  stock.batch_number ?? null,
                ],
              );
              slId = Number((insSl as any).insertId);
            }
          }

          pickedRows.push({
            stock_location_id: slId,
            location: stock.location,
            batch: stock.batch_number,
            quantity: deduct,
            expiry_date: stock.expiry_date,
          });

          if (!usedBatch) usedBatch = stock.batch_number;
          if (!usedLocation) usedLocation = stock.location;
          remaining -= deduct;
        }

        await dbExec('DELETE FROM outbound_item_locations WHERE outbound_item_id = ?', [item.id]);
        for (const pr of pickedRows) {
          if (pr.stock_location_id) {
            await dbExec(
              `INSERT IGNORE INTO outbound_item_locations (outbound_item_id, stock_location_id, quantity) VALUES (?, ?, ?)`,
              [item.id, pr.stock_location_id, pr.quantity],
            );
          }
        }

        await dbExec(
          `UPDATE outbound_items SET batch_no = ?, batch_number = ?, location = COALESCE(?, location), exp_date = ? WHERE id = ?`,
          [usedBatch, usedBatch, usedLocation, stockRows[0]?.expiry_date ?? null, item.id],
        );

        item.batch_no = usedBatch;
        item.location = usedLocation;
        item.actual_qty = needed;
      }

      await dbExec("UPDATE outbound_orders SET status = 'Picking' WHERE id = ?", [outboundId]);
      return true;
    });
  }

  static async ship(outboundId: number): Promise<boolean> {
    return withTransaction(async () => {
      const outbound = await Outbound.getById(outboundId);
      const items = await Outbound.getItems(outboundId);

      await dbExec(
        `UPDATE outbound_orders SET
                status = 'Shipped',
                shipped_by = ?
                WHERE id = ?`,
        [ctx().user?.id ?? null, outboundId],
      );

      for (const item of items) {
        const qty = Number(item.actual_qty ?? item.quantity ?? 0);
        if (qty <= 0) continue;
        await Outbound.addToLedger(item, outbound);
      }
      return true;
    });
  }

  static async complete(outboundId: number): Promise<boolean> {
    await dbExec("UPDATE outbound_orders SET status = 'Completed' WHERE id = ?", [outboundId]);
    return true;
  }

  private static async addToLedger(item: any, outbound: any): Promise<void> {
    const row = await dbExecFirst(
      `SELECT COALESCE(SUM(quantity_in),0) - COALESCE(SUM(quantity_out),0) AS running_balance
       FROM stock_ledger WHERE product_id = ?`,
      [item.product_id],
    );
    const balance = Number(row?.running_balance ?? 0) - Number(item.actual_qty);

    await dbExec(
      `INSERT INTO stock_ledger
         (transaction_date, product_id, transaction_type, reference_type,
          reference_id, reference_number, batch_number, quantity_in,
          quantity_out, uom, pallet, balance, location, notes)
       VALUES (?, ?, 'OUT', 'Outbound', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`,
      [
        todayYmd(),
        item.product_id,
        outbound.id,
        outbound.order_number,
        item.batch_no,
        0,
        item.actual_qty,
        item.uom,
        item.pallet,
        balance,
        item.location,
        '[Outbound] Shipped | Status: Shipped | ' + outbound.order_number,
      ],
    );
  }

  static async delete(id: number): Promise<boolean> {
    return withTransaction(async () => {
      const items = await Outbound.getItems(id);
      const outbound = await Outbound.getById(id);
      const wasPickedOrShipped = ['Picking', 'Shipped', 'Completed'].includes(outbound?.status ?? '');

      for (const item of items) {
        const batch = item.batch_no ?? item.batch_number ?? null;
        const qty = Number(item.actual_qty ?? item.quantity ?? 0);
        const pid = item.product_id;
        const isUnserv = (item.in_process_status ?? '') === 'Unserviceable';

        if (wasPickedOrShipped && qty > 0 && !isUnserv) {
          const pickRows = await dbExec(
            `SELECT oil.quantity       AS restore_qty,
                   sl.id             AS sl_id,
                   sl.stock_id       AS stock_id,
                   sl.location_code  AS loc,
                   sl.batch_number   AS sl_batch,
                   sl.original_quantity AS orig_qty,
                   sl.uom            AS sl_uom
            FROM outbound_item_locations oil
            JOIN stock_locations sl ON sl.id = oil.stock_location_id
            WHERE oil.outbound_item_id = ?`,
            [item.id],
          );

          if (pickRows.length > 0) {
            const uomItem = item.uom ?? 'Drum';
            const expItem = item.exp_date ?? item.expiry_date ?? null;

            for (const pr of pickRows) {
              const rQty = Number(pr.restore_qty ?? 0);
              const rLoc = pr.loc ?? null;
              const rBatch = pr.sl_batch ?? batch;
              const slId = pr.sl_id ?? null;
              const sid = pr.stock_id ?? null;

              if (rQty <= 0) continue;

              let restored = false;
              if (sid) {
                const stockRow = await dbExecFirst('SELECT id, quantity, pallet, uom_per_pallet FROM stock WHERE id=?', [sid]);
                if (stockRow) {
                  const upp = Math.max(1, Number(stockRow.uom_per_pallet ?? 4));
                  const newQty = Number(stockRow.quantity) + rQty;
                  const newPlt = Math.ceil(newQty / upp);
                  await dbExec('UPDATE stock SET quantity=?, pallet=?, updated_at=NOW() WHERE id=?', [newQty, newPlt, sid]);
                  restored = true;
                }
              }

              if (!restored && rLoc) {
                const found = await dbExecFirst(
                  `SELECT id, quantity FROM stock
                   WHERE product_id=? AND batch_number<=>? AND location=? AND stock_status='Available'`,
                  [pid, rBatch, rLoc],
                );
                if (found) {
                  await dbExec('UPDATE stock SET quantity=quantity+?, updated_at=NOW() WHERE id=?', [rQty, found.id]);
                  if (slId) {
                    await dbExec("UPDATE stock_locations SET stock_id=?, status='Available' WHERE id=?", [found.id, slId]);
                  }
                } else {
                  const uomPerPallet = Math.max(1, Number(item.uom_per_pallet ?? 4));
                  const ins = await dbExec(
                    `INSERT INTO stock
                       (product_id, batch_number, location, quantity, uom, pallet, expiry_date, stock_status)
                       VALUES (?,?,?,?,?,?,?,'Available')`,
                    [pid, rBatch, rLoc, rQty, uomItem, Math.max(1, Math.ceil(rQty / uomPerPallet)), expItem],
                  );
                  const newSid = Number((ins as any).insertId);
                  if (slId) {
                    await dbExec("UPDATE stock_locations SET stock_id=?, status='Available' WHERE id=?", [newSid, slId]);
                  }
                }
                restored = true;
              }

              if (slId) {
                const origQty = Number(pr.orig_qty ?? rQty);
                await dbExec(
                  `UPDATE stock_locations SET status='Available', quantity=? WHERE id=?`,
                  [origQty, slId],
                );
              }
            }
          } else {
            const loc = item.location ?? null;
            if (loc && loc !== 'QUA_SHELL' && qty > 0) {
              const row = await dbExecFirst(
                `SELECT id FROM stock
                 WHERE product_id=? AND batch_number<=>? AND location=? AND stock_status='Available'`,
                [pid, batch, loc],
              );
              if (row) {
                await dbExec('UPDATE stock SET quantity=quantity+?, updated_at=NOW() WHERE id=?', [qty, row.id]);
              } else {
                const uomPerPallet = Math.max(1, Number(item.uom_per_pallet ?? 4));
                await dbExec(
                  `INSERT INTO stock
                     (product_id, batch_number, location, quantity, uom, pallet, stock_status)
                     VALUES (?,?,?,?,?,?,'Available')`,
                  [pid, batch, loc, qty, item.uom ?? 'Drum', Math.max(1, Math.ceil(qty / uomPerPallet))],
                );
              }
            }
          }
        }

        if (isUnserv && qty > 0) {
          await dbExec(
            `DELETE FROM stock
             WHERE product_id=? AND batch_number<=>?
             AND location='QUA_SHELL' AND stock_status='Rejected'`,
            [pid, batch],
          );
        }

        await dbExec(
          `DELETE FROM stock_ledger
           WHERE reference_type='Outbound' AND reference_id=? AND product_id=?`,
          [id, pid],
        );

        await dbExec('DELETE FROM outbound_item_locations WHERE outbound_item_id=?', [item.id]);
      }

      await dbExec(`DELETE FROM stock_ledger WHERE reference_type='Outbound' AND reference_id=?`, [id]);
      await dbExec(`DELETE FROM location_allocations WHERE reference_type='Outbound' AND reference_id=?`, [id]);
      await dbExec(`DELETE pi FROM picklist_items pi JOIN picklists pl ON pl.id = pi.picklist_id WHERE pl.outbound_order_id=?`, [id]);
      await dbExec(`DELETE FROM picklists WHERE outbound_order_id=?`, [id]);
      await dbExec(`DELETE FROM outbound_items WHERE outbound_order_id=?`, [id]);
      await dbExec(`DELETE FROM outbound_orders WHERE id=?`, [id]);
      return true;
    });
  }

  static async getStats(): Promise<any> {
    const stats: any = {};
    const m = await dbScalar(
      `SELECT COUNT(*) as count FROM outbound_orders
       WHERE YEAR(order_date) = YEAR(CURDATE())
       AND MONTH(order_date) = MONTH(CURDATE())`,
    );
    stats.this_month = Number(m ?? 0);
    stats.by_status = await dbExec('SELECT status, COUNT(*) as count FROM outbound_orders GROUP BY status');
    const p = await dbScalar(`SELECT COUNT(*) as count FROM outbound_orders WHERE status IN ('Open', 'Picking')`);
    stats.pending = Number(p ?? 0);
    return stats;
  }
}

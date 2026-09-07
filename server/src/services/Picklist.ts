import { dbExec, dbExecFirst, dbScalar, withTransaction } from '../db';
import { ctx } from '../helpers';
import { getPickfaceConfig, splitOrderLine, checkReplenishment, createReplenTask } from './PickfaceSplitter';

export class Picklist {
  static async generateNumber(): Promise<string> {
    const now = new Date();
    const prefix = `PKL-${now.getFullYear()}${String(now.getMonth() + 1).padStart(2, '0')}-`;
    const last = await dbScalar(
      'SELECT picklist_number FROM picklists WHERE picklist_number LIKE ? ORDER BY picklist_number DESC LIMIT 1',
      [prefix + '%'],
    );
    let seq = last ? parseInt(String(last).slice(String(last).lastIndexOf('-') + 1), 10) + 1 : 1;

    let maxTries = 20;
    while (maxTries-- > 0) {
      const number = prefix + String(seq).padStart(4, '0');
      const chk = await dbExecFirst('SELECT id FROM picklists WHERE picklist_number = ? LIMIT 1', [number]);
      if (!chk) return number;
      seq++;
    }
    const hms = `${String(now.getHours()).padStart(2, '0')}${String(now.getMinutes()).padStart(2, '0')}${String(now.getSeconds()).padStart(2, '0')}`;
    return prefix + hms + Math.floor(Math.random() * 90 + 10);
  }

  static async createFromOutbound(outboundId: number): Promise<number> {
    return withTransaction(async () => {
      const outbound = await dbExecFirst(
        `SELECT o.*, c.customer_name, c.address, c.city
         FROM outbound_orders o
         LEFT JOIN customers c ON o.customer_id = c.id
         WHERE o.id = ?`,
        [outboundId],
      );

      if (!outbound) throw new Error('Outbound order not found');

      const existing = await dbExecFirst('SELECT id FROM picklists WHERE outbound_order_id = ?', [outboundId]);
      if (existing) return Number(existing.id);

      const picklistNumber = await Picklist.generateNumber();
      const ins = await dbExec(
        `INSERT INTO picklists
           (outbound_order_id, picklist_number, created_date, status, created_by)
         VALUES (?, ?, CURDATE(), 'Draft', ?)`,
        [outboundId, picklistNumber, ctx().user?.id ?? null],
      );
      const picklistId = Number((ins as any).insertId);

      const items = await dbExec(
        `SELECT oi.*,
                p.product_code, p.product_name, p.uom_type, p.uom_per_pallet
         FROM outbound_items oi
         JOIN products p ON oi.product_id = p.id
         WHERE oi.outbound_order_id = ?
         ORDER BY oi.exp_date ASC, oi.id ASC`,
        [outboundId],
      );

      for (const item of items) {
        const batchNumber = item.batch_number ?? item.batch_no ?? null;
        const uomPerPallet = Math.max(1, Number(item.uom_per_pallet ?? 4));

        const locationRows = await dbExec(
          `SELECT MIN(oil.stock_location_id) as stock_location_id,
                  SUM(oil.quantity) as alloc_qty,
                  sl.location_code,
                  MIN(sl.batch_number) as batch_number
           FROM outbound_item_locations oil
           JOIN stock_locations sl ON oil.stock_location_id = sl.id
           WHERE oil.outbound_item_id = ?
           GROUP BY sl.location_code
           ORDER BY sl.location_code`,
          [item.id],
        );

        if (locationRows.length) {
          let palletSeq = 1;
          for (const lr of locationRows) {
            const locBatch = lr.batch_number ?? batchNumber;
            const locCode = lr.location_code ?? '';
            const locLevel = locCode[4] ? locCode[4].toUpperCase() : 'B';
            const qty = Number(lr.alloc_qty);
            const plt = uomPerPallet > 0
              ? (locLevel === 'A' ? Math.round((qty / uomPerPallet) * 100) / 100 : Math.ceil(qty / uomPerPallet))
              : 0;
            await dbExec(
              `INSERT INTO picklist_items
                 (picklist_id, outbound_item_id, product_id, batch_no, batch_number,
                  location, quantity, uom, pallet, pallet_seq,
                  stock_location_id, status)
               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending')`,
              [
                picklistId,
                item.id,
                item.product_id,
                locBatch,
                locBatch,
                locCode,
                qty,
                item.uom_type,
                plt,
                palletSeq++,
                lr.stock_location_id,
              ],
            );
          }
        } else {
          // Fallback: no outbound_item_locations — pick from available stock per pallet
          const distribution = Picklist.calculatePalletDistribution(
            item.actual_qty || item.quantity,
            uomPerPallet,
          );

          const available = await dbExec(
            `SELECT sl.id, sl.location_code, sl.pallet_seq
             FROM stock_locations sl
             LEFT JOIN stock st ON st.id = sl.stock_id
             WHERE sl.batch_number = ?
               AND sl.status IN ('Available','Reserved')
               AND (st.hold_status = 'available' OR st.hold_status IS NULL)
               AND sl.id NOT IN (
                 SELECT DISTINCT stock_location_id
                 FROM picklist_items
                 WHERE batch_number = ? AND stock_location_id IS NOT NULL
               )
             ORDER BY sl.location_code, sl.pallet_seq`,
            [batchNumber, batchNumber],
          );

          let availIdx = 0;
          let palletSeq = 1;
          for (const pallet of distribution) {
            let slId: any = null;
            let locCode2 = item.location ?? 'TBD';
            if (availIdx < available.length) {
              slId = available[availIdx].id;
              locCode2 = available[availIdx].location_code;
              availIdx++;
            }

            const locLevel2 = locCode2[4] ? locCode2[4].toUpperCase() : 'B';
            const qty2 = Number(pallet.quantity);
            const plt2 = uomPerPallet > 0
              ? (locLevel2 === 'A' ? Math.round((qty2 / uomPerPallet) * 100) / 100 : Math.ceil(qty2 / uomPerPallet))
              : 0;

            await dbExec(
              `INSERT INTO picklist_items
                 (picklist_id, outbound_item_id, product_id, batch_no, batch_number,
                  location, quantity, uom, pallet, pallet_seq,
                  stock_location_id, status)
               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending')`,
              [
                picklistId,
                item.id,
                item.product_id,
                batchNumber,
                batchNumber,
                locCode2,
                qty2,
                item.uom_type,
                plt2,
                palletSeq++,
                slId,
              ],
            );
          }
        }
      }

      {
        const skuRows = await dbExec(
          `SELECT product_id, SUM(quantity) as total_qty
           FROM picklist_items
           WHERE picklist_id = ?
           GROUP BY product_id`,
          [picklistId],
        );

        for (const skuRow of skuRows) {
          const skuId = Number(skuRow.product_id);
          const totalQty = Number(skuRow.total_qty);

          const config = await getPickfaceConfig(skuId);
          if (!config) continue;

          const split = splitOrderLine(totalQty, config.pickface_max);
          const pickfaceQty = split.pickface_qty;
          if (pickfaceQty <= 0) continue;

          const check = await checkReplenishment(skuId, pickfaceQty);
          const replenishQty = check.needs_replenishment
            ? config.pickface_max - check.projected_on_hand
            : 0;

          const triggerRow = await dbExecFirst(
            `SELECT oi.outbound_order_id
             FROM picklist_items pki
             JOIN outbound_items oi ON oi.id = pki.outbound_item_id
             WHERE pki.picklist_id = ? AND pki.product_id = ?
             LIMIT 1`,
            [picklistId, skuId],
          );
          const triggerOrderId = triggerRow ? Number(triggerRow.outbound_order_id) : null;

          const sourceBins = await dbExec(
            `SELECT DISTINCT lm.id AS stock_location_id, SUM(pki.quantity) as bin_qty
             FROM picklist_items pki
             JOIN stock_locations sl ON sl.id = pki.stock_location_id
             JOIN location_master lm ON lm.location_code = sl.location_code
             WHERE pki.picklist_id = ? AND pki.product_id = ?
               AND lm.row_name IN ('B', 'C', 'D', 'E')
             GROUP BY lm.id`,
            [picklistId, skuId],
          );

          const existingSources: number[] = [];
          if (sourceBins.length > 0) {
            const binIds = sourceBins.map((b: any) => Number(b.stock_location_id));
            const placeholders = binIds.map(() => '?').join(',');
            const existRows = await dbExec(
              `SELECT DISTINCT source_bin_id FROM replen_task
               WHERE sku_id = ? AND destination_bin_id = ?
                 AND status IN ('pending', 'printed', 'in_progress')
                 AND source_bin_id IN (${placeholders})`,
              [skuId, config.pickface_bin_id, ...binIds],
            );
            for (const r of existRows) {
              existingSources.push(Number(r.source_bin_id));
            }
          }

          const needQty = Math.max(0, Math.ceil(replenishQty));
          if (needQty > 0 && sourceBins.length > 0) {
            let remaining = needQty;
            for (const bin of sourceBins) {
              if (remaining <= 0) break;
              const srcBinId = Number(bin.stock_location_id);
              if (existingSources.includes(srcBinId)) continue;

              const taskQty = Math.min(remaining, config.pickface_max);
              try {
                await createReplenTask(skuId, taskQty, triggerOrderId, srcBinId);
                remaining -= taskQty;
                console.log(`[PickfaceSplitter] Created replenishment task: SKU #${skuId}, qty ${taskQty}, source #${srcBinId}, order #${triggerOrderId}`);
              } catch (e: any) {
                console.error(`[PickfaceSplitter] Failed to create replenishment task for SKU #${skuId}: ${e.message}`);
              }
            }
          }
        }
      }

      return picklistId;
    });
  }

  static calculatePalletDistribution(quantity: number, uomPerPallet: number): Array<{ quantity: number; is_full: boolean }> {
    const fullPallets = Math.floor(Number(quantity) / Number(uomPerPallet));
    const remainder = Number(quantity) % Number(uomPerPallet);
    const dist: Array<{ quantity: number; is_full: boolean }> = [];
    for (let i = 0; i < fullPallets; i++) {
      dist.push({ quantity: uomPerPallet, is_full: true });
    }
    if (remainder > 0) {
      dist.push({ quantity: remainder, is_full: false });
    }
    return dist;
  }

  static async getById(id: number): Promise<any> {
    return dbExecFirst(
      `SELECT pkl.*,
              o.order_number as outbound_number,
              o.so_number, o.do_number, o.shipment_number,
              o.destination, o.kota, o.armada_no, o.container_no,
              c.customer_name, c.address, c.city,
              u.full_name as created_by_name
       FROM picklists pkl
       JOIN outbound_orders o ON pkl.outbound_order_id = o.id
       LEFT JOIN customers c ON o.customer_id = c.id
       LEFT JOIN users u ON pkl.created_by = u.id
       WHERE pkl.id = ?`,
      [id],
    );
  }

  static async getItems(picklistId: number): Promise<any[]> {
    return dbExec(
      `SELECT pki.*,
              p.product_code, p.product_name,
              COALESCE(pki.batch_number, pki.batch_no) as resolved_batch,
              sl.pallet_seq as sl_pallet_seq,
              lm.zone, lm.aisle,
              oi.so_number  AS item_so_number,
              oi.od_number  AS item_od_number,
              COALESCE(ci.customer_name, co.customer_name) AS item_customer_name,
              COALESCE(NULLIF(od.ship_to_name,''), NULLIF(o.ship_to_name,'')) AS item_ship_to,
              COALESCE(NULLIF(od.kota,''), NULLIF(o.kota,''))                 AS item_kota
       FROM picklist_items pki
       JOIN products p ON pki.product_id = p.id
       LEFT JOIN stock_locations sl ON sl.id = pki.stock_location_id
       LEFT JOIN location_master lm
              ON lm.location_code COLLATE utf8mb4_general_ci
               = pki.location    COLLATE utf8mb4_general_ci
       LEFT JOIN outbound_items oi ON oi.id = pki.outbound_item_id
       LEFT JOIN outbound_destinations od ON od.id = oi.destination_id
       LEFT JOIN outbound_orders o ON o.id = oi.outbound_order_id
       LEFT JOIN customers ci ON ci.id = oi.customer_id
       LEFT JOIN customers co ON co.id = o.customer_id
       WHERE pki.picklist_id = ?
       ORDER BY pki.location, pki.pallet_seq, pki.id`,
      [picklistId],
    );
  }

  static async getAll(status: string | null = null, limit: number | null = null, offset = 0): Promise<any[]> {
    const where = status ? 'WHERE pkl.status = ?' : '';
    const params: any[] = status ? [status] : [];

    let sql = `SELECT pkl.*,
            o.order_number as outbound_number,
            o.so_number, o.do_number, o.shipment_number,
            c.customer_name,
            COUNT(pki.id) as total_items,
            SUM(pki.quantity) as total_qty,
            CEIL(SUM(pki.pallet)) as total_pallet
            FROM picklists pkl
            JOIN outbound_orders o ON pkl.outbound_order_id = o.id
            LEFT JOIN customers c ON o.customer_id = c.id
            LEFT JOIN picklist_items pki ON pkl.id = pki.picklist_id
            ${where}
            GROUP BY pkl.id
            ORDER BY pkl.created_date DESC, pkl.created_at DESC`;

    if (limit) {
      sql += ' LIMIT ' + Number(limit) + ' OFFSET ' + Number(offset);
    }

    return dbExec(sql, params);
  }

  static async countAll(status: string | null = null): Promise<number> {
    const where = status ? 'WHERE pkl.status = ?' : '';
    const params: any[] = status ? [status] : [];
    const n = await dbScalar(`SELECT COUNT(*) FROM picklists pkl ${where}`, params);
    return Number(n ?? 0);
  }

  static async getStats(): Promise<any> {
    const rows = await dbExec('SELECT status, COUNT(*) AS cnt FROM picklists GROUP BY status');
    const s: any = { total: 0, pending: 0, picking: 0, completed: 0 };
    for (const r of rows) {
      s.total += Number(r.cnt);
      if (['Draft', 'Confirmed'].includes(r.status)) s.pending += Number(r.cnt);
      else if (r.status === 'Picked') s.picking += Number(r.cnt);
      else if (r.status === 'Completed') s.completed += Number(r.cnt);
    }
    return s;
  }

  static async updateItem(itemId: number, data: any): Promise<boolean> {
    await dbExec(
      `UPDATE picklist_items SET
         picked_quantity = ?,
         status = ?,
         location = COALESCE(?, location),
         batch_number = COALESCE(NULLIF(?, ''), batch_number),
         batch_no = COALESCE(NULLIF(?, ''), batch_no),
         notes = ?,
         picked_at = NOW()
       WHERE id = ?`,
      [
        data.picked_quantity ?? 0,
        data.status ?? 'Pending',
        data.location ?? null,
        data.batch_number ?? null,
        data.batch_number ?? null,
        data.notes ?? null,
        itemId,
      ],
    );
    return true;
  }

  static async confirm(picklistId: number): Promise<boolean> {
    await dbExec("UPDATE picklists SET status='Confirmed', confirmed_at=NOW() WHERE id=?", [picklistId]);
    return true;
  }

  static async complete(picklistId: number): Promise<boolean> {
    await dbExec("UPDATE picklists SET status='Completed', completed_at=NOW() WHERE id=?", [picklistId]);
    return true;
  }

  static async delete(picklistId: number): Promise<boolean> {
    return withTransaction(async () => {
      await dbExec('DELETE FROM picklist_items WHERE picklist_id=?', [picklistId]);
      await dbExec('DELETE FROM picklists WHERE id=?', [picklistId]);
      return true;
    });
  }

  static async exportForPrint(picklistId: number): Promise<any> {
    return {
      picklist: await Picklist.getById(picklistId),
      items: await Picklist.getItems(picklistId),
    };
  }
}

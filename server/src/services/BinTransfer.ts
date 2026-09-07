import { dbExec, dbExecFirst, dbScalar, withTransaction } from '../db';
import { ctx } from '../helpers';

export class BinTransfer {
  static async generateNumber(): Promise<string> {
    const now = new Date();
    const prefix = `BTR-${now.getFullYear()}${String(now.getMonth() + 1).padStart(2, '0')}-`;
    const last = await dbScalar(
      'SELECT transfer_number FROM bin_transfers WHERE transfer_number LIKE ? ORDER BY transfer_number DESC LIMIT 1',
      [prefix + '%'],
    );
    let seq = last ? parseInt(String(last).slice(String(last).lastIndexOf('-') + 1), 10) + 1 : 1;

    for (let i = 0; i < 20; i++) {
      const num = prefix + String(seq).padStart(4, '0');
      const chk = await dbExecFirst('SELECT id FROM bin_transfers WHERE transfer_number = ?', [num]);
      if (!chk) return num;
      seq++;
    }
    const hms = `${String(now.getHours()).padStart(2, '0')}${String(now.getMinutes()).padStart(2, '0')}${String(now.getSeconds()).padStart(2, '0')}`;
    return prefix + hms + Math.floor(Math.random() * 90 + 10);
  }

  static async getAll(status: string | null = null, limit = 200, offset = 0): Promise<any[]> {
    const where = status ? 'WHERE bt.status = ?' : '';
    const params: any[] = status ? [status] : [];
    const sql = `SELECT bt.*,
            p.product_code, p.product_name, p.uom_type, p.uom_per_pallet,
            u1.full_name AS created_by_name,
            u2.full_name AS completed_by_name
            FROM bin_transfers bt
            JOIN products p ON bt.product_id = p.id
            LEFT JOIN users u1 ON bt.created_by = u1.id
            LEFT JOIN users u2 ON bt.completed_by = u2.id
            ${where}
            ORDER BY bt.transfer_date DESC, bt.created_at DESC
            LIMIT ${Number(limit)} OFFSET ${Number(offset)}`;
    return dbExec(sql, params);
  }

  static async countAll(status: string | null = null): Promise<number> {
    const where = status ? 'WHERE bt.status = ?' : '';
    const params: any[] = status ? [status] : [];
    const n = await dbScalar(`SELECT COUNT(*) FROM bin_transfers bt ${where}`, params);
    return Number(n ?? 0);
  }

  static async getById(id: number): Promise<any> {
    return dbExecFirst(
      `SELECT bt.*,
              p.product_code, p.product_name, p.uom_type, p.uom_per_pallet,
              u1.full_name AS created_by_name,
              u2.full_name AS completed_by_name
       FROM bin_transfers bt
       JOIN products p ON bt.product_id = p.id
       LEFT JOIN users u1 ON bt.created_by = u1.id
       LEFT JOIN users u2 ON bt.completed_by = u2.id
       WHERE bt.id = ?`,
      [id],
    );
  }

  static async getStockAtLocation(productId: number, location = ''): Promise<any[]> {
    if (location !== '') {
      return dbExec(
        `SELECT s.*, p.product_name, p.product_code, p.uom_type
         FROM stock s
         JOIN products p ON s.product_id = p.id
         WHERE s.product_id = ?
           AND s.location = ?
           AND s.stock_status = 'Available'
           AND s.quantity > 0
         ORDER BY
           CASE WHEN s.expiry_date IS NULL THEN 1 ELSE 0 END,
           s.expiry_date ASC`,
        [productId, location],
      );
    }
    return dbExec(
      `SELECT s.*, p.product_name, p.product_code, p.uom_type
       FROM stock s
       JOIN products p ON s.product_id = p.id
       WHERE s.product_id = ?
         AND s.stock_status = 'Available'
         AND s.quantity > 0
         AND s.location NOT IN ('STAGING')
       ORDER BY s.location,
         CASE WHEN s.expiry_date IS NULL THEN 1 ELSE 0 END,
         s.expiry_date ASC`,
      [productId],
    );
  }

  static async getLocationsWithStock(productId: number): Promise<any[]> {
    return dbExec(
      `SELECT s.location,
              SUM(s.quantity) AS total_qty, s.uom,
              MIN(s.expiry_date) AS earliest_expiry,
              COUNT(*) AS batch_count
       FROM stock s
       WHERE s.product_id = ?
         AND s.stock_status = 'Available'
         AND s.quantity > 0
         AND s.location NOT IN ('STAGING')
       GROUP BY s.location, s.uom
       ORDER BY s.location`,
      [productId],
    );
  }

  static async create(data: any): Promise<number> {
    return withTransaction(async () => {
      const number = await BinTransfer.generateNumber();
      const specialLocs = ['QUA_SHELL', 'STAGING'];
      const fromLocCode = String(data.from_location ?? '').trim().toUpperCase();
      const toLocCode = String(data.to_location ?? '').trim().toUpperCase();

      if (!specialLocs.includes(fromLocCode)) {
        const chk = await dbExecFirst('SELECT id FROM location_master WHERE location_code = ? AND is_active = 1 LIMIT 1', [fromLocCode]);
        if (!chk) throw new Error(`Lokasi sumber '${fromLocCode}' tidak ditemukan di master lokasi.`);
      }

      if (!specialLocs.includes(toLocCode)) {
        const chk = await dbExecFirst('SELECT id FROM location_master WHERE location_code = ? AND is_active = 1 LIMIT 1', [toLocCode]);
        if (!chk) throw new Error(`Lokasi tujuan '${toLocCode}' tidak ditemukan di master lokasi.`);
      }

      if (fromLocCode === toLocCode) {
        throw new Error(`Lokasi sumber dan tujuan tidak boleh sama (${fromLocCode}).`);
      }

      const stockRow = await dbExecFirst(
        `SELECT id, quantity FROM stock
         WHERE product_id = ?
           AND location = ?
           AND stock_status = 'Available'
           AND quantity > 0
         ORDER BY
           CASE WHEN expiry_date IS NULL THEN 1 ELSE 0 END,
           expiry_date ASC
         LIMIT 1`,
        [data.product_id, fromLocCode],
      );

      if (!stockRow) {
        throw new Error(`Tidak ada stok tersedia di lokasi ${fromLocCode}.`);
      }

      const totalAvail = Number(await dbScalar(
        `SELECT SUM(quantity) FROM stock
         WHERE product_id = ? AND location = ?
           AND stock_status = 'Available'`,
        [data.product_id, fromLocCode],
      ) ?? 0);

      if (Number(data.quantity) > totalAvail + 0.001) {
        throw new Error(
          'Stok tidak cukup. Tersedia: ' + Number(totalAvail).toFixed(2) +
          ' — Diminta: ' + Number(data.quantity).toFixed(2),
        );
      }

      const ins = await dbExec(
        `INSERT INTO bin_transfers
           (transfer_number, transfer_date, product_id, stock_id,
            batch_number, from_location, to_location,
            quantity, uom, reason, status, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending', ?)`,
        [
          number,
          data.transfer_date,
          data.product_id,
          stockRow.id,
          data.batch_number ?? null,
          fromLocCode,
          toLocCode,
          data.quantity,
          data.uom ?? 'Drum',
          data.reason ?? null,
          ctx().user?.id ?? null,
        ],
      );
      return Number((ins as any).insertId);
    });
  }

  static async execute(transferId: number): Promise<boolean> {
    return withTransaction(async () => {
      const transfer = await BinTransfer.getById(transferId);
      if (!transfer) throw new Error('Transfer tidak ditemukan');
      if (transfer.status !== 'Pending') {
        throw new Error(`Transfer status harus Pending (saat ini: ${transfer.status})`);
      }

      const qty = Number(transfer.quantity);
      const productId = Number(transfer.product_id);
      const fromLoc = transfer.from_location;
      const toLoc = transfer.to_location;
      const uom = transfer.uom;

      const srcRows = await dbExec(
        `SELECT * FROM stock
         WHERE product_id = ? AND location = ?
           AND stock_status = 'Available'
           AND quantity > 0
         ORDER BY
           CASE WHEN expiry_date IS NULL THEN 1 ELSE 0 END,
           expiry_date ASC`,
        [productId, fromLoc],
      );

      if (!srcRows.length) {
        throw new Error(`Tidak ada stok di lokasi sumber: ${fromLoc}`);
      }

      const totalAvail = srcRows.reduce((s: number, r: any) => s + Number(r.quantity), 0);
      if (qty > totalAvail + 0.001) {
        throw new Error('Stok tidak cukup: tersedia ' + Math.round(totalAvail * 100) / 100 + ', diminta ' + Math.round(qty * 100) / 100);
      }

      let remaining = qty;
      let usedBatch: any = null;
      let usedExpiry: any = null;

      for (const src of srcRows) {
        if (remaining <= 0.001) break;
        const deduct = Math.min(remaining, Number(src.quantity));
        const newQty = Number(src.quantity) - deduct;

        if (!usedBatch) {
          usedBatch = src.batch_number;
          usedExpiry = src.expiry_date;
        }

        if (newQty <= 0.001) {
          await dbExec('DELETE FROM stock WHERE id = ?', [src.id]);
        } else {
          await dbExec('UPDATE stock SET quantity = ?, updated_at = NOW() WHERE id = ?', [newQty, src.id]);
        }

        const sl = await dbExecFirst(
          `SELECT id, quantity FROM stock_locations
           WHERE stock_id = ? AND status = 'Available' ORDER BY pallet_seq ASC LIMIT 1`,
          [src.id],
        );
        if (sl) {
          const slNew = Math.max(0, Number(sl.quantity) - deduct);
          await dbExec('UPDATE stock_locations SET quantity = ?, status = ? WHERE id = ?', [
            slNew,
            slNew <= 0 ? 'Picked' : 'Available',
            sl.id,
          ]);
        }

        remaining -= deduct;
      }

      const dest = await dbExecFirst(
        `SELECT id, quantity FROM stock
         WHERE product_id = ? AND location = ?
           AND batch_number <=> ?
           AND stock_status = 'Available'
         LIMIT 1`,
        [productId, toLoc, usedBatch],
      );

      let destStockId: number;
      if (dest) {
        await dbExec('UPDATE stock SET quantity = quantity + ?, updated_at = NOW() WHERE id = ?', [qty, dest.id]);
        destStockId = dest.id;
      } else {
        const ins = await dbExec(
          `INSERT INTO stock
             (product_id, batch_number, location, quantity, uom,
              pallet, manufacture_date, expiry_date, stock_status)
           VALUES (?, ?, ?, ?, ?, ?, NULL, ?, 'Available')`,
          [
            productId,
            usedBatch,
            toLoc,
            qty,
            uom,
            Math.ceil(qty / Math.max(1, Number(transfer.uom_per_pallet ?? 4))),
            usedExpiry,
          ],
        );
        destStockId = Number((ins as any).insertId);
      }

      const currentBalance = Number(await dbScalar(
        'SELECT balance FROM stock_ledger WHERE product_id = ? ORDER BY id DESC LIMIT 1',
        [productId],
      ) ?? 0);

      await BinTransfer._addLedger(productId, 'TRANSFER_OUT', 'BinTransfer', transferId,
        transfer.transfer_number, usedBatch, 0, qty, uom, fromLoc,
        `Bin Transfer ke ${toLoc}`, currentBalance - qty);

      await BinTransfer._addLedger(productId, 'TRANSFER_IN', 'BinTransfer', transferId,
        transfer.transfer_number, usedBatch, qty, 0, uom, toLoc,
        `Bin Transfer dari ${fromLoc}`, currentBalance);

      await dbExec(
        `UPDATE bin_transfers SET
           status = 'Completed',
           completed_by = ?,
           completed_at = NOW(),
           updated_at = NOW()
         WHERE id = ?`,
        [ctx().user?.id ?? null, transferId],
      );
      return true;
    });
  }

  static async cancel(transferId: number): Promise<boolean> {
    const transfer = await BinTransfer.getById(transferId);
    if (!transfer) throw new Error('Transfer tidak ditemukan');
    if (transfer.status !== 'Pending') {
      throw new Error('Hanya transfer berstatus Pending yang dapat dibatalkan');
    }
    await dbExec("UPDATE bin_transfers SET status = 'Cancelled', updated_at = NOW() WHERE id = ?", [transferId]);
    return true;
  }

  private static async _addLedger(
    productId: number,
    txType: string,
    refType: string,
    refId: number,
    refNo: string,
    batch: any,
    qIn: number,
    qOut: number,
    uom: string,
    location: string,
    notes: string,
    forceBalance: number | null = null,
  ): Promise<void> {
    let balance: number;
    if (forceBalance !== null) {
      balance = forceBalance;
    } else {
      const bal = Number(await dbScalar(
        'SELECT balance FROM stock_ledger WHERE product_id = ? ORDER BY id DESC LIMIT 1',
        [productId],
      ) ?? 0);
      balance = bal + qIn - qOut;
    }

    await dbExec(
      `INSERT INTO stock_ledger
         (transaction_date, product_id, transaction_type, reference_type,
          reference_id, reference_number, batch_number,
          quantity_in, quantity_out, uom, balance, location, notes)
       VALUES (CURDATE(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`,
      [productId, txType, refType, refId, refNo, batch, qIn, qOut, uom, balance, location, notes],
    );
  }
}

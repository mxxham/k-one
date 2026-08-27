import { dbExec, dbExecFirst } from '../db';
import { ctx, todayYmd } from '../helpers';
import { JsonOutSent } from '../helpers';

function csvField(v: any): string {
  const s = v === null || v === undefined ? '' : String(v);
  if (s.includes(',') || s.includes('"') || s.includes('\n') || s.includes('\r')) {
    return '"' + s.replace(/"/g, '""') + '"';
  }
  return s;
}

/** Port of classes/Report.php */
export class Report {
  static async getDailyReport(date: string | null = null, dateTo: string | null = null): Promise<any> {
    if (!date) date = todayYmd();
    if (!dateTo) dateTo = date;

    return {
      date,
      date_to: dateTo,
      stock_summary: await Report.getStockSummary(date, dateTo),
      inbound_activity: await Report.getInboundActivity(date, dateTo),
      outbound_activity: await Report.getOutboundActivity(date, dateTo),
      expiring_items: await Report.getExpiringItems(),
      low_stock: await Report.getLowStock(),
      ledger_summary: await Report.getLedgerSummary(date, dateTo),
    };
  }

  private static async getStockSummary(dateFrom: string | null = null, dateTo: string | null = null): Promise<any[]> {
    return dbExec(`SELECT
      p.product_code, p.product_name,
      COALESCE(p.uom_type, 'Drum') as uom_type,
      COUNT(s.id) as batches,
      SUM(s.quantity) as total_qty, SUM(s.quantity) as total_drums,
      SUM(CEILING(s.quantity / GREATEST(p.uom_per_pallet,1))) as total_pallets,
      MIN(s.expiry_date) as nearest_expiry
      FROM stock s
      JOIN products p ON s.product_id = p.id
      WHERE s.quantity > 0 AND s.stock_status = 'Available'
      GROUP BY p.id
      ORDER BY p.product_name`);
  }

  private static async getInboundActivity(dateFrom: string, dateTo: string): Promise<any[]> {
    return dbExec(`SELECT io.*, COUNT(ii.id) as item_count,
      SUM(COALESCE(ii.actual_qty, ii.quantity, 0)) as total_drums
      FROM inbound_orders io
      LEFT JOIN inbound_items ii ON io.id = ii.inbound_order_id
      WHERE (DATE(io.order_date) BETWEEN ? AND ?)
         OR (DATE(io.received_date) BETWEEN ? AND ?)
         OR (DATE(io.created_at) BETWEEN ? AND ?)
      GROUP BY io.id
      ORDER BY COALESCE(io.received_date, io.order_date) DESC, io.id DESC`,
      [dateFrom, dateTo, dateFrom, dateTo, dateFrom, dateTo]);
  }

  private static async getOutboundActivity(dateFrom: string, dateTo: string): Promise<any[]> {
    return dbExec(`SELECT oo.*, COUNT(oi.id) as item_count,
      SUM(COALESCE(oi.actual_qty, oi.quantity, 0)) as total_drums
      FROM outbound_orders oo
      LEFT JOIN outbound_items oi ON oo.id = oi.outbound_order_id
      WHERE (DATE(oo.order_date) BETWEEN ? AND ?)
         OR (DATE(oo.created_at) BETWEEN ? AND ?)
      GROUP BY oo.id
      ORDER BY oo.order_date DESC, oo.id DESC`,
      [dateFrom, dateTo, dateFrom, dateTo]);
  }

  private static async getExpiringItems(): Promise<any[]> {
    return dbExec(`SELECT s.*, p.product_code, p.product_name,
      DATEDIFF(s.expiry_date, CURDATE()) as days_until_expiry
      FROM stock s
      JOIN products p ON s.product_id = p.id
      WHERE s.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 180 DAY)
      AND s.quantity > 0 AND s.stock_status = 'Available'
      ORDER BY s.expiry_date ASC
      LIMIT 50`);
  }

  private static async getLowStock(): Promise<any[]> {
    return dbExec(`SELECT p.product_code, p.product_name,
      SUM(s.quantity) as total_drums
      FROM stock s
      JOIN products p ON s.product_id = p.id
      WHERE s.quantity > 0 AND s.stock_status = 'Available'
      GROUP BY p.id
      HAVING total_drums < 16
      ORDER BY total_drums ASC`);
  }

  private static async getLedgerSummary(dateFrom: string, dateTo: string): Promise<any> {
    const ledgerResult = await dbExecFirst(
      `SELECT
        COUNT(CASE WHEN transaction_type = 'IN' THEN 1 END) as transactions_in,
        COUNT(CASE WHEN transaction_type = 'OUT' THEN 1 END) as transactions_out,
        SUM(CASE WHEN transaction_type = 'IN' THEN COALESCE(quantity_in, 0) ELSE 0 END) as qty_in,
        SUM(CASE WHEN transaction_type = 'OUT' THEN COALESCE(quantity_out, 0) ELSE 0 END) as qty_out
       FROM stock_ledger
       WHERE (transaction_date BETWEEN ? AND ?) OR (DATE(created_at) BETWEEN ? AND ?)`,
      [dateFrom, dateTo, dateFrom, dateTo],
    );

    if (Number(ledgerResult?.qty_in ?? 0) === 0 && Number(ledgerResult?.qty_out ?? 0) === 0) {
      const inResult = await dbExecFirst(
        `SELECT
          COUNT(DISTINCT io.id) as transactions_in,
          COALESCE(SUM(ii.actual_qty), 0) as qty_in
         FROM inbound_orders io
         LEFT JOIN inbound_items ii ON io.id = ii.inbound_order_id
         WHERE (DATE(io.order_date) BETWEEN ? AND ?) OR (DATE(io.created_at) BETWEEN ? AND ?)`,
        [dateFrom, dateTo, dateFrom, dateTo],
      );

      const outResult = await dbExecFirst(
        `SELECT
          COUNT(DISTINCT oo.id) as transactions_out,
          COALESCE(SUM(oi.actual_qty), 0) as qty_out
         FROM outbound_orders oo
         LEFT JOIN outbound_items oi ON oo.id = oi.outbound_order_id
         WHERE (DATE(oo.order_date) BETWEEN ? AND ?) OR (DATE(oo.created_at) BETWEEN ? AND ?)`,
        [dateFrom, dateTo, dateFrom, dateTo],
      );

      return {
        transactions_in: inResult?.transactions_in ?? 0,
        transactions_out: outResult?.transactions_out ?? 0,
        qty_in: inResult?.qty_in ?? 0,
        qty_out: outResult?.qty_out ?? 0,
      };
    }

    return ledgerResult;
  }

  static async exportToCSV(data: any[], filename: string): Promise<never> {
    const c = ctx();
    const res = c.res;
    if (!res.headersSent) {
      res.setHeader('Content-Type', 'text/csv');
      res.setHeader('Content-Disposition', 'attachment; filename="' + filename + '"');
    }
    let out = '';
    if (data.length) {
      out += Object.keys(data[0]).map(csvField).join(',') + '\n';
      for (const row of data) {
        out += Object.values(row).map(csvField).join(',') + '\n';
      }
    }
    res.send(out);
    throw new JsonOutSent();
  }
}

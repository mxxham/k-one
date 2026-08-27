import { dbExec, dbExecFirst, dbScalar, withTransaction } from '../db';

export class Stock {
  static async getAll(status: string | null = null, expiring = false): Promise<any[]> {
    let sql = `SELECT s.*, p.product_code, p.product_name, p.category, p.uom_type, p.uom_per_pallet
               FROM stock s
               JOIN products p ON s.product_id = p.id
               WHERE s.quantity > 0`;

    if (status) {
      sql += ' AND s.stock_status = ?';
    }

    if (expiring) {
      sql += " AND s.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 90 DAY) ORDER BY s.expiry_date ASC";
    } else {
      sql += ' ORDER BY p.product_name, s.expiry_date ASC';
    }

    return dbExec(sql, status ? [status] : []);
  }

  static async getById(id: number): Promise<any> {
    return dbExecFirst(
      `SELECT s.*, p.product_code, p.product_name, p.category, p.uom_type, p.uom_per_pallet
       FROM stock s
       JOIN products p ON s.product_id = p.id
       WHERE s.id = ?`,
      [id],
    );
  }

  static async getByProduct(productId: number): Promise<any[]> {
    return dbExec('SELECT * FROM stock WHERE product_id = ? AND quantity > 0 ORDER BY expiry_date ASC', [productId]);
  }

  static async getSummary(): Promise<any> {
    const summary = await dbExecFirst(
      `SELECT
         COUNT(DISTINCT product_id) as total_products,
         SUM(quantity) as total_drums,
         SUM(pallet) as total_pallets,
         COUNT(CASE WHEN stock_status = 'Available' THEN 1 END) as available_items,
         COUNT(CASE WHEN stock_status = 'Reserved' THEN 1 END) as reserved_items,
         COUNT(CASE WHEN stock_status = 'Expired' THEN 1 END) as expired_items,
         COUNT(CASE WHEN stock_status = 'Dues In' THEN 1 END) as dues_in_items
       FROM stock WHERE quantity > 0`,
    );

    const expiring = await dbScalar(
      `SELECT COUNT(*) as count FROM stock
       WHERE expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
       AND quantity > 0 AND stock_status = 'Available'`,
    );

    const critical = await dbScalar(
      `SELECT COUNT(*) as count FROM stock
       WHERE expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 120 DAY)
       AND quantity > 0 AND stock_status = 'Available'`,
    );

    const expired = await dbScalar(
      `SELECT COUNT(*) as count FROM stock
       WHERE expiry_date < CURDATE()
       AND quantity > 0`,
    );

    summary.expiring_soon = expiring;
    summary.critical = critical;
    summary.expired = expired;
    summary.total_qty = Number(summary.total_drums ?? 0);
    return summary;
  }

  static async getExpiringSoon(days = 30): Promise<any[]> {
    return dbExec(
      `SELECT s.*, p.product_code, p.product_name, p.uom_type, p.uom_per_pallet,
              DATEDIFF(s.expiry_date, CURDATE()) as days_until_expiry
       FROM stock s
       JOIN products p ON s.product_id = p.id
       WHERE s.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? DAY)
       AND s.quantity > 0 AND s.stock_status = 'Available'
       ORDER BY s.expiry_date ASC`,
      [days],
    );
  }

  static getExpiryInfo(expiryDate: any): any {
    if (!expiryDate) {
      return {
        has_expiry: false,
        text: 'No expiry',
        months: 0,
        days: 0,
        total_days: 0,
        is_expired: false,
        is_critical: false,
        css_class: 'text-gray-400',
        bg_class: 'bg-gray-50',
      };
    }

    const expiryStr = String(expiryDate);
    const expiry = new Date(/^\d{4}-\d{2}-\d{2}$/.test(expiryStr) ? expiryStr + 'T00:00:00' : expiryStr);
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    const diffMs = expiry.getTime() - today.getTime();

    const totalDays = Math.round(Math.abs(diffMs) / 86400000);
    let months = (expiry.getFullYear() - today.getFullYear()) * 12 + (expiry.getMonth() - today.getMonth());
    let days = expiry.getDate() - today.getDate();
    if (days < 0) {
      months -= 1;
      days += new Date(expiry.getFullYear(), expiry.getMonth(), 0).getDate();
    }
    if (months < 0) months = 0;
    if (days < 0) days = 0;

    const isExpired = diffMs < 0;
    const isCritical = !isExpired && totalDays <= 120;
    const isWarning = !isExpired && totalDays <= 180;

    let cssClass = 'text-gray-700';
    let bgClass = 'bg-gray-50';
    let icon = '';

    if (isExpired) {
      cssClass = 'text-red-700 font-bold';
      bgClass = 'bg-red-100';
      icon = '✗ ';
    } else if (isCritical) {
      cssClass = 'text-red-600 font-bold';
      bgClass = 'bg-red-50 border border-red-300';
      icon = '⚠ ';
    } else if (isWarning) {
      cssClass = 'text-orange-600';
      bgClass = 'bg-orange-50';
      icon = '⚠ ';
    }

    const text = isExpired ? `Expired ${totalDays} days ago` : `${months}m ${days}d left`;

    const monthNames = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

    return {
      has_expiry: true,
      text,
      formatted_date: `${String(expiry.getDate()).padStart(2, '0')} ${monthNames[expiry.getMonth()]} ${expiry.getFullYear()}`,
      months,
      days,
      total_days: totalDays,
      is_expired: isExpired,
      is_critical: isCritical,
      is_warning: isWarning,
      css_class: cssClass,
      bg_class: bgClass,
      icon,
    };
  }

  static async getMovement(productId: number | null = null, startDate: string | null = null, endDate: string | null = null, limit = 100): Promise<any[]> {
    let sql = `SELECT sl.*, p.product_code, p.product_name
               FROM stock_ledger sl
               JOIN products p ON sl.product_id = p.id
               WHERE 1=1`;

    const params: any[] = [];

    if (productId) {
      sql += ' AND sl.product_id = ?';
      params.push(productId);
    }

    if (startDate) {
      sql += ' AND sl.transaction_date >= ?';
      params.push(startDate);
    }

    if (endDate) {
      sql += ' AND sl.transaction_date <= ?';
      params.push(endDate);
    }

    sql += ' ORDER BY sl.transaction_date DESC, sl.created_at DESC';

    if (limit) {
      sql += ' LIMIT ' + Number(limit);
    }

    return dbExec(sql, params);
  }

  static async getStockByLocation(): Promise<any[]> {
    return dbExec(
      `SELECT SUBSTRING_INDEX(location, '-', 1) as area,
              COUNT(DISTINCT product_id) as products,
              SUM(quantity) as total_qty,
              SUM(pallet) as total_pallet
       FROM stock WHERE quantity > 0 AND location IS NOT NULL
       GROUP BY SUBSTRING_INDEX(location, '-', 1)
       ORDER BY area`,
    );
  }

  static async transfer(stockId: number, newLocation: string, quantity: number | null = null): Promise<boolean> {
    return withTransaction(async () => {
      const stock = await Stock.getById(stockId);
      const transferQty = quantity ?? stock.quantity;

      if (transferQty > stock.quantity) {
        throw new Error('Transfer quantity exceeds available stock');
      }

      if (transferQty < stock.quantity) {
        const uomPerPallet = stock.uom_per_pallet ?? 4;
        const palletReduction = Math.ceil(transferQty / uomPerPallet);
        await dbExec('UPDATE stock SET quantity = quantity - ?, pallet = pallet - ? WHERE id = ?', [transferQty, palletReduction, stockId]);

        await dbExec(
          'INSERT INTO stock (product_id, batch_number, quantity, uom, pallet, manufacture_date, expiry_date, location, stock_status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
          [
            stock.product_id,
            stock.batch_number,
            transferQty,
            stock.uom,
            palletReduction,
            stock.manufacture_date,
            stock.expiry_date,
            newLocation,
            stock.stock_status,
          ],
        );
      } else {
        await dbExec('UPDATE stock SET location = ? WHERE id = ?', [newLocation, stockId]);
      }
      return true;
    });
  }

  static async adjust(stockId: number, newQuantity: number, reason: string): Promise<boolean> {
    return withTransaction(async () => {
      const stock = await Stock.getById(stockId);
      const oldQuantity = stock.quantity;
      const difference = newQuantity - oldQuantity;

      const uomPerPallet = stock.uom_per_pallet ?? 4;
      const newPallet = Math.ceil(newQuantity / uomPerPallet);
      await dbExec('UPDATE stock SET quantity = ?, pallet = ? WHERE id = ?', [newQuantity, newPallet, stockId]);

      const balance = Number(await dbScalar(
        "SELECT COALESCE(SUM(quantity), 0) as balance FROM stock WHERE product_id = ? AND stock_status = 'Available'",
        [stock.product_id],
      ) ?? 0);

      const type = difference > 0 ? 'IN' : 'OUT';

      const now = new Date();
      const ymdHis = `${now.getFullYear()}${String(now.getMonth() + 1).padStart(2, '0')}${String(now.getDate()).padStart(2, '0')}${String(now.getHours()).padStart(2, '0')}${String(now.getMinutes()).padStart(2, '0')}${String(now.getSeconds()).padStart(2, '0')}`;
      const adjNumber = 'ADJ-' + ymdHis;

      await dbExec(
        `INSERT INTO stock_ledger (transaction_date, product_id, batch_number, transaction_type, quantity_in, quantity_out, uom, pallet, reference_number, reference_type, balance, location, notes)
         VALUES (CURDATE(), ?, ?, ?, ?, ?, ?, ?, ?, 'Adjustment', ?, ?, ?)`,
        [
          stock.product_id,
          stock.batch_number,
          type,
          type === 'IN' ? difference : 0,
          type === 'OUT' ? Math.abs(difference) : 0,
          stock.uom_type ?? stock.uom,
          difference / uomPerPallet,
          adjNumber,
          balance + (type === 'IN' ? difference : 0),
          stock.location,
          reason,
        ],
      );
      return true;
    });
  }
}

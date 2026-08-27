import { dbExec, dbExecFirst, dbScalar } from '../db';

/**
 * Port of classes/Product.php.
 */
export class Product {
  static async getAll(limit: number | null = null, offset = 0): Promise<any[]> {
    let sql = `SELECT p.*,
            COALESCE(SUM(s.quantity), 0) as total_drums,
            COALESCE(SUM(s.quantity), 0) as total_qty,
            COALESCE(SUM(CEILING(s.quantity / GREATEST(p.uom_per_pallet, 1))), 0) as total_pallets
            FROM products p
            LEFT JOIN stock s ON p.id = s.product_id
                AND (s.stock_status IN ('Available','Dues In') OR s.stock_status IS NULL OR s.stock_status = '')
                AND s.quantity > 0
                AND (s.location IS NULL OR s.location NOT IN ('QUA_SHELL','STAGING'))
            GROUP BY p.id
            ORDER BY p.product_name`;
    if (limit) {
      sql += ' LIMIT ' + Number(limit) + ' OFFSET ' + Number(offset);
    }
    return await dbExec(sql);
  }

  static async getCount(search = ''): Promise<number> {
    if (search) {
      const term = '%' + search + '%';
      const n = await dbScalar(
        'SELECT COUNT(*) FROM products WHERE product_code LIKE ? OR product_name LIKE ? OR category LIKE ?',
        [term, term, term],
      );
      return Number(n ?? 0);
    }
    const n = await dbScalar('SELECT COUNT(*) FROM products');
    return Number(n ?? 0);
  }

  static async getStatsByUom(): Promise<Record<string, number>> {
    const rows = await dbExec('SELECT uom_type, COUNT(*) as cnt FROM products GROUP BY uom_type');
    const result: Record<string, number> = {};
    for (const r of rows) result[r.uom_type] = Number(r.cnt);
    return result;
  }

  static async getPaginated(search: string, perPage: number, offset: number): Promise<any[]> {
    let where = '';
    const params: any[] = [];
    if (search) {
      const term = '%' + search + '%';
      where = ' WHERE p.product_code LIKE ? OR p.product_name LIKE ? OR p.category LIKE ?';
      params.push(term, term, term);
    }
    const sql = `SELECT p.*,
            COALESCE(SUM(s.quantity), 0) as total_drums,
            COALESCE(SUM(s.quantity), 0) as total_qty,
            COALESCE(SUM(CEILING(s.quantity / GREATEST(p.uom_per_pallet, 1))), 0) as total_pallets
            FROM products p
            LEFT JOIN stock s ON p.id = s.product_id
                AND (s.stock_status IN ('Available','Dues In') OR s.stock_status IS NULL OR s.stock_status = '')
                AND s.quantity > 0
                AND (s.location IS NULL OR s.location NOT IN ('QUA_SHELL','STAGING'))
            ${where}
            GROUP BY p.id
            ORDER BY p.product_name
            LIMIT ` + Number(perPage) + ' OFFSET ' + Number(offset);
    return await dbExec(sql, params);
  }

  static async getById(id: number): Promise<any> {
    return dbExecFirst('SELECT * FROM products WHERE id = ?', [id]);
  }

  static async getByCode(code: string): Promise<any> {
    return dbExecFirst('SELECT * FROM products WHERE product_code = ?', [code]);
  }

  static async create(data: any): Promise<any> {
    return dbExec(
      `INSERT INTO products (product_code, product_name, category, description, drums_per_pallet, uom_type, uom_per_pallet, liters_per_unit, max_sku_qty, max_trans_qty) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`,
      [
        data.product_code,
        data.product_name,
        data.category ?? null,
        data.description ?? null,
        data.drums_per_pallet ?? 4,
        data.uom_type ?? 'Drum',
        data.uom_per_pallet ?? 4,
        data.liters_per_unit ?? 209.00,
        data.max_sku_qty ?? 44,
        data.max_trans_qty ?? 80,
      ],
    );
  }

  static async update(id: number, data: any): Promise<any> {
    return dbExec(
      `UPDATE products SET product_code = ?, product_name = ?, category = ?, description = ?, drums_per_pallet = ?, uom_type = ?, uom_per_pallet = ?, liters_per_unit = ?, max_sku_qty = ?, max_trans_qty = ? WHERE id = ?`,
      [
        data.product_code,
        data.product_name,
        data.category ?? null,
        data.description ?? null,
        data.drums_per_pallet ?? 4,
        data.uom_type ?? 'Drum',
        data.uom_per_pallet ?? 4,
        data.liters_per_unit ?? 209.00,
        data.max_sku_qty ?? 44,
        data.max_trans_qty ?? 80,
        id,
      ],
    );
  }

  static async delete(id: number): Promise<any> {
    return dbExec('DELETE FROM products WHERE id = ?', [id]);
  }
}

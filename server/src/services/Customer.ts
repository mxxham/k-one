import { dbExec, dbExecFirst, dbScalar } from '../db';

/**
 * Port of classes/Customer.php.
 */
export class Customer {
  static async getAll(): Promise<any[]> {
    return dbExec('SELECT * FROM customers ORDER BY customer_name');
  }

  static async getCount(search = ''): Promise<number> {
    if (search) {
      const term = '%' + search + '%';
      const n = await dbScalar(
        'SELECT COUNT(*) FROM customers WHERE customer_code LIKE ? OR customer_name LIKE ? OR city LIKE ?',
        [term, term, term],
      );
      return Number(n ?? 0);
    }
    const n = await dbScalar('SELECT COUNT(*) FROM customers');
    return Number(n ?? 0);
  }

  static async getTypeStats(): Promise<Record<string, number>> {
    const active = Number(await dbScalar('SELECT COUNT(*) FROM customers WHERE is_active = 1') ?? 0);
    const inactive = Number(await dbScalar('SELECT COUNT(*) FROM customers WHERE is_active = 0') ?? 0);
    const result: Record<string, number> = {};
    if (active) result['Active'] = active;
    if (inactive) result['Inactive'] = inactive;
    return result;
  }

  static async getPaginated(search: string, perPage: number, offset: number): Promise<any[]> {
    let where = '';
    const params: any[] = [];
    if (search) {
      const term = '%' + search + '%';
      where = ' WHERE customer_code LIKE ? OR customer_name LIKE ? OR city LIKE ?';
      params.push(term, term, term);
    }
    const sql = `SELECT * FROM customers${where} ORDER BY customer_name LIMIT ` + Number(perPage) + ' OFFSET ' + Number(offset);
    return dbExec(sql, params);
  }

  static async getById(id: number): Promise<any> {
    return dbExecFirst('SELECT * FROM customers WHERE id = ?', [id]);
  }

  static async create(data: any): Promise<any> {
    return dbExec(
      `INSERT INTO customers (customer_code, customer_name, contact_person, phone, email, address) VALUES (?, ?, ?, ?, ?, ?)`,
      [
        data.customer_code,
        data.customer_name,
        data.contact_person ?? null,
        data.phone ?? null,
        data.email ?? null,
        data.address ?? null,
      ],
    );
  }

  static async update(id: number, data: any): Promise<any> {
    return dbExec(
      `UPDATE customers SET customer_code = ?, customer_name = ?, contact_person = ?, phone = ?, email = ?, address = ? WHERE id = ?`,
      [
        data.customer_code,
        data.customer_name,
        data.contact_person ?? null,
        data.phone ?? null,
        data.email ?? null,
        data.address ?? null,
        id,
      ],
    );
  }

  static async delete(id: number): Promise<any> {
    return dbExec('DELETE FROM customers WHERE id = ?', [id]);
  }
}

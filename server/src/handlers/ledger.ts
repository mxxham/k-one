import {
  jsonErr,
  jsonOut,
  query,
  productsOptions,
  apiRequireAuth,
  apiRequireAdmin,
} from '../helpers';
import { dbExec, withTransaction } from '../db';
import { activityLog } from '../activityLog';

export async function handleLedger(action: string): Promise<void> {
  switch (action) {
    case 'list': {
      apiRequireAuth();
      const productId = String(query('product_id') || '').trim();
      const startDate = String(query('start_date') || '').trim();
      const endDate = String(query('end_date') || '').trim();
      let limit = Number(query('limit', 200));
      if (limit <= 0 || limit > 5000) limit = 200;

      const where: string[] = [];
      const params: any[] = [];

      if (productId !== '') {
        where.push('sl.product_id = ?');
        params.push(Number(productId));
      }
      if (startDate !== '') {
        where.push('sl.transaction_date >= ?');
        params.push(startDate);
      }
      if (endDate !== '') {
        where.push('sl.transaction_date <= ?');
        params.push(endDate);
      }

      const whereSql = where.length ? 'WHERE ' + where.join(' AND ') : '';

      const sql = `SELECT sl.id, sl.transaction_date, sl.product_id,
                        p.product_code, p.product_name,
                        sl.transaction_type, sl.reference_type, sl.reference_number,
                        sl.batch_number, sl.quantity_in, sl.quantity_out,
                        sl.uom, sl.pallet, sl.balance, sl.location, sl.notes, sl.created_at
                    FROM stock_ledger sl
                    JOIN products p ON sl.product_id = p.id
                    ${whereSql}
                    ORDER BY sl.transaction_date DESC, sl.created_at DESC, sl.id DESC
                    LIMIT ${limit}`;

      const rows = await dbExec(sql, params);

      for (const r of rows) {
        r.id = Number(r.id);
        r.quantity_in = Number(r.quantity_in);
        r.quantity_out = Number(r.quantity_out);
        r.pallet = Number(r.pallet);
        r.balance = Number(r.balance);
      }

      jsonOut({
        rows,
        products: await productsOptions(),
      });
    }

    case 'repair_all': {
      apiRequireAdmin();
      await withTransaction(async () => {
        const productIds = (await dbExec('SELECT id FROM products ORDER BY id')).map((r: any) => r.id);
        for (const pid of productIds) {
          const movements = await dbExec(
            `SELECT id, quantity_in, quantity_out FROM stock_ledger
             WHERE product_id = ? ORDER BY transaction_date ASC, created_at ASC, id ASC`,
            [pid],
          );
          let balance = 0.0;
          for (const m of movements) {
            balance += Number(m.quantity_in);
            balance -= Number(m.quantity_out);
            await dbExec('UPDATE stock_ledger SET balance = ? WHERE id = ?', [balance, Number(m.id)]);
          }
        }
      });
      await activityLog('REPAIR_LEDGER', 'ledger', 'Ledger', null, null, 'Perbaiki seluruh data ledger');
      jsonOut({ ok: true });
    }

    default:
      jsonErr('Invalid action: ' + action, 404);
  }
}

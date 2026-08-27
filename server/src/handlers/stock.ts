import {
  body,
  jsonErr,
  jsonOut,
  query,
  apiRequireAuth,
  apiRequireWrite,
  apiRequireAdmin,
} from '../helpers';
import { dbExec, dbExecFirst } from '../db';
import { activityLog } from '../activityLog';
import { Stock } from '../services/Stock';

export async function handleStock(action: string): Promise<void> {
  switch (action) {
    case 'list': {
      apiRequireAuth();
      const status = query('status') || null;
      const expiring = query('expiring') === '1' || query('expiring') === 'true';
      const search = String(query('q') || '').trim();
      const location = String(query('location') || '').trim();
      let rows = await Stock.getAll(status, expiring);
      if (search) {
        const needle = search.toLowerCase();
        rows = rows.filter((r: any) =>
          String(r.product_code ?? '').toLowerCase().includes(needle)
          || String(r.product_name ?? '').toLowerCase().includes(needle)
          || String(r.batch_number ?? '').toLowerCase().includes(needle)
          || String(r.location ?? '').toLowerCase().includes(needle),
        );
      }
      if (location) {
        rows = rows.filter((r: any) => String(r.location ?? '').toLowerCase() === location.toLowerCase());
      }
      jsonOut({ rows, summary: await Stock.getSummary() });
    }

    case 'summary':
      apiRequireAuth();
      jsonOut({ summary: await Stock.getSummary() });

    case 'expiring':
      apiRequireAuth();
      jsonOut({ rows: await Stock.getExpiringSoon(Number(query('days', 90))) });

    case 'by_location':
      apiRequireAuth();
      jsonOut({ rows: await Stock.getStockByLocation() });

    case 'detail':
      apiRequireAuth();
      jsonOut({ stock: await Stock.getById(Number(query('id')) || 0) });

    case 'locations': {
      apiRequireAuth();
      const rows = await dbExec("SELECT DISTINCT location FROM stock WHERE location IS NOT NULL AND location != '' AND quantity > 0 ORDER BY location");
      jsonOut({ rows: rows.map((r: any) => r.location) });
    }

    case 'transfer': {
      apiRequireWrite();
      const data = body();
      const stockId = Number(data.stock_id ?? 0);
      const newLocation = String(data.to_location ?? '').trim().toUpperCase();
      const quantity = data.quantity !== undefined && data.quantity !== '' ? Number(data.quantity) : null;
      if (!stockId || !newLocation) jsonErr('stock_id dan to_location wajib diisi.');
      const st = await Stock.getById(stockId);
      await Stock.transfer(stockId, newLocation, quantity);
      await activityLog('STOCK_TRANSFER', 'stock', 'Stock', stockId, null,
        `Transfer stok ID ${stockId} → ${newLocation} qty ${quantity ?? 'all'}`);
      jsonOut({ ok: true });
    }

    case 'adjust': {
      apiRequireAdmin();
      const data = body();
      const stockId = Number(data.stock_id ?? 0);
      const newQty = Number(data.quantity ?? 0);
      const reason = String(data.reason ?? '').trim();
      if (!stockId) jsonErr('stock_id wajib diisi.');
      if (newQty < 0) jsonErr('Quantity tidak boleh negatif.');
      await Stock.adjust(stockId, newQty, reason);
      await activityLog('STOCK_ADJUST', 'stock', 'Stock', stockId, null,
        `Adjust stok ID ${stockId} → ${newQty} ${reason || 'no reason'}`);
      jsonOut({ ok: true });
    }

    case 'scan': {
      apiRequireAuth();
      const code = String(query('code') || body()?.code || '').trim();
      if (!code) jsonErr('Kode wajib diisi.');
      const row = await dbExecFirst(
        `SELECT p.id, p.product_code, p.product_name, p.uom_type, p.uom_per_pallet,
                p.default_location,
                COALESCE((SELECT s.location FROM stock s
                          WHERE s.product_id = p.id AND s.stock_status = 'Available'
                            AND (s.hold_status = 'available' OR s.hold_status IS NULL)
                            AND s.quantity > 0 AND s.location NOT IN ('QUA_SHELL','STAGING')
                          ORDER BY (s.expiry_date IS NULL) ASC, s.expiry_date ASC, s.id ASC
                          LIMIT 1), p.default_location) AS expected_location
         FROM products p
         WHERE p.product_code = ? AND p.is_active = 1
         LIMIT 1`,
        [code],
      );
      if (!row) jsonOut({ found: false, code });
      jsonOut({
        found: true,
        code,
        product: {
          id: Number(row.id),
          product_code: row.product_code,
          product_name: row.product_name,
          uom_type: row.uom_type,
          uom_per_pallet: Number(row.uom_per_pallet),
          default_location: row.default_location ?? null,
        },
        expected_location: row.expected_location ?? null,
      });
    }

    case 'scan_override': {
      apiRequireWrite();
      const data = body();
      const code = String(data.code ?? '').trim();
      const reason = String(data.reason ?? '').trim();
      const context = String(data.context ?? '').trim();
      if (!code) jsonErr('Kode wajib diisi.');
      if (!reason) jsonErr('Alasan override wajib diisi.');
      await activityLog('SCAN_OVERRIDE', 'stock', 'Stock', null, null,
        `Scan mismatch di-override${context ? ` [${context}]` : ''}: '${code}' — ${reason}`,
        { scanned: code, context: context || null },
        { reason });
      jsonOut({ ok: true });
    }

    default:
      jsonErr('Invalid action: ' + action, 404);
  }
}

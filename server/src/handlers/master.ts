import bcrypt from 'bcryptjs';
import { activityLog } from '../activityLog';
import { dbExec, dbExecFirst, dbScalar } from '../db';
import {
  apiRequireAdmin,
  apiRequireAuth,
  apiRequireWrite,
  body,
  customersOptions,
  jsonErr,
  jsonOut,
  locationOptions,
  productsOptions,
  query,
} from '../helpers';
import { Customer } from '../services/Customer';
import { LocationManager } from '../services/LocationManager';
import { Product } from '../services/Product';

/** Port of api/handlers/master.php handle_products */
export async function handleProducts(action: string): Promise<void> {
  switch (action) {
    case 'list': {
      apiRequireAuth();
      const search = String(query('search') ?? '').trim();
      const perPage = Number(query('per_page', 25));
      const page = Math.max(1, Number(query('page', 1)));
      const offset = (page - 1) * perPage;
      const total = await Product.getCount(search);
      const totalAll = search ? await Product.getCount() : total;
      const rows = await Product.getPaginated(search, perPage, offset);
      for (const r of rows) r.id = Number(r.id);
      jsonOut({
        rows,
        total: Number(total),
        total_all: Number(totalAll),
        page,
        per_page: perPage,
        uom_stats: await Product.getStatsByUom(),
      });
    }

    case 'all':
      apiRequireAuth();
      jsonOut({ rows: await productsOptions() });

    case 'detail': {
      apiRequireAuth();
      const id = Number(query('id'));
      jsonOut({ product: await Product.getById(id) });
    }

    case 'create': {
      apiRequireWrite();
      const data = body();
      await Product.create(data);
      await activityLog('CREATE_PRODUCT', 'stock', 'Product', null,
        data.product_code ?? null, 'Buat produk: ' + (data.product_name ?? '—'));
      jsonOut({ ok: true });
    }

    case 'update': {
      apiRequireWrite();
      const data = body();
      const id = Number(data.id ?? 0);
      await Product.update(id, data);
      await activityLog('UPDATE_PRODUCT', 'stock', 'Product', id,
        data.product_code ?? null, 'Edit produk: ' + (data.product_name ?? '—'));
      jsonOut({ id });
    }

    case 'delete': {
      apiRequireAdmin();
      const data = body();
      const pid = Number(data.id ?? query('id'));
      const pRow = await dbExecFirst('SELECT product_code, product_name FROM products WHERE id=?', [pid]);
      await dbExec('DELETE sl FROM stock_locations sl JOIN stock s ON sl.stock_id = s.id WHERE s.product_id=?', [pid]);
      await dbExec('DELETE FROM stock_locations WHERE inbound_item_id IN (SELECT id FROM inbound_items WHERE product_id=?)', [pid]);
      await dbExec('DELETE FROM stock_ledger WHERE product_id=?', [pid]);
      await dbExec('DELETE FROM stock WHERE product_id=?', [pid]);
      await dbExec('DELETE FROM inbound_items WHERE product_id=?', [pid]);
      await dbExec('DELETE FROM outbound_items WHERE product_id=?', [pid]);
      await dbExec('DELETE FROM stock_take_items WHERE product_id=?', [pid]);
      await Product.delete(pid);
      await activityLog('DELETE_PRODUCT', 'stock', 'Product', pid,
        pRow?.product_code ?? null, 'Hapus produk: ' + (pRow?.product_name ?? pid));
      jsonOut({ id: pid });
    }

    default:
      jsonErr('Invalid action: ' + action, 404);
  }
}

/** Port of api/handlers/master.php handle_customers */
export async function handleCustomers(action: string): Promise<void> {
  switch (action) {
    case 'list': {
      apiRequireAuth();
      const search = String(query('search') ?? '').trim();
      const perPage = Number(query('per_page', 25));
      const page = Math.max(1, Number(query('page', 1)));
      const offset = (page - 1) * perPage;
      const total = await Customer.getCount(search);
      const rows = await Customer.getPaginated(search, perPage, offset);
      jsonOut({
        rows,
        total: Number(total),
        page,
        per_page: perPage,
        type_stats: await Customer.getTypeStats(),
      });
    }

    case 'all':
      apiRequireAuth();
      jsonOut({ rows: await customersOptions() });

    case 'detail': {
      apiRequireAuth();
      const id = Number(query('id'));
      jsonOut({ customer: await Customer.getById(id) });
    }

    case 'create': {
      apiRequireWrite();
      const data = body();
      await Customer.create(data);
      await activityLog('CREATE_CUSTOMER', 'customer', 'Customer', null,
        data.customer_code ?? null, 'Buat customer: ' + (data.customer_name ?? '—'));
      jsonOut({ ok: true });
    }

    case 'update': {
      apiRequireWrite();
      const data = body();
      const id = Number(data.id ?? 0);
      await Customer.update(id, data);
      await activityLog('UPDATE_CUSTOMER', 'customer', 'Customer', id,
        data.customer_code ?? null, 'Edit customer: ' + (data.customer_name ?? '—'));
      jsonOut({ id });
    }

    case 'delete': {
      apiRequireWrite();
      const data = body();
      const id = Number(data.id ?? query('id'));
      await Customer.delete(id);
      await activityLog('DELETE_CUSTOMER', 'customer', 'Customer', id, null, 'Hapus customer ID ' + id);
      jsonOut({ id });
    }

    default:
      jsonErr('Invalid action: ' + action, 404);
  }
}

/** Port of api/handlers/master.php handle_locations */
export async function handleLocations(action: string): Promise<void> {
  switch (action) {
    case 'list': {
      apiRequireAuth();
      const zone = query('zone') || null;
      const availableOnly = query('available_only') === '1' || query('available_only') === 'true';
      const rows = await LocationManager.getAll(zone, availableOnly);
      jsonOut({ rows, zones: await locationZoneSummary() });
    }

    case 'all':
      apiRequireAuth();
      jsonOut({ rows: await locationOptions() });

    case 'check': {
      apiRequireAuth();
      const code = String(query('code') ?? '').trim().toUpperCase();
      const info = await LocationManager.getLocationInfo(code);
      jsonOut({ available: await LocationManager.isAvailable(code), info });
    }

    case 'available': {
      apiRequireAuth();
      const count = Number(query('count', 20));
      const zone = query('zone') || null;
      jsonOut({ rows: await LocationManager.getAvailableLocations(count, zone) });
    }

    case 'zone_summary':
      apiRequireAuth();
      jsonOut({ rows: await LocationManager.getZoneSummary() });

    case 'suggest': {
      apiRequireAuth();
      const qty = Number(query('quantity', 0));
      const uom = query('uom', 'Drum');
      const upp = Number(query('uom_per_pallet', 4));
      const zone = query('zone') || null;
      jsonOut({ rows: await LocationManager.suggestLocationsForInbound(qty, uom, upp, zone) });
    }

    case 'create': {
      apiRequireWrite();
      const data = body();
      const code = String(data.location_code ?? '').trim().toUpperCase();
      if (!code) jsonErr('location_code wajib diisi.');
      const exists = await dbExecFirst('SELECT id FROM location_master WHERE location_code=?', [code]);
      if (exists) jsonErr("Lokasi '" + code + "' sudah ada.", 409);
      const ins = await dbExec(
        `INSERT INTO location_master (location_code, aisle, rack, row_name, position, zone, is_active)
         VALUES (?, ?, ?, ?, ?, ?, ?)`,
        [
          code,
          data.aisle ?? null,
          data.rack ?? null,
          data.row_name ?? null,
          data.position ?? null,
          data.zone ?? null,
          data.is_active != null ? Number(data.is_active) : 1,
        ],
      );
      await activityLog('ADD_LOCATION', 'location', 'Location', null, code, 'Tambah lokasi ' + code);
      jsonOut({ id: Number((ins as any).insertId) });
    }

    case 'update': {
      apiRequireWrite();
      const data = body();
      const id = Number(data.id ?? 0);
      await dbExec(
        `UPDATE location_master SET location_code=?, aisle=?, rack=?, row_name=?, position=?, zone=?, is_active=? WHERE id=?`,
        [
          String(data.location_code ?? '').trim().toUpperCase(),
          data.aisle ?? null,
          data.rack ?? null,
          data.row_name ?? null,
          data.position ?? null,
          data.zone ?? null,
          data.is_active != null ? Number(data.is_active) : 1,
          id,
        ],
      );
      await activityLog('EDIT_LOCATION', 'location', 'Location', id, null, 'Edit lokasi ID ' + id);
      jsonOut({ id });
    }

    case 'delete': {
      apiRequireAdmin();
      const data = body();
      const id = Number(data.id ?? query('id'));
      const code = await dbScalar('SELECT location_code FROM location_master WHERE id=?', [id]);
      const inUse = Number(await dbScalar('SELECT COUNT(*) FROM stock WHERE location=? AND quantity>0', [code]) ?? 0);
      if (inUse > 0) {
        jsonErr("Lokasi '" + code + "' masih memiliki stok dan tidak dapat dihapus.", 409);
      }
      await dbExec('DELETE FROM location_master WHERE id=?', [id]);
      await activityLog('DELETE_LOCATION', 'location', 'Location', id, code, 'Hapus lokasi ' + code);
      jsonOut({ id });
    }

    default:
      jsonErr('Invalid action: ' + action, 404);
  }
}

/** Port of api/handlers/master.php location_zone_summary() */
async function locationZoneSummary(): Promise<any[]> {
  return dbExec(
    `SELECT zone, COUNT(*) as total, SUM(CASE WHEN is_active=1 THEN 1 ELSE 0 END) as active
     FROM location_master GROUP BY zone ORDER BY zone`,
  );
}

/** Port of api/handlers/master.php handle_users */
export async function handleUsers(action: string): Promise<void> {
  switch (action) {
    case 'list': {
      apiRequireAdmin();
      const rows = await dbExec('SELECT id, username, full_name, email, role, is_active, created_at, updated_at FROM users ORDER BY full_name');
      for (const r of rows) r.id = Number(r.id);
      jsonOut({
        rows,
        roles: [
          { key: 'admin', label: 'Admin' },
          { key: 'operator', label: 'Operator' },
          { key: 'viewer', label: 'Viewer' },
        ],
      });
    }

    case 'create': {
      apiRequireAdmin();
      const data = body();
      if (!String(data.password ?? '').trim()) jsonErr('Password is required.');
      if (!String(data.username ?? '').trim() || !String(data.full_name ?? '').trim()) jsonErr('Username dan full name wajib diisi.');
      const ins = await dbExec(
        `INSERT INTO users (username, password, full_name, email, role, is_active) VALUES (?, ?, ?, ?, ?, ?)`,
        [
          String(data.username).trim(),
          bcrypt.hashSync(String(data.password), 10),
          String(data.full_name).trim(),
          String(data.email ?? '').trim(),
          data.role ?? 'viewer',
          data.is_active != null ? Number(data.is_active) : 1,
        ],
      );
      const newId = Number((ins as any).insertId);
      await activityLog('CREATE_USER', 'user', 'User', newId, String(data.username).trim(),
        'Buat user baru: ' + String(data.full_name).trim() + ' (' + (data.role ?? 'viewer') + ')');
      jsonOut({ id: newId });
    }

    case 'update': {
      apiRequireAdmin();
      const data = body();
      const id = Number(data.id ?? 0);
      let sql = 'UPDATE users SET username = ?, full_name = ?, email = ?, role = ?, is_active = ?';
      const params: any[] = [
        String(data.username ?? '').trim(),
        String(data.full_name ?? '').trim(),
        String(data.email ?? '').trim(),
        data.role ?? 'viewer',
        data.is_active != null ? Number(data.is_active) : 1,
      ];
      if (data.password) {
        sql += ', password = ?';
        params.push(bcrypt.hashSync(String(data.password), 10));
      }
      sql += ' WHERE id = ?';
      params.push(id);
      await dbExec(sql, params);
      await activityLog('UPDATE_USER', 'user', 'User', id, String(data.username ?? '').trim(),
        'Edit user: ' + String(data.full_name ?? '').trim() + ' → role ' + (data.role ?? 'viewer') + (data.password ? ', password diubah' : ''));
      jsonOut({ id });
    }

    case 'delete': {
      apiRequireAdmin();
      const data = body();
      const userId = Number(data.id ?? query('id'));
      const me = apiRequireAdmin();
      if (userId === Number(me.id)) jsonErr('Anda tidak dapat menghapus akun sendiri.', 409);
      const tables: Record<string, string[]> = {
        inbound_orders: ['created_by', 'received_by'],
        outbound_orders: ['created_by', 'shipped_by'],
        picklists: ['created_by'],
      };
      for (const tbl of Object.keys(tables)) {
        for (const col of tables[tbl]) {
          try {
            await dbExec(`UPDATE \`${tbl}\` SET \`${col}\` = NULL WHERE \`${col}\` = ?`, [userId]);
          } catch (e) {}
        }
      }
      await dbExec('DELETE FROM auth_tokens WHERE user_id=?', [userId]);
      await dbExec('DELETE FROM users WHERE id=?', [userId]);
      await activityLog('DELETE_USER', 'user', 'User', userId, null, 'Hapus user ID ' + userId);
      jsonOut({ id: userId });
    }

    default:
      jsonErr('Invalid action: ' + action, 404);
  }
}

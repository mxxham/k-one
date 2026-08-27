import { dbExec } from '../db';
import { actionLabel, moduleIcon } from '../activityLog';
import { apiRequireAuth, jsonErr, jsonOut, query } from '../helpers';
import { Report } from '../services/Report';
import { Inbound } from '../services/Inbound';
import { Product } from '../services/Product';
import { Stock } from '../services/Stock';
import { Outbound } from '../services/Outbound';

/** Port of api/handlers/reports.php handle_report */
export async function handleReport(action: string): Promise<void> {
  switch (action) {
    case 'daily':
      apiRequireAuth();
      jsonOut({ report: await Report.getDailyReport(query('date') || null, query('date_to') || null) });

    case 'products':
      apiRequireAuth();
      jsonOut({ rows: await Product.getAll() });

    case 'inbound': {
      apiRequireAuth();
      const start = query('start_date') || null;
      const end = query('end_date') || null;
      const status = query('status') || null;
      let rows = await Inbound.getAll(status, 2000, 0, null);
      if (start) rows = rows.filter((r: any) => (r.order_date ?? '') >= start);
      if (end) rows = rows.filter((r: any) => (r.order_date ?? '') <= end);
      jsonOut({ rows });
    }

    case 'outbound': {
      apiRequireAuth();
      const start = query('start_date') || null;
      const end = query('end_date') || null;
      const status = query('status') || null;
      let rows = await Outbound.getAll(status, 2000, 0, null);
      if (start) rows = rows.filter((r: any) => (r.order_date ?? '') >= start);
      if (end) rows = rows.filter((r: any) => (r.order_date ?? '') <= end);
      jsonOut({ rows });
    }

    case 'stock':
      apiRequireAuth();
      jsonOut({ rows: await Stock.getAll() });

    case 'ledger': {
      apiRequireAuth();
      const start = query('start_date') || null;
      const end = query('end_date') || null;
      jsonOut({ rows: await Stock.getMovement(null, start, end, 5000) });
    }

    default:
      jsonErr('Invalid action: ' + action, 404);
  }
}

/** Port of api/handlers/reports.php handle_activitylog */
export async function handleActivityLog(action: string): Promise<void> {
  switch (action) {
    case 'list': {
      apiRequireAuth();
      const module = query('module') || null;
      const limit = Number(query('limit', 200)) || 0;
      let sql = 'SELECT al.* FROM activity_log al WHERE 1=1';
      const params: any[] = [];
      if (module) {
        sql += ' AND al.module = ?';
        params.push(module);
      }
      sql += ' ORDER BY al.id DESC LIMIT ' + limit;
      const rows = await dbExec(sql, params);
      for (const r of rows) {
        r.id = Number(r.id);
        r.action_label = actionLabel(r.action);
        r.module_icon = moduleIcon(r.module);
      }
      jsonOut({ rows });
    }

    case 'modules':
      apiRequireAuth();
      jsonOut({
        rows: (await dbExec('SELECT DISTINCT module FROM activity_log ORDER BY module')).map((r: any) => r[Object.keys(r)[0]]),
      });

    default:
      jsonErr('Invalid action: ' + action, 404);
  }
}

import { jsonErr, jsonOut, apiRequireAdmin } from '../helpers';
import { db, dbScalar, withTransaction } from '../db';
import { activityLog } from '../activityLog';

/** Port of api/handlers/system.php */
export async function handleSystem(action: string): Promise<void> {
  switch (action) {
    case 'reset_operational_data': {
      apiRequireAdmin();
      const tables = [
        'activity_log',
        'stock_ledger',
        'outbound_item_locations',
        'picklist_items',
        'picklists',
        'location_allocations',
        'stock_take_items',
        'stock_take',
        'bin_transfers',
        'outbound_destinations',
        'outbound_items',
        'outbound_orders',
        'inbound_items',
        'inbound_orders',
        'stock_locations',
        'stock',
      ];
      try {
        await withTransaction(async () => {
          await db().query('SET FOREIGN_KEY_CHECKS = 0');
          for (const tbl of tables) {
            const cnt = await dbScalar(
              'SELECT COUNT(*) AS cnt FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
              [tbl],
            );
            if (Number(cnt ?? 0) > 0) {
              await db().query('TRUNCATE TABLE `' + tbl + '`');
            }
          }
          await db().query('SET FOREIGN_KEY_CHECKS = 1');
        });
      } catch (e: any) {
        try {
          await db().query('SET FOREIGN_KEY_CHECKS = 1');
        } catch {}
        jsonErr('Reset gagal: ' + (e?.message ?? e), 500);
      }
      await activityLog('RESET_OPERATIONAL_DATA', 'system', 'System', 0, null, 'Reset semua data operasional');
      jsonOut({ message: 'Reset berhasil. Semua data transaksi/log telah dibersihkan. Master data tetap aman.' });
    }

    default:
      jsonErr('Invalid action: ' + action, 404);
  }
}

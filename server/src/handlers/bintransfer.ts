import { body, jsonErr, jsonOut, pageParams, query, apiRequireAuth, apiRequireWrite, statusesFor } from '../helpers';
import { activityLog } from '../activityLog';
import { BinTransfer } from '../services/BinTransfer';

export async function handleBinTransfer(action: string): Promise<void> {
  switch (action) {
    case 'list': {
      apiRequireAuth();
      const [page, perPage, offset] = pageParams(200);
      const status = query('status') || null;
      const total = await BinTransfer.countAll(status);
      let rows = await BinTransfer.getAll(status, perPage, offset);
      rows = rows.map((r: any) => ({ ...r, id: Number(r.id) }));
      jsonOut({
        rows,
        total: Number(total),
        page,
        per_page: perPage,
        statuses: statusesFor('bintransfer'),
      });
    }

    case 'detail': {
      apiRequireAuth();
      const id = Number(query('id')) || 0;
      const transfer = await BinTransfer.getById(id);
      if (!transfer) jsonErr('Transfer tidak ditemukan', 404);
      jsonOut({ transfer });
    }

    case 'locations_with_stock': {
      apiRequireAuth();
      const pid = Number(query('product_id')) || 0;
      jsonOut({ rows: await BinTransfer.getLocationsWithStock(pid) });
    }

    case 'stock_at_location': {
      apiRequireAuth();
      const pid = Number(query('product_id')) || 0;
      const loc = query('location') || '';
      jsonOut({ rows: await BinTransfer.getStockAtLocation(pid, loc) });
    }

    case 'create': {
      const data = body();
      apiRequireWrite();
      let id: number;
      try {
        id = await BinTransfer.create(data);
      } catch (e) {
        jsonErr((e as Error).message, 409);
      }
      await activityLog('CREATE_BIN_TRANSFER', 'bin_transfer', 'BinTransfer', Number(id), null,
        'Buat transfer ' + (data.from_location ?? '') + ' → ' + (data.to_location ?? '') + ' qty ' + (data.quantity ?? 0));
      jsonOut({ id: Number(id) });
    }

    case 'execute': {
      const data = body();
      apiRequireWrite();
      const id = Number(data.id ?? query('id'));
      try {
        await BinTransfer.execute(id);
      } catch (e) {
        jsonErr((e as Error).message, 409);
      }
      await activityLog('EXECUTE_BIN_TRANSFER', 'bin_transfer', 'BinTransfer', id, null, 'Eksekusi transfer ID ' + id);
      jsonOut({ id });
    }

    case 'cancel': {
      const data = body();
      apiRequireWrite();
      const id = Number(data.id ?? query('id'));
      try {
        await BinTransfer.cancel(id);
      } catch (e) {
        jsonErr((e as Error).message, 409);
      }
      await activityLog('CANCEL_BIN_TRANSFER', 'bin_transfer', 'BinTransfer', id, null, 'Batalkan transfer ID ' + id);
      jsonOut({ id });
    }

    default:
      jsonErr('Invalid action: ' + action, 404);
  }
}

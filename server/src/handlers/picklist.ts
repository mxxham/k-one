import { body, jsonErr, jsonOut, pageParams, query, apiRequireAuth, apiRequireWrite, statusesFor } from '../helpers';
import { activityLog } from '../activityLog';
import { Picklist } from '../services/Picklist';

export async function handlePicklist(action: string): Promise<void> {
  switch (action) {
    case 'list': {
      apiRequireAuth();
      const [page, perPage, offset] = pageParams(50);
      const status = query('status') || null;
      const total = await Picklist.countAll(status);
      let rows = await Picklist.getAll(status, perPage, offset);
      rows = rows.map((r: any) => ({ ...r, id: Number(r.id) }));
      jsonOut({
        rows,
        total: Number(total),
        page,
        per_page: perPage,
        statuses: statusesFor('picklist'),
      });
    }

    case 'detail': {
      apiRequireAuth();
      const id = Number(query('id')) || 0;
      const picklist = await Picklist.getById(id);
      if (!picklist) jsonErr('Picklist tidak ditemukan', 404);
      const items = await Picklist.getItems(id);
      items.forEach((it: any) => { it.id = Number(it.id); });
      jsonOut({ picklist, items });
    }

    case 'stats':
      apiRequireAuth();
      jsonOut({ stats: await Picklist.getStats() });

    case 'create_from_outbound': {
      const data = body();
      apiRequireWrite();
      const outboundId = Number(data.outbound_id ?? query('outbound_id'));
      if (!outboundId) jsonErr('outbound_id wajib diisi.');
      const id = await Picklist.createFromOutbound(outboundId);
      await activityLog('CREATE_PICKLIST', 'picklist', 'Picklist', Number(id), null,
        'Buat picklist dari outbound ID ' + outboundId);
      jsonOut({ id: Number(id) });
    }

    case 'confirm': {
      const data = body();
      apiRequireWrite();
      const id = Number(data.id ?? query('id'));
      await Picklist.confirm(id);
      await activityLog('CONFIRM_PICKLIST', 'picklist', 'Picklist', id, null, 'Konfirmasi picklist ID ' + id);
      jsonOut({ id });
    }

    case 'complete': {
      const data = body();
      apiRequireWrite();
      const id = Number(data.id ?? query('id'));
      await Picklist.complete(id);
      await activityLog('COMPLETE_PICKLIST', 'picklist', 'Picklist', id, null, 'Selesaikan picklist ID ' + id);
      jsonOut({ id });
    }

    case 'delete': {
      const data = body();
      apiRequireWrite();
      const id = Number(data.id ?? query('id'));
      await Picklist.delete(id);
      await activityLog('DELETE_PICKLIST', 'picklist', 'Picklist', id, null, 'Hapus picklist ID ' + id);
      jsonOut({ id });
    }

    case 'update_item': {
      const data = body();
      apiRequireWrite();
      const itemId = Number(data.item_id ?? 0);
      await Picklist.updateItem(itemId, data);
      jsonOut({ item_id: itemId });
    }

    case 'export_data': {
      apiRequireAuth();
      const id = Number(query('id')) || 0;
      const data = await Picklist.exportForPrint(id);
      jsonOut({ data });
    }

    default:
      jsonErr('Invalid action: ' + action, 404);
  }
}

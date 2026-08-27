import { apiRequireWrite, jsonErr, jsonOut, JsonOutSent } from '../helpers';
import { genInboundTemplate, genOutboundTemplate, genStockTemplate } from './importTemplates';
import { runImportInbound } from './importInbound';
import { runImportOutbound } from './importOutbound';
import { stockCommitAction, stockPreview } from './importStock';
import { runImportAuto } from './importAuto';

/**
 * Port of api/handlers/import.php — dispatches the Excel import actions.
 * Actions: tpl_inbound / tpl_outbound / tpl_stock / inbound / outbound /
 * stock_preview / stock_commit / auto.
 */
export async function handleImport(action: string): Promise<void> {
  try {
    switch (action) {
      case 'tpl_inbound':
        await genInboundTemplate();
        return;
      case 'tpl_outbound':
        await genOutboundTemplate();
        return;
      case 'tpl_stock':
        await genStockTemplate();
        return;
      case 'inbound':
        apiRequireWrite();
        await runImportInbound();
        return;
      case 'outbound': {
        apiRequireWrite();
        const result = await runImportOutbound();
        jsonOut(result);
        return;
      }
      case 'stock_preview':
        apiRequireWrite();
        await stockPreview();
        return;
      case 'stock_commit':
        apiRequireWrite();
        await stockCommitAction();
        return;
      case 'auto':
        apiRequireWrite();
        await runImportAuto();
        return;
      default:
        jsonErr('Invalid action: ' + action, 404);
    }
  } catch (e: any) {
    if (e instanceof JsonOutSent) throw e;
    jsonErr(e?.message ?? 'Import error', 400);
  }
}

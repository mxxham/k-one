import { dbExec, dbExecFirst, withTransaction } from '../db';
import { ctx, jsonOut } from '../helpers';
import { detectHeader, headerIndex, isMetaRow, normalizeUom, parseImportDate, readSheet, resolveCol, toFloat, uomPerPallet } from '../importHelpers';
import { Inbound } from '../services/Inbound';

/**
 * Port of api/handlers/import_inbound.php — action `import/inbound`.
 */

export async function runImportInbound(): Promise<void> {
  const carrierName = String(ctx().body?.carrier_name ?? '').trim() || null;
  const allRows = await readSheet();
  if (!allRows.length) throw new Error('File kosong atau tidak bisa dibaca');

  const detect = detectHeader(allRows);
  const headerIdx = detect.index;
  const rawHeaders = detect.row.map((h) => String(h ?? '').trim());
  const col = headerIndex(rawHeaders);

  const itemCol = resolveCol(rawHeaders, ['item code', 'item', 'material no', 'material', 'sku', 'product code', 'product']);
  const qtyCol = resolveCol(rawHeaders, ['actual qty', 'actual_qty', 'received qty', 'quantity', 'qty', 'on hand', 'remain qty']);
  const qtyOrderCol = resolveCol(rawHeaders, ['qty order', 'order qty', 'quantity ordered', 'so qty']);
  const uomCol = resolveCol(rawHeaders, ['uom', 'unit', 'sales unit', 'unit of measure']);
  if (itemCol === null) throw new Error('Invalid format. Kolom item (Item Code / Material / SKU / Item) tidak ditemukan.');
  if (qtyCol === null) throw new Error('Invalid format. Kolom qty (Actual Qty / Quantity / Qty / on hand) tidak ditemukan.');

  let successCount = 0;
  const errorMessages: string[] = [];
  const orderCache: Record<string, string> = {};

  await withTransaction(async () => {
    let rowNumber = headerIdx;
    for (const data of allRows) {
      rowNumber++;
      if (rowNumber <= headerIdx + 1) continue;
      if (data.every((v) => v === null || v === undefined || v === '')) continue;
      if (isMetaRow(data)) continue;

      try {
        const getCol = (ci: number): string => {
          const v = data[ci];
          return v === null || v === undefined ? '' : String(v).trim();
        };
        const get = (key: string, aliases: string[]): string => {
          if (col[key] !== undefined) {
            const v = String(data[col[key]] ?? '').trim();
            if (v !== '' && v !== '0') return v;
          }
          for (const a of aliases) {
            if (col[a] !== undefined) {
              const v = String(data[col[a]] ?? '').trim();
              if (v !== '' && v !== '0') return v;
            }
          }
          return '';
        };

        const odNumber = get('od no', ['od number', 'od_number', 'outbound delivery']);
        const soNumber = get('so no', ['so number', 'so_no', 'sales order']);
        const poNumber = get('gr number', ['po no', 'po number', 'po_no', 'purchase order']);
        const shipmentNo = get('shipment no', ['shipment number', 'shipment_no', 'shipment']);
        const inboundOrderNo = get('inbound order no', ['inbound order number', 'inbound_order_no', 'io number']);

        const itemCode = getCol(itemCol);
        const skuCol = resolveCol(rawHeaders, ['sku', 'sku code', 'sku_number']);
        const skuCode = skuCol !== null ? getCol(skuCol) : '';

        const actualQty = toFloat(getCol(qtyCol));
        const qtyOrder = qtyOrderCol !== null ? toFloat(getCol(qtyOrderCol)) : actualQty;
        const uom = normalizeUom(getCol(uomCol) || 'Drum');
        const palletCol = resolveCol(rawHeaders, ['pallet', 'pallet qty']);
        const pallet = palletCol !== null ? toFloat(getCol(palletCol)) : 0;

        const batchNo = get('batch no', ['batch number', 'batch', 'lot', 'no batch']);
        const grDate = get('gr date', ['goods receipt date', 'receipt date']);
        const manufactureDate = get('manufacture date', ['mfg date', 'production date', 'tgl produksi', 'manufacturing date']) || grDate;
        const expDate = get('exp date', ['expiry date', 'expired date', 'expiration date', 'best before', 'tgl exp', 'expiry']);
        const location = get('location', ['lokasi', 'bin']);
        const remarks = get('remarks', ['notes', 'keterangan', 'description']);

        if (itemCode === '') continue;
        if (actualQty <= 0) {
          errorMessages.push(`Row ${rowNumber}: Dilewati — qty kosong atau 0`);
          continue;
        }

        let product = await dbExecFirst(
          'SELECT id, product_name, uom_type, uom_per_pallet, max_sku_qty, max_trans_qty, liters_per_unit FROM products WHERE product_code = ?',
          [itemCode],
        );
        let autoCreatedProduct = false;
        if (!product && skuCode !== '') {
          const skuProd = await dbExecFirst(
            'SELECT id, product_name, uom_type, uom_per_pallet, max_sku_qty, max_trans_qty, liters_per_unit FROM products WHERE product_code = ?',
            [skuCode],
          );
          if (skuProd) {
            product = skuProd;
            errorMessages.push(`Row ${rowNumber}: Item '${itemCode}' cocok lewat SKU '${skuCode}'`);
          }
        }
        if (!product) {
          const newCode = skuCode !== '' ? skuCode : itemCode;
          const newName = remarks !== '' ? remarks : itemCode;
          const prodIns = await dbExec(
            `INSERT INTO products (product_code, product_name, description, uom_type, uom_per_pallet, liters_per_unit, max_sku_qty, max_trans_qty, reorder_level, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, 1)`,
            [newCode, newName, newName, uom, uomPerPallet(uom, 4), 209.0, 44, 80],
          );
          product = {
            id: Number((prodIns as any).insertId),
            product_name: newName,
            uom_type: uom,
            uom_per_pallet: uomPerPallet(uom, 4),
          };
          autoCreatedProduct = true;
          errorMessages.push(`Row ${rowNumber}: Product '${newCode}' dibuat otomatis`);
        }

        let orderNumber: string;
        if (shipmentNo !== '') orderNumber = shipmentNo;
        else if (inboundOrderNo !== '') orderNumber = inboundOrderNo;
        else if (grDate !== '') {
          if (!orderCache[grDate]) orderCache[grDate] = await Inbound.generateNumber();
          orderNumber = orderCache[grDate];
        } else {
          orderNumber = await Inbound.generateNumber();
        }

        const existing = await dbExecFirst(
          'SELECT id FROM inbound_orders WHERE order_number = ? OR (shipment_no IS NOT NULL AND shipment_no <> \'\' AND shipment_no = ?) LIMIT 1',
          [orderNumber, shipmentNo !== '' ? shipmentNo : orderNumber],
        );

        let inboundId: number;
        if (!existing) {
          const shipVal = shipmentNo !== '' ? shipmentNo : null;
          const ins = await dbExec(
            `INSERT INTO inbound_orders (order_number, order_date, carrier_name, status, shipment_no, notes, created_by)
             VALUES (?, ?, ?, 'Dues In', ?, 'Imported from Excel', ?)`,
            [orderNumber, grDate !== '' ? grDate : todayStr(), carrierName, shipVal, ctx().user?.id ?? null],
          );
          inboundId = Number((ins as any).insertId);
        } else {
          inboundId = Number(existing.id);
        }

        const uomPerPalletVal = uomPerPallet(uom, Number(product.uom_per_pallet ?? 4));
        const calculatedPallet = actualQty > 0 ? Math.ceil(actualQty / uomPerPalletVal) : 0;
        const finalPallet = pallet > 0 ? Math.floor(pallet) : calculatedPallet;

        if (pallet > 0 && Math.abs(pallet - calculatedPallet) > 1) {
          errorMessages.push(`Row ${rowNumber}: Pallet mismatch (calc: ${calculatedPallet}, given: ${pallet})`);
        }

        let parsedMfgDate = parseImportDate(manufactureDate);
        let parsedExpDate = parseImportDate(expDate);
        if (!parsedExpDate && parsedMfgDate) {
          const d = new Date(parsedMfgDate + 'T00:00:00');
          d.setFullYear(d.getFullYear() + 4);
          parsedExpDate = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
        }

        const hasInProcessCol = await dbExecFirst("SHOW COLUMNS FROM inbound_items LIKE 'in_process_status'");

        if (hasInProcessCol) {
          await dbExec(
            `INSERT INTO inbound_items (inbound_order_id, product_id, od_number, so_number, batch_number, location, quantity, uom, actual_qty, pallet, manufacture_date, exp_date, stock_status, in_process_status, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending', 'Dues In', ?)`,
            [
              inboundId,
              product.id,
              odNumber || null,
              soNumber || null,
              batchNo || null,
              location || null,
              qtyOrder > 0 ? qtyOrder : actualQty,
              uom,
              actualQty,
              finalPallet,
              parsedMfgDate,
              parsedExpDate,
              remarks || null,
            ],
          );
        } else {
          await dbExec(
            `INSERT INTO inbound_items (inbound_order_id, product_id, od_number, so_number, batch_number, location, quantity, uom, actual_qty, pallet, manufacture_date, exp_date, stock_status, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending', ?)`,
            [
              inboundId,
              product.id,
              odNumber || null,
              soNumber || null,
              batchNo || null,
              location || null,
              qtyOrder > 0 ? qtyOrder : actualQty,
              uom,
              actualQty,
              finalPallet,
              parsedMfgDate,
              parsedExpDate,
              remarks || null,
            ],
          );
        }

        successCount++;
      } catch (e) {
        errorMessages.push((e as Error).message);
      }
    }
  });

  jsonOut({
    success: true,
    message: `Import completed! ${successCount} items imported successfully.`,
    processed: successCount,
    stats: { items_imported: successCount, rows_skipped: errorMessages.length, errors: errorMessages.length },
    errors: errorMessages,
    has_errors: errorMessages.length > 0,
  });
}

function todayStr(): string {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

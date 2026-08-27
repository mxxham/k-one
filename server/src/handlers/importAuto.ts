import { dbExec, dbExecFirst, withTransactionOwned } from '../db';
import { ctx, jsonOut } from '../helpers';
import { detectHeader, normalizeUom, readAllSheets, resolveCol, toFloat, uomPerPallet } from '../importHelpers';
import { Inbound } from '../services/Inbound';
import { stockParse, stockValidate, stockCommit } from './importStock';
import { processOutboundSheet } from './importOutbound';

/**
 * Port of api/handlers/import_auto.php — action `import/auto`.
 * Multi-sheet workbook: master data → products, WMS → stock + inbound,
 * data putaway → stock, schedule → outbound, everything else → skipped.
 */

function classifySheet(name: string): string {
  const n = String(name ?? '').toLowerCase();
  if (n.includes('master')) return 'master';
  if (n.includes('wms')) return 'wms';
  if (n.includes('putaway')) return 'putaway';
  if (n.includes('schedule')) return 'schedule';
  return 'skip';
}

function inferUom(upp: number): string {
  if (upp <= 1) return 'Bags';
  if (upp <= 8) return 'Drum';
  if (upp <= 28) return 'Pail';
  return 'Carton';
}

/**
 * Read "Master SKU" sheet and build product_code → UOM lookup from TYPE column (col L).
 * Master SKU layout: col B = Material, col L (index 11) = TYPE (DRUM/CAR/PAIL/IBC/FLUID BAG).
 * Returns Map<product_code, normalized_uom_type>.
 */
function readMasterSkuUom(sheets: { name: string; rows: any[][] }[]): Map<string, string> {
  const map = new Map<string, string>();
  const uomTypeMap: Record<string, string> = {
    DRUM: 'Drum',
    CAR: 'CAR',
    CARTON: 'CAR',
    PAIL: 'Pail',
    IBC: 'IBC',
    'FLUID BAG': 'Fluidbag',
    FLUIDBAG: 'Fluidbag',
  };

  for (const sheet of sheets) {
    if (!sheet.name.toLowerCase().includes('master sku')) continue;
    const detect = detectHeader(sheet.rows);
    const headers = detect.row.map((h: any) => String(h ?? '').trim());

    let colMaterial = resolveCol(headers, ['material', 'item', 'sku', 'product code']);
    let colType = resolveCol(headers, ['type', 'material type', 'packaging type']);

    // Fallback: fixed column indices B=1, L=11
    if (colMaterial === null) colMaterial = 1;
    if (colType === null) colType = 11;

    for (let i = detect.index + 1; i < sheet.rows.length; i++) {
      const row = sheet.rows[i];
      const code = String(row[colMaterial] ?? '').trim();
      const type = String(row[colType] ?? '').trim().toUpperCase();
      if (code === '' || type === '') continue;

      map.set(code, uomTypeMap[type] ?? type.charAt(0).toUpperCase() + type.slice(1).toLowerCase());
    }
    break;
  }
  return map;
}

async function processMaster(allRows: any[][], log: string[]): Promise<{ created: number; updated: number }> {
  const detect = detectHeader(allRows);
  const headers = detect.row.map((h) => String(h ?? '').trim());

  const col = {
    code: resolveCol(headers, ['material', 'item', 'item code', 'product code', 'sku']),
    name: resolveCol(headers, ['material description', 'description', 'product name']),
    loc: resolveCol(headers, ['storage location', 'location', 'lokasi', 'bin']),
    upp: resolveCol(headers, ['upp', 'uom per pallet', 'units per pallet']),
    vol: resolveCol(headers, ['volume', 'vol', 'liters per unit']),
  };

  if (col.code === null) {
    log.push('Master data: kolom Material tidak ditemukan → sheet dilewati');
    return { created: 0, updated: 0 };
  }

  let created = 0;
  let updated = 0;
  for (let i = detect.index + 1; i < allRows.length; i++) {
    const row = allRows[i];
    const code = String(row[col.code] ?? '').trim();
    if (code === '') continue;

    const name = col.name !== null ? String(row[col.name] ?? '').trim() : '';
    const finalName = name !== '' ? name : code;
    const loc = col.loc !== null ? String(row[col.loc] ?? '').trim() : '';
    const upp = col.upp !== null ? Number(row[col.upp] ?? 0) : 0;
    const vol = col.vol !== null ? toFloat(row[col.vol]) : 0;
    const uomType = inferUom(upp);

    const existing = await dbExecFirst('SELECT id FROM products WHERE product_code = ? LIMIT 1', [code]);
    if (existing) {
      await dbExec(
        `UPDATE products SET product_name = ?, uom_type = ?, uom_per_pallet = ?, liters_per_unit = ?,
                default_location = COALESCE(?, default_location), description = ?, updated_at = NOW() WHERE id = ?`,
        [finalName, uomType, upp || 4, vol || 209, loc || null, finalName, Number(existing.id)],
      );
      updated++;
    } else {
      await dbExec(
        `INSERT INTO products (product_code, product_name, description, uom_type, uom_per_pallet, liters_per_unit, default_location, max_sku_qty, max_trans_qty, reorder_level, is_active)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 1)`,
        [code, finalName, finalName, uomType, upp || 4, vol || 209, loc || null, Math.max(1, upp || 4), Math.max(2, (upp || 4) * 2)],
      );
      created++;
    }
  }
  log.push(`Master data: ${created} produk baru, ${updated} diperbarui`);
  return { created, updated };
}

async function createInboundFromWms(rows: any[], log: string[]): Promise<{ orders: number; items: number }> {
  const groups: Record<string, any[]> = {};
  for (const row of rows) {
    if ((row._errors ?? []).length || !row.product_id) continue;
    const gr = row.manufacture_date || 'NO_GR_DATE';
    (groups[gr] = groups[gr] ?? []).push(row);
  }

  let orders = 0;
  let items = 0;
  for (const [gr, groupRows] of Object.entries(groups)) {
    const orderDate = gr === 'NO_GR_DATE' ? todayStr() : gr;
    const orderNumber = await Inbound.generateNumber();
    const ins = await dbExec(
      `INSERT INTO inbound_orders (order_number, order_date, carrier_name, status, notes, created_by)
       VALUES (?, ?, NULL, 'Dues In', ?, ?)`,
      [orderNumber, orderDate, 'Auto import (WMS) — GR: ' + gr, ctx().user?.id ?? null],
    );
    const inboundId = Number((ins as any).insertId);

    let i = 0;
    for (const row of groupRows) {
      const upp = row.uom_per_pallet ?? uomPerPallet(row.uom, 4);
      const pallet = Math.ceil(row.quantity / Math.max(1, upp));
      await dbExec(
        `INSERT INTO inbound_items (inbound_order_id, product_id, batch_number, location, quantity, uom, actual_qty, pallet, manufacture_date, exp_date, stock_status, in_process_status, notes)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending', 'Dues In', ?)`,
        [
          inboundId,
          row.product_id,
          row.batch_number || null,
          row.location || null,
          row.quantity,
          row.uom,
          row.quantity,
          pallet,
          row.manufacture_date || null,
          row.expiry_date || null,
          'Auto import',
        ],
      );
      i++;
    }
    orders++;
    items += i;
    log.push(`Inbound #${orderNumber} (GR: ${gr}) — ${i} item`);
  }
  return { orders, items };
}

export async function runImportAuto(): Promise<void> {
  const sheets = await readAllSheets();
  if (!sheets.length) throw new Error('File kosong atau tidak bisa dibaca');

  const report: Record<string, any> = {
    products_created: 0,
    products_updated: 0,
    stock_imported: 0,
    stock_skipped: 0,
    stock_auto_created: 0,
    inbound_orders: 0,
    inbound_items: 0,
    outbound_orders: 0,
    outbound_items: 0,
    outbound_skipped: 0,
    skipped_sheets: [],
  };
  const log: string[] = [];

  await withTransactionOwned(async () => {
    for (const sheet of sheets) {
      if (classifySheet(sheet.name) !== 'master') continue;
      const r = await processMaster(sheet.rows, log);
      report.products_created += r.created;
      report.products_updated += r.updated;
    }

    const masterSkuUom = readMasterSkuUom(sheets);
    if (masterSkuUom.size > 0) {
      log.push(`Master SKU: ${masterSkuUom.size} produk UOM lookup`);
    }

    // Correct product UOM from Master SKU after processMaster() overwrites with inferUom()
    if (masterSkuUom.size > 0) {
      let uomCorrected = 0;
      for (const [code, uom] of masterSkuUom) {
        const uppVal = uomPerPallet(uom, 4);
        const res = await dbExec(
          'UPDATE products SET uom_type = ?, uom_per_pallet = ? WHERE product_code = ? AND (uom_type != ? OR uom_per_pallet != ?)',
          [uom, uppVal, code, uom, uppVal]
        );
        uomCorrected += res.affectedRows;
      }
      if (uomCorrected > 0) {
        log.push(`UOM correction: ${uomCorrected} produk diperbarui dari Master SKU`);
      }
    }

    for (const sheet of sheets) {
      const type = classifySheet(sheet.name);
      if (type !== 'wms' && type !== 'putaway') continue;

      const rows = stockParse(sheet.rows);

      if (masterSkuUom.size > 0) {
        let uomFilled = 0;
        let uomUnknown = 0;
        for (const row of rows) {
          const code = row.sku_code || row.product_code;
          const curUom = (row.uom ?? '').trim();
          if (curUom === '' || curUom === 'Drum') {
            const lookup = masterSkuUom.get(code);
            if (lookup) {
              row.uom = lookup;
              uomFilled++;
            } else if (curUom === '') {
              row.uom = 'UNKNOWN';
              uomUnknown++;
            }
          }
        }
        if (uomFilled > 0 || uomUnknown > 0) {
          log.push(`UOM fill: ${uomFilled} dari Master SKU, ${uomUnknown} UNKNOWN`);
        }
      }

      if (type === 'wms') {
        for (const r of rows) r.stock_status = 'Available';
      }
      await stockValidate(rows);

      const commit = await stockCommit(rows, 'add');
      report.stock_imported += commit.imported;
      report.stock_skipped += commit.skipped;
      report.stock_auto_created += commit.auto_created;

      if (type === 'wms') {
        const inb = await createInboundFromWms(rows, log);
        report.inbound_orders += inb.orders;
        report.inbound_items += inb.items;
      }
      log.push(`Sheet '${sheet.name}' — stok ${commit.imported} diimport, ${commit.skipped} dilewati`);
    }

    for (const sheet of sheets) {
      if (classifySheet(sheet.name) !== 'schedule') continue;
      const result = await processOutboundSheet(sheet.rows, true, true);
      report.outbound_orders += result.stats.orders_created;
      report.outbound_items += result.stats.items_imported;
      report.outbound_skipped += result.stats.rows_skipped;
      log.push(`Sheet '${sheet.name}' — outbound ${result.stats.orders_created} orders, ${result.stats.items_imported} items`);
      log.push(...result.log);
    }

    for (const sheet of sheets) {
      if (classifySheet(sheet.name) === 'skip') {
        report.skipped_sheets.push(sheet.name);
      }
    }
  });

  jsonOut({
    success: true,
    message: 'Auto import selesai. Semua sheet diproses dalam satu aksi.',
    stats: report,
    log,
  });
}

function todayStr(): string {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

import { dbExec, dbExecFirst, withTransactionOwned } from '../db';
import { ctx } from '../helpers';
import { makeGetter, normalizeUom, parseImportDate, readSheet, toFloat, uomPerPallet } from '../importHelpers';
import { Outbound } from '../services/Outbound';

/**
 * Port of api/handlers/import_outbound.php — action `import/outbound`
 * plus the reusable sheet processor shared with auto-import.
 */

export interface OutboundImportResult {
  success: boolean;
  message: string;
  stats: Record<string, number>;
  log: string[];
}

export async function runImportOutbound(): Promise<OutboundImportResult> {
  const body = ctx().body ?? {};
  const skipUnknown = !!body.skip_unknown;
  const groupByShipment = !!body.group_by_shipment;

  const file = ctx().files?.[0];
  if (!file) throw new Error('File tidak diupload atau error upload.');
  const name: string = file.originalname ?? '';
  const ext = name.split('.').pop()?.toLowerCase() ?? '';
  if (!['xlsx', 'xls'].includes(ext)) throw new Error('Format file harus .xlsx atau .xls');

  const allRows = await readSheet();
  if (!allRows.length) throw new Error('File kosong atau tidak bisa dibaca');

  return processOutboundSheet(allRows, skipUnknown, groupByShipment);
}

/**
 * Reusable outbound processor: groups rows by shipment and creates outbound
 * orders + items with FEFO allocation. Respects an already-open transaction.
 */
export async function processOutboundSheet(allRows: any[][], skipUnknown: boolean, groupByShipment: boolean): Promise<OutboundImportResult> {
  let headerRowIndex: number | null = null;
  let headers: string[] = [];
  const headerKeywords = ['shipment', 'material', 'delivery quantity', 'ship-to', 'order no', 'plan date',
    'destination', 'location of the ship', 'name of ship', 'street'];
  for (let idx = 0; idx < allRows.length; idx++) {
    const row = allRows[idx];
    const rowStr = row.map((v) => String(v ?? '')).join(' ').toLowerCase();
    let matches = 0;
    for (const kw of headerKeywords) if (rowStr.includes(kw)) matches++;
    if (matches >= 2) {
      headerRowIndex = idx;
      headers = row.map((h) => String(h ?? '').trim().replace(/^\*\s*/, '').trim().toLowerCase());
      break;
    }
  }
  if (headerRowIndex === null) throw new Error('Header row tidak ditemukan. Pastikan file menggunakan format Planning Outbound.');

  const col: Record<string, number> = {};
  headers.forEach((h, i) => { if (h !== '') col[h] = i; });

  const productCache: Record<string, any> = {};
  const products = await dbExec('SELECT id, product_code, product_name, uom_type, uom_per_pallet FROM products WHERE is_active=1');
  for (const p of products) {
    productCache[String(p.product_code).toLowerCase()] = p;
    const m = /(\d{7,})/.exec(p.product_code);
    if (m) productCache[m[1]] = p;
  }

  const customerCache: Record<string, any> = {};
  const customers = await dbExec('SELECT id, customer_code, customer_name FROM customers');
  for (const c of customers) {
    customerCache[String(c.customer_code).trim().toLowerCase()] = c;
    customerCache[String(c.customer_name).trim().toLowerCase()] = c;
  }

  const shipmentData: Record<string, { rows: any[][]; plan_date: string | null; customer_id: number | null }> = {};
  const dataRows = allRows.slice(headerRowIndex + 1);
  dataRows.forEach((row, rIdx) => {
    if (row.every((v) => v === null || v === undefined || v === '')) return;
    const getAlt = makeGetter(col, row);
    let shipmentNum = getAlt('shipment number', 'shipment no', 'shipment');
    const materialRaw = getAlt('material');
    const deliveryQty = toFloat(getAlt('delivery quantity'));
    if (materialRaw === '' || deliveryQty <= 0) return;
    if (shipmentNum === '') shipmentNum = 'NO_SHIPMENT_' + (rIdx + 1);
    if (!shipmentData[shipmentNum]) {
      shipmentData[shipmentNum] = { rows: [], plan_date: null, customer_id: null };
    }
    const planDate = parseImportDate(getAlt('plan date', 'first delivery date', 'goods issue date'));
    if (planDate && !shipmentData[shipmentNum].plan_date) {
      shipmentData[shipmentNum].plan_date = planDate;
    }
    shipmentData[shipmentNum].rows.push(row);
  });

  const result: OutboundImportResult = {
    success: true,
    message: '',
    stats: { orders_created: 0, items_imported: 0, rows_skipped: 0, errors: 0 },
    log: [],
  };

  await withTransactionOwned(async () => {
    for (const [shipmentNum, sData] of Object.entries(shipmentData)) {
      const rows = sData.rows;
      const planDate = sData.plan_date ?? todayStr();

      const firstRow = rows[0];
      const getAlt = makeGetter(col, firstRow);
      const shipToName = getAlt('name of ship-to party', 'name of the ship-to party', 'ship-to party', 'destination');
      const shipToLoc = getAlt('location of ship-to party', 'location of the ship-to party', 'destination');
      let customerId: number | null = null;

      if (!customerId && shipToName !== '') {
        const code = 'OUT-' + String(shipToName).toUpperCase().replace(/[^A-Z0-9]/g, '').substring(0, 8);
        try {
          await dbExec(
            'INSERT INTO customers (customer_code, customer_name, city) VALUES (?,?,?) ON DUPLICATE KEY UPDATE customer_name=customer_name',
            [code, shipToName, shipToLoc || null],
          );
          const custRow = await dbExecFirst('SELECT id FROM customers WHERE customer_code = ?', [code]);
          customerId = custRow ? Number(custRow.id) : null;
          result.log.push(`Customer: ${shipToName}`);
        } catch (e) {
          /* ignore */
        }
      }
      if (!customerId) {
        const defCust = await dbExecFirst('SELECT id FROM customers LIMIT 1');
        customerId = defCust ? Number(defCust.id) : null;
        if (!customerId) throw new Error('Tidak ada customer di database. Tambahkan customer terlebih dahulu.');
      }

      const isNoShipment = shipmentNum.startsWith('NO_SHIPMENT');
      const outboundId = await Outbound.create({
        order_date: planDate,
        customer_id: customerId,
        shipment_number: isNoShipment ? null : shipmentNum,
        ship_to_name: shipToName || null,
        ship_to_location: shipToLoc || null,
        kota: shipToLoc || null,
        expected_date: planDate,
        status: 'Open',
        notes: 'Imported from Excel | Shipment: ' + (isNoShipment ? '—' : shipmentNum),
      });
      result.stats.orders_created++;
      result.log.push(`Order #${outboundId} — Shipment: ${shipmentNum} (${shipToName}, ${rows.length} items)`);

      const primaryDestKey = String(shipToName + '|' + shipToLoc).trim().toLowerCase();
      const destMap: Record<string, number> = {};
      let destSeq = 2;

      for (const row of rows) {
        const ga = makeGetter(col, row);
        const dName = ga('name of ship-to party', 'name of the ship-to party', 'ship-to party', 'destination');
        const dLoc = ga('location of ship-to party', 'location of the ship-to party', 'destination');
        const dStreet = ga('street / address', 'street', 'address');
        const dKey = String(dName + '|' + dLoc).trim().toLowerCase();
        if (dName === '' || dKey === primaryDestKey) continue;
        if (!destMap[dKey]) {
          try {
            await dbExec(
              'INSERT INTO outbound_destinations (outbound_id, seq, ship_to_name, ship_to_location, kota, street_address, notes) VALUES (?, ?, ?, ?, ?, ?, ?)',
              [outboundId, destSeq++, dName, dLoc, dLoc, dStreet || null, null],
            );
          } catch (e) {
            await dbExec(
              'INSERT INTO outbound_destinations (outbound_id, seq, ship_to_name, ship_to_location, kota, notes) VALUES (?, ?, ?, ?, ?, ?)',
              [outboundId, destSeq++, dName, dLoc, dLoc, null],
            );
          }
          const destRow = await dbExecFirst('SELECT LAST_INSERT_ID() as id');
          destMap[dKey] = Number(destRow.id);
        }
      }

      for (const row of rows) {
        const ga = makeGetter(col, row);
        const odNo = ga('order no (od)', 'order no', 'od no', 'od number');
        const soNo = ga('purchase order number', 'so no', 'so number');
        const destName = ga('name of ship-to party', 'name of the ship-to party', 'ship-to party', 'destination');
        const destLoc = ga('location of ship-to party', 'location of the ship-to party', 'destination');
        const materialRaw = ga('material');
        const description = ga('description');
        const deliveryQty = toFloat(ga('delivery quantity'));
        const salesUnit = ga('sales unit', 'uom', 'type');
        const expDate = parseImportDate(ga('exp date', 'expiry date', 'best before'));

        if (materialRaw === '' || deliveryQty <= 0) { result.stats.rows_skipped++; continue; }

        const matKey = materialRaw.trim().toLowerCase();
        const matNum = materialRaw.replace(/[^0-9]/g, '');
        let product = productCache[matKey] ?? productCache[matNum] ?? productCache[String(Math.floor(Number(materialRaw)))] ?? null;

        if (!product && description !== '') {
          const sub = description.substring(0, 15).toLowerCase();
          for (const p of Object.values(productCache)) {
            if (!p || typeof p !== 'object') continue;
            if (String(p.product_name ?? '').toLowerCase().includes(sub)) { product = p; break; }
          }
        }

        if (!product) {
          if (skipUnknown) {
            result.log.push(`Material '${materialRaw}' tidak ditemukan di database → dilewati`);
            result.stats.rows_skipped++;
            continue;
          }
          throw new Error(`Material '${materialRaw}' tidak ditemukan di database.`);
        }

        const uom = normalizeUom(salesUnit, product.uom_type);
        const uomPerPalletVal = uomPerPallet(uom, Number(product.uom_per_pallet));
        const pallet = Math.ceil(deliveryQty / uomPerPalletVal);

        const dKey = String(destName + '|' + destLoc).trim().toLowerCase();
        const destId = dKey === primaryDestKey ? null : (destMap[dKey] ?? null);

        const fefo = await Outbound.getFEFOAllocation(Number(product.id), deliveryQty);
        let firstBatch: string | null = null;
        let firstLoc: string | null = null;
        let firstExpDate = expDate;

        if (fefo.total_available <= 0) {
          result.log.push(`SKIP (stok 0): ${product.product_name} [${materialRaw}] — ${deliveryQty} ${uom}`);
          result.stats.rows_skipped++;
          continue;
        }
        if (fefo.allocation?.length) {
          firstBatch = fefo.allocation[0].batch_number ?? null;
          firstLoc = fefo.allocation[0].location ?? null;
          firstExpDate = fefo.allocation[0].expiry_date ?? expDate;
        }

        let outboundItemId: number;
        try {
          const ins = await dbExec(
            `INSERT INTO outbound_items (outbound_order_id, product_id, quantity, actual_qty, uom, pallet, batch_no, exp_date, location, notes, od_number, so_number, destination_id, customer_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`,
            [
              outboundId, product.id, deliveryQty, deliveryQty, uom, pallet, firstBatch, firstExpDate,
              firstLoc, description || product.product_name, odNo || null, soNo || null, destId, customerId,
            ],
          );
          outboundItemId = Number((ins as any).insertId);
        } catch (colErr) {
          const ins = await dbExec(
            `INSERT INTO outbound_items (outbound_order_id, product_id, quantity, actual_qty, uom, pallet, batch_no, exp_date, location, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`,
            [
              outboundId, product.id, deliveryQty, deliveryQty, uom, pallet, firstBatch, firstExpDate,
              firstLoc, description || product.product_name,
            ],
          );
          outboundItemId = Number((ins as any).insertId);
        }

        if (fefo.allocation?.length) {
          for (const alloc of fefo.allocation) {
            if (!alloc.location) continue;
            try {
              const sl = await dbExecFirst(
                `SELECT sl.id FROM stock_locations sl JOIN stock s ON sl.stock_id = s.id
                 WHERE s.product_id=? AND sl.location_code=? AND sl.status IN ('Available','Reserved') ORDER BY sl.id ASC LIMIT 1`,
                [product.id, alloc.location],
              );
              if (sl) {
                await dbExec(
                  'INSERT IGNORE INTO outbound_item_locations (outbound_item_id, stock_location_id, quantity) VALUES (?,?,?)',
                  [outboundItemId, Number(sl.id), alloc.required_qty ?? alloc.quantity ?? 0],
                );
              }
            } catch (e) {
              /* ignore */
            }
          }
        }

        const fefoInfo = fefo.sufficient
          ? `FEFO OK (${firstBatch}@${firstLoc})`
          : `Stok kurang (tersedia ${fefo.total_available}, diminta ${deliveryQty})`;
        const destDisplay = destName ? ` → ${destName}` : '';
        result.log.push(`OD:${odNo} | ${product.product_name} | ${deliveryQty} ${uom}${destDisplay} | ${fefoInfo}`);
        result.stats.items_imported++;
      }

      const orderItemCount = await dbExecFirst('SELECT COUNT(*) as cnt FROM outbound_items WHERE outbound_order_id=?', [outboundId]);
      if (Number(orderItemCount?.cnt ?? 0) === 0) {
        await dbExec('DELETE FROM outbound_orders WHERE id=?', [outboundId]);
        result.stats.orders_created--;
        result.log.push(`Order #${outboundId} (${shipmentNum}) dihapus — semua item tidak ada di stok`);
      }
    }
  });

  result.message = `Import selesai: ${result.stats.orders_created} orders, ${result.stats.items_imported} items dari ${Object.keys(shipmentData).length} shipments`;
  return result;
}

function todayStr(): string {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

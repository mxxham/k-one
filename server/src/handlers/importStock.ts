import { dbExec, dbExecFirst, withTransactionOwned } from '../db';
import {
  detectHeader, normalizeUom, parseImportDate, readSheet, resolveCol, toFloat, uomPerPallet,
} from '../importHelpers';
import { body, jsonOut } from '../helpers';

/**
 * Port of api/handlers/import_stock.php — stock_preview + stock_commit.
 */

const STOCK_FIELDS: Record<string, string[]> = {
  product_code: ['item', 'item code', 'material no', 'material', 'sku', 'product code', 'kode produk', 'product', 'product_code'],
  sku_code: ['sku', 'sku code', 'sku_number', 'sku no'],
  batch_number: ['batch number', 'batch no', 'batch', 'lot', 'no batch', 'batch_number', 'lot number'],
  location: ['lokasi', 'location', 'bin', 'warehouse location', 'storage bin', 'zone'],
  quantity: ['on hand', 'on-hand', 'onhand', 'remain qty', 'remaining qty', 'available qty', 'quantity', 'actual qty', 'stock qty', 'qty', 'jumlah'],
  uom: ['uom', 'unit', 'satuan', 'unit of measure', 'sales unit', 'uom code'],
  manufacture_date: ['gr date', 'goods receipt date', 'receipt date', 'mfg date', 'manufacture date', 'manufacture_date', 'production date', 'tgl produksi', 'manufacturing date', 'production_date'],
  expiry_date: ['expired date', 'expiry date', 'exp date', 'expiration date', 'best before', 'tgl exp', 'expiry', 'exp_date'],
  stock_status: ['stock status', 'status', 'stock_status', 'state', 'quality'],
  notes: ['notes', 'catatan', 'keterangan', 'remarks', 'remark'],
  description: ['description', 'product description', 'item description', 'deskripsi', 'material description'],
};

export async function stockCommitAction(): Promise<void> {
  const data = body();
  const rows = data.rows ?? [];
  const mode = ['add', 'replace', 'skip'].includes(data.mode ?? '') ? data.mode : 'add';
  if (!Array.isArray(rows) || !rows.length) throw new Error('Tidak ada data untuk di-commit');
  const result = await stockCommit(rows, mode);
  const autoTxt = result.auto_created ? `, ${result.auto_created} produk baru dibuat otomatis.` : '.';
  jsonOut({
    message: `Import selesai: ${result.imported} diimport, ${result.skipped} dilewati` + autoTxt,
    stats: result,
  });
}

export async function stockPreview(): Promise<void> {
  const allRows = await readSheet();
  if (!allRows.length) throw new Error('File kosong atau tidak bisa dibaca');
  const rows = stockParse(allRows);
  await stockValidate(rows);
  jsonOut({
    message: `${rows.length} baris dibaca dari file.`,
    stats: {
      total_rows: rows.length,
      has_errors: rows.filter((r: any) => (r._errors ?? []).length).length,
    },
    rows,
  });
}

export function stockParse(allRows: any[][]): any[] {
  const detect = detectHeader(allRows);
  const headerIdx = detect.index;
  const headers = detect.row.map((h) => String(h ?? '').trim());

  const resolved: Record<string, number | null> = {};
  for (const [key, patterns] of Object.entries(STOCK_FIELDS)) {
    resolved[key] = resolveCol(headers, patterns);
  }

  if (resolved.product_code === null && resolved.sku_code !== null) {
    resolved.product_code = resolved.sku_code;
  }
  if (resolved.product_code === null) throw new Error('Kolom produk (Item / SKU / product code) tidak ditemukan.');
  if (resolved.quantity === null) throw new Error("Kolom qty ('on hand' / 'Qty') tidak ditemukan.");

  const rows: any[] = [];
  for (let i = headerIdx + 1; i < allRows.length; i++) {
    const row = allRows[i];
    const productCode = String(row[resolved.product_code!] ?? '').trim();
    if (!productCode || productCode.toLowerCase() === 'kode produk (wajib)') continue;
    const qty = toFloat(row[resolved.quantity!]);
    if (qty <= 0) continue;
    const uomRaw = String(row[resolved.uom ?? -1] ?? 'Drum').trim() || 'Drum';
    rows.push({
      product_code: productCode,
      sku_code: resolved.sku_code !== null ? String(row[resolved.sku_code] ?? '').trim() : '',
      batch_number: String(row[resolved.batch_number ?? -1] ?? '').trim(),
      location: String(row[resolved.location ?? -1] ?? '').trim(),
      quantity: qty,
      uom: normalizeUom(uomRaw),
      manufacture_date: parseImportDate(row[resolved.manufacture_date ?? -1] ?? null),
      expiry_date: parseImportDate(row[resolved.expiry_date ?? -1] ?? null),
      stock_status: String(row[resolved.stock_status ?? -1] ?? 'Available').trim() || 'Available',
      notes: String(row[resolved.notes ?? -1] ?? '').trim(),
      description: String(row[resolved.description ?? -1] ?? '').trim(),
      _row_num: i + 1,
    });
  }
  if (!rows.length) throw new Error('Tidak ada data valid yang ditemukan di file.');
  return rows;
}

export async function stockValidate(rows: any[]): Promise<void> {
  const today = new Date();
  const todayY = `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}-${String(today.getDate()).padStart(2, '0')}`;
  const productCache: Record<string, any> = {};

  for (const row of rows) {
    row._errors = [];
    row._warnings = [];

    const candidates = [...new Set([row.product_code, row.sku_code ?? ''].filter(Boolean))];
    let product: any = null;
    let usedCode: string | null = null;
    for (const code of candidates) {
      if (!(code in productCache)) {
        productCache[code] = await dbExecFirst(
          'SELECT id, product_name, uom_type, uom_per_pallet FROM products WHERE product_code = ? AND is_active = 1 LIMIT 1',
          [code],
        );
      }
      if (productCache[code]) { product = productCache[code]; usedCode = code; break; }
    }

    if (!product) {
      row._auto_create = true;
      row.product_id = null;
      row.product_name = row.description || row.product_code;
      const autoCode = row.sku_code || row.product_code;
      row._warnings.push(`Produk '${row.product_code}' tidak ditemukan — akan dibuat otomatis sebagai '${autoCode}'`);
    } else {
      if (usedCode !== row.product_code) {
        row._warnings.push(`Produk dicocokkan lewat SKU '${usedCode}' (Item '${row.product_code}' tidak ditemukan)`);
        row.product_code = usedCode;
      }
      row.product_id = Number(product.id);
      row.product_name = product.product_name;
      if (!row.uom) row.uom = product.uom_type ?? 'Drum';
      row.uom_per_pallet = uomPerPallet(row.uom, Number(product.uom_per_pallet ?? 4));
      row.pallet = Math.ceil(row.quantity / row.uom_per_pallet);
    }

    const validStatuses = ['Available', 'Reserved', 'Dues In', 'Expired'];
    if (!validStatuses.includes(row.stock_status)) {
      row._warnings.push(`Status '${row.stock_status}' tidak dikenal, akan diset ke 'Available'`);
      row.stock_status = 'Available';
    }
    if (row.expiry_date && row.expiry_date < todayY) {
      row._warnings.push(`Produk sudah expired (${row.expiry_date})`);
    }
  }
}

export async function stockCommit(rows: any[], mode: string): Promise<Record<string, number>> {
  const m = ['add', 'replace', 'skip'].includes(mode) ? mode : 'add';
  const productCache: Record<string, any> = {};
  const locationCache: Record<string, boolean> = {};
  let autoCreated = 0;
  let autoLocations = 0;

  const findProduct = async (code: string): Promise<any> => {
    if (!(code in productCache)) {
      productCache[code] = await dbExecFirst(
        'SELECT id, product_name, uom_type, uom_per_pallet FROM products WHERE product_code = ? AND is_active = 1 LIMIT 1',
        [code],
      );
    }
    return productCache[code];
  };

  const ensureLocation = async (loc: string): Promise<void> => {
    loc = String(loc ?? '').trim();
    if (!loc || locationCache[loc]) return;
    const existing = await dbExecFirst('SELECT id FROM location_master WHERE location_code = ? LIMIT 1', [loc]);
    if (existing) { locationCache[loc] = true; return; }
    await dbExec("INSERT INTO location_master (location_code, zone, is_active) VALUES (?, 'Bulk', 1)", [loc]);
    locationCache[loc] = true;
    autoLocations++;
  };

  const now = new Date();
  const refPrefix = `IST-${now.getFullYear()}${String(now.getMonth() + 1).padStart(2, '0')}${String(now.getDate()).padStart(2, '0')}-`;

  return withTransactionOwned(async () => {
    let imported = 0;
    let skipped = 0;

    for (const row of rows) {
      if ((row._errors ?? []).length) { skipped++; continue; }

      row.product_id = Number(row.product_id ?? 0);

      if (row.product_id <= 0 && row._auto_create) {
        const code = row.sku_code || row.product_code;
        const name = row.description || row.product_name;
        const uomType = row.uom ?? 'Drum';
        await dbExec(
          `INSERT INTO products (product_code, product_name, description, uom_type, uom_per_pallet, liters_per_unit, max_sku_qty, max_trans_qty, reorder_level, is_active)
           VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, 1)`,
          [code, name, row.description || null, uomType, uomPerPallet(uomType, 4), 209.0, 44, 80],
        );
        const ins = await dbExec('SELECT LAST_INSERT_ID() as id');
        row.product_id = Number(ins[0].id);
        row.uom_per_pallet = uomPerPallet(uomType, 4);
        row.pallet = Math.ceil(row.quantity / row.uom_per_pallet);
        autoCreated++;
      }

      if (row.product_id <= 0) { skipped++; continue; }
      if (row.location) await ensureLocation(row.location);

      const existing = await dbExecFirst(
        'SELECT id, quantity FROM stock WHERE product_id=? AND batch_number<=>? AND location<=>? AND stock_status=? LIMIT 1',
        [row.product_id, row.batch_number || null, row.location || null, row.stock_status],
      );

      const palletVal = row.pallet ?? Math.ceil(row.quantity / 4);

      if (m === 'replace' && existing) {
        await dbExec(
          'UPDATE stock SET quantity=?, pallet=?, manufacture_date=?, expiry_date=?, uom=?, updated_at=NOW() WHERE id=?',
          [row.quantity, palletVal, row.manufacture_date, row.expiry_date, row.uom, Number(existing.id)],
        );
        imported++;
        continue;
      }

      if (m === 'add' && existing) {
        const newQty = Number(existing.quantity ?? 0) + row.quantity;
        const newPlt = Math.ceil(newQty / (row.uom_per_pallet ?? 4));
        await dbExec('UPDATE stock SET quantity=?, pallet=?, updated_at=NOW() WHERE id=?', [newQty, newPlt, Number(existing.id)]);
        imported++;
        continue;
      }

      if (m === 'skip' && existing) { skipped++; continue; }

      await dbExec(
        `INSERT INTO stock (product_id, batch_number, location, quantity, uom, pallet, manufacture_date, expiry_date, stock_status, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())`,
        [
          row.product_id,
          row.batch_number || null,
          row.location || null,
          row.quantity,
          row.uom,
          palletVal,
          row.manufacture_date || null,
          row.expiry_date || null,
          row.stock_status,
        ],
      );

      const refNo = refPrefix + String(imported + 1).padStart(4, '0');
      const balRow = await dbExecFirst(
        "SELECT COALESCE(SUM(quantity),0) as bal FROM stock WHERE product_id=? AND stock_status='Available'",
        [row.product_id],
      );
      const balance = Number(balRow?.bal ?? 0);

      await dbExec(
        `INSERT INTO stock_ledger (transaction_date, product_id, batch_number, transaction_type, quantity_in, quantity_out, uom, pallet, reference_number, reference_type, balance, location, notes)
         VALUES (CURDATE(), ?, ?, 'IN', ?, 0, ?, ?, ?, 'Stock Import', ?, ?, ?)`,
        [
          row.product_id,
          row.batch_number || null,
          row.quantity,
          row.uom,
          palletVal,
          refNo,
          balance,
          row.location || null,
          row.notes || 'Direct stock import',
        ],
      );

      imported++;
    }

    return { imported, skipped, auto_created: autoCreated, auto_locations: autoLocations };
  });
}

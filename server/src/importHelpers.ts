import ExcelJS from 'exceljs';
import { uploadedFile } from './helpers';

/**
 * Port of api/handlers/import_helpers.php — shared helpers for the import
 * module (Excel/csv parsing, header detection, uom normalisation).
 */

/** PHP `import_parse_date()` — accept Excel serial, YYYY-MM-DD, D/M/YYYY, etc. */
export function parseImportDate(val: any): string | null {
  if (val === null || val === undefined || val === '' || val === '0') return null;
  if (val instanceof Date) {
    const y = val.getFullYear();
    if (y < 1990 || y > 2100) return null;
    return toYmd(val);
  }
  if (typeof val === 'number' || (typeof val === 'string' && /^\d+(\.\d+)?$/.test(val.trim()) && parseFloat(val) > 40000)) {
    const serial = Number(val);
    if (serial > 40000) {
      const ms = Math.round((serial - 25569) * 86400000);
      const dt = new Date(ms);
      const y = dt.getUTCFullYear();
      if (y < 1990 || y > 2100) return null;
      return `${y}-${String(dt.getUTCMonth() + 1).padStart(2, '0')}-${String(dt.getUTCDate()).padStart(2, '0')}`;
    }
  }
  const s = String(val).trim();
  if (/^\d{4}-\d{2}-\d{2}$/.test(s)) return s;
  let m = /^(\d{1,2})\/(\d{1,2})\/(\d{4})$/.exec(s);
  if (m) {
    const d = parseInt(m[1], 10);
    const mo = parseInt(m[2], 10);
    const y = parseInt(m[3], 10);
    if (d > 12 && mo <= 12) return `${y}-${String(mo).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
    if (mo > 12 && d <= 12) return `${y}-${String(d).padStart(2, '0')}-${String(mo).padStart(2, '0')}`;
    return `${y}-${String(mo).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
  }
  m = /^(\d{1,2})-(\d{1,2})-(\d{4})$/.exec(s);
  if (m) {
    return `${m[3]}-${String(parseInt(m[2], 10)).padStart(2, '0')}-${String(parseInt(m[1], 10)).padStart(2, '0')}`;
  }
  const ts = Date.parse(s.replace(/\//g, '-'));
  if (!isNaN(ts)) {
    const dt = new Date(ts);
    const y = dt.getFullYear();
    if (y >= 1990 && y <= 2100) return toYmd(dt);
  }
  return null;
}

function toYmd(d: Date): string {
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

/** PHP `import_normalize_uom()`. */
export function normalizeUom(raw: string, fallback = 'Drum'): string {
  const map: Record<string, string> = {
    car: 'Carton', ctn: 'Carton', carton: 'Carton',
    drm: 'Drum', drum: 'Drum',
    pail: 'Pail', pal: 'Pail',
    bag: 'Bags', bags: 'Bags',
    ea: 'EA', each: 'EA', pcs: 'EA',
  };
  const k = String(raw ?? '').trim().toLowerCase();
  if (map[k]) return map[k];
  return k ? k.charAt(0).toUpperCase() + k.slice(1) : fallback;
}

/** PHP `import_uom_per_pallet()`. */
export function uomPerPallet(uom: string, productUpp = 4): number {
  switch (String(uom ?? '').trim().toLowerCase()) {
    case 'drum': return 4;
    case 'carton': return Math.max(1, productUpp || 44);
    case 'pail': return 24;
    case 'bags': return 1;
    case 'ea': return 4;
    default: return Math.max(1, productUpp || 4);
  }
}

/** Read a cell value into a simple type (Date preserved for date parsing). */
function cellToValue(v: any): any {
  if (v === null || v === undefined) return null;
  if (typeof v === 'object') {
    if (v instanceof Date) return v;
    if (typeof v.result !== 'undefined') return cellToValue(v.result);
    if (v.richText && Array.isArray(v.richText)) return v.richText.map((t: any) => t.text ?? '').join('');
    if (typeof v.text !== 'undefined') return v.text;
    return null;
  }
  return v;
}

/** Read the uploaded sheet grid (first worksheet unless a sheet name is given). */
export async function readSheet(sheetName: string | null = null): Promise<any[][]> {
  const file = uploadedFile();
  if (!file) throw new Error('No file uploaded or upload error');
  const name: string = file.originalname ?? '';
  const ext = name.split('.').pop()?.toLowerCase() ?? '';
  if (!['xlsx', 'xls', 'csv'].includes(ext)) throw new Error('Format file harus .xlsx, .xls, atau .csv');

  if (ext === 'csv') {
    return parseCsv(file.buffer.toString('utf8'));
  }

  const wb = new ExcelJS.Workbook();
  try {
    await wb.xlsx.load(file.buffer as any);
  } catch (e) {
    throw new Error('File Excel tidak bisa dibaca: ' + (e as Error).message);
  }
  const sheet = sheetName ? (wb.getWorksheet(sheetName) ?? null) : null;
  const ws = sheet ?? (wb.worksheets.length ? wb.worksheets[0] : null);
  if (!ws) throw new Error('File kosong atau tidak bisa dibaca');

  const rows: any[][] = [];
  ws.eachRow({ includeEmpty: true }, (row) => {
    const cells: any[] = [];
    for (let c = 1; c <= ws.columnCount; c++) {
      cells.push(cellToValue(row.getCell(c).value));
    }
    rows.push(cells);
  });
  return rows;
}

/** Read every worksheet of the uploaded workbook into `{name, rows}` entries. */
export async function readAllSheets(): Promise<{ name: string; rows: any[][] }[]> {
  const wb = await loadWorkbook();
  const sheets: { name: string; rows: any[][] }[] = [];
  for (const ws of wb.worksheets) {
    const rows: any[][] = [];
    ws.eachRow({ includeEmpty: true }, (row) => {
      const cells: any[] = [];
      for (let c = 1; c <= ws.columnCount; c++) {
        cells.push(cellToValue(row.getCell(c).value));
      }
      rows.push(cells);
    });
    sheets.push({ name: ws.name, rows });
  }
  return sheets;
}

/** Load the uploaded workbook (xlsx via exceljs). */
async function loadWorkbook(): Promise<ExcelJS.Workbook> {
  const file = uploadedFile();
  if (!file) throw new Error('No file uploaded or upload error');
  const name: string = file.originalname ?? '';
  const ext = name.split('.').pop()?.toLowerCase() ?? '';
  if (ext === 'csv') throw new Error('Auto import memerlukan file .xlsx atau .xls');
  if (!['xlsx', 'xls'].includes(ext)) throw new Error('Format file harus .xlsx, .xls, atau .csv');
  const wb = new ExcelJS.Workbook();
  try {
    await wb.xlsx.load(file.buffer as any);
  } catch (e) {
    throw new Error('File Excel tidak bisa dibaca: ' + (e as Error).message);
  }
  return wb;
}

/** Simple CSV parser (handles quoted fields and escaped quotes). */
export function parseCsv(text: string): string[][] {
  const rows: string[][] = [];
  let row: string[] = [];
  let field = '';
  let inQuotes = false;
  for (let i = 0; i < text.length; i++) {
    const ch = text[i];
    if (inQuotes) {
      if (ch === '"') {
        if (text[i + 1] === '"') { field += '"'; i++; }
        else inQuotes = false;
      } else field += ch;
    } else if (ch === '"') {
      inQuotes = true;
    } else if (ch === ',') {
      row.push(field); field = '';
    } else if (ch === '\n' || ch === '\r') {
      if (ch === '\r' && text[i + 1] === '\n') i++;
      row.push(field); field = '';
      rows.push(row); row = [];
    } else {
      field += ch;
    }
  }
  if (field !== '' || row.length) { row.push(field); rows.push(row); }
  return rows;
}

/** PHP `import_header_index()` — lowercase column-name map. */
export function headerIndex(row: any[]): Record<string, number> {
  const map: Record<string, number> = {};
  row.forEach((h, i) => {
    const key = String(h ?? '').trim().replace(/^\s*\*\s*/, '').toLowerCase();
    if (key !== '') map[key] = i;
  });
  return map;
}

/** PHP `import_resolve_col()` — best-matching header column for a field. */
export function resolveCol(headers: any[], patterns: string[]): number | null {
  let best: number | null = null;
  let bestPri = Number.MAX_SAFE_INTEGER;
  let bestExact = false;
  patterns.forEach((pattern, pri) => {
    const p = String(pattern ?? '').trim().toLowerCase();
    if (p === '') return;
    headers.forEach((h, ci) => {
      const hl = String(h ?? '').trim().toLowerCase();
      if (hl === '') return;
      const exact = hl === p;
      if (exact || hl.includes(p)) {
        if (pri < bestPri || (pri === bestPri && exact && !bestExact)) {
          best = ci;
          bestPri = pri;
          bestExact = exact;
        }
      }
    });
  });
  return best;
}

/** PHP `import_detect_header()` — find the row that looks like a table header. */
export function detectHeader(allRows: any[][]): { index: number; row: any[] } {
  const keywords = ['product', 'qty', 'batch', 'item', 'lokasi', 'location', 'on hand', 'uom', 'unit', 'shipment', 'material', 'sku', 'expiry', 'exp date', 'expired date', 'gr date', 'quantity', 'actual qty', 'volume', 'batch no'];
  let bestIdx = 0;
  let bestScore = -1;
  allRows.forEach((row, idx) => {
    const rowStr = row.map((v) => String(v ?? '')).join(' ').toLowerCase();
    let score = 0;
    for (const kw of keywords) if (rowStr.includes(kw)) score++;
    if (score > bestScore) { bestScore = score; bestIdx = idx; }
  });
  if (bestScore < 1) bestIdx = 0;
  return { index: bestIdx, row: allRows[bestIdx] ?? [] };
}

/** PHP `import_getter()` — value lookup closure over a column map. */
export function makeGetter(colMap: Record<string, number>, row: any[]) {
  return (...keys: string[]): string => {
    for (const key of keys) {
      const k = String(key ?? '').trim().toLowerCase();
      const ci = colMap[k];
      if (ci !== undefined && ci !== null) {
        const v = String(row[ci] ?? '').trim();
        if (v !== '' && v !== '0') return v;
      }
    }
    return '';
  };
}

/** PHP `import_is_meta_row()` — template note / meta rows. */
export function isMetaRow(row: any[]): boolean {
  const first = String(row[0] ?? '').trim();
  if (first === '' || first.startsWith('*')) return true;
  const lower = first.toLowerCase();
  for (const kw of ['kolom', 'values', 'format', 'uraian', 'petunjuk', 'note', '* nama']) {
    if (lower.includes(kw)) return true;
  }
  return false;
}

/** PHP `floatval()`. */
export function toFloat(v: any): number {
  const n = parseFloat(String(v ?? '').replace(/[^\d.\-]/g, ''));
  return isNaN(n) ? 0 : n;
}

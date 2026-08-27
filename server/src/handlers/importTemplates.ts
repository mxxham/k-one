import ExcelJS from 'exceljs';
import { sendBuffer } from '../helpers';

/**
 * Port of api/handlers/import_templates.php — Excel template downloads.
 * Uses exceljs instead of PhpSpreadsheet.
 */

function styleHeader(ws: ExcelJS.Worksheet, headers: { label: string; required?: boolean; width?: number }[], headerColor: string): void {
  const col = 1;
  headers.forEach((h, i) => {
    const cell = ws.getCell(i + 1, 1);
    cell.value = (h.required ? '* ' : '') + h.label;
    cell.font = { bold: true, size: 10, color: { argb: 'FFFFFFFF' } };
    cell.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: 'FF' + headerColor } };
    cell.alignment = { horizontal: 'center', vertical: 'middle' };
    cell.border = { bottom: { style: 'thin', color: { argb: 'FF9E9E9E' } } };
    ws.getColumn(i + 1).width = h.width ?? 18;
  });
  ws.getRow(1).height = 24;
  ws.views = [{ state: 'frozen', ySplit: 1 }];
}

function addSampleRow(ws: ExcelJS.Worksheet, values: any[]): void {
  const rowNum = ws.actualRowCount + 1;
  values.forEach((val, ci) => {
    ws.getCell(ci + 1, rowNum).value = val;
  });
}

function addNote(ws: ExcelJS.Worksheet, label: string, value = ''): void {
  const rowNum = ws.actualRowCount + 1;
  ws.getCell(1, rowNum).value = label;
  ws.getCell(1, rowNum).font = { italic: true, color: { argb: 'FF6B7280' } };
  if (value !== '') {
    ws.getCell(2, rowNum).value = value;
    ws.getCell(2, rowNum).font = { italic: true, color: { argb: 'FF1565C0' } };
  }
}

async function workbookBuffer(wb: ExcelJS.Workbook): Promise<Buffer> {
  return wb.xlsx.writeBuffer() as unknown as Promise<Buffer>;
}

export async function genInboundTemplate(): Promise<void> {
  const wb = new ExcelJS.Workbook();
  const ws = wb.addWorksheet('Inbound Data');
  styleHeader(ws, [
    { label: 'Shipment No', width: 20 },
    { label: 'OD No', width: 18 },
    { label: 'SO No', width: 18 },
    { label: 'Item Code', width: 22, required: true },
    { label: 'Uom', width: 10, required: true },
    { label: 'ACTUAL QTY', width: 12, required: true },
    { label: 'QTY ORDER', width: 12 },
    { label: 'Pallet', width: 10 },
    { label: 'Batch No', width: 18 },
    { label: 'Manufacture date', width: 16, required: true },
    { label: 'Exp Date', width: 14, required: true },
    { label: 'Location', width: 14 },
    { label: 'Remarks', width: 20 },
  ], '1565C0');

  addSampleRow(ws, ['SHP-2026-001', '530870001', '4549106001', 'ADVANCE-AX7', 'Carton', 12, 12, '', 'LOT/2026/001', '2024-06-01', '2028-06-01', 'CA05B01', '']);
  addSampleRow(ws, ['SHP-2026-001', '530870002', '4549106002', 'HELIX-HX7', 'Drum', 4, 4, 1, 'LOT/2026/002', '2024-07-01', '2028-07-01', 'CB03A01', '']);
  addSampleRow(ws, ['SHP-2026-002', '530870003', '4549106003', 'GADUS-S2', 'Pail', 1, 1, '', 'LOT/2026/003', '2025-01-01', '2029-01-01', 'CC08E01', 'Fragile']);

  addNote(ws, '* = Kolom wajib diisi', 'Drum | Carton | Pail | EA | Bags');
  addNote(ws, 'Uom values:', 'Drum | Carton | Pail | EA | Bags');
  addNote(ws, 'Date format:', 'YYYY-MM-DD atau DD/MM/YYYY');
  addNote(ws, 'Item Code:', 'Harus sesuai Product Code di database K-one');
  addNote(ws, 'Shipment No:', 'WAJIB untuk grouping — baris dengan Shipment No sama = satu inbound order');

  const buf = await workbookBuffer(wb);
  sendBuffer(buf, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Inbound_Import_Template_K-one.xlsx');
}

export async function genOutboundTemplate(): Promise<void> {
  const wb = new ExcelJS.Workbook();
  const ws = wb.addWorksheet('Outbound Data');
  styleHeader(ws, [
    { label: 'Plan Date', width: 14 },
    { label: 'Shipment Number', width: 18, required: true },
    { label: 'Order No (OD)', width: 16 },
    { label: 'Purchasing Document', width: 18 },
    { label: 'Ship-to Party Code', width: 14 },
    { label: 'Material', width: 16, required: true },
    { label: 'Description', width: 32 },
    { label: 'Delivery quantity', width: 12, required: true },
    { label: 'Sales Unit', width: 10, required: true },
    { label: 'Name of Ship-To Party', width: 28 },
    { label: 'Location of Ship-To Party', width: 22 },
    { label: 'Street / Address', width: 30 },
    { label: 'Goods Issue Date', width: 16 },
    { label: 'SO Number', width: 22 },
    { label: 'TRANSPORT', width: 12 },
  ], '4A148C');

  addSampleRow(ws, ['2026-03-31', '109294012', '531746742', '13004218', 'PO/001', 'ADVANCE-AX7', 'Shell Advance 4T AX7', 100, 'CAR', 'CV MULTI SARANA BAN', 'SURABAYA', 'Jl. Raya Darmo No. 1', '2026-03-31', '', 'LF']);
  addSampleRow(ws, ['2026-03-31', '109294012', '531746741', '12529551', 'PO/002', 'HELIX-HX7', 'Shell Helix HX7', 100, 'CAR', 'CV MULTI SARANA BAN', 'SURABAYA', 'Jl. Raya Darmo No. 1', '2026-03-31', '', 'LF']);
  addSampleRow(ws, ['2026-03-31', '109294013', '531746801', '10000001', 'PO/003', 'ADVANCE-AX7', 'Shell Advance 4T AX7', 50, 'CAR', 'PT SUMBER BARU BAN', 'MALANG', 'Jl. Soekarno Hatta 88', '2026-03-31', '', 'LF']);

  addNote(ws, 'PENTING — Struktur Order:', '1 Shipment Number = 1 Order Outbound di sistem WMS');
  addNote(ws, 'Name/Location/Street per item:', 'Boleh berbeda tiap baris dalam 1 shipment (multi-tujuan)');
  addNote(ws, 'UOM values:', 'Drum (DRM) | Carton (CAR) | Pail (PAL/PAIL) | Bags | EA');
  addNote(ws, 'Material:', 'Gunakan Product Code dari database K-one');

  const buf = await workbookBuffer(wb);
  sendBuffer(buf, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Outbound_Import_Template_K-one.xlsx');
}

export async function genStockTemplate(): Promise<void> {
  const wb = new ExcelJS.Workbook();
  const ws = wb.addWorksheet('Stock Import');
  const headers = ['product_code*', 'batch_number', 'location', 'quantity*', 'uom', 'manufacture_date', 'expiry_date', 'stock_status', 'notes'];
  headers.forEach((val, i) => {
    const cell = ws.getCell(i + 1, 1);
    cell.value = val;
    cell.font = { bold: true, size: 11, color: { argb: 'FFFFFFFF' } };
    cell.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: 'FF1B5E20' } };
    cell.alignment = { horizontal: 'center' };
  });

  const descs = ['Kode produk (wajib)', 'No batch / lot', 'Lokasi bin (mis: A-01-01)', 'Jumlah qty (wajib)', 'Drum/Carton/Pail/Bags/EA (default: Drum)', 'Tgl produksi', 'Tgl exp', 'Available/Dues In/Reserved', 'Catatan opsional'];
  descs.forEach((val, i) => { ws.getCell(i + 1, 2).value = val; });

  const examples: [number, number, any][] = [
    [1, 3, 'SHE-001'], [2, 3, 'BT2024001'], [3, 3, 'A-01-01'], [4, 3, 20],
    [5, 3, 'Drum'], [6, 3, '01/01/2024'], [7, 3, '01/01/2026'], [8, 3, 'Available'], [9, 3, 'Opening stock'],
  ];
  examples.forEach(([col, row, val]) => { ws.getCell(col, row).value = val; });

  for (let c = 1; c <= 9; c++) ws.getColumn(c).width = 16;
  ws.views = [{ state: 'frozen', ySplit: 4 }];

  const buf = await workbookBuffer(wb);
  sendBuffer(buf, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'template_import_stock.xlsx');
}

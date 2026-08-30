import { useState, useCallback } from 'react';
import { Printer, X, FileText, Layers, Package } from 'lucide-react';
import GS1Barcode from './GS1Barcode';
import { buildGS1ProductBarcode, buildSSCCBarcode, formatGS1HumanReadable } from '@/lib/gs1';
import { fmtNum } from '@/lib/format';
import type { GS1ProductLabelData } from './GS1ProductLabel';
import type { SSCCLabelData } from './SSCCLabel';

// ─── Types ──────────────────────────────────────────────────────────────────

export type LabelType = 'gs1' | 'sscc' | 'lpn';
export type PageSize = 'A4' | 'A5' | 'letter';

export interface BatchPrintOptions {
  labelType: LabelType;
  pageSize: PageSize;
  orientation: 'portrait' | 'landscape';
  title: string;
}

interface BatchPrintDialogProps {
  /** Labels to print */
  labels: Array<GS1ProductLabelData | SSCCLabelData | Record<string, unknown>>;
  /** Label type determines which template to render */
  labelType: LabelType;
  /** Callback when dialog is closed */
  onClose: () => void;
  /** Optional: pre-selected page size */
  pageSize?: PageSize;
}

// ─── Size Presets ───────────────────────────────────────────────────────────

const PAGE_SIZES: Record<PageSize, { label: string; width: string; height: string }> = {
  A4:     { label: 'A4 (210×297mm)',     width: '210mm', height: '297mm' },
  A5:     { label: 'A5 (148×210mm)',     width: '148mm', height: '210mm' },
  letter: { label: 'Letter (216×279mm)', width: '216mm', height: '279mm' },
};

// ─── Label Renderers ────────────────────────────────────────────────────────

function renderGS1LabelHtml(item: GS1ProductLabelData): string {
  let barcodeValue = '';
  let humanReadable = '';
  try {
    barcodeValue = buildGS1ProductBarcode({
      gtin: item.gtin || undefined,
      batchNumber: item.batch_number || undefined,
      expiryDate: item.expiry_date || undefined,
      quantity: item.quantity,
    });
    humanReadable = formatGS1HumanReadable(barcodeValue);
  } catch {
    barcodeValue = item.product_code || '';
    humanReadable = barcodeValue;
  }

  const name = item.product_name || item.product_code || '—';
  const safeName = name.replace(/</g, '&lt;').replace(/>/g, '&gt;');
  const safeCode = (item.product_code || '').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  const safeBatch = (item.batch_number || '—').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  const safeExp = (item.expiry_date || '—').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  const qty = fmtNum(item.quantity ?? 0);
  const uom = (item.uom || '').replace(/</g, '&lt;').replace(/>/g, '&gt;');

  return `<div style="font-family:monospace;width:350px;max-width:350px;border:2px solid #111;padding:12px;margin:0 auto;background:#fff;color:#000">
  <div style="display:flex;justify-content:space-between;border-bottom:1px solid #999;padding-bottom:6px;margin-bottom:8px">
    <div style="font-weight:900;font-size:14px;letter-spacing:.08em">${safeCode}<br><span style="font-size:9px;font-weight:700;color:#666">GS1 PRODUCT LABEL</span></div>
    <div style="font-size:9px;font-weight:700;color:#666;text-align:right;line-height:1.3">PT. K-ONE<br>WAREHOUSE</div>
  </div>
  <div style="font-size:11px;line-height:1.4">
    <div style="font-weight:700">${safeName}</div>
    <div style="display:flex;justify-content:space-between;margin-top:4px">
      <span>Batch: <b>${safeBatch}</b></span>
      <span>Exp: <b>${safeExp}</b></span>
    </div>
    <div>Qty: <b>${qty} ${uom}</b></div>
  </div>
  <div style="text-align:center;margin:8px 0"><svg class="barcode-placeholder" data-barcode="${barcodeValue.replace(/"/g, '&quot;')}" data-format="GS1_128" data-height="50"></svg></div>
  <div style="text-align:center;font-size:8px;color:#666;word-break:break-all;font-family:monospace">${humanReadable.replace(/</g, '&lt;').replace(/>/g, '&gt;')}</div>
</div>`;
}

function renderSSCCLabelHtml(item: SSCCLabelData): string {
  const sscc = (item.sscc || '').replace(/\D/g, '');
  let barcodeValue = sscc;
  let humanReadable = sscc;
  try {
    if (sscc.length === 18) {
      barcodeValue = buildSSCCBarcode(sscc);
      humanReadable = formatGS1HumanReadable(barcodeValue);
    }
  } catch { /* fallback */ }

  const po = (item.po_number || '—').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  const sender = (item.sender_name || 'PT. K-ONE').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  const receiver = (item.receiver_name || '—').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  const location = (item.location || '—').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  const itemCount = item.item_count ?? 0;

  return `<div style="font-family:monospace;width:400px;max-width:400px;border:2px solid #111;padding:14px;margin:0 auto;background:#fff;color:#000">
  <div style="display:flex;justify-content:space-between;border-bottom:1px solid #999;padding-bottom:6px;margin-bottom:8px">
    <div style="font-weight:900;font-size:14px;letter-spacing:.08em">SSCC<br><span style="font-size:9px;font-weight:700;color:#666">SERIAL SHIPPING CONTAINER CODE</span></div>
    <div style="font-size:9px;font-weight:700;color:#666;text-align:right;line-height:1.3">${sender}</div>
  </div>
  <div style="font-size:11px;line-height:1.4">
    <div style="display:flex;justify-content:space-between"><span>PO: <b>${po}</b></span><span>Items: <b>${itemCount}</b></span></div>
    <div>Receiver: <b>${receiver}</b></div>
    <div>Location: <b>${location}</b></div>
  </div>
  <div style="text-align:center;margin:8px 0"><svg class="barcode-placeholder" data-barcode="${barcodeValue.replace(/"/g, '&quot;')}" data-format="GS1_128" data-height="60"></svg></div>
  <div style="text-align:center;font-size:8px;color:#666;word-break:break-all;font-family:monospace">${humanReadable.replace(/</g, '&lt;').replace(/>/g, '&gt;')}</div>
  <div style="text-align:center;font-weight:700;letter-spacing:.35em;font-size:11px;margin-top:6px;border-top:1px solid #ccc;padding-top:4px">${item.sscc || ''}</div>
</div>`;
}

function renderLPNLabelHtml(item: Record<string, unknown>): string {
  const lpn = String(item.lpn_code || '').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  const prodName = String(item.product_name || item.product_code || '—').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  const prodCode = String(item.product_code || '').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  const batch = String(item.batch_number || '—').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  const expiry = String(item.expiry_date || '—').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  const qty = fmtNum(item.quantity as number ?? 0);
  const uom = String(item.uom || '').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  const pallet = Number(item.pallet_seq ?? 0);
  const loc = String(item.suggested_location || '—').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  const taskNum = String(item.task_number || '—').replace(/</g, '&lt;').replace(/>/g, '&gt;');

  return `<div style="font-family:monospace;width:320px;border:2px solid #111;padding:12px;margin:0 auto;background:#fff;color:#000">
  <div style="display:flex;justify-content:space-between;border-bottom:1px solid #999;padding-bottom:6px;margin-bottom:8px">
    <div style="font-weight:900;font-size:14px;letter-spacing:.08em">${lpn}<br><span style="font-size:9px;font-weight:700;color:#666">LABEL PALLET / LPN</span></div>
    <div style="font-size:9px;font-weight:700;color:#666;text-align:right;line-height:1.3">PT. K-ONE<br>WAREHOUSE</div>
  </div>
  <div style="font-size:11px;line-height:1.4">
    <div style="font-weight:700">${prodName}</div>
    <div style="font-size:10px;color:#666">${prodCode}</div>
    <div style="display:flex;justify-content:space-between;margin-top:4px"><span>Batch: <b>${batch}</b></span><span>Exp: <b>${expiry}</b></span></div>
    <div style="display:flex;justify-content:space-between"><span>Qty: <b>${qty} ${uom}</b></span><span>Pallet: <b>#${pallet}</b></span></div>
    <div style="display:flex;justify-content:space-between"><span>Lokasi: <b>${loc}</b></span><span>Task: <b>${taskNum}</b></span></div>
  </div>
  <div style="text-align:center;font-weight:700;letter-spacing:.35em;font-size:11px;margin-top:6px;border-top:1px solid #ccc;padding-top:4px">${lpn}</div>
</div>`;
}

// ─── Print Document Builder ─────────────────────────────────────────────────

function buildPrintDocument(
  labelHtmls: string[],
  options: { title: string; pageSize: PageSize; orientation: string },
): string {
  const size = PAGE_SIZES[options.pageSize];
  const labelsHtml = labelHtmls.map((html, idx) => {
    const breakStyle = idx < labelHtmls.length - 1 ? 'page-break-after:always' : 'page-break-after:auto';
    return `<div class="batch-label" style="display:flex;justify-content:center;align-items:flex-start;padding:10mm;${breakStyle}">${html}</div>`;
  }).join('\n');

  return `<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>${options.title}</title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: monospace; background: #f3f4f6; }
  @media print {
    body { background: white; }
    .batch-label { width: ${size.width}; height: ${size.height}; padding: 5mm; page-break-after: always; }
    .batch-label:last-child { page-break-after: auto; }
    @page { size: ${options.pageSize} ${options.orientation}; margin: 0; }
  }
</style>
</head>
<body>
${labelsHtml}
</body>
</html>`;
}

// ─── BatchPrintDialog Component ─────────────────────────────────────────────

/**
 * Batch printing dialog for GS1, SSCC, and LPN labels.
 *
 * Renders a preview of labels with page size selection and opens
 * the browser print dialog with a properly formatted print document.
 */
export default function BatchPrintDialog({
  labels,
  labelType,
  onClose,
  pageSize: initialPageSize = 'A4',
}: BatchPrintDialogProps) {
  const [pageSize, setPageSize] = useState<PageSize>(initialPageSize);
  const [printCount, setPrintCount] = useState(1);
  const [isPrinting, setIsPrinting] = useState(false);

  const handlePrint = useCallback(() => {
    if (labels.length === 0) return;

    setIsPrinting(true);

    try {
      const renderFn = labelType === 'gs1'
        ? renderGS1LabelHtml
        : labelType === 'sscc'
          ? renderSSCCLabelHtml
          : renderLPNLabelHtml;

      // Build labels for each copy
      const allLabels: string[] = [];
      for (let copy = 0; copy < printCount; copy++) {
        for (const label of labels) {
          allLabels.push(renderFn(label as any));
        }
      }

      const html = buildPrintDocument(allLabels, {
        title: `K-one ${labelType.toUpperCase()} Labels`,
        pageSize,
        orientation: 'portrait',
      });

      // Open print window
      const printWindow = window.open('', '_blank', 'width=800,height=600');
      if (printWindow) {
        printWindow.document.write(html);
        printWindow.document.close();
        // Wait for barcode rendering, then print
        setTimeout(() => {
          printWindow.print();
          printWindow.close();
        }, 500);
      }
    } finally {
      setIsPrinting(false);
    }
  }, [labels, labelType, pageSize, printCount]);

  const totalLabels = labels.length * printCount;
  const estimatedPages = Math.ceil(totalLabels / (pageSize === 'A5' ? 2 : 1));

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
      <div className="bg-white rounded-2xl shadow-2xl w-full max-w-lg mx-4 overflow-hidden">
        {/* Header */}
        <div className="flex items-center justify-between px-5 py-4 border-b border-gray-200">
          <div className="flex items-center gap-2">
            <Layers className="w-5 h-5 text-brand-600" />
            <h2 className="text-lg font-bold text-gray-900">
              Batch Print — {labelType.toUpperCase()} Labels
            </h2>
          </div>
          <button onClick={onClose} className="p-1 rounded-lg hover:bg-gray-100">
            <X className="w-5 h-5 text-gray-500" />
          </button>
        </div>

        {/* Body */}
        <div className="px-5 py-4 space-y-4">
          {/* Summary */}
          <div className="flex items-center gap-3 p-3 bg-gray-50 rounded-xl">
            <Package className="w-8 h-8 text-brand-500 shrink-0" />
            <div>
              <div className="text-sm font-semibold text-gray-900">
                {labels.length} label{labels.length !== 1 ? 's' : ''} selected
              </div>
              <div className="text-xs text-gray-500">
                ~{estimatedPages} page{estimatedPages !== 1 ? 's' : ''} estimated
              </div>
            </div>
          </div>

          {/* Page Size */}
          <div>
            <label className="block text-sm font-semibold text-gray-700 mb-1.5">Page Size</label>
            <div className="flex gap-2">
              {(Object.entries(PAGE_SIZES) as [PageSize, typeof PAGE_SIZES[PageSize]][]).map(([key, val]) => (
                <button
                  key={key}
                  onClick={() => setPageSize(key)}
                  className={`flex-1 px-3 py-2 rounded-lg text-sm font-medium border-2 transition-colors ${
                    pageSize === key
                      ? 'border-brand-600 bg-brand-50 text-brand-700'
                      : 'border-gray-200 text-gray-600 hover:border-gray-300'
                  }`}
                >
                  {key}
                </button>
              ))}
            </div>
          </div>

          {/* Print Copies */}
          <div>
            <label className="block text-sm font-semibold text-gray-700 mb-1.5">Copies</label>
            <div className="flex items-center gap-3">
              <button
                onClick={() => setPrintCount(Math.max(1, printCount - 1))}
                className="w-8 h-8 rounded-lg border border-gray-300 flex items-center justify-center text-gray-600 hover:bg-gray-50"
              >
                −
              </button>
              <span className="w-12 text-center font-mono font-bold text-lg">{printCount}</span>
              <button
                onClick={() => setPrintCount(Math.min(10, printCount + 1))}
                className="w-8 h-8 rounded-lg border border-gray-300 flex items-center justify-center text-gray-600 hover:bg-gray-50"
              >
                +
              </button>
              <span className="text-sm text-gray-500">
                ({totalLabels} label{totalLabels !== 1 ? 's' : ''} total)
              </span>
            </div>
          </div>
        </div>

        {/* Footer */}
        <div className="flex items-center justify-end gap-2 px-5 py-4 border-t border-gray-200 bg-gray-50">
          <button
            onClick={onClose}
            className="px-4 py-2 rounded-lg text-sm font-semibold text-gray-700 hover:bg-gray-200"
          >
            Cancel
          </button>
          <button
            onClick={handlePrint}
            disabled={labels.length === 0 || isPrinting}
            className="inline-flex items-center gap-1.5 px-5 py-2 rounded-lg bg-brand-600 text-white text-sm font-semibold hover:bg-brand-700 disabled:opacity-50"
          >
            <Printer className="w-4 h-4" />
            {isPrinting ? 'Printing...' : `Print ${totalLabels} Label${totalLabels !== 1 ? 's' : ''}`}
          </button>
        </div>
      </div>
    </div>
  );
}

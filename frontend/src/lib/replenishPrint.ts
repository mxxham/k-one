import type { ReplenishmentTaskData } from '@/components/ReplenishmentSheet';

/**
 * CSS copied from print_picklist.php for the professional A4 print format.
 */
const PICKLIST_CSS = `
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap');
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Inter',system-ui,sans-serif;font-size:10pt;color:#0f172a;background:#f1f5f9;line-height:1.5}
@page{size:A4 portrait;margin:0}
@media print{body{background:#fff;-webkit-print-color-adjust:exact;print-color-adjust:exact}tr{page-break-inside:avoid}.document{box-shadow:none;margin:0;padding:14mm 14mm 16mm;max-width:100%;border-radius:0}}

.document{max-width:794px;margin:20px auto;background:#fff;padding:28px 32px;box-shadow:0 1px 12px rgba(0,0,0,.06);border-radius:6px}

.doc-header{display:flex;justify-content:space-between;align-items:center;padding-bottom:16px;margin-bottom:16px;border-bottom:2px solid #e2e8f0}
.logo-area{display:flex;align-items:center;gap:12px}
.logo-mark{width:44px;height:44px;background:linear-gradient(135deg,#026766,#013d3c);border-radius:10px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:20px;font-weight:800;flex-shrink:0}
.company-name{font-size:18px;font-weight:800;color:#0f172a;letter-spacing:-.5px}
.company-name span{color:#64748b;font-weight:400}
.doc-title-block{text-align:right}
.doc-title{font-size:20px;font-weight:800;color:#0f172a;letter-spacing:-.3px}
.doc-subtitle{font-size:11px;color:#64748b;margin-top:1px}
.doc-taskno{font-size:12px;font-weight:700;color:#334155;margin-top:4px;font-family:'SF Mono',Consolas,monospace}

.info-grid{display:grid;grid-template-columns:1fr 1fr 1fr;border:1px solid #e2e8f0;border-radius:8px;overflow:hidden;margin-bottom:16px}
.info-cell{padding:10px 14px;border-right:1px solid #e2e8f0;border-bottom:1px solid #e2e8f0;background:#fff}
.info-cell:nth-child(3n){border-right:none}
.info-cell:nth-last-child(-n+3){border-bottom:none}
.info-cell .lbl{font-size:9px;color:#64748b;text-transform:uppercase;letter-spacing:.6px;font-weight:600;margin-bottom:2px}
.info-cell .val{font-size:12px;font-weight:600;color:#0f172a;line-height:1.6}

.section-title{font-size:11px;font-weight:700;color:#334155;margin-bottom:10px;display:flex;align-items:center;gap:6px}
.section-title::before{content:'';display:block;width:3px;height:14px;background:#026766;border-radius:2px}

table{width:100%;border-collapse:collapse;font-size:10px;margin-bottom:14px}
thead th{background:#0f2e2d;color:#fff;padding:8px 10px;text-align:left;font-weight:600;font-size:9px;letter-spacing:.4px;text-transform:uppercase}
thead th:first-child{border-radius:6px 0 0 0}
thead th:last-child{border-radius:0 6px 0 0}
thead th.c{text-align:center}
thead th.r{text-align:right}
tbody tr{border-bottom:1px solid #f1f5f9}
tbody tr:nth-child(even){background:#f8fafc}
tbody td{padding:8px 10px;vertical-align:middle;line-height:1.5}
tbody td.c{text-align:center}
tbody td.r{text-align:right;font-weight:600}
tfoot td{padding:10px;font-weight:700;font-size:11px;border-top:2px solid #0f2e2d;background:#f0fdfa;color:#0f172a}
tfoot td.r{text-align:right}

.chip{display:inline-block;border-radius:4px;padding:2px 8px;font-family:'SF Mono',Consolas,monospace;font-size:9px;font-weight:600}
.chip-loc{background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0}
.check-box{width:15px;height:15px;border:1.5px solid #94a3b8;border-radius:3px;display:inline-block;background:#fff}

.remarks-box{border:1px solid #e2e8f0;border-radius:8px;padding:12px 16px;margin-bottom:16px;min-height:40px;background:#f8fafc}
.remarks-lbl{font-size:9px;color:#64748b;text-transform:uppercase;letter-spacing:.6px;font-weight:600;margin-bottom:4px}

.sig-grid{display:grid;grid-template-columns:1fr 1fr 1fr;gap:24px;margin-top:24px;padding-top:16px;border-top:1px solid #e2e8f0}
.sig-box{padding-top:0}
.sig-role{font-size:9px;color:#64748b;text-transform:uppercase;letter-spacing:.5px;font-weight:600;margin-bottom:4px}
.sig-space{height:48px}
.sig-line{border-bottom:1px solid #cbd5e1;margin:0 0 6px}
.sig-name{font-size:10px;color:#94a3b8;font-style:italic}

.doc-footer{margin-top:20px;padding-top:10px;border-top:1px solid #e2e8f0;display:flex;justify-content:space-between;font-size:9px;color:#94a3b8}
`;

function fmtDateShort(iso?: string): string {
  if (!iso) return '—';
  const d = new Date(iso);
  if (isNaN(d.getTime())) return iso;
  const dd = String(d.getDate()).padStart(2, '0');
  const mm = String(d.getMonth() + 1).padStart(2, '0');
  return `${dd}/${mm}/${d.getFullYear()}`;
}

function nowTimestamp(): string {
  const d = new Date();
  const dd = String(d.getDate()).padStart(2, '0');
  const months = ['January','February','March','April','May','June','July','August','September','October','November','December'];
  return `${dd} ${months[d.getMonth()]} ${d.getFullYear()} ${String(d.getHours()).padStart(2,'0')}:${String(d.getMinutes()).padStart(2,'0')}`;
}

function fmtNum(v: unknown): string {
  const n = Number(v ?? 0);
  if (isNaN(n)) return '0';
  return n.toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
}

function escapeHtml(str: string): string {
  return str
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

function renderTaskHtml(task: ReplenishmentTaskData, pageBreak: boolean): string {
  const pb = pageBreak ? 'page-break-after:always' : '';
  return `
  <div class="document" style="${pb}">
    <div class="doc-header">
      <div class="logo-area">
        <div class="logo-mark">K</div>
        <div class="company-name">K<span>-one</span></div>
      </div>
      <div class="doc-title-block">
        <div class="doc-title">REPLENISHMENT SHEET</div>
        <div class="doc-subtitle">Move Stock to Pickface</div>
        <div class="doc-taskno">Task #${task.id}</div>
      </div>
    </div>

    <div class="info-grid">
      <div class="info-cell">
        <div class="lbl">Task No.</div>
        <div class="val" style="font-family:'SF Mono',Consolas,monospace">#${task.id}</div>
      </div>
      <div class="info-cell">
        <div class="lbl">Date</div>
        <div class="val">${fmtDateShort(task.created_at)}</div>
      </div>
      <div class="info-cell span2">
        <div class="lbl">Product / SKU</div>
        <div class="val" style="font-weight:700">${escapeHtml(task.product_name || '—')}</div>
        <div class="val" style="font-family:'SF Mono',Consolas,monospace;font-size:10px;color:#64748b">${escapeHtml(task.product_code)}</div>
      </div>
      <div class="info-cell">
        <div class="lbl">Linked Order</div>
        <div class="val" style="font-family:'SF Mono',Consolas,monospace">${escapeHtml(task.order_number ?? '—')}</div>
      </div>
      <div class="info-cell">
        <div class="lbl">Source Location</div>
        <div class="val" style="font-family:'SF Mono',Consolas,monospace">${escapeHtml(task.source_location)}</div>
      </div>
      <div class="info-cell">
        <div class="lbl">Destination</div>
        <div class="val" style="font-family:'SF Mono',Consolas,monospace">${escapeHtml(task.dest_location)}</div>
      </div>
      <div class="info-cell">
        <div class="lbl">Qty to Move</div>
        <div class="val" style="font-weight:800;font-size:14px">${fmtNum(task.qty)}</div>
      </div>
    </div>

    <div class="remarks-box">
      <div class="remarks-lbl">Remarks / Notes</div>
      <div style="font-size:8.5pt;color:#546e7a;min-height:16px"></div>
    </div>

    <div class="sig-grid">
      <div class="sig-box">
        <div class="sig-role">Picker / Petugas</div>
        <div class="sig-space"></div>
        <div class="sig-line"></div>
        <div class="sig-name">( ......................... )</div>
      </div>
      <div class="sig-box">
        <div class="sig-role">Checker / Verifier</div>
        <div class="sig-space"></div>
        <div class="sig-line"></div>
        <div class="sig-name">( ......................... )</div>
      </div>
      <div class="sig-box">
        <div class="sig-role">Supervisor</div>
        <div class="sig-space"></div>
        <div class="sig-line"></div>
        <div class="sig-name">( ......................... )</div>
      </div>
    </div>

    <div class="doc-footer">
      <span>K-one</span>
      <span>Printed: ${nowTimestamp()}</span>
      <span>Task #${task.id}</span>
    </div>
  </div>`;
}

/**
 * Opens a new window with professional A4-formatted replenishment sheets
 * and triggers the browser print dialog.
 *
 * Matches the visual format of print_picklist.php.
 */
export function printReplenishmentSheets(tasks: ReplenishmentTaskData[]): void {
  if (!tasks.length) return;

  const htmlParts = tasks.map((t, i) => renderTaskHtml(t, i < tasks.length - 1));

  const html = `<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Replenishment Sheet${tasks.length === 1 ? ` — Task #${tasks[0].id}` : ''}</title>
<style>${PICKLIST_CSS}</style>
</head>
<body>
${htmlParts.join('\n')}
<script>
window.onload = function() {
  setTimeout(function() { window.print(); }, 400);
};
<\/script>
</body>
</html>`;

  const w = window.open('', '_blank');
  if (!w) {
    // Fallback: blocked by popup blocker — try in-place
    const container = document.createElement('div');
    container.id = 'replen-print-container';
    container.innerHTML = html;
    container.style.cssText = 'position:fixed;left:0;top:0;z-index:9999;background:white;width:100%;height:100%';
    document.body.appendChild(container);
    window.print();
    setTimeout(() => document.body.removeChild(container), 1500);
    return;
  }

  w.document.write(html);
  w.document.close();
}

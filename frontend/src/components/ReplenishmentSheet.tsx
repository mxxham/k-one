import { Printer, X } from 'lucide-react';
import { fmtNum } from '@/lib/format';

// ─── Types ──────────────────────────────────────────────────────────────────

export interface ReplenishmentTaskData {
  id: number;
  product_code: string;
  product_name: string;
  source_location: string;
  dest_location: string;
  qty: number;
  order_number: string | null;
  created_at?: string;
}

export interface ReplenishmentItem {
  product_code: string;
  product_name: string;
  source_location: string;
  dest_location: string;
  qty: number;
  batch_no?: string;
}

// ─── CSS (copied from print_picklist.php) ───────────────────────────────────

const PICKLIST_CSS = `
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap');
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Inter',system-ui,sans-serif;font-size:10pt;color:#0f172a;background:#f1f5f9;line-height:1.5}
@page{size:A4 portrait;margin:0}
@media print{body{background:#fff;-webkit-print-color-adjust:exact;print-color-adjust:exact}.no-print{display:none!important}.document{box-shadow:none;margin:0;padding:12mm 14mm;border-radius:0}tr{page-break-inside:avoid}}

.print-bar{background:#0f2e2d;color:#fff;padding:12px 24px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:99}
.print-bar-title{font-weight:700;font-size:13px;display:flex;align-items:center;gap:8px}
.btns{display:flex;gap:8px}
.btn-print{background:#fff;color:#026766;border:none;padding:8px 18px;border-radius:6px;font-weight:700;cursor:pointer;font-size:12px}
.btn-back{background:rgba(255,255,255,.1);color:#fff;border:1px solid rgba(255,255,255,.3);padding:8px 14px;border-radius:6px;font-weight:600;font-size:12px;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center}

.document{max-width:794px;margin:20px auto;background:#fff;padding:28px 32px;box-shadow:0 1px 12px rgba(0,0,0,.06);border-radius:6px}
@media print{.document{box-shadow:none;margin:0;padding:14mm 14mm 16mm;max-width:100%;border-radius:0}}

/* Header */
.doc-header{display:flex;justify-content:space-between;align-items:center;padding-bottom:16px;margin-bottom:16px;border-bottom:2px solid #e2e8f0}
.logo-area{display:flex;align-items:center;gap:12px}
.logo-mark{width:44px;height:44px;background:linear-gradient(135deg,#026766,#013d3c);border-radius:10px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:20px;font-weight:800;flex-shrink:0}
.company-name{font-size:18px;font-weight:800;color:#0f172a;letter-spacing:-.5px}
.company-name span{color:#64748b;font-weight:400}
.doc-title-block{text-align:right}
.doc-title{font-size:20px;font-weight:800;color:#0f172a;letter-spacing:-.3px}
.doc-subtitle{font-size:11px;color:#64748b;margin-top:1px}
.doc-taskno{font-size:12px;font-weight:700;color:#334155;margin-top:4px;font-family:'SF Mono',Consolas,monospace}

/* Info grid */
.info-grid{display:grid;grid-template-columns:1fr 1fr 1fr;border:1px solid #e2e8f0;border-radius:8px;overflow:hidden;margin-bottom:16px}
.info-cell{padding:10px 14px;border-right:1px solid #e2e8f0;border-bottom:1px solid #e2e8f0;background:#fff}
.info-cell:nth-child(3n){border-right:none}
.info-cell:nth-last-child(-n+3){border-bottom:none}
.info-cell.span2{grid-column:span 2}
.info-cell .lbl{font-size:9px;color:#64748b;text-transform:uppercase;letter-spacing:.6px;font-weight:600;margin-bottom:2px}
.info-cell .val{font-size:12px;font-weight:600;color:#0f172a;line-height:1.6}
.info-cell .val .sub{font-size:10px;font-weight:400;color:#94a3b8}

.section-title{font-size:11px;font-weight:700;color:#334155;margin-bottom:10px;display:flex;align-items:center;gap:6px}
.section-title::before{content:'';display:block;width:3px;height:14px;background:#026766;border-radius:2px}

/* Table */
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
.chip-batch{background:#f1f5f9;color:#334155;border:1px solid #e2e8f0}
.chip-loc{background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0}
.check-box{width:15px;height:15px;border:1.5px solid #94a3b8;border-radius:3px;display:inline-block;background:#fff}

/* Notes */
.remarks-box{border:1px solid #e2e8f0;border-radius:8px;padding:12px 16px;margin-bottom:16px;min-height:40px;background:#f8fafc}
.remarks-lbl{font-size:9px;color:#64748b;text-transform:uppercase;letter-spacing:.6px;font-weight:600;margin-bottom:4px}

/* Signature */
.sig-grid{display:grid;grid-template-columns:1fr 1fr 1fr;gap:24px;margin-top:24px;padding-top:16px;border-top:1px solid #e2e8f0}
.sig-box{padding-top:0}
.sig-role{font-size:9px;color:#64748b;text-transform:uppercase;letter-spacing:.5px;font-weight:600;margin-bottom:4px}
.sig-space{height:48px}
.sig-line{border-bottom:1px solid #cbd5e1;margin:0 0 6px}
.sig-name{font-size:10px;color:#94a3b8;font-style:italic}

.doc-footer{margin-top:20px;padding-top:10px;border-top:1px solid #e2e8f0;display:flex;justify-content:space-between;font-size:9px;color:#94a3b8}
`;

// ─── Helpers ────────────────────────────────────────────────────────────────

function fmtDateShort(iso?: string): string {
  if (!iso) return '—';
  const d = new Date(iso);
  if (isNaN(d.getTime())) return iso;
  const dd = String(d.getDate()).padStart(2, '0');
  const mm = String(d.getMonth() + 1).padStart(2, '0');
  const yyyy = d.getFullYear();
  return `${dd}/${mm}/${yyyy}`;
}

function nowTimestamp(): string {
  const d = new Date();
  const dd = String(d.getDate()).padStart(2, '0');
  const months = ['January','February','March','April','May','June','July','August','September','October','November','December'];
  return `${dd} ${months[d.getMonth()]} ${d.getFullYear()} ${String(d.getHours()).padStart(2,'0')}:${String(d.getMinutes()).padStart(2,'0')}`;
}

// ─── Component ──────────────────────────────────────────────────────────────

/**
 * Printable replenishment sheet for warehouse tasks.
 *
 * Matches the professional A4 print format used in print_picklist.php.
 * Uses @media print CSS for clean output. Accepts optional items array
 * for the table rows.
 */
export default function ReplenishmentSheet({
  task,
  items,
}: {
  task: ReplenishmentTaskData;
  items?: ReplenishmentItem[];
}) {
  const doPrint = () => {
    window.print();
  };

  const doClose = () => {
    window.close();
  };

  const totalQty = items
    ? items.reduce((sum, it) => sum + (it.qty ?? 0), 0)
    : task.qty;

  return (
    <div>
      {/* ── Screen-only: Print Bar ──────────────────────────────────────── */}
      <div className="no-print print-bar">
        <div className="print-bar-title">
          Replenishment Sheet — Task #{task.id}
        </div>
        <div className="btns">
          <button className="btn-back" onClick={doClose}>
            <X className="w-3.5 h-3.5 mr-1" /> Close
          </button>
          <button className="btn-print" onClick={doPrint}>
            <Printer className="w-3.5 h-3.5 mr-1 inline" /> Print / PDF
          </button>
        </div>
      </div>

      {/* ── Document ────────────────────────────────────────────────────── */}
      <div className="document">
        {/* Header */}
        <div className="doc-header">
          <div className="logo-area">
            <div className="logo-mark">K</div>
            <div className="company-name">
              K<span>-one</span>
            </div>
          </div>
          <div className="doc-title-block">
            <div className="doc-title">REPLENISHMENT SHEET</div>
            <div className="doc-subtitle">Move Stock to Pickface</div>
            <div className="doc-taskno">Task #{task.id}</div>
          </div>
        </div>

        {/* Info Grid */}
        <div className="info-grid">
          <div className="info-cell">
            <div className="lbl">Task No.</div>
            <div
              className="val"
              style={{ fontFamily: "'SF Mono',Consolas,monospace" }}
            >
              #{task.id}
            </div>
          </div>
          <div className="info-cell">
            <div className="lbl">Date</div>
            <div className="val">{fmtDateShort(task.created_at)}</div>
          </div>
          <div className="info-cell span2">
            <div className="lbl">Product / SKU</div>
            <div className="val" style={{ fontWeight: 700 }}>
              {task.product_name || '—'}
            </div>
            <div
              className="val"
              style={{
                fontFamily: "'SF Mono',Consolas,monospace",
                fontSize: 10,
                color: '#64748b',
              }}
            >
              {task.product_code}
            </div>
          </div>
          <div className="info-cell">
            <div className="lbl">Linked Order</div>
            <div
              className="val"
              style={{ fontFamily: "'SF Mono',Consolas,monospace" }}
            >
              {task.order_number ?? '—'}
            </div>
          </div>
          <div className="info-cell">
            <div className="lbl">Source Location</div>
            <div
              className="val"
              style={{ fontFamily: "'SF Mono',Consolas,monospace" }}
            >
              {task.source_location}
            </div>
          </div>
          <div className="info-cell">
            <div className="lbl">Destination</div>
            <div
              className="val"
              style={{ fontFamily: "'SF Mono',Consolas,monospace" }}
            >
              {task.dest_location}
            </div>
          </div>
          <div className="info-cell">
            <div className="lbl">Qty to Move</div>
            <div className="val" style={{ fontWeight: 800, fontSize: 14 }}>
              {fmtNum(totalQty)}
            </div>
          </div>
        </div>

        {/* Items Table (if provided) */}
        {items && items.length > 0 && (
          <>
            <div className="section-title">Items to Move</div>
            <table>
              <thead>
                <tr>
                  <th className="c" style={{ width: 32 }}>
                    No.
                  </th>
                  <th>Product</th>
                  <th>Source Bin</th>
                  <th>Destination Bin</th>
                  <th className="r" style={{ width: 60 }}>
                    Qty
                  </th>
                  <th className="c" style={{ width: 40 }}>
                    Pick
                  </th>
                </tr>
              </thead>
              <tbody>
                {items.map((item, idx) => (
                  <tr key={idx}>
                    <td className="c" style={{ color: '#94a3b8', fontSize: 10 }}>
                      {idx + 1}
                    </td>
                    <td>
                      <div
                        style={{
                          fontWeight: 700,
                          fontSize: 10,
                          color: '#0f172a',
                        }}
                      >
                        {item.product_name || '—'}
                      </div>
                      <div
                        style={{
                          fontFamily: "'SF Mono',Consolas,monospace",
                          fontSize: 9,
                          color: '#64748b',
                        }}
                      >
                        {item.product_code}
                      </div>
                    </td>
                    <td>
                      {item.source_location ? (
                        <span className="chip chip-loc">
                          {item.source_location}
                        </span>
                      ) : (
                        <span
                          style={{
                            display: 'inline-block',
                            minWidth: 70,
                            borderBottom: '1.5px dashed #cbd5e1',
                            height: 18,
                          }}
                        />
                      )}
                    </td>
                    <td>
                      {item.dest_location ? (
                        <span className="chip chip-loc">
                          {item.dest_location}
                        </span>
                      ) : (
                        <span
                          style={{
                            display: 'inline-block',
                            minWidth: 70,
                            borderBottom: '1.5px dashed #cbd5e1',
                            height: 18,
                          }}
                        />
                      )}
                    </td>
                    <td className="r" style={{ fontWeight: 700 }}>
                      {fmtNum(item.qty)}
                    </td>
                    <td className="c">
                      <span className="check-box" />
                    </td>
                  </tr>
                ))}
              </tbody>
              <tfoot>
                <tr>
                  <td colSpan={4} style={{ textAlign: 'right', paddingRight: 10 }}>
                    TOTAL
                  </td>
                  <td className="r">{fmtNum(totalQty)}</td>
                  <td />
                </tr>
              </tfoot>
            </table>
          </>
        )}

        {/* Notes */}
        <div className="remarks-box">
          <div className="remarks-lbl">Remarks / Notes</div>
          <div style={{ fontSize: '8.5pt', color: '#546e7a', minHeight: 16 }} />
        </div>

        {/* Signatures */}
        <div className="sig-grid">
          <div className="sig-box">
            <div className="sig-role">Picker / Petugas</div>
            <div className="sig-space" />
            <div className="sig-line" />
            <div className="sig-name">( ......................... )</div>
          </div>
          <div className="sig-box">
            <div className="sig-role">Checker / Verifier</div>
            <div className="sig-space" />
            <div className="sig-line" />
            <div className="sig-name">( ......................... )</div>
          </div>
          <div className="sig-box">
            <div className="sig-role">Supervisor</div>
            <div className="sig-space" />
            <div className="sig-line" />
            <div className="sig-name">( ......................... )</div>
          </div>
        </div>

        {/* Footer */}
        <div className="doc-footer">
          <span>K-one</span>
          <span>Printed: {nowTimestamp()}</span>
          <span>Task #{task.id}</span>
        </div>
      </div>

      {/* ── Embedded CSS ─────────────────────────────────────────────────── */}
      <style>{PICKLIST_CSS}</style>
      <style>{`
        @media print {
          body * { visibility: hidden; }
          .document, .document * { visibility: visible; }
          .document {
            position: absolute;
            left: 0;
            top: 0;
            width: 210mm;
            max-width: 100%;
            margin: 0;
            padding: 14mm 14mm 16mm;
            border-radius: 0;
            box-shadow: none;
          }
          .no-print { display: none !important; }
          @page { size: A4 portrait; margin: 0; }
        }
      `}</style>
    </div>
  );
}

import { useEffect, useRef } from 'react';
import { QRCodeSVG } from 'qrcode.react';
import { Printer } from 'lucide-react';
import { fmtNum } from '@/lib/format';
import type { LpnLabelData } from './LpnLabel';

const PER_PAGE = 2;

export default function LpnLabelSheet({ labels, onPrint }: { labels: LpnLabelData[]; onPrint?: () => void }) {
  const portalRef = useRef<HTMLDivElement | null>(null);

  const pages: LpnLabelData[][] = [];
  for (let i = 0; i < labels.length; i += PER_PAGE) {
    pages.push(labels.slice(i, i + PER_PAGE));
  }

  useEffect(() => {
    const el = document.getElementById('lpn-print-portal') as HTMLDivElement | null;
    const target = el ?? document.createElement('div');
    if (!el) {
      target.id = 'lpn-print-portal';
      document.body.appendChild(target);
    }
    portalRef.current = target;

    target.innerHTML = `<div class="lpn-sheet-print-area">${
      pages.map((page) =>
        `<div class="lpn-page">${page.map((l) => renderLabel(l)).join('')}</div>`
      ).join('')
    }</div>`;

    return () => { target.innerHTML = ''; };
  }, [labels]);

  const doPrint = () => {
    onPrint?.();
    window.print();
  };

  return (
    <div>
      <div className="flex flex-col gap-4 mb-4">
        {labels.map((label, i) => (
          <PreviewCard key={label.lpn_code ?? i} label={label} />
        ))}
      </div>

      <div className="flex justify-center gap-2 no-print">
        <button
          onClick={doPrint}
          className="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg bg-brand-600 text-white text-sm font-semibold hover:bg-brand-700"
        >
          <Printer className="w-4 h-4" /> Cetak {labels.length} Label ({pages.length} halaman A4)
        </button>
      </div>
    </div>
  );
}

function PreviewCard({ label }: { label: LpnLabelData }) {
  return (
    <div className="bg-white text-black rounded-xl border-2 border-gray-900 overflow-hidden w-full">
      <div className="flex items-center justify-between bg-gray-900 text-white px-4 py-1.5">
        <span className="text-xs font-bold tracking-[0.22em]">PT. K-ONE</span>
        <span className="text-[10px] font-semibold tracking-[0.15em] text-gray-300">LABEL PALLET / LPN</span>
      </div>
      <div className="flex" style={{ minHeight: 280 }}>
        <div className="flex flex-col items-center justify-center shrink-0 w-[25%] border-r-2 border-dashed border-gray-300 px-3 py-4">
          <QRCodeSVG value={label.lpn_code} size={120} level="M" includeMargin={false} />
          <div className="text-[9px] font-mono font-bold tracking-wide mt-2 text-center break-all">{label.lpn_code}</div>
        </div>
        <div className="flex-1 min-w-0 flex flex-col px-4 py-3">
          <div className="flex-1 flex flex-col justify-center pb-2 mb-2 border-b-2 border-gray-900">
            <div className="text-[8px] font-bold text-gray-500 tracking-[0.16em] uppercase mb-0.5">LOKASI</div>
            <div className="text-5xl font-black text-gray-900 leading-none break-words">
              {label.suggested_location ?? '—'}
            </div>
          </div>
          <div className="flex gap-3 items-start">
            <div className="flex-1 min-w-0">
              <div className="text-sm font-black leading-snug break-words">
                {label.product_name || label.product_code || '—'}
              </div>
              {label.product_name && label.product_code && (
                <div className="text-[9px] font-mono text-gray-500 break-all mt-0.5">{label.product_code}</div>
              )}
            </div>
            <div className="flex gap-2.5 flex-shrink-0">
              <PreviewField label="Batch" value={label.batch_number} />
              <PreviewField label="Exp" value={label.expiry_date} />
              <PreviewField label="Qty" value={`${fmtNum(label.quantity)} ${label.uom || ''}`.trim()} />
              <PreviewField label="Pallet" value={`#${label.pallet_seq}`} />
              <PreviewField label="Task" value={label.task_number} />
            </div>
          </div>
          {label.order_number && (
            <div className="mt-1.5 pt-1.5 border-t border-dashed border-gray-300 flex justify-between text-[9px]">
              <span className="text-gray-400 font-semibold tracking-wide">ORDER</span>
              <span className="font-mono font-semibold text-gray-700 break-all">{label.order_number}</span>
            </div>
          )}
        </div>
      </div>
    </div>
  );
}

function PreviewField({ label: fieldLabel, value }: { label: string; value: React.ReactNode }) {
  return (
    <div>
      <div className="font-semibold text-gray-400 tracking-wide uppercase leading-none mb-0.5 text-[8px]">{fieldLabel}</div>
      <div className="font-semibold text-gray-900 break-words leading-snug text-[11px]">{value ?? '—'}</div>
    </div>
  );
}

function renderLabel(l: LpnLabelData): string {
  const product = l.product_name || l.product_code || '—';
  const code = l.product_name && l.product_code
    ? `<div style="font-size:10px;font-family:monospace;color:#6b7280;word-break:break-all;margin-top:2px;line-height:1.3">${l.product_code}</div>`
    : '';
  const order = l.order_number
    ? `<div style="padding-top:4px;border-top:1px dashed #d1d5db;display:flex;align-items:center;justify-content:space-between;gap:6px"><span style="font-size:8px;color:#9ca3af;font-weight:600;letter-spacing:0.14em;white-space:nowrap">ORDER</span><span style="font-size:10px;font-family:monospace;font-weight:600;color:#374151;word-break:break-all;text-align:right">${l.order_number}</span></div>`
    : '';
  const f = (k: string, v: React.ReactNode) =>
    `<div style="flex-shrink:0"><div style="font-size:8px;font-weight:600;color:#9ca3af;letter-spacing:0.14em;text-transform:uppercase;line-height:1;margin-bottom:2px">${k}</div><div style="font-size:12px;font-weight:600;color:#111827;word-break:break-word;line-height:1.2">${v ?? '—'}</div></div>`;

  return `<div class="lpn-card" style="background:white;color:black;border-radius:8px;border:3px solid #111;overflow:hidden;width:277mm;height:93mm;box-sizing:border-box;margin-bottom:4mm">
    <div style="display:flex;align-items:center;justify-content:space-between;background:#111;color:white;padding:5px 16px">
      <span style="font-size:11px;font-weight:900;letter-spacing:0.22em;white-space:nowrap">PT. K-ONE</span>
      <span style="font-size:9px;font-weight:600;letter-spacing:0.15em;color:#d1d5db;white-space:nowrap">LABEL PALLET / LPN</span>
    </div>
    <div style="display:flex;height:calc(93mm - 28px);box-sizing:border-box">
      <div style="display:flex;flex-direction:column;align-items:center;justify-content:center;flex-shrink:0;width:25%;border-right:2px dashed #d1d5db;padding:8px 12px">
        <img src="https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=${encodeURIComponent(l.lpn_code)}" width="150" height="150" />
        <div style="font-size:10px;font-family:monospace;font-weight:700;letter-spacing:0.08em;margin-top:6px;text-align:center;line-height:1.3;word-break:break-all">${l.lpn_code}</div>
      </div>
      <div style="flex:1;min-width:0;display:flex;flex-direction:column;padding:8px 16px">
        <div style="flex:1;display:flex;flex-direction:column;justify-content:center;padding-bottom:6px;margin-bottom:6px;border-bottom:2px solid #111">
          <div style="font-size:9px;font-weight:700;color:#6b7280;letter-spacing:0.16em;text-transform:uppercase;margin-bottom:2px">LOKASI</div>
          <div style="font-size:64px;font-weight:900;color:#111;line-height:0.95;word-break:break-word">${l.suggested_location ?? '—'}</div>
        </div>
        <div style="display:flex;gap:12px;align-items:flex-start">
          <div style="flex:1;min-width:0">
            <div style="font-size:12px;font-weight:900;line-height:1.2;word-break:break-word">${product}</div>${code}
          </div>
          <div style="display:flex;gap:10px;flex-shrink:0;align-items:flex-start">
            ${f('Batch', l.batch_number)}${f('Exp', l.expiry_date)}${f('Qty', `${fmtNum(l.quantity)} ${l.uom || ''}`.trim())}${f('Pallet', `#${l.pallet_seq}`)}${f('Task', l.task_number)}
          </div>
        </div>${order}
      </div>
    </div>
  </div>`;
}

import { useEffect, useRef } from 'react';
import { QRCodeSVG } from 'qrcode.react';
import { Printer } from 'lucide-react';
import { fmtNum } from '@/lib/format';
import type { LpnLabelData } from './LpnLabel';

const PER_PAGE = 1;

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
    <div className="bg-white text-black rounded-xl border-2 border-gray-900 overflow-hidden w-full max-w-[1000px] mx-auto">
      <div className="flex items-center justify-between bg-gray-900 text-white px-6 py-2.5">
        <span className="text-base font-bold tracking-[0.22em]">PT. K-ONE</span>
        <span className="text-sm font-semibold tracking-[0.15em] text-gray-300">LABEL PALLET / LPN</span>
      </div>
      <div className="flex" style={{ minHeight: 400 }}>
        <div className="flex flex-col items-center justify-center shrink-0 w-[25%] border-r-2 border-dashed border-gray-300 px-6 py-6">
          <QRCodeSVG value={label.lpn_code} size={200} level="M" includeMargin={false} />
          <div className="text-base font-mono font-bold tracking-wide mt-3 text-center break-all">{label.lpn_code}</div>
        </div>
        <div className="flex-1 min-w-0 flex flex-col px-6 py-5">
          <div className="flex-1 flex flex-col justify-start pt-2 pb-6 mb-6 border-b-2 border-gray-900">
            <div className="text-[40px] font-black leading-none break-words">
              {label.product_name || label.product_code || '—'}
            </div>
            {label.product_name && label.product_code && (
              <div className="text-[15px] font-mono text-gray-500 break-all mt-1 leading-tight">{label.product_code}</div>
            )}
          </div>
          <div className="flex gap-6 items-start">
            <div className="flex-1 min-w-0">
              <div className="text-sm font-bold text-gray-500 tracking-[0.16em] uppercase mb-1">LOKASI</div>
              <div className="text-[64px] font-black text-gray-900 leading-none whitespace-nowrap overflow-hidden text-ellipsis">
                {label.suggested_location ?? '—'}
              </div>
            </div>
            <div className="flex gap-4 flex-shrink-0">
              <PreviewField label="Qty" value={`${fmtNum(label.quantity)} ${label.uom || ''}`.trim()} />
              <PreviewField label="Pallet" value={`#${label.pallet_seq}`} />
              <PreviewField label="Batch" value={label.batch_number} />
              <PreviewField label="Exp" value={label.expiry_date} />
              <PreviewField label="Task" value={label.task_number} />
            </div>
          </div>
          {label.order_number && (
            <div className="mt-3 pt-3 border-t border-dashed border-gray-300 flex justify-between text-sm">
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
  const isQty = fieldLabel === 'Qty';
  return (
    <div>
      <div className="font-semibold text-gray-400 tracking-wide uppercase leading-none mb-1 text-sm">{fieldLabel}</div>
      <div className={`font-semibold text-gray-900 break-words leading-snug ${isQty ? 'text-[40px]' : 'text-[32px]'}`}>{value ?? '—'}</div>
    </div>
  );
}

function renderLabel(l: LpnLabelData): string {
  const product = l.product_name || l.product_code || '—';
  const code = l.product_name && l.product_code
    ? `<div style="font-size:15px;font-family:monospace;color:#6b7280;word-break:break-all;margin-top:4px;line-height:1.1">${l.product_code}</div>`
    : '';
  const order = l.order_number
    ? `<div style="padding-top:8px;border-top:1px dashed #d1d5db;display:flex;align-items:center;justify-content:space-between;gap:8px"><span style="font-size:14px;color:#9ca3af;font-weight:600;letter-spacing:0.14em;white-space:nowrap">ORDER</span><span style="font-size:16px;font-family:monospace;font-weight:600;color:#374151;word-break:break-all;text-align:right">${l.order_number}</span></div>`
    : '';
  const f = (k: string, v: React.ReactNode) => {
    const isQty = k === 'Qty';
    const valSize = isQty ? '40px' : '32px';
    return `<div style="flex-shrink:0"><div style="font-size:14px;font-weight:600;color:#9ca3af;letter-spacing:0.14em;text-transform:uppercase;line-height:1;margin-bottom:4px">${k}</div><div style="font-size:${valSize};font-weight:600;color:#111827;word-break:break-word;line-height:1.1">${v ?? '—'}</div></div>`;
  };

  return `<div class="lpn-card" style="background:white;color:black;border-radius:8px;border:3px solid #111;overflow:hidden;width:277mm;height:190mm;box-sizing:border-box;margin-bottom:4mm">
    <div style="display:flex;align-items:center;justify-content:space-between;background:#111;color:white;padding:8px 20px">
      <span style="font-size:14px;font-weight:900;letter-spacing:0.22em;white-space:nowrap">PT. K-ONE</span>
      <span style="font-size:12px;font-weight:600;letter-spacing:0.15em;color:#d1d5db;white-space:nowrap">LABEL PALLET / LPN</span>
    </div>
    <div style="display:flex;height:calc(190mm - 40px);box-sizing:border-box">
      <div style="display:flex;flex-direction:column;align-items:center;justify-content:center;flex-shrink:0;width:25%;border-right:2px dashed #d1d5db;padding:12px 16px">
        <img src="https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=${encodeURIComponent(l.lpn_code)}" width="200" height="200" />
        <div style="font-size:16px;font-family:monospace;font-weight:700;letter-spacing:0.08em;margin-top:10px;text-align:center;line-height:1.3;word-break:break-all">${l.lpn_code}</div>
      </div>
      <div style="flex:1;min-width:0;display:flex;flex-direction:column;padding:12px 20px">
        <div style="flex:1;display:flex;flex-direction:column;justify-content:flex-start;padding-top:8px;padding-bottom:16px;margin-bottom:16px;border-bottom:3px solid #111">
          <div style="font-size:40px;font-weight:900;color:#111;line-height:0.95;word-break:break-word">${product}</div>${code}
        </div>
        <div style="display:flex;gap:16px;align-items:flex-start">
          <div style="flex:1;min-width:0">
            <div style="font-size:14px;font-weight:700;color:#6b7280;letter-spacing:0.16em;text-transform:uppercase;margin-bottom:4px">LOKASI</div>
            <div style="font-size:64px;font-weight:900;color:#111;line-height:0.95;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${l.suggested_location ?? '—'}</div>
          </div>
          <div style="display:flex;gap:14px;flex-shrink:0;align-items:flex-start">
            ${f('Qty', `${fmtNum(l.quantity)} ${l.uom || ''}`.trim())}${f('Pallet', `#${l.pallet_seq}`)}${f('Batch', l.batch_number)}${f('Exp', l.expiry_date)}${f('Task', l.task_number)}
          </div>
        </div>${order}
      </div>
    </div>
  </div>`;
}

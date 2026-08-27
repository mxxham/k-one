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
    <div className="bg-white text-black rounded-xl border-2 border-gray-900 overflow-hidden max-w-[640px] mx-auto w-full">
      <div className="flex items-center justify-between bg-gray-900 text-white px-4 py-2">
        <span className="text-sm font-bold tracking-[0.22em]">PT. K-ONE</span>
        <span className="text-xs font-semibold tracking-[0.15em] text-gray-300">LABEL PALLET / LPN</span>
      </div>
      <div className="p-4">
        <div className="flex gap-4">
          <div className="flex flex-col items-center shrink-0 w-[140px] border-r-2 border-dashed border-gray-300 pr-4">
            <QRCodeSVG value={label.lpn_code} size={120} level="M" includeMargin={false} />
            <div className="text-[10px] font-mono font-bold tracking-wide mt-2 text-center break-all">{label.lpn_code}</div>
          </div>
          <div className="flex-1 min-w-0">
            <div className="pb-2 mb-2 border-b-2 border-dashed border-gray-300">
              <div className="text-base font-black leading-snug break-words">{label.product_name || label.product_code || '—'}</div>
              {label.product_name && label.product_code && (
                <div className="text-xs font-mono text-gray-500 break-all mt-0.5">{label.product_code}</div>
              )}
            </div>
            <div className="grid grid-cols-3 gap-x-3 gap-y-2 text-[11px]">
              <PreviewField label="Batch" value={label.batch_number} />
              <PreviewField label="Exp" value={label.expiry_date} />
              <PreviewField label="Qty" value={`${fmtNum(label.quantity)} ${label.uom || ''}`.trim()} />
              <PreviewField label="Pallet" value={`#${label.pallet_seq}`} />
              <PreviewField label="Lokasi" value={label.suggested_location} />
              <PreviewField label="Task" value={label.task_number} />
            </div>
            {label.order_number && (
              <div className="mt-2 pt-2 border-t-2 border-dashed border-gray-300 flex justify-between text-[10px]">
                <span className="text-gray-400 font-semibold tracking-wide">ORDER</span>
                <span className="font-mono font-semibold text-gray-700 break-all">{label.order_number}</span>
              </div>
            )}
          </div>
        </div>
      </div>
    </div>
  );
}

function PreviewField({ label: fieldLabel, value }: { label: string; value: React.ReactNode }) {
  return (
    <div>
      <div className="font-semibold text-gray-400 tracking-wide uppercase leading-none mb-0.5">{fieldLabel}</div>
      <div className="font-semibold text-gray-900 break-words leading-snug">{value ?? '—'}</div>
    </div>
  );
}

function renderLabel(l: LpnLabelData): string {
  const product = l.product_name || l.product_code || '—';
  const code = l.product_name && l.product_code
    ? `<div style="font-size:12px;font-family:monospace;color:#6b7280;word-break:break-all;margin-top:4px;line-height:1.3">${l.product_code}</div>`
    : '';
  const order = l.order_number
    ? `<div style="margin-top:12px;padding-top:8px;border-top:2px dashed #d1d5db;display:flex;align-items:center;justify-content:space-between;gap:8px"><span style="font-size:10px;color:#9ca3af;font-weight:600;letter-spacing:0.14em;white-space:nowrap">ORDER</span><span style="font-size:13px;font-family:monospace;font-weight:600;color:#374151;word-break:break-all;text-align:right">${l.order_number}</span></div>`
    : '';
  const f = (k: string, v: React.ReactNode) =>
    `<div><div style="font-size:10px;font-weight:600;color:#9ca3af;letter-spacing:0.14em;text-transform:uppercase;line-height:1;margin-bottom:4px">${k}</div><div style="font-size:16px;font-weight:600;color:#111827;word-break:break-word;line-height:1.3">${v ?? '—'}</div></div>`;

  return `<div class="lpn-card" style="background:white;color:black;border-radius:12px;border:3px solid #111;overflow:hidden;width:200mm;height:100mm;box-sizing:border-box;margin-bottom:5mm">
    <div style="display:flex;align-items:center;justify-content:space-between;background:#111;color:white;padding:8px 20px">
      <span style="font-size:14px;font-weight:900;letter-spacing:0.22em;white-space:nowrap">PT. K-ONE</span>
      <span style="font-size:11px;font-weight:600;letter-spacing:0.15em;color:#d1d5db;white-space:nowrap">LABEL PALLET / LPN</span>
    </div>
    <div style="padding:16px 20px;height:calc(100mm - 38px);box-sizing:border-box">
      <div style="display:flex;gap:20px;height:100%">
        <div style="display:flex;flex-direction:column;align-items:center;justify-content:center;flex-shrink:0;width:210px;border-right:2px dashed #d1d5db;padding-right:20px">
          <img src="https://api.qrserver.com/v1/create-qr-code/?size=170x170&data=${encodeURIComponent(l.lpn_code)}" width="170" height="170" />
          <div style="font-size:13px;font-family:monospace;font-weight:700;letter-spacing:0.1em;margin-top:12px;text-align:center;line-height:1.3;word-break:break-all">${l.lpn_code}</div>
        </div>
        <div style="flex:1;min-width:0;display:flex;flex-direction:column;justify-content:center">
          <div style="padding-bottom:12px;margin-bottom:12px;border-bottom:2px dashed #d1d5db">
            <div style="font-size:19px;font-weight:900;line-height:1.3;word-break:break-word">${product}</div>${code}
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:20px 12px">
            ${f('Batch', l.batch_number)}${f('Exp', l.expiry_date)}${f('Qty', `${fmtNum(l.quantity)} ${l.uom || ''}`.trim())}${f('Pallet', `#${l.pallet_seq}`)}${f('Lokasi', l.suggested_location)}${f('Task', l.task_number)}
          </div>${order}
        </div>
      </div>
    </div>
  </div>`;
}

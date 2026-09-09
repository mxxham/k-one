import { Printer } from 'lucide-react';
import { QRCodeSVG } from 'qrcode.react';
import { fmtNum } from '@/lib/format';

export interface LpnLabelData {
  lpn_code: string;
  product_code: string | null;
  product_name: string | null;
  batch_number: string | null;
  uom: string | null;
  quantity: number;
  pallet_seq: number;
  suggested_location: string | null;
  expiry_date: string | null;
  task_number: string | null;
  order_number: string | null;
}

/**
 * Printable LPN label (Step 1 / S46). The QR code is rendered client-side
 * with qrcode.react; window.print() prints ONLY the .lpn-print-area via
 * the @media print CSS in index.css.
 */
export default function LpnLabel({ label, onPrint }: { label: LpnLabelData; onPrint?: () => void }) {
  const doPrint = () => {
    onPrint?.();
    window.print();
  };

  return (
    <div>
      <div className="lpn-print-area bg-white text-black rounded-xl border-2 border-gray-900 w-full max-w-[1000px] mx-auto overflow-hidden print:rounded-none">
        <div className="flex items-center justify-between bg-gray-900 text-white px-6 py-2">
          <span className="text-base font-bold tracking-[0.2em] whitespace-nowrap">PT. K-ONE</span>
          <span className="text-sm font-semibold tracking-[0.15em] text-gray-300 whitespace-nowrap">
            LABEL PALLET / LPN
          </span>
        </div>

        <div className="flex" style={{ minHeight: 400 }}>
          <div className="flex flex-col items-center justify-center shrink-0 w-[25%] border-r-2 border-dashed border-gray-300 px-6 py-6">
            <div className="p-2 border border-gray-200 rounded-md bg-white">
              <QRCodeSVG
                value={label.lpn_code}
                size={200}
                level="M"
                includeMargin={false}
              />
            </div>
            <div className="text-base font-mono font-bold tracking-[0.08em] mt-3 text-center leading-tight break-all">
              {label.lpn_code}
            </div>
          </div>

          <div className="flex-1 min-w-0 flex flex-col px-6 py-5">
            <div className="flex-1 flex flex-col justify-center pb-6 mb-6 border-b-2 border-gray-900">
              <div className="text-[40px] font-black leading-none break-words">
                {label.product_name || label.product_code || '—'}
              </div>
              {label.product_name && label.product_code && (
                <div className="text-[30px] font-mono text-gray-500 break-all mt-1 leading-tight">
                  {label.product_code}
                </div>
              )}
              <div className="mt-2">
                <div className="text-sm font-bold text-gray-500 tracking-[0.16em] uppercase mb-1">LOKASI</div>
                <div className="text-[64px] font-black text-gray-900 leading-none break-words">
                  {label.suggested_location ?? '—'}
                </div>
              </div>
            </div>

            <div className="flex gap-4 flex-shrink-0">
              <CompactField label="Qty" value={`${fmtNum(label.quantity)} ${label.uom || ''}`.trim()} />
              <CompactField label="Pallet" value={`#${label.pallet_seq}`} />
              <CompactField label="Batch" value={label.batch_number} />
              <CompactField label="Exp" value={label.expiry_date} />
              <CompactField label="Task" value={label.task_number} />
            </div>

            {label.order_number && (
              <div className="mt-3 pt-3 border-t border-dashed border-gray-300 flex items-center justify-between gap-2 text-sm">
                <span className="text-gray-400 font-semibold tracking-[0.15em] whitespace-nowrap">ORDER</span>
                <span className="font-mono font-semibold text-gray-700 break-all text-right">
                  {label.order_number}
                </span>
              </div>
            )}
          </div>
        </div>
      </div>

      <div className="flex justify-center gap-2 mt-4 no-print">
        <button
          onClick={doPrint}
          className="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg bg-brand-600 text-white text-sm font-semibold hover:bg-brand-700"
        >
          <Printer className="w-4 h-4" /> Cetak Label LPN
        </button>
        <button
          onClick={doPrint}
          className="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg bg-gray-100 text-gray-700 text-sm font-semibold hover:bg-gray-200"
        >
          <Printer className="w-4 h-4" /> Cetak Ulang
        </button>
      </div>
    </div>
  );
}

function CompactField({ label: fieldLabel, value }: { label: string; value: React.ReactNode }) {
  const isQty = fieldLabel === 'Qty';
  return (
    <div>
      <div className="text-sm font-semibold text-gray-400 tracking-[0.12em] uppercase leading-none mb-1">
        {fieldLabel}
      </div>
      <div className={`font-semibold text-gray-900 break-words leading-snug ${isQty ? 'text-[40px]' : 'text-[32px]'}`}>
        {value ?? '—'}
      </div>
    </div>
  );
}
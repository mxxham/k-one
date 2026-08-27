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

  const Field = ({ label: fieldLabel, value }: { label: string; value: React.ReactNode }) => (
    <div className="min-w-0">
      <div className="text-[7px] font-semibold text-gray-400 tracking-[0.12em] uppercase leading-none mb-1">
        {fieldLabel}
      </div>
      <div className="text-[10px] font-semibold text-gray-900 break-words leading-snug">
        {value ?? '—'}
      </div>
    </div>
  );

  return (
    <div>
      <div className="lpn-print-area bg-white text-black rounded-xl border-2 border-gray-900 w-[380px] mx-auto overflow-hidden print:rounded-none">
        {/* HEADER STRIP */}
        <div className="flex items-center justify-between gap-3 bg-gray-900 text-white px-3 py-1.5">
          <span className="text-[9px] font-bold tracking-[0.2em] whitespace-nowrap">PT. K-ONE</span>
          <span className="text-[7px] font-semibold tracking-[0.15em] text-gray-300 whitespace-nowrap">
            LABEL PALLET / LPN
          </span>
        </div>

        <div className="px-3 py-3">
          <div className="flex gap-3 items-stretch">
            {/* LEFT: QR Code (bigger, own column) */}
            <div className="flex flex-col items-center justify-center shrink-0 w-[140px] border-r border-dashed border-gray-300 pr-3">
              <div className="p-1.5 border border-gray-200 rounded-md bg-white">
                <QRCodeSVG
                  value={label.lpn_code}
                  size={124}
                  level="M"
                  includeMargin={false}
                />
              </div>
              <div className="text-[8px] font-mono font-bold tracking-[0.08em] mt-2 text-center leading-tight break-all">
                {label.lpn_code}
              </div>
            </div>

            {/* RIGHT: Info */}
            <div className="flex-1 min-w-0 flex flex-col justify-center">
              {/* Product block */}
              <div className="pb-2 mb-2 border-b border-dashed border-gray-300">
                <div className="text-[10.5px] font-black leading-snug break-words">
                  {label.product_name || label.product_code || '—'}
                </div>
                {label.product_name && label.product_code && (
                  <div className="text-[8px] font-mono text-gray-500 break-all mt-0.5 leading-tight">
                    {label.product_code}
                  </div>
                )}
              </div>

              {/* Field grid */}
              <div className="grid grid-cols-2 gap-x-3 gap-y-2">
                <Field label="Batch" value={label.batch_number} />
                <Field label="Exp" value={label.expiry_date} />
                <Field label="Qty" value={`${fmtNum(label.quantity)} ${label.uom || ''}`.trim()} />
                <Field label="Pallet" value={`#${label.pallet_seq}`} />
                <Field label="Lokasi" value={label.suggested_location} />
                <Field label="Task" value={label.task_number} />
              </div>
            </div>
          </div>

          {label.order_number && (
            <div className="mt-2.5 pt-2 border-t border-dashed border-gray-300 flex items-center justify-between gap-2 text-[8px]">
              <span className="text-gray-400 font-semibold tracking-[0.15em] whitespace-nowrap">ORDER</span>
              <span className="font-mono font-semibold text-gray-700 break-all text-right">
                {label.order_number}
              </span>
            </div>
          )}
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
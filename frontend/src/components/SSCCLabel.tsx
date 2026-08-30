import { Printer } from 'lucide-react';
import GS1Barcode from './GS1Barcode';
import { buildSSCCBarcode, formatGS1HumanReadable } from '@/lib/gs1';

export interface SSCCLabelData {
  sscc: string;
  po_number?: string | null;
  sender_name?: string | null;
  receiver_name?: string | null;
  location?: string | null;
  item_count?: number;
}

export interface SSCCLabelProps {
  label: SSCCLabelData;
  onPrint?: () => void;
}

/**
 * SSCC (Serial Shipping Container Code) label component.
 *
 * Renders a printable SSCC label with GS1-128 barcode, human-readable
 * encoded data, and shipment details.
 */
export default function SSCCLabel({ label, onPrint }: SSCCLabelProps) {
  const doPrint = () => {
    onPrint?.();
    window.print();
  };

  // Build GS1 barcode data
  let barcodeValue = label.sscc?.replace(/\D/g, '') || '';
  let humanReadable = barcodeValue;
  try {
    if (barcodeValue.length === 18) {
      barcodeValue = buildSSCCBarcode(barcodeValue);
      humanReadable = formatGS1HumanReadable(barcodeValue);
    }
  } catch {
    // Use raw SSCC as fallback
  }

  return (
    <div>
      <div className="sscc-print-area bg-white text-black rounded-xl border-2 border-gray-900 w-[400px] mx-auto overflow-hidden print:rounded-none">
        {/* Header */}
        <div className="flex items-center justify-between bg-gray-900 text-white px-4 py-2">
          <span className="text-sm font-bold tracking-[0.22em]">SSCC</span>
          <span className="text-xs font-semibold tracking-[0.15em] text-gray-300">
            SERIAL SHIPPING CONTAINER CODE
          </span>
        </div>

        <div className="p-4">
          {/* Shipment Details */}
          <div className="grid grid-cols-2 gap-x-4 gap-y-2 text-[11px] mb-3">
            <div>
              <div className="text-[9px] font-semibold text-gray-400 tracking-wide uppercase">PO Number</div>
              <div className="font-semibold text-gray-900">{label.po_number || '—'}</div>
            </div>
            <div>
              <div className="text-[9px] font-semibold text-gray-400 tracking-wide uppercase">Item Count</div>
              <div className="font-semibold text-gray-900">{label.item_count ?? 0}</div>
            </div>
            <div>
              <div className="text-[9px] font-semibold text-gray-400 tracking-wide uppercase">Sender</div>
              <div className="font-semibold text-gray-900">{label.sender_name || 'PT. K-ONE'}</div>
            </div>
            <div>
              <div className="text-[9px] font-semibold text-gray-400 tracking-wide uppercase">Receiver</div>
              <div className="font-semibold text-gray-900">{label.receiver_name || '—'}</div>
            </div>
            <div className="col-span-2">
              <div className="text-[9px] font-semibold text-gray-400 tracking-wide uppercase">Location</div>
              <div className="font-semibold text-gray-900">{label.location || '—'}</div>
            </div>
          </div>

          {/* Barcode */}
          <div className="border-t-2 border-dashed border-gray-300 pt-3 mt-2">
            <GS1Barcode
              value={barcodeValue}
              width={2}
              height={60}
              format="GS1_128"
              className="w-full"
            />
          </div>

          {/* Human-readable encoded data */}
          <div className="text-[8px] text-gray-500 text-center font-mono mt-1 break-all">
            {humanReadable}
          </div>

          {/* SSCC number */}
          <div className="text-center font-bold tracking-[0.35em] text-[11px] mt-2 border-t border-gray-200 pt-2">
            {label.sscc}
          </div>
        </div>
      </div>

      <div className="flex justify-center gap-2 mt-4 no-print">
        <button
          onClick={doPrint}
          className="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg bg-brand-600 text-white text-sm font-semibold hover:bg-brand-700"
        >
          <Printer className="w-4 h-4" /> Cetak Label SSCC
        </button>
      </div>
    </div>
  );
}

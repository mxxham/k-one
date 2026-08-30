import { Printer } from 'lucide-react';
import { QRCodeSVG } from 'qrcode.react';
import { fmtNum } from '@/lib/format';
import GS1Barcode from './GS1Barcode';
import { buildGS1ProductBarcode, formatGS1HumanReadable } from '@/lib/gs1';

export interface GS1ProductLabelData {
  product_code: string | null;
  product_name: string | null;
  gtin?: string | null;
  batch_number?: string | null;
  expiry_date?: string | null;
  quantity?: number;
  uom?: string | null;
  lpn_code?: string | null;
}

export interface GS1ProductLabelProps {
  label: GS1ProductLabelData;
  /** Show QR code for LPN (default: true if lpn_code present) */
  showQR?: boolean;
  onPrint?: () => void;
}

/** Label size presets */
export type LabelSize = 'small' | 'medium' | 'large';

const SIZE_CLASSES: Record<LabelSize, string> = {
  small:  'w-[250px]',
  medium: 'w-[350px]',
  large:  'w-[480px]',
};

/**
 * GS1 Product Label with GS1-128 barcode.
 *
 * Renders a product label with barcode encoding GS1 Application Identifiers
 * (GTIN, Batch, Expiry, Quantity). Optionally includes QR code for LPN.
 */
export default function GS1ProductLabel({
  label,
  showQR = true,
  onPrint,
}: GS1ProductLabelProps) {
  const doPrint = () => {
    onPrint?.();
    window.print();
  };

  // Build GS1 barcode data
  let barcodeValue = '';
  let humanReadable = '';
  try {
    barcodeValue = buildGS1ProductBarcode({
      gtin: label.gtin || undefined,
      batchNumber: label.batch_number || undefined,
      expiryDate: label.expiry_date || undefined,
      quantity: label.quantity,
    });
    humanReadable = formatGS1HumanReadable(barcodeValue);
  } catch {
    // Fallback to product code
    barcodeValue = label.product_code || '';
    humanReadable = barcodeValue;
  }

  const productName = label.product_name || label.product_code || '—';

  return (
    <div>
      <div className="gs1-print-area bg-white text-black rounded-xl border-2 border-gray-900 w-[380px] mx-auto overflow-hidden print:rounded-none">
        {/* Header */}
        <div className="flex items-center justify-between bg-gray-900 text-white px-3 py-1.5">
          <span className="text-[9px] font-bold tracking-[0.2em] whitespace-nowrap">PT. K-ONE</span>
          <span className="text-[7px] font-semibold tracking-[0.15em] text-gray-300 whitespace-nowrap">
            GS1 PRODUCT LABEL
          </span>
        </div>

        <div className="px-3 py-3">
          <div className="flex gap-3 items-stretch">
            {/* Optional QR Code column */}
            {showQR && label.lpn_code && (
              <div className="flex flex-col items-center justify-center shrink-0 w-[120px] border-r border-dashed border-gray-300 pr-3">
                <div className="p-1 border border-gray-200 rounded-md bg-white">
                  <QRCodeSVG value={label.lpn_code} size={100} level="M" includeMargin={false} />
                </div>
                <div className="text-[7px] font-mono font-bold tracking-[0.08em] mt-1.5 text-center leading-tight break-all">
                  {label.lpn_code}
                </div>
              </div>
            )}

            {/* Info */}
            <div className="flex-1 min-w-0 flex flex-col justify-center">
              {/* Product */}
              <div className="pb-2 mb-2 border-b border-dashed border-gray-300">
                <div className="text-[10.5px] font-black leading-snug break-words">
                  {productName}
                </div>
                {label.product_name && label.product_code && (
                  <div className="text-[8px] font-mono text-gray-500 break-all mt-0.5 leading-tight">
                    {label.product_code}
                  </div>
                )}
              </div>

              {/* Fields */}
              <div className="grid grid-cols-2 gap-x-3 gap-y-1.5 text-[10px]">
                <div>
                  <div className="text-[8px] font-semibold text-gray-400 tracking-wide uppercase">Batch</div>
                  <div className="font-semibold">{label.batch_number || '—'}</div>
                </div>
                <div>
                  <div className="text-[8px] font-semibold text-gray-400 tracking-wide uppercase">Exp</div>
                  <div className="font-semibold">{label.expiry_date || '—'}</div>
                </div>
                <div>
                  <div className="text-[8px] font-semibold text-gray-400 tracking-wide uppercase">Qty</div>
                  <div className="font-semibold">{fmtNum(label.quantity ?? 0)} {label.uom || ''}</div>
                </div>
                {label.gtin && (
                  <div>
                    <div className="text-[8px] font-semibold text-gray-400 tracking-wide uppercase">GTIN</div>
                    <div className="font-mono font-semibold text-[9px]">{label.gtin}</div>
                  </div>
                )}
              </div>
            </div>
          </div>

          {/* GS1 Barcode */}
          <div className="border-t-2 border-dashed border-gray-300 pt-2 mt-3">
            <GS1Barcode
              value={barcodeValue}
              width={1.5}
              height={45}
              format="GS1_128"
              className="w-full"
            />
          </div>

          {/* Human-readable GS1 data */}
          <div className="text-[7px] text-gray-500 text-center font-mono mt-1 break-all leading-tight">
            {humanReadable}
          </div>
        </div>
      </div>

      <div className="flex justify-center gap-2 mt-4 no-print">
        <button
          onClick={doPrint}
          className="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg bg-brand-600 text-sm font-semibold hover:bg-brand-700 text-white"
        >
          <Printer className="w-4 h-4" /> Cetak Label GS1
        </button>
      </div>
    </div>
  );
}

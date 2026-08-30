import { useEffect, useRef } from 'react';
import JsBarcode from 'jsbarcode';

export interface GS1BarcodeProps {
  /** Encoded GS1-128 data string */
  value: string;
  /** Barcode width multiplier (default: 2) */
  width?: number;
  /** Barcode height in pixels (default: 50) */
  height?: number;
  /** Show human-readable text below barcode (default: true) */
  displayValue?: boolean;
  /** Additional CSS classes */
  className?: string;
  /** Format override (default: GS1_128) */
  format?: 'GS1_128' | 'CODE128';
}

/**
 * Client-side GS1-128 barcode renderer using JsBarcode.
 *
 * Renders an SVG barcode from encoded GS1-128 data. Falls back to
 * displaying the raw value as text if barcode rendering fails.
 */
export default function GS1Barcode({
  value,
  width = 2,
  height = 50,
  displayValue = true,
  className = '',
  format = 'GS1_128',
}: GS1BarcodeProps) {
  const svgRef = useRef<SVGSVGElement>(null);
  const failed = useRef(false);

  useEffect(() => {
    if (!svgRef.current || !value) return;
    svgRef.current.innerHTML = '';
    failed.current = false;

    try {
      JsBarcode(svgRef.current, value, {
        format,
        width,
        height,
        displayValue,
        margin: 2,
        textMargin: 2,
        fontSize: 12,
        font: 'monospace',
      });
    } catch {
      failed.current = true;
      // Barcode render failure — display fallback text
      if (svgRef.current) {
        svgRef.current.innerHTML = `<text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" font-family="monospace" font-size="11" fill="#374151">${value}</text>`;
      }
    }
  }, [value, width, height, displayValue, format]);

  if (!value) {
    return <div className={`text-xs text-gray-400 italic ${className}`}>No barcode data</div>;
  }

  return (
    <svg
      ref={svgRef}
      className={`gs1-barcode ${className}`}
      aria-label={`GS1 barcode: ${value}`}
    />
  );
}

export interface GS1BarcodeDataProps {
  /** Raw GS1 product data */
  gtin?: string;
  batchNumber?: string;
  expiryDate?: string;
  quantity?: number | string;
  /** Barcode styling */
  width?: number;
  height?: number;
  className?: string;
}

/**
 * Convenience component that builds GS1-128 data from product fields
 * and renders the barcode.
 */
export function GS1ProductBarcode({
  gtin,
  batchNumber,
  expiryDate,
  quantity,
  width = 2,
  height = 50,
  className,
}: GS1BarcodeDataProps) {
  // Build encoded data client-side
  const encodedValue = buildEncodedValue(gtin, batchNumber, expiryDate, quantity);

  return (
    <GS1Barcode
      value={encodedValue}
      width={width}
      height={height}
      className={className}
    />
  );
}

/** Client-side GS1 encoding helper (mirrors gs1.ts logic inline) */
function buildEncodedValue(
  gtin?: string,
  batchNumber?: string,
  expiryDate?: string,
  quantity?: number | string,
): string {
  const items: [string, string, boolean][] = []; // [ai, value, fixed]

  if (gtin) items.push(['01', gtin, true]);
  if (batchNumber) items.push(['10', batchNumber, false]);
  if (expiryDate) items.push(['17', expiryDate, true]);
  if (quantity !== undefined && quantity !== '') {
    items.push(['37', String(parseInt(String(quantity), 10)), false]);
  }

  if (items.length === 0) return '';

  let result = '';
  for (const [ai, value, fixed] of items) {
    let val = value.slice(0, ai === '10' ? 20 : ai === '37' ? 8 : 14);
    if (fixed && /^\d+$/.test(val) && val.length < (ai === '01' ? 14 : 6)) {
      val = val.padStart(ai === '01' ? 14 : 6, '0');
    }
    result += ai + val;
    if (!fixed) result += '\x1D';
  }
  return result;
}

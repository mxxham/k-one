/**
 * GS1 barcode utilities — client-side encoding helpers.
 *
 * These mirror the server-side LabelPrinter::gs1* methods for
 * client-side barcode rendering and validation.
 */

/** GS1 Application Identifier definitions (common subset) */
const GS1_AI: Record<string, { length: number; fixed: boolean; description: string }> = {
  '00':  { length: 18, fixed: true,  description: 'SSCC' },
  '01':  { length: 14, fixed: true,  description: 'GTIN' },
  '02':  { length: 14, fixed: true,  description: 'GTIN of contained trade items' },
  '10':  { length: 20, fixed: false, description: 'Batch/Lot Number' },
  '11':  { length: 6,  fixed: true,  description: 'Production Date' },
  '13':  { length: 6,  fixed: true,  description: 'Packaging Date' },
  '15':  { length: 6,  fixed: true,  description: 'Best Before Date' },
  '17':  { length: 6,  fixed: true,  description: 'Expiration Date' },
  '20':  { length: 2,  fixed: true,  description: 'Variant' },
  '21':  { length: 20, fixed: false, description: 'Serial Number' },
  '30':  { length: 8,  fixed: false, description: 'Variable Count' },
  '37':  { length: 8,  fixed: false, description: 'Count of Trade Items' },
  '400': { length: 30, fixed: false, description: 'Customer PO Number' },
  '410': { length: 13, fixed: true,  description: 'Ship To GLN' },
  '414': { length: 13, fixed: true,  description: 'Identification of Physical Location' },
  '7003': { length: 10, fixed: true, description: 'Expiration Date/Time' },
  '7004': { length: 4,  fixed: false, description: 'Active Potency' },
  '8200': { length: 28, fixed: false, description: 'Extended Packaging URL' },
};

/**
 * Calculate GS1 check digit (Modulo 10).
 */
export function gs1CheckDigit(digits: string): string {
  if (!/^\d+$/.test(digits)) {
    throw new Error(`GS1 check digit input must be numeric, got: ${digits}`);
  }
  let sum = 0;
  for (let i = digits.length - 1, pos = 1; i >= 0; i--, pos++) {
    sum += parseInt(digits[i], 10) * (pos % 2 === 0 ? 1 : 3);
  }
  return String((10 - (sum % 10)) % 10);
}

/**
 * Encode GS1 Application Identifiers into a single string.
 *
 * @param items - Record of AI code to value (e.g., { '01': '09521234543213', '10': 'BATCH-42' })
 * @returns Encoded string for GS1-128 barcode (includes FNC1 group separators)
 */
export function encodeGS1(items: Record<string, string>): string {
  let result = '';
  for (const [ai, value] of Object.entries(items)) {
    const def = GS1_AI[ai];
    if (!def) {
      throw new Error(`Unknown GS1 Application Identifier: ${ai}`);
    }
    let val = value.slice(0, def.length);
    // Pad fixed-length AIs with leading zeros
    if (def.fixed && /^\d+$/.test(val) && val.length < def.length) {
      val = val.padStart(def.length, '0');
    }
    result += ai + val;
    // Append FNC1 group separator for variable-length AIs
    if (!def.fixed) {
      result += '\x1D';
    }
  }
  return result;
}

/**
 * Build GS1-128 barcode data for a product.
 *
 * @param data - Must contain at least one of: gtin, batchNumber, expiryDate, quantity
 * @returns Encoded GS1-128 string
 */
export function buildGS1ProductBarcode(data: {
  gtin?: string;
  batchNumber?: string;
  expiryDate?: string;  // YYMMDD format
  quantity?: number | string;
}): string {
  const items: Record<string, string> = {};
  if (data.gtin) items['01'] = data.gtin;
  if (data.batchNumber) items['10'] = data.batchNumber;
  if (data.expiryDate) items['17'] = data.expiryDate;
  if (data.quantity !== undefined && data.quantity !== '') {
    items['37'] = String(parseInt(String(data.quantity), 10));
  }
  if (Object.keys(items).length === 0) {
    throw new Error('At least one GS1 field (gtin, batchNumber, expiryDate, or quantity) is required');
  }
  return encodeGS1(items);
}

/**
 * Build GS1-128 barcode data for an SSCC.
 *
 * @param sscc - 18-digit SSCC string
 * @returns Encoded GS1-128 string with AI 00
 */
export function buildSSCCBarcode(sscc: string): string {
  const digits = sscc.replace(/\D/g, '');
  if (digits.length !== 18) {
    throw new Error(`SSCC must be exactly 18 numeric digits, got: ${sscc}`);
  }
  // Verify check digit
  const body = digits.slice(0, 17);
  const expectedCheck = gs1CheckDigit(body);
  if (digits[17] !== expectedCheck) {
    throw new Error(`SSCC check digit mismatch: expected ${expectedCheck}, got ${digits[17]}`);
  }
  return '00' + digits;
}

/**
 * Format GS1 barcode data into human-readable form with AI labels.
 *
 * Example: (01)09521234543213(10)BATCH-42(17)260101
 */
export function formatGS1HumanReadable(encoded: string): string {
  let result = '';
  let pos = 0;

  while (pos < encoded.length) {
    const ch = encoded[pos];
    if (ch === '\x1D') {
      pos++;
      continue;
    }

    // Determine AI length (2, 3, or 4 digits)
    let ai = '';
    const rest = encoded.slice(pos);
    for (const aiLen of [4, 3, 2]) {
      const candidate = rest.slice(0, aiLen);
      if (GS1_AI[candidate]) {
        ai = candidate;
        break;
      }
    }

    if (!ai) {
      result += rest.slice(0, 2);
      pos += 2;
      continue;
    }

    const def = GS1_AI[ai];
    const valueStart = pos + ai.length;
    const value = encoded.slice(valueStart, valueStart + def.length);
    result += `(${ai})${value}`;
    pos = valueStart + def.length;

    // Skip FNC1 separator if present
    if (pos < encoded.length && encoded[pos] === '\x1D') {
      pos++;
    }
  }

  return result;
}

/**
 * Generate a GTIN-14 from a GTIN-13 by adding indicator digit + check digit.
 */
export function gtin13to14(gtin13: string): string {
  const digits = gtin13.replace(/\D/g, '');
  if (digits.length === 14) return digits; // already GTIN-14
  if (digits.length !== 13) {
    throw new Error(`GTIN must be 13 digits, got: ${digits}`);
  }
  const body = '0' + digits; // indicator digit 0
  return body + gs1CheckDigit(body);
}

/** Get AI description */
export function getAIDescription(ai: string): string {
  return GS1_AI[ai]?.description ?? `Unknown AI (${ai})`;
}

/** List all known AI codes */
export function listAICodes(): string[] {
  return Object.keys(GS1_AI);
}

<?php

/**
 * LabelPrinter — Server-side label HTML generators.
 *
 * Each static method returns an HTML string that can be printed directly
 * from the browser. Matches frontend component structure for visual parity.
 *
 * Capabilities:
 *   - LPN pallet labels (original)
 *   - GS1-128 barcode data encoding
 *   - SSCC (Serial Shipping Container Code) label generation
 *   - Customizable label templates with size presets
 *   - Batch printing (multiple labels per page)
 */
class LabelPrinter {

    // ─── Label Template Definitions ───────────────────────────────────────

    /** @var array<string, array{width:string,height:string,maxWidth:string,padding:string,fontSize:string}> */
    private static array $templates = [
        'lpn-small' => [
            'width'     => '200px',
            'height'    => 'auto',
            'maxWidth'  => '200px',
            'padding'   => '8px',
            'fontSize'  => '9px',
            'barcodeHeight' => 40,
        ],
        'lpn-medium' => [
            'width'     => '320px',
            'height'    => 'auto',
            'maxWidth'  => '320px',
            'padding'   => '12px',
            'fontSize'  => '11px',
            'barcodeHeight' => 50,
        ],
        'lpn-large' => [
            'width'     => '480px',
            'height'    => 'auto',
            'maxWidth'  => '480px',
            'padding'   => '16px',
            'fontSize'  => '13px',
            'barcodeHeight' => 60,
        ],
        'gs1-small' => [
            'width'     => '250px',
            'height'    => 'auto',
            'maxWidth'  => '250px',
            'padding'   => '8px',
            'fontSize'  => '9px',
            'barcodeHeight' => 45,
        ],
        'gs1-medium' => [
            'width'     => '350px',
            'height'    => 'auto',
            'maxWidth'  => '350px',
            'padding'   => '12px',
            'fontSize'  => '11px',
            'barcodeHeight' => 55,
        ],
        'sscc-medium' => [
            'width'     => '400px',
            'height'    => 'auto',
            'maxWidth'  => '400px',
            'padding'   => '14px',
            'fontSize'  => '11px',
            'barcodeHeight' => 60,
        ],
    ];

    // ─── GS1 Application Identifiers (common subset) ──────────────────────

    /** @var array<string, array{length:int,description:string,fixed:bool}> Common GS1 AI definitions */
    private static array $gs1AI = [
        '00'  => ['length' => 18, 'description' => 'SSCC',           'fixed' => true],
        '01'  => ['length' => 14, 'description' => 'GTIN',           'fixed' => true],
        '02'  => ['length' => 14, 'description' => 'GTIN of contained trade items', 'fixed' => true],
        '10'  => ['length' => 20, 'description' => 'Batch/Lot Number','fixed' => false],
        '11'  => ['length' => 6,  'description' => 'Production Date','fixed' => true],
        '13'  => ['length' => 6,  'description' => 'Packaging Date', 'fixed' => true],
        '15'  => ['length' => 6,  'description' => 'Best Before Date','fixed' => true],
        '17'  => ['length' => 6,  'description' => 'Expiration Date','fixed' => true],
        '20'  => ['length' => 2,  'description' => 'Variant',        'fixed' => true],
        '21'  => ['length' => 20, 'description' => 'Serial Number',  'fixed' => false],
        '30'  => ['length' => 8,  'description' => 'Variable Count', 'fixed' => false],
        '37'  => ['length' => 8,  'description' => 'Count of Trade Items', 'fixed' => false],
        '400' => ['length' => 30, 'description' => 'Customer PO Number',   'fixed' => false],
        '410' => ['length' => 13, 'description' => 'Ship To GLN',   'fixed' => true],
        '414' => ['length' => 13, 'description' => 'Identification of Physical Location', 'fixed' => true],
        '7003' => ['length' => 10, 'description' => 'Expiration Date/Time', 'fixed' => true],
        '7004' => ['length' => 4,  'description' => 'Active Potency', 'fixed' => false],
        '8200' => ['length' => 28, 'description' => 'Extended Packaging URL', 'fixed' => false],
    ];

    // ─── GS1 / SSCC Encoding Utilities ────────────────────────────────────

    /**
     * Calculate GS1 Check Digit (Modulo 10).
     *
     * @param string $digits  Numeric string (without check digit)
     * @return string         Single check digit character
     * @throws InvalidArgumentException if input is non-numeric
     */
    public static function gs1CheckDigit(string $digits): string {
        if (!ctype_digit($digits)) {
            throw new InvalidArgumentException('GS1 check digit input must be numeric, got: ' . $digits);
        }
        $len = strlen($digits);
        $sum = 0;
        for ($i = $len - 1, $pos = 1; $i >= 0; $i--, $pos++) {
            $sum += (int)$digits[$i] * ($pos % 2 === 0 ? 1 : 3);
        }
        return (string)((10 - ($sum % 10)) % 10);
    }

    /**
     * Build a GS1 Application Identifier string for barcode encoding.
     *
     * Accepts an associative array of AI => value pairs and returns the
     * combined string with FNC1 group separators (GS char \x1D) appended
     * to variable-length AIs.
     *
     * @param array<string,string> $items  AI code => value (e.g. ['01' => '09521234543213', '10' => 'BATCH-42'])
     * @return string  Encoded string for GS1-128 barcode
     * @throws InvalidArgumentException if an unknown AI is provided
     */
    public static function encodeGS1(array $items): string {
        $result = '';
        foreach ($items as $ai => $value) {
            if (!isset(self::$gs1AI[$ai])) {
                throw new InvalidArgumentException("Unknown GS1 Application Identifier: {$ai}");
            }
            $def = self::$gs1AI[$ai];
            $maxLen = $def['length'];
            $val = substr($value, 0, $maxLen);
            // Pad fixed-length AIs with leading zeros
            if ($def['fixed'] && ctype_digit($val) && strlen($val) < $maxLen) {
                $val = str_repeat('0', $maxLen - strlen($val)) . $val;
            }
            $result .= $ai . $val;
            // Append FNC1 group separator for variable-length AIs
            if (!$def['fixed']) {
                $result .= "\x1D";
            }
        }
        return $result;
    }

    /**
     * Generate an SSCC (Serial Shipping Container Code) from a company prefix + extension.
     *
     * SSCC = Extension digit (1) + Company prefix (n) + Serial reference (n) + Check digit (1) = 18 digits
     *
     * @param string $companyPrefix   Company prefix (digits only, typically 7-10 digits)
     * @param string $serialReference Serial reference (digits, padded to fill 18 - 1 - prefixLen - 1)
     * @param string $extensionDigit  Single digit extension (default '0')
     * @return string  18-digit SSCC with check digit
     * @throws InvalidArgumentException if inputs are invalid
     */
    public static function generateSSCC(
        string $companyPrefix,
        string $serialReference,
        string $extensionDigit = '0'
    ): string {
        if (!ctype_digit($extensionDigit) || strlen($extensionDigit) !== 1) {
            throw new InvalidArgumentException("Extension digit must be a single numeric digit, got: {$extensionDigit}");
        }
        if (!ctype_digit($companyPrefix) || strlen($companyPrefix) < 7 || strlen($companyPrefix) > 10) {
            throw new InvalidArgumentException("Company prefix must be 7-10 numeric digits, got: {$companyPrefix}");
        }
        if (!ctype_digit($serialReference)) {
            throw new InvalidArgumentException("Serial reference must be numeric, got: {$serialReference}");
        }

        // Calculate required serial reference length: 18 - 1(ext) - prefixLen - 1(check)
        $serialLen = 18 - 1 - strlen($companyPrefix) - 1;
        $serial = str_pad($serialReference, $serialLen, '0', STR_PAD_LEFT);
        if (strlen($serial) > $serialLen) {
            throw new InvalidArgumentException("Serial reference too long for given company prefix (max {$serialLen} digits)");
        }

        $body = $extensionDigit . $companyPrefix . $serial;
        $checkDigit = self::gs1CheckDigit($body);

        return $body . $checkDigit;
    }

    // ─── GS1-128 Barcode Data Helpers ─────────────────────────────────────

    /**
     * Build GS1-128 barcode data for a product label.
     *
     * Common AIs: 01 (GTIN), 10 (Batch), 17 (Expiry), 37 (Quantity)
     *
     * @param array $data  Must contain at minimum 'gtin'. Optional: batch_number, expiry_date (YYMMDD), quantity
     * @return string  Encoded GS1-128 data string
     */
    public static function buildGS1ProductBarcode(array $data): string {
        $items = [];

        if (!empty($data['gtin'])) {
            $items['01'] = $data['gtin'];
        }
        if (!empty($data['batch_number'])) {
            $items['10'] = $data['batch_number'];
        }
        if (!empty($data['expiry_date'])) {
            $items['17'] = $data['expiry_date'];
        }
        if (isset($data['quantity']) && $data['quantity'] !== '') {
            $items['37'] = (string)(int)$data['quantity'];
        }

        if (empty($items)) {
            throw new InvalidArgumentException('At least one GS1 field (gtin, batch_number, expiry_date, or quantity) is required');
        }

        return self::encodeGS1($items);
    }

    /**
     * Build GS1-128 barcode data for an SSCC label.
     *
     * @param string $sscc  18-digit SSCC
     * @return string  Encoded GS1-128 data with AI 00
     */
    public static function buildSSCCBarcode(string $sscc): string {
        if (!ctype_digit($sscc) || strlen($sscc) !== 18) {
            throw new InvalidArgumentException("SSCC must be exactly 18 numeric digits, got: {$sscc}");
        }
        // Verify check digit
        $body = substr($sscc, 0, 17);
        $expectedCheck = self::gs1CheckDigit($body);
        if ($sscc[17] !== $expectedCheck) {
            throw new InvalidArgumentException("SSCC check digit mismatch: expected {$expectedCheck}, got {$sscc[17]}");
        }
        return '00' . $sscc;
    }

    // ─── Human-Readable GS1 Formatting ────────────────────────────────────

    /**
     * Format GS1 barcode data into human-readable form with AI labels.
     *
     * Example: (01)09521234543213(10)BATCH-42(17)260101
     *
     * @param string $encoded  Raw encoded GS1 data (from encodeGS1)
     * @return string  Human-readable formatted string
     */
    public static function formatGS1HumanReadable(string $encoded): string {
        $result = '';
        $pos = 0;
        $len = strlen($encoded);

        while ($pos < $len) {
            $ch = $encoded[$pos];
            if ($ch === "\x1D") {
                $pos++;
                continue;
            }

            // Determine AI length (2 or 3+ digits)
            $ai = '';
            $rest = substr($encoded, $pos);
            // Try 4-digit AI first, then 3, then 2
            foreach ([4, 3, 2] as $aiLen) {
                $candidate = substr($rest, 0, $aiLen);
                if (isset(self::$gs1AI[$candidate])) {
                    $ai = $candidate;
                    break;
                }
            }

            if ($ai === '') {
                // Unknown AI — take next 2 chars as-is
                $result .= substr($rest, 0, 2);
                $pos += 2;
                continue;
            }

            $def = self::$gs1AI[$ai];
            $valueStart = $pos + strlen($ai);
            $valueEnd = $valueStart + $def['length'];
            $value = substr($encoded, $valueStart, $def['length']);

            $result .= "({$ai}){$value}";
            $pos = $valueEnd;

            // Skip FNC1 separator if present
            if ($pos < $len && $encoded[$pos] === "\x1D") {
                $pos++;
            }
        }

        return $result;
    }

    // ─── Label Template Access ────────────────────────────────────────────

    /**
     * Get template configuration by name.
     *
     * @param string $templateName
     * @return array  Template config array
     * @throws InvalidArgumentException if template not found
     */
    public static function getTemplate(string $templateName): array {
        if (!isset(self::$templates[$templateName])) {
            throw new InvalidArgumentException(
                "Unknown label template: {$templateName}. Available: " . implode(', ', array_keys(self::$templates))
            );
        }
        return self::$templates[$templateName];
    }

    /**
     * List all available template names.
     *
     * @return array<string>
     */
    public static function listTemplates(): array {
        return array_keys(self::$templates);
    }

    // ─── LPN Label (original, preserved exactly) ──────────────────────────

    /**
     * LPN (License Plate Number) pallet label.
     *
     * @param array $data  Output of Putaway::getLpnLabelData()
     * @param string|null $templateName  Optional template override (default: null = lpn-medium inline)
     * @return string      Standalone HTML fragment
     */
    public static function lpnLabel(array $data, ?string $templateName = null): string {
        $lpn        = htmlspecialchars($data['lpn_code'] ?? '');
        $prodCode   = htmlspecialchars($data['product_code'] ?? '');
        $prodName   = htmlspecialchars($data['product_name'] ?? '');
        $batch      = htmlspecialchars($data['batch_number'] ?? '—');
        $expiry     = htmlspecialchars($data['expiry_date'] ?? '—');
        $qty        = number_format((float)($data['quantity'] ?? 0), 0, ',', '.');
        $uom        = htmlspecialchars($data['uom'] ?? '');
        $pallet     = (int)($data['pallet_seq'] ?? 0);
        $loc        = htmlspecialchars($data['suggested_location'] ?? '—');
        $taskNum    = htmlspecialchars($data['task_number'] ?? '—');
        $orderNum   = htmlspecialchars($data['order_number'] ?? '');

        // Apply template sizing if specified, otherwise use original inline styles
        $containerStyle = 'font-family:monospace;width:320px;border:2px solid #111;padding:12px;margin:0 auto;background:#fff;color:#000';
        if ($templateName !== null) {
            $tpl = self::getTemplate($templateName);
            $containerStyle = "font-family:monospace;width:{$tpl['width']};max-width:{$tpl['maxWidth']};height:{$tpl['height']};border:2px solid #111;padding:{$tpl['padding']};margin:0 auto;background:#fff;color:#000";
        }

        return <<<HTML
<div style="{$containerStyle}">
  <div style="display:flex;justify-content:space-between;border-bottom:1px solid #999;padding-bottom:6px;margin-bottom:8px">
    <div style="font-weight:900;font-size:14px;letter-spacing:.08em">
      {$lpn}<br><span style="font-size:9px;font-weight:700;color:#666">LABEL PALLET / LPN</span>
    </div>
    <div style="font-size:9px;font-weight:700;color:#666;text-align:right;line-height:1.3">
      PT. K-ONE<br>WAREHOUSE
    </div>
  </div>
  <div style="font-size:11px;line-height:1.4">
    <div style="font-weight:700">{$prodName}</div>
    <div style="font-size:10px;color:#666">{$prodCode}</div>
    <div style="display:flex;justify-content:space-between;margin-top:4px">
      <span>Batch: <b>{$batch}</b></span>
      <span>Exp: <b>{$expiry}</b></span>
    </div>
    <div style="display:flex;justify-content:space-between">
      <span>Qty: <b>{$qty} {$uom}</b></span>
      <span>Pallet: <b>#{$pallet}</b></span>
    </div>
    <div style="display:flex;justify-content:space-between">
      <span>Lokasi: <b>{$loc}</b></span>
      <span>Task: <b>{$taskNum}</b></span>
    </div>
  </div>
  <div style="text-align:center;font-weight:700;letter-spacing:.35em;font-size:11px;margin-top:6px;border-top:1px solid #ccc;padding-top:4px">{$lpn}</div>
</div>
HTML;
    }

    // ─── GS1 Product Label ────────────────────────────────────────────────

    /**
     * GS1 product label with barcode data for barcode rendering.
     *
     * Returns an HTML fragment. The barcode itself should be rendered client-side
     * using JsBarcode (GS1-128 format). This method provides the data attributes
     * and label structure.
     *
     * @param array $data  Product label data
     * @param string $templateName  Template to use (default: 'gs1-medium')
     * @return string  Standalone HTML fragment with barcode data attribute
     */
    public static function gs1ProductLabel(array $data, string $templateName = 'gs1-medium'): string {
        $tpl = self::getTemplate($templateName);

        $prodCode   = htmlspecialchars($data['product_code'] ?? '');
        $prodName   = htmlspecialchars($data['product_name'] ?? '');
        $batch      = htmlspecialchars($data['batch_number'] ?? '—');
        $expiry     = htmlspecialchars($data['expiry_date'] ?? '—');
        $qty        = number_format((float)($data['quantity'] ?? 0), 0, ',', '.');
        $uom        = htmlspecialchars($data['uom'] ?? '');

        // Build GS1 barcode data
        $barcodeData = '';
        $humanReadable = '';
        try {
            $barcodeData = self::buildGS1ProductBarcode($data);
            $humanReadable = self::formatGS1HumanReadable($barcodeData);
        } catch (InvalidArgumentException $e) {
            // If GS1 encoding fails, fall back to product code as plain barcode
            $barcodeData = $prodCode;
            $humanReadable = $prodCode;
        }

        $barcodeDataAttr = htmlspecialchars($barcodeData, ENT_QUOTES);
        $humanReadableEsc = htmlspecialchars($humanReadable);

        return <<<HTML
<div style="font-family:monospace;width:{$tpl['width']};max-width:{$tpl['maxWidth']};border:2px solid #111;padding:{$tpl['padding']};margin:0 auto;background:#fff;color:#000">
  <div style="display:flex;justify-content:space-between;border-bottom:1px solid #999;padding-bottom:6px;margin-bottom:8px">
    <div style="font-weight:900;font-size:14px;letter-spacing:.08em">
      {$prodCode}<br><span style="font-size:9px;font-weight:700;color:#666">GS1 PRODUCT LABEL</span>
    </div>
    <div style="font-size:9px;font-weight:700;color:#666;text-align:right;line-height:1.3">
      PT. K-ONE<br>WAREHOUSE
    </div>
  </div>
  <div style="font-size:{$tpl['fontSize']};line-height:1.4">
    <div style="font-weight:700">{$prodName}</div>
    <div style="display:flex;justify-content:space-between;margin-top:4px">
      <span>Batch: <b>{$batch}</b></span>
      <span>Exp: <b>{$expiry}</b></span>
    </div>
    <div style="display:flex;justify-content:space-between">
      <span>Qty: <b>{$qty} {$uom}</b></span>
    </div>
  </div>
  <div class="gs1-barcode-container" data-barcode="{$barcodeDataAttr}" data-format="GS1_128" data-height="{$tpl['barcodeHeight']}" style="text-align:center;margin:8px 0 4px 0">
    <svg class="gs1-barcode-svg"></svg>
  </div>
  <div style="text-align:center;font-size:8px;color:#666;word-break:break-all;font-family:monospace">{$humanReadableEsc}</div>
</div>
HTML;
    }

    // ─── SSCC Label ───────────────────────────────────────────────────────

    /**
     * SSCC (Serial Shipping Container Code) label.
     *
     * @param array $data  Must contain 'sscc' (18-digit). Optional: po_number, sender_name, receiver_name, location
     * @param string $templateName  Template to use (default: 'sscc-medium')
     * @return string  Standalone HTML fragment
     */
    public static function ssccLabel(array $data, string $templateName = 'sscc-medium'): string {
        $tpl = self::getTemplate($templateName);

        $sscc = htmlspecialchars($data['sscc'] ?? '');
        $poNumber   = htmlspecialchars($data['po_number'] ?? '—');
        $sender     = htmlspecialchars($data['sender_name'] ?? 'PT. K-ONE');
        $receiver   = htmlspecialchars($data['receiver_name'] ?? '—');
        $location   = htmlspecialchars($data['location'] ?? '—');
        $itemCount  = (int)($data['item_count'] ?? 0);

        // Build GS1 barcode data for SSCC
        $barcodeData = '';
        $humanReadable = '';
        try {
            $barcodeData = self::buildSSCCBarcode(preg_replace('/\D/', '', $sscc));
            $humanReadable = self::formatGS1HumanReadable($barcodeData);
        } catch (InvalidArgumentException $e) {
            $barcodeData = preg_replace('/\D/', '', $sscc);
            $humanReadable = $sscc;
        }

        $barcodeDataAttr = htmlspecialchars($barcodeData, ENT_QUOTES);
        $humanReadableEsc = htmlspecialchars($humanReadable);

        return <<<HTML
<div style="font-family:monospace;width:{$tpl['width']};max-width:{$tpl['maxWidth']};border:2px solid #111;padding:{$tpl['padding']};margin:0 auto;background:#fff;color:#000">
  <div style="display:flex;justify-content:space-between;border-bottom:1px solid #999;padding-bottom:6px;margin-bottom:8px">
    <div style="font-weight:900;font-size:14px;letter-spacing:.08em">
      SSCC<br><span style="font-size:9px;font-weight:700;color:#666">SERIAL SHIPPING CONTAINER CODE</span>
    </div>
    <div style="font-size:9px;font-weight:700;color:#666;text-align:right;line-height:1.3">
      {$sender}
    </div>
  </div>
  <div style="font-size:{$tpl['fontSize']};line-height:1.4">
    <div style="display:flex;justify-content:space-between">
      <span>PO: <b>{$poNumber}</b></span>
      <span>Items: <b>{$itemCount}</b></span>
    </div>
    <div style="display:flex;justify-content:space-between">
      <span>Receiver: <b>{$receiver}</b></span>
    </div>
    <div style="display:flex;justify-content:space-between">
      <span>Location: <b>{$location}</b></span>
    </div>
  </div>
  <div class="sscc-barcode-container" data-barcode="{$barcodeDataAttr}" data-format="GS1_128" data-height="{$tpl['barcodeHeight']}" style="text-align:center;margin:8px 0 4px 0">
    <svg class="sscc-barcode-svg"></svg>
  </div>
  <div style="text-align:center;font-size:8px;color:#666;word-break:break-all;font-family:monospace">{$humanReadableEsc}</div>
  <div style="text-align:center;font-weight:700;letter-spacing:.35em;font-size:11px;margin-top:6px;border-top:1px solid #ccc;padding-top:4px">{$sscc}</div>
</div>
HTML;
    }

    // ─── Batch Printing ───────────────────────────────────────────────────

    /**
     * Wrap multiple label HTML fragments in a print-ready document.
     *
     * Includes inline CSS for @media print with page-break control.
     * Each label is a separate page by default (page-break-after: always).
     *
     * @param string[] $labelHtmls  Array of label HTML fragments
     * @param array $options  Options: 'title' (string), 'pageSize' ('A4'|'A5'|'letter'), 'orientation' ('portrait'|'landscape'), 'perPage' (int, 0=one per page)
     * @return string  Complete HTML document ready for printing
     */
    public static function batchPrintDocument(array $labelHtmls, array $options = []): string {
        $title      = htmlspecialchars($options['title'] ?? 'K-one Batch Labels');
        $pageSize   = $options['pageSize'] ?? 'A4';
        $orientation = $options['orientation'] ?? 'portrait';
        $perPage    = (int)($options['perPage'] ?? 0);

        $sizeMap = [
            'A4'     => '210mm',
            'A5'     => '148mm',
            'letter' => '216mm',
        ];
        $heightMap = [
            'A4'     => '297mm',
            'A5'     => '210mm',
            'letter' => '279mm',
        ];

        $pageWidth  = $sizeMap[$pageSize] ?? '210mm';
        $pageHeight = $heightMap[$pageSize] ?? '297mm';

        $labelsHtml = '';
        foreach ($labelHtmls as $idx => $html) {
            $breakStyle = $idx < count($labelHtmls) - 1 ? 'page-break-after:always' : 'page-break-after:auto';
            $labelsHtml .= "<div class=\"batch-label\" style=\"{$breakStyle}\">{$html}</div>\n";
        }

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{$title}</title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: monospace; background: #f3f4f6; }
  .batch-label {
    display: flex;
    justify-content: center;
    align-items: flex-start;
    padding: 10mm;
  }
  .batch-label > div {
    margin: 0 auto;
  }
  @media print {
    body { background: white; }
    .batch-label {
      width: {$pageWidth};
      height: {$pageHeight};
      padding: 5mm;
      page-break-after: always;
    }
    .batch-label:last-child {
      page-break-after: auto;
    }
    @page {
      size: {$pageSize} {$orientation};
      margin: 0;
    }
  }
</style>
</head>
<body>
{$labelsHtml}
</body>
</html>
HTML;
    }

    /**
     * Generate batch print HTML for GS1 product labels.
     *
     * @param array[] $items  Array of product data arrays
     * @param string $templateName
     * @return string  Complete print document
     */
    public static function batchGS1Labels(array $items, string $templateName = 'gs1-medium'): string {
        $labels = [];
        foreach ($items as $item) {
            $labels[] = self::gs1ProductLabel($item, $templateName);
        }
        return self::batchPrintDocument($labels, [
            'title' => 'K-one GS1 Product Labels',
        ]);
    }

    /**
     * Generate batch print HTML for SSCC labels.
     *
     * @param array[] $items  Array of SSCC data arrays
     * @param string $templateName
     * @return string  Complete print document
     */
    public static function batchSSCCLabels(array $items, string $templateName = 'sscc-medium'): string {
        $labels = [];
        foreach ($items as $item) {
            $labels[] = self::ssccLabel($item, $templateName);
        }
        return self::batchPrintDocument($labels, [
            'title' => 'K-one SSCC Labels',
        ]);
    }

    /**
     * Generate batch print HTML for LPN labels.
     *
     * @param array[] $items  Array of LPN data arrays
     * @param string|null $templateName
     * @return string  Complete print document
     */
    public static function batchLPNLabels(array $items, ?string $templateName = null): string {
        $labels = [];
        foreach ($items as $item) {
            $labels[] = self::lpnLabel($item, $templateName);
        }
        return self::batchPrintDocument($labels, [
            'title' => 'K-one LPN Labels',
        ]);
    }
}

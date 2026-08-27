<?php

/**
 * LabelPrinter — Server-side label HTML generators.
 *
 * Each static method returns an HTML string that can be printed directly
 * from the browser. Matches frontend component structure for visual parity.
 */
class LabelPrinter {

    /**
     * LPN (License Plate Number) pallet label.
     *
     * @param array $data  Output of Putaway::getLpnLabelData()
     * @return string      Standalone HTML fragment
     */
    public static function lpnLabel(array $data): string {
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

        return <<<HTML
<div style="font-family:monospace;width:320px;border:2px solid #111;padding:12px;margin:0 auto;background:#fff;color:#000">
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
}

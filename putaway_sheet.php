<?php
session_start();
date_default_timezone_set('Asia/Jakarta');
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/classes/Auth.php';
require_once __DIR__ . '/classes/Inbound.php';

Auth::requireAuth();

$id = $_GET['id'] ?? null;
if (!$id) { header('Location: inbound.php'); exit; }

$inbound = Inbound::getById($id);
if (!$inbound) { header('Location: inbound.php'); exit; }

$items = Inbound::getItems($id);

$itemLocations = [];
foreach ($items as $item) {
    $locs = Inbound::getItemLocations($item['id']);
    $itemLocations[$item['id']] = $locs ?: [];
}

$putawayRows = [];
$totalQty    = 0;
$totalLines  = 0;

foreach ($items as $item) {
    $locs   = $itemLocations[$item['id']];
    $batch  = $item['batch_number'] ?? $item['batch_no'] ?? '';
    $uom    = $item['uom'] ?? $item['uom_type'] ?? 'Drum';
    $odNo   = $item['od_number'] ?? '';
    $soNo   = $item['so_number'] ?? '';

    if (!empty($locs)) {
        foreach ($locs as $loc) {
            $qty = floatval($loc['display_quantity'] ?? $loc['quantity'] ?? 0);
            $putawayRows[] = [
                'item_code'  => $item['product_code'] ?? '',
                'item_desc'  => $item['product_name'] ?? '',
                'batch_no'   => $batch,
                'qty'        => $qty,
                'uom'        => $uom,
                'pallet_seq' => $loc['pallet_seq'] ?? '',
                'location'   => $loc['location_code'] ?? '',
                'od_number'  => $odNo,
                'so_number'  => $soNo,
            ];
            $totalQty += $qty;
            $totalLines++;
        }
    } else {
        $qty = floatval($item['actual_qty'] ?? $item['quantity'] ?? 0);
        $putawayRows[] = [
            'item_code'  => $item['product_code'] ?? '',
            'item_desc'  => $item['product_name'] ?? '',
            'batch_no'   => $batch,
            'qty'        => $qty,
            'uom'        => $uom,
            'pallet_seq' => '',
            'location'   => $item['location'] ?? '',
            'od_number'  => $odNo,
            'so_number'  => $soNo,
        ];
        $totalQty += $qty;
        $totalLines++;
    }
}

$orderNo     = $inbound['order_number'] ?? $inbound['inbound_number'] ?? '—';
$customer    = $inbound['carrier_name'] ?? '—';
$putawayDate = $inbound['received_date'] ?? $inbound['order_date'] ?? date('Y-m-d');
$userName    = $inbound['received_by_name'] ?? $inbound['created_by_name'] ?? '—';
$shipmentNo  = $inbound['shipment_no'] ?? '';
$notes       = $inbound['notes'] ?? '';
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>Putaway Sheet — <?= htmlspecialchars($orderNo) ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap');
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Inter',Arial,sans-serif;font-size:10pt;color:#1a1a1a;background:#fff}
@page{size:A4;margin:0}
@media print{.no-print{display:none!important}body{-webkit-print-color-adjust:exact;print-color-adjust:exact;background:#fff}.page-break{page-break-before:always}}

.print-bar{background:linear-gradient(135deg,#013d3c,#026766);color:#fff;padding:10px 22px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:99}
.print-bar-title{font-weight:700;font-size:13px;display:flex;align-items:center;gap:8px}
.btns{display:flex;gap:8px}
.btn-print{background:#fff;color:#026766;border:none;padding:7px 16px;border-radius:6px;font-weight:700;cursor:pointer;font-size:12px;display:flex;align-items:center;gap:5px}
.btn-back{background:rgba(255,255,255,.15);color:#fff;border:1.5px solid rgba(255,255,255,.5);padding:7px 14px;border-radius:6px;font-weight:600;font-size:12px;text-decoration:none;display:flex;align-items:center;gap:5px}

.document{max-width:794px;margin:18px auto;background:#fff;padding:24px 28px;box-shadow:0 2px 20px rgba(0,0,0,.1);border-radius:4px}
@media print{.document{box-shadow:none;margin:0;padding:14mm 14mm 16mm;max-width:100%;border-radius:0}}

.doc-header{display:flex;justify-content:space-between;align-items:flex-start;border-bottom:3px solid #026766;padding-bottom:12px;margin-bottom:14px}
.logo-area{display:flex;align-items:center;gap:10px}
.logo-mark{width:46px;height:46px;background:#026766;border-radius:10px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:24px;font-weight:900;letter-spacing:-1px;flex-shrink:0}
.company-name .nk{font-size:16pt;font-weight:900;color:#013d3c}
.company-name .none{font-size:12pt;font-weight:400;color:#013d3c;opacity:.65}
.doc-title-block{text-align:right}
.doc-title{font-size:16pt;font-weight:800;color:#013d3c;letter-spacing:-.3px}
.doc-subtitle{font-size:8pt;color:#607d8b;margin-top:1px}
.doc-orderno{font-size:10pt;font-weight:700;color:#1a1a1a;margin-top:5px;font-family:monospace}

.info-grid{display:grid;grid-template-columns:1fr 1fr 1fr;border:1px solid #b2dfdb;border-radius:7px;overflow:hidden;margin-bottom:14px}
.info-cell{padding:8px 12px;border-right:1px solid #b2dfdb;border-bottom:1px solid #b2dfdb;background:#fff}
.info-cell:nth-child(3n){border-right:none}
.info-cell:nth-last-child(-n+3){border-bottom:none}
.info-cell.span2{grid-column:span 2}
.info-cell .lbl{font-size:6.5pt;color:#026766;text-transform:uppercase;letter-spacing:.5px;font-weight:700;margin-bottom:2px}
.info-cell .val{font-size:9.5pt;font-weight:600;color:#1a1a1a}

.summary-bar{display:flex;gap:10px;margin-bottom:14px}
.sum-card{flex:1;border-radius:7px;padding:9px 12px;text-align:center;border:1px solid transparent}
.sum-card .num{font-size:18pt;font-weight:800;line-height:1}
.sum-card .lbl{font-size:6.5pt;text-transform:uppercase;letter-spacing:.5px;margin-top:3px;font-weight:600}
.sc-teal{background:#e0f2f1;color:#013d3c;border-color:#80cbc4}
.sc-green{background:#e8f5e9;color:#013d3c;border-color:#a5d6a7}
.sc-amber{background:#fff8e1;color:#e65100;border-color:#ffcc80}

.section-title{font-size:8.5pt;font-weight:700;color:#013d3c;border-left:4px solid #026766;padding-left:8px;margin-bottom:8px;text-transform:uppercase;letter-spacing:.5px}

table{width:100%;border-collapse:collapse;font-size:8.5pt;margin-bottom:12px}
thead th{background:#026766;color:#fff;padding:7px 8px;text-align:left;font-weight:600;font-size:7.5pt;letter-spacing:.3px;text-transform:uppercase}
thead th.c{text-align:center}
thead th.r{text-align:right}
tbody tr:nth-child(even){background:#f0fdf9}
tbody td{padding:6px 8px;border-bottom:1px solid #e0f2f1;vertical-align:middle;line-height:1.4}
tbody td.c{text-align:center}
tbody td.r{text-align:right;font-weight:600}
tfoot td{padding:7px 8px;font-weight:700;font-size:9pt;border-top:2.5px solid #026766;background:#e0f2f1;color:#013d3c}
tfoot td.r{text-align:right}

.chip{display:inline-block;border-radius:4px;padding:1px 6px;font-family:monospace;font-size:7pt;font-weight:700}
.chip-batch{background:#f3f4f6;color:#374151;border:1px solid #d1d5db}
.chip-od{background:#e0f7f7;color:#026766;border:1px solid #80d2d2}
.chip-so{background:#e0f7f7;color:#026766;border:1px solid #b2e5e5}
.chip-loc{background:#d1fae5;color:#065f46;border:1px solid #6ee7b7}
.palt-badge{display:inline-flex;align-items:center;justify-content:center;width:20px;height:20px;border-radius:50%;background:#026766;color:#fff;font-size:7pt;font-weight:700}
.check-box{width:14px;height:14px;border:1.5px solid #607d8b;border-radius:2px;display:inline-block}

.remarks-box{border:1px solid #b2dfdb;border-radius:7px;padding:10px 14px;margin-bottom:14px;min-height:38px;background:#f9fdfd}
.remarks-lbl{font-size:7pt;color:#026766;text-transform:uppercase;letter-spacing:.5px;font-weight:700;margin-bottom:4px}

.sig-grid{display:grid;grid-template-columns:1fr 1fr 1fr;gap:20px;margin-top:20px}
.sig-box{border-top:1.5px solid #b2dfdb;padding-top:8px}
.sig-role{font-size:7pt;color:#026766;text-transform:uppercase;letter-spacing:.4px;font-weight:700}
.sig-space{height:40px}
.sig-line{border-bottom:1px dashed #90a4ae;margin:4px 0}
.sig-name{font-size:8pt;color:#546e7a}

.doc-footer{margin-top:18px;padding-top:8px;border-top:1px solid #e0e0e0;display:flex;justify-content:space-between;font-size:6.5pt;color:#90a4ae}
</style>
</head>
<body>

<div id="back-to-app" style="position:fixed;top:10px;right:10px;z-index:9999;">
  <a href="javascript:window.close()" style="background:#0d1f1f;color:white;padding:8px 16px;border-radius:8px;text-decoration:none;font-size:13px;font-weight:600;border:1px solid rgba(255,255,255,0.2);">
    Close &amp; Back to K-one
  </a>
</div>
<style>
  @media print {
    #back-to-app { display: none !important; }
  }
</style>

<div class="print-bar no-print">
  <div class="print-bar-title"><i class="fas fa-clipboard-list"></i> Putaway Sheet — <?= htmlspecialchars($orderNo) ?></div>
  <div class="btns">
    <a class="btn-back" href="http://localhost:5173/">← Kembali</a>
    <button class="btn-print" onclick="window.print()">🖨️ Print / PDF</button>
  </div>
</div>

<div class="document">

  
  <div class="doc-header">
    <div class="logo-area">
      <div class="logo-mark">K</div>
      <div>
        <div class="company-name"><span class="nk">K</span><span class="none">-one</span></div>
      </div>
    </div>
    <div class="doc-title-block">
      <div class="doc-title">PUT AWAY SHEET</div>
      <div class="doc-subtitle">Inbound Putaway Document</div>
      <div class="doc-orderno"><?= htmlspecialchars($orderNo) ?></div>
    </div>
  </div>

  
  <div class="info-grid">
    <div class="info-cell">
      <div class="lbl">Order No.</div>
      <div class="val" style="font-family:monospace"><?= htmlspecialchars($orderNo) ?></div>
    </div>
    <div class="info-cell">
      <div class="lbl">Putaway Date</div>
      <div class="val"><?= date('d/m/Y', strtotime($putawayDate)) ?></div>
    </div>
    <div class="info-cell">
      <div class="lbl">User / Petugas</div>
      <div class="val"><?= htmlspecialchars($userName) ?></div>
    </div>
    <div class="info-cell span2">
      <div class="lbl">Carrier / Transporter</div>
      <div class="val"><?= htmlspecialchars($customer) ?></div>
    </div>
    <div class="info-cell">
      <div class="lbl">Shipment No.</div>
      <div class="val" style="font-family:monospace"><?= htmlspecialchars($shipmentNo ?: '—') ?></div>
    </div>
  </div>

  
  <div class="summary-bar">
    <div class="sum-card sc-teal">
      <div class="num"><?= count($items) ?></div>
      <div class="lbl">Total Items</div>
    </div>
    <div class="sum-card sc-green">
      <div class="num"><?= $totalLines ?></div>
      <div class="lbl">Total Pallet</div>
    </div>
    <div class="sum-card sc-amber">
      <div class="num"><?= number_format($totalQty, 0) ?></div>
      <div class="lbl">Total Qty</div>
    </div>
  </div>

  
  <div class="section-title">📦 Detail Putaway</div>
  <table>
    <thead>
      <tr>
        <th class="c" style="width:24px">No.</th>
        <th>Item Code</th>
        <th>Item Description</th>
        <th>Batch No.</th>
        <th class="c" style="width:28px">Plt</th>
        <th class="r">Qty</th>
        <th class="c">UOM</th>
        <th>OD No. / SO No.</th>
        <th>Actual Location</th>
        <th class="c" style="width:18px">✓</th>
      </tr>
    </thead>
    <tbody>
    <?php $seq = 1; foreach ($putawayRows as $row): ?>
    <tr>
      <td class="c" style="color:#90a4ae;font-size:7.5pt"><?= $seq++ ?></td>
      <td><span style="font-family:monospace;font-weight:700;font-size:8.5pt;color:#013d3c"><?= htmlspecialchars($row['item_code']) ?></span></td>
      <td style="font-size:8.5pt"><?= htmlspecialchars($row['item_desc']) ?></td>
      <td>
        <?php if ($row['batch_no']): ?>
        <span class="chip chip-batch"><?= htmlspecialchars($row['batch_no']) ?></span>
        <?php else: ?>
        <span style="color:#ccc;font-size:7pt">—</span>
        <?php endif; ?>
      </td>
      <td class="c">
        <?php if ($row['pallet_seq'] !== ''): ?>
        <span class="palt-badge">P<?= $row['pallet_seq'] ?></span>
        <?php else: ?>
        <span style="color:#ccc;font-size:7pt">—</span>
        <?php endif; ?>
      </td>
      <td class="r"><?= number_format(floatval($row['qty']), 0) ?></td>
      <td class="c" style="font-size:8pt"><?= htmlspecialchars($row['uom']) ?></td>
      <td>
        <?php if ($row['od_number']): ?><span class="chip chip-od">OD: <?= htmlspecialchars($row['od_number']) ?></span><?php endif; ?>
        <?php if ($row['so_number']): ?>
          <?php if ($row['od_number']): ?><br><?php endif; ?>
          <span class="chip chip-so">SO: <?= htmlspecialchars($row['so_number']) ?></span>
        <?php endif; ?>
        <?php if (!$row['od_number'] && !$row['so_number']): ?><span style="color:#ccc;font-size:7pt">—</span><?php endif; ?>
      </td>
      <td>
        <?php if ($row['location']): ?>
        <span class="chip chip-loc"><?= htmlspecialchars($row['location']) ?></span>
        <?php else: ?>
        <span style="display:inline-block;min-width:90px;border-bottom:1.5px dashed #90a4ae;height:16px"></span>
        <?php endif; ?>
      </td>
      <td class="c"><span class="check-box"></span></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr>
        <td colspan="4" style="text-align:right;padding-right:10px">TOTAL</td>
        <td class="c" style="font-weight:700"><?= $totalLines ?> plt</td>
        <td class="r"><?= number_format($totalQty, 0) ?></td>
        <td colspan="4"></td>
      </tr>
    </tfoot>
  </table>

  
  <div class="remarks-box">
    <div class="remarks-lbl">Remarks / Catatan</div>
    <div style="font-size:8.5pt;color:#546e7a;min-height:16px"><?= htmlspecialchars($notes ?: '') ?></div>
  </div>

  
  <div class="sig-grid">
    <div class="sig-box">
      <div class="sig-role">Prepared By</div>
      <div class="sig-space"></div>
      <div class="sig-line"></div>
      <div class="sig-name"><?= htmlspecialchars($userName) ?></div>
    </div>
    <div class="sig-box">
      <div class="sig-role">Checked By</div>
      <div class="sig-space"></div>
      <div class="sig-line"></div>
      <div class="sig-name">( ......................... )</div>
    </div>
    <div class="sig-box">
      <div class="sig-role">Approved By</div>
      <div class="sig-space"></div>
      <div class="sig-line"></div>
      <div class="sig-name">( ......................... )</div>
    </div>
  </div>

  
  <div class="doc-footer">
    <span>K-one</span>
    <span>Dicetak: <?= date('d F Y H:i') ?> WIB</span>
    <span><?= htmlspecialchars($orderNo) ?></span>
  </div>

</div>

<script>
window.onload = function() {
  setTimeout(function() { window.print(); }, 500);
};
</script>
</body>
</html>

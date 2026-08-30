<?php
session_start();
date_default_timezone_set('Asia/Jakarta');
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/classes/Auth.php';
require_once __DIR__ . '/classes/Outbound.php';

Auth::requireAuth();

$id = $_GET['id'] ?? null;
if (!$id) { header('Location: outbound.php'); exit; }

$outbound = Outbound::getById($id);
if (!$outbound) { header('Location: outbound.php'); exit; }

$displayOrderNo = Outbound::displayOrderNo($outbound);
$items = Outbound::getItems($id);
function calcItemPallet(array $item): int {
    $qty = floatval($item['actual_qty'] ?? $item['quantity'] ?? 0);
    $upp = max(1, intval($item['uom_per_pallet'] ?? 4));
    return (int)ceil($qty / $upp);
}

$db = db();

$dstStmt = $db->prepare("SELECT * FROM outbound_destinations WHERE outbound_id = ? ORDER BY seq");
$dstStmt->execute([$id]);
$destinations = $dstStmt->fetchAll();

$itemsByDest = [];
foreach ($items as $item) {
    $dId = isset($item['destination_id']) && $item['destination_id'] ? (int)$item['destination_id'] : 0;
    $itemsByDest[$dId][] = $item;
}

$itemPickLocations = [];
foreach ($items as $item) {
    $locs = Outbound::getItemPickedLocations($item['id']);
    if (!empty($locs)) {
        $itemPickLocations[$item['id']] = $locs;
    }
}

$totalQty = 0;
$totalPallet = 0;
foreach ($items as $it) {
    $totalQty += floatval($it['actual_qty'] ?? $it['quantity'] ?? 0);
    $totalPallet += calcItemPallet($it);
}
$hasPrimaryDest = !empty($itemsByDest[0]);
$destCount = count($destinations) + ($hasPrimaryDest ? 1 : 0);
$customerNames = [];
foreach ($items as $it) {
    $cn = trim((string)($it['order_customer_name'] ?? $it['customer_name'] ?? ''));
    if ($cn !== '') $customerNames[$cn] = true;
}
$customerList = array_keys($customerNames);
$customerLabel = count($customerList) > 1
    ? ('Multi Customer (' . count($customerList) . ')')
    : ($customerList[0] ?? ($outbound['customer_name'] ?? '—'));
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>Outbound Report - <?= htmlspecialchars($displayOrderNo) ?></title>
<style>
  @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');

  * { margin: 0; padding: 0; box-sizing: border-box; }

  body {
    font-family: 'Inter', Arial, sans-serif;
    font-size: 10.5pt;
    color: #1a1a1a;
    background: #fff;
  }

  @page {
    size: A4;
    margin: 0;
  }

  @media print {
    .no-print { display: none !important; }
    body { -webkit-print-color-adjust: exact; print-color-adjust: exact; background: #fff; }
    .page-break { page-break-before: always; }
  }

  .print-bar {
    background: #013d3c;
    color: #fff;
    padding: 10px 20px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    position: sticky;
    top: 0;
    z-index: 99;
  }
  .print-bar span { font-weight: 600; font-size: 13px; }
  .print-bar .btns { display: flex; gap: 8px; }
  .btn-print {
    background: #fff;
    color: #013d3c;
    border: none;
    padding: 7px 18px;
    border-radius: 6px;
    font-weight: 700;
    cursor: pointer;
    font-size: 13px;
    display: flex;
    align-items: center;
    gap: 6px;
  }
  .btn-back {
    background: transparent;
    color: #fff;
    border: 1.5px solid rgba(255,255,255,.6);
    padding: 7px 18px;
    border-radius: 6px;
    font-weight: 600;
    cursor: pointer;
    font-size: 13px;
    text-decoration: none;
  }

  .document {
    max-width: 794px;
    margin: 20px auto;
    background: #fff;
    padding: 30px 32px;
    box-shadow: 0 2px 20px rgba(0,0,0,.1);
    border-radius: 4px;
  }
  @media print {
    .document { box-shadow: none; margin: 0; padding: 14mm 14mm 16mm; max-width: 100%; border-radius: 0; }
  }

  .doc-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    border-bottom: 3px solid #013d3c;
    padding-bottom: 14px;
    margin-bottom: 18px;
  }
  .doc-logo { display: flex; align-items: center; gap: 12px; }
  .hdr-logo {
    width: 52px; height: 52px;
    background: #013d3c;
    border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 28px; font-weight: 900; color: #fff;
    flex-shrink: 0; letter-spacing: -1px;
  }
  .doc-company { line-height: 1.1; }
  .doc-company .name .nk   { font-size: 24pt; font-weight: 900; color: #013d3c; }
  .doc-company .name .none { font-size: 18pt; font-weight: 400; color: #013d3c; opacity: .7; }
  .doc-title-block { text-align: right; }
  .doc-title-block .title  { font-size: 16pt; font-weight: 700; color: #013d3c; letter-spacing: -.3px; }
  .doc-title-block .sub    { font-size: 8.5pt; color: #78909c; margin-top: 2px; }
  .doc-title-block .number { font-size: 10.5pt; font-weight: 700; color: #1a1a1a; margin-top: 5px; font-family: monospace; }

  .badge {
    display: inline-block;
    padding: 3px 10px;
    border-radius: 20px;
    font-size: 8pt;
    font-weight: 700;
    letter-spacing: .4px;
    text-transform: uppercase;
  }
  .badge-open       { background: #fff8e1; color: #f57f17; border: 1px solid #ffe082; }
  .badge-picking    { background: #e3f2fd; color: #014f4e; border: 1px solid #90caf9; }
  .badge-picked     { background: #e8f5e9; color: #026766; border: 1px solid #a5d6a7; }
  .badge-shipped    { background: #e0f7f7; color: #026766; border: 1px solid #80d2d2; }
  .badge-completed  { background: #e8f5e9; color: #013d3c; border: 1px solid #81c784; }
  .badge-default    { background: #f5f5f5; color: #616161; border: 1px solid #e0e0e0; }

  
  .shipment-box {
    background: linear-gradient(135deg, #e0f7f7, #e0f7f7);
    border: 1.5px solid #80d2d2;
    border-radius: 8px;
    padding: 12px 18px;
    margin-bottom: 14px;
    display: grid;
    grid-template-columns: auto 1fr 1fr;
    gap: 14px;
    align-items: center;
  }
  .shipment-box .ship-num {
    font-family: monospace;
    font-size: 15pt;
    font-weight: 700;
    color: #013d3c;
    border-right: 2px solid #80d2d2;
    padding-right: 14px;
  }
  .shipment-box .ship-lbl { font-size: 7.5pt; color: #026766; text-transform: uppercase; letter-spacing: .4px; }
  .shipment-box .ship-val { font-size: 10pt; font-weight: 600; color: #1a1a1a; }

  .info-grid {
    display: grid;
    grid-template-columns: 1fr 1fr 1fr;
    gap: 0;
    border: 1px solid #dde3ea;
    border-radius: 6px;
    overflow: hidden;
    margin-bottom: 18px;
  }
  .info-cell { padding: 8px 12px; border-right: 1px solid #dde3ea; border-bottom: 1px solid #dde3ea; }
  .info-cell:nth-child(3n) { border-right: none; }
  .info-cell:nth-last-child(-n+3) { border-bottom: none; }
  .info-cell .lbl { font-size: 7.5pt; color: #90a4ae; text-transform: uppercase; letter-spacing: .4px; margin-bottom: 2px; }
  .info-cell .val { font-size: 10pt; font-weight: 600; color: #1a1a1a; }
  .info-cell.span2 { grid-column: span 2; }
  .info-cell.span3 { grid-column: span 3; }

  .summary-bar { display: flex; gap: 12px; margin-bottom: 16px; }
  .sum-card { flex: 1; border-radius: 6px; padding: 10px 14px; text-align: center; }
  .sum-card .num { font-size: 18pt; font-weight: 700; }
  .sum-card .lbl { font-size: 7.5pt; text-transform: uppercase; letter-spacing: .4px; margin-top: 2px; }
  .sc-purple { background: #e0f7f7; color: #013d3c; }
  .sc-green  { background: #e8f5e9; color: #013d3c; }
  .sc-amber  { background: #fff8e1; color: #f57f17; }
  .sc-teal   { background: #e0f2f1; color: #013d3c; }

  .section-title {
    font-size: 10pt; font-weight: 700; color: #013d3c;
    border-left: 4px solid #013d3c; padding-left: 8px;
    margin-bottom: 8px; margin-top: 16px;
    text-transform: uppercase; letter-spacing: .5px;
  }

  
  .dest-block {
    border: 1px solid #dde3ea;
    border-radius: 8px;
    overflow: hidden;
    margin-bottom: 10px;
  }
  .dest-header {
    padding: 8px 14px;
    display: flex;
    align-items: center;
    gap: 10px;
  }
  .dest-header.primary { background: linear-gradient(90deg, #e0f2f1, #f0fdf9); border-bottom: 1px solid #b2dfdb; }
  .dest-header.secondary { background: linear-gradient(90deg, #e8f5e9, #f1f8e9); border-bottom: 1px solid #c8e6c9; }
  .dest-seq {
    width: 22px; height: 22px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 8pt; font-weight: 700; color: #fff; flex-shrink: 0;
  }
  .dest-seq.primary   { background: #026766; }
  .dest-seq.secondary { background: #026766; }
  .dest-name { font-weight: 700; font-size: 9.5pt; }
  .dest-name.primary   { color: #013d3c; }
  .dest-name.secondary { color: #013d3c; }
  .dest-loc  { font-size: 8pt; color: #607d8b; margin-top: 1px; }
  .dest-tag  { font-size: 7.5pt; font-weight: 700; padding: 2px 8px; border-radius: 10px; margin-left: auto; }
  .dest-tag.primary   { background: #b2dfdb; color: #026766; }
  .dest-tag.secondary { background: #c8e6c9; color: #026766; }

  table { width: 100%; border-collapse: collapse; font-size: 8.5pt; margin-bottom: 0; }
  thead th {
    background: #013d3c;
    color: #fff;
    padding: 6px 7px;
    text-align: left;
    font-weight: 600;
    font-size: 7.5pt;
    letter-spacing: .3px;
  }
  thead th.num { text-align: center; }
  thead th.right { text-align: right; }
  tbody tr:nth-child(even) { background: #f0fbfb; }
  tbody td { padding: 5px 7px; border-bottom: 1px solid #e0f7f7; vertical-align: top; line-height: 1.35; }
  tbody td.num { text-align: center; }
  tbody td.right { text-align: right; font-weight: 600; }
  tfoot td {
    padding: 6px 7px; font-weight: 700; font-size: 9pt;
    border-top: 2px solid #013d3c; background: #e0f7f7;
  }
  tfoot td.right { text-align: right; }

  .dest-table { border-radius: 0; }
  .sku { font-family: monospace; font-size: 7.5pt; color: #546e7a; }
  .mono { font-family: monospace; font-size: 8pt; }
  .loc-badge {
    display: inline-block;
    background: #e0f7f7;
    color: #013d3c;
    border-radius: 4px;
    padding: 1px 5px;
    font-family: monospace;
    font-size: 7.5pt;
    font-weight: 600;
  }
  .exp-warn { color: #014f4e; font-weight: 700; }
  .fefo-tag {
    display: inline-block;
    background: #e8f5e9;
    color: #026766;
    border-radius: 3px;
    padding: 1px 5px;
    font-size: 7pt;
    font-weight: 700;
    letter-spacing: .5px;
    margin-left: 4px;
  }

  .sig-grid { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 20px; margin-top: 28px; }
  .sig-box { border-top: 1.5px solid #bdbdbd; padding-top: 8px; }
  .sig-box .role { font-size: 8pt; color: #78909c; text-transform: uppercase; letter-spacing: .4px; }
  .sig-box .space { height: 40px; }
  .sig-box .name-line { border-bottom: 1px dashed #bdbdbd; margin: 4px 0 4px; }

  .doc-footer {
    margin-top: 24px; padding-top: 10px; border-top: 1px solid #e0e0e0;
    display: flex; justify-content: space-between; font-size: 7.5pt; color: #90a4ae;
  }
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
  <span>🖨️ Outbound Report Preview</span>
  <div class="btns">
    <a class="btn-back" href="http://localhost:5173/">← Kembali</a>
    <button class="btn-print" onclick="window.print()">🖨️ Print / Save PDF</button>
  </div>
</div>

<div class="document">

  
  <div class="doc-header">
    <div class="doc-logo">
      <div class="hdr-logo">K</div>
      <div class="doc-company">
        <div class="name"><span class="nk">K</span><span class="none">-one</span></div>
        <div class="sub">secondary administration — Warehouse Management</div>
      </div>
    </div>
    <div class="doc-title-block">
      <div class="title">OUTBOUND REPORT</div>
      <div class="sub">Delivery Order Document</div>
      <div class="number"><?= htmlspecialchars($displayOrderNo) ?></div>
      <div style="margin-top:5px">
        <?php
        $s = $outbound['status'] ?? 'Open';
        $cls = strtolower($s);
        ?>
        <span class="badge badge-<?= htmlspecialchars($cls) ?>"><?= htmlspecialchars($s) ?></span>
      </div>
    </div>
  </div>

  
  <?php if ($outbound['shipment_number'] ?? ''): ?>
  <div class="shipment-box">
    <div>
      <div class="ship-lbl">Shipment Number</div>
      <div class="ship-num"><?= htmlspecialchars($outbound['shipment_number']) ?></div>
    </div>
    <div>
      <div class="ship-lbl">Customer</div>
      <div class="ship-val"><?= htmlspecialchars($outbound['ship_to_name'] ?: $customerLabel) ?></div>
      <?php if (!empty($destinations)): ?>
      <div style="font-size:8.5pt;color:#78909c;margin-top:2px">📍 Multi Tujuan (<?= count($destinations) ?>)</div>
      <?php endif; ?>
    </div>
    <div>
      <div class="ship-lbl">Expected Date</div>
      <div class="ship-val"><?= $outbound['expected_date'] ? date('d F Y', strtotime($outbound['expected_date'])) : '—' ?></div>
    </div>
  </div>
  <?php endif; ?>

  
  <div class="section-title">📋 Detail Order</div>
  <div class="info-grid">
    <div class="info-cell">
      <div class="lbl">Customer</div>
      <div class="val"><?= htmlspecialchars($outbound['customer_name'] ?? '—') ?></div>
    </div>
    <div class="info-cell">
      <div class="lbl">Order Date</div>
      <div class="val"><?= date('d F Y', strtotime($outbound['order_date'])) ?></div>
    </div>
    <div class="info-cell">
      <div class="lbl">Expected Date</div>
      <div class="val"><?= $outbound['expected_date'] ? date('d F Y', strtotime($outbound['expected_date'])) : '—' ?></div>
    </div>
    <div class="info-cell">
      <div class="lbl">Armada No</div>
      <div class="val"><?= htmlspecialchars($outbound['armada_no'] ?? '—') ?></div>
    </div>
    <div class="info-cell">
      <div class="lbl">Jenis Armada</div>
      <div class="val"><?= htmlspecialchars($outbound['jenis_armada'] ?? '—') ?></div>
    </div>
    <div class="info-cell">
      <div class="lbl">Container No</div>
      <div class="val"><?= htmlspecialchars($outbound['container_no'] ?? '—') ?></div>
    </div>
    <?php if ($outbound['notes'] ?? ''): ?>
    <div class="info-cell span3">
      <div class="lbl">Notes</div>
      <div class="val" style="font-weight:400"><?= htmlspecialchars($outbound['notes']) ?></div>
    </div>
    <?php endif; ?>
  </div>

  
  <div class="summary-bar">
    <div class="sum-card sc-purple">
      <div class="num"><?= count($items) ?></div>
      <div class="lbl">Total Lines</div>
    </div>
    <div class="sum-card sc-green">
      <div class="num"><?= number_format($totalQty, 0) ?></div>
      <div class="lbl">Total Qty</div>
    </div>
    <div class="sum-card sc-amber">
      <div class="num"><?= number_format($totalPallet, 0) ?></div>
      <div class="lbl">Total Pallets</div>
    </div>
    <div class="sum-card sc-teal">
      <div class="num"><?= $destCount ?></div>
      <div class="lbl">Tujuan</div>
    </div>
  </div>

  <?php
  
  function renderItemsTable(array $items, array $itemPickLocations): void {
      echo '<table class="dest-table">';
      echo '<thead><tr>
        <th style="width:24px">#</th>
        <th style="width:90px">OD No.</th>
        <th style="width:80px">SO No.</th>
        <th>Product / SKU</th>
        <th>Batch No</th>
        <th style="width:120px">Customer</th>
        <th class="right" style="width:50px">Qty</th>
        <th class="num" style="width:38px">UOM</th>
        <th class="right" style="width:38px">Plt</th>
        <th style="width:100px">Lokasi</th>
        <th style="width:80px">Expiry</th>
      </tr></thead><tbody>';

      $subtotalQty = 0;
      $subtotalPlt = 0;
      foreach ($items as $i => $item) {
          $dispBatch  = $item['batch_number'] ?? $item['batch_no'] ?? '—';
          $dispExpiry = $item['expiry_date']  ?? $item['exp_date'] ?? null;
          $warnExp    = $dispExpiry && strtotime($dispExpiry) < strtotime('+3 months');
          $dispPlt    = calcItemPallet($item);
          $pickLocs   = $itemPickLocations[$item['id']] ?? [];
          $qty        = (float)($item['actual_qty'] ?? $item['quantity'] ?? 0);
          $subtotalQty += $qty;
          $subtotalPlt += $dispPlt;

          echo '<tr>';
          echo '<td class="num" style="color:#90a4ae">' . ($i + 1) . '</td>';
          
          echo '<td class="mono" style="font-size:7.5pt">' . htmlspecialchars($item['od_number'] ?? '—') . '</td>';
          
          echo '<td class="mono" style="font-size:7.5pt;color:#026766">' . htmlspecialchars($item['so_number'] ?? '—') . '</td>';
          
          echo '<td><div style="font-weight:600;color:#1a1a1a;font-size:8.5pt">' . htmlspecialchars($item['product_name'] ?? '—') . '</div>';
          echo '<div class="sku">' . htmlspecialchars($item['product_code'] ?? '') . '</div></td>';
          
          echo '<td class="mono">' . htmlspecialchars($dispBatch) . '</td>';
          
          echo '<td>' . htmlspecialchars($item['order_customer_name'] ?? $item['customer_name'] ?? '—') . '</td>';
          
          echo '<td class="right">' . number_format($qty, 0) . '</td>';
          
          echo '<td class="num">' . htmlspecialchars($item['uom'] ?? '—') . '</td>';
          
          echo '<td class="right">' . $dispPlt . '</td>';
          
          echo '<td>';
          if (!empty($pickLocs)) {
              echo '<div style="font-size:7pt;line-height:1.8">';
              foreach ($pickLocs as $pl) {
                  echo '<span style="display:inline-block;background:#e8f5e9;color:#013d3c;border-radius:3px;padding:1px 5px;margin:1px 0;font-family:monospace;font-weight:600;font-size:6.5pt;white-space:nowrap">'
                      . htmlspecialchars($pl['location_code']) . '(' . number_format($pl['picked_qty'], 0) . ')</span> ';
              }
              echo '</div>';
          } else {
              echo '<span class="loc-badge">' . htmlspecialchars($item['location'] ?? '—') . '</span>';
          }
          echo '</td>';
          
          echo '<td><span class="' . ($warnExp ? 'exp-warn' : '') . '">' . ($dispExpiry ? date('d/m/Y', strtotime($dispExpiry)) : '—') . '</span></td>';
          echo '</tr>';
      }

      if (empty($items)) {
          echo '<tr><td colspan="11" style="text-align:center;color:#90a4ae;padding:16px">Tidak ada item</td></tr>';
      }

      echo '</tbody><tfoot><tr>';
      echo '<td colspan="6"><strong>SUBTOTAL</strong></td>';
      echo '<td class="right">' . number_format($subtotalQty, 0) . '</td>';
      echo '<td></td>';
      echo '<td class="right">' . number_format($subtotalPlt, 0) . '</td>';
      echo '<td colspan="2"><span class="fefo-tag">FEFO</span></td>';
      echo '</tr></tfoot></table>';
  }
  ?>

  
  <div class="section-title">🚚 Tujuan Pengiriman &amp; Produk</div>

  
  <?php
  $primaryName   = $outbound['ship_to_name'] ?: ($outbound['customer_name'] ?? 'Tujuan Utama');
  $primaryLoc    = $outbound['ship_to_location'] ?? $outbound['kota'] ?? '';
  $primaryStreet = $outbound['ship_to_street'] ?? '';
  $primaryItems  = $itemsByDest[0] ?? [];
  ?>
  <?php if ($hasPrimaryDest): ?>
  <div class="dest-block">
    <div class="dest-header primary">
      <span class="dest-seq primary">1</span>
      <div>
        <div class="dest-name primary"><?= htmlspecialchars($primaryName) ?></div>
        <?php if ($primaryLoc): ?>
        <div class="dest-loc">📍 <?= htmlspecialchars($primaryLoc . ($primaryStreet ? ' — ' . $primaryStreet : '')) ?></div>
        <?php endif; ?>
      </div>
      <span class="dest-tag primary">Tujuan Utama</span>
    </div>
    <div style="padding:0">
      <?php renderItemsTable($primaryItems, $itemPickLocations); ?>
    </div>
  </div>
  <?php endif; ?>

  
  <?php foreach ($destinations as $di => $dst): ?>
  <?php $dstItems = $itemsByDest[$dst['id']] ?? []; ?>
  <div class="dest-block">
    <div class="dest-header secondary">
      <?php $labelNo = ($hasPrimaryDest ? 2 : 1) + $di; ?>
      <span class="dest-seq secondary"><?= $labelNo ?></span>
      <div>
        <div class="dest-name secondary"><?= htmlspecialchars($dst['ship_to_name'] ?? '—') ?></div>
        <?php
        $dstLocParts = array_filter([
            $dst['ship_to_location'] ?? '',
            
            (($dst['kota'] ?? '') && ($dst['kota'] ?? '') !== ($dst['ship_to_location'] ?? '')) ? $dst['kota'] : '',
            $dst['street_address'] ?? '',
        ]);
        if (!empty($dstLocParts)):
        ?>
        <div class="dest-loc">📍 <?= htmlspecialchars(implode(' — ', $dstLocParts)) ?></div>
        <?php endif; ?>
      </div>
      <span class="dest-tag secondary">Tujuan <?= $labelNo ?></span>
    </div>
    <div style="padding:0">
      <?php renderItemsTable($dstItems, $itemPickLocations); ?>
    </div>
  </div>
  <?php endforeach; ?>

  
  <?php if (!empty($destinations)): ?>
  <table style="margin-top:4px">
    <tfoot>
      <tr>
        <td colspan="5" style="padding:7px 8px;font-weight:700;font-size:9.5pt;border-top:2px solid #013d3c;background:#e0f7f7">
          GRAND TOTAL — <?= $destCount ?> Tujuan
        </td>
        <td class="right" style="padding:7px 8px;font-weight:700;font-size:9.5pt;border-top:2px solid #013d3c;background:#e0f7f7"><?= number_format($totalQty, 0) ?></td>
        <td style="padding:7px 8px;border-top:2px solid #013d3c;background:#e0f7f7"></td>
        <td class="right" style="padding:7px 8px;font-weight:700;font-size:9.5pt;border-top:2px solid #013d3c;background:#e0f7f7"><?= number_format($totalPallet, 0) ?></td>
        <td colspan="2" style="padding:7px 8px;border-top:2px solid #013d3c;background:#e0f7f7"></td>
      </tr>
    </tfoot>
  </table>
  <?php endif; ?>

  
  <div class="sig-grid">
    <div class="sig-box">
      <div class="role">Dibuat Oleh</div>
      <div class="space"></div>
      <div class="name-line"></div>
      <div style="font-size:8pt;color:#546e7a"><?= htmlspecialchars($outbound['created_by_name'] ?? 'Warehouse Staff') ?></div>
    </div>
    <div class="sig-box">
      <div class="role">Driver / Kurir</div>
      <div class="space"></div>
      <div class="name-line"></div>
      <div style="font-size:8pt;color:#546e7a"><?= htmlspecialchars($outbound['armada_no'] ?? '( .............. )') ?></div>
    </div>
    <div class="sig-box">
      <div class="role">Penerima</div>
      <div class="space"></div>
      <div class="name-line"></div>
      <div style="font-size:8pt;color:#546e7a"><?= htmlspecialchars($customerLabel) ?></div>
    </div>
  </div>

  
  <div class="doc-footer">
    <span>K-one — Warehouse Management System</span>
    <span>Dicetak: <?= date('d F Y H:i') ?> WIB</span>
    <span><?= htmlspecialchars($displayOrderNo) ?></span>
  </div>

</div>

<script>
window.onload = function() {
  setTimeout(function() { window.print(); }, 500);
};
</script>

</body>
</html>

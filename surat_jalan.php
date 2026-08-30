<?php
session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/classes/Auth.php';
require_once __DIR__ . '/classes/Outbound.php';

Auth::requireAuth();

$id = $_GET['id'] ?? null;
if (!$id) { header('Location: outbound.php'); exit; }

$outbound = Outbound::getById($id);
if (!$outbound) { header('Location: outbound.php'); exit; }

$items = Outbound::getItems($id);
function calcItemPalletSJ(array $item): int {
    $qty = floatval($item['actual_qty'] ?? $item['quantity'] ?? 0);
    $upp = max(1, intval($item['uom_per_pallet'] ?? 4));
    return (int)ceil($qty / $upp);
}

$itemPickLocations = [];
foreach ($items as $item) {
    $locs = Outbound::getItemPickedLocations($item['id']);
    if (!empty($locs)) $itemPickLocations[$item['id']] = $locs;
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

$totalQty    = 0;
$totalPallet = 0;
$totalWeight = 0;
foreach ($items as $item) {
    $totalQty    += floatval($item['actual_qty'] ?? $item['quantity'] ?? 0);
    $totalPallet += calcItemPalletSJ($item);
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

$dispatchDate  = $outbound['expected_date'] ?? $outbound['order_date'] ?? date('Y-m-d');
$warehouseName = 'Surabaya Oso 5 Non BLC Covered';

$shipToName    = $outbound['ship_to_name'] ?: $customerLabel;
$shipToAddr    = $outbound['ship_to_location'] ?? $outbound['kota'] ?? $outbound['destination'] ?? '—';
$shipToCity    = $outbound['kota'] ?? $outbound['ship_to_location'] ?? '—';
$shipToStreet  = $outbound['ship_to_street'] ?? '';
$orderNo       = $outbound['order_number'] ?? $outbound['outbound_number'] ?? '—';

$primaryDestName = $outbound['ship_to_name'] ?: $customerLabel;
$primaryDestCity = $outbound['ship_to_location'] ?? $outbound['kota'] ?? '';
$primaryDestAddr = $outbound['ship_to_street'] ?? '';
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>Surat Jalan - <?= htmlspecialchars($orderNo) ?></title>
<style>
  @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');
  * { margin:0; padding:0; box-sizing:border-box; }
  body { font-family:'Inter',Arial,sans-serif; font-size:10pt; color:#1a1a1a; background:#fff; }

  @page { size:A4; margin:0; }
  @media print {
    .no-print { display:none !important; }
    body { -webkit-print-color-adjust:exact; print-color-adjust:exact; background:#fff; }
    .page-break { page-break-before:always; }
  }

  .print-bar {
    background:#013d3c; color:#fff; padding:10px 20px;
    display:flex; align-items:center; justify-content:space-between;
    position:sticky; top:0; z-index:99;
  }
  .print-bar span { font-weight:600; font-size:13px; }
  .print-bar .btns { display:flex; gap:8px; }
  .btn-print {
    background:#fff; color:#013d3c; border:none; padding:7px 18px;
    border-radius:6px; font-weight:700; cursor:pointer; font-size:13px;
  }
  .btn-back {
    background:transparent; color:#fff; border:1.5px solid rgba(255,255,255,.6);
    padding:7px 18px; border-radius:6px; font-weight:600; font-size:13px;
    text-decoration:none;
  }

  .document {
    max-width:794px; margin:20px auto; background:#fff;
    padding:26px 30px; box-shadow:0 2px 20px rgba(0,0,0,.1); border-radius:4px;
  }
  @media print {
    .document { box-shadow:none; margin:0; padding:14mm 14mm 16mm; max-width:100%; border-radius:0; }
  }

  
  .doc-header {
    display:flex; justify-content:space-between; align-items:flex-start;
    border-bottom:3px solid #013d3c; padding-bottom:12px; margin-bottom:16px;
  }
  .doc-logo-area { display:flex; align-items:center; gap:10px; }
  .logo-box {
    width:44px; height:44px; background:linear-gradient(135deg,#013d3c,#43a047);
    border-radius:8px; display:flex; align-items:center; justify-content:center;
    color:#fff; font-size:20px; font-weight:700;
  }
  .company-name { font-size:13pt; font-weight:700; color:#013d3c; }
  .company-sub  { font-size:8pt; color:#607d8b; margin-top:1px; }
  .doc-title    { font-size:16pt; font-weight:700; color:#013d3c; text-align:right; }
  .doc-number   { font-size:9.5pt; font-weight:700; font-family:monospace; text-align:right; margin-top:3px; }

  
  .ref-box {
    background:linear-gradient(135deg,#e8f5e9,#f1f8e9);
    border:1.5px solid #a5d6a7; border-radius:8px;
    padding:10px 16px; margin-bottom:14px;
    display:grid; grid-template-columns:auto 1fr 1fr; gap:16px; align-items:center;
  }
  .ref-num {
    font-family:monospace; font-size:14pt; font-weight:700; color:#013d3c;
    border-right:2px solid #a5d6a7; padding-right:16px;
  }
  .ref-lbl { font-size:7pt; color:#026766; text-transform:uppercase; letter-spacing:.4px; }
  .ref-val { font-size:10pt; font-weight:600; color:#1a1a1a; }

  
  .info-grid {
    display:grid; grid-template-columns:1fr 1fr 1fr;
    border:1px solid #dde3ea; border-radius:6px; overflow:hidden; margin-bottom:14px;
  }
  .ic { padding:7px 11px; border-right:1px solid #dde3ea; border-bottom:1px solid #dde3ea; }
  .ic:nth-child(3n) { border-right:none; }
  .ic:nth-last-child(-n+3) { border-bottom:none; }
  .ic .lbl { font-size:7pt; color:#90a4ae; text-transform:uppercase; letter-spacing:.4px; margin-bottom:1px; }
  .ic .val { font-size:9.5pt; font-weight:600; color:#1a1a1a; }
  .ic.span2 { grid-column:span 2; }
  .ic.span3 { grid-column:span 3; }

  
  .dest-block {
    border:1.5px solid #c8e6c9; border-radius:8px;
    background:#f1f8e9; padding:10px 14px; margin-bottom:14px;
  }
  .dest-block .dt { font-size:7.5pt; color:#026766; font-weight:700; text-transform:uppercase;
                    letter-spacing:.4px; margin-bottom:5px; }
  .dest-item { font-size:9.5pt; margin-bottom:2px; }
  .dest-addr { font-size:8.5pt; color:#546e7a; margin-top:1px; }

  
  .summary-bar { display:flex; gap:10px; margin-bottom:14px; }
  .sc { flex:1; border-radius:6px; padding:8px 12px; text-align:center; }
  .sc .num { font-size:16pt; font-weight:700; }
  .sc .lbl { font-size:7pt; text-transform:uppercase; letter-spacing:.4px; }
  .sc-green  { background:#e8f5e9; color:#013d3c; }
  .sc-blue   { background:#e3f0ff; color:#026766; }
  .sc-amber  { background:#fff8e1; color:#f57f17; }
  .sc-teal   { background:#e0f2f1; color:#013d3c; }

  
  .sec-title {
    font-size:9pt; font-weight:700; color:#013d3c;
    border-left:4px solid #013d3c; padding-left:8px;
    margin-bottom:8px; margin-top:14px;
    text-transform:uppercase; letter-spacing:.5px;
  }

  
  table { width:100%; border-collapse:collapse; font-size:8.5pt; }
  thead th {
    background:#013d3c; color:#fff; padding:6px 8px;
    text-align:left; font-size:7.5pt; font-weight:600; letter-spacing:.3px;
  }
  thead th.c { text-align:center; }
  thead th.r { text-align:right; }
  tbody tr:nth-child(even) { background:#f1f8e9; }
  tbody td { padding:6px 8px; border-bottom:1px solid #dde9ee; vertical-align:top; line-height:1.4; }
  tbody td.c { text-align:center; }
  tbody td.r { text-align:right; font-weight:600; }
  tfoot td {
    padding:7px 8px; font-weight:700; font-size:9pt;
    border-top:2px solid #013d3c; background:#e8f5e9;
  }
  tfoot td.r { text-align:right; }

  .sku { font-family:monospace; font-size:7.5pt; color:#546e7a; }
  .batch-tag {
    display:inline-block; background:#f3f4f6; color:#374151;
    border-radius:3px; padding:1px 5px; font-family:monospace; font-size:7.5pt;
  }
  .od-tag {
    display:inline-block; background:#e0f7f7; color:#026766;
    border-radius:3px; padding:1px 5px; font-family:monospace; font-size:7pt; font-weight:700;
  }
  .so-tag {
    display:inline-block; background:#e0f7f7; color:#026766;
    border-radius:3px; padding:1px 5px; font-family:monospace; font-size:7pt; font-weight:700;
  }
  .loc-tag {
    display:inline-block; background:#e8f5e9; color:#013d3c;
    border-radius:3px; padding:1px 5px; font-family:monospace; font-size:7pt; font-weight:600;
  }

  
  .dest-section {
    border:1px solid #dde3ea; border-radius:6px; overflow:hidden; margin-bottom:12px;
  }
  .dest-header {
    background:#e8f5e9; padding:8px 12px;
    display:flex; align-items:center; gap:8px;
    border-bottom:1px solid #c8e6c9;
  }
  .dest-header .num-badge {
    background:#013d3c; color:#fff; border-radius:50%;
    width:20px; height:20px; display:flex; align-items:center; justify-content:center;
    font-size:8pt; font-weight:700; flex-shrink:0;
  }
  .dest-header .dest-name { font-weight:700; font-size:9.5pt; color:#013d3c; }
  .dest-header .dest-addr { font-size:8pt; color:#546e7a; margin-left:auto; }

  
  .sig-grid { display:grid; grid-template-columns:1fr 1fr 1fr; gap:20px; margin-top:24px; }
  .sig-box { border-top:1.5px solid #bdbdbd; padding-top:8px; }
  .sig-role  { font-size:7.5pt; color:#78909c; text-transform:uppercase; letter-spacing:.4px; }
  .sig-space { height:40px; }
  .sig-line  { border-bottom:1px dashed #bdbdbd; margin:4px 0 4px; }
  .sig-name  { font-size:8pt; color:#546e7a; }

  .doc-footer {
    margin-top:20px; padding-top:8px; border-top:1px solid #e0e0e0;
    display:flex; justify-content:space-between; font-size:7pt; color:#90a4ae;
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
  <span>🚚 Surat Jalan — <?= htmlspecialchars($orderNo) ?></span>
  <div class="btns">
    <a class="btn-back" href="http://localhost:5173/">← Kembali</a>
    <button class="btn-print" onclick="window.print()">🖨️ Print / Save PDF</button>
  </div>
</div>

<div class="document">

  
  <div class="doc-header">
    <div class="doc-logo-area">
      <div class="logo-box">W</div>
      <div>
        <div class="company-name">K-one</div>
        <div class="company-sub"><?= htmlspecialchars($warehouseName) ?> — K-one</div>
      </div>
    </div>
    <div>
      <div class="doc-title">SURAT JALAN</div>
      <div class="doc-number"><?= htmlspecialchars($orderNo) ?></div>
      <div style="text-align:right;margin-top:4px">
        <?php
        $s = $outbound['status'] ?? 'Open';
        $badgeColors = ['Completed'=>'#013d3c','Shipped'=>'#014f4e','Picked'=>'#026766','Open'=>'#e65100'];
        $bc = $badgeColors[$s] ?? '#546e7a';
        ?>
        <span style="background:<?=$bc?>;color:#fff;padding:2px 9px;border-radius:10px;font-size:8pt;font-weight:700"><?= htmlspecialchars($s) ?></span>
      </div>
    </div>
  </div>

  
  <?php if ($outbound['shipment_number'] ?? ''): ?>
  <div class="ref-box">
    <div>
      <div class="ref-lbl">Shipment Number</div>
      <div class="ref-num"><?= htmlspecialchars($outbound['shipment_number']) ?></div>
    </div>
    <div>
      <div class="ref-lbl">Ship-To Party</div>
      <div class="ref-val"><?= htmlspecialchars($shipToName) ?></div>
      <?php if (!empty($destinations)): ?>
      <div style="font-size:8pt;color:#546e7a">📍 Multi Tujuan (<?= count($destinations) ?>)</div>
      <?php endif; ?>
    </div>
    <div>
      <div class="ref-lbl">Delivery Address</div>
      <div class="ref-val" style="font-size:9pt"><?= htmlspecialchars(
          ($shipToStreet ?: ($shipToAddr !== '—' ? $shipToAddr : '—'))
      ) ?></div>
      <?php if ($shipToStreet && $shipToAddr !== '—'): ?>
      <div style="font-size:8pt;color:#546e7a"><?= htmlspecialchars($shipToAddr) ?></div>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  
  <div class="info-grid">
    <div class="ic">
      <div class="lbl">Pengirim (Shipper)</div>
      <div class="val"><?= htmlspecialchars($customerLabel) ?></div>
    </div>
    <div class="ic">
      <div class="lbl">Tanggal Kirim</div>
      <div class="val"><?= date('d F Y', strtotime($dispatchDate)) ?></div>
    </div>
    <div class="ic">
      <div class="lbl">Client DO No.</div>
      <div class="val" style="font-family:monospace"><?= htmlspecialchars($outbound['do_number'] ?? '—') ?></div>
    </div>
    <div class="ic">
      <div class="lbl">SO Number</div>
      <div class="val" style="font-family:monospace"><?= htmlspecialchars($outbound['so_number'] ?? '—') ?></div>
    </div>
    <div class="ic">
      <div class="lbl">Nomor Kendaraan</div>
      <div class="val"><?= htmlspecialchars($outbound['armada_no'] ?? '—') ?></div>
    </div>
    <div class="ic">
      <div class="lbl">Jenis Kendaraan</div>
      <div class="val"><?= htmlspecialchars($outbound['jenis_armada'] ?? '—') ?></div>
    </div>
    <div class="ic">
      <div class="lbl">Container No.</div>
      <div class="val" style="font-family:monospace"><?= htmlspecialchars($outbound['container_no'] ?? '—') ?></div>
    </div>
    <div class="ic span2">
      <div class="lbl">Tujuan Pengiriman</div>
      <div class="val"><?= htmlspecialchars($shipToName . ($shipToCity && $shipToCity !== '—' ? ' — ' . $shipToCity : '')) ?></div>
      <?php if ($shipToAddr && $shipToAddr !== '—'): ?>
      <div class="dest-addr"><?= htmlspecialchars($shipToAddr) ?></div>
      <?php endif; ?>
    </div>
  </div>

  
  <div class="summary-bar">
    <div class="sc sc-green">
      <div class="num"><?= count($items) ?></div>
      <div class="lbl">Total Lines</div>
    </div>
    <div class="sc sc-blue">
      <div class="num"><?= number_format($totalQty, 0) ?></div>
      <div class="lbl">Total Qty</div>
    </div>
    <div class="sc sc-amber">
      <div class="num"><?= number_format($totalPallet, 0) ?></div>
      <div class="lbl">Total Pallets</div>
    </div>
    <div class="sc sc-teal">
      <div class="num"><?= $destCount ?></div>
      <div class="lbl">Tujuan</div>
    </div>
  </div>

  
  <div class="sec-title">📦 Detail Barang</div>
  <table>
    <thead>
      <tr>
        <th style="width:24px" class="c">S.No</th>
        <th>Item Code</th>
        <th>Item Description</th>
        <th>Batch No.</th>
        <th>Customer</th>
        <th class="c">UOM</th>
        <th class="r">Qty</th>
        <th class="r">Pallets</th>
        <th>Lokasi Ambil</th>
        <th>OD / SO No.</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($items as $i => $item):
      $dispBatch  = $item['batch_number'] ?? $item['batch_no'] ?? '—';
      $dispQty    = floatval($item['actual_qty'] ?? $item['quantity'] ?? 0);
      $dispPlt    = calcItemPalletSJ($item);
      $pickLocs   = $itemPickLocations[$item['id']] ?? [];
      $uniqLocs   = array_unique(array_column($pickLocs, 'location_code'));
    ?>
    <tr>
      <td class="c" style="color:#90a4ae;font-size:7.5pt"><?= $i+1 ?></td>
      <td>
        <div style="font-family:monospace;font-weight:700;font-size:8pt"><?= htmlspecialchars($item['product_code'] ?? '') ?></div>
      </td>
      <td>
        <div style="font-weight:600"><?= htmlspecialchars($item['product_name'] ?? '—') ?></div>
      </td>
      <td><span class="batch-tag"><?= htmlspecialchars($dispBatch) ?></span></td>
      <td><?= htmlspecialchars($item['order_customer_name'] ?? $item['customer_name'] ?? '—') ?></td>
      <td class="c"><?= htmlspecialchars($item['uom'] ?? '—') ?></td>
      <td class="r"><?= number_format($dispQty, 0) ?></td>
      <td class="r"><?= $dispPlt ?></td>
      <td>
        <?php if (!empty($uniqLocs)): ?>
          <?php foreach ($uniqLocs as $ul): ?>
          <span class="loc-tag"><?= htmlspecialchars($ul) ?></span>
          <?php endforeach; ?>
        <?php elseif ($item['location'] ?? ''): ?>
          <span class="loc-tag"><?= htmlspecialchars($item['location']) ?></span>
        <?php else: ?>
          <span style="color:#cfd8dc;font-size:7.5pt">—</span>
        <?php endif; ?>
      </td>
      <td>
        <?php if (!empty($item['od_number'])): ?>
        <span class="od-tag">OD: <?= htmlspecialchars($item['od_number']) ?></span>
        <?php endif; ?>
        <?php if (!empty($item['so_number'])): ?>
        <br><span class="so-tag">SO: <?= htmlspecialchars($item['so_number']) ?></span>
        <?php endif; ?>
        <?php if (empty($item['od_number']) && empty($item['so_number'])): ?>
        <span style="color:#cfd8dc;font-size:7.5pt">—</span>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr>
        <td colspan="6"><strong>Grand Total</strong></td>
        <td class="r"><?= number_format($totalQty, 0) ?></td>
        <td class="r"><?= number_format($totalPallet, 0) ?></td>
        <td colspan="2"><span style="font-size:7.5pt;font-weight:400">Total packages: <?= count($items) ?></span></td>
      </tr>
    </tfoot>
  </table>

  
  <?php if ($destCount > 0): ?>
  <div class="sec-title">📍 Tujuan Pengiriman</div>

  <?php if ($hasPrimaryDest): ?>
  <div class="dest-section">
    <div class="dest-header">
      <div class="num-badge">1</div>
      <div>
        <div class="dest-name"><?= htmlspecialchars($primaryDestName ?: 'Tujuan Utama') ?></div>
        <?php if ($primaryDestCity): ?>
        <div style="font-size:8pt;color:#546e7a"><?= htmlspecialchars($primaryDestCity) ?></div>
        <?php endif; ?>
      </div>
      <?php if ($primaryDestAddr): ?>
      <div class="dest-addr"><?= htmlspecialchars($primaryDestAddr) ?></div>
      <?php elseif ($primaryDestCity): ?>
      <div class="dest-addr"><?= htmlspecialchars($primaryDestCity) ?></div>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <?php foreach ($destinations as $di => $dst): ?>
  <?php $labelNo = ($hasPrimaryDest ? 2 : 1) + $di; ?>
  <div class="dest-section">
    <div class="dest-header">
      <div class="num-badge"><?= $labelNo ?></div>
      <div>
        <div class="dest-name"><?= htmlspecialchars($dst['ship_to_name'] ?? '—') ?></div>
        <?php if ($dst['ship_to_location'] ?? ''): ?>
        <div style="font-size:8pt;color:#546e7a"><?= htmlspecialchars($dst['ship_to_location']) ?></div>
        <?php endif; ?>
      </div>
      <?php if ($dst['ship_to_street'] ?? ''): ?>
      <div class="dest-addr"><?= htmlspecialchars($dst['ship_to_street']) ?></div>
      <?php elseif ($dst['kota'] ?? ''): ?>
      <div class="dest-addr"><?= htmlspecialchars($dst['kota']) ?></div>
      <?php endif; ?>
    </div>
    <?php if ($dst['notes'] ?? ''): ?>
    <div style="padding:6px 12px;font-size:8pt;color:#546e7a;font-style:italic">
      Catatan: <?= htmlspecialchars($dst['notes']) ?>
    </div>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
  <?php endif; ?>

  
  <div style="margin-top:14px;border:1px solid #dde3ea;border-radius:6px;padding:10px 14px;display:grid;grid-template-columns:1fr 1fr;gap:16px">
    <div>
      <div style="font-size:7.5pt;color:#90a4ae;text-transform:uppercase;letter-spacing:.4px;margin-bottom:4px">Received in good condition by</div>
      <div style="border-bottom:1px dashed #bdbdbd;min-height:28px;margin-top:6px"></div>
      <div style="font-size:8pt;color:#546e7a;margin-top:3px">Tanda Tangan &amp; Tanggal Penerimaan</div>
    </div>
    <div style="text-align:right">
      <div style="font-size:7.5pt;color:#90a4ae;text-transform:uppercase;letter-spacing:.4px;margin-bottom:4px">For <?= htmlspecialchars($warehouseName) ?></div>
      <div style="border-bottom:1px dashed #bdbdbd;min-height:28px;margin-top:6px"></div>
      <div style="font-size:8pt;color:#546e7a;margin-top:3px"><?= htmlspecialchars($outbound['created_by_name'] ?? 'Warehouse Staff') ?></div>
    </div>
  </div>

  
  <div class="sig-grid">
    <div class="sig-box">
      <div class="sig-role">Dibuat Oleh</div>
      <div class="sig-space"></div>
      <div class="sig-line"></div>
      <div class="sig-name"><?= htmlspecialchars($outbound['created_by_name'] ?? 'Warehouse Staff') ?></div>
    </div>
    <div class="sig-box">
      <div class="sig-role">Driver / Kurir</div>
      <div class="sig-space"></div>
      <div class="sig-line"></div>
      <div class="sig-name"><?= htmlspecialchars($outbound['armada_no'] ?? '( .................. )') ?></div>
    </div>
    <div class="sig-box">
      <div class="sig-role">Penerima</div>
      <div class="sig-space"></div>
      <div class="sig-line"></div>
      <div class="sig-name"><?= htmlspecialchars($shipToName) ?></div>
    </div>
  </div>

  
  <div class="doc-footer">
    <span>K-one — K-one secondary administration</span>
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

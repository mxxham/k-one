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
  @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap');
  *{margin:0;padding:0;box-sizing:border-box}
  body{font-family:'Inter',system-ui,sans-serif;font-size:10pt;color:#0f172a;background:#f1f5f9;line-height:1.5}
  @page{size:A4 portrait;margin:0}
  @media print{body{background:#fff;-webkit-print-color-adjust:exact;print-color-adjust:exact}.no-print{display:none!important}.document{box-shadow:none;margin:0;padding:12mm 14mm;border-radius:0}tr{page-break-inside:avoid}}

  .print-bar{background:#0f2e2d;color:#fff;padding:12px 24px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:99}
  .print-bar-title{font-weight:700;font-size:13px;display:flex;align-items:center;gap:8px}
  .btns{display:flex;gap:8px}
  .btn-print{background:#fff;color:#026766;border:none;padding:8px 18px;border-radius:6px;font-weight:700;cursor:pointer;font-size:12px}
  .btn-back{background:rgba(255,255,255,.1);color:#fff;border:1px solid rgba(255,255,255,.3);padding:8px 14px;border-radius:6px;font-weight:600;font-size:12px;text-decoration:none}

  .document{max-width:794px;margin:20px auto;background:#fff;padding:28px 32px;box-shadow:0 1px 12px rgba(0,0,0,.06);border-radius:6px}
  @media print{.document{box-shadow:none;margin:0;padding:14mm 14mm 16mm;max-width:100%;border-radius:0}}

  .doc-header{display:flex;justify-content:space-between;align-items:center;padding-bottom:16px;margin-bottom:16px;border-bottom:2px solid #e2e8f0}
  .doc-logo-area{display:flex;align-items:center;gap:12px}
  .logo-box{width:44px;height:44px;background:linear-gradient(135deg,#026766,#013d3c);border-radius:10px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:20px;font-weight:800;flex-shrink:0}
  .company-name{font-size:18px;font-weight:800;color:#0f172a;letter-spacing:-.5px}
  .company-name span{color:#64748b;font-weight:400}
  .company-sub{font-size:11px;color:#64748b;margin-top:1px}
  .doc-title-block{text-align:right}
  .doc-title{font-size:20px;font-weight:800;color:#0f172a;letter-spacing:-.3px}
  .doc-subtitle{font-size:11px;color:#64748b;margin-top:1px}
  .doc-number{font-size:12px;font-weight:700;color:#334155;margin-top:4px;font-family:'SF Mono',Consolas,monospace}

  .ref-box{border:1px solid #e2e8f0;border-radius:8px;padding:12px 16px;margin-bottom:14px;display:grid;grid-template-columns:auto 1fr 1fr;gap:16px;align-items:center;background:#f8fafc}
  .ref-num{font-family:'SF Mono',Consolas,monospace;font-size:14pt;font-weight:700;color:#0f172a;border-right:2px solid #e2e8f0;padding-right:16px}
  .ref-lbl{font-size:9px;color:#64748b;text-transform:uppercase;letter-spacing:.6px;font-weight:600;margin-bottom:2px}
  .ref-val{font-size:10pt;font-weight:600;color:#0f172a}

  .info-grid{display:grid;grid-template-columns:1fr 1fr 1fr;border:1px solid #e2e8f0;border-radius:8px;overflow:hidden;margin-bottom:16px}
  .info-cell{padding:10px 14px;border-right:1px solid #e2e8f0;border-bottom:1px solid #e2e8f0;background:#fff}
  .info-cell:nth-child(3n){border-right:none}
  .info-cell:nth-last-child(-n+3){border-bottom:none}
  .info-cell.span2{grid-column:span 2}
  .info-cell .lbl{font-size:9px;color:#64748b;text-transform:uppercase;letter-spacing:.6px;font-weight:600;margin-bottom:2px}
  .info-cell .val{font-size:12px;font-weight:600;color:#0f172a;line-height:1.6}

  .section-title{font-size:11px;font-weight:700;color:#334155;margin-bottom:10px;display:flex;align-items:center;gap:6px}
  .section-title::before{content:'';display:block;width:3px;height:14px;background:#026766;border-radius:2px}

  table{width:100%;border-collapse:collapse;font-size:10px;margin-bottom:14px}
  thead th{background:#0f2e2d;color:#fff;padding:8px 10px;text-align:left;font-weight:600;font-size:9px;letter-spacing:.4px;text-transform:uppercase}
  thead th:first-child{border-radius:6px 0 0 0}
  thead th:last-child{border-radius:0 6px 0 0}
  thead th.c{text-align:center}
  thead th.r{text-align:right}
  tbody tr{border-bottom:1px solid #f1f5f9}
  tbody tr:nth-child(even){background:#f8fafc}
  tbody td{padding:8px 10px;vertical-align:middle;line-height:1.5}
  tbody td.c{text-align:center}
  tbody td.r{text-align:right;font-weight:600}
  tfoot td{padding:10px;font-weight:700;font-size:11px;border-top:2px solid #0f2e2d;background:#f0fdfa;color:#0f172a}
  tfoot td.r{text-align:right}

  .batch-tag{display:inline-block;background:#f1f5f9;color:#334155;border:1px solid #e2e8f0;border-radius:4px;padding:2px 8px;font-family:'SF Mono',Consolas,monospace;font-size:9px;font-weight:600}
  .od-tag{display:inline-block;background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0;border-radius:4px;padding:2px 8px;font-family:'SF Mono',Consolas,monospace;font-size:9px;font-weight:700}
  .so-tag{display:inline-block;background:#f5f3ff;color:#5b21b6;border:1px solid #ddd6fe;border-radius:4px;padding:2px 8px;font-family:'SF Mono',Consolas,monospace;font-size:9px;font-weight:700}
  .loc-tag{display:inline-block;background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0;border-radius:4px;padding:2px 8px;font-family:'SF Mono',Consolas,monospace;font-size:9px;font-weight:600}

  .dest-section{border:1px solid #e2e8f0;border-radius:8px;overflow:hidden;margin-bottom:12px}
  .dest-header{background:#f8fafc;padding:10px 14px;display:flex;align-items:center;gap:8px;border-bottom:1px solid #e2e8f0}
  .dest-header .num-badge{background:#013d3c;color:#fff;border-radius:50%;width:22px;height:22px;display:flex;align-items:center;justify-content:center;font-size:9px;font-weight:700;flex-shrink:0}
  .dest-header .dest-name{font-weight:700;font-size:10px;color:#0f172a}
  .dest-header .dest-addr{font-size:9px;color:#64748b;margin-left:auto}

  .sig-grid{display:grid;grid-template-columns:1fr 1fr 1fr;gap:24px;margin-top:24px;padding-top:16px;border-top:1px solid #e2e8f0}
  .sig-box{padding-top:0}
  .sig-role{font-size:9px;color:#64748b;text-transform:uppercase;letter-spacing:.5px;font-weight:600;margin-bottom:4px}
  .sig-space{height:48px}
  .sig-line{border-bottom:1px solid #cbd5e1;margin:0 0 6px}
  .sig-name{font-size:10px;color:#94a3b8;font-style:italic}

  .doc-footer{margin-top:20px;padding-top:10px;border-top:1px solid #e2e8f0;display:flex;justify-content:space-between;font-size:9px;color:#94a3b8}
</style>
</head>
<body>



<div class="print-bar no-print">
  <div class="print-bar-title">Surat Jalan — <?= htmlspecialchars($orderNo) ?></div>
  <div class="btns">
    <a class="btn-back" href="javascript:history.back()">Back</a>
    <button class="btn-print" onclick="window.print()">Print / Save PDF</button>
  </div>
</div>

<div class="document">

  
  <div class="doc-header">
    <div class="doc-logo-area">
      <div class="logo-box">K</div>
      <div>
        <div class="company-name">K<span>-one</span></div>
        <div class="company-sub"><?= htmlspecialchars($warehouseName) ?></div>
      </div>
    </div>
    <div class="doc-title-block">
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
      <div style="font-size:9px;color:#64748b;margin-top:2px">Multi Tujuan (<?= count($destinations) ?>)</div>
      <?php endif; ?>
    </div>
    <div>
      <div class="ref-lbl">Delivery Address</div>
      <div class="ref-val" style="font-size:9pt"><?= htmlspecialchars(
          ($shipToStreet ?: ($shipToAddr !== '—' ? $shipToAddr : '—'))
      ) ?></div>
      <?php if ($shipToStreet && $shipToAddr !== '—'): ?>
      <div style="font-size:9px;color:#64748b;margin-top:2px"><?= htmlspecialchars($shipToAddr) ?></div>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  
  <div class="info-grid">
    <div class="info-cell">
      <div class="lbl">Pengirim (Shipper)</div>
      <div class="val"><?= htmlspecialchars($customerLabel) ?></div>
    </div>
    <div class="info-cell">
      <div class="lbl">Tanggal Kirim</div>
      <div class="val"><?= date('d F Y', strtotime($dispatchDate)) ?></div>
    </div>
    <div class="info-cell">
      <div class="lbl">Client DO No.</div>
      <div class="val" style="font-family:'SF Mono',Consolas,monospace"><?= htmlspecialchars($outbound['do_number'] ?? '—') ?></div>
    </div>
    <div class="info-cell">
      <div class="lbl">SO Number</div>
      <div class="val" style="font-family:'SF Mono',Consolas,monospace"><?= htmlspecialchars($outbound['so_number'] ?? '—') ?></div>
    </div>
    <div class="info-cell">
      <div class="lbl">Nomor Kendaraan</div>
      <div class="val"><?= htmlspecialchars($outbound['armada_no'] ?? '—') ?></div>
    </div>
    <div class="info-cell">
      <div class="lbl">Jenis Kendaraan</div>
      <div class="val"><?= htmlspecialchars($outbound['jenis_armada'] ?? '—') ?></div>
    </div>
    <div class="info-cell">
      <div class="lbl">Container No.</div>
      <div class="val" style="font-family:'SF Mono',Consolas,monospace"><?= htmlspecialchars($outbound['container_no'] ?? '—') ?></div>
    </div>
    <div class="info-cell span2">
      <div class="lbl">Tujuan Pengiriman</div>
      <div class="val"><?= htmlspecialchars($shipToName . ($shipToCity && $shipToCity !== '—' ? ' — ' . $shipToCity : '')) ?></div>
      <?php if ($shipToAddr && $shipToAddr !== '—'): ?>
      <div style="font-size:10px;color:#64748b;margin-top:2px"><?= htmlspecialchars($shipToAddr) ?></div>
      <?php endif; ?>
    </div>
  </div>

  
  <div class="section-title">Detail Barang</div>
  <table>
    <thead>
      <tr>
        <th style="width:24px" class="c">No.</th>
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
      <td class="c" style="color:#94a3b8;font-size:10px"><?= $i+1 ?></td>
      <td>
        <div style="font-family:'SF Mono',Consolas,monospace;font-weight:700;font-size:9px;color:#0f172a"><?= htmlspecialchars($item['product_code'] ?? '') ?></div>
      </td>
      <td>
        <div style="font-weight:600;font-size:10px"><?= htmlspecialchars($item['product_name'] ?? '—') ?></div>
      </td>
      <td><span class="batch-tag"><?= htmlspecialchars($dispBatch) ?></span></td>
      <td style="font-size:10px"><?= htmlspecialchars($item['order_customer_name'] ?? $item['customer_name'] ?? '—') ?></td>
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
          <span style="color:#cbd5e1;font-size:9px">—</span>
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
        <span style="color:#cbd5e1;font-size:9px">—</span>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr>
        <td colspan="6"><strong>TOTAL</strong></td>
        <td class="r"><?= number_format($totalQty, 0) ?></td>
        <td class="r"><?= number_format($totalPallet, 0) ?></td>
        <td colspan="2"><span style="font-size:9px;font-weight:400;color:#64748b">Total packages: <?= count($items) ?></span></td>
      </tr>
    </tfoot>
  </table>

  
  <?php if ($destCount > 0): ?>
  <div class="section-title">Tujuan Pengiriman</div>

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

  
  <div style="margin-top:14px;border:1px solid #e2e8f0;border-radius:8px;padding:12px 16px;display:grid;grid-template-columns:1fr 1fr;gap:16px">
    <div>
      <div style="font-size:9px;color:#64748b;text-transform:uppercase;letter-spacing:.6px;font-weight:600;margin-bottom:4px">Received in good condition by</div>
      <div style="border-bottom:1px solid #cbd5e1;min-height:28px;margin-top:8px"></div>
      <div style="font-size:10px;color:#94a3b8;margin-top:4px">Tanda Tangan &amp; Tanggal Penerimaan</div>
    </div>
    <div style="text-align:right">
      <div style="font-size:9px;color:#64748b;text-transform:uppercase;letter-spacing:.6px;font-weight:600;margin-bottom:4px">For <?= htmlspecialchars($warehouseName) ?></div>
      <div style="border-bottom:1px solid #cbd5e1;min-height:28px;margin-top:8px"></div>
      <div style="font-size:10px;color:#94a3b8;margin-top:4px"><?= htmlspecialchars($outbound['created_by_name'] ?? 'Warehouse Staff') ?></div>
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

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
<link rel="stylesheet" href="assets/css/print-shared.css">
<style>
  .doc-header { padding-bottom: 14px; margin-bottom: 18px; }
  .hdr-logo { width: 52px; height: 52px; }
  .doc-company .name .nk { color: #0f172a; }
  .doc-company .name .none { color: #0f172a; opacity: .7; }
  .doc-title-block .number { font-family: 'SF Mono', Consolas, monospace; }

  .badge-open { background: #fff8e1; color: #f57f17; border: 1px solid #ffe082; }
  .badge-picking { background: #e3f2fd; color: #014f4e; border: 1px solid #90caf9; }
  .badge-picked { background: #e8f5e9; color: #026766; border: 1px solid #a5d6a7; }
  .badge-shipped { background: #e0f7f7; color: #026766; border: 1px solid #80d2d2; }
  .badge-completed { background: #e8f5e9; color: #013d3c; border: 1px solid #81c784; }

  .shipment-box { background: #f0fdfa; border: 1.5px solid #e2e8f0; border-radius: 8px; padding: 12px 18px; margin-bottom: 14px; display: grid; grid-template-columns: auto 1fr 1fr; gap: 14px; align-items: center; }
  .shipment-box .ship-num { font-family: 'SF Mono', Consolas, monospace; font-size: 15pt; font-weight: 700; color: #0f172a; border-right: 2px solid #e2e8f0; padding-right: 14px; }
  .shipment-box .ship-lbl { font-size: 7.5pt; color: #64748b; text-transform: uppercase; letter-spacing: .4px; }
  .shipment-box .ship-val { font-size: 10pt; font-weight: 600; color: #0f172a; }

  .info-cell { padding: 8px 12px; }
  .info-cell .lbl { font-weight: 600; }

  .dest-block { border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden; margin-bottom: 10px; }
  .dest-header { padding: 8px 14px; display: flex; align-items: center; gap: 10px; }
  .dest-header.primary, .dest-header.secondary { background: #f0fdfa; border-bottom: 1px solid #e2e8f0; }
  .dest-seq { width: 22px; height: 22px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 8pt; font-weight: 700; color: #fff; flex-shrink: 0; }
  .dest-seq.primary, .dest-seq.secondary { background: #026766; }
  .dest-name { font-weight: 700; font-size: 9.5pt; }
  .dest-name.primary, .dest-name.secondary { color: #0f172a; }
  .dest-loc { font-size: 8pt; color: #64748b; margin-top: 1px; }
  .dest-tag { font-size: 7.5pt; font-weight: 700; padding: 2px 8px; border-radius: 10px; margin-left: auto; }
  .dest-tag.primary, .dest-tag.secondary { background: #e2e8f0; color: #026766; }

  .dest-table { border-radius: 0; }
  .sku { font-family: 'SF Mono', Consolas, monospace; font-size: 7.5pt; color: #64748b; }
  .mono { font-family: 'SF Mono', Consolas, monospace; font-size: 8pt; }
  .loc-badge { display: inline-block; background: #f1f5f9; color: #0f172a; border-radius: 4px; padding: 1px 5px; font-family: 'SF Mono', Consolas, monospace; font-size: 7.5pt; font-weight: 600; }
  .exp-warn { color: #065f46; font-weight: 700; }
  .fefo-tag { display: inline-block; background: #ecfdf5; color: #065f46; border-radius: 3px; padding: 1px 5px; font-size: 7pt; font-weight: 700; letter-spacing: .5px; margin-left: 4px; }

  .sig-box { border-top: 1px solid #e2e8f0; }
  .doc-footer { color: #94a3b8; }
</style>
</head>
<body>

<div class="print-bar no-print">
  <div class="print-bar-title">Outbound Report — <?= htmlspecialchars($displayOrderNo) ?></div>
  <div class="btns">
    <a class="btn-back" href="javascript:history.back()">Back</a>
    <button class="btn-print" onclick="window.print()">Print / Save PDF</button>
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
      <div style="font-size:8.5pt;color:#64748b;margin-top:2px">Multi Tujuan (<?= count($destinations) ?>)</div>
      <?php endif; ?>
    </div>
    <div>
      <div class="ship-lbl">Expected Date</div>
      <div class="ship-val"><?= $outbound['expected_date'] ? date('d F Y', strtotime($outbound['expected_date'])) : '—' ?></div>
    </div>
  </div>
  <?php endif; ?>

  
  <div class="section-title">Detail Order</div>
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
          echo '<td class="num" style="color:#94a3b8">' . ($i + 1) . '</td>';
          
          echo '<td class="mono" style="font-size:7.5pt;color:#334155">' . htmlspecialchars($item['od_number'] ?? '—') . '</td>';
          
          echo '<td class="mono" style="font-size:7.5pt;color:#334155">' . htmlspecialchars($item['so_number'] ?? '—') . '</td>';
          
          echo '<td><div style="font-weight:600;color:#0f172a;font-size:8.5pt">' . htmlspecialchars($item['product_name'] ?? '—') . '</div>';
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
                  echo '<span style="display:inline-block;background:#f1f5f9;color:#0f172a;border-radius:3px;padding:1px 5px;margin:1px 0;font-family:\'SF Mono\',Consolas,monospace;font-weight:600;font-size:6.5pt;white-space:nowrap">'
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
          echo '<tr><td colspan="11" style="text-align:center;color:#94a3b8;padding:16px">Tidak ada item</td></tr>';
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

  
  <div class="section-title">Tujuan Pengiriman &amp; Produk</div>

  
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
        <div class="dest-loc"><?= htmlspecialchars($primaryLoc . ($primaryStreet ? ' — ' . $primaryStreet : '')) ?></div>
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
        <div class="dest-loc"><?= htmlspecialchars(implode(' — ', $dstLocParts)) ?></div>
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
        <td colspan="5" style="padding:7px 8px;font-weight:700;font-size:9.5pt;border-top:2px solid #0f2e2d;background:#f0fdfa">
          GRAND TOTAL — <?= $destCount ?> Tujuan
        </td>
        <td class="right" style="padding:7px 8px;font-weight:700;font-size:9.5pt;border-top:2px solid #0f2e2d;background:#f0fdfa"><?= number_format($totalQty, 0) ?></td>
        <td style="padding:7px 8px;border-top:2px solid #0f2e2d;background:#f0fdfa"></td>
        <td class="right" style="padding:7px 8px;font-weight:700;font-size:9.5pt;border-top:2px solid #0f2e2d;background:#f0fdfa"><?= number_format($totalPallet, 0) ?></td>
        <td colspan="2" style="padding:7px 8px;border-top:2px solid #0f2e2d;background:#f0fdfa"></td>
      </tr>
    </tfoot>
  </table>
  <?php endif; ?>

  
  <div class="sig-grid">
    <div class="sig-box">
      <div class="role">Dibuat Oleh</div>
      <div class="space"></div>
      <div class="name-line"></div>
      <div style="font-size:8pt;color:#64748b"><?= htmlspecialchars($outbound['created_by_name'] ?? 'Warehouse Staff') ?></div>
    </div>
    <div class="sig-box">
      <div class="role">Driver / Kurir</div>
      <div class="space"></div>
      <div class="name-line"></div>
      <div style="font-size:8pt;color:#64748b"><?= htmlspecialchars($outbound['armada_no'] ?? '( .............. )') ?></div>
    </div>
    <div class="sig-box">
      <div class="role">Penerima</div>
      <div class="space"></div>
      <div class="name-line"></div>
      <div style="font-size:8pt;color:#64748b"><?= htmlspecialchars($customerLabel) ?></div>
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

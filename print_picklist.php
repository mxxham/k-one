<?php
session_start();
date_default_timezone_set('Asia/Jakarta');
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/classes/Auth.php';
require_once __DIR__ . '/classes/Picklist.php';

Auth::requireAuth();

$id = $_GET['id'] ?? null;
if (!$id) { header('Location: outbound.php'); exit; }

$picklist = Picklist::getById($id);
if (!$picklist) { header('Location: outbound.php'); exit; }

$items = Picklist::getItems($id);

$totalQty  = array_sum(array_column($items, 'quantity'));
$totalPlt  = (int)ceil(array_sum(array_column($items, 'pallet')));
$pickedQty = array_sum(array_column($items, 'picked_quantity'));
$totalLines = count($items);

// Info header
$picklistNo  = $picklist['picklist_number'] ?? '—';
$pickDate    = $picklist['created_date'] ?? date('Y-m-d');
$outboundNo  = $picklist['outbound_number'] ?? '—';
$status      = $picklist['status'] ?? 'Draft';
$notes       = $picklist['notes'] ?? '';
$armada      = $picklist['armada_no'] ?? '';
$container   = $picklist['container_no'] ?? '';

$binToBinStmt = db()->prepare('SELECT * FROM picklist_bin_to_bin WHERE picklist_id = ? ORDER BY id');
$binToBinStmt->execute([$id]);
$binToBinRows = $binToBinStmt->fetchAll(PDO::FETCH_ASSOC);

$itemCustomers = array_unique(array_filter(array_column($items, 'item_customer_name')));
$allCustomers  = array_unique(array_filter(array_merge(
    $itemCustomers ?: ($picklist['customer_name'] ? [$picklist['customer_name']] : [])
)));
$itemSoNums = array_unique(array_filter(array_column($items, 'item_so_number')));
$allSo      = array_unique(array_filter(array_merge(
    $itemSoNums ?: ($picklist['so_number'] ? [$picklist['so_number']] : [])
)));
$allDo = array_unique(array_filter($picklist['do_number'] ? [$picklist['do_number']] : []));
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>Pick List — <?= htmlspecialchars($picklistNo) ?></title>
<link rel="stylesheet" href="assets/css/print-shared.css">
<style>
/* picklist-specific: compact overrides only */

.print-bar-title{font-weight:700;font-size:13px;display:flex;align-items:center;gap:8px}

.document{padding:28px 32px}
@media print{.document{padding:12mm 14mm}}

/* Header - compact picklist variant */
.doc-header{display:flex;justify-content:space-between;align-items:center;padding-bottom:16px;margin-bottom:16px;border-bottom:2px solid #e2e8f0}
.logo-area{display:flex;align-items:center;gap:12px}
.logo-mark{width:44px;height:44px;background:linear-gradient(135deg,#026766,#013d3c);border-radius:10px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:20px;font-weight:800;flex-shrink:0}
.company-name{font-size:18px;font-weight:800;color:#0f172a;letter-spacing:-.5px}
.company-name span{color:#64748b;font-weight:400}
.doc-title{font-size:20px;font-weight:800;color:#0f172a;letter-spacing:-.3px}
.doc-subtitle{font-size:11px;color:#64748b;margin-top:1px}
.doc-orderno{font-size:12px;font-weight:700;color:#334155;margin-top:4px;font-family:'SF Mono',Consolas,monospace}

/* Info grid compact */
.info-grid{margin-bottom:16px}
.info-cell{padding:10px 14px}
.info-cell .lbl{font-size:9px;letter-spacing:.6px}
.info-cell .val{font-size:12px;line-height:1.6}
.info-cell .val .sub{font-size:10px;font-weight:400;color:#94a3b8}
.info-cell.span2{grid-column:span 2}

/* Table compact */
table{font-size:10px;margin-bottom:14px}
thead th{padding:8px 10px;font-size:9px;letter-spacing:.4px;text-transform:uppercase}
thead th:first-child{border-radius:6px 0 0 0}
thead th:last-child{border-radius:0 6px 0 0}
thead th.c{text-align:center}
thead th.r{text-align:right}
tbody td{padding:8px 10px;line-height:1.5}
tbody td.c{text-align:center}
tbody td.r{text-align:right;font-weight:600}
tfoot td{padding:10px;font-size:11px;color:#0f172a}
tfoot td.r{text-align:right}

/* Chip variants */
.chip{display:inline-block;border-radius:4px;padding:2px 8px;font-family:'SF Mono',Consolas,monospace;font-size:9px;font-weight:600}
.chip-batch{background:#f1f5f9;color:#334155;border:1px solid #e2e8f0}
.chip-od{background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0}
.chip-so{background:#f5f3ff;color:#5b21b6;border:1px solid #ddd6fe}
.chip-loc{background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0}
.check-box{width:15px;height:15px;border:1.5px solid #94a3b8;border-radius:3px;display:inline-block;background:#fff}

/* Notes */
.remarks-box{border:1px solid #e2e8f0;border-radius:8px;padding:12px 16px;margin-bottom:16px;min-height:40px;background:#f8fafc}
.remarks-lbl{font-size:9px;color:#64748b;text-transform:uppercase;letter-spacing:.6px;font-weight:600;margin-bottom:4px}

/* Signature compact */
.sig-grid{gap:24px;margin-top:24px;padding-top:16px;border-top:1px solid #e2e8f0}
.sig-box{padding-top:0}
.sig-role{font-size:9px;color:#64748b;text-transform:uppercase;letter-spacing:.5px;font-weight:600;margin-bottom:4px}
.sig-space{height:48px}
.sig-line{border-bottom:1px solid #cbd5e1;margin:0 0 6px}
.sig-name{font-size:10px;color:#94a3b8;font-style:italic}

.doc-footer{font-size:9px}
</style>
</head>
<body>

<div id="back-to-app" style="position:fixed;top:12px;right:12px;z-index:9999">
  <a href="javascript:window.close()" style="background:#0f172a;color:#fff;padding:8px 16px;border-radius:6px;text-decoration:none;font-size:12px;font-weight:600;font-family:Inter,sans-serif;border:1px solid rgba(255,255,255,.15)">
    Close &amp; Back
  </a>
</div>
<style>@media print{#back-to-app{display:none!important}}</style>

<div class="print-bar no-print">
  <div class="print-bar-title">Pick List — <?= htmlspecialchars($picklistNo) ?></div>
  <div class="btns">
    <a class="btn-back" href="javascript:window.close()">Close</a>
    <button class="btn-print" onclick="window.print()">Print / PDF</button>
  </div>
</div>

<div class="document">

  <!-- Header -->
  <div class="doc-header">
    <div class="logo-area">
      <div class="logo-mark">K</div>
      <div class="company-name">K<span>-one</span></div>
    </div>
    <div class="doc-title-block">
      <div class="doc-title">PICK LIST</div>
      <div class="doc-subtitle">Outbound Picking Document</div>
      <div class="doc-orderno"><?= htmlspecialchars($picklistNo) ?></div>
    </div>
  </div>

  <!-- Info Grid -->
  <div class="info-grid">
    <div class="info-cell">
      <div class="lbl">Pick List No.</div>
      <div class="val" style="font-family:'SF Mono',Consolas,monospace"><?= htmlspecialchars($picklistNo) ?></div>
    </div>
    <div class="info-cell">
      <div class="lbl">Tanggal</div>
      <div class="val"><?= date('d/m/Y', strtotime($pickDate)) ?></div>
    </div>
    <div class="info-cell">
      <div class="lbl">Outbound No.</div>
      <div class="val" style="font-family:'SF Mono',Consolas,monospace"><?= htmlspecialchars($outboundNo) ?></div>
    </div>
    <div class="info-cell span2">
      <div class="lbl">Customer</div>
      <div class="val">
        <?= !empty($allCustomers) ? htmlspecialchars(implode(' / ', $allCustomers)) : '—' ?>
        <?php if ($picklist['city'] ?? ''): ?>
        <br><span class="sub"><?= htmlspecialchars($picklist['city']) ?></span>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Items Table -->
  <div class="section-title">Items to Pick</div>
  <table>
    <thead>
      <tr>
        <th class="c" style="width:24px">No.</th>
        <th>Product</th>
        <th>Customer</th>
        <th>SO / OD No.</th>
        <th>Batch</th>
        <th>Lokasi</th>
        <th class="r" style="width:38px">Qty Order</th>
        <th class="r" style="width:32px">Plt</th>
        <th class="r" style="width:54px">Actual Qty</th>
        <th class="c" style="width:28px">Pick</th>
      </tr>
    </thead>
    <tbody>
    <?php if (empty($items)): ?>
    <tr><td colspan="10" style="text-align:center;color:#94a3b8;padding:24px">No items</td></tr>
    <?php endif; ?>
    <?php foreach ($items as $idx => $item):
      $pltDisp = (string)(int)ceil(floatval($item['pallet'] ?? 0));
      $iCust = $item['item_customer_name'] ?? null;
      $iSo   = $item['item_so_number'] ?? null;
      $iOd   = $item['item_od_number'] ?? null;
    ?>
    <tr>
      <td class="c" style="color:#94a3b8;font-size:10px"><?= $idx + 1 ?></td>
      <td>
        <div style="font-weight:700;font-size:10px;color:#0f172a"><?= htmlspecialchars($item['product_name'] ?? '—') ?></div>
        <div style="font-family:'SF Mono',Consolas,monospace;font-size:9px;color:#64748b"><?= htmlspecialchars($item['product_code'] ?? '') ?></div>
      </td>
      <td style="font-size:10px">
        <?= $iCust ? htmlspecialchars($iCust) : '<span style="color:#cbd5e1">—</span>' ?>
        <?php if ($item['item_ship_to'] ?? null): ?>
        <div style="font-size:9px;color:#94a3b8"><?= htmlspecialchars($item['item_ship_to']) ?></div>
        <?php endif; ?>
      </td>
      <td>
        <?php if ($iSo): ?><span class="chip chip-so">SO: <?= htmlspecialchars($iSo) ?></span><?php endif; ?>
        <?php if ($iOd): ?>
          <?php if ($iSo): ?><br><?php endif; ?>
          <span class="chip chip-od">OD: <?= htmlspecialchars($iOd) ?></span>
        <?php endif; ?>
        <?php if (!$iSo && !$iOd): ?><span style="color:#cbd5e1;font-size:9px">—</span><?php endif; ?>
      </td>
      <td>
        <?php $bn = $item['batch_no'] ?? $item['batch_number'] ?? null; ?>
        <?php if ($bn): ?>
        <span class="chip chip-batch"><?= htmlspecialchars($bn) ?></span>
        <?php else: ?><span style="color:#cbd5e1;font-size:9px">—</span><?php endif; ?>
      </td>
      <td>
        <?php if ($item['location'] ?? null): ?>
        <span class="chip chip-loc"><?= htmlspecialchars($item['location']) ?></span>
        <?php else: ?>
        <span style="display:inline-block;min-width:70px;border-bottom:1.5px dashed #cbd5e1;height:18px"></span>
        <?php endif; ?>
      </td>
      <td class="r" style="font-weight:700"><?= number_format((float)($item['quantity'] ?? 0), 0) ?></td>
      <td class="r" style="color:#475569"><?= $pltDisp ?></td>
      <td class="r"><span style="display:inline-block;min-width:48px;border-bottom:1.5px solid #cbd5e1;height:18px"></span></td>
      <td class="c"><span class="check-box"></span></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr>
        <td colspan="6" style="text-align:right;padding-right:10px">TOTAL</td>
        <td class="r"><?= number_format($totalQty, 0) ?></td>
        <td class="r" style="color:#546e7a"><?= number_format($totalPlt, 0) ?></td>
        <td colspan="2"></td>
      </tr>
    </tfoot>
  </table>

  <?php if (!empty($binToBinRows)): ?>
  <div style="margin-top:16px">
    <div class="section-title">Bin-to-Bin Consolidation</div>
    <table>
      <thead>
        <tr>
          <th class="c" style="width:24px">No.</th>
          <th>SKU</th>
          <th>Source Location</th>
          <th>Destination</th>
          <th class="r" style="width:54px">Quantity</th>
          <th>UOM</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($binToBinRows as $btIdx => $btRow): ?>
      <tr>
        <td class="c" style="color:#94a3b8;font-size:10px"><?= $btIdx + 1 ?></td>
        <td style="font-family:'SF Mono',Consolas,monospace;font-size:10px;font-weight:600;color:#0f172a"><?= htmlspecialchars($btRow['product_code'] ?? '—') ?></td>
        <td><span class="chip chip-loc"><?= htmlspecialchars($btRow['source_location'] ?? '—') ?></span></td>
        <td><span class="chip chip-loc"><?= htmlspecialchars($btRow['destination_location'] ?? '—') ?></span></td>
        <td class="r" style="font-weight:700"><?= number_format((float)($btRow['quantity'] ?? 0), 2) ?></td>
        <td style="font-size:10px;color:#64748b"><?= htmlspecialchars($btRow['uom'] ?? 'EA') ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <!-- Notes -->
  <div class="remarks-box">
    <div class="remarks-lbl">Catatan Picking</div>
    <div style="font-size:8.5pt;color:#546e7a;min-height:16px"><?= htmlspecialchars($notes ?: '') ?></div>
  </div>

  <!-- Signatures -->
  <div class="sig-grid">
    <div class="sig-box">
      <div class="sig-role">Picker / Petugas</div>
      <div class="sig-space"></div>
      <div class="sig-line"></div>
      <div class="sig-name">( ......................... )</div>
    </div>
    <div class="sig-box">
      <div class="sig-role">Checker / Verifier</div>
      <div class="sig-space"></div>
      <div class="sig-line"></div>
      <div class="sig-name">( ......................... )</div>
    </div>
    <div class="sig-box">
      <div class="sig-role">Supervisor</div>
      <div class="sig-space"></div>
      <div class="sig-line"></div>
      <div class="sig-name">( ......................... )</div>
    </div>
  </div>

  <!-- Footer -->
  <div class="doc-footer">
    <span>K-one</span>
    <span>Dicetak: <?= date('d F Y H:i') ?> WIB</span>
    <span><?= htmlspecialchars($picklistNo) ?></span>
  </div>

</div>

<script>
window.onload = function() {
  setTimeout(function() { window.print(); }, 500);
};
</script>
</body>
</html>

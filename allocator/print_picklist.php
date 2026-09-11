<?php
/**
 * Allocator Print Picklist
 * Print-friendly view of allocation results (same style as K-one print_picklist.php)
 */

session_start();
date_default_timezone_set('Asia/Jakarta');
require_once __DIR__ . '/../config/database.php';

// Standalone — no auth required

$resultId = $_GET['id'] ?? null;
if (!$resultId) { header('Location: index.php'); exit; }

$resultFile = sys_get_temp_dir() . '/allocator_' . $resultId . '.json';
if (!file_exists($resultFile)) {
    header('Location: index.php?error=expired'); 
    exit;
}

$result = json_decode(file_get_contents($resultFile), true);
if (!$result) { header('Location: index.php?error=invalid'); exit; }

$picks = $result['picks'] ?? [];
$replenishments = $result['replenishments'] ?? [];
$errors = $result['errors'] ?? [];
$summary = $result['summary'] ?? [];
$createdAt = $result['created_at'] ?? date('Y-m-d H:i:s');

$totalPicks = count($picks);
$totalReplenishments = count($replenishments);
$totalErrors = count($errors);
$totalQty = array_sum(array_column($picks, 'quantity'));

// Group picks by order
$groupedPicks = [];
foreach ($picks as $pick) {
    $orderNo = $pick['order_no'] ?? 'Unknown';
    if (!isset($groupedPicks[$orderNo])) {
        $groupedPicks[$orderNo] = [];
    }
    $groupedPicks[$orderNo][] = $pick;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>Pick List — Allocator <?= date('d/m/Y') ?></title>
<link rel="stylesheet" href="../assets/css/print-shared.css">
<style>
.picklist-specific .document{padding:28px 32px}
@media print{.picklist-specific .document{padding:12mm 14mm}}

.doc-header{display:flex;justify-content:space-between;align-items:center;padding-bottom:16px;margin-bottom:16px;border-bottom:2px solid #e2e8f0}
.logo-area{display:flex;align-items:center;gap:12px}
.logo-mark{width:44px;height:44px;background:linear-gradient(135deg,#026766,#013d3c);border-radius:10px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:20px;font-weight:800;flex-shrink:0}
.company-name{font-size:18px;font-weight:800;color:#0f172a;letter-spacing:-.5px}
.company-name span{color:#64748b;font-weight:400}
.doc-title{font-size:20px;font-weight:800;color:#0f172a;letter-spacing:-.3px}
.doc-subtitle{font-size:11px;color:#64748b;margin-top:1px}
.doc-orderno{font-size:12px;font-weight:700;color:#334155;margin-top:4px;font-family:'SF Mono',Consolas,monospace}

.info-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:0;border:1px solid #e2e8f0;border-radius:10px;overflow:hidden;margin-bottom:16px}
.info-cell{padding:10px 14px;border-bottom:1px solid #e2e8f0}
.info-cell:nth-child(odd){background:#f8fafc}
.info-cell .lbl{font-size:9px;letter-spacing:.6px;color:#64748b;text-transform:uppercase;font-weight:600}
.info-cell .val{font-size:12px;line-height:1.6;color:#0f172a;font-weight:500}
.info-cell .val .sub{font-size:10px;font-weight:400;color:#94a3b8}

.section-title{font-size:11px;font-weight:700;color:#013d3c;text-transform:uppercase;letter-spacing:.6px;margin-bottom:8px;padding-bottom:4px;border-bottom:2px solid #e2e8f0}

table{width:100%;border-collapse:collapse;font-size:10px;margin-bottom:14px}
thead th{background:#013d3c;color:#fff;padding:8px 10px;font-size:9px;letter-spacing:.4px;text-transform:uppercase;text-align:left}
thead th:first-child{border-radius:6px 0 0 0}
thead th:last-child{border-radius:0 6px 0 0}
thead th.c{text-align:center}
thead th.r{text-align:right}
tbody td{padding:8px 10px;line-height:1.5;border-bottom:1px solid #e2e8f0}
tbody tr:nth-child(even){background:#f8fafc}
tbody td.c{text-align:center}
tbody td.r{text-align:right;font-weight:600}
tfoot td{padding:10px;font-size:11px;color:#0f172a;background:#f1f5f9;font-weight:700}
tfoot td.r{text-align:right}

.chip{display:inline-block;border-radius:4px;padding:2px 8px;font-family:'SF Mono',Consolas,monospace;font-size:9px;font-weight:600}
.chip-full{background:#dcfce7;color:#166534;border:1px solid #86efac}
.chip-pickface{background:#dbeafe;color:#1e40af;border:1px solid #93c5fd}
.chip-location{background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0}
.chip-replenish{background:#fef3c7;color:#92400e;border:1px solid #fcd34d}

.check-box{width:15px;height:15px;border:1.5px solid #94a3b8;border-radius:3px;display:inline-block;background:#fff}

.remarks-box{border:1px solid #e2e8f0;border-radius:8px;padding:12px 16px;margin-bottom:16px;min-height:40px;background:#f8fafc}
.remarks-lbl{font-size:9px;color:#64748b;text-transform:uppercase;letter-spacing:.6px;font-weight:600;margin-bottom:4px}

.sig-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:24px;margin-top:24px;padding-top:16px;border-top:1px solid #e2e8f0}
.sig-box{padding-top:0}
.sig-role{font-size:9px;color:#64748b;text-transform:uppercase;letter-spacing:.5px;font-weight:600;margin-bottom:4px}
.sig-space{height:48px}
.sig-line{border-bottom:1px solid #cbd5e1;margin:0 0 6px}
.sig-name{font-size:10px;color:#94a3b8;font-style:italic}

.doc-footer{font-size:9px;color:#64748b;display:flex;justify-content:space-between;margin-top:16px;padding-top:8px;border-top:1px solid #e2e8f0}

.order-group{margin-bottom:16px}
.order-header{font-size:11px;font-weight:700;color:#013d3c;margin-bottom:6px;padding:6px 10px;background:#e6f7f7;border-radius:6px;display:flex;justify-content:space-between;align-items:center}
.order-header .badge{background:#013d3c;color:#fff;padding:2px 8px;border-radius:4px;font-size:9px}
</style>
</head>
<body class="picklist-specific">

<div id="back-to-app" style="position:fixed;top:12px;right:12px;z-index:9999">
  <a href="javascript:window.close()" style="background:#0f172a;color:#fff;padding:8px 16px;border-radius:6px;text-decoration:none;font-size:12px;font-weight:600;font-family:Inter,sans-serif;border:1px solid rgba(255,255,255,.15)">
    Close &amp; Back
  </a>
</div>
<style>@media print{#back-to-app{display:none!important}}</style>

<div class="print-bar no-print">
  <div class="print-bar-title">Pick List — Allocator</div>
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
    <div style="text-align:right">
      <div class="doc-title">PICK LIST</div>
      <div class="doc-subtitle">Allocator — Alokasi FEFO</div>
      <div class="doc-orderno"><?= date('d/m/Y H:i', strtotime($createdAt)) ?></div>
    </div>
  </div>

  <!-- Summary Info -->
  <div class="info-grid">
    <div class="info-cell">
      <div class="lbl">Tanggal</div>
      <div class="val"><?= date('d/m/Y', strtotime($createdAt)) ?></div>
    </div>
    <div class="info-cell">
      <div class="lbl">Total Orders</div>
      <div class="val" style="font-family:'SF Mono',Consolas,monospace"><?= $summary['total_orders'] ?? 0 ?></div>
    </div>
    <div class="info-cell">
      <div class="lbl">Total Items</div>
      <div class="val" style="font-family:'SF Mono',Consolas,monospace"><?= $summary['total_items'] ?? 0 ?></div>
    </div>
    <div class="info-cell">
      <div class="lbl">Full Pallet Picks</div>
      <div class="val" style="font-family:'SF Mono',Consolas,monospace;color:#059669"><?= $summary['full_pallet_picks'] ?? 0 ?></div>
    </div>
    <div class="info-cell">
      <div class="lbl">Pickface Picks</div>
      <div class="val" style="font-family:'SF Mono',Consolas,monospace;color:#3b82f6"><?= $summary['pickface_picks'] ?? 0 ?></div>
    </div>
    <div class="info-cell">
      <div class="lbl">Replenishments</div>
      <div class="val" style="font-family:'SF Mono',Consolas,monospace;color:#f59e0b"><?= $summary['replenishments'] ?? 0 ?></div>
    </div>
  </div>

  <!-- Picks Table -->
  <?php if (!empty($picks)): ?>
  <div class="section-title">Items to Pick (<?= $totalPicks ?> picks)</div>
  
  <?php foreach ($groupedPicks as $orderNo => $orderPicks): ?>
  <div class="order-group">
    <div class="order-header">
      <span>Order: <?= htmlspecialchars($orderNo) ?></span>
      <span class="badge"><?= count($orderPicks) ?> items</span>
    </div>
    <table>
      <thead>
        <tr>
          <th class="c" style="width:24px">No.</th>
          <th>Item Code</th>
          <th>Lokasi</th>
          <th class="r" style="width:50px">Qty</th>
          <th>Tipe</th>
          <th>Batch</th>
          <th>Expiry</th>
          <th class="c" style="width:28px">Pick</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($orderPicks as $idx => $pick): ?>
      <tr>
        <td class="c" style="color:#94a3b8;font-size:10px"><?= $idx + 1 ?></td>
        <td style="font-family:'SF Mono',Consolas,monospace;font-size:10px;font-weight:600;color:#0f172a"><?= htmlspecialchars($pick['item_code'] ?? '—') ?></td>
        <td>
          <?php if ($pick['location'] ?? null): ?>
          <span class="chip chip-location"><?= htmlspecialchars($pick['location']) ?></span>
          <?php else: ?>
          <span style="display:inline-block;min-width:70px;border-bottom:1.5px dashed #cbd5e1;height:18px"></span>
          <?php endif; ?>
        </td>
        <td class="r" style="font-weight:700"><?= number_format((float)($pick['quantity'] ?? 0), 0) ?></td>
        <td>
          <?php 
          $typeClass = ($pick['type'] ?? '') === 'full_pallet' ? 'chip-full' : 'chip-pickface';
          $typeLabel = ($pick['type'] ?? '') === 'full_pallet' ? 'Full Pallet' : 'Pickface';
          ?>
          <span class="chip <?= $typeClass ?>"><?= $typeLabel ?></span>
        </td>
        <td>
          <?php $bn = $pick['batch_number'] ?? null; ?>
          <?php if ($bn): ?>
          <span class="chip" style="background:#f1f5f9;color:#334155;border:1px solid #e2e8f0"><?= htmlspecialchars($bn) ?></span>
          <?php else: ?>
          <span style="color:#cbd5e1;font-size:9px">—</span>
          <?php endif; ?>
        </td>
        <td style="font-size:9px;color:#64748b"><?= $pick['expiry_date'] ?? '—' ?></td>
        <td class="c"><span class="check-box"></span></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endforeach; ?>
  
  <!-- Total Summary -->
  <table style="margin-top:8px">
    <tfoot>
      <tr>
        <td colspan="3" style="text-align:right;padding-right:10px">TOTAL</td>
        <td class="r"><?= number_format($totalQty, 0) ?></td>
        <td colspan="4"></td>
      </tr>
    </tfoot>
  </table>
  <?php endif; ?>

  <!-- Replenishments -->
  <?php if (!empty($replenishments)): ?>
  <div style="margin-top:20px">
    <div class="section-title" style="color:#f59e0b">Replenishment (<?= $totalReplenishments ?> tasks)</div>
    <table>
      <thead>
        <tr>
          <th class="c" style="width:24px">No.</th>
          <th>Item Code</th>
          <th>From Location</th>
          <th>To Location</th>
          <th class="r" style="width:50px">Qty</th>
          <th>UOM</th>
          <th class="c" style="width:28px">Done</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($replenishments as $idx => $rep): ?>
      <tr>
        <td class="c" style="color:#94a3b8;font-size:10px"><?= $idx + 1 ?></td>
        <td style="font-family:'SF Mono',Consolas,monospace;font-size:10px;font-weight:600;color:#0f172a"><?= htmlspecialchars($rep['item_code'] ?? '—') ?></td>
        <td><span class="chip chip-location"><?= htmlspecialchars($rep['from_location'] ?? '—') ?></span></td>
        <td><span class="chip chip-location"><?= htmlspecialchars($rep['to_location'] ?? '—') ?></span></td>
        <td class="r" style="font-weight:700"><?= number_format((float)($rep['quantity'] ?? 0), 0) ?></td>
        <td style="font-size:10px;color:#64748b"><?= htmlspecialchars($rep['uom_type'] ?? 'EA') ?></td>
        <td class="c"><span class="check-box"></span></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <!-- Notes -->
  <div class="remarks-box">
    <div class="remarks-lbl">Catatan Picking</div>
    <div style="font-size:8.5pt;color:#546e7a;min-height:16px"></div>
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
    <span>K-one Allocator</span>
    <span>Dicetak: <?= date('d F Y H:i') ?> WIB</span>
    <span><?= $totalPicks ?> picks / <?= $totalReplenishments ?> replenishments</span>
  </div>

</div>

<script>
window.onload = function() {
  setTimeout(function() { window.print(); }, 500);
};
</script>
</body>
</html>

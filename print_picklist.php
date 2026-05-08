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
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap');
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Inter',Arial,sans-serif;font-size:10pt;color:#1a1a1a;background:#fff}
@page{size:A4;margin:0}
@media print{.no-print{display:none!important}body{-webkit-print-color-adjust:exact;print-color-adjust:exact;background:#fff}.page-break{page-break-before:always}tr{page-break-inside:avoid}}

.print-bar{background:linear-gradient(135deg,#013d3c,#026766);color:#fff;padding:10px 22px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:99}
.print-bar-title{font-weight:700;font-size:13px;display:flex;align-items:center;gap:8px}
.btns{display:flex;gap:8px}
.btn-print{background:#fff;color:#026766;border:none;padding:7px 16px;border-radius:6px;font-weight:700;cursor:pointer;font-size:12px;display:flex;align-items:center;gap:5px}
.btn-back{background:rgba(255,255,255,.15);color:#fff;border:1.5px solid rgba(255,255,255,.5);padding:7px 14px;border-radius:6px;font-weight:600;font-size:12px;text-decoration:none;display:flex;align-items:center;gap:5px}

.document{max-width:794px;margin:18px auto;background:#fff;padding:24px 28px;box-shadow:0 2px 20px rgba(0,0,0,.1);border-radius:4px}
@media print{.document{box-shadow:none;margin:0;padding:14mm 14mm 16mm;max-width:100%;border-radius:0}}

/* Header */
.doc-header{display:flex;justify-content:space-between;align-items:flex-start;border-bottom:3px solid #026766;padding-bottom:12px;margin-bottom:14px}
.logo-area{display:flex;align-items:center;gap:10px}
.logo-mark{width:46px;height:46px;background:#026766;border-radius:10px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:24px;font-weight:900;letter-spacing:-1px;flex-shrink:0}
.company-name .nk{font-size:16pt;font-weight:900;color:#013d3c}
.company-name .none{font-size:12pt;font-weight:400;color:#013d3c;opacity:.65}
.doc-title-block{text-align:right}
.doc-title{font-size:16pt;font-weight:800;color:#013d3c;letter-spacing:-.3px}
.doc-subtitle{font-size:8pt;color:#607d8b;margin-top:1px}
.doc-orderno{font-size:10pt;font-weight:700;color:#1a1a1a;margin-top:5px;font-family:monospace}
.status-badge{display:inline-block;padding:2px 10px;border-radius:20px;font-size:7.5pt;font-weight:700;text-transform:uppercase;letter-spacing:.4px;margin-top:4px}
.sb-draft{background:#f5f5f5;color:#616161;border:1px solid #e0e0e0}
.sb-confirmed{background:#e3f2fd;color:#014f4e;border:1px solid #90caf9}
.sb-picked{background:#fff8e1;color:#e65100;border:1px solid #ffe082}
.sb-completed{background:#e8f5e9;color:#013d3c;border:1px solid #a5d6a7}

/* Info grid */
.info-grid{display:grid;grid-template-columns:1fr 1fr 1fr;border:1px solid #b2dfdb;border-radius:7px;overflow:hidden;margin-bottom:14px}
.info-cell{padding:8px 12px;border-right:1px solid #b2dfdb;border-bottom:1px solid #b2dfdb;background:#fff}
.info-cell:nth-child(3n){border-right:none}
.info-cell:nth-last-child(-n+3){border-bottom:none}
.info-cell.span2{grid-column:span 2}
.info-cell .lbl{font-size:6.5pt;color:#026766;text-transform:uppercase;letter-spacing:.5px;font-weight:700;margin-bottom:2px}
.info-cell .val{font-size:9.5pt;font-weight:600;color:#1a1a1a;line-height:1.5}
.info-cell .val .sub{font-size:8pt;font-weight:400;color:#78909c}

/* Summary */
.summary-bar{display:flex;gap:10px;margin-bottom:14px}
.sum-card{flex:1;border-radius:7px;padding:9px 12px;text-align:center;border:1px solid transparent}
.sum-card .num{font-size:18pt;font-weight:800;line-height:1}
.sum-card .lbl{font-size:6.5pt;text-transform:uppercase;letter-spacing:.5px;margin-top:3px;font-weight:600}
.sc-teal{background:#e0f2f1;color:#013d3c;border-color:#80cbc4}
.sc-green{background:#e8f5e9;color:#013d3c;border-color:#a5d6a7}
.sc-amber{background:#fff8e1;color:#e65100;border-color:#ffcc80}
.sc-blue{background:#e3f2fd;color:#0d47a1;border-color:#90caf9}
.sc-gray{background:#f5f5f5;color:#424242;border-color:#e0e0e0}

.section-title{font-size:8.5pt;font-weight:700;color:#013d3c;border-left:4px solid #026766;padding-left:8px;margin-bottom:8px;text-transform:uppercase;letter-spacing:.5px}

/* Table */
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
.chip-so{background:#ede9fe;color:#5b21b6;border:1px solid #c4b5fd}
.chip-loc{background:#d1fae5;color:#065f46;border:1px solid #6ee7b7}
.st-pending{background:#fff3e0;color:#e65100;padding:1px 7px;border-radius:4px;font-size:7.5pt;font-weight:700}
.st-picked{background:#e8f5e9;color:#026766;padding:1px 7px;border-radius:4px;font-size:7.5pt;font-weight:700}
.st-verified{background:#e3f2fd;color:#014f4e;padding:1px 7px;border-radius:4px;font-size:7.5pt;font-weight:700}
.check-box{width:14px;height:14px;border:1.5px solid #607d8b;border-radius:2px;display:inline-block}

/* Notes */
.remarks-box{border:1px solid #b2dfdb;border-radius:7px;padding:10px 14px;margin-bottom:14px;min-height:38px;background:#f9fdfd}
.remarks-lbl{font-size:7pt;color:#026766;text-transform:uppercase;letter-spacing:.5px;font-weight:700;margin-bottom:4px}

/* Signature */
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

<div class="print-bar no-print">
  <div class="print-bar-title"><i class="fas fa-clipboard-check"></i> Pick List — <?= htmlspecialchars($picklistNo) ?></div>
  <div class="btns">
    <a class="btn-back" href="javascript:window.close()">✕ Tutup</a>
    <button class="btn-print" onclick="window.print()">🖨️ Print / PDF</button>
  </div>
</div>

<div class="document">

  <!-- Header -->
  <div class="doc-header">
    <div class="logo-area">
      <div class="logo-mark">K</div>
      <div>
        <div class="company-name"><span class="nk">K</span><span class="none">-one</span></div>
      </div>
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
      <div class="val" style="font-family:monospace"><?= htmlspecialchars($picklistNo) ?></div>
    </div>
    <div class="info-cell">
      <div class="lbl">Tanggal</div>
      <div class="val"><?= date('d/m/Y', strtotime($pickDate)) ?></div>
    </div>
    <div class="info-cell">
      <div class="lbl">Outbound No.</div>
      <div class="val" style="font-family:monospace"><?= htmlspecialchars($outboundNo) ?></div>
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

  <!-- Summary -->
  <div class="summary-bar">
    <div class="sum-card sc-teal">
      <div class="num"><?= $totalLines ?></div>
      <div class="lbl">Total Lines</div>
    </div>
    <div class="sum-card sc-blue">
      <div class="num"><?= number_format($totalQty, 0) ?></div>
      <div class="lbl">Total Qty</div>
    </div>
    <div class="sum-card sc-gray">
      <div class="num"><?= number_format($totalPlt, 0) ?></div>
      <div class="lbl">Total Pallet</div>
    </div>
  </div>

  <!-- Items Table -->
  <div class="section-title">📦 Items to Pick</div>
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
        <th class="c" style="width:28px">Pick ✓</th>
      </tr>
    </thead>
    <tbody>
    <?php if (empty($items)): ?>
    <tr><td colspan="10" style="text-align:center;color:#90a4ae;padding:20px">Tidak ada item</td></tr>
    <?php endif; ?>
    <?php foreach ($items as $idx => $item):
      $pltDisp = (string)(int)ceil(floatval($item['pallet'] ?? 0));
      $iCust = $item['item_customer_name'] ?? null;
      $iSo   = $item['item_so_number'] ?? null;
      $iOd   = $item['item_od_number'] ?? null;
    ?>
    <tr>
      <td class="c" style="color:#90a4ae;font-size:7.5pt"><?= $idx + 1 ?></td>
      <td>
        <div style="font-weight:700;font-size:8.5pt;color:#013d3c"><?= htmlspecialchars($item['product_name'] ?? '—') ?></div>
        <div style="font-family:monospace;font-size:7.5pt;color:#607d8b"><?= htmlspecialchars($item['product_code'] ?? '') ?></div>
      </td>
      <td style="font-size:8pt">
        <?= $iCust ? htmlspecialchars($iCust) : '<span style="color:#ccc">—</span>' ?>
        <?php if ($item['item_ship_to'] ?? null): ?>
        <div style="font-size:7pt;color:#90a4ae"><?= htmlspecialchars($item['item_ship_to']) ?></div>
        <?php endif; ?>
      </td>
      <td>
        <?php if ($iSo): ?><span class="chip chip-so">SO: <?= htmlspecialchars($iSo) ?></span><?php endif; ?>
        <?php if ($iOd): ?>
          <?php if ($iSo): ?><br><?php endif; ?>
          <span class="chip chip-od">OD: <?= htmlspecialchars($iOd) ?></span>
        <?php endif; ?>
        <?php if (!$iSo && !$iOd): ?><span style="color:#ccc;font-size:7pt">—</span><?php endif; ?>
      </td>
      <td>
        <?php $bn = $item['batch_no'] ?? $item['batch_number'] ?? null; ?>
        <?php if ($bn): ?>
        <span class="chip chip-batch"><?= htmlspecialchars($bn) ?></span>
        <?php else: ?><span style="color:#ccc;font-size:7pt">—</span><?php endif; ?>
      </td>
      <td>
        <?php if ($item['location'] ?? null): ?>
        <span class="chip chip-loc"><?= htmlspecialchars($item['location']) ?></span>
        <?php else: ?>
        <span style="display:inline-block;min-width:70px;border-bottom:1.5px dashed #90a4ae;height:16px"></span>
        <?php endif; ?>
      </td>
      <td class="r" style="font-weight:700"><?= number_format((float)($item['quantity'] ?? 0), 0) ?></td>
      <td class="r" style="color:#546e7a"><?= $pltDisp ?></td>
      <td class="r"><span style="display:inline-block;min-width:46px;border-bottom:1.5px solid #b2dfdb;height:16px"></span></td>
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
if (location.search.includes('autoprint=1')) {
  window.onload = () => setTimeout(() => window.print(), 400);
}
</script>
</body>
</html>

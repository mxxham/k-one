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

$totalQty = array_sum(array_column($items, 'actual_qty'));
$accepted = array_filter($items, function($i){ return $i['stock_status'] === 'Accepted'; });
$rejected = array_filter($items, function($i){ return $i['stock_status'] === 'Rejected'; });

$itemLocations = [];
foreach ($items as $item) {
    $locs = Inbound::getItemLocations($item['id']);
    if (!empty($locs)) {
        $itemLocations[$item['id']] = $locs;
    }
}

// Pallet count: use actual stock_location rows if assigned, else stored pallet field
$totalPallet = 0;
foreach ($items as $item) {
    $locs = $itemLocations[$item['id']] ?? [];
    $totalPallet += !empty($locs) ? count($locs) : floatval($item['pallet'] ?? 0);
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>Inbound Report - <?= htmlspecialchars($inbound['order_number'] ?? $inbound['inbound_number'] ?? '-') ?></title>
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
    background: #026766;
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
    color: #026766;
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
    display: flex;
    align-items: center;
    gap: 6px;
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
    border-bottom: 3px solid #026766;
    padding-bottom: 14px;
    margin-bottom: 18px;
  }
  .doc-logo {
    display: flex;
    align-items: center;
    gap: 12px;
  }
  .hdr-logo {
    width: 52px; height: 52px;
    background: #026766;
    border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 28px; font-weight: 900; color: #fff;
    flex-shrink: 0; letter-spacing: -1px;
  }
  .doc-company { line-height: 1.1; }
  .doc-company .name .nk   { font-size: 24pt; font-weight: 900; color: #026766; }
  .doc-company .name .none { font-size: 18pt; font-weight: 400; color: #026766; opacity: .7; }
  .doc-title-block { text-align: right; }
  .doc-title-block .title  { font-size: 16pt; font-weight: 700; color: #026766; letter-spacing: -.3px; }
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
  .badge-dues-in    { background: #fff3e0; color: #e65100; border: 1px solid #ffcc80; }
  .badge-atp        { background: #e8f5e9; color: #026766; border: 1px solid #a5d6a7; }
  .badge-completed  { background: #e3f2fd; color: #014f4e; border: 1px solid #90caf9; }
  .badge-received   { background: #e0f7f7; color: #026766; border: 1px solid #80d2d2; }
  .badge-default    { background: #f5f5f5; color: #616161; border: 1px solid #e0e0e0; }

  
  .info-grid {
    display: grid;
    grid-template-columns: 1fr 1fr 1fr;
    gap: 0;
    border: 1px solid #dde3ea;
    border-radius: 6px;
    overflow: hidden;
    margin-bottom: 18px;
  }
  .info-cell {
    padding: 8px 12px;
    border-right: 1px solid #dde3ea;
    border-bottom: 1px solid #dde3ea;
  }
  .info-cell:nth-child(3n) { border-right: none; }
  .info-cell:nth-last-child(-n+3) { border-bottom: none; }
  .info-cell .lbl { font-size: 7.5pt; color: #90a4ae; text-transform: uppercase; letter-spacing: .4px; margin-bottom: 2px; }
  .info-cell .val { font-size: 10pt; font-weight: 600; color: #1a1a1a; }
  .info-cell.span2 { grid-column: span 2; }
  .info-cell.span3 { grid-column: span 3; }

  
  .summary-bar {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-bottom: 16px;
  }
  .sum-card {
    flex: 1 1 100px;
    min-width: 88px;
    border-radius: 6px;
    padding: 10px 12px;
    text-align: center;
  }
  .sum-card .num { font-size: 18pt; font-weight: 700; }
  .sum-card .lbl { font-size: 7.5pt; text-transform: uppercase; letter-spacing: .4px; margin-top: 2px; }
  .sc-blue  { background: #e3f2fd; color: #013d3c; }
  .sc-green { background: #e8f5e9; color: #013d3c; }
  .sc-amber { background: #fff8e1; color: #f57f17; }
  .sc-red   { background: #fce4ec; color: #880e4f; }

  
  .section-title {
    font-size: 10pt;
    font-weight: 700;
    color: #026766;
    border-left: 4px solid #026766;
    padding-left: 8px;
    margin-bottom: 8px;
    margin-top: 16px;
    text-transform: uppercase;
    letter-spacing: .5px;
  }

  
  table.items-table {
    width: 100%;
    border-collapse: collapse;
    table-layout: fixed;
    font-size: 8pt;
    margin-bottom: 10px;
  }
  table.items-table thead th {
    background: #026766;
    color: #fff;
    padding: 6px 5px;
    text-align: left;
    font-weight: 600;
    font-size: 7pt;
    letter-spacing: .2px;
    word-wrap: break-word;
  }
  thead th.num { text-align: center; }
  thead th.right { text-align: right; }
  tbody tr:nth-child(even) { background: #f8fafd; }
  tbody tr:hover { background: #e3f0ff; }
  @media print {
    tbody tr:hover { background: transparent !important; }
    tbody tr:nth-child(even) { background: #f8fafd; }
  }
  table.items-table tbody td {
    padding: 5px 5px;
    border-bottom: 1px solid #e8ecf0;
    vertical-align: top;
    line-height: 1.35;
    word-wrap: break-word;
    overflow-wrap: break-word;
  }
  tbody td.num { text-align: center; }
  tbody td.right { text-align: right; font-weight: 600; }
  tfoot td {
    padding: 7px 8px;
    font-weight: 700;
    font-size: 9.5pt;
    border-top: 2px solid #026766;
    background: #e8f0fb;
  }
  tfoot td.right { text-align: right; }

  .sku { font-family: monospace; font-size: 8pt; color: #546e7a; }
  .loc-badge {
    display: inline-block;
    background: #e3f2fd;
    color: #014f4e;
    border-radius: 4px;
    padding: 1px 6px;
    font-family: monospace;
    font-size: 8pt;
    font-weight: 600;
  }
  .loc-badge.qua { background: #fce4ec; color: #880e4f; }
  .status-acc { color: #026766; font-weight: 700; }
  .status-rej { color: #014f4e; font-weight: 700; }

  .ref-stack { font-family: monospace; font-size: 7pt; line-height: 1.3; }
  .ref-stack .ref-od { color: #013d3c; }
  .ref-stack .ref-so { color: #283593; }
  .loc-stack { font-size: 7pt; line-height: 1.35; }
  .loc-row {
    margin: 0 0 3px;
    padding: 2px 0 2px 6px;
    border-left: 2px solid #90caf9;
    word-break: break-word;
  }
  .loc-row:last-child { margin-bottom: 0; }
  .loc-meta { font-size: 6.5pt; color: #546e7a; }

  
  .sig-grid {
    display: grid;
    grid-template-columns: 1fr 1fr 1fr;
    gap: 20px;
    margin-top: 28px;
  }
  .sig-box {
    border-top: 1.5px solid #bdbdbd;
    padding-top: 8px;
  }
  .sig-box .role { font-size: 8pt; color: #78909c; text-transform: uppercase; letter-spacing: .4px; }
  .sig-box .space { height: 40px; }
  .sig-box .name-line { border-bottom: 1px dashed #bdbdbd; margin: 4px 0 4px; }

  
  .doc-footer {
    margin-top: 24px;
    padding-top: 10px;
    border-top: 1px solid #e0e0e0;
    display: flex;
    justify-content: space-between;
    font-size: 7.5pt;
    color: #90a4ae;
  }
</style>
</head>
<body>

<div class="print-bar no-print">
  <span>🖨️ Inbound Report Preview</span>
  <div class="btns">
    <a class="btn-back" href="inbound.php?action=view&id=<?= $id ?>">← Kembali</a>
    <button class="btn-print" onclick="window.print()">🖨️ Print / Save PDF</button>
  </div>
</div>

<div class="document">

  
  <div class="doc-header">
    <div class="doc-logo">
      <div class="hdr-logo">K</div>
      <div class="doc-company">
        <div class="name"><span class="nk">K</span><span class="none">-one</span></div>
      </div>
    </div>
    <div class="doc-title-block">
      <div class="title">INBOUND REPORT</div>
      <div class="sub">Goods Receipt Document</div>
      <div class="number"><?= htmlspecialchars($inbound['order_number'] ?? $inbound['inbound_number'] ?? '-') ?></div>
      <div style="margin-top:5px">
        <?php
        $s = $inbound['status'] ?? 'Dues In';
        if ($s === 'Dues In')           $cls = 'dues-in';
        elseif ($s === 'ATP')           $cls = 'atp';
        elseif ($s === 'Completed')     $cls = 'completed';
        elseif (strpos($s,'Received') !== false) $cls = 'received';
        else                            $cls = 'default';
        ?>
        <span class="badge badge-<?= $cls ?>"><?= htmlspecialchars($s) ?></span>
      </div>
    </div>
  </div>

  
  <div class="section-title">📋 Detail Order</div>
  <div class="info-grid">
    <div class="info-cell">
      <div class="lbl">Carrier</div>
      <div class="val"><?= htmlspecialchars($inbound['carrier_name'] ?? '—') ?></div>
    </div>
    <div class="info-cell">
      <div class="lbl">Order Date</div>
      <div class="val"><?= $inbound['order_date'] ? date('d F Y', strtotime($inbound['order_date'])) : '—' ?></div>
    </div>
    <div class="info-cell">
      <div class="lbl">Status</div>
      <div class="val"><span class="badge badge-<?= $cls ?>"><?= htmlspecialchars($s) ?></span></div>
    </div>
    <div class="info-cell">
      <div class="lbl">Shipment No.</div>
      <div class="val"><?= htmlspecialchars($inbound['shipment_no'] ?? '—') ?></div>
    </div>
    <div class="info-cell">
      <div class="lbl">Container No.</div>
      <div class="val"><?= htmlspecialchars($inbound['container_no'] ?? '—') ?></div>
    </div>
    <div class="info-cell">
      <div class="lbl">Armada No.</div>
      <div class="val"><?= htmlspecialchars($inbound['armada_no'] ?? '—') ?></div>
    </div>
    <div class="info-cell">
      <div class="lbl">Received By</div>
      <div class="val"><?= htmlspecialchars($inbound['received_by_name'] ?? '—') ?></div>
    </div>
    <div class="info-cell span2">
      <div class="lbl">Received Date</div>
      <div class="val"><?= $inbound['received_date'] ? date('d F Y', strtotime($inbound['received_date'])) : '—' ?></div>
    </div>
    <?php if ($inbound['notes'] ?? ''): ?>
    <div class="info-cell span3">
      <div class="lbl">Notes</div>
      <div class="val" style="font-weight:400"><?= htmlspecialchars($inbound['notes']) ?></div>
    </div>
    <?php endif; ?>
  </div>

  
  <div class="summary-bar">
    <div class="sum-card sc-blue">
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
    <div class="sum-card sc-green">
      <div class="num"><?= count($accepted) ?></div>
      <div class="lbl">Accepted</div>
    </div>
    <div class="sum-card sc-red">
      <div class="num"><?= count($rejected) ?></div>
      <div class="lbl">Rejected</div>
    </div>
  </div>

  
  <div class="section-title">📦 Detail Barang</div>
  <table class="items-table">
    <thead>
      <tr>
        <th style="width:4%">#</th>
        <th style="width:11%">OD / SO</th>
        <th style="width:20%">Product / SKU</th>
        <th style="width:9%">Batch</th>
        <th style="width:6%" class="right">Qty</th>
        <th style="width:5%" class="num">UOM</th>
        <th style="width:6%" class="right">Plt</th>
        <th style="width:22%">Lokasi pallet</th>
        <th style="width:7%">Mfg</th>
        <th style="width:7%">Exp</th>
        <th style="width:5%" class="num">Sts</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($items as $i => $item): ?>
      <tr>
        <td class="num" style="color:#90a4ae"><?= $i + 1 ?></td>
        <td>
          <div class="ref-stack">
            <div class="ref-od">OD: <?= htmlspecialchars($item['od_number'] ?? '—') ?></div>
            <div class="ref-so">SO: <?= htmlspecialchars($item['so_number'] ?? '—') ?></div>
          </div>
        </td>
        <td>
          <div style="font-weight:600;color:#1a1a1a"><?= htmlspecialchars($item['product_name'] ?? '—') ?></div>
          <div class="sku"><?= htmlspecialchars($item['product_code'] ?? '') ?></div>
        </td>
        <td style="font-family:monospace;font-size:7.5pt"><?= htmlspecialchars($item['batch_number'] ?? '—') ?></td>
        <td class="right"><?= number_format((float)($item['actual_qty'] ?? $item['quantity'] ?? 0), 0) ?></td>
        <td class="num"><?= htmlspecialchars($item['uom'] ?? '—') ?></td>
        <td class="right"><?php
          $palletLocs0 = $itemLocations[$item['id']] ?? [];
          // Actual pallet count: number of pallet location rows, or stored pallet field
          $plt = !empty($palletLocs0) ? count($palletLocs0) : floatval($item['pallet'] ?? 0);
          echo number_format($plt, 0);
        ?></td>
        <td>
          <?php $palletLocs = $itemLocations[$item['id']] ?? []; ?>
          <?php if (!empty($palletLocs)): ?>
          <div class="loc-stack">
            <?php foreach ($palletLocs as $pl):
              $dq = floatval($pl['display_quantity'] ?? $pl['original_quantity'] ?? $pl['quantity']);
              $isFull = (bool)($pl['is_full_pallet'] ?? ($dq >= 4));
            ?>
            <div class="loc-row">
              <strong>P<?= (int)$pl['pallet_seq'] ?></strong>
              <code style="font-size:7pt"><?= htmlspecialchars($pl['location_code']) ?></code>
              · <?= number_format($dq, 0) ?> <?= htmlspecialchars($pl['uom'] ?? '') ?>
              <span class="loc-meta"> · <?= $isFull ? 'full' : 'partial' ?></span>
            </div>
            <?php endforeach; ?>
          </div>
          <?php else:
          $loc = $item['location'] ?? '';
          ?>
          <span class="loc-badge <?= $loc === 'QUA_SHELL' ? 'qua' : '' ?>"><?= htmlspecialchars($loc ?: '—') ?></span>
          <?php endif; ?>
        </td>
        <td style="font-size:7.5pt"><?= $item['manufacture_date'] ? date('d/m/y', strtotime($item['manufacture_date'])) : '—' ?></td>
        <td style="font-size:7.5pt;<?= ($item['exp_date'] && strtotime($item['exp_date']) < strtotime('+3 months')) ? 'color:#014f4e;font-weight:700' : '' ?>">
          <?= $item['exp_date'] ? date('d/m/y', strtotime($item['exp_date'])) : '—' ?>
        </td>
        <td class="num">
          <?php if ($item['stock_status'] === 'Accepted'): ?>
          <span class="status-acc">✓</span>
          <?php else: ?>
          <span class="status-rej">✗</span>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if (empty($items)): ?>
      <tr><td colspan="11" style="text-align:center;color:#90a4ae;padding:20px">Tidak ada item</td></tr>
      <?php endif; ?>
    </tbody>
    <tfoot>
      <tr>
        <td colspan="4"><strong>TOTAL</strong></td>
        <td class="right"><?= number_format($totalQty, 0) ?></td>
        <td></td>
        <td class="right"><?= number_format($totalPallet, 0) ?></td>
        <td colspan="4"></td>
      </tr>
    </tfoot>
  </table>

  
  <div class="sig-grid">
    <div class="sig-box">
      <div class="role">Dibuat Oleh</div>
      <div class="space"></div>
      <div class="name-line"></div>
      <div style="font-size:8pt;color:#546e7a"><?= htmlspecialchars($inbound['created_by_name'] ?? 'Warehouse Staff') ?></div>
    </div>
    <div class="sig-box">
      <div class="role">Diperiksa Oleh</div>
      <div class="space"></div>
      <div class="name-line"></div>
      <div style="font-size:8pt;color:#546e7a">Warehouse Supervisor</div>
    </div>
    <div class="sig-box">
      <div class="role">Disetujui Oleh</div>
      <div class="space"></div>
      <div class="name-line"></div>
      <div style="font-size:8pt;color:#546e7a">Warehouse Manager</div>
    </div>
  </div>

  
  <div class="doc-footer">
    <span>K-one</span>
    <span>Dicetak: <?= date('d F Y H:i') ?> WIB</span>
    <span><?= htmlspecialchars($inbound['order_number'] ?? $inbound['inbound_number'] ?? '-') ?></span>
  </div>

</div>

<script>

if (location.search.includes('autoprint=1')) {
  window.onload = () => setTimeout(() => window.print(), 400);
}
</script>

</body>
</html>

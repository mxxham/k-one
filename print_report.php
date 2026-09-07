<?php
session_start();
date_default_timezone_set('Asia/Jakarta');
if (ob_get_level() === 0) {
    header('Content-Type: text/html; charset=UTF-8');
}
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/classes/Auth.php';
require_once __DIR__ . '/classes/Report.php';
require_once __DIR__ . '/classes/Stock.php';

Auth::requireAuth();

$reportType = $_GET['type'] ?? 'daily';
$date       = $_GET['date']    ?? date('Y-m-d');
$dateTo     = $_GET['date_to'] ?? $date;
if ($dateTo < $date) $dateTo = $date;

$report   = ($reportType === 'daily')    ? Report::getDailyReport($date, $dateTo) : null;
$stock    = ($reportType === 'stock')    ? Stock::getAll()               : null;
$expiring = ($reportType === 'expiring') ? Stock::getExpiringSoon(365)   : null;

$reportTitles = [
    'daily'    => 'Daily Report — ' . date('d F Y', strtotime($date)),
    'stock'    => 'Stock Summary Report',
    'expiring' => 'Expiring Items Report (Next 365 Days)',
];
$reportTitle = $reportTitles[$reportType] ?? 'Report';

$themeColors = [
    'daily'    => ['#026766', '#e3f2fd', '#014f4e'],
    'stock'    => ['#013d3c', '#e8f5e9', '#026766'],
    'expiring' => ['#014f4e', '#fce4ec', '#014f4e'],
];
[$accent, $lightBg, $darkAccent] = $themeColors[$reportType] ?? $themeColors['daily'];
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title><?= htmlspecialchars($reportTitle) ?> — Shell CKB WMS</title>
<link rel="stylesheet" href="assets/css/print-shared.css">
<style>
  body { background: #f8fafc; }
  @media print { .document { box-shadow: none !important; margin: 0 !important; padding: 14mm 14mm 16mm !important; max-width: 100% !important; border-radius: 0 !important; } }

  .print-bar { padding: 10px 24px; box-shadow: 0 2px 12px rgba(0,0,0,.25); z-index: 999; }
  .print-bar .left { display: flex; align-items: center; gap: 10px; }
  .print-bar .left .badge { background: rgba(255,255,255,.2); border: 1px solid rgba(255,255,255,.4); border-radius: 20px; padding: 3px 12px; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .5px; }
  .print-bar .title { font-weight: 700; font-size: 14px; }
  .btn-print { color: #0f2e2d; padding: 7px 20px; border-radius: 7px; font-size: 13px; display: flex; align-items: center; gap: 6px; font-family: 'Inter', sans-serif; }
  .btn-back { background: transparent; border: 1.5px solid rgba(255,255,255,.5); padding: 7px 18px; border-radius: 7px; font-size: 13px; display: flex; align-items: center; gap: 6px; }

  .document { max-width: 794px; margin: 22px auto 40px; padding: 32px 34px; box-shadow: 0 4px 30px rgba(0,0,0,.12); }
  @media print { .document { box-shadow: none; margin: 0; padding: 14mm 14mm 16mm; max-width: 100%; border-radius: 0; } }

  .doc-header { padding-bottom: 16px; margin-bottom: 20px; }
  .hdr-left { display: flex; align-items: center; gap: 14px; }
  .hdr-logo { width: 52px; height: 52px; }
  .hdr-logo .logo-k { color: #fff; font-size: 26px; font-weight: 900; line-height: 1; }
  .hdr-company .name { line-height: 1.1; }
  .hdr-company .name .nk { font-size: 24pt; font-weight: 900; color: #026766; }
  .hdr-company .name .none { font-size: 18pt; font-weight: 400; color: #026766; opacity: .7; }
  .hdr-right { text-align: right; }
  .hdr-right .report-type { font-size: 17pt; font-weight: 700; color: #026766; letter-spacing: -.5px; line-height: 1.1; }
  .hdr-right .report-sub { font-size: 8.5pt; color: #64748b; margin-top: 3px; }
  .hdr-right .report-date { margin-top: 6px; background: #e8f5e9; color: #013d3c; display: inline-block; padding: 3px 12px; border-radius: 20px; font-size: 9pt; font-weight: 600; }

  .sec-title { font-size: 9.5pt; font-weight: 700; color: #026766; border-left: 4px solid #026766; padding-left: 8px; margin: 16px 0 8px; text-transform: uppercase; letter-spacing: .6px; }

  .summary-row { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin-bottom: 18px; }
  .sum-card { border-radius: 8px; padding: 11px 14px; text-align: center; }
  .sum-card .num { font-size: 18pt; font-weight: 700; line-height: 1.1; }
  .sum-card .lbl { font-size: 7.5pt; text-transform: uppercase; letter-spacing: .5px; margin-top: 2px; opacity: .75; }
  .sc-in { background: #e8f5e9; color: #026766; }
  .sc-out { background: #fce4ec; color: #880e4f; }
  .sc-trx { background: #e3f2fd; color: #026766; }
  .sc-exp { background: #fff3e0; color: #e65100; }

  table { font-size: 8.5pt; margin-bottom: 18px; page-break-inside: auto; }
  thead th { padding: 7px 8px; font-size: 7.5pt; letter-spacing: .3px; position: sticky; top: 0; }
  thead th.r { text-align: right; }
  thead th.c { text-align: center; }
  tbody tr { page-break-inside: avoid; }
  tbody td { padding: 5.5px 8px; border-bottom: 1px solid #e2e8f0; line-height: 1.4; }
  tbody td.r { text-align: right; font-weight: 600; }
  tbody td.c { text-align: center; }
  tfoot td { font-size: 9pt; background: #f8fafc; }
  tfoot td.r { text-align: right; }

  .uom-badge { display: inline-block; padding: 1px 7px; border-radius: 20px; font-size: 7.5pt; font-weight: 700; }
  .uom-drum { background: #e3f2fd; color: #014f4e; }
  .uom-carton { background: #e8f5e9; color: #026766; }
  .uom-pail { background: #fff8e1; color: #f57f17; }
  .uom-ea { background: #e0f7f7; color: #026766; }
  .uom-bags { background: #fff3e0; color: #e65100; }

  .tx-in { background: #e8f5e9; color: #026766; font-weight: 700; display: inline-block; padding: 1px 8px; border-radius: 4px; font-size: 8pt; }
  .tx-out { background: #fce4ec; color: #014f4e; font-weight: 700; display: inline-block; padding: 1px 8px; border-radius: 4px; font-size: 8pt; }

  .exp-crit { color: #014f4e; font-weight: 700; }
  .exp-warn { color: #e65100; font-weight: 700; }
  .exp-ok { color: #026766; }

  .act-badge { display: inline-block; padding: 1px 8px; border-radius: 4px; font-size: 7.5pt; font-weight: 600; }
  .act-completed { background: #e8f5e9; color: #026766; }
  .act-dues { background: #fff3e0; color: #e65100; }
  .act-default { background: #f8fafc; color: #64748b; }

  .doc-footer { margin-top: 28px; font-size: 7pt; }
  .watermark-row { display: flex; align-items: center; gap: 8px; }
  .watermark-dot { width: 8px; height: 8px; border-radius: 50%; background: #026766; opacity: .4; }
</style>
</head>
<body>

<div class="print-bar no-print">
  <div class="left">
    <span class="title">Report Preview</span>
    <span class="badge"><?= strtoupper($reportType) ?></span>
  </div>
  <div class="btns">
    <a class="btn-back" href="javascript:history.back()">Back</a>
    <button class="btn-print" onclick="window.print()">Print / Save PDF</button>
  </div>
</div>

<div class="document">

  
  <div class="doc-header">
    <div class="hdr-left">
      <div class="hdr-logo">
        <span class="logo-k">K</span>
      </div>
      <div class="hdr-company">
        <div class="name"><span class="nk">K</span><span class="none">-one</span></div>
      </div>
    </div>
    <div class="hdr-right">
      <div class="report-type">
        <?= match($reportType) {
          'daily'    => 'DAILY REPORT',
          'stock'    => 'STOCK SUMMARY',
          'expiring' => 'EXPIRY REPORT',
          'movement' => 'MOVEMENT LOG',
          default    => 'REPORT'
        } ?>
      </div>
      <div class="report-sub">K-one Management</div>
      <div class="report-date"><?= htmlspecialchars($reportTitle) ?></div>
    </div>
  </div>

  <?php  ?>
  <?php if ($reportType === 'daily' && $report): ?>

    <?php $ls = $report['ledger_summary'] ?? []; ?>
    <div class="summary-row">
      <div class="sum-card sc-in">
        <div class="num"><?= number_format($ls['qty_in'] ?? $ls['drums_in'] ?? 0) ?></div>
        <div class="lbl">Qty In</div>
      </div>
      <div class="sum-card sc-out">
        <div class="num"><?= number_format($ls['qty_out'] ?? $ls['drums_out'] ?? 0) ?></div>
        <div class="lbl">Qty Out</div>
      </div>
      <div class="sum-card sc-trx">
        <div class="num"><?= number_format(($ls['transactions_in'] ?? 0) + ($ls['transactions_out'] ?? 0)) ?></div>
        <div class="lbl">Transaksi</div>
      </div>
      <div class="sum-card sc-exp">
        <div class="num"><?= count($report['expiring_items'] ?? []) ?></div>
        <div class="lbl">Near Expiry</div>
      </div>
    </div>

    
    <div class="sec-title">Stock Summary</div>
    <table>
      <thead><tr>
        <th>Product Code</th>
        <th>Product Name</th>
        <th class="c">Batches</th>
        <th class="c">UOM</th>
        <th class="r">Qty</th>
        <th class="r">Pallets</th>
        <th>Nearest Expiry</th>
      </tr></thead>
      <tbody>
        <?php foreach ($report['stock_summary'] ?? [] as $item): ?>
        <tr>
          <td class="mono"><?= htmlspecialchars($item['product_code']) ?></td>
          <td style="font-weight:500"><?= htmlspecialchars($item['product_name']) ?></td>
          <td class="c"><?= $item['batches'] ?></td>
          <td class="c">
            <?php $u=$item['uom_type']??'Drum'; ?>
            <span class="uom-badge uom-<?= strtolower($u) ?>"><?= $u ?></span>
          </td>
          <td class="r"><?= number_format($item['total_drums'] ?? $item['total_qty'] ?? 0) ?></td>
          <td class="r"><?= (int)ceil($item['total_pallets']) ?></td>
          <td><?= $item['nearest_expiry'] ? date('d M Y', strtotime($item['nearest_expiry'])) : '—' ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot><tr>
        <td colspan="4"><strong>TOTAL</strong></td>
        <td class="r"><?= number_format(array_sum(array_column($report['stock_summary']??[], 'total_drums') ?: array_column($report['stock_summary']??[], 'total_qty'))) ?></td>
        <td class="r"><?= (int)ceil(array_sum(array_column($report['stock_summary']??[], 'total_pallets'))) ?></td>
        <td></td>
      </tr></tfoot>
    </table>

    
    <?php if (!empty($report['inbound_activity'])): ?>
    <div class="sec-title">Inbound Activity</div>
    <table>
      <thead><tr>
        <th>Inbound #</th>
        <th>Status</th>
        <th class="r">Lines</th>
        <th class="r">Total Qty</th>
      </tr></thead>
      <tbody>
        <?php foreach ($report['inbound_activity'] as $item): ?>
        <tr>
          <td class="mono"><?= htmlspecialchars($item['order_number'] ?? $item['inbound_number'] ?? '—') ?></td>
          <td>
            <?php $sc = str_contains($item['status'],'Complet') ? 'act-completed' : (str_contains($item['status'],'Dues') ? 'act-dues' : 'act-default'); ?>
            <span class="act-badge <?= $sc ?>"><?= htmlspecialchars($item['status']) ?></span>
          </td>
          <td class="r"><?= $item['item_count'] ?></td>
          <td class="r"><?= number_format($item['total_drums'] ?? $item['total_qty'] ?? 0) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>

    
    <?php if (!empty($report['outbound_activity'])): ?>
    <div class="sec-title">Outbound Activity</div>
    <table>
      <thead><tr>
        <th>Outbound #</th>
        <th>Status</th>
        <th class="r">Lines</th>
        <th class="r">Total Qty</th>
      </tr></thead>
      <tbody>
        <?php foreach ($report['outbound_activity'] as $item): ?>
        <tr>
          <td class="mono"><?= htmlspecialchars($item['outbound_number'] ?? $item['order_number'] ?? '—') ?></td>
          <td>
            <?php $sc = str_contains($item['status'],'Complet') ? 'act-completed' : (str_contains($item['status'],'Ship') ? 'act-completed' : 'act-default'); ?>
            <span class="act-badge <?= $sc ?>"><?= htmlspecialchars($item['status']) ?></span>
          </td>
          <td class="r"><?= $item['item_count'] ?></td>
          <td class="r"><?= number_format($item['total_drums'] ?? $item['total_qty'] ?? 0) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>

    
    <?php if (!empty($report['expiring_items'])): ?>
    <div class="sec-title">Expiring Items (Within 90 Days)</div>
    <table>
      <thead><tr>
        <th>Product</th>
        <th>Batch</th>
        <th>Expiry Date</th>
        <th class="r">Days Left</th>
        <th class="r">Qty</th>
      </tr></thead>
      <tbody>
        <?php foreach ($report['expiring_items'] as $item): ?>
        <tr>
          <td>
            <div style="font-weight:500"><?= htmlspecialchars($item['product_name']) ?></div>
            <div class="mono"><?= htmlspecialchars($item['product_code']) ?></div>
          </td>
          <td class="mono"><?= htmlspecialchars($item['batch_number']) ?></td>
          <td><?= date('d M Y', strtotime($item['expiry_date'])) ?></td>
          <td class="r">
            <?php $d=$item['days_until_expiry']; $cls=$d<30?'exp-crit':($d<90?'exp-warn':'exp-ok'); ?>
            <span class="<?= $cls ?>"><?= $d ?> hari</span>
          </td>
          <td class="r"><?= number_format($item['quantity_in'] ?? $item['quantity_drums'] ?? 0) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>

  <?php  ?>
  <?php elseif ($reportType === 'stock' && $stock !== null): ?>

    <div class="sec-title">Detail Stock per Batch</div>
    <table>
      <thead><tr>
        <th>Product</th>
        <th>Batch</th>
        <th class="c">UOM</th>
        <th class="r">Qty</th>
        <th class="r">Pallets</th>
        <th>Expiry</th>
        <th>Lokasi</th>
        <th class="c">Status</th>
      </tr></thead>
      <tbody>
        <?php foreach ($stock as $item):
          $uom = $item['uom_type'] ?? $item['uom'] ?? 'Drum';
          $expDate = $item['expiry_date'] ?? null;
          $isExpWarn = $expDate && strtotime($expDate) < strtotime('+90 days');
        ?>
        <tr>
          <td>
            <div style="font-weight:500;font-size:8.5pt"><?= htmlspecialchars($item['product_name']) ?></div>
            <div class="mono"><?= htmlspecialchars($item['product_code']) ?></div>
          </td>
          <td class="mono"><?= htmlspecialchars($item['batch_number'] ?? '—') ?></td>
          <td class="c"><span class="uom-badge uom-<?= strtolower($uom) ?>"><?= $uom ?></span></td>
          <td class="r"><?= number_format($item['quantity_in'] ?? $item['quantity_drums'] ?? 0) ?></td>
          <td class="r"><?= (int)ceil($item['pallet'] ?? 0) ?></td>
          <td class="<?= $isExpWarn ? 'exp-crit' : '' ?>">
            <?= $expDate ? date('d M Y', strtotime($expDate)) : '—' ?>
          </td>
          <td class="mono" style="font-size:8pt"><?= htmlspecialchars($item['location'] ?? '—') ?></td>
          <td class="c">
            <?php $ss=$item['stock_status']??''; ?>
            <span class="act-badge <?= $ss==='Accepted'||$ss===''?'act-completed':'act-dues' ?>"><?= $ss ?: 'OK' ?></span>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot><tr>
        <td colspan="3"><strong>TOTAL</strong></td>
        <td class="r"><?= number_format(array_sum(array_column($stock, 'quantity_in') ?: array_column($stock, 'quantity_drums'))) ?></td>
        <td class="r"><?= (int)ceil(array_sum(array_column($stock, 'pallet'))) ?></td>
        <td colspan="3"></td>
      </tr></tfoot>
    </table>

  <?php  ?>
  <?php elseif ($reportType === 'expiring' && $expiring !== null): ?>

    <?php
    $crit  = array_filter($expiring, fn($i) => $i['days_until_expiry'] < 30);
    $warn  = array_filter($expiring, fn($i) => $i['days_until_expiry'] >= 30 && $i['days_until_expiry'] < 90);
    $later = array_filter($expiring, fn($i) => $i['days_until_expiry'] >= 90);
    ?>
    <div class="summary-row">
      <div class="sum-card sc-out"><div class="num"><?= count($crit) ?></div><div class="lbl">Critical &lt;30 days</div></div>
      <div class="sum-card sc-exp"><div class="num"><?= count($warn) ?></div><div class="lbl">Warning 30-90 days</div></div>
      <div class="sum-card sc-in"><div class="num"><?= count($later) ?></div><div class="lbl">OK &gt;90 days</div></div>
      <div class="sum-card sc-trx"><div class="num"><?= count($expiring) ?></div><div class="lbl">Total Batches</div></div>
    </div>

    <div class="sec-title">Daftar Item Mendekati Kadaluarsa</div>
    <table>
      <thead><tr>
        <th>Product</th>
        <th>Batch</th>
        <th>Expiry Date</th>
        <th class="r">Sisa Hari</th>
        <th class="r">Qty</th>
        <th>Lokasi</th>
      </tr></thead>
      <tbody>
        <?php foreach ($expiring as $item):
          $d=$item['days_until_expiry'];
          $cls = $d<30 ? 'exp-crit' : ($d<90 ? 'exp-warn' : 'exp-ok');
        ?>
        <tr>
          <td>
            <div style="font-weight:500"><?= htmlspecialchars($item['product_name']) ?></div>
            <div class="mono"><?= htmlspecialchars($item['product_code']) ?></div>
          </td>
          <td class="mono"><?= htmlspecialchars($item['batch_number']) ?></td>
          <td><?= date('d M Y', strtotime($item['expiry_date'])) ?></td>
          <td class="r"><span class="<?= $cls ?>"><?= $d ?> hari</span></td>
          <td class="r"><?= number_format($item['quantity_in'] ?? $item['quantity_drums'] ?? 0) ?></td>
          <td class="mono"><?= htmlspecialchars($item['location'] ?? '—') ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

  <?php  ?>
  <?php elseif ($reportType === 'movement' && $movement !== null): ?>

    <?php
    $qIn  = array_sum(array_map(fn($i) => $i['transaction_type']==='IN' ? ($i['quantity_in']??$i['quantity_drums']??0) : 0, $movement));
    $qOut = array_sum(array_map(fn($i) => $i['transaction_type']==='OUT' ? ($i['quantity_in']??$i['quantity_drums']??0) : 0, $movement));
    ?>
    <div class="summary-row">
      <div class="sum-card sc-in"><div class="num"><?= number_format($qIn) ?></div><div class="lbl">Total IN</div></div>
      <div class="sum-card sc-out"><div class="num"><?= number_format($qOut) ?></div><div class="lbl">Total OUT</div></div>
      <div class="sum-card sc-trx"><div class="num"><?= count($movement) ?></div><div class="lbl">Transaksi</div></div>
      <div class="sum-card sc-exp"><div class="num"><?= number_format($qIn - $qOut) ?></div><div class="lbl">Net Movement</div></div>
    </div>

    <div class="sec-title">Log Pergerakan Stok</div>
    <table>
      <thead><tr>
        <th>Waktu</th>
        <th>Product</th>
        <th class="c">Tipe</th>
        <th>Referensi</th>
        <th class="r">Qty</th>
        <th class="r">Balance</th>
      </tr></thead>
      <tbody>
        <?php foreach ($movement as $item):
          $isIn = $item['transaction_type'] === 'IN';
          $qty  = $item['quantity_in'] ?? $item['quantity_drums'] ?? 0;
        ?>
        <tr>
          <td class="mono"><?= date('H:i', strtotime($item['created_at'])) ?></td>
          <td>
            <div style="font-weight:500;font-size:8.5pt"><?= htmlspecialchars($item['product_code'] ?? '') ?></div>
          </td>
          <td class="c"><span class="<?= $isIn ? 'tx-in' : 'tx-out' ?>"><?= $item['transaction_type'] ?></span></td>
          <td class="mono"><?= htmlspecialchars($item['reference_number'] ?? '—') ?></td>
          <td class="r" style="color:<?= $isIn ? '#026766' : '#014f4e' ?>">
            <?= $isIn ? '+' : '–' ?><?= number_format($qty) ?>
          </td>
          <td class="r"><?= number_format($item['balance'] ?? $item['balance_drums'] ?? 0) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot><tr>
        <td colspan="4"><strong>TOTAL MOVEMENT</strong></td>
        <td class="r">+<?= number_format($qIn) ?> / –<?= number_format($qOut) ?></td>
        <td></td>
      </tr></tfoot>
    </table>

  <?php endif; ?>

  
  <div class="doc-footer">
    <div class="watermark-row">
      <div class="watermark-dot"></div>
      <span>K-one</span>
    </div>
    <span>Dicetak: <?= date('d F Y H:i') ?> WIB</span>
    <span><?= htmlspecialchars($reportTitle) ?></span>
  </div>

</div>

<script>
window.onload = function() {
  setTimeout(function() { window.print(); }, 500);
};
</script>
</body>
</html>

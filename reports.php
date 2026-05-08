<?php
session_start();
header("Cache-Control: no-store, no-cache, must-revalidate");
header("Pragma: no-cache");
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/classes/Auth.php';
require_once __DIR__ . '/classes/Report.php';
require_once __DIR__ . '/classes/Stock.php';
require_once __DIR__ . '/classes/ExcelExport.php';

Auth::requireAuth();
$canWrite = Auth::canWrite();
$canAdmin = Auth::canAdmin();

$pageTitle = 'Reports';
$currentPage = 'reports';

$reportType = $_GET['type'] ?? 'daily';
$date   = $_GET['date']    ?? date('Y-m-d');
$dateTo = $_GET['date_to'] ?? $date;
if ($dateTo < $date) $dateTo = $date;

if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    if ($reportType === 'daily') {
        $report = Report::getDailyReport($date, $dateTo);
        ExcelExport::exportDailyReport($report);
    } elseif ($reportType === 'expiring') {
        $data = Stock::getExpiringSoon(365);
        ExcelExport::exportExpiringItems($data);
    } elseif ($reportType === 'stock') {
        $data = Stock::getAll();
        ExcelExport::exportStock($data);
    }
}

$REPORT_LABELS = [
    'daily'    => 'Activity Report',
    'stock'    => 'Stock Summary',
    'expiring' => 'Expiring Items',
];

require_once __DIR__ . '/includes/header.php';
?>

<div class="space-y-5">
  <div class="wms-banner" style="background:linear-gradient(135deg,#012e2d 0%,#026766 100%)">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px">
      <div>
        <h1><i class="fas fa-chart-bar mr-2"></i>Reports</h1>
        <p style="opacity:.75;font-size:.875rem;margin-top:2px">Laporan operasional warehouse</p>
      </div>
      <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
        <a href="print_report.php?<?= http_build_query(['type' => $reportType, 'date' => $date]) ?>" target="_blank"
           class="wms-btn" style="background:#fff;color:#013d3c;font-weight:700">
          <i class="fas fa-print"></i> Print / PDF
        </a>
      </div>
    </div>
    <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-top:16px">
      <div class="stat-pill"><div class="num"><?= date('d') ?></div><div class="lbl">Today <?= date('M Y') ?></div></div>
      <div class="stat-pill"><div class="num"><?= $REPORT_LABELS[$reportType] ?? strtoupper($reportType) ?></div><div class="lbl">Report Type</div></div>
      <div class="stat-pill"><div class="num"><?= date('d M', strtotime($date)) ?></div><div class="lbl">Selected Date</div></div>
      <div class="stat-pill"><div class="num">3</div><div class="lbl">Report Types</div></div>
    </div>
  </div>

  <!-- Filter -->
  <div style="background:#fff;border-radius:12px;box-shadow:0 1px 8px rgba(0,0,0,.08);padding:20px 24px">
    <form method="GET" action="reports.php" id="rptForm" style="display:flex;flex-wrap:wrap;gap:14px;align-items:flex-end">
      <div>
        <label class="wms-label">Report Type</label>
        <select name="type" onchange="this.form.submit()" class="wms-input" style="min-width:180px">
          <option value="daily"    <?= $reportType==='daily'    ? 'selected' : '' ?>>Activity Report</option>
          <option value="stock"    <?= $reportType==='stock'    ? 'selected' : '' ?>>Stock Summary</option>
          <option value="expiring" <?= $reportType==='expiring' ? 'selected' : '' ?>>Expiring Items</option>
        </select>
      </div>
      <?php if ($reportType === 'daily'): ?>
      <div>
        <label class="wms-label">Date From</label>
        <input type="date" name="date" id="rptFrom" value="<?= htmlspecialchars($date) ?>"
               class="wms-input" onchange="onFromChange(this)">
      </div>
      <div>
        <label class="wms-label">Date To</label>
        <input type="date" name="date_to" id="rptTo" value="<?= htmlspecialchars($dateTo) ?>"
               min="<?= htmlspecialchars($date) ?>" class="wms-input" onchange="onToChange(this)">
      </div>
      <div>
        <button type="submit" class="wms-btn wms-btn-primary"><i class="fas fa-filter mr-1"></i> Apply</button>
      </div>
      <!-- Quick date chips -->
      <div style="display:flex;gap:6px;align-items:center;padding-bottom:2px">
        <?php
        $today    = date('Y-m-d');
        $chips = [
          'Today'      => [$today, $today],
          'This Week'  => [date('Y-m-d', strtotime('monday this week')), $today],
          'This Month' => [date('Y-m-01'), $today],
          'Last Month' => [date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('last day of last month'))],
        ];
        foreach ($chips as $label => [$f, $t]):
          $active = ($date === $f && $dateTo === $t);
        ?>
        <a href="reports.php?type=daily&date=<?= $f ?>&date_to=<?= $t ?>"
           style="padding:5px 12px;border-radius:99px;font-size:.75rem;font-weight:600;text-decoration:none;
                  background:<?= $active ? '#013d3c' : '#f3f4f6' ?>;
                  color:<?= $active ? '#fff' : '#374151' ?>;
                  border:1px solid <?= $active ? '#013d3c' : '#e5e7eb' ?>">
          <?= $label ?>
        </a>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </form>
  </div>

  <script>
  function onFromChange(from) {
    const to = document.getElementById('rptTo');
    if (!to) return;
    to.min = from.value;
    if (to.value < from.value) to.value = from.value;
  }
  function onToChange(to) {
    const from = document.getElementById('rptFrom');
    if (!from) return;
    if (to.value < from.value) { from.value = to.value; }
  }
  </script>

  <?php
  // ─── UOM badge helper ───────────────────────────────────────────────────────
  $UOM_STYLES = [
    'Drum'   => ['bg'=>'#e0f7f7','color'=>'#014f4e'],
    'Carton' => ['bg'=>'#dcfce7','color'=>'#166534'],
    'Pail'   => ['bg'=>'#fef9c3','color'=>'#854d0e'],
    'EA'     => ['bg'=>'#ede9fe','color'=>'#6d28d9'],
    'Bags'   => ['bg'=>'#ffedd5','color'=>'#9a3412'],
  ];
  function uomBadge(string $uom, array $styles): string {
    $s = $styles[$uom] ?? ['bg'=>'#f3f4f6','color'=>'#374151'];
    return "<span style=\"background:{$s['bg']};color:{$s['color']};padding:2px 8px;border-radius:12px;font-size:.72rem;font-weight:700\">{$uom}</span>";
  }

  // ─── STATUS badge helper ─────────────────────────────────────────────────────
  $STATUS_STYLES = [
    'Completed'  => ['bg'=>'#dcfce7','color'=>'#166534'],
    'Shipped'    => ['bg'=>'#dcfce7','color'=>'#166534'],
    'Receiving'  => ['bg'=>'#e0f2fe','color'=>'#0369a1'],
    'Dues In'    => ['bg'=>'#e0f7f7','color'=>'#014f4e'],
    'Draft'      => ['bg'=>'#f3f4f6','color'=>'#374151'],
    'Open'       => ['bg'=>'#fef9c3','color'=>'#854d0e'],
    'Picking'    => ['bg'=>'#ffedd5','color'=>'#9a3412'],
    'Cancelled'  => ['bg'=>'#fee2e2','color'=>'#991b1b'],
  ];
  function statusBadge(string $status, array $styles): string {
    $s = $styles[$status] ?? ['bg'=>'#f3f4f6','color'=>'#374151'];
    return "<span style=\"background:{$s['bg']};color:{$s['color']};padding:3px 10px;border-radius:99px;font-size:.72rem;font-weight:700\">{$status}</span>";
  }
  ?>

  <?php if ($reportType === 'daily'): ?>
  <?php $report = Report::getDailyReport($date, $dateTo); ?>

  <!-- Transaction Summary KPI -->
  <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px">
    <div style="background:#dcfce7;border:1px solid #bbf7d0;border-radius:10px;padding:16px;text-align:center">
      <div style="font-size:1.6rem;font-weight:800;color:#166534"><?= number_format($report['ledger_summary']['qty_in'] ?? $report['ledger_summary']['drums_in'] ?? 0) ?></div>
      <div style="font-size:.78rem;color:#4b5563;margin-top:2px">Qty Masuk</div>
    </div>
    <div style="background:#fee2e2;border:1px solid #fecaca;border-radius:10px;padding:16px;text-align:center">
      <div style="font-size:1.6rem;font-weight:800;color:#dc2626"><?= number_format($report['ledger_summary']['qty_out'] ?? $report['ledger_summary']['drums_out'] ?? 0) ?></div>
      <div style="font-size:.78rem;color:#4b5563;margin-top:2px">Qty Keluar</div>
    </div>
    <div style="background:#e0f7f7;border:1px solid #b2e5e5;border-radius:10px;padding:16px;text-align:center">
      <div style="font-size:1.6rem;font-weight:800;color:#026766"><?= number_format($report['ledger_summary']['transactions_in'] ?? 0) ?></div>
      <div style="font-size:.78rem;color:#4b5563;margin-top:2px">Transaksi Masuk</div>
    </div>
    <div style="background:#f3f4f6;border:1px solid #e5e7eb;border-radius:10px;padding:16px;text-align:center">
      <div style="font-size:1.6rem;font-weight:800;color:#374151"><?= number_format($report['ledger_summary']['transactions_out'] ?? 0) ?></div>
      <div style="font-size:.78rem;color:#4b5563;margin-top:2px">Transaksi Keluar</div>
    </div>
  </div>

  <!-- Activity Report Card -->
  <div style="background:#fff;border-radius:12px;box-shadow:0 1px 8px rgba(0,0,0,.08);padding:24px">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:10px">
      <h2 style="font-size:1.05rem;font-weight:700;color:#111827">
        <i class="fas fa-file-alt mr-2" style="color:#026766"></i>
        <?php if ($date === $dateTo): ?>
          Activity Report — <?= date('d F Y', strtotime($date)) ?>
        <?php else: ?>
          Activity Report — <?= date('d M Y', strtotime($date)) ?> s/d <?= date('d M Y', strtotime($dateTo)) ?>
        <?php endif; ?>
      </h2>
      <div style="display:flex;gap:8px">
        <a href="<?= BASE_URL ?>/reports.php?<?= http_build_query(['type'=>'daily','date'=>$date,'date_to'=>$dateTo,'export'=>'excel']) ?>"
           style="background:#166534;color:#fff;padding:8px 16px;border-radius:8px;font-size:.82rem;font-weight:600;text-decoration:none;display:inline-flex;align-items:center;gap:6px">
          <i class="fas fa-file-excel"></i> Export Excel
        </a>
        <a href="print_report.php?<?= http_build_query(['type'=>'daily','date'=>$date,'date_to'=>$dateTo]) ?>" target="_blank"
           style="background:#013d3c;color:#fff;padding:8px 16px;border-radius:8px;font-size:.82rem;font-weight:600;text-decoration:none;display:inline-flex;align-items:center;gap:6px">
          <i class="fas fa-print"></i> Print / PDF
        </a>
      </div>
    </div>

    <!-- Stock Summary -->
    <div style="margin-bottom:24px">
      <div class="wms-card-header" style="padding:10px 0;border-bottom:2px solid #e5e7eb;margin-bottom:12px;background:none;border-radius:0">
        <i class="fas fa-boxes mr-2" style="color:#026766"></i> Current Stock Position
        <span style="font-size:.72rem;font-weight:400;color:#9ca3af;margin-left:8px">posisi stok terkini per <?= date('d M Y') ?></span>
      </div>
      <div style="overflow-x:auto">
        <table class="wms-table">
          <thead><tr>
            <th>Product Code</th><th>Product Name</th>
            <th class="tc">Batches</th><th class="tc">UOM</th>
            <th class="tr">Qty</th><th class="tr">Pallets</th><th>Nearest Expiry</th>
          </tr></thead>
          <tbody>
            <?php foreach ($report['stock_summary'] as $item): ?>
            <tr>
              <td style="font-family:monospace;font-weight:600"><?= htmlspecialchars($item['product_code']) ?></td>
              <td style="font-weight:600"><?= htmlspecialchars($item['product_name']) ?></td>
              <td class="tc"><?= $item['batches'] ?></td>
              <td class="tc"><?= uomBadge($item['uom_type'] ?? 'Drum', $UOM_STYLES) ?></td>
              <td class="tr" style="font-weight:700"><?= number_format($item['total_drums'] ?? $item['total_qty'] ?? 0) ?></td>
              <td class="tr"><?= number_format((int)ceil($item['total_pallets']),0) ?></td>
              <td><?= $item['nearest_expiry'] ? date('d M Y', strtotime($item['nearest_expiry'])) : '—' ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($report['stock_summary'])): ?>
            <tr><td colspan="7" style="text-align:center;padding:20px;color:#9ca3af">Tidak ada data stok</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Inbound Activity -->
    <div style="margin-bottom:24px">
      <div class="wms-card-header" style="padding:10px 0;border-bottom:2px solid #e5e7eb;margin-bottom:12px;background:none;border-radius:0">
        <i class="fas fa-arrow-circle-down mr-2" style="color:#16a34a"></i> Inbound Activity
      </div>
      <div style="overflow-x:auto">
        <?php if (!empty($report['inbound_activity'])): ?>
        <table class="wms-table">
          <thead><tr>
            <th>Inbound #</th><th>Shipment No</th><th class="tc">Status</th>
            <th class="tr">Lines</th><th class="tr">Total Qty</th>
          </tr></thead>
          <tbody>
            <?php foreach ($report['inbound_activity'] as $item): ?>
            <tr>
              <td style="font-family:monospace;font-weight:600"><a href="inbound.php?id=<?= $item['id'] ?? '' ?>" style="color:#026766;text-decoration:none"><?= htmlspecialchars($item['order_number'] ?? '—') ?></a></td>
              <td style="font-family:monospace"><?= htmlspecialchars($item['shipment_no'] ?? '—') ?></td>
              <td class="tc"><?= statusBadge($item['status'], $STATUS_STYLES) ?></td>
              <td class="tr"><?= $item['item_count'] ?></td>
              <td class="tr" style="font-weight:600"><?= number_format($item['total_drums'] ?? 0) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php else: ?>
        <p style="color:#9ca3af;font-size:.85rem;padding:12px 0"><i class="fas fa-info-circle mr-1"></i>Tidak ada inbound activity pada periode ini.</p>
        <?php endif; ?>
      </div>
    </div>

    <!-- Outbound Activity -->
    <div style="margin-bottom:24px">
      <div class="wms-card-header" style="padding:10px 0;border-bottom:2px solid #e5e7eb;margin-bottom:12px;background:none;border-radius:0">
        <i class="fas fa-arrow-circle-up mr-2" style="color:#dc2626"></i> Outbound Activity
      </div>
      <div style="overflow-x:auto">
        <?php if (!empty($report['outbound_activity'])): ?>
        <table class="wms-table">
          <thead><tr>
            <th>Outbound #</th><th>Shipment No</th><th class="tc">Status</th>
            <th class="tr">Lines</th><th class="tr">Total Qty</th>
          </tr></thead>
          <tbody>
            <?php foreach ($report['outbound_activity'] as $item): ?>
            <tr>
              <td style="font-family:monospace;font-weight:600"><a href="outbound.php?id=<?= $item['id'] ?? '' ?>" style="color:#026766;text-decoration:none"><?= htmlspecialchars($item['order_number'] ?? '—') ?></a></td>
              <td style="font-family:monospace"><?= htmlspecialchars($item['shipment_number'] ?? $item['shipment_no'] ?? '—') ?></td>
              <td class="tc"><?= statusBadge($item['status'], $STATUS_STYLES) ?></td>
              <td class="tr"><?= $item['item_count'] ?></td>
              <td class="tr" style="font-weight:600"><?= number_format($item['total_drums'] ?? 0) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php else: ?>
        <p style="color:#9ca3af;font-size:.85rem;padding:12px 0"><i class="fas fa-info-circle mr-1"></i>Tidak ada outbound activity pada periode ini.</p>
        <?php endif; ?>
      </div>
    </div>

    <!-- Expiring Items (if any in range) -->
    <?php if (!empty($report['expiring_items'])): ?>
    <div>
      <div class="wms-card-header" style="padding:10px 0;border-bottom:2px solid #fecaca;margin-bottom:12px;background:none;border-radius:0;color:#991b1b">
        <i class="fas fa-exclamation-triangle mr-2"></i> Expiring Items (90 Hari)
      </div>
      <div style="overflow-x:auto">
        <table class="wms-table">
          <thead><tr>
            <th>Product</th><th>Batch</th><th>Expiry Date</th>
            <th class="tr">Days Left</th><th class="tr">Qty</th>
          </tr></thead>
          <tbody>
            <?php foreach ($report['expiring_items'] as $item):
              $dLeft = $item['days_until_expiry'];
              $dColor = $dLeft < 30 ? '#dc2626' : ($dLeft < 60 ? '#d97706' : '#374151');
            ?>
            <tr>
              <td style="font-weight:600"><?= htmlspecialchars($item['product_code'] . ' — ' . $item['product_name']) ?></td>
              <td style="font-family:monospace"><?= htmlspecialchars($item['batch_number']) ?></td>
              <td><?= date('d M Y', strtotime($item['expiry_date'])) ?></td>
              <td class="tr" style="font-weight:700;color:<?= $dColor ?>"><?= $dLeft ?> hari</td>
              <td class="tr"><?= number_format($item['quantity'] ?? $item['total_qty'] ?? 0) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <?php elseif ($reportType === 'stock'): ?>
  <?php $stock = Stock::getAll(); ?>

  <div style="background:#fff;border-radius:12px;box-shadow:0 1px 8px rgba(0,0,0,.08);padding:24px">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:10px">
      <h2 style="font-size:1.05rem;font-weight:700;color:#111827">
        <i class="fas fa-boxes mr-2" style="color:#026766"></i>Stock Summary Report
      </h2>
      <div style="display:flex;gap:8px">
        <a href="<?= BASE_URL ?>/reports.php?type=stock&export=excel"
           style="background:#166534;color:#fff;padding:8px 16px;border-radius:8px;font-size:.82rem;font-weight:600;text-decoration:none;display:inline-flex;align-items:center;gap:6px">
          <i class="fas fa-file-excel"></i> Export Excel
        </a>
        <a href="print_report.php?type=stock" target="_blank"
           style="background:#013d3c;color:#fff;padding:8px 16px;border-radius:8px;font-size:.82rem;font-weight:600;text-decoration:none;display:inline-flex;align-items:center;gap:6px">
          <i class="fas fa-print"></i> Print / PDF
        </a>
      </div>
    </div>
    <div style="overflow-x:auto">
      <table class="wms-table">
        <thead><tr>
          <th>Product</th><th>Batch</th><th class="tc">UOM</th>
          <th class="tr">Qty</th><th class="tr">Pallets</th>
          <th>Expiry</th><th>Location</th><th class="tc">Status</th>
        </tr></thead>
        <tbody>
          <?php foreach ($stock as $item):
            $uom = $item['uom_type'] ?? $item['uom'] ?? 'Drum';
          ?>
          <tr>
            <td style="font-weight:600"><?= htmlspecialchars($item['product_code'] . ' — ' . $item['product_name']) ?></td>
            <td style="font-family:monospace"><?= htmlspecialchars($item['batch_number'] ?? '—') ?></td>
            <td class="tc"><?= uomBadge($uom, $UOM_STYLES) ?></td>
            <td class="tr" style="font-weight:700"><?= number_format($item['quantity'] ?? $item['total_qty'] ?? 0) ?></td>
            <td class="tr"><?= number_format((int)ceil($item['pallet'] ?? 0),0) ?></td>
            <td><?= $item['expiry_date'] ? date('d M Y', strtotime($item['expiry_date'])) : '—' ?></td>
            <td><?= htmlspecialchars($item['location'] ?? '—') ?></td>
            <td class="tc"><?= statusBadge($item['stock_status'] ?? '—', $STATUS_STYLES) ?></td>
          </tr>
          <?php endforeach; ?>
          <?php if (empty($stock)): ?>
          <tr><td colspan="8" style="text-align:center;padding:20px;color:#9ca3af">Tidak ada data stok</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <?php elseif ($reportType === 'expiring'): ?>
  <?php $expiring = Stock::getExpiringSoon(365); ?>

  <div style="background:#fff;border-radius:12px;box-shadow:0 1px 8px rgba(0,0,0,.08);padding:24px">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:10px">
      <h2 style="font-size:1.05rem;font-weight:700;color:#111827">
        <i class="fas fa-calendar-times mr-2" style="color:#dc2626"></i>Expiring Items — Next 365 Days
      </h2>
      <div style="display:flex;gap:8px">
        <a href="<?= BASE_URL ?>/reports.php?type=expiring&export=excel"
           style="background:#166534;color:#fff;padding:8px 16px;border-radius:8px;font-size:.82rem;font-weight:600;text-decoration:none;display:inline-flex;align-items:center;gap:6px">
          <i class="fas fa-file-excel"></i> Export Excel
        </a>
        <a href="print_report.php?type=expiring" target="_blank"
           style="background:#013d3c;color:#fff;padding:8px 16px;border-radius:8px;font-size:.82rem;font-weight:600;text-decoration:none;display:inline-flex;align-items:center;gap:6px">
          <i class="fas fa-print"></i> Print / PDF
        </a>
      </div>
    </div>
    <div style="overflow-x:auto">
      <table class="wms-table">
        <thead><tr>
          <th>Product</th><th>Batch</th><th>Expiry Date</th>
          <th class="tr">Days Left</th><th class="tr">Qty</th><th>Location</th>
        </tr></thead>
        <tbody>
          <?php foreach ($expiring as $item):
            $dLeft  = $item['days_until_expiry'];
            $dColor = $dLeft < 30 ? '#dc2626' : ($dLeft < 60 ? '#d97706' : '#374151');
            $dBg    = $dLeft < 30 ? '#fee2e2'  : ($dLeft < 60 ? '#fef9c3' : 'transparent');
          ?>
          <tr>
            <td style="font-weight:600"><?= htmlspecialchars($item['product_code'] . ' — ' . $item['product_name']) ?></td>
            <td style="font-family:monospace"><?= htmlspecialchars($item['batch_number']) ?></td>
            <td><?= date('d M Y', strtotime($item['expiry_date'])) ?></td>
            <td class="tr">
              <span style="background:<?= $dBg ?>;color:<?= $dColor ?>;padding:2px 8px;border-radius:6px;font-weight:700;font-size:.8rem">
                <?= $dLeft ?> hari
              </span>
            </td>
            <td class="tr" style="font-weight:600"><?= number_format($item['quantity'] ?? $item['total_qty'] ?? 0) ?></td>
            <td><?= htmlspecialchars($item['location'] ?? '—') ?></td>
          </tr>
          <?php endforeach; ?>
          <?php if (empty($expiring)): ?>
          <tr><td colspan="6" style="text-align:center;padding:20px;color:#9ca3af"><i class="fas fa-check-circle mr-2" style="color:#16a34a"></i>Tidak ada item yang akan expired dalam 365 hari</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

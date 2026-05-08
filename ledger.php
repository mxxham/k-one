<?php
session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/classes/Auth.php';
require_once __DIR__ . '/classes/Stock.php';
require_once __DIR__ . '/classes/Product.php';
require_once __DIR__ . '/classes/Inbound.php';

Auth::requireAuth();
$canWrite = Auth::canWrite();
$canAdmin = Auth::canAdmin();

// ── Repair All Inbound Ledger ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['repair_all_ledger'])) {
    if ($canAdmin) {
        $db = db();
        $orders = $db->query("SELECT id FROM inbound_orders WHERE status != 'Cancelled'")->fetchAll(PDO::FETCH_COLUMN);
        $fixed = 0;
        foreach ($orders as $oid) {
            try { Inbound::regenerateLedger((int)$oid); $fixed++; } catch (Exception $e) { /* skip */ }
        }
        header('Location: ledger.php?bucket=available&repair_ok=' . $fixed);
        exit;
    }
}

$pageTitle   = 'Stock Ledger';
$currentPage = 'ledger';

// Transaction type metadata
$TX_META = [
    'IN'           => ['label' => 'Penerimaan',    'icon' => 'fa-arrow-circle-down', 'color' => '#16a34a', 'bg' => '#dcfce7', 'border' => '#86efac'],
    'OUT'          => ['label' => 'Pengiriman',     'icon' => 'fa-arrow-circle-up',   'color' => '#dc2626', 'bg' => '#fee2e2', 'border' => '#fca5a5'],
    'ADJUSTMENT'   => ['label' => 'Penyesuaian',   'icon' => 'fa-balance-scale',     'color' => '#d97706', 'bg' => '#fef3c7', 'border' => '#fcd34d'],
    'TRANSFER_IN'  => ['label' => 'Pindah Masuk',  'icon' => 'fa-arrow-right',       'color' => '#0891b2', 'bg' => '#e0f2fe', 'border' => '#7dd3fc'],
    'TRANSFER_OUT' => ['label' => 'Pindah Keluar', 'icon' => 'fa-arrow-left',        'color' => '#7c3aed', 'bg' => '#ede9fe', 'border' => '#c4b5fd'],
];

$viewMode  = $_GET['view']  ?? 'timeline'; // timeline | document
$bucket    = $_GET['bucket'] ?? 'available'; // available | incoming | quarantine | shipped | adjustment

// Ledger always shows all dates to keep UI simple.
$startDate = '2000-01-01';
$endDate   = '2099-12-31';

$productId  = $_GET['product_id']  ?? null;
$txType     = $_GET['tx_type']     ?? null;
$searchOdNo = $_GET['od_no']       ?? null;
$searchShip = $_GET['shipment_no'] ?? null;
$searchRef  = $_GET['ref_no']      ?? null;

$bucketExpr = "CASE
    WHEN UPPER(TRIM(COALESCE(sl.reference_type,''))) = 'ADJUSTMENT'
         OR sl.transaction_type = 'ADJUSTMENT'
         OR sl.reference_number LIKE 'ADJ-%' THEN 'adjustment'
    WHEN sl.reference_type = 'BinTransfer' OR sl.transaction_type IN ('TRANSFER_IN','TRANSFER_OUT','TRANSFER') THEN 'transfer'
    WHEN sl.reference_type = 'Outbound' AND sl.transaction_type = 'OUT' THEN 'shipped'
    WHEN sl.reference_type = 'Inbound' AND (sl.location = 'QUA_SHELL' OR sl.notes LIKE '%Unserviceable%') THEN 'quarantine'
    WHEN sl.reference_type = 'Inbound' AND sl.transaction_type = 'IN' THEN 'available'
    ELSE 'other'
END";

$allowedBuckets = ['available', 'quarantine', 'shipped', 'transfer', 'adjustment'];
if (!in_array($bucket, $allowedBuckets, true)) $bucket = 'available';
if ($bucket === 'adjustment' && $txType === 'ADJUSTMENT') $txType = null;

$whereClauseBase = "WHERE sl.transaction_date >= ? AND sl.transaction_date <= ?";
$whereParamsBase = [$startDate, $endDate];

if ($productId)  { $whereClauseBase .= " AND sl.product_id = ?";           $whereParamsBase[] = $productId; }
if ($txType)     { $whereClauseBase .= " AND sl.transaction_type = ?";     $whereParamsBase[] = $txType; }
if ($searchOdNo) {
    $whereClauseBase .= " AND (COALESCE(
            (SELECT oi2.od_number FROM outbound_items oi2 WHERE oi2.outbound_order_id = sl.reference_id
             AND oi2.product_id = sl.product_id AND sl.reference_type = 'Outbound' ORDER BY oi2.id LIMIT 1),
            (SELECT ii3.od_number FROM inbound_items ii3 WHERE ii3.inbound_order_id = sl.reference_id
             AND ii3.product_id = sl.product_id AND sl.reference_type = 'Inbound' ORDER BY ii3.id LIMIT 1)
        ) LIKE ?)";
    $whereParamsBase[] = "%$searchOdNo%";
}
if ($searchShip) { $whereClauseBase .= " AND (io.shipment_no LIKE ? OR oo.shipment_number LIKE ?)"; $whereParamsBase[] = "%$searchShip%"; $whereParamsBase[] = "%$searchShip%"; }
if ($searchRef)  { $whereClauseBase .= " AND sl.reference_number LIKE ?";  $whereParamsBase[] = "%$searchRef%"; }

$whereClause = $whereClauseBase . " AND ($bucketExpr = ?)";
$whereParams = array_merge($whereParamsBase, [$bucket]);

$baseFromNoBucket = "FROM stock_ledger sl
        JOIN products p ON sl.product_id = p.id
        LEFT JOIN inbound_orders io  ON io.id  = sl.reference_id AND sl.reference_type = 'Inbound'
        LEFT JOIN outbound_orders oo ON oo.id  = sl.reference_id AND sl.reference_type = 'Outbound'
        $whereClauseBase";

$baseFrom = "FROM stock_ledger sl
        JOIN products p ON sl.product_id = p.id
        LEFT JOIN inbound_orders io  ON io.id  = sl.reference_id AND sl.reference_type = 'Inbound'
        LEFT JOIN outbound_orders oo ON oo.id  = sl.reference_id AND sl.reference_type = 'Outbound'
        $whereClause";

$selectCols = "SELECT sl.*,
               p.product_code, p.product_name, p.uom_type,
               COALESCE(
                   (SELECT ii_in.od_number FROM inbound_items ii_in
                     WHERE ii_in.inbound_order_id = sl.reference_id
                       AND ii_in.product_id = sl.product_id
                       AND sl.reference_type = 'Inbound'
                     ORDER BY ii_in.id LIMIT 1),
                   (SELECT oi_out.od_number FROM outbound_items oi_out
                     WHERE oi_out.outbound_order_id = sl.reference_id
                       AND oi_out.product_id = sl.product_id
                       AND sl.reference_type = 'Outbound'
                     ORDER BY oi_out.id LIMIT 1),
                   (SELECT ii_bt.od_number FROM inbound_items ii_bt
                     WHERE ii_bt.product_id = sl.product_id
                       AND ii_bt.batch_number <=> sl.batch_number
                       AND sl.reference_type = 'BinTransfer'
                     ORDER BY ii_bt.id LIMIT 1)
               ) AS od_number,
               (SELECT ii_so.so_number FROM inbound_items ii_so
                 WHERE ii_so.inbound_order_id = sl.reference_id
                   AND ii_so.product_id = sl.product_id
                   AND sl.reference_type = 'Inbound'
                 ORDER BY ii_so.id LIMIT 1) AS so_number,
               io.shipment_no AS io_shipment_no,
               oo.shipment_number AS oo_shipment_no,
               (SELECT io_bt.shipment_no FROM inbound_orders io_bt
                 JOIN inbound_items ii_bt2 ON ii_bt2.inbound_order_id = io_bt.id
                 WHERE ii_bt2.product_id = sl.product_id
                   AND ii_bt2.batch_number <=> sl.batch_number
                   AND sl.reference_type = 'BinTransfer'
                 ORDER BY ii_bt2.id LIMIT 1) AS bt_shipment_no";

$products = Product::getAll();

// ── Export ─────────────────────────────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    $stmt = db()->prepare("$selectCols $baseFrom GROUP BY sl.id ORDER BY sl.transaction_date ASC, sl.created_at ASC");
    $stmt->execute($whereParams);
    $movements = $stmt->fetchAll();
    require_once __DIR__ . '/vendor/autoload.php';
    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Stock Ledger');
    $headers = ['No','Tanggal','Kode Produk','Nama Produk','Tipe','Label','Ref No','Ref Type',
                'OD No','Shipment No','Batch','UOM','Qty Masuk','Qty Keluar','Pallet','Balance','Lokasi','Keterangan'];
    foreach ($headers as $c => $h) {
        $cell = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c+1).'1';
        $sheet->setCellValue($cell, $h);
        $sheet->getStyle($cell)->getFont()->setBold(true);
        $sheet->getStyle($cell)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FF013d3c');
        $sheet->getStyle($cell)->getFont()->getColor()->setARGB('FFFFFFFF');
    }
    $row = 2;
    foreach ($movements as $i => $m) {
        $txMeta  = $TX_META[$m['transaction_type']] ?? ['label' => $m['transaction_type']];
        $shipNo  = $m['io_shipment_no'] ?? $m['oo_shipment_no'] ?? $m['bt_shipment_no'] ?? '';
        $rowData = [$i+1, $m['transaction_date'], $m['product_code'], $m['product_name'],
                    $m['transaction_type'], $txMeta['label'], $m['reference_number']??'', $m['reference_type']??'',
                    $m['od_number']??'', $shipNo, $m['batch_number']??'', $m['uom']??'',
                    $m['quantity_in']??0, $m['quantity_out']??0, $m['pallet']??0, $m['balance']??0,
                    $m['location']??'', $m['notes']??''];
        foreach ($rowData as $c => $val) {
            $cell = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c+1) . $row;
            $sheet->setCellValue($cell, $val);
        }
        $bgColor = $m['transaction_type']==='IN' ? 'FFE8F5E9' : ($m['transaction_type']==='OUT' ? 'FFFCE4EC' : 'FFFFF9C4');
        $sheet->getStyle("A{$row}:R{$row}")->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB($bgColor);
        $row++;
    }
    foreach (range('A','R') as $col) $sheet->getColumnDimension($col)->setAutoSize(true);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="Ledger_'.date('Ymd_His').'.xlsx"');
    header('Cache-Control: max-age=0');
    (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save('php://output');
    exit;
}

// ── Aggregates ─────────────────────────────────────────────────────────────
$_aggStmt = db()->prepare("SELECT
    COUNT(DISTINCT sl.id) as total_tx,
    SUM(CASE WHEN sl.transaction_type='IN' THEN sl.quantity_in ELSE 0 END) as total_in,
    SUM(CASE WHEN sl.transaction_type='OUT' THEN sl.quantity_out ELSE 0 END) as total_out,
    SUM(CASE WHEN (UPPER(TRIM(COALESCE(sl.reference_type,''))) = 'ADJUSTMENT' OR sl.transaction_type='ADJUSTMENT' OR sl.reference_number LIKE 'ADJ-%') AND sl.quantity_in > 0 THEN sl.quantity_in ELSE 0 END) -
    SUM(CASE WHEN (UPPER(TRIM(COALESCE(sl.reference_type,''))) = 'ADJUSTMENT' OR sl.transaction_type='ADJUSTMENT' OR sl.reference_number LIKE 'ADJ-%') AND sl.quantity_out > 0 THEN sl.quantity_out ELSE 0 END) as net_adj,
    SUM(CASE WHEN sl.transaction_type='TRANSFER_OUT' THEN sl.quantity_out ELSE 0 END) as total_transfer
    $baseFrom");
$_aggStmt->execute($whereParams);
$_agg = $_aggStmt->fetch();

$_bucketStmt = db()->prepare("SELECT
    SUM(CASE WHEN $bucketExpr = 'available' THEN 1 ELSE 0 END) AS cnt_available,
    SUM(CASE WHEN $bucketExpr = 'quarantine' THEN 1 ELSE 0 END) AS cnt_quarantine,
    SUM(CASE WHEN $bucketExpr = 'shipped' THEN 1 ELSE 0 END) AS cnt_shipped,
    SUM(CASE WHEN $bucketExpr = 'transfer' THEN 1 ELSE 0 END) AS cnt_transfer,
    SUM(CASE WHEN $bucketExpr = 'adjustment' THEN 1 ELSE 0 END) AS cnt_adjustment
    $baseFromNoBucket");
$_bucketStmt->execute($whereParamsBase);
$_bucketCounts = $_bucketStmt->fetch();

// Opening balance for single-product chronological view
$openingBalance = null;
if ($productId && $viewMode === 'timeline') {
    $_obStmt = db()->prepare("SELECT balance FROM stock_ledger
        WHERE product_id = ?
          AND transaction_date < ?
          AND transaction_type NOT IN ('TRANSFER_IN','TRANSFER_OUT')
        ORDER BY transaction_date DESC, created_at DESC LIMIT 1");
    $_obStmt->execute([$productId, $startDate]);
    $openingBalance = $_obStmt->fetchColumn();
    $openingBalance = ($openingBalance !== false) ? (float)$openingBalance : 0.0;
}

// ── Timeline view ──────────────────────────────────────────────────────────
$_ldPerPage = 100;
$_ldPage    = max(1, (int)($_GET['page'] ?? 1));

$_cntStmt = db()->prepare("SELECT COUNT(DISTINCT sl.id) $baseFrom");
$_cntStmt->execute($whereParams);
$_ldTotal      = (int)$_cntStmt->fetchColumn();
$_ldTotalPages = max(1, (int)ceil($_ldTotal / $_ldPerPage));
$_ldPage       = min($_ldPage, $_ldTotalPages);
$_ldOffset     = ($_ldPage - 1) * $_ldPerPage;

// Total entries in ledger (no date filter) for diagnostic banner
$_totalAllTime = (int)db()->query("SELECT COUNT(*) FROM stock_ledger")->fetchColumn();

// Single product → ASC (show journey), multi-product → DESC (latest first)
$_sortDir   = ($productId && $viewMode === 'timeline') ? 'ASC' : 'DESC';
$_dataStmt  = db()->prepare("$selectCols $baseFrom GROUP BY sl.id ORDER BY sl.transaction_date $_sortDir, sl.created_at $_sortDir LIMIT $_ldPerPage OFFSET $_ldOffset");
$_dataStmt->execute($whereParams);
$movements = $_dataStmt->fetchAll();

// ── Document view ──────────────────────────────────────────────────────────
$docGroups   = [];
$docDetails  = [];
if ($viewMode === 'document') {
    $_docStmt = db()->prepare("SELECT
        sl.reference_id,
        sl.reference_number,
        sl.reference_type,
        MIN(sl.transaction_date) AS tx_date,
        COUNT(DISTINCT sl.id) AS row_count,
        COUNT(DISTINCT sl.product_id) AS product_count,
        SUM(sl.quantity_in) AS total_in,
        SUM(sl.quantity_out) AS total_out,
        MAX(io.shipment_no) AS shipment_no,
        MAX(oo.shipment_number) AS oo_shipment_no
        $baseFrom
        GROUP BY sl.reference_id, sl.reference_number, sl.reference_type
        ORDER BY tx_date DESC, sl.reference_id DESC");
    $_docStmt->execute($whereParams);
    $docGroups = $_docStmt->fetchAll();

    // Load detail rows for all doc groups
    $_detStmt = db()->prepare("$selectCols $baseFrom GROUP BY sl.id ORDER BY sl.transaction_date ASC, sl.created_at ASC");
    $_detStmt->execute($whereParams);
    foreach ($_detStmt->fetchAll() as $r) {
        $dk = ($r['reference_id'] ?? 'null') . '|' . ($r['reference_type'] ?? '');
        $docDetails[$dk][] = $r;
    }
}

require_once __DIR__ . '/includes/header.php';

// Build base URL preserving filters (strip page, export, view)
$_baseGet = array_diff_key($_GET, array_flip(['page','export','view']));
$_tabBaseGet = array_diff_key($_baseGet, ['tx_type' => true]);
?>
<style>
.ld-card { background:#fff; border-radius:12px; box-shadow:0 1px 8px rgba(2,103,102,.07); padding:20px 22px; }
.ld-input, .ld-select {
  width:100%; padding:7px 10px; border:1.5px solid #b2e5e5; border-radius:7px;
  font-size:.82rem; color:#013d3c; background:#fff; outline:none; box-sizing:border-box;
}
.ld-input:focus, .ld-select:focus { border-color:#026766; }
.ld-label { display:block; font-size:.72rem; font-weight:700; color:#013d3c; margin-bottom:4px; text-transform:uppercase; letter-spacing:.04em; }
.ld-btn { display:inline-flex; align-items:center; gap:6px; padding:7px 14px; border-radius:7px; font-size:.82rem; font-weight:600; cursor:pointer; border:none; text-decoration:none; }
.ld-table { width:100%; border-collapse:separate; border-spacing:0; font-size:.81rem; }
.ld-table thead tr { background:linear-gradient(90deg,#013d3c,#026766); color:#fff; }
.ld-table th { padding:10px 12px; font-weight:600; font-size:.76rem; letter-spacing:.03em; white-space:nowrap; }
.ld-table td { padding:8px 12px; border-bottom:1px solid #e6f7f7; vertical-align:middle; }
.ld-table tbody tr:hover td { background:#f0fbfb; }
.ld-chip {
  display:inline-flex; align-items:center; gap:5px; padding:4px 12px;
  border-radius:20px; font-size:.76rem; font-weight:600; cursor:pointer;
  border:1.5px solid transparent; text-decoration:none; transition:all .12s;
}
.ld-chip-outline { border-color:#b2e5e5; color:#026766; background:#fff; }
.ld-chip-outline:hover, .ld-chip-outline.active { background:#026766; color:#fff; border-color:#026766; }
.ld-view-btn {
  padding:6px 14px; border-radius:7px; font-size:.8rem; font-weight:600;
  cursor:pointer; border:none; background:#e6f7f7; color:#026766; text-decoration:none;
  display:inline-flex; align-items:center; gap:5px; transition:background .12s;
}
.ld-view-btn.active { background:#026766; color:#fff; }
.tx-pill {
  display:inline-flex; align-items:center; gap:4px; padding:3px 9px;
  border-radius:20px; font-size:.71rem; font-weight:700; white-space:nowrap; border-width:1px; border-style:solid;
}
.balance-chip {
  display:inline-block; padding:3px 10px; border-radius:6px;
  font-weight:700; font-size:.82rem; font-family:monospace;
  background:#e6f7f7; color:#013d3c;
}
.doc-row-header { cursor:pointer; transition:background .12s; }
.doc-row-header:hover td { background:#f0fbfb; }
.doc-row-header.open td { background:#e6f7f7; }
.ld-tabs { display:flex; gap:0; border-bottom:2px solid #d9f3f2; flex-wrap:wrap; }
.ld-tab {
  border:none; border-bottom:2px solid transparent; margin-bottom:-2px;
  padding:10px 14px; font-size:.8rem; font-weight:700; color:#6b7280; text-decoration:none;
  display:inline-flex; align-items:center; gap:7px; transition:all .12s;
}
.ld-tab:hover { color:#026766; }
.ld-tab.active { color:#026766; border-bottom-color:#026766; }
.ld-tab-badge {
  background:#eef2f7; color:#374151; border-radius:999px; padding:1px 8px; font-size:.7rem; font-weight:700;
}
.ld-tab.active .ld-tab-badge { background:#026766; color:#fff; }
</style>

<div style="display:flex;flex-direction:column;gap:16px">

  <?php if (isset($_GET['repair_ok'])): ?>
  <div style="background:#dcfce7;border:1.5px solid #86efac;border-radius:10px;padding:11px 16px;display:flex;align-items:center;gap:10px">
    <i class="fas fa-check-circle" style="color:#16a34a"></i>
    <span style="font-size:.83rem;color:#166534;font-weight:600">
      Berhasil regenerasi ledger untuk <?= (int)$_GET['repair_ok'] ?> inbound order.
    </span>
  </div>
  <?php endif; ?>

  <!-- Hero -->
  <div style="background:linear-gradient(135deg,#013d3c 0%,#026766 100%);border-radius:14px;padding:26px 28px;color:#fff">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:10px">
      <h1 style="font-size:1.35rem;font-weight:700;margin:0"><i class="fas fa-book mr-2"></i>Stock Ledger</h1>
      <div style="display:flex;gap:8px;flex-wrap:wrap">
        <?php if ($canAdmin): ?>
        <form method="POST" onsubmit="return confirm('Regenerasi semua entri ledger dari inbound orders? Ini akan menghapus dan menulis ulang semua entri ledger inbound.')">
          <button type="submit" name="repair_all_ledger"
            style="background:rgba(255,200,0,.2);color:#fde68a;border:1px solid rgba(255,200,0,.4);border-radius:7px;padding:7px 14px;font-size:.8rem;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:6px">
            <i class="fas fa-tools"></i> Repair All Ledger
          </button>
        </form>
        <?php endif; ?>
        <a href="?<?= http_build_query(array_merge($_GET,['export'=>'excel'])) ?>"
           style="background:rgba(255,255,255,.15);color:#fff;border:1px solid rgba(255,255,255,.3);border-radius:7px;padding:7px 14px;font-size:.8rem;font-weight:600;text-decoration:none;display:inline-flex;align-items:center;gap:6px">
          <i class="fas fa-file-excel"></i> Export Excel
        </a>
      </div>
    </div>
    <div style="display:grid;grid-template-columns:repeat(5,1fr);gap:10px;margin-top:20px">
      <div style="background:rgba(255,255,255,.12);border-radius:10px;padding:13px 15px">
        <div style="font-size:1.4rem;font-weight:700"><?= number_format((int)$_agg['total_tx']) ?></div>
        <div style="font-size:.67rem;opacity:.7;margin-top:2px;text-transform:uppercase;letter-spacing:.05em">Transaksi</div>
      </div>
      <div style="background:rgba(255,255,255,.12);border-radius:10px;padding:13px 15px">
        <div style="font-size:1.4rem;font-weight:700;color:#86efac">+<?= number_format((float)$_agg['total_in'],0) ?></div>
        <div style="font-size:.67rem;opacity:.7;margin-top:2px;text-transform:uppercase;letter-spacing:.05em">Total Masuk</div>
      </div>
      <div style="background:rgba(255,255,255,.12);border-radius:10px;padding:13px 15px">
        <div style="font-size:1.4rem;font-weight:700;color:#fca5a5">-<?= number_format((float)$_agg['total_out'],0) ?></div>
        <div style="font-size:.67rem;opacity:.7;margin-top:2px;text-transform:uppercase;letter-spacing:.05em">Total Keluar</div>
      </div>
      <div style="background:rgba(255,255,255,.12);border-radius:10px;padding:13px 15px">
        <?php $netAdj = (float)$_agg['net_adj']; ?>
        <div style="font-size:1.4rem;font-weight:700;color:#fde68a"><?= ($netAdj >= 0 ? '+' : '') . number_format($netAdj, 0) ?></div>
        <div style="font-size:.67rem;opacity:.7;margin-top:2px;text-transform:uppercase;letter-spacing:.05em">Net Adjustment</div>
      </div>
      <div style="background:rgba(255,255,255,.12);border-radius:10px;padding:13px 15px">
        <div style="font-size:1.4rem;font-weight:700;color:#93c5fd"><?= number_format((float)$_agg['total_transfer'],0) ?></div>
        <div style="font-size:.67rem;opacity:.7;margin-top:2px;text-transform:uppercase;letter-spacing:.05em">Bin Transfer</div>
      </div>
    </div>
  </div>

  <!-- Filters -->
  <div class="ld-card">
    <form method="GET">
      <input type="hidden" name="view" value="<?= htmlspecialchars($viewMode) ?>">
      <input type="hidden" name="bucket" value="<?= htmlspecialchars($bucket) ?>">
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:10px;align-items:end">
        <div>
          <label class="ld-label">Produk</label>
          <select name="product_id" class="ld-select">
            <option value="">Semua</option>
            <?php foreach ($products as $pr): ?>
            <option value="<?= $pr['id'] ?>" <?= ($productId==$pr['id'])?'selected':'' ?>>
              <?= htmlspecialchars($pr['product_code'].' - '.$pr['product_name']) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="ld-label">Tipe</label>
          <select name="tx_type" class="ld-select">
            <option value="">Semua</option>
            <?php foreach ($TX_META as $tk => $tm): ?>
            <option value="<?= $tk ?>" <?= ($txType??'')===$tk?'selected':'' ?>><?= $tm['label'] ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="ld-label">OD No</label>
          <input type="text" name="od_no" value="<?= htmlspecialchars($searchOdNo??'') ?>" placeholder="Cari OD No..." class="ld-input" style="font-family:monospace">
        </div>
        <div>
          <label class="ld-label">Shipment No</label>
          <input type="text" name="shipment_no" value="<?= htmlspecialchars($searchShip??'') ?>" placeholder="Shipment..." class="ld-input">
        </div>
        <div>
          <label class="ld-label">Ref No</label>
          <input type="text" name="ref_no" value="<?= htmlspecialchars($searchRef??'') ?>" placeholder="No dokumen..." class="ld-input">
        </div>
        <div style="display:flex;gap:6px">
          <button type="submit" class="ld-btn" style="flex:1;background:#026766;color:#fff"><i class="fas fa-filter"></i> Filter</button>
          <a href="ledger.php?view=<?= $viewMode ?>&bucket=<?= urlencode($bucket) ?>" class="ld-btn" style="background:#e6f7f7;color:#026766"><i class="fas fa-times"></i></a>
        </div>
      </div>
    </form>
  </div>

  <!-- Table card -->
  <div class="ld-card" style="padding:0;overflow:hidden">
    <div style="padding:0 20px;background:#f9fdfd">
      <div class="ld-tabs" style="border-bottom:none">
        <a href="?<?= http_build_query(array_merge($_tabBaseGet, ['bucket'=>'available','view'=>$viewMode])) ?>"
           class="ld-tab <?= $bucket==='available'?'active':'' ?>">
          <i class="fas fa-check-circle" style="font-size:.75rem"></i> Available
          <span class="ld-tab-badge"><?= number_format((int)($_bucketCounts['cnt_available'] ?? 0)) ?></span>
        </a>
        <a href="?<?= http_build_query(array_merge($_tabBaseGet, ['bucket'=>'quarantine','view'=>$viewMode])) ?>"
           class="ld-tab <?= $bucket==='quarantine'?'active':'' ?>">
          <i class="fas fa-exclamation-triangle" style="font-size:.75rem"></i> Quarantine
          <span class="ld-tab-badge"><?= number_format((int)($_bucketCounts['cnt_quarantine'] ?? 0)) ?></span>
        </a>
        <a href="?<?= http_build_query(array_merge($_tabBaseGet, ['bucket'=>'shipped','view'=>$viewMode])) ?>"
           class="ld-tab <?= $bucket==='shipped'?'active':'' ?>">
          <i class="fas fa-shipping-fast" style="font-size:.75rem"></i> Shipped
          <span class="ld-tab-badge"><?= number_format((int)($_bucketCounts['cnt_shipped'] ?? 0)) ?></span>
        </a>
        <a href="?<?= http_build_query(array_merge($_tabBaseGet, ['bucket'=>'transfer','view'=>$viewMode])) ?>"
           class="ld-tab <?= $bucket==='transfer'?'active':'' ?>">
          <i class="fas fa-exchange-alt" style="font-size:.75rem"></i> Bin Transfer
          <span class="ld-tab-badge"><?= number_format((int)($_bucketCounts['cnt_transfer'] ?? 0)) ?></span>
        </a>
        <a href="?<?= http_build_query(array_merge($_tabBaseGet, ['bucket'=>'adjustment','view'=>$viewMode])) ?>"
           class="ld-tab <?= $bucket==='adjustment'?'active':'' ?>">
          <i class="fas fa-sliders-h" style="font-size:.75rem"></i> Adjustment
          <span class="ld-tab-badge"><?= number_format((int)($_bucketCounts['cnt_adjustment'] ?? 0)) ?></span>
        </a>
      </div>
    </div>

    <!-- Header bar with view toggle -->
    <div style="display:flex;justify-content:space-between;align-items:center;padding:14px 20px;border-bottom:1.5px solid #e6f7f7;flex-wrap:wrap;gap:8px">
      <div style="display:flex;align-items:center;gap:10px">
        <span style="font-size:.9rem;font-weight:700;color:#013d3c"><i class="fas fa-history mr-2" style="color:#026766"></i>
          <?php if ($productId): ?>
            <?php $selProd = array_filter($products, fn($p) => $p['id'] == $productId); $selProd = reset($selProd); ?>
            <?= $selProd ? htmlspecialchars($selProd['product_code'].' — '.$selProd['product_name']) : 'Produk' ?>
          <?php else: ?>Semua Produk<?php endif; ?>
        </span>
        <span style="background:#e6f7f7;color:#026766;border-radius:20px;padding:2px 10px;font-size:.74rem;font-weight:700">
          <?= $viewMode === 'document' ? count($docGroups).' dokumen' : number_format($_ldTotal).' transaksi' ?>
        </span>
        <?php if ($productId && $viewMode === 'timeline'): ?>
        <span style="font-size:.72rem;color:#6b7280;background:#f3f4f6;padding:2px 8px;border-radius:4px">
          <i class="fas fa-arrow-up mr-1"></i>oldest → newest
        </span>
        <?php endif; ?>
      </div>
      <div style="display:flex;gap:6px">
        <a href="?<?= http_build_query(array_merge($_baseGet, ['view'=>'timeline'])) ?>"
           class="ld-view-btn <?= $viewMode==='timeline'?'active':'' ?>">
          <i class="fas fa-stream"></i> Timeline
        </a>
        <a href="?<?= http_build_query(array_merge($_baseGet, ['view'=>'document'])) ?>"
           class="ld-view-btn <?= $viewMode==='document'?'active':'' ?>">
          <i class="fas fa-file-alt"></i> Per Dokumen
        </a>
      </div>
    </div>

    <?php if ($viewMode === 'document'): ?>
    <!-- ════════════ DOCUMENT VIEW ════════════ -->
    <div style="overflow-x:auto">
      <table class="ld-table">
        <thead>
          <tr>
            <th style="width:28px"></th>
            <th style="text-align:left">Tanggal</th>
            <th style="text-align:left">Dokumen</th>
            <th style="text-align:left">Tipe</th>
            <th style="text-align:left">Shipment</th>
            <th style="text-align:center">Produk</th>
            <th style="text-align:right">Total Masuk</th>
            <th style="text-align:right">Total Keluar</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($docGroups)): ?>
          <tr><td colspan="8" style="padding:48px;text-align:center;color:#9ca3af">
            <i class="fas fa-folder-open" style="font-size:2rem;display:block;margin-bottom:8px;opacity:.3"></i>
            Tidak ada dokumen ditemukan
          </td></tr>
          <?php else: ?>
          <?php foreach ($docGroups as $_dg):
            $dk       = ($g['reference_id'] ?? $g['reference_id'] ?? null);
            $dk       = ($_dg['reference_id'] ?? 'null') . '|' . ($_dg['reference_type'] ?? '');
            $dRows    = $docDetails[$dk] ?? [];
            $dgRowId  = 'dg-'.md5($dk);
            $shipNo   = $_dg['shipment_no'] ?? $_dg['oo_shipment_no'] ?? '';
            $refType  = $_dg['reference_type'] ?? '';
            // Dominant tx type from this group's detail rows
            $types = array_unique(array_column($dRows, 'transaction_type'));
            $domType = count($types) === 1 ? $types[0] : 'MIXED';
            $txM = $TX_META[$domType] ?? ['label'=>$domType,'icon'=>'fa-circle','color'=>'#6b7280','bg'=>'#f3f4f6','border'=>'#d1d5db'];
            // Ref type label
            $rtLabels = ['Inbound'=>'Penerimaan','Outbound'=>'Pengiriman','BinTransfer'=>'Bin Transfer','StockTake'=>'Stock Take','Adjustment'=>'Penyesuaian'];
            $rtLabel = $rtLabels[$refType] ?? $refType;
            // Reference link
            $refLinks = ['Inbound'=>'inbound.php','Outbound'=>'outbound.php','BinTransfer'=>'bin_transfer.php','StockTake'=>'stocktake.php'];
            $refLink = $refLinks[$refType] ?? null;
          ?>
          <tr class="doc-row-header" onclick="toggleDocGroup('<?= $dgRowId ?>', this)">
            <td style="text-align:center;padding:6px 4px">
              <span style="color:#026766;font-size:.8rem"><i id="ic-<?= $dgRowId ?>" class="fas fa-chevron-right" style="transition:transform .2s"></i></span>
            </td>
            <td style="color:#374151;font-size:.8rem;white-space:nowrap">
              <?= date('d M Y', strtotime($_dg['tx_date'])) ?>
            </td>
            <td>
              <div style="font-family:monospace;font-weight:700;color:#013d3c;font-size:.84rem">
                <?php if ($refLink): ?>
                <a href="<?= $refLink ?>" style="color:#013d3c;text-decoration:none" onclick="event.stopPropagation()"><?= htmlspecialchars($_dg['reference_number']??'—') ?></a>
                <?php else: ?><?= htmlspecialchars($_dg['reference_number']??'—') ?><?php endif; ?>
              </div>
              <div style="font-size:.7rem;color:#6b7280;margin-top:1px"><?= htmlspecialchars($rtLabel) ?></div>
            </td>
            <td>
              <span class="tx-pill" style="color:<?= $txM['color'] ?>;background:<?= $txM['bg'] ?>;border-color:<?= $txM['border'] ?>">
                <i class="fas <?= $txM['icon'] ?>" style="font-size:.6rem"></i>
                <?= htmlspecialchars($txM['label']) ?>
              </span>
            </td>
            <td style="font-family:monospace;font-size:.75rem;color:#026766"><?= htmlspecialchars($shipNo ?: '—') ?></td>
            <td style="text-align:center">
              <span style="background:#e6f7f7;color:#013d3c;border-radius:20px;padding:1px 9px;font-size:.73rem;font-weight:700"><?= (int)$_dg['product_count'] ?></span>
            </td>
            <td style="text-align:right;font-weight:700;color:#16a34a">
              <?= (float)$_dg['total_in'] > 0 ? '+'.number_format((float)$_dg['total_in'],0) : '<span style="color:#d1d5db">—</span>' ?>
            </td>
            <td style="text-align:right;font-weight:700;color:#dc2626">
              <?= (float)$_dg['total_out'] > 0 ? '-'.number_format((float)$_dg['total_out'],0) : '<span style="color:#d1d5db">—</span>' ?>
            </td>
          </tr>
          <?php if (!empty($dRows)): ?>
          <tr id="<?= $dgRowId ?>" style="display:none">
            <td colspan="8" style="padding:0">
              <div style="background:#f8fdfd;border-top:1px solid #b2e5e5;border-bottom:2px solid #b2e5e5;padding:10px 16px 12px 48px">
                <table style="width:100%;border-collapse:collapse;font-size:.77rem">
                  <thead>
                    <tr style="background:#026766;color:#fff">
                      <th style="padding:5px 10px;font-weight:600;text-align:left;border-radius:4px 0 0 4px">Produk</th>
                      <th style="padding:5px 10px;font-weight:600;text-align:center">Tipe</th>
                      <th style="padding:5px 10px;font-weight:600;text-align:left">Batch</th>
                      <th style="padding:5px 10px;font-weight:600;text-align:left">OD No</th>
                      <th style="padding:5px 10px;font-weight:600;text-align:right">Qty Masuk</th>
                      <th style="padding:5px 10px;font-weight:600;text-align:right">Qty Keluar</th>
                      <th style="padding:5px 10px;font-weight:600;text-align:right">Balance</th>
                      <th style="padding:5px 10px;font-weight:600;text-align:left;border-radius:0 4px 4px 0">Lokasi</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($dRows as $_dr):
                      $drTxM = $TX_META[$_dr['transaction_type']] ?? ['label'=>$_dr['transaction_type'],'icon'=>'fa-circle','color'=>'#6b7280','bg'=>'#f3f4f6','border'=>'#d1d5db'];
                    ?>
                    <tr style="border-bottom:1px solid #e6f7f7">
                      <td style="padding:5px 10px">
                        <div style="font-weight:600;color:#013d3c"><?= htmlspecialchars($_dr['product_code']) ?></div>
                        <div style="font-size:.7rem;color:#6b7280"><?= htmlspecialchars($_dr['product_name']) ?></div>
                      </td>
                      <td style="padding:5px 10px;text-align:center">
                        <span class="tx-pill" style="color:<?= $drTxM['color'] ?>;background:<?= $drTxM['bg'] ?>;border-color:<?= $drTxM['border'] ?>">
                          <i class="fas <?= $drTxM['icon'] ?>" style="font-size:.58rem"></i> <?= $drTxM['label'] ?>
                        </span>
                      </td>
                      <td style="padding:5px 10px;font-family:monospace;font-size:.74rem;color:#374151"><?= htmlspecialchars($_dr['batch_number']??'—') ?></td>
                      <td style="padding:5px 10px;font-family:monospace;font-size:.74rem;color:#026766;font-weight:600"><?= htmlspecialchars($_dr['od_number']??'—') ?></td>
                      <td style="padding:5px 10px;text-align:right;font-weight:700;color:#16a34a">
                        <?= (float)$_dr['quantity_in'] > 0 ? '+'.number_format((float)$_dr['quantity_in'],0) : '<span style="color:#d1d5db">—</span>' ?>
                      </td>
                      <td style="padding:5px 10px;text-align:right;font-weight:700;color:#dc2626">
                        <?= (float)$_dr['quantity_out'] > 0 ? '-'.number_format((float)$_dr['quantity_out'],0) : '<span style="color:#d1d5db">—</span>' ?>
                      </td>
                      <td style="padding:5px 10px;text-align:right">
                        <span class="balance-chip"><?= number_format((float)$_dr['balance'],0) ?></span>
                      </td>
                      <td style="padding:5px 10px;font-family:monospace;font-size:.72rem;color:#6b7280"><?= htmlspecialchars($_dr['location']??'—') ?></td>
                    </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            </td>
          </tr>
          <?php endif; ?>
          <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <?php else: ?>
    <!-- ════════════ TIMELINE VIEW ════════════ -->

    <?php if ($_ldTotal === 0 && $_totalAllTime === 0): ?>
    <div style="background:#fef2f2;border:1.5px solid #fca5a5;border-radius:0;padding:10px 20px;display:flex;align-items:center;gap:10px">
      <i class="fas fa-database" style="color:#dc2626"></i>
      <span style="font-size:.83rem;color:#991b1b;font-weight:600">
        Stock ledger kosong — belum ada transaksi yang tercatat. Ubah status item inbound ke GR atau ATP untuk mulai merekam.
      </span>
    </div>
    <?php endif; ?>

    <?php if ($productId && $openingBalance !== null): ?>
    <div style="background:#f8fdfd;border-bottom:1.5px solid #b2e5e5;padding:9px 20px;display:flex;align-items:center;gap:10px">
      <span style="font-size:.72rem;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.05em">Saldo Awal <?= date('d M Y', strtotime($startDate)) ?></span>
      <span class="balance-chip" style="font-size:.88rem"><?= number_format($openingBalance, 0) ?></span>
    </div>
    <?php endif; ?>

    <div style="overflow-x:auto">
      <table class="ld-table">
        <thead>
          <tr>
            <th style="text-align:left">Tanggal</th>
            <th style="text-align:center">Tipe</th>
            <th style="text-align:left">Produk</th>
            <th style="text-align:left">Referensi</th>
            <th style="text-align:left">OD No / Shipment</th>
            <th style="text-align:left">Batch</th>
            <th style="text-align:right">Qty Masuk</th>
            <th style="text-align:right">Qty Keluar</th>
            <th style="text-align:right">Pallet</th>
            <th style="text-align:right">Balance</th>
            <th style="text-align:left">Lokasi</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($movements)): ?>
          <tr><td colspan="11" style="padding:48px;text-align:center;color:#9ca3af">
            <i class="fas fa-inbox" style="font-size:2rem;display:block;margin-bottom:8px;opacity:.3"></i>
            Tidak ada transaksi ditemukan
          </td></tr>
          <?php else: ?>
          <?php foreach ($movements as $m):
            $txM    = $TX_META[$m['transaction_type']] ?? ['label'=>$m['transaction_type'],'icon'=>'fa-circle','color'=>'#6b7280','bg'=>'#f3f4f6','border'=>'#d1d5db'];
            $shipNo = $m['io_shipment_no'] ?? $m['oo_shipment_no'] ?? $m['bt_shipment_no'] ?? '';
            $qIn    = (float)($m['quantity_in']??0);
            $qOut   = (float)($m['quantity_out']??0);
            $refType = $m['reference_type'] ?? '';
            $refLinks = ['Inbound'=>'inbound.php','Outbound'=>'outbound.php','BinTransfer'=>'bin_transfer.php','StockTake'=>'stocktake.php'];
            $refLink  = $refLinks[$refType] ?? null;
          ?>
          <tr>
            <td style="color:#6b7280;font-size:.78rem;white-space:nowrap"><?= date('d M Y', strtotime($m['transaction_date'])) ?></td>
            <td style="text-align:center">
              <span class="tx-pill" style="color:<?= $txM['color'] ?>;background:<?= $txM['bg'] ?>;border-color:<?= $txM['border'] ?>">
                <i class="fas <?= $txM['icon'] ?>" style="font-size:.6rem"></i>
                <?= $txM['label'] ?>
              </span>
            </td>
            <td>
              <div style="font-weight:600;font-size:.82rem;color:#013d3c"><?= htmlspecialchars($m['product_code']) ?></div>
              <div style="font-size:.72rem;color:#6b7280"><?= htmlspecialchars($m['product_name']) ?></div>
            </td>
            <td>
              <div style="font-family:monospace;font-weight:600;font-size:.8rem;color:#013d3c">
                <?php if ($refLink): ?>
                <a href="<?= $refLink ?>" style="color:#013d3c;text-decoration:none"><?= htmlspecialchars($m['reference_number']??'—') ?></a>
                <?php else: ?><?= htmlspecialchars($m['reference_number']??'—') ?><?php endif; ?>
              </div>
              <div style="font-size:.68rem;color:#9ca3af"><?= htmlspecialchars($refType) ?></div>
            </td>
            <td style="font-size:.76rem">
              <?php if (!empty($m['od_number'])): ?>
              <div style="font-family:monospace;font-weight:600;color:#026766"><?= htmlspecialchars($m['od_number']) ?></div>
              <?php endif; ?>
              <?php if (!empty($m['so_number'])): ?>
              <div style="font-family:monospace;font-size:.68rem;color:#014f4e">SO: <?= htmlspecialchars($m['so_number']) ?></div>
              <?php endif; ?>
              <?php if (!empty($shipNo)): ?>
              <div style="font-size:.68rem;color:#9ca3af"><?= htmlspecialchars($shipNo) ?></div>
              <?php endif; ?>
              <?php if (empty($m['od_number']) && empty($m['so_number']) && empty($shipNo)): ?>
              <span style="color:#d1d5db">—</span>
              <?php endif; ?>
            </td>
            <td style="font-family:monospace;font-size:.77rem;color:#374151"><?= htmlspecialchars($m['batch_number']??'—') ?></td>
            <td style="text-align:right;font-weight:700;color:#16a34a">
              <?= $qIn > 0 ? '<span style="font-size:.82rem">+'.number_format($qIn,0).'</span>' : '<span style="color:#d1d5db">—</span>' ?>
            </td>
            <td style="text-align:right;font-weight:700;color:#dc2626">
              <?= $qOut > 0 ? '<span style="font-size:.82rem">-'.number_format($qOut,0).'</span>' : '<span style="color:#d1d5db">—</span>' ?>
            </td>
            <td style="text-align:right;color:#6b7280;font-size:.78rem"><?= number_format((int)ceil($m['pallet']??0)) ?></td>
            <td style="text-align:right">
              <span class="balance-chip"><?= number_format((float)($m['balance']??0),0) ?></span>
            </td>
            <td style="font-family:monospace;font-size:.74rem;color:#6b7280"><?= htmlspecialchars($m['location']??'—') ?></td>
          </tr>
          <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <?php if ($_ldTotalPages > 1): ?>
    <div style="display:flex;align-items:center;justify-content:space-between;padding:12px 20px;border-top:1px solid #e6f7f7;flex-wrap:wrap;gap:8px">
      <span style="font-size:.8rem;color:#9ca3af">
        <?= number_format($_ldOffset+1) ?>–<?= number_format(min($_ldOffset+$_ldPerPage, $_ldTotal)) ?> dari <?= number_format($_ldTotal) ?>
      </span>
      <div style="display:flex;gap:4px">
        <?php if ($_ldPage > 1): ?>
        <a href="?<?= http_build_query(array_merge($_GET, ['page'=>$_ldPage-1])) ?>"
           style="padding:5px 11px;background:#e6f7f7;color:#026766;border-radius:6px;text-decoration:none;font-size:.8rem">&laquo; Prev</a>
        <?php endif; ?>
        <?php for ($p = max(1,$_ldPage-2); $p <= min($_ldTotalPages,$_ldPage+2); $p++): ?>
        <a href="?<?= http_build_query(array_merge($_GET, ['page'=>$p])) ?>"
           style="padding:5px 9px;background:<?= $p===$_ldPage?'#026766':'#e6f7f7' ?>;color:<?= $p===$_ldPage?'#fff':'#026766' ?>;border-radius:6px;text-decoration:none;font-size:.8rem;font-weight:<?= $p===$_ldPage?'700':'400' ?>">
          <?= $p ?>
        </a>
        <?php endfor; ?>
        <?php if ($_ldPage < $_ldTotalPages): ?>
        <a href="?<?= http_build_query(array_merge($_GET, ['page'=>$p])) ?>"
           style="padding:5px 11px;background:#e6f7f7;color:#026766;border-radius:6px;text-decoration:none;font-size:.8rem">Next &raquo;</a>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>
    <?php endif; // end timeline view ?>
  </div>

</div>

<script>
function toggleDocGroup(rowId, headerRow) {
  var row  = document.getElementById(rowId);
  var icon = document.getElementById('ic-' + rowId);
  if (!row) return;
  var isOpen = row.style.display !== 'none';
  row.style.display = isOpen ? 'none' : '';
  if (icon) icon.style.transform = isOpen ? '' : 'rotate(90deg)';
  headerRow.classList.toggle('open', !isOpen);
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

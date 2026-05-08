<?php
ob_start();
error_reporting(0);
ini_set('display_errors', '0');
session_start();

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/classes/Auth.php';

Auth::requireAuth();

if (isset($_GET['action']) && $_GET['action'] === 'aisle_detail') {
    ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $aisle = trim($_GET['aisle'] ?? '');
    if (!$aisle) { echo json_encode(['success'=>false,'message'=>'aisle required']); exit; }
    try {
        $db = db();
        $stmt = $db->prepare("
            SELECT
                lm.location_code AS code,
                lm.rack, lm.row_name, lm.zone,
                COALESCE(s1.quantity, s2.quantity, 0)         AS qty,
                COALESCE(s1.pallet,  s2.pallet,  0)          AS pallet,
                COALESCE(s1.uom,     s2.uom)                  AS uom,
                COALESCE(s1.batch_number, s2.batch_number)    AS batch,
                COALESCE(s1.expiry_date,  s2.expiry_date)     AS expiry,
                COALESCE(p1.product_name, p2.product_name)    AS product,
                COALESCE(p1.product_code, p2.product_code)    AS product_code,
                COALESCE(p1.uom_per_pallet, p2.uom_per_pallet) AS uom_per_pallet
            FROM location_master lm
            LEFT JOIN stock_locations sl
                ON sl.location_code COLLATE utf8mb4_general_ci
                 = lm.location_code COLLATE utf8mb4_general_ci
                AND sl.status IN ('Available','Reserved')
            LEFT JOIN stock s1 ON sl.stock_id = s1.id AND s1.quantity > 0
            LEFT JOIN products p1 ON s1.product_id = p1.id
            LEFT JOIN stock s2
                ON s2.location COLLATE utf8mb4_general_ci
                 = lm.location_code COLLATE utf8mb4_general_ci
                AND s2.quantity > 0 AND s2.stock_status = 'Available'
                AND s1.id IS NULL
            LEFT JOIN products p2 ON s2.product_id = p2.id
            WHERE lm.aisle = ? AND lm.is_active = 1
            ORDER BY lm.rack, lm.row_name, lm.position
        ");
        $stmt->execute([$aisle]);
        $locations = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($locations as &$l) {
            $l['qty']      = (float)$l['qty'];
            $l['pallet']   = (float)$l['pallet'];
            $l['is_eceran']= ($l['row_name'] === 'A');
            $upp = (int)($l['uom_per_pallet'] ?? 4);
            $l['is_partial']= $l['is_eceran'] ||
                              (!$l['is_eceran'] && $l['qty'] > 0 && $upp > 0 && $l['qty'] < $upp);
            if (!$l['is_eceran'] && $l['pallet'] > 0)
                $l['pallet'] = (int)ceil($l['pallet']);
            if ($l['expiry']) $l['expiry'] = date('d M Y', strtotime($l['expiry']));
        }
        unset($l);

        $total    = count($locations);
        $occupied = count(array_filter($locations, function($l){ return $l['qty'] > 0; }));
        $totalQty = array_sum(array_column($locations, 'qty'));
        $totalPlt = (int)ceil(array_sum(array_column($locations, 'pallet')));

        echo json_encode([
            'success'   => true,
            'locations' => $locations,
            'stats'     => [
                'aisle'        => $aisle,
                'total'        => $total,
                'occupied'     => $occupied,
                'total_qty'    => number_format($totalQty, 0),
                'total_pallet' => $totalPlt,
            ],
        ], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
    }
    exit;
}

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$pageTitle = 'Dashboard';
$currentPage = 'dashboard';
$_flashError = match($_GET['error'] ?? '') {
    'unauthorized' => 'Akses ditolak. Anda tidak memiliki izin untuk mengakses halaman tersebut.',
    default        => null,
};

$db = db();

$stockByUOM = $db->query("SELECT p.uom_type, SUM(s.quantity) as total_qty, SUM(s.pallet) as total_pallet
                           FROM stock s JOIN products p ON s.product_id = p.id
                           WHERE s.stock_status = 'Available' GROUP BY p.uom_type")->fetchAll();
$totalDrums = 0; $totalPallets = 0;
foreach ($stockByUOM as $u) { $totalDrums += $u['total_qty']; $totalPallets += $u['total_pallet']; }

$expiringSoon  = $db->query("SELECT COUNT(*) as count FROM stock
                              WHERE expiry_date <= DATE_ADD(CURDATE(), INTERVAL 120 DAY)
                              AND expiry_date > CURDATE() AND stock_status = 'Available'")->fetch()['count'];
$expiredItems  = $db->query("SELECT COUNT(*) as count FROM stock
                              WHERE expiry_date < CURDATE() AND stock_status = 'Available'")->fetch()['count'];
$duesInCount   = $db->query("SELECT COUNT(*) as count FROM inbound_orders WHERE status = 'Dues In'")->fetch()['count'];
$receivingNow  = $db->query("SELECT COUNT(*) as count FROM inbound_orders WHERE status = 'Receiving'")->fetch()['count'];
$pendingOutbound = $db->query("SELECT COUNT(*) as count FROM outbound_orders WHERE status IN ('Open','Picking')")->fetch()['count'];
$dispatchedToday = $db->query("SELECT COUNT(*) as count FROM outbound_orders WHERE status = 'Completed' AND DATE(updated_at) = CURDATE()")->fetch()['count'];
$receivedToday   = $db->query("SELECT COUNT(*) as count FROM inbound_orders WHERE status IN ('Goods Received','Good Received') AND DATE(updated_at) = CURDATE()")->fetch()['count'];
$todayInbound    = $db->query("SELECT COUNT(*) as count FROM inbound_orders WHERE order_date = CURDATE()")->fetch()['count'];
$todayOutbound   = $db->query("SELECT COUNT(*) as count FROM outbound_orders WHERE order_date = CURDATE()")->fetch()['count'];

// Expired stock details for alert strip
$expiredDetail = [];
if ($expiredItems > 0) {
    $expiredDetail = $db->query("
        SELECT p.product_code, p.product_name, s.batch_number, s.expiry_date,
               SUM(s.quantity) as qty, SUM(s.pallet) as pallet
        FROM stock s JOIN products p ON s.product_id = p.id
        WHERE s.expiry_date < CURDATE() AND s.stock_status = 'Available'
        GROUP BY p.id, p.product_code, p.product_name, s.batch_number, s.expiry_date
        ORDER BY s.expiry_date ASC LIMIT 5
    ")->fetchAll();
}

$stockSummary = $db->query("
    SELECT p.id, p.product_code, p.product_name, p.uom_type, p.uom_per_pallet,
        COUNT(DISTINCT s.batch_number) as batches,
        SUM(s.quantity) as total_qty, SUM(s.pallet) as total_pallet,
        MIN(s.expiry_date) as nearest_expiry,
        SUM(CASE WHEN s.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 120 DAY) AND s.expiry_date > CURDATE() THEN 1 ELSE 0 END) as expiring_count
    FROM stock s JOIN products p ON s.product_id = p.id
    WHERE s.stock_status = 'Available'
    GROUP BY p.id HAVING total_qty > 0
    ORDER BY nearest_expiry ASC, total_qty DESC LIMIT 25
")->fetchAll();

$monthlyActivity = $db->query("
    SELECT DATE_FORMAT(transaction_date, '%Y-%m') as month,
        SUM(CASE WHEN transaction_type = 'IN' THEN quantity_in ELSE 0 END) as inbound_qty,
        SUM(CASE WHEN transaction_type = 'OUT' THEN quantity_out ELSE 0 END) as outbound_qty
    FROM stock_ledger WHERE transaction_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
    GROUP BY DATE_FORMAT(transaction_date, '%Y-%m') ORDER BY month DESC
")->fetchAll();

$stockByLocation = $db->query("
    SELECT lm.aisle,
           COUNT(DISTINCT lm.location_code) as total_locs,
           COUNT(DISTINCT CASE
               WHEN s1.quantity > 0 OR s2.quantity > 0
               THEN lm.location_code
           END) as occupied_locs,
           COALESCE(SUM(CASE WHEN s1.quantity > 0 THEN s1.quantity ELSE s2.quantity END), 0) as total_qty,
           CEIL(COALESCE(SUM(CASE WHEN s1.quantity > 0 THEN s1.pallet ELSE s2.pallet END), 0)) as total_pallet
    FROM location_master lm
    -- Path 1: via stock_locations (per-pallet, from inbound completed)
    LEFT JOIN stock_locations sl ON sl.location_code COLLATE utf8mb4_general_ci
                                  = lm.location_code COLLATE utf8mb4_general_ci
        AND sl.status IN ('Available','Reserved')
    LEFT JOIN stock s1 ON sl.stock_id = s1.id AND s1.quantity > 0
    -- Path 2: via stock.location directly (old/manual stock entries)
    LEFT JOIN stock s2 ON s2.location COLLATE utf8mb4_general_ci
                        = lm.location_code COLLATE utf8mb4_general_ci
        AND s2.quantity > 0 AND s2.stock_status = 'Available'
        AND s1.id IS NULL  -- don't double count
    WHERE lm.is_active = 1
    GROUP BY lm.aisle
    ORDER BY lm.aisle
")->fetchAll();

$recentActivity = $db->query("
    SELECT sl.*, p.product_code, p.product_name
    FROM stock_ledger sl JOIN products p ON sl.product_id = p.id
    ORDER BY sl.id DESC LIMIT 8
")->fetchAll();

// Pending work queue
$pendingInboundQ = $db->query("
    SELECT io.id, io.order_number, io.status, io.order_date,
           io.shipment_no, io.carrier_name,
           COUNT(ii.id) AS line_count,
           COALESCE(SUM(ii.quantity), 0) AS total_qty
    FROM inbound_orders io
    LEFT JOIN inbound_items ii ON ii.inbound_order_id = io.id
    WHERE io.status IN ('Dues In','Receiving')
    GROUP BY io.id, io.order_number, io.status, io.order_date, io.shipment_no, io.carrier_name
    ORDER BY FIELD(io.status,'Receiving','Dues In'), io.order_date ASC
    LIMIT 10
")->fetchAll();

$pendingOutboundQ = $db->query("
    SELECT oo.id, oo.order_number, oo.status, oo.order_date,
           oo.shipment_number,
           COUNT(oi.id) AS line_count,
           COALESCE(SUM(oi.quantity), 0) AS total_qty
    FROM outbound_orders oo
    LEFT JOIN outbound_items oi ON oi.outbound_order_id = oo.id
    WHERE oo.status IN ('Open','Picking')
    GROUP BY oo.id, oo.order_number, oo.status, oo.order_date, oo.shipment_number
    ORDER BY FIELD(oo.status,'Picking','Open'), oo.order_date ASC
    LIMIT 10
")->fetchAll();

ob_end_flush();
require_once __DIR__ . '/includes/header.php';
?>
<?php if ($_flashError): ?>
<div style="background:#fef2f2;border:1px solid #fecaca;color:#dc2626;padding:10px 16px;border-radius:10px;font-size:.83rem;font-weight:600;display:flex;align-items:center;gap:8px;margin-bottom:16px">
    <i class="fas fa-ban"></i> <?= htmlspecialchars($_flashError) ?>
</div>
<?php endif; ?>
<style>

.db-hero {
  background: linear-gradient(135deg, #0f172a 0%, #1e293b 40%, #026766 100%);
  border-radius: 20px;
  padding: 28px 32px;
  color: #fff;
  position: relative;
  overflow: hidden;
}
.db-hero::before {
  content: '';
  position: absolute;
  top: -60px; right: -60px;
  width: 240px; height: 240px;
  border-radius: 50%;
  background: rgba(255,255,255,.04);
}
.db-hero::after {
  content: '';
  position: absolute;
  bottom: -40px; left: 200px;
  width: 180px; height: 180px;
  border-radius: 50%;
  background: rgba(255,255,255,.03);
}
.db-hero h1 { font-size: 1.5rem; font-weight: 800; }
.db-hero .welcome { font-size: 1rem; opacity: .8; margin-top: 2px; }
.db-hero .clock-area { text-align:right; }
.db-hero .clock { font-size: 2.8rem; font-weight: 800; letter-spacing: 2px; font-family: 'DM Mono', monospace; line-height: 1; }
.db-hero .clock-date { font-size: .85rem; opacity: .75; margin-top: 4px; }

.kpi-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px; }
@media(min-width:1200px) { .kpi-grid { grid-template-columns: repeat(6, 1fr); } }
@media(max-width:768px)  { .kpi-grid { grid-template-columns: repeat(2, 1fr); } }

.kpi-card {
  border-radius: 14px;
  padding: 18px 16px;
  color: #fff;
  position: relative;
  overflow: hidden;
  box-shadow: 0 4px 15px rgba(0,0,0,.15);
  transition: transform .15s;
}
.kpi-card:hover { transform: translateY(-3px); }
.kpi-card .kpi-icon { position:absolute; right:12px; top:12px; font-size:1.8rem; opacity:.2; }
.kpi-card .kpi-num  { font-size: 2rem; font-weight: 800; line-height: 1; }
.kpi-card .kpi-lbl  { font-size: .75rem; opacity: .85; margin-top: 4px; text-transform: uppercase; letter-spacing: .5px; }
.kpi-card .kpi-sub  { font-size: .7rem; opacity: .65; margin-top: 6px; }

.kpi-1 { background: linear-gradient(135deg, #012e2d, #026766); }
.kpi-2 { background: linear-gradient(135deg, #014f4e, #04a39f); }
.kpi-3 { background: linear-gradient(135deg, #7c3000, #d97706); }
.kpi-4 { background: linear-gradient(135deg, #7f1d1d, #dc2626); }
.kpi-5 { background: linear-gradient(135deg, #1e3a3a, #026766); }
.kpi-6 { background: linear-gradient(135deg, #374151, #6b7280); }

.section-title {
  font-size: .7rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 1px;
  color: #9ca3af;
  margin-bottom: 12px;
  display: flex;
  align-items: center;
  gap: 8px;
}
.section-title::after {
  content: '';
  flex: 1;
  height: 1px;
  background: #f3f4f6;
}

.action-btn {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 8px;
  padding: 18px 12px;
  border-radius: 14px;
  text-decoration: none;
  font-size: .8rem;
  font-weight: 700;
  transition: all .15s;
  border: 2px solid transparent;
}
.action-btn:hover { transform: translateY(-2px); filter: brightness(1.05); }
.action-btn i { font-size: 1.6rem; }

.stock-table { width: 100%; border-collapse: collapse; font-size: .82rem; }
.stock-table thead th {
  background: #1f2937; color: #f9fafb; padding: 10px 12px;
  font-size: .72rem; font-weight: 700; text-transform: uppercase; letter-spacing: .3px;
  white-space: nowrap;
}
.stock-table thead th.tc { text-align: center; }
.stock-table thead th.tr { text-align: right; }
.stock-table tbody tr { border-bottom: 1px solid #f3f4f6; transition: background .1s; }
.stock-table tbody tr:hover { background: #e6f7f6; }
.stock-table tbody td { padding: 9px 12px; }

.activity-item {
  display: flex; align-items: center; gap: 12px;
  padding: 10px 16px; border-bottom: 1px solid #f9fafb;
  transition: background .1s;
}
.activity-item:hover { background: #fafafa; }
.activity-dot { width: 36px; height: 36px; border-radius: 50%; display:flex; align-items:center; justify-content:center; flex-shrink:0; font-size:.9rem; }
.dot-in  { background: #e0f7f7; color: #013d3c; }
.dot-out { background: #e0f7f7; color: #013d3c; }

.prog-bar { background: #f3f4f6; border-radius: 99px; height: 8px; overflow: hidden; margin-top: 4px; }
.prog-fill { height: 100%; border-radius: 99px; background: linear-gradient(90deg, #026766, #014f4e); }
</style>

<div style="display:grid;gap:18px">

  
  <div class="db-hero">
    <div style="display:flex;justify-content:space-between;align-items:center;position:relative;z-index:1">
      <div>
        <h1><i class="fas fa-warehouse mr-2" style="color:#fbbf24"></i>K-one</h1>
        <p class="welcome">
          Welcome back, <strong><?= htmlspecialchars($_SESSION['full_name'] ?? 'Administrator') ?></strong>!
          <span style="opacity:.6;font-size:.85rem;margin-left:6px">K-one</span>
        </p>
        <div style="display:flex;gap:8px;margin-top:14px;flex-wrap:wrap">
          <a href="inbound.php?action=create" style="background:rgba(255,255,255,.15);backdrop-filter:blur(4px);color:#fff;padding:7px 16px;border-radius:8px;font-size:.8rem;font-weight:600;text-decoration:none;border:1px solid rgba(255,255,255,.2)">
            <i class="fas fa-truck-loading mr-1"></i> New Inbound
          </a>
          <a href="outbound.php?action=create" style="background:rgba(255,193,7,.2);backdrop-filter:blur(4px);color:#fbbf24;padding:7px 16px;border-radius:8px;font-size:.8rem;font-weight:600;text-decoration:none;border:1px solid rgba(255,193,7,.3)">
            <i class="fas fa-truck mr-1"></i> New Outbound
          </a>
          <a href="reports.php" style="background:rgba(255,255,255,.1);backdrop-filter:blur(4px);color:rgba(255,255,255,.8);padding:7px 16px;border-radius:8px;font-size:.8rem;font-weight:600;text-decoration:none;border:1px solid rgba(255,255,255,.15)">
            <i class="fas fa-chart-bar mr-1"></i> Reports
          </a>
        </div>
      </div>
      <div class="clock-area" style="position:relative;z-index:1">
        <div class="clock" id="liveClock">--:--:--</div>
        <div class="clock-date" id="liveDate">Loading...</div>
        <div style="margin-top:8px;font-size:.72rem;opacity:.5;text-align:right">
          <i class="fas fa-map-marker-alt mr-1"></i>Surabaya, WIB
        </div>
      </div>
    </div>
  </div>

  
  <?php if ($expiredItems > 0): ?>
  <div style="background:linear-gradient(135deg,#7f1d1d,#dc2626);border-radius:14px;padding:14px 22px;color:#fff;display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;box-shadow:0 4px 15px rgba(220,38,38,.35)">
    <div style="display:flex;align-items:center;gap:12px">
      <div style="width:40px;height:40px;background:rgba(255,255,255,.2);border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0">
        <i class="fas fa-exclamation-circle" style="font-size:1.3rem"></i>
      </div>
      <div>
        <div style="font-weight:800;font-size:.95rem">EXPIRED STOCK — Perlu Tindakan Segera</div>
        <div style="font-size:.8rem;opacity:.85;margin-top:2px">
          <?= $expiredItems ?> batch expired masih berstatus Available —
          <?php foreach ($expiredDetail as $i => $ed): ?>
            <?= $i > 0 ? ', ' : '' ?><strong><?= htmlspecialchars($ed['product_code']) ?></strong>
            (<?= date('d M Y', strtotime($ed['expiry_date'])) ?>)
          <?php endforeach; ?>
          <?php if ($expiredItems > count($expiredDetail)): ?> &amp; <?= $expiredItems - count($expiredDetail) ?> lainnya<?php endif; ?>
        </div>
      </div>
    </div>
    <a href="stock.php?filter_status=expired" style="background:rgba(255,255,255,.2);color:#fff;padding:8px 18px;border-radius:8px;font-size:.82rem;font-weight:700;text-decoration:none;white-space:nowrap;border:1px solid rgba(255,255,255,.3)">
      <i class="fas fa-arrow-right mr-1"></i>Lihat Stock
    </a>
  </div>
  <?php endif; ?>

  <div class="kpi-grid">
    <div class="kpi-card kpi-2" style="cursor:pointer" onclick="location.href='stock.php'">
      <i class="fas fa-layer-group kpi-icon"></i>
      <div class="kpi-num"><?= number_format($totalDrums) ?></div>
      <div class="kpi-lbl">Total Qty</div>
      <div class="kpi-sub"><?= (int)ceil($totalPallets) ?> Pallets</div>
    </div>
    <div class="kpi-card kpi-4" style="cursor:pointer" onclick="location.href='stock.php'">
      <i class="fas fa-times-circle kpi-icon"></i>
      <div class="kpi-num"><?= number_format($expiredItems) ?></div>
      <div class="kpi-lbl">Expired</div>
      <div class="kpi-sub">Perlu dikeluarkan</div>
    </div>
    <div class="kpi-card kpi-3" style="cursor:pointer" onclick="location.href='stock.php'">
      <i class="fas fa-exclamation-triangle kpi-icon"></i>
      <div class="kpi-num"><?= number_format($expiringSoon) ?></div>
      <div class="kpi-lbl">Expiring</div>
      <div class="kpi-sub">Within 120 days</div>
    </div>
    <div class="kpi-card" style="background:linear-gradient(135deg,#064e3b,#059669);cursor:pointer" onclick="location.href='inbound.php'">
      <i class="fas fa-truck-loading kpi-icon"></i>
      <div class="kpi-num"><?= number_format($receivingNow) ?></div>
      <div class="kpi-lbl">Receiving</div>
      <div class="kpi-sub">Sedang bongkar</div>
    </div>
    <div class="kpi-card kpi-5" style="cursor:pointer" onclick="location.href='inbound.php'">
      <i class="fas fa-calendar-check kpi-icon"></i>
      <div class="kpi-num"><?= number_format($duesInCount) ?></div>
      <div class="kpi-lbl">Dues In</div>
      <div class="kpi-sub">Expected arrival</div>
    </div>
    <div class="kpi-card kpi-6" style="cursor:pointer" onclick="location.href='outbound.php'">
      <i class="fas fa-shipping-fast kpi-icon"></i>
      <div class="kpi-num"><?= number_format($pendingOutbound) ?></div>
      <div class="kpi-lbl">Pending Out</div>
      <div class="kpi-sub">Open + Picking</div>
    </div>
  </div>


  <div style="background:#fff;border-radius:14px;box-shadow:0 1px 8px rgba(0,0,0,.07);overflow:hidden">
    <div style="padding:16px 22px;border-bottom:1px solid #f3f4f6;display:flex;justify-content:space-between;align-items:center">
      <div class="section-title" style="margin:0"><i class="fas fa-tasks" style="color:#d97706"></i> Pending Work Queue</div>
      <?php $totalPending = count($pendingInboundQ) + count($pendingOutboundQ); ?>
      <?php if ($totalPending > 0): ?>
      <span style="background:#fef3c7;color:#92400e;font-size:.72rem;font-weight:700;padding:3px 10px;border-radius:99px"><?= $totalPending ?> order perlu tindakan</span>
      <?php else: ?>
      <span style="background:#d1fae5;color:#065f46;font-size:.72rem;font-weight:700;padding:3px 10px;border-radius:99px"><i class="fas fa-check-circle mr-1"></i>Semua clear</span>
      <?php endif; ?>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:0">

      <!-- Inbound Pending -->
      <div style="border-right:1px solid #f3f4f6;padding:16px 20px">
        <div style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:#16a34a;margin-bottom:10px;display:flex;align-items:center;justify-content:space-between">
          <span style="display:flex;align-items:center;gap:6px"><i class="fas fa-arrow-circle-down"></i> Inbound — Perlu Proses</span>
          <a href="inbound.php" style="font-size:.68rem;color:#026766;text-decoration:none;font-weight:600;opacity:.8">Lihat semua</a>
        </div>
        <?php if (empty($pendingInboundQ)): ?>
        <div style="color:#9ca3af;font-size:.82rem;padding:12px 0;text-align:center"><i class="fas fa-check-circle" style="color:#10b981;display:block;font-size:1.4rem;margin-bottom:6px"></i>Tidak ada inbound pending</div>
        <?php else: ?>
        <div style="display:flex;flex-direction:column;gap:5px">
          <?php foreach ($pendingInboundQ as $ord):
            $statusColor = match($ord['status']) {
              'Receiving' => ['bg'=>'#dcfce7','c'=>'#166534','dot'=>'#16a34a'],
              'Dues In'   => ['bg'=>'#e0f7f7','c'=>'#014f4e','dot'=>'#026766'],
              default     => ['bg'=>'#f3f4f6','c'=>'#374151','dot'=>'#9ca3af'],
            };
            $daysOld = (int)floor((strtotime('now') - strtotime($ord['order_date'])) / 86400);
            $isOld   = $daysOld > 5;
            $label   = !empty($ord['shipment_no']) ? $ord['shipment_no'] : ($ord['order_number'] ?? '—');
          ?>
          <a href="inbound.php?id=<?= $ord['id'] ?>" style="text-decoration:none;display:flex;align-items:center;justify-content:space-between;padding:9px 11px;border-radius:9px;border:1px solid <?= $isOld ? '#fde68a' : '#f3f4f6' ?>;background:<?= $isOld ? '#fffbeb' : '#fafafa' ?>;gap:8px;transition:background .1s" onmouseover="this.style.background='#f0fdf4'" onmouseout="this.style.background='<?= $isOld ? '#fffbeb' : '#fafafa' ?>'">
            <div style="display:flex;align-items:center;gap:8px;min-width:0">
              <div style="width:8px;height:8px;border-radius:50%;background:<?= $statusColor['dot'] ?>;flex-shrink:0"></div>
              <div style="min-width:0">
                <div style="font-size:.8rem;font-weight:700;color:#111827;font-family:monospace;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= htmlspecialchars($label) ?></div>
                <div style="font-size:.68rem;color:#6b7280;margin-top:1px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
                  <?= $ord['line_count'] ?> lines · <?= number_format($ord['total_qty']) ?> qty
                  <?php if (!empty($ord['carrier_name'])): ?> · <span style="color:#374151"><?= htmlspecialchars($ord['carrier_name']) ?></span><?php endif; ?>
                  <?php if ($isOld): ?> · <span style="color:#d97706;font-weight:600"><?= $daysOld ?>d lalu</span><?php endif; ?>
                </div>
              </div>
            </div>
            <span style="background:<?= $statusColor['bg'] ?>;color:<?= $statusColor['c'] ?>;font-size:.65rem;font-weight:700;padding:2px 8px;border-radius:99px;white-space:nowrap;flex-shrink:0"><?= $ord['status'] ?></span>
          </a>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>

      <!-- Outbound Pending -->
      <div style="padding:16px 20px">
        <div style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:#dc2626;margin-bottom:10px;display:flex;align-items:center;justify-content:space-between">
          <span style="display:flex;align-items:center;gap:6px"><i class="fas fa-arrow-circle-up"></i> Outbound — Perlu Proses</span>
          <a href="outbound.php" style="font-size:.68rem;color:#026766;text-decoration:none;font-weight:600;opacity:.8">Lihat semua</a>
        </div>
        <?php if (empty($pendingOutboundQ)): ?>
        <div style="color:#9ca3af;font-size:.82rem;padding:12px 0;text-align:center"><i class="fas fa-check-circle" style="color:#10b981;display:block;font-size:1.4rem;margin-bottom:6px"></i>Tidak ada outbound pending</div>
        <?php else: ?>
        <div style="display:flex;flex-direction:column;gap:5px">
          <?php foreach ($pendingOutboundQ as $ord):
            $statusColor = match($ord['status']) {
              'Picking' => ['bg'=>'#ffedd5','c'=>'#9a3412','dot'=>'#f97316'],
              'Open'    => ['bg'=>'#fef9c3','c'=>'#854d0e','dot'=>'#eab308'],
              default   => ['bg'=>'#f3f4f6','c'=>'#374151','dot'=>'#9ca3af'],
            };
            $daysOld = (int)floor((strtotime('now') - strtotime($ord['order_date'])) / 86400);
            $isOld   = $daysOld > 5;
            $label   = !empty($ord['shipment_number']) ? $ord['shipment_number'] : ($ord['order_number'] ?? '—');
          ?>
          <a href="outbound.php?id=<?= $ord['id'] ?>" style="text-decoration:none;display:flex;align-items:center;justify-content:space-between;padding:9px 11px;border-radius:9px;border:1px solid <?= $isOld ? '#fde68a' : '#f3f4f6' ?>;background:<?= $isOld ? '#fffbeb' : '#fafafa' ?>;gap:8px;transition:background .1s" onmouseover="this.style.background='#fff1f2'" onmouseout="this.style.background='<?= $isOld ? '#fffbeb' : '#fafafa' ?>'">
            <div style="display:flex;align-items:center;gap:8px;min-width:0">
              <div style="width:8px;height:8px;border-radius:50%;background:<?= $statusColor['dot'] ?>;flex-shrink:0"></div>
              <div style="min-width:0">
                <div style="font-size:.8rem;font-weight:700;color:#111827;font-family:monospace;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= htmlspecialchars($label) ?></div>
                <div style="font-size:.68rem;color:#6b7280;margin-top:1px">
                  <?= $ord['line_count'] ?> lines · <?= number_format($ord['total_qty']) ?> qty
                  <?php if ($isOld): ?> · <span style="color:#d97706;font-weight:600"><?= $daysOld ?>d lalu</span><?php endif; ?>
                </div>
              </div>
            </div>
            <span style="background:<?= $statusColor['bg'] ?>;color:<?= $statusColor['c'] ?>;font-size:.65rem;font-weight:700;padding:2px 8px;border-radius:99px;white-space:nowrap;flex-shrink:0"><?= $ord['status'] ?></span>
          </a>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>

    </div>
  </div>


  <div style="display:grid;grid-template-columns:2fr 1fr;gap:18px">
    <div style="background:#fff;border-radius:14px;box-shadow:0 1px 8px rgba(0,0,0,.07);padding:22px">
      <div class="section-title"><i class="fas fa-bolt" style="color:#f59e0b"></i> Quick Actions</div>
      <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:10px">
        <a href="inbound.php?action=create" class="action-btn" style="background:#e6f7f7;color:#026766;border-color:#e0f7f7">
          <i class="fas fa-truck-loading"></i><span>New<br>Inbound</span>
        </a>
        <a href="outbound.php?action=create" class="action-btn" style="background:#fdf4ff;color:#026766;border-color:#e0f7f7">
          <i class="fas fa-truck"></i><span>New<br>Outbound</span>
        </a>
        <a href="stocktake.php?action=create" class="action-btn" style="background:#e6f7f7;color:#013d3c;border-color:#e0f7f7">
          <i class="fas fa-clipboard-check"></i><span>Stock<br>Take</span>
        </a>
        <a href="reports.php" class="action-btn" style="background:#fff7ed;color:#9a3412;border-color:#ffedd5">
          <i class="fas fa-chart-bar"></i><span>View<br>Reports</span>
        </a>
      </div>
    </div>
    <div style="background:#fff;border-radius:14px;box-shadow:0 1px 8px rgba(0,0,0,.07);padding:22px">
      <div class="section-title"><i class="fas fa-sun" style="color:#f59e0b"></i> Hari Ini</div>
      <div style="display:flex;flex-direction:column;gap:10px">
        <div style="display:flex;justify-content:space-between;align-items:center;padding:10px;background:#f0fdf4;border-radius:10px">
          <div>
            <div style="font-size:.72rem;color:#6b7280;font-weight:600">Diterima</div>
            <div style="font-size:1.5rem;font-weight:800;color:#16a34a;line-height:1.1"><?= $receivedToday ?></div>
            <div style="font-size:.68rem;color:#9ca3af"><?= $todayInbound ?> direncanakan</div>
          </div>
          <div style="width:38px;height:38px;background:#dcfce7;border-radius:9px;display:flex;align-items:center;justify-content:center;color:#16a34a">
            <i class="fas fa-truck-loading"></i>
          </div>
        </div>
        <div style="display:flex;justify-content:space-between;align-items:center;padding:10px;background:#fff7ed;border-radius:10px">
          <div>
            <div style="font-size:.72rem;color:#6b7280;font-weight:600">Dikirim</div>
            <div style="font-size:1.5rem;font-weight:800;color:#ea580c;line-height:1.1"><?= $dispatchedToday ?></div>
            <div style="font-size:.68rem;color:#9ca3af"><?= $pendingOutbound ?> masih pending</div>
          </div>
          <div style="width:38px;height:38px;background:#ffedd5;border-radius:9px;display:flex;align-items:center;justify-content:center;color:#ea580c">
            <i class="fas fa-shipping-fast"></i>
          </div>
        </div>
      </div>
    </div>
  </div>

  
  <div style="background:#fff;border-radius:14px;box-shadow:0 1px 8px rgba(0,0,0,.07);overflow:hidden">
    <div style="padding:18px 22px;border-bottom:1px solid #f3f4f6;display:flex;justify-content:space-between;align-items:center">
      <div class="section-title" style="margin:0"><i class="fas fa-table" style="color:#026766"></i> Stock Summary</div>
      <a href="stock.php" style="font-size:.8rem;color:#026766;text-decoration:none;font-weight:600">View All <i class="fas fa-arrow-right ml-1"></i></a>
    </div>
    <div style="overflow-x:auto">
      <table class="stock-table">
        <thead><tr>
          <th>Product Code</th>
          <th>Product Name</th>
          <th class="tc">Batches</th>
          <th class="tc">UOM</th>
          <th class="tr">Qty</th>
          <th class="tr">Pallets</th>
          <th class="tc">Nearest Expiry</th>
          <th class="tc">Status</th>
        </tr></thead>
        <tbody>
          <?php foreach ($stockSummary as $item):
            $nearestExpiry = $item['nearest_expiry'];
            $today = date('Y-m-d');
            $days  = $nearestExpiry ? (strtotime($nearestExpiry) - strtotime($today)) / 86400 : 9999;
            $isExpired  = $nearestExpiry && $nearestExpiry < $today;
            $isCritical = !$isExpired && $days <= 30;
            $isWarn     = !$isExpired && !$isCritical && $days <= 120;
            $expiryColor = $isExpired ? '#e0f2f1' : ($isCritical ? '#fef3c7' : ($isWarn ? '#fef9c3' : '#e6f7f7'));
            $expiryText  = $isExpired ? '#013d3c' : ($isCritical ? '#92400e' : ($isWarn ? '#854d0e' : '#013d3c'));
            $uomColors   = ['Drum'=>'#e0f7f7|#014f4e','Carton'=>'#e0f7f7|#013d3c','Pail'=>'#fef9c3|#854d0e','EA'=>'#f3e8ff|#6b21a8','Bags'=>'#ffedd5|#9a3412'];
            [$ubg,$utx] = explode('|', $uomColors[$item['uom_type']] ?? '#f3f4f6|#374151');
          ?>
          <tr>
            <td style="font-family:monospace;font-weight:600;font-size:.8rem;color:#374151"><?= htmlspecialchars($item['product_code']) ?></td>
            <td style="font-weight:600;color:#111827"><?= htmlspecialchars($item['product_name']) ?></td>
            <td style="text-align:center"><span style="background:#f3f4f6;color:#374151;padding:2px 8px;border-radius:12px;font-size:.75rem;font-weight:600"><?= $item['batches'] ?></span></td>
            <td style="text-align:center"><span style="background:<?= $ubg ?>;color:<?= $utx ?>;padding:2px 8px;border-radius:12px;font-size:.75rem;font-weight:700"><?= $item['uom_type'] ?></span></td>
            <td style="text-align:right;font-weight:700"><?= number_format($item['total_qty']) ?></td>
            <td style="text-align:right;color:#6b7280"><?= (int)ceil($item['total_pallet']) ?></td>
            <td style="text-align:center">
              <?php if ($nearestExpiry): ?>
              <span style="background:<?= $expiryColor ?>;color:<?= $expiryText ?>;padding:3px 8px;border-radius:12px;font-size:.72rem;font-weight:600">
                <?= $isExpired ? 'EXPIRED' : date('d M Y', strtotime($nearestExpiry)) ?>
              </span>
              <?php else: ?><span style="color:#9ca3af">—</span><?php endif; ?>
            </td>
            <td style="text-align:center">
              <?php if ($item['expiring_count'] > 0): ?>
              <span style="color:#f59e0b;font-size:.78rem;font-weight:600"><i class="fas fa-exclamation-circle mr-1"></i><?= $item['expiring_count'] ?></span>
              <?php else: ?>
              <span style="color:#10b981;font-size:.78rem"><i class="fas fa-check-circle mr-1"></i>OK</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (empty($stockSummary)): ?>
          <tr><td colspan="8" style="text-align:center;padding:32px;color:#9ca3af"><i class="fas fa-inbox" style="font-size:2rem;display:block;margin-bottom:8px"></i>No stock data</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  
  <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:18px">

    
    <div style="background:#fff;border-radius:14px;box-shadow:0 1px 8px rgba(0,0,0,.07);padding:22px;overflow-y:auto;max-height:380px">
      <div class="section-title"><i class="fas fa-warehouse" style="color:#026766"></i> Occupancy per Aisle</div>
      <div style="display:flex;flex-direction:column;gap:6px">
        <?php foreach ($stockByLocation as $loc):
          $pct    = $loc['total_locs'] > 0 ? round($loc['occupied_locs'] / $loc['total_locs'] * 100) : 0;
          $avail  = $loc['total_locs'] - $loc['occupied_locs'];
          $barCol = $pct > 80 ? '#026766' : ($pct > 50 ? '#d97706' : '#026766');
        ?>
        <div onclick="openAisle('<?= htmlspecialchars($loc['aisle']) ?>')"
             style="cursor:pointer;padding:8px 10px;border-radius:8px;border:1px solid #eef0f5;
                    transition:all .15s;background:#fafbff"
             onmouseover="this.style.background='#f0f4ff';this.style.borderColor='#c7d2fe'"
             onmouseout="this.style.background='#fafbff';this.style.borderColor='#eef0f5'">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:5px">
            <div style="display:flex;align-items:center;gap:8px">
              <span style="font-weight:800;color:#026766;font-size:.85rem;font-family:monospace"><?= htmlspecialchars($loc['aisle']) ?></span>
              <?php if ($loc['occupied_locs'] > 0): ?>
              <span style="font-size:.7rem;color:#374151">
                <b><?= number_format($loc['total_qty'],0) ?></b>
                <?php if($loc['total_pallet'] > 0): ?>
                <span style="color:#6b7280">(<?= (int)$loc['total_pallet'] ?> plt)</span>
                <?php endif; ?>
              </span>
              <?php else: ?>
              <span style="font-size:.68rem;color:#026766;background:#e6f7f7;border-radius:4px;padding:1px 6px">✓ Kosong</span>
              <?php endif; ?>
            </div>
            <div style="display:flex;align-items:center;gap:8px;font-size:.7rem;color:#6b7280">
              <span><?= $loc['occupied_locs'] ?> terisi</span>
              <span style="color:#b0bec5">·</span>
              <span style="color:#026766"><?= $avail ?> available</span>
              <i class="fas fa-chevron-right" style="font-size:.58rem;color:#c7d2fe"></i>
            </div>
          </div>
          <div style="background:#f1f5f9;border-radius:99px;height:6px;overflow:hidden">
            <div style="width:<?= $pct ?>%;height:100%;background:<?= $barCol ?>;border-radius:99px;transition:width .4s"></div>
          </div>
        </div>
        <?php endforeach; ?>
        <?php if (empty($stockByLocation)): ?>
        <p style="color:#9ca3af;font-size:.85rem;text-align:center">Tidak ada data lokasi</p>
        <?php endif; ?>
      </div>
    </div>

    
    <div style="background:#fff;border-radius:14px;box-shadow:0 1px 8px rgba(0,0,0,.07);padding:22px">
      <div class="section-title"><i class="fas fa-chart-line" style="color:#10b981"></i> Monthly Activity</div>
      <div style="display:flex;flex-direction:column;gap:10px">
        <?php
        $maxAct = 0;
        foreach ($monthlyActivity as $a) $maxAct = max($maxAct, $a['inbound_qty'], $a['outbound_qty']);
        foreach ($monthlyActivity as $activity):
          $inPct  = $maxAct > 0 ? ($activity['inbound_qty'] / $maxAct) * 100 : 0;
          $outPct = $maxAct > 0 ? ($activity['outbound_qty'] / $maxAct) * 100 : 0;
        ?>
        <div>
          <div style="font-size:.75rem;font-weight:600;color:#374151;margin-bottom:3px">
            <?= date('M Y', strtotime($activity['month'] . '-01')) ?>
          </div>
          <div style="display:grid;gap:2px">
            <div style="display:flex;align-items:center;gap:6px">
              <span style="font-size:.65rem;color:#10b981;width:16px">IN</span>
              <div style="flex:1;background:#f3f4f6;border-radius:99px;height:6px">
                <div style="width:<?= $inPct ?>%;background:#10b981;height:100%;border-radius:99px"></div>
              </div>
              <span style="font-size:.65rem;color:#6b7280;width:28px;text-align:right"><?= number_format($activity['inbound_qty']) ?></span>
            </div>
            <div style="display:flex;align-items:center;gap:6px">
              <span style="font-size:.65rem;color:#026766;width:16px">OUT</span>
              <div style="flex:1;background:#f3f4f6;border-radius:99px;height:6px">
                <div style="width:<?= $outPct ?>%;background:#026766;height:100%;border-radius:99px"></div>
              </div>
              <span style="font-size:.65rem;color:#6b7280;width:28px;text-align:right"><?= number_format($activity['outbound_qty']) ?></span>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
        <?php if (empty($monthlyActivity)): ?><p style="color:#9ca3af;font-size:.85rem;text-align:center">No activity yet</p><?php endif; ?>
      </div>
    </div>

    
    <div style="background:#fff;border-radius:14px;box-shadow:0 1px 8px rgba(0,0,0,.07);overflow:hidden">
      <div style="padding:18px 22px;border-bottom:1px solid #f3f4f6">
        <div class="section-title" style="margin:0"><i class="fas fa-history" style="color:#6366f1"></i> Recent Activity</div>
      </div>
      <div>
        <?php foreach ($recentActivity as $act):
          $isIn = $act['transaction_type'] === 'IN';
          $qty  = $isIn ? $act['quantity_in'] : $act['quantity_out'];
        ?>
        <div class="activity-item">
          <div class="activity-dot <?= $isIn ? 'dot-in' : 'dot-out' ?>">
            <i class="fas <?= $isIn ? 'fa-arrow-down' : 'fa-arrow-up' ?>"></i>
          </div>
          <div style="flex:1;min-width:0">
            <div style="font-size:.8rem;font-weight:600;color:#111827;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
              <?= htmlspecialchars($act['product_name']) ?>
            </div>
            <div style="font-size:.72rem;color:#6b7280">
              <?= $isIn ? '+' : '-' ?><?= number_format($qty,1) ?> <?= $act['uom'] ?>
              · <?= date('d M', strtotime($act['transaction_date'])) ?>
            </div>
          </div>
          <span style="font-size:.7rem;color:#013d3c;font-weight:700;background:#e0f7f7;padding:2px 7px;border-radius:99px">
            <?= $isIn ? 'IN' : 'OUT' ?>
          </span>
        </div>
        <?php endforeach; ?>
        <?php if (empty($recentActivity)): ?>
        <div style="padding:24px;text-align:center;color:#9ca3af;font-size:.85rem"><i class="fas fa-inbox" style="display:block;font-size:1.5rem;margin-bottom:6px"></i>No activity</div>
        <?php endif; ?>
      </div>
    </div>
  </div>

</div>

<script>

function updateClock() {
  const now = new Date();
  const wib = new Date(now.toLocaleString('en-US', { timeZone: 'Asia/Jakarta' }));

  const hh = String(wib.getHours()).padStart(2,'0');
  const mm = String(wib.getMinutes()).padStart(2,'0');
  const ss = String(wib.getSeconds()).padStart(2,'0');
  document.getElementById('liveClock').textContent = hh + ':' + mm + ':' + ss;

  const days   = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
  const months = ['January','February','March','April','May','June','July','August','September','October','November','December'];
  document.getElementById('liveDate').textContent =
    days[wib.getDay()] + ', ' + String(wib.getDate()).padStart(2,'0') + ' ' +
    months[wib.getMonth()] + ' ' + wib.getFullYear();
}
updateClock();
setInterval(updateClock, 1000);
</script>

<script>
function openAisle(aisle) {
  console.log('openAisle called:', aisle);
  
  const old = document.getElementById('aisleModal');
  if (old) old.remove();

  
  const modal = document.createElement('div');
  modal.id = 'aisleModal';
  modal.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.5);' +
    'z-index:2147483647;display:flex;align-items:flex-start;justify-content:center;' +
    'padding:16px 12px 0 12px;overflow-y:auto';
  modal.onclick = function(e){ if(e.target===modal) closeAisle(); };

  modal.innerHTML =
    '<div style="background:#fff;border-radius:14px;width:100%;max-width:1200px;' +
    'box-shadow:0 20px 60px rgba(0,0,0,.3);overflow:hidden;display:flex;flex-direction:column;max-height:90vh">' +
      '<div style="padding:16px 22px;display:flex;align-items:center;justify-content:space-between;' +
           'background:linear-gradient(135deg,#026766,#026766);color:#fff">' +
        '<div>' +
          '<div id="aisleModalTitle" style="font-size:1.1rem;font-weight:800">Aisle ' + aisle + '</div>' +
          '<div id="aisleModalSub" style="font-size:.75rem;opacity:.8;margin-top:2px">Memuat...</div>' +
        '</div>' +
        '<button onclick="closeAisle()" style="background:rgba(255,255,255,.2);border:none;' +
          'border-radius:8px;color:#fff;padding:6px 14px;cursor:pointer;font-size:.85rem">✕ Tutup</button>' +
      '</div>' +
      '<div style="padding:10px 22px;background:#f8faff;border-bottom:1px solid #cce8e8;' +
           'display:flex;gap:14px;font-size:.72rem;flex-wrap:wrap">' +
        '<span><span style="display:inline-block;width:12px;height:12px;background:#c8e6c9;' +
          'border:1px solid #66bb6a;border-radius:2px;vertical-align:middle;margin-right:3px"></span>Terisi</span>' +
        '<span><span style="display:inline-block;width:12px;height:12px;background:#fff9c4;' +
          'border:1px solid #ffd54f;border-radius:2px;vertical-align:middle;margin-right:3px"></span>Eceran/Partial</span>' +
        '<span><span style="display:inline-block;width:12px;height:12px;background:#f1f5f9;' +
          'border:1px solid #dde4ee;border-radius:2px;vertical-align:middle;margin-right:3px"></span>Kosong</span>' +
        '<span style="margin-left:auto;color:#546e7a">Level A = Eceran | B–E = Full Pallet</span>' +
      '</div>' +
      '<div id="aisleModalBody" style="padding:16px 22px;flex:1;overflow-y:auto;' +
           'display:flex;align-items:flex-start;justify-content:flex-start">'  +
        '<div style="text-align:center;color:#90a4ae">' +
          '<div style="font-size:1.5rem;margin-bottom:8px">⏳</div>' +
          'Memuat data lokasi...</div>' +
      '</div>' +
    '</div>';

  (document.getElementById('modal-root') || document.body).appendChild(modal);
  document.body.style.overflow = 'hidden';

  fetch('<?= BASE_URL ?>/dashboard.php?action=aisle_detail&aisle=' + encodeURIComponent(aisle))
    .then(r => {
      if (!r.ok) throw new Error('HTTP ' + r.status);
      return r.text();
    })
    .then(txt => {
      let data;
      try { data = JSON.parse(txt); }
      catch(e) { throw new Error('JSON parse error: ' + txt.substr(0,100)); }
      if (!data.success) throw new Error(data.message || 'API error');
      document.getElementById('aisleModalSub').textContent =
        data.stats.occupied + ' terisi / ' + data.stats.total + ' lokasi' +
        (data.stats.total_qty > 0 ? ' · ' + data.stats.total_qty + ' qty' : '');
      renderAisleDetail(aisle, data);
    })
    .catch(err => {
      document.getElementById('aisleModalBody').innerHTML =
        '<div style="text-align:center;color:#026766;padding:30px">' +
        '<div style="font-size:1.5rem;margin-bottom:8px">⚠️</div>' +
        'Error: ' + err.message + '</div>';
    });
}

function renderAisleDetail(aisle, data) {
  const locs  = data.locations || [];
  if (!locs.length) {
    document.getElementById('aisleModalBody').innerHTML =
      '<div style="text-align:center;color:#90a4ae;padding:30px">Tidak ada lokasi</div>';
    return;
  }

  const allRows  = [...new Set(locs.map(l => l.row_name||'A'))].sort();
  const allRacks = [...new Set(locs.map(l => l.rack||'00'))].sort();

  
  const idx = {};
  locs.forEach(l => {
    const k = (l.rack||'00')+'|'+(l.row_name||'A');
    if (!idx[k]) idx[k] = [];
    idx[k].push(l);
  });

  function box(l) {
    const has = l.qty > 0;
    const isEceran = l.row_name === 'A';
    let bg, bd, tc;
    if (!has)        { bg='#f1f5f9'; bd='#dde4ee'; tc='#b0bec5'; }
    else if(isEceran){ bg='#fff9c4'; bd='#ffd54f'; tc='#b45309'; }
    else             { bg='#c8e6c9'; bd='#66bb6a'; tc='#013d3c'; }
    const sh = l.code.slice(-3);
    const en = encodeURIComponent(JSON.stringify(l));
    return '<div onmouseenter="showLocPop(event,\''+en+'\')" onmouseleave="hideLocPop()" '+
      'style="width:44px;height:44px;border-radius:6px;border:1px solid '+bd+';background:'+bg+';'+
      'color:'+tc+';display:flex;align-items:center;justify-content:center;'+
      'font-size:.62rem;font-weight:700;cursor:'+(has?'pointer':'default')+';'+
      'transition:transform .1s;font-family:monospace" '+
      'onmouseover="this.style.transform=\'scale(1.15)\'" '+
      'onmouseout="this.style.transform=\'\'">'+sh+'</div>';
  }

  
  
  let html = '<div style="display:flex;flex-wrap:wrap;gap:14px;padding:4px 0">';

  allRacks.forEach(rack => {
    
    const hasAny = allRows.some(row => (idx[rack+'|'+row]||[]).some(l => l.qty > 0));

    html += '<div style="background:#fafbff;border:1px solid '+(hasAny?'#c7d2fe':'#eef0f5')+';'+
            'border-radius:10px;padding:10px 8px;min-width:fit-content">';

    
    html += '<div style="font-size:.7rem;font-weight:700;color:#546e7a;text-align:center;'+
            'margin-bottom:8px;letter-spacing:.04em">R'+rack+'</div>';

    
    allRows.forEach(row => {
      const cells = idx[rack+'|'+row] || [];
      const isEceran = row === 'A';

      html += '<div style="display:flex;align-items:center;gap:4px;margin-bottom:3px">';

      
      html += '<span style="font-size:.68rem;font-weight:800;width:14px;text-align:center;'+
              'flex-shrink:0;color:'+(isEceran?'#b45309':'#6b7280')+'">'+row+'</span>';

      
      if (!cells.length) {
        html += '<div style="width:44px;height:44px;border-radius:6px;'+
                'background:#f1f5f9;border:1px dashed #e2e8f0"></div>';
      } else {
        cells.forEach(l => { html += box(l); });
      }
      html += '</div>';
    });

    html += '</div>'; 
  });

  html += '</div>'; 
  document.getElementById('aisleModalBody').innerHTML = html;
}

function closeAisle() {
  const m = document.getElementById('aisleModal');
  if (m) m.remove();
  document.body.style.overflow = '';
  const p = document.getElementById('ldpop');
  if (p) p.remove();
}

function showLocPop(e, encoded) {
  try {
    const l = JSON.parse(decodeURIComponent(encoded));
    let pop = document.getElementById('ldpop');
    if (!pop) {
      pop = document.createElement('div');
      pop.id = 'ldpop';
      pop.style.cssText = 'position:fixed;z-index:2147483647;background:#fff;border:1px solid #cce8e8;' +
        'border-radius:10px;padding:12px 16px;box-shadow:0 8px 30px rgba(0,0,0,.18);' +
        'min-width:200px;max-width:260px;font-size:.78rem;pointer-events:none';
      (document.getElementById('modal-root') || document.body).appendChild(pop);
    }
    let body = '<div style="font-weight:800;color:#026766;font-family:monospace;font-size:.9rem;' +
               'margin-bottom:6px;border-bottom:1px solid #f1f5f9;padding-bottom:5px">' + l.code + '</div>';
    if (l.qty > 0) {
      body += '<div style="font-weight:600;color:#1a1a1a;margin-bottom:3px">' + (l.product||'—') + '</div>';
      body += '<div style="font-size:.7rem;color:#90a4ae;font-family:monospace;margin-bottom:5px">' + (l.product_code||'') + '</div>';
      body += '<div style="display:flex;gap:8px;flex-wrap:wrap;font-size:.74rem">';
      body += '<b>' + l.qty + ' ' + (l.uom||'') + '</b>';
      if (l.pallet > 0) body += '<span style="color:#546e7a">' + (l.is_eceran ? l.pallet.toFixed(2) : l.pallet) + ' plt</span>';
      if (l.batch) body += '<span style="font-family:monospace;color:#014f4e">' + l.batch + '</span>';
      if (l.expiry) body += '<span style="color:#546e7a">' + l.expiry + '</span>';
      body += '</div>';
      const isEceran2 = l.row_name === 'A';
    body += '<div style="margin-top:6px;font-size:.68rem;' + (isEceran2 ?
        'background:#fff3e0;color:#e65100' : 'background:#e8f5e9;color:#026766') +
        ';border-radius:4px;padding:2px 7px;display:inline-block">' +
        (isEceran2 ? '⚡ Eceran' : '📦 Full Pallet') + '</div>';
    } else {
      body += '<div style="color:#90a4ae;padding:4px 0">Lokasi kosong</div>';
    }
    pop.innerHTML = body;
    pop.style.display = 'block';
    const vw = window.innerWidth, vh = window.innerHeight;
    let x = e.clientX + 14, y = e.clientY + 14;
    if (x + 265 > vw) x = e.clientX - 270;
    if (y + 180 > vh) y = e.clientY - 185;
    pop.style.left = x + 'px'; pop.style.top = y + 'px';
  } catch(er) {}
}
function hideLocPop() {
  const p = document.getElementById('ldpop');
  if (p) p.style.display = 'none';
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

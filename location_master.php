<?php
session_start();
header("Cache-Control: no-store, no-cache, must-revalidate");
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/classes/Auth.php';
require_once __DIR__ . '/classes/LocationManager.php';
Auth::requireAuth();
$canWrite = Auth::canWrite();
$canAdmin = Auth::canAdmin();

$pageTitle   = 'Master Lokasi';
$currentPage = 'location_master';

$ZONE_OPTIONS = ['Bulk','Carton','Pallet','Rack','Pail','Special','Quarantine','General'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::requireAdmin(); // hard-stop non-admins immediately
    $qp = array_filter([
        'q'     => $_GET['q']     ?? '',
        'zone'  => $_GET['zone']  ?? '',
        'aisle' => $_GET['aisle'] ?? '',
        'avail' => $_GET['avail'] ?? '',
        'page'  => $_GET['page']  ?? '',
    ]);
    try {
        $db = db();

        if (isset($_POST['toggle_active'])) {
            $db->prepare("UPDATE location_master SET is_active=? WHERE id=?")
               ->execute([(int)$_POST['is_active'], (int)$_POST['loc_id']]);
            header('Location: location_master.php?' . http_build_query(array_merge($qp, ['success'=>'updated'])));
            exit;
        }

        if (isset($_POST['add_location'])) {
            $code = strtoupper(trim($_POST['location_code']));
            if (!$code) throw new Exception("Location code diperlukan");
            $chk = $db->prepare("SELECT id FROM location_master WHERE location_code=?");
            $chk->execute([$code]);
            if ($chk->fetch()) throw new Exception("Kode lokasi '{$code}' sudah ada");
            $db->prepare("INSERT INTO location_master (location_code,aisle,rack,row_name,position,zone) VALUES (?,?,?,?,?,?)")
               ->execute([$code,
                   strtoupper(trim($_POST['aisle']    ?? '')),
                   trim($_POST['rack']      ?? ''),
                   strtoupper(trim($_POST['row_name'] ?? '')),
                   trim($_POST['position']  ?? ''),
                   $_POST['zone'] ?? 'Bulk',
               ]);
            header('Location: location_master.php?success=added');
            exit;
        }

        if (isset($_POST['edit_location'])) {
            $id   = intval($_POST['loc_id']);
            $code = strtoupper(trim($_POST['location_code']));
            if (!$code || !$id) throw new Exception("Data tidak lengkap");
            $chk = $db->prepare("SELECT id FROM location_master WHERE location_code=? AND id!=?");
            $chk->execute([$code, $id]);
            if ($chk->fetch()) throw new Exception("Kode lokasi '{$code}' sudah dipakai lokasi lain");
            $db->prepare("UPDATE location_master SET location_code=?,aisle=?,rack=?,row_name=?,position=?,zone=? WHERE id=?")
               ->execute([$code,
                   strtoupper(trim($_POST['aisle']    ?? '')),
                   trim($_POST['rack']      ?? ''),
                   strtoupper(trim($_POST['row_name'] ?? '')),
                   trim($_POST['position']  ?? ''),
                   $_POST['zone'] ?? 'Bulk',
                   $id,
               ]);
            header('Location: location_master.php?' . http_build_query(array_merge($qp, ['success'=>'updated'])));
            exit;
        }

        if (isset($_POST['delete_location'])) {
            $id   = intval($_POST['loc_id']);
            $code = trim($_POST['del_code']);
            $chk  = $db->prepare("SELECT COUNT(*) FROM stock_locations WHERE location_code=? AND status IN ('Available','Reserved')");
            $chk->execute([$code]);
            if ($chk->fetchColumn() > 0) throw new Exception("Lokasi '{$code}' masih terisi stok, tidak bisa dihapus");
            $db->prepare("DELETE FROM location_master WHERE id=?")->execute([$id]);
            header('Location: location_master.php?' . http_build_query(array_merge($qp, ['success'=>'deleted'])));
            exit;
        }

    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

$db        = db();
$search    = trim($_GET['q']     ?? '');
$zoneF     = trim($_GET['zone']  ?? '');
$aisleF    = trim($_GET['aisle'] ?? '');
$onlyAvail = ($_GET['avail']     ?? '') === '1';
$page      = max(1, intval($_GET['page'] ?? 1));
$perPage   = 50;

$where  = " WHERE 1=1";
$params = [];
if ($search) {
    $where  .= " AND lm.location_code LIKE ?";
    $params[] = "%{$search}%";
}
if ($zoneF) {
    $where  .= " AND lm.zone = ?";
    $params[] = $zoneF;
}
if ($aisleF) {
    $where  .= " AND lm.aisle = ?";
    $params[] = $aisleF;
}
if ($onlyAvail) {
    $where .= " AND NOT EXISTS (SELECT 1 FROM stock_locations sl
                WHERE sl.location_code=lm.location_code AND sl.status IN ('Available','Reserved'))";
}

$cs = $db->prepare("SELECT COUNT(*) FROM location_master lm" . $where);
$cs->execute($params);
$totalCount = (int)$cs->fetchColumn();
$totalPages = max(1, (int)ceil($totalCount / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

$filterQs = http_build_query(array_filter([
    'q'     => $search,
    'zone'  => $zoneF,
    'aisle' => $aisleF,
    'avail' => $onlyAvail ? '1' : '',
    'page'  => $page > 1 ? (string)$page : '',
]));

$sql = "SELECT lm.*,
            CASE WHEN sl.id IS NOT NULL THEN 'Occupied' ELSE 'Available' END AS availability,
            sl.batch_number as current_batch,
            sl.quantity as current_qty,
            sl.uom as current_uom,
            p.product_name as current_product
        FROM location_master lm
        LEFT JOIN stock_locations sl ON sl.location_code = lm.location_code
            AND sl.status IN ('Available','Reserved')
        LEFT JOIN stock st ON sl.stock_id = st.id
        LEFT JOIN products p ON st.product_id = p.id"
    . $where
    . " ORDER BY lm.aisle, lm.rack, lm.row_name, lm.position LIMIT ? OFFSET ?";
$stmt = $db->prepare($sql);
$stmt->execute(array_merge($params, [$perPage, $offset]));
$locations = $stmt->fetchAll();

$summary = LocationManager::getZoneSummary();
$zones   = array_column($summary, 'zone');

$aisles = $db->query("SELECT DISTINCT aisle FROM location_master
                      WHERE aisle IS NOT NULL AND aisle != ''
                      ORDER BY aisle")
             ->fetchAll(PDO::FETCH_COLUMN);

require_once __DIR__ . '/includes/header.php';
?>

<style>
:root {
  --lm-primary: #026766;
  --lm-border:  #cce8e8;
  --lm-bg:      #f5f8fc;
  --lm-card:    #ffffff;
  --lm-radius:  10px;
  --lm-shadow:  0 2px 12px rgba(2,103,102,.08);
}
.lm-page { font-family:'Segoe UI',system-ui,sans-serif; background:var(--lm-bg); }
.lm-hero {
  background:linear-gradient(135deg,#026766 0%,#014f4e 60%,#026766 100%);
  border-radius:var(--lm-radius); padding:22px 28px; color:#fff;
  display:flex; align-items:center; justify-content:space-between;
  gap:16px; box-shadow:0 4px 20px rgba(2,103,102,.25); margin-bottom:18px;
  flex-wrap:wrap;
}
.lm-hero-title { font-size:1.4rem; font-weight:700; }
.lm-stat-pill {
  background:rgba(255,255,255,.15); border:1px solid rgba(255,255,255,.25);
  border-radius:8px; padding:8px 14px; text-align:center; min-width:70px;
}
.lm-stat-pill .num { font-size:1.3rem; font-weight:700; line-height:1; }
.lm-stat-pill .lbl { font-size:.7rem; opacity:.8; margin-top:2px; }
.lm-card {
  background:var(--lm-card); border-radius:var(--lm-radius);
  border:1px solid var(--lm-border); box-shadow:var(--lm-shadow);
  margin-bottom:18px; overflow:hidden;
}
.lm-card-header {
  padding:13px 20px; border-bottom:1px solid var(--lm-border);
  display:flex; align-items:center; justify-content:space-between;
  background:#fafcff;
}
.lm-card-header h2 { font-size:.95rem; font-weight:700; color:var(--lm-primary); margin:0; }
.zone-cards { display:grid; grid-template-columns:repeat(auto-fill,minmax(150px,1fr)); gap:12px; margin-bottom:18px; }
.zone-card { background:#fff; border-radius:var(--lm-radius); border:1px solid var(--lm-border);
             box-shadow:var(--lm-shadow); padding:14px; text-align:center; cursor:pointer;
             transition:box-shadow .15s,border-color .15s; }
.zone-card:hover,.zone-card.active { border-color:#026766; box-shadow:0 0 0 2px rgba(2,103,102,.2); }
.zone-card .zc-num { font-size:1.2rem; font-weight:700; color:var(--lm-primary); }
.zone-card .zc-sub { font-size:.72rem; color:#90a4ae; }
.zone-card .zc-bar { margin-top:8px; height:5px; background:#cce8e8; border-radius:4px; overflow:hidden; }
.zone-card .zc-fill { height:100%; border-radius:4px; transition:width .3s; }
.zone-card .zc-pct { font-size:.7rem; color:#90a4ae; margin-top:3px; }
.lm-table { width:100%; border-collapse:collapse; font-size:.83rem; }
.lm-table th {
  background:#f1f5fb; padding:9px 12px; text-align:left;
  font-size:.75rem; font-weight:700; text-transform:uppercase;
  letter-spacing:.04em; color:#546e7a; white-space:nowrap;
}
.lm-table td { padding:8px 12px; border-bottom:1px solid #e6f7f7; vertical-align:middle; }
.lm-table tbody tr { cursor:pointer; transition:background .1s; }
.lm-table tbody tr:hover td { background:#f0fbfb; }
.lm-table tbody tr.row-inactive { opacity:.45; }
.badge-avail { background:#e0f7f7; color:#026766; border-radius:20px; padding:2px 10px; font-size:.73rem; font-weight:700; white-space:nowrap; }
.badge-occ   { background:#fff3e0; color:#e65100; border-radius:20px; padding:2px 10px; font-size:.73rem; font-weight:700; white-space:nowrap; }
.badge-inactive { background:#f5f5f5; color:#9e9e9e; border-radius:20px; padding:2px 10px; font-size:.73rem; font-weight:700; }
.badge-zone  { border-radius:6px; padding:2px 8px; font-size:.72rem; font-weight:700; white-space:nowrap; }
.zone-Bulk       { background:#e0f7f7; color:#014f4e; }
.zone-Carton     { background:#dbeafe; color:#1e40af; }
.zone-Pallet     { background:#e0f7f7; color:#014f4e; }
.zone-Rack       { background:#dbeafe; color:#1e40af; }
.zone-Pail       { background:#fef9c3; color:#854d0e; }
.zone-Special    { background:#fff8e1; color:#e65100; }
.zone-Quarantine { background:#fee2e2; color:#991b1b; }
.zone-General    { background:#f5f5f5; color:#424242; }
.lm-input {
  border:1px solid var(--lm-border); border-radius:7px; padding:7px 11px;
  font-size:.83rem; outline:none; transition:border .15s; background:#fff;
}
.lm-input:focus { border-color:#026766; box-shadow:0 0 0 3px rgba(2,103,102,.1); }
.lm-btn {
  border:none; border-radius:7px; padding:7px 16px; font-size:.82rem;
  cursor:pointer; font-weight:600; transition:opacity .15s,background .15s;
  display:inline-flex; align-items:center; gap:5px;
}
.lm-btn:hover { opacity:.88; }
.btn-primary  { background:#026766; color:#fff; }
.btn-success  { background:#2e7d32; color:#fff; }
.btn-danger   { background:#c62828; color:#fff; }
.btn-warning  { background:#e65100; color:#fff; }
.btn-muted    { background:#e0f7f7; color:#546e7a; }
.btn-sm       { padding:4px 9px; font-size:.75rem; }
.btn-deactivate { background:#fff3e0; color:#e65100; border:1px solid #ffcc80; }
.btn-activate   { background:#e8f5e9; color:#2e7d32; border:1px solid #a5d6a7; }
.btn-deactivate:hover { background:#ffe0b2; }
.btn-activate:hover   { background:#c8e6c9; }
.lm-pagination { display:flex; align-items:center; gap:6px; flex-wrap:wrap; }
.pg-btn {
  border:1px solid var(--lm-border); border-radius:6px; padding:5px 10px;
  font-size:.8rem; cursor:pointer; background:#fff; color:#546e7a;
  transition:all .15s; text-decoration:none; display:inline-flex; align-items:center;
}
.pg-btn:hover { border-color:#026766; color:#026766; }
.pg-btn.active { background:#026766; color:#fff; border-color:#026766; font-weight:700; }
.pg-btn.disabled { opacity:.4; pointer-events:none; }
.pg-info { font-size:.8rem; color:#90a4ae; margin-left:4px; }
.lm-modal-overlay {
  display:none; position:fixed; inset:0; background:rgba(0,0,0,.45);
  backdrop-filter:blur(4px); -webkit-backdrop-filter:blur(4px);
  z-index:9999; align-items:center; justify-content:center; padding:16px;
}
.lm-modal-overlay.open { display:flex; }
.lm-modal {
  background:#fff; border-radius:12px; width:100%; max-height:90vh;
  overflow-y:auto; box-shadow:0 12px 40px rgba(0,0,0,.2);
}
.lm-modal-header {
  padding:18px 24px 14px; border-bottom:1px solid var(--lm-border);
  display:flex; align-items:center; justify-content:space-between;
  position:sticky; top:0; background:#fff; z-index:1;
}
.lm-modal-header h3 { margin:0; font-size:1rem; font-weight:700; color:#026766; }
.lm-modal-body { padding:20px 24px; }
.lm-modal-footer { padding:14px 24px; border-top:1px solid var(--lm-border); display:flex; gap:10px; justify-content:flex-end; }
.modal-close { background:none; border:none; font-size:1.2rem; cursor:pointer; color:#90a4ae; line-height:1; padding:4px; }
.modal-close:hover { color:#026766; }
.detail-grid { display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:16px; }
.detail-item label { font-size:.72rem; font-weight:700; text-transform:uppercase; color:#90a4ae; display:block; margin-bottom:3px; }
.detail-item .val { font-size:.88rem; color:#263238; font-weight:500; }
.detail-stock-box { background:#f5f8fc; border:1px solid var(--lm-border); border-radius:8px; padding:12px; }
.detail-stock-box h4 { font-size:.8rem; font-weight:700; color:#546e7a; margin:0 0 10px; text-transform:uppercase; letter-spacing:.05em; }
</style>

<div class="lm-page container-fluid py-3">

<div class="lm-hero">
  <div>
    <div class="lm-hero-title"><i class="fas fa-map-marked-alt mr-2"></i>Master Lokasi Gudang</div>
    <div style="font-size:.82rem;opacity:.8;margin-top:3px">
      CKB × Shell — <?= number_format(array_sum(array_column($summary,'total_locations'))) ?> total lokasi
    </div>
  </div>
  <div style="display:flex;gap:10px;flex-wrap:wrap">
    <?php foreach ($summary as $s): ?>
    <div class="lm-stat-pill">
      <div class="num"><?= number_format($s['available']) ?></div>
      <div class="lbl"><?= htmlspecialchars($s['zone']) ?><br>available</div>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<?php if (!empty($error)): ?>
<div style="background:#fff3e0;border:1px solid #ffcc80;border-radius:8px;padding:10px 16px;margin-bottom:14px;color:#e65100">
    <i class="fas fa-exclamation-circle mr-1"></i> <?= htmlspecialchars($error) ?>
</div>
<?php endif; ?>

<?php if (isset($_GET['success'])): ?>
<?php $msgs = ['added'=>'Lokasi berhasil ditambahkan','updated'=>'Lokasi berhasil diupdate','deleted'=>'Lokasi berhasil dihapus']; ?>
<div style="background:#e8f5e9;border:1px solid #a5d6a7;border-radius:8px;padding:10px 16px;margin-bottom:14px;color:#2e7d32">
    <i class="fas fa-check-circle mr-1"></i> <?= $msgs[$_GET['success']] ?? 'Berhasil' ?>
</div>
<?php endif; ?>

<div class="zone-cards">
<?php foreach ($summary as $s):
    $pct = $s['total_locations'] > 0 ? round($s['occupied'] / $s['total_locations'] * 100) : 0;
    $fillColor = $pct > 80 ? '#c62828' : ($pct > 50 ? '#e65100' : '#026766');
?>
<div class="zone-card <?= $zoneF===$s['zone']?'active':'' ?>"
     onclick="setZoneFilter('<?= htmlspecialchars($s['zone']) ?>')">
    <div><span class="badge-zone zone-<?= htmlspecialchars($s['zone']) ?>"><?= htmlspecialchars($s['zone']) ?></span></div>
    <div class="zc-num" style="margin-top:6px"><?= number_format($s['available']) ?></div>
    <div class="zc-sub">available / <?= number_format($s['total_locations']) ?></div>
    <div class="zc-bar"><div class="zc-fill" style="width:<?= $pct ?>%;background:<?= $fillColor ?>"></div></div>
    <div class="zc-pct"><?= $pct ?>% terisi</div>
</div>
<?php endforeach; ?>
</div>

<div class="lm-card">
<div class="lm-card-header">
    <h2><i class="fas fa-filter mr-2"></i>Filter Lokasi</h2>
    <?php if ($canAdmin): ?>
    <button class="lm-btn btn-primary btn-sm" onclick="openAddModal()">
        <i class="fas fa-plus"></i> Tambah Lokasi
    </button>
    <?php endif; ?>
</div>
<div style="padding:14px 20px">
<form method="GET" id="filterForm" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
    <div>
        <label style="font-size:.72rem;font-weight:700;color:#546e7a;display:block;margin-bottom:3px;text-transform:uppercase">Kode Lokasi</label>
        <input type="text" name="q" class="lm-input" placeholder="Cari kode…"
               value="<?= htmlspecialchars($search) ?>" style="width:160px">
    </div>
    <?php if (count($aisles) > 1): ?>
    <div>
        <label style="font-size:.72rem;font-weight:700;color:#546e7a;display:block;margin-bottom:3px;text-transform:uppercase">Aisle</label>
        <select name="aisle" class="lm-input" style="width:110px">
            <option value="">Semua Aisle</option>
            <?php foreach ($aisles as $a): ?>
            <option value="<?= htmlspecialchars($a) ?>" <?= $aisleF===$a?'selected':'' ?>><?= htmlspecialchars($a) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php endif; ?>
    <div>
        <label style="font-size:.72rem;font-weight:700;color:#546e7a;display:block;margin-bottom:3px;text-transform:uppercase">Zone</label>
        <select name="zone" id="zoneSelect" class="lm-input" style="width:130px">
            <option value="">Semua Zone</option>
            <?php foreach ($zones as $z): ?>
            <option value="<?= htmlspecialchars($z) ?>" <?= $zoneF===$z?'selected':'' ?>><?= htmlspecialchars($z) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div style="display:flex;align-items:flex-end">
        <label style="display:flex;align-items:center;gap:6px;font-size:.82rem;cursor:pointer;padding-bottom:2px">
            <input type="checkbox" name="avail" value="1" <?= $onlyAvail?'checked':'' ?>> Available saja
        </label>
    </div>
    <div style="display:flex;align-items:flex-end;gap:6px">
        <button type="submit" class="lm-btn btn-primary"><i class="fas fa-search"></i> Cari</button>
        <a href="location_master.php" class="lm-btn btn-muted">Reset</a>
    </div>
    <div style="display:flex;align-items:flex-end;margin-left:auto">
        <span style="font-size:.78rem;color:#90a4ae">
            <?= number_format($totalCount) ?> lokasi ditemukan
            <?php if ($totalPages > 1): ?> · Hal <?= $page ?> / <?= $totalPages ?><?php endif; ?>
        </span>
    </div>
</form>

<?php if ($totalCount > 0): ?>
<div style="margin-top:10px;padding-top:10px;border-top:1px dashed #e0e0e0;display:flex;align-items:center;gap:8px">
    <i class="fas fa-bolt" style="color:#f57c00;font-size:.8rem"></i>
    <label style="font-size:.75rem;color:#90a4ae;white-space:nowrap">Filter halaman ini:</label>
    <input type="text" id="liveSearch" class="lm-input" placeholder="Ketik untuk filter baris…" style="width:200px" oninput="liveFilter(this.value)">
    <span id="liveCount" style="font-size:.75rem;color:#90a4ae"></span>
</div>
<?php endif; ?>
</div>
</div>

<div class="lm-card">
<div class="lm-card-header">
    <h2><i class="fas fa-list mr-2"></i>Daftar Lokasi</h2>
    <span style="font-size:.75rem;color:#90a4ae">Klik baris untuk detail · <?= count($locations) ?> baris di halaman ini</span>
</div>
<div style="overflow-x:auto">
<table class="lm-table">
    <thead>
        <tr>
            <th>Kode Lokasi</th>
            <th>Aisle</th>
            <th>Rack</th>
            <th>Row</th>
            <th>Zone</th>
            <th style="text-align:center">Status</th>
            <th>Batch Aktif</th>
            <th style="text-align:right">Qty</th>
            <th>Produk</th>
            <?php if ($canAdmin): ?><th style="text-align:center;min-width:100px">Aksi</th><?php endif; ?>
        </tr>
    </thead>
    <tbody id="locTbody">
    <?php foreach ($locations as $loc):
        $isActive = (bool)$loc['is_active'];
        $isOcc    = $loc['availability'] === 'Occupied';
        $locJson  = htmlspecialchars(json_encode([
            'id'            => $loc['id'],
            'location_code' => $loc['location_code'],
            'aisle'         => $loc['aisle']    ?? '',
            'rack'          => $loc['rack']     ?? '',
            'row_name'      => $loc['row_name'] ?? '',
            'position'      => $loc['position'] ?? '',
            'zone'          => $loc['zone']     ?? 'Bulk',
            'is_active'     => $loc['is_active'],
        ]), ENT_QUOTES);
    ?>
    <tr class="loc-row <?= !$isActive ? 'row-inactive' : '' ?>"
        onclick="openDetailModal('<?= htmlspecialchars($loc['location_code']) ?>')">
        <td>
            <span style="font-family:monospace;font-weight:700;color:#026766;font-size:.9rem">
                <?= htmlspecialchars($loc['location_code']) ?>
            </span>
            <?php if (!$isActive): ?>
            <span class="badge-inactive" style="margin-left:4px">Nonaktif</span>
            <?php endif; ?>
        </td>
        <td style="color:#546e7a"><?= htmlspecialchars($loc['aisle']    ?? '—') ?></td>
        <td style="color:#546e7a"><?= htmlspecialchars($loc['rack']     ?? '—') ?></td>
        <td style="color:#546e7a"><?= htmlspecialchars($loc['row_name'] ?? '—') ?></td>
        <td>
            <span class="badge-zone zone-<?= htmlspecialchars($loc['zone'] ?? 'General') ?>">
                <?= htmlspecialchars($loc['zone'] ?? '—') ?>
            </span>
        </td>
        <td style="text-align:center">
            <?php if ($isOcc): ?>
                <span class="badge-occ"><i class="fas fa-box"></i> Terisi</span>
            <?php else: ?>
                <span class="badge-avail"><i class="fas fa-check"></i> Tersedia</span>
            <?php endif; ?>
        </td>
        <td style="font-family:monospace;font-size:.8rem;color:<?= $loc['current_batch'] ? '#014f4e' : '#b0bec5' ?>">
            <?= htmlspecialchars($loc['current_batch'] ?? '—') ?>
        </td>
        <td style="text-align:right;font-weight:600;white-space:nowrap">
            <?= $loc['current_qty'] ? number_format($loc['current_qty']) . ' ' . htmlspecialchars($loc['current_uom'] ?? '') : '—' ?>
        </td>
        <td style="font-size:.78rem;color:#546e7a;max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
            <?= htmlspecialchars($loc['current_product'] ?? '—') ?>
        </td>
        <?php if ($canAdmin): ?>
        <td style="text-align:center" onclick="event.stopPropagation()">
            <button type="button" class="lm-btn btn-sm" style="margin-right:3px"
                    onclick="openEditModal(<?= $locJson ?>)" title="Edit lokasi">
                <i class="fas fa-edit"></i>
            </button>
            <form method="POST" action="location_master.php<?= $filterQs ? '?'.$filterQs : '' ?>" style="display:inline">
                <input type="hidden" name="loc_id"    value="<?= $loc['id'] ?>">
                <input type="hidden" name="is_active" value="<?= $isActive ? 0 : 1 ?>">
                <button type="submit" name="toggle_active"
                        class="lm-btn btn-sm <?= $isActive ? 'btn-deactivate' : 'btn-activate' ?>"
                        title="<?= $isActive ? 'Nonaktifkan lokasi' : 'Aktifkan lokasi' ?>">
                    <?= $isActive ? '<i class="fas fa-ban"></i>' : '<i class="fas fa-check-circle"></i>' ?>
                </button>
            </form>
        </td>
        <?php endif; ?>
    </tr>
    <?php endforeach; ?>
    <?php if (empty($locations)): ?>
    <tr>
        <td colspan="<?= $canAdmin ? 10 : 9 ?>" style="text-align:center;padding:40px;color:#90a4ae">
            <i class="fas fa-search" style="font-size:2rem;display:block;margin-bottom:10px;opacity:.4"></i>
            Tidak ada lokasi yang cocok dengan filter
        </td>
    </tr>
    <?php endif; ?>
    </tbody>
</table>
</div>

<?php if ($totalPages > 1): ?>
<div style="padding:14px 20px;border-top:1px solid var(--lm-border);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px">
    <span class="pg-info">
        Menampilkan <?= number_format(($page-1)*$perPage+1) ?>–<?= number_format(min($page*$perPage,$totalCount)) ?>
        dari <?= number_format($totalCount) ?> lokasi
    </span>
    <div class="lm-pagination">
        <?php
        $qs = array_filter(['q'=>$search,'zone'=>$zoneF,'aisle'=>$aisleF,'avail'=>$onlyAvail?'1':'']);
        ?>
        <a href="?<?= http_build_query(array_merge($qs,['page'=>1])) ?>"
           class="pg-btn <?= $page<=1?'disabled':'' ?>"><i class="fas fa-angle-double-left"></i></a>
        <a href="?<?= http_build_query(array_merge($qs,['page'=>max(1,$page-1)])) ?>"
           class="pg-btn <?= $page<=1?'disabled':'' ?>"><i class="fas fa-angle-left"></i></a>
        <?php
        $start = max(1, $page - 2);
        $end   = min($totalPages, $page + 2);
        if ($start > 1) echo '<span class="pg-info">…</span>';
        for ($p = $start; $p <= $end; $p++):
        ?>
        <a href="?<?= http_build_query(array_merge($qs,['page'=>$p])) ?>"
           class="pg-btn <?= $p===$page?'active':'' ?>"><?= $p ?></a>
        <?php endfor;
        if ($end < $totalPages) echo '<span class="pg-info">…</span>'; ?>
        <a href="?<?= http_build_query(array_merge($qs,['page'=>min($totalPages,$page+1)])) ?>"
           class="pg-btn <?= $page>=$totalPages?'disabled':'' ?>"><i class="fas fa-angle-right"></i></a>
        <a href="?<?= http_build_query(array_merge($qs,['page'=>$totalPages])) ?>"
           class="pg-btn <?= $page>=$totalPages?'disabled':'' ?>"><i class="fas fa-angle-double-right"></i></a>
    </div>
</div>
<?php endif; ?>

</div>
</div>

<?php if ($canAdmin): ?>
<div id="addLocModal" class="lm-modal-overlay">
<div class="lm-modal" style="max-width:440px">
    <div class="lm-modal-header">
        <h3><i class="fas fa-map-pin mr-2"></i>Tambah Lokasi Baru</h3>
        <button class="modal-close" onclick="closeModal('addLocModal')"><i class="fas fa-times"></i></button>
    </div>
    <form method="POST">
    <div class="lm-modal-body">
        <div style="margin-bottom:14px">
            <label style="font-size:.8rem;font-weight:600;display:block;margin-bottom:4px">
                Kode Lokasi <span style="color:red">*</span>
            </label>
            <input type="text" name="location_code" id="addCode" class="lm-input" style="width:100%"
                   placeholder="CA01A03" required oninput="autoParseCode(this.value,'add')">
            <div style="font-size:.72rem;color:#90a4ae;margin-top:3px">
                Format: [Aisle][Rack][Row][Pos] — contoh: CA01A03 atau CB02B01
            </div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:14px">
            <div>
                <label style="font-size:.8rem;font-weight:600;display:block;margin-bottom:4px">Aisle</label>
                <input type="text" name="aisle" id="add_aisle" class="lm-input" style="width:100%" placeholder="CA" maxlength="5">
            </div>
            <div>
                <label style="font-size:.8rem;font-weight:600;display:block;margin-bottom:4px">Rack</label>
                <input type="text" name="rack" id="add_rack" class="lm-input" style="width:100%" placeholder="01" maxlength="5">
            </div>
            <div>
                <label style="font-size:.8rem;font-weight:600;display:block;margin-bottom:4px">Row</label>
                <input type="text" name="row_name" id="add_row" class="lm-input" style="width:100%" placeholder="A" maxlength="5">
            </div>
            <div>
                <label style="font-size:.8rem;font-weight:600;display:block;margin-bottom:4px">Position</label>
                <input type="text" name="position" id="add_pos" class="lm-input" style="width:100%" placeholder="03" maxlength="5">
            </div>
        </div>
        <div>
            <label style="font-size:.8rem;font-weight:600;display:block;margin-bottom:4px">Zone</label>
            <select name="zone" id="add_zone" class="lm-input" style="width:100%">
                <?php foreach ($ZONE_OPTIONS as $z): ?>
                <option value="<?= $z ?>"><?= $z ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    <div class="lm-modal-footer">
        <button type="button" onclick="closeModal('addLocModal')" class="lm-btn btn-muted">Batal</button>
        <button type="submit" name="add_location" class="lm-btn btn-primary">
            <i class="fas fa-save"></i> Simpan
        </button>
    </div>
    </form>
</div>
</div>

<div id="editLocModal" class="lm-modal-overlay">
<div class="lm-modal" style="max-width:440px">
    <div class="lm-modal-header">
        <h3><i class="fas fa-edit mr-2"></i>Edit Lokasi</h3>
        <button class="modal-close" onclick="closeModal('editLocModal')"><i class="fas fa-times"></i></button>
    </div>
    <form method="POST" action="location_master.php<?= $filterQs ? '?'.$filterQs : '' ?>" id="editLocForm">
    <input type="hidden" name="loc_id" id="edit_id">
    <div class="lm-modal-body">
        <div style="margin-bottom:14px">
            <label style="font-size:.8rem;font-weight:600;display:block;margin-bottom:4px">
                Kode Lokasi <span style="color:red">*</span>
            </label>
            <input type="text" name="location_code" id="edit_code" class="lm-input" style="width:100%"
                   required oninput="autoParseCode(this.value,'edit')">
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:14px">
            <div>
                <label style="font-size:.8rem;font-weight:600;display:block;margin-bottom:4px">Aisle</label>
                <input type="text" name="aisle" id="edit_aisle" class="lm-input" style="width:100%" maxlength="5">
            </div>
            <div>
                <label style="font-size:.8rem;font-weight:600;display:block;margin-bottom:4px">Rack</label>
                <input type="text" name="rack" id="edit_rack" class="lm-input" style="width:100%" maxlength="5">
            </div>
            <div>
                <label style="font-size:.8rem;font-weight:600;display:block;margin-bottom:4px">Row</label>
                <input type="text" name="row_name" id="edit_row" class="lm-input" style="width:100%" maxlength="5">
            </div>
            <div>
                <label style="font-size:.8rem;font-weight:600;display:block;margin-bottom:4px">Position</label>
                <input type="text" name="position" id="edit_pos" class="lm-input" style="width:100%" maxlength="5">
            </div>
        </div>
        <div>
            <label style="font-size:.8rem;font-weight:600;display:block;margin-bottom:4px">Zone</label>
            <select name="zone" id="edit_zone" class="lm-input" style="width:100%">
                <?php foreach ($ZONE_OPTIONS as $z): ?>
                <option value="<?= $z ?>"><?= $z ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    <div class="lm-modal-footer">
        <button type="button" onclick="confirmDelete()"
                class="lm-btn btn-danger btn-sm" style="margin-right:auto">
            <i class="fas fa-trash"></i> Hapus
        </button>
        <button type="button" onclick="closeModal('editLocModal')" class="lm-btn btn-muted">Batal</button>
        <button type="submit" name="edit_location" class="lm-btn btn-primary">
            <i class="fas fa-save"></i> Simpan
        </button>
    </div>
    </form>
</div>
</div>

<form id="deleteLocForm" method="POST" action="location_master.php<?= $filterQs ? '?'.$filterQs : '' ?>">
    <input type="hidden" name="loc_id"   id="del_id">
    <input type="hidden" name="del_code" id="del_code">
    <button type="submit" name="delete_location" style="display:none"></button>
</form>
<?php endif; ?>

<div id="detailModal" class="lm-modal-overlay">
<div class="lm-modal" style="max-width:480px">
    <div class="lm-modal-header">
        <h3 id="detailTitle"><i class="fas fa-map-marker-alt mr-2"></i>Detail Lokasi</h3>
        <button class="modal-close" onclick="closeModal('detailModal')"><i class="fas fa-times"></i></button>
    </div>
    <div class="lm-modal-body" id="detailBody">
        <div style="text-align:center;padding:30px;color:#90a4ae">
            <i class="fas fa-spinner fa-spin" style="font-size:1.5rem"></i>
            <div style="margin-top:8px;font-size:.85rem">Memuat detail…</div>
        </div>
    </div>
    <?php if ($canAdmin): ?>
    <div class="lm-modal-footer">
        <button type="button" onclick="closeModal('detailModal');setTimeout(()=>openEditModal(window._detailLoc),100)"
                id="detailEditBtn" class="lm-btn btn-primary btn-sm" style="display:none">
            <i class="fas fa-edit"></i> Edit Lokasi
        </button>
    </div>
    <?php endif; ?>
</div>
</div>

<script>
function openModal(id) {
    document.getElementById(id).classList.add('open');
}
function closeModal(id) {
    document.getElementById(id).classList.remove('open');
}
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        document.querySelectorAll('.lm-modal-overlay.open').forEach(function(m){ m.classList.remove('open'); });
    }
});
document.querySelectorAll('.lm-modal-overlay').forEach(function(overlay) {
    overlay.addEventListener('click', function(e) {
        if (e.target === overlay) overlay.classList.remove('open');
    });
});

function autoParseCode(val, prefix) {
    val = val.toUpperCase();
    var m = val.match(/^([A-Z]{1,4})(\d{1,4})([A-Z])(\d{1,4})$/);
    if (!m) return;
    var fields = { aisle: m[1], rack: m[2], row_name: m[3], position: m[4] };
    Object.keys(fields).forEach(function(k) {
        var el = document.getElementById(prefix + '_' + (k === 'row_name' ? 'row' : k));
        if (el && !el.dataset.manuallyEdited) {
            el.value = fields[k];
            el.style.background = '#e8f5e9';
            setTimeout(function(){ el.style.background = ''; }, 800);
        }
    });
}
document.querySelectorAll('#add_aisle,#add_rack,#add_row,#add_pos,#edit_aisle,#edit_rack,#edit_row,#edit_pos')
    .forEach(function(el) {
        el.addEventListener('input', function() { this.dataset.manuallyEdited = '1'; });
    });

function openAddModal() {
    ['add_aisle','add_rack','add_row','add_pos'].forEach(function(id) {
        var el = document.getElementById(id);
        if (el) { el.value = ''; delete el.dataset.manuallyEdited; }
    });
    document.getElementById('addCode').value = '';
    openModal('addLocModal');
    setTimeout(function(){ document.getElementById('addCode').focus(); }, 100);
}

function openEditModal(data) {
    if (!data) return;
    document.getElementById('edit_id').value    = data.id;
    document.getElementById('edit_code').value  = data.location_code;
    document.getElementById('edit_aisle').value = data.aisle;
    document.getElementById('edit_rack').value  = data.rack;
    document.getElementById('edit_row').value   = data.row_name;
    document.getElementById('edit_pos').value   = data.position;
    var sel = document.getElementById('edit_zone');
    for (var i = 0; i < sel.options.length; i++) {
        if (sel.options[i].value === data.zone) { sel.selectedIndex = i; break; }
    }
    ['edit_aisle','edit_rack','edit_row','edit_pos'].forEach(function(id) {
        var el = document.getElementById(id);
        if (el) delete el.dataset.manuallyEdited;
    });
    document.getElementById('del_id').value   = data.id;
    document.getElementById('del_code').value = data.location_code;
    openModal('editLocModal');
}

function confirmDelete() {
    var code = document.getElementById('del_code').value;
    if (!confirm('Hapus lokasi ' + code + '?')) return;
    document.getElementById('deleteLocForm').submit();
}

window._detailLoc = null;

function openDetailModal(code) {
    openModal('detailModal');
    document.getElementById('detailTitle').innerHTML = '<i class="fas fa-map-marker-alt mr-2"></i>' + code;
    document.getElementById('detailBody').innerHTML =
        '<div style="text-align:center;padding:30px;color:#90a4ae">' +
        '<i class="fas fa-spinner fa-spin" style="font-size:1.5rem"></i>' +
        '<div style="margin-top:8px;font-size:.85rem">Memuat detail…</div></div>';
    var editBtn = document.getElementById('detailEditBtn');
    if (editBtn) editBtn.style.display = 'none';

    fetch('api_locations.php?action=check&code=' + encodeURIComponent(code))
        .then(function(r){ return r.json(); })
        .then(function(data) {
            if (!data.success || !data.info) {
                document.getElementById('detailBody').innerHTML =
                    '<p style="color:#e65100;text-align:center;padding:20px">Gagal memuat data lokasi</p>';
                return;
            }
            var info  = data.info;
            var avail = data.available;

            window._detailLoc = {
                id: info.id, location_code: info.location_code,
                aisle: info.aisle||'', rack: info.rack||'',
                row_name: info.row_name||'', position: info.position||'',
                zone: info.zone||'General', is_active: info.is_active,
            };

            var zoneClass  = 'zone-' + (info.zone||'General');
            var statusHtml = avail
                ? '<span class="badge-avail"><i class="fas fa-check"></i> Tersedia</span>'
                : '<span class="badge-occ"><i class="fas fa-box"></i> Terisi</span>';
            var activeHtml = info.is_active
                ? '<span style="color:#2e7d32"><i class="fas fa-check-circle"></i> Aktif</span>'
                : '<span style="color:#9e9e9e"><i class="fas fa-ban"></i> Nonaktif</span>';

            var html = '<div class="detail-grid">' +
                '<div class="detail-item"><label>Kode Lokasi</label><div class="val" style="font-family:monospace;font-size:1.1rem;color:#026766;font-weight:700">' + esc(info.location_code) + '</div></div>' +
                '<div class="detail-item"><label>Zone</label><div class="val"><span class="badge-zone ' + zoneClass + '">' + esc(info.zone||'—') + '</span></div></div>' +
                '<div class="detail-item"><label>Aisle</label><div class="val">' + esc(info.aisle||'—') + '</div></div>' +
                '<div class="detail-item"><label>Rack</label><div class="val">' + esc(info.rack||'—') + '</div></div>' +
                '<div class="detail-item"><label>Row</label><div class="val">' + esc(info.row_name||'—') + '</div></div>' +
                '<div class="detail-item"><label>Position</label><div class="val">' + esc(info.position||'—') + '</div></div>' +
                '<div class="detail-item"><label>Ketersediaan</label><div class="val">' + statusHtml + '</div></div>' +
                '<div class="detail-item"><label>Status Lokasi</label><div class="val">' + activeHtml + '</div></div>' +
                '</div>';

            if (!avail && info.sl_id) {
                var exp = info.expiry_date
                    ? new Date(info.expiry_date).toLocaleDateString('id-ID',{day:'2-digit',month:'short',year:'numeric'})
                    : '—';
                html += '<div class="detail-stock-box">' +
                    '<h4><i class="fas fa-boxes mr-1"></i>Stok Saat Ini</h4>' +
                    '<div class="detail-grid" style="margin-bottom:0">' +
                    '<div class="detail-item"><label>Produk</label><div class="val">' + esc(info.product_name||'—') + '</div></div>' +
                    '<div class="detail-item"><label>Kode Produk</label><div class="val" style="font-family:monospace">' + esc(info.product_code||'—') + '</div></div>' +
                    '<div class="detail-item"><label>Batch</label><div class="val" style="font-family:monospace">' + esc(info.batch_number||'—') + '</div></div>' +
                    '<div class="detail-item"><label>Exp. Date</label><div class="val">' + exp + '</div></div>' +
                    '<div class="detail-item"><label>Qty</label><div class="val" style="font-weight:700;color:#026766">' + (info.quantity ? parseFloat(info.quantity).toLocaleString('id-ID') + ' ' + (info.uom||'') : '—') + '</div></div>' +
                    '</div></div>';
            } else if (avail) {
                html += '<div class="detail-stock-box" style="background:#f1f8e9;border-color:#a5d6a7">' +
                    '<div style="text-align:center;color:#2e7d32;font-size:.88rem"><i class="fas fa-check-circle mr-1"></i>Lokasi kosong dan siap digunakan</div>' +
                    '</div>';
            }

            document.getElementById('detailBody').innerHTML = html;
            if (editBtn) editBtn.style.display = '';
        })
        .catch(function() {
            document.getElementById('detailBody').innerHTML =
                '<p style="color:#e65100;text-align:center;padding:20px">Gagal terhubung ke server</p>';
        });
}

function esc(str) {
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function setZoneFilter(zone) {
    var sel = document.getElementById('zoneSelect');
    if (!sel) return;
    sel.value = sel.value === zone ? '' : zone;
    document.getElementById('filterForm').submit();
}

function liveFilter(query) {
    var q = query.toLowerCase().trim();
    var rows = document.querySelectorAll('#locTbody .loc-row');
    var visible = 0;
    rows.forEach(function(row) {
        var show = !q || row.textContent.toLowerCase().indexOf(q) !== -1;
        row.style.display = show ? '' : 'none';
        if (show) visible++;
    });
    var cnt = document.getElementById('liveCount');
    if (cnt) cnt.textContent = q ? ('Menampilkan ' + visible + ' baris') : '';
}

document.getElementById('filterForm') && document.getElementById('filterForm').addEventListener('submit', function() {
    var el = document.getElementById('liveSearch');
    if (el) el.value = '';
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

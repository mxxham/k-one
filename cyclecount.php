<?php
ob_start();
error_reporting(0);
ini_set('display_errors', '0');
session_start();
header("Cache-Control: no-store, no-cache, must-revalidate");
header("Pragma: no-cache");
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/classes/Auth.php';
require_once __DIR__ . '/classes/CycleCount.php';
Auth::requireAuth();
$canWrite = Auth::canWrite();

$pageTitle   = 'Cycle Count';
$currentPage = 'cyclecount';

$error   = null;
$success = $_GET['success'] ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::requireWrite();
    try {
        if (isset($_POST['save_schedule'])) {
            $id = CycleCount::saveSchedule([
                'id'               => (int)($_POST['id'] ?? 0),
                'schedule_name'    => $_POST['schedule_name'],
                'frequency'        => $_POST['frequency'],
                'scope_type'       => $_POST['scope_type'],
                'scope_locations'  => $_POST['scope_locations'] ?? null,
                'velocity_class'   => $_POST['velocity_class'] ?? null,
                'next_run_date'    => $_POST['next_run_date'],
                'is_active'        => isset($_POST['is_active']) ? 1 : 0,
            ]);
            header('Location: cyclecount.php?success=saved'); exit;
        }
        if (isset($_POST['delete_schedule'])) {
            CycleCount::deleteSchedule((int)$_POST['id']);
            header('Location: cyclecount.php?success=deleted'); exit;
        }
        if (isset($_POST['run_now'])) {
            $takeId = CycleCount::runNow((int)$_POST['id']);
            header('Location: stocktake.php?take_id=' . $takeId . '&success=cyclecount'); exit;
        }
        if (isset($_POST['run_due'])) {
            $created = CycleCount::runDue();
            header('Location: cyclecount.php?success=ran&count=' . count($created)); exit;
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

$schedules = CycleCount::listSchedules();

ob_end_flush();
require_once __DIR__ . '/includes/header.php';
?>
<style>
:root{--cc-primary:#026766;--cc-border:#b2dfdb;--cc-bg:#f0fdf9;--cc-muted:#607d8b}
.cc-card{background:#fff;border-radius:10px;border:1px solid var(--cc-border);box-shadow:0 2px 12px rgba(0,105,92,.08);overflow:hidden;margin-bottom:18px}
.cc-ch{padding:12px 20px;border-bottom:1px solid var(--cc-border);display:flex;align-items:center;justify-content:space-between;background:var(--cc-bg)}
.cc-ch h2{font-size:.93rem;font-weight:600;color:var(--cc-primary);margin:0}
.cc-cb{padding:20px}
.cc-tbl{width:100%;border-collapse:collapse;font-size:.875rem}
.cc-tbl thead tr{background:#e0f2f1}
.cc-tbl th{padding:9px 12px;text-align:left;font-size:.7rem;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:var(--cc-muted);border-bottom:2px solid var(--cc-border)}
.cc-tbl td{padding:9px 12px;border-bottom:1px solid #e0f2f1;color:#37474f}
.cc-tbl tbody tr:hover{background:var(--cc-bg)}
.cc-badge{display:inline-block;padding:3px 10px;border-radius:20px;font-size:.7rem;font-weight:700}
.cc-on{background:#e8f5e9;color:#013d3c;border:1px solid #a5d6a7}
.cc-off{background:#fce4ec;color:#880e4f;border:1px solid #f48fb1}
.cc-due{background:#fff8e1;color:#e65100;border:1px solid #ffcc80}
.cc-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 18px;border-radius:7px;font-size:.85rem;font-weight:600;cursor:pointer;border:none;transition:.15s;text-decoration:none}
.cc-btn-p{background:var(--cc-primary);color:#fff}.cc-btn-p:hover{background:#013d3c}
.cc-btn-g{background:#e0f2f1;color:#013d3c}.cc-btn-g:hover{background:#b2dfdb}
.cc-btn-r{background:#014f4e;color:#fff}.cc-btn-r:hover{background:#012d2c}
.cc-btn-sm{padding:4px 10px;font-size:.75rem}
.cc-inp{width:100%;padding:7px 11px;border:1.5px solid #b2dfdb;border-radius:7px;font-size:.85rem;outline:none}
.cc-inp:focus{border-color:var(--cc-primary);box-shadow:0 0 0 3px rgba(0,105,92,.1)}
.cc-alert{padding:10px 14px;border-radius:7px;font-size:.86rem;display:flex;align-items:center;gap:8px}
.cc-ok{background:#e8f5e9;border-left:4px solid #43a047;color:#013d3c}
.cc-err{background:#ffebee;border-left:4px solid #026766;color:#014f4e}
.cc-info{background:#e3f2fd;border-left:4px solid #1e88e5;color:#013d3c}
</style>

<div style="max-width:1080px;margin:0 auto;padding:18px">

<?php if($success==='saved'):?><div class="cc-alert cc-ok mb-4"><i class="fas fa-check-circle"></i> Jadwal cycle count disimpan.</div><?php endif;?>
<?php if($success==='deleted'):?><div class="cc-alert cc-info mb-4"><i class="fas fa-info-circle"></i> Jadwal dihapus.</div><?php endif;?>
<?php if($success==='ran'):?><div class="cc-alert cc-ok mb-4"><i class="fas fa-check-circle"></i> <?=(int)($_GET['count']??0)?> stock take cycle count dibuat dari jadwal yang jatuh tempo.</div><?php endif;?>
<?php if($error):?><div class="cc-alert cc-err mb-4"><i class="fas fa-exclamation-circle"></i> <?=htmlspecialchars($error)?></div><?php endif;?>

<div class="cc-card">
  <div class="cc-ch">
    <h2><i class="fas fa-recycle mr-2"></i>Jadwal Cycle Count</h2>
    <?php if($canWrite):?>
    <form method="POST" onsubmit="return confirm('Jalankan semua jadwal yang jatuh tempo sekarang?')">
      <button type="submit" name="run_due" class="cc-btn cc-btn-p cc-btn-sm"><i class="fas fa-play"></i> Jalankan yang Jatuh Tempo</button>
    </form>
    <?php endif;?>
  </div>
  <div style="overflow-x:auto">
  <?php if(empty($schedules)):?>
  <div style="text-align:center;padding:40px;color:#90a4ae">
    <i class="fas fa-recycle" style="font-size:2rem;margin-bottom:10px;display:block"></i>
    Belum ada jadwal cycle count.
  </div>
  <?php else:?>
  <table class="cc-tbl">
    <thead><tr>
      <th>Nama Jadwal</th><th>Frekuensi</th><th>Scope</th><th>Next Run</th>
      <th>Status</th><th>Jatuh Tempo</th><th style="text-align:right">Run</th>
      <th style="text-align:center">Aksi</th>
    </tr></thead>
    <tbody>
    <?php foreach($schedules as $s):?>
    <tr>
      <td style="font-weight:600"><?=htmlspecialchars($s['schedule_name'])?></td>
      <td style="text-transform:capitalize"><?=htmlspecialchars($s['frequency'])?></td>
      <td>
        <span style="text-transform:capitalize"><?=htmlspecialchars($s['scope_type'])?></span>
        <?php if($s['scope_type']==='velocity' && $s['velocity_class']):?>
        <span class="cc-badge cc-due">Class <?=htmlspecialchars($s['velocity_class'])?></span>
        <?php endif;?>
      </td>
      <td style="font-size:.82rem"><?=date('d M Y',strtotime($s['next_run_date']))?></td>
      <td>
        <?php if($s['is_active']):?>
        <span class="cc-badge cc-on"><i class="fas fa-check"></i> Aktif</span>
        <?php else:?>
        <span class="cc-badge cc-off">Nonaktif</span>
        <?php endif;?>
      </td>
      <td>
        <?php if($s['due']):?>
        <span class="cc-badge cc-due"><i class="fas fa-exclamation-triangle"></i> Due</span>
        <?php else:?>
        <span style="color:#90a4ae;font-size:.78rem">—</span>
        <?php endif;?>
      </td>
      <td style="text-align:right"><?=$s['run_count']?></td>
      <td style="text-align:center;white-space:nowrap">
        <?php if($canWrite):?>
        <form method="POST" style="display:inline" onsubmit="return confirm('Jalankan cycle count sekarang?')">
          <input type="hidden" name="id" value="<?=$s['id']?>">
          <button type="submit" name="run_now" class="cc-btn cc-btn-p cc-btn-sm"><i class="fas fa-play"></i></button>
        </form>
        <form method="POST" style="display:inline" onsubmit="return confirm('Hapus jadwal ini?')">
          <input type="hidden" name="id" value="<?=$s['id']?>">
          <button type="submit" name="delete_schedule" class="cc-btn cc-btn-g cc-btn-sm"><i class="fas fa-trash"></i></button>
        </form>
        <?php endif;?>
      </td>
    </tr>
    <?php endforeach;?>
    </tbody>
  </table>
  <?php endif;?>
  </div>
</div>

<?php if($canWrite):?>
<div class="cc-card">
  <div class="cc-ch"><h2><i class="fas fa-plus-circle mr-2"></i>Buat Jadwal Baru</h2></div>
  <div class="cc-cb">
    <form method="POST">
      <div style="display:grid;grid-template-columns:1.2fr .8fr .8fr 1fr;gap:12px;margin-bottom:12px">
        <div>
          <label style="font-size:.72rem;font-weight:600;color:#37474f;display:block;margin-bottom:3px">Nama Jadwal <span style="color:#e53935">*</span></label>
          <input type="text" name="schedule_name" class="cc-inp" placeholder="cth: Cycle Count Bulanan Gudang" required>
        </div>
        <div>
          <label style="font-size:.72rem;font-weight:600;color:#37474f;display:block;margin-bottom:3px">Frekuensi</label>
          <select name="frequency" class="cc-inp">
            <option value="weekly">Weekly</option>
            <option value="monthly" selected>Monthly</option>
            <option value="quarterly">Quarterly</option>
          </select>
        </div>
        <div>
          <label style="font-size:.72rem;font-weight:600;color:#37474f;display:block;margin-bottom:3px">Scope</label>
          <select name="scope_type" class="cc-inp" id="ccScopeType" onchange="toggleScope()">
            <option value="full">Full (semua lokasi)</option>
            <option value="location">Lokasi tertentu</option>
            <option value="velocity">Kelas Velocity (ABC)</option>
          </select>
        </div>
        <div>
          <label style="font-size:.72rem;font-weight:600;color:#37474f;display:block;margin-bottom:3px">Next Run <span style="color:#e53935">*</span></label>
          <input type="date" name="next_run_date" class="cc-inp" value="<?=date('Y-m-d')?>" required>
        </div>
      </div>
      <div id="ccLocRow" style="display:none;margin-bottom:12px">
        <label style="font-size:.72rem;font-weight:600;color:#37474f;display:block;margin-bottom:3px">Lokasi (pisahkan dengan koma, mis: A0101, A0202)</label>
        <input type="text" name="scope_locations" class="cc-inp" placeholder="A0101, A0102, ...">
      </div>
      <div id="ccVcRow" style="display:none;margin-bottom:12px">
        <label style="font-size:.72rem;font-weight:600;color:#37474f;display:block;margin-bottom:3px">Kelas Velocity</label>
        <select name="velocity_class" class="cc-inp">
          <option value="A">A — Fast movers</option>
          <option value="B">B — Medium movers</option>
          <option value="C">C — Slow movers</option>
        </select>
      </div>
      <div style="display:flex;gap:10px;align-items:center">
        <label style="display:flex;align-items:center;gap:6px;font-size:.83rem;color:#37474f;margin-right:8px">
          <input type="checkbox" name="is_active" checked> Aktif
        </label>
        <button type="submit" name="save_schedule" class="cc-btn cc-btn-p"><i class="fas fa-check"></i> Simpan Jadwal</button>
      </div>
    </form>
  </div>
</div>
<?php endif;?>

<script>
function toggleScope() {
  const v = document.getElementById('ccScopeType').value;
  document.getElementById('ccLocRow').style.display = (v==='location') ? 'block' : 'none';
  document.getElementById('ccVcRow').style.display = (v==='velocity') ? 'block' : 'none';
}
</script>

</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
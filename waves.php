<?php
ob_start();
error_reporting(0);
ini_set('display_errors', '0');
session_start();
header("Cache-Control: no-store, no-cache, must-revalidate");
header("Pragma: no-cache");
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/classes/Auth.php';
require_once __DIR__ . '/classes/Wave.php';
require_once __DIR__ . '/classes/Picklist.php';
Auth::requireAuth();
$canWrite = Auth::canWrite();

$pageTitle   = 'Waves';
$currentPage = 'waves';

$error   = null;
$success = $_GET['success'] ?? null;
$action  = $_GET['action'] ?? 'list';
$id      = isset($_GET['id']) ? (int)$_GET['id'] : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::requireWrite();
    try {
        if (isset($_POST['create_wave'])) {
            $orderIds = array_map('intval', $_POST['order_ids'] ?? []);
            $res = Wave::create([
                'order_ids'   => $orderIds,
                'carrier'     => $_POST['carrier'] ?? null,
                'cutoff_time' => $_POST['cutoff_time'] ?? null,
            ]);
            header('Location: waves.php?action=view&id=' . $res['wave_id'] . '&success=created'); exit;
        }
        if (isset($_POST['cancel_wave'])) {
            Wave::cancel((int)$_POST['id']);
            header('Location: waves.php?success=cancelled'); exit;
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

$waves      = Wave::list();
$candidates = Wave::candidateOrders();
$detail     = $id ? Wave::detail($id) : null;

ob_end_flush();
require_once __DIR__ . '/includes/header.php';
?>
<style>
:root{--wv-primary:#026766;--wv-border:#b2dfdb;--wv-bg:#f0fdf9;--wv-muted:#607d8b}
.wv-card{background:#fff;border-radius:10px;border:1px solid var(--wv-border);box-shadow:0 2px 12px rgba(0,105,92,.08);overflow:hidden;margin-bottom:18px}
.wv-ch{padding:12px 20px;border-bottom:1px solid var(--wv-border);display:flex;align-items:center;justify-content:space-between;background:var(--wv-bg)}
.wv-ch h2{font-size:.93rem;font-weight:600;color:var(--wv-primary);margin:0}
.wv-cb{padding:20px}
.wv-tbl{width:100%;border-collapse:collapse;font-size:.875rem}
.wv-tbl thead tr{background:#e0f2f1}
.wv-tbl th{padding:9px 12px;text-align:left;font-size:.7rem;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:var(--wv-muted);border-bottom:2px solid var(--wv-border)}
.wv-tbl td{padding:9px 12px;border-bottom:1px solid #e0f2f1;color:#37474f}
.wv-tbl tbody tr:hover{background:var(--wv-bg)}
.wv-badge{display:inline-block;padding:3px 10px;border-radius:20px;font-size:.7rem;font-weight:700}
.wv-planning{background:#e3f2fd;color:#01579b;border:1px solid #90caf9}
.wv-picking{background:#fff8e1;color:#e65100;border:1px solid #ffcc80}
.wv-completed{background:#e8f5e9;color:#013d3c;border:1px solid #a5d6a7}
.wv-cancelled{background:#fce4ec;color:#880e4f;border:1px solid #f48fb1}
.wv-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 18px;border-radius:7px;font-size:.85rem;font-weight:600;cursor:pointer;border:none;transition:.15s;text-decoration:none}
.wv-btn-p{background:var(--wv-primary);color:#fff}.wv-btn-p:hover{background:#013d3c}
.wv-btn-g{background:#e0f2f1;color:#013d3c}.wv-btn-g:hover{background:#b2dfdb}
.wv-btn-r{background:#014f4e;color:#fff}.wv-btn-r:hover{background:#012d2c}
.wv-btn-sm{padding:4px 10px;font-size:.75rem}
.wv-inp{width:100%;padding:7px 11px;border:1.5px solid #b2dfdb;border-radius:7px;font-size:.85rem;outline:none}
.wv-inp:focus{border-color:var(--wv-primary);box-shadow:0 0 0 3px rgba(0,105,92,.1)}
.wv-alert{padding:10px 14px;border-radius:7px;font-size:.86rem;display:flex;align-items:center;gap:8px}
.wv-ok{background:#e8f5e9;border-left:4px solid #43a047;color:#013d3c}
.wv-err{background:#ffebee;border-left:4px solid #026766;color:#014f4e}
.wv-info{background:#e3f2fd;border-left:4px solid #1e88e5;color:#013d3c}
</style>

<div style="max-width:1080px;margin:0 auto;padding:18px">

<?php if($success==='created'):?><div class="wv-alert wv-ok mb-4"><i class="fas fa-check-circle"></i> Wave berhasil dibuat — picklist konsolidasi (Draft) siap diproses.</div><?php endif;?>
<?php if($success==='cancelled'):?><div class="wv-alert wv-info mb-4"><i class="fas fa-info-circle"></i> Wave dibatalkan. Picklist Draft terkait dihapus.</div><?php endif;?>
<?php if($error):?><div class="wv-alert wv-err mb-4"><i class="fas fa-exclamation-circle"></i> <?=htmlspecialchars($error)?></div><?php endif;?>

<?php if ($action === 'list'):?>

<div class="wv-card">
  <div class="wv-ch">
    <h2><i class="fas fa-wave-square mr-2"></i>Buat Wave Baru</h2>
  </div>
  <div class="wv-cb">
    <?php if(empty($candidates)):?>
    <div style="text-align:center;padding:24px;color:#90a4ae">
      <i class="fas fa-inbox" style="font-size:1.6rem;margin-bottom:8px;display:block"></i>
      Tidak ada order Open/Draft tanpa picklist yang bisa di-wave.
    </div>
    <?php else:?>
    <form method="POST" id="waveForm">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px">
        <div>
          <label style="font-size:.72rem;font-weight:600;color:#37474f;display:block;margin-bottom:3px">Carrier / Ekspedisi</label>
          <input type="text" name="carrier" class="wv-inp" placeholder="Opsional">
        </div>
        <div>
          <label style="font-size:.72rem;font-weight:600;color:#37474f;display:block;margin-bottom:3px">Cutoff Time</label>
          <input type="datetime-local" name="cutoff_time" class="wv-inp">
        </div>
      </div>
      <div style="font-size:.75rem;font-weight:700;color:#37474f;margin-bottom:6px">
        <i class="fas fa-check-square mr-1" style="color:var(--wv-primary)"></i>Pilih Outbound Orders (maks 50)
      </div>
      <table class="wv-tbl">
        <thead><tr>
          <th style="width:36px"><input type="checkbox" id="wvCheckAll" onclick="toggleAll(this)"></th>
          <th>Order No</th><th>Customer</th><th>Tanggal</th><th style="text-align:right">Jumlah Item</th>
        </tr></thead>
        <tbody>
        <?php foreach($candidates as $c):?>
        <tr>
          <td><input type="checkbox" name="order_ids[]" value="<?=$c['id']?>" class="wvOrderChk"></td>
          <td style="font-weight:600;color:var(--wv-primary);font-family:monospace"><?=htmlspecialchars($c['order_number'])?></td>
          <td><?=htmlspecialchars($c['customer_name']??'—')?></td>
          <td style="font-size:.82rem;color:#546e7a"><?=date('d M Y',strtotime($c['order_date']??$c['created_at']))?></td>
          <td style="text-align:right"><?=$c['total_items']?></td>
        </tr>
        <?php endforeach;?>
        </tbody>
      </table>
      <div style="display:flex;gap:10px;margin-top:14px;align-items:center">
        <button type="submit" name="create_wave" class="wv-btn wv-btn-p" onclick="return requireSelected()">
          <i class="fas fa-play"></i> Buat Wave
        </button>
        <span id="wvSelCount" style="font-size:.8rem;color:#607d8b">0 order dipilih</span>
      </div>
    </form>
    <?php endif;?>
  </div>
</div>

<div class="wv-card">
  <div class="wv-ch">
    <h2><i class="fas fa-list mr-2"></i>Semua Wave</h2>
  </div>
  <div style="overflow-x:auto">
  <?php if(empty($waves)):?>
  <div style="text-align:center;padding:40px;color:#90a4ae">
    <i class="fas fa-wave-square" style="font-size:2rem;margin-bottom:10px;display:block"></i>
    Belum ada wave.
  </div>
  <?php else:?>
  <table class="wv-tbl">
    <thead><tr>
      <th>No. Wave</th><th>Status</th><th style="text-align:right">Order</th>
      <th style="text-align:right">Picklist</th><th style="text-align:right">Item</th>
      <th>Carrier</th><th>Cutoff</th><th>Dibuat Oleh</th><th style="text-align:center">Aksi</th>
    </tr></thead>
    <tbody>
    <?php foreach($waves as $w):?>
    <tr>
      <td><a href="?action=view&id=<?=$w['id']?>" style="font-weight:700;color:var(--wv-primary);text-decoration:none;font-family:monospace"><?=htmlspecialchars($w['wave_number'])?></a></td>
      <td>
        <?php
        $cls = ['Planning'=>'wv-planning','Picking'=>'wv-picking','Completed'=>'wv-completed','Cancelled'=>'wv-cancelled'][$w['status']]??'wv-planning';
        echo "<span class='wv-badge $cls'>{$w['status']}</span>";
        ?>
      </td>
      <td style="text-align:right"><?=$w['order_count']?></td>
      <td style="text-align:right"><?=$w['picklist_count']?></td>
      <td style="text-align:right"><?=$w['total_items']?></td>
      <td style="font-size:.82rem"><?=htmlspecialchars($w['carrier']??'—')?></td>
      <td style="font-size:.78rem;color:#546e7a"><?=$w['cutoff_time']?date('d M Y H:i',strtotime($w['cutoff_time'])):'—'?></td>
      <td style="font-size:.8rem;color:#607d8b"><?=htmlspecialchars($w['created_by_name']??'—')?></td>
      <td style="text-align:center">
        <a href="?action=view&id=<?=$w['id']?>" class="wv-btn wv-btn-g wv-btn-sm"><i class="fas fa-eye"></i></a>
      </td>
    </tr>
    <?php endforeach;?>
    </tbody>
  </table>
  <?php endif;?>
  </div>
</div>

<script>
function toggleAll(chk) {
  document.querySelectorAll('.wvOrderChk').forEach(c => c.checked = chk.checked);
  updateCount();
}
function updateCount() {
  const n = document.querySelectorAll('.wvOrderChk:checked').length;
  document.getElementById('wvSelCount').textContent = n + ' order dipilih';
}
document.querySelectorAll('.wvOrderChk').forEach(c => c.addEventListener('change', updateCount));
function requireSelected() {
  const n = document.querySelectorAll('.wvOrderChk:checked').length;
  if (!n) { alert('Pilih minimal satu outbound order.'); return false; }
  return true;
}
</script>

<?php elseif ($action === 'view' && $detail): $w = $detail['wave'];?>
<div style="display:flex;gap:10px;margin-bottom:14px;align-items:center">
  <a href="?action=list" class="wv-btn wv-btn-g wv-btn-sm"><i class="fas fa-arrow-left"></i> Kembali</a>
  <span style="font-size:1.1rem;font-weight:700;color:var(--wv-primary);font-family:monospace"><?=htmlspecialchars($w['wave_number'])?></span>
  <?php
  $cls = ['Planning'=>'wv-planning','Picking'=>'wv-picking','Completed'=>'wv-completed','Cancelled'=>'wv-cancelled'][$w['status']]??'wv-planning';
  echo "<span class='wv-badge $cls'>{$w['status']}</span>";
  ?>
  <?php if($canWrite && in_array($w['status'],['Planning','Picking'],true)):?>
  <form method="POST" onsubmit="return confirm('Batalkan wave ini? Picklist Draft akan dihapus.')" style="margin-left:auto">
    <input type="hidden" name="id" value="<?=$w['id']?>">
    <button type="submit" name="cancel_wave" class="wv-btn wv-btn-r wv-btn-sm"><i class="fas fa-ban"></i> Batalkan Wave</button>
  </form>
  <?php endif;?>
</div>

<?php if($w['carrier']||$w['cutoff_time']):?>
<div class="wv-alert wv-info mb-4">
  <i class="fas fa-truck"></i>
  <span>Carrier: <?=htmlspecialchars($w['carrier']??'—')?> · Cutoff: <?=$w['cutoff_time']?date('d M Y H:i',strtotime($w['cutoff_time'])):'—'?></span>
</div>
<?php endif;?>

<div class="wv-card">
  <div class="wv-ch"><h2><i class="fas fa-file-invoice mr-2"></i>Orders dalam Wave</h2></div>
  <div style="overflow-x:auto">
  <table class="wv-tbl">
    <thead><tr><th>Order No</th><th>Customer</th><th>Tanggal</th></tr></thead>
    <tbody>
    <?php foreach($detail['orders'] as $o):?>
    <tr>
      <td style="font-weight:600;color:var(--wv-primary);font-family:monospace"><?=htmlspecialchars($o['order_number'])?></td>
      <td><?=htmlspecialchars($o['customer_name']??'—')?></td>
      <td style="font-size:.82rem;color:#546e7a"><?=date('d M Y',strtotime($o['order_date']))?></td>
    </tr>
    <?php endforeach;?>
    </tbody>
  </table>
  </div>
</div>

<div class="wv-card">
  <div class="wv-ch"><h2><i class="fas fa-list-check mr-2"></i>Picklist Wave</h2></div>
  <div style="overflow-x:auto">
  <table class="wv-tbl">
    <thead><tr><th>Picklist No</th><th>Status</th><th style="text-align:right">Item</th><th>Tanggal</th><th style="text-align:center">Aksi</th></tr></thead>
    <tbody>
    <?php foreach($detail['picklists'] as $pl):?>
    <tr>
      <td style="font-weight:600;color:var(--wv-primary);font-family:monospace"><?=htmlspecialchars($pl['picklist_number'])?></td>
      <td><span class="wv-badge <?=$pl['status']==='Draft'?'wv-planning':'wv-picking'?>"><?=$pl['status']?></span></td>
      <td style="text-align:right"><?=(int)$pl['item_count']?></td>
      <td style="font-size:.82rem;color:#546e7a"><?=date('d M Y',strtotime($pl['created_date']))?></td>
      <td style="text-align:center">
        <a href="picklist.php?picklist_id=<?=$pl['id']?>" class="wv-btn wv-btn-g wv-btn-sm"><i class="fas fa-clipboard-list"></i> Buka</a>
      </td>
    </tr>
    <?php endforeach;?>
    </tbody>
  </table>
  </div>
</div>

<?php endif;?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
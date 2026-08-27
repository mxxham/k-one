<?php
ob_start();
error_reporting(0);
ini_set('display_errors', '0');
session_start();
header("Cache-Control: no-store, no-cache, must-revalidate");
header("Pragma: no-cache");
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/classes/Auth.php';
require_once __DIR__ . '/classes/Putaway.php';
require_once __DIR__ . '/classes/Inbound.php';
require_once __DIR__ . '/classes/ActivityLogger.php';
Auth::requireAuth();
$canWrite = Auth::canWrite();
$canAdmin = Auth::canAdmin();

$pageTitle   = 'Putaway Tasks';
$currentPage = 'putaway_tasks';

$error   = null;
$success = $_GET['success'] ?? null;
$action  = $_GET['action'] ?? 'list';
$id      = isset($_GET['id']) ? (int)$_GET['id'] : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::requireWrite();
    try {
        if (isset($_POST['create_task'])) {
            $taskId = Putaway::createTask((int)$_POST['inbound_order_id']);
            header('Location: putaway_tasks.php?action=view&id=' . $taskId . '&success=created'); exit;
        }
        if (isset($_POST['assign_task'])) {
            Putaway::assignTask((int)$_POST['id'], [
                'assigned_to' => (int)$_POST['assigned_to'],
                'team_partner'=> (int)$_POST['team_partner'],
            ]);
            header('Location: putaway_tasks.php?action=view&id=' . (int)$_POST['id'] . '&success=assigned'); exit;
        }
        if (isset($_POST['complete_task'])) {
            Putaway::completeTask((int)$_POST['id']);
            header('Location: putaway_tasks.php?action=view&id=' . (int)$_POST['id'] . '&success=completed'); exit;
        }
        if (isset($_POST['cancel_task'])) {
            Putaway::cancelTask((int)$_POST['id']);
            header('Location: putaway_tasks.php?success=cancelled'); exit;
        }
        if (isset($_POST['add_block'])) {
            Putaway::addBlock([
                'scope_type'   => $_POST['scope_type'],
                'aisle_prefix' => $_POST['aisle_prefix'] ?? null,
                'location_code'=> $_POST['location_code'] ?? null,
                'reason'       => $_POST['reason'],
            ]);
            header('Location: putaway_tasks.php?tab=blocks&success=blocked'); exit;
        }
        if (isset($_POST['remove_block'])) {
            Putaway::removeBlock((int)$_POST['id']);
            header('Location: putaway_tasks.php?tab=blocks&success=unblocked'); exit;
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

$tab = $_GET['tab'] ?? 'tasks';

$tasks   = Putaway::listTasks(['mine' => 0]);
$blocks  = Putaway::listBlocks();
$inbounds = [];
$users = [];
try {
    $db = db();
    $inbounds = $db->query("SELECT io.id, io.order_number, io.shipment_no, io.status,
            io.received_at, COUNT(ii.id) AS item_count
            FROM inbound_orders io
            LEFT JOIN inbound_items ii ON ii.inbound_order_id = io.id
            GROUP BY io.id
            HAVING io.status = 'Goods Received'
            ORDER BY io.received_at DESC LIMIT 30")->fetchAll();
    $users = $db->query("SELECT id, full_name, role FROM users ORDER BY full_name")->fetchAll();
} catch (Throwable $e) { $inbounds = []; $users = []; }

$taskDetail = $id ? Putaway::taskDetail($id) : null;

ob_end_flush();
require_once __DIR__ . '/includes/header.php';
?>
<style>
:root{--pw-primary:#026766;--pw-border:#b2dfdb;--pw-bg:#f0fdf9;--pw-muted:#607d8b}
.pw-card{background:#fff;border-radius:10px;border:1px solid var(--pw-border);box-shadow:0 2px 12px rgba(0,105,92,.08);overflow:hidden;margin-bottom:18px}
.pw-ch{padding:12px 20px;border-bottom:1px solid var(--pw-border);display:flex;align-items:center;justify-content:space-between;background:var(--pw-bg)}
.pw-ch h2{font-size:.93rem;font-weight:600;color:var(--pw-primary);margin:0}
.pw-cb{padding:20px}
.pw-tbl{width:100%;border-collapse:collapse;font-size:.875rem}
.pw-tbl thead tr{background:#e0f2f1}
.pw-tbl th{padding:9px 12px;text-align:left;font-size:.7rem;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:var(--pw-muted);border-bottom:2px solid var(--pw-border)}
.pw-tbl td{padding:9px 12px;border-bottom:1px solid #e0f2f1;color:#37474f}
.pw-tbl tbody tr:hover{background:var(--pw-bg)}
.pw-badge{display:inline-block;padding:3px 10px;border-radius:20px;font-size:.7rem;font-weight:700}
.pw-open{background:#e3f2fd;color:#01579b;border:1px solid #90caf9}
.pw-progress{background:#fff8e1;color:#e65100;border:1px solid #ffcc80}
.pw-completed{background:#e8f5e9;color:#013d3c;border:1px solid #a5d6a7}
.pw-cancelled{background:#fce4ec;color:#880e4f;border:1px solid #f48fb1}
.pw-pending{background:#fff8e1;color:#e65100;border:1px solid #ffcc80}
.pw-confirmed{background:#e8f5e9;color:#013d3c;border:1px solid #a5d6a7}
.pw-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 18px;border-radius:7px;font-size:.85rem;font-weight:600;cursor:pointer;border:none;transition:.15s;text-decoration:none}
.pw-btn-p{background:var(--pw-primary);color:#fff}.pw-btn-p:hover{background:#013d3c}
.pw-btn-g{background:#e0f2f1;color:#013d3c}.pw-btn-g:hover{background:#b2dfdb}
.pw-btn-r{background:#014f4e;color:#fff}.pw-btn-r:hover{background:#012d2c}
.pw-btn-sm{padding:4px 10px;font-size:.75rem}
.pw-inp{width:100%;padding:7px 11px;border:1.5px solid #b2dfdb;border-radius:7px;font-size:.85rem;outline:none}
.pw-inp:focus{border-color:var(--pw-primary);box-shadow:0 0 0 3px rgba(0,105,92,.1)}
.pw-alert{padding:10px 14px;border-radius:7px;font-size:.86rem;display:flex;align-items:center;gap:8px}
.pw-ok{background:#e8f5e9;border-left:4px solid #43a047;color:#013d3c}
.pw-err{background:#ffebee;border-left:4px solid #026766;color:#014f4e}
.pw-info{background:#e3f2fd;border-left:4px solid #1e88e5;color:#013d3c}
.pw-tab{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:8px 8px 0 0;font-size:.83rem;font-weight:600;text-decoration:none;color:#607d8b;background:#e0f2f1;border:1px solid var(--pw-border);border-bottom:none;margin-right:4px}
.pw-tab.active{background:#fff;color:var(--pw-primary);border-color:var(--pw-border)}
</style>

<div style="max-width:1080px;margin:0 auto;padding:18px">

<?php if($success==='created'):?><div class="pw-alert pw-ok mb-4"><i class="fas fa-check-circle"></i> Putaway task dibuat — arahkan operator ke Putaway Scan untuk konfirmasi pallet.</div><?php endif;?>
<?php if($success==='assigned'):?><div class="pw-alert pw-ok mb-4"><i class="fas fa-check-circle"></i> Task di-assign ke operator + partner.</div><?php endif;?>
<?php if($success==='completed'):?><div class="pw-alert pw-ok mb-4"><i class="fas fa-check-double"></i> Task selesai — stok dipindahkan dari STAGING ke lokasi tujuan.</div><?php endif;?>
<?php if($success==='cancelled'):?><div class="pw-alert pw-info mb-4"><i class="fas fa-info-circle"></i> Task dibatalkan.</div><?php endif;?>
<?php if($success==='blocked'):?><div class="pw-alert pw-ok mb-4"><i class="fas fa-ban"></i> Lokasi/aisle diblokir.</div><?php endif;?>
<?php if($success==='unblocked'):?><div class="pw-alert pw-info mb-4"><i class="fas fa-check"></i> Blokir dihapus.</div><?php endif;?>
<?php if($error):?><div class="pw-alert pw-err mb-4"><i class="fas fa-exclamation-circle"></i> <?=htmlspecialchars($error)?></div><?php endif;?>

<?php if ($action === 'list'):?>

<div style="display:flex;gap:4px;margin-bottom:16px;border-bottom:1px solid var(--pw-border)">
  <a href="?tab=tasks" class="pw-tab <?=$tab==='tasks'?'active':''?>"><i class="fas fa-tasks"></i> Tasks</a>
  <a href="?tab=blocks" class="pw-tab <?=$tab==='blocks'?'active':''?>"><i class="fas fa-ban"></i> Location Blocks</a>
</div>

<?php if ($tab === 'tasks'):?>

<div class="pw-card">
  <div class="pw-ch">
    <h2><i class="fas fa-arrow-circle-down mr-2"></i>Buat Putaway Task</h2>
  </div>
  <div class="pw-cb">
    <?php if(empty($inbounds)):?>
    <div class="pw-alert pw-info"><i class="fas fa-info-circle"></i> Belum ada inbound Goods Received tanpa task. Selesaikan penerimaan (GR) dulu untuk membuat task putaway.</div>
    <?php else:?>
    <form method="POST" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
      <select name="inbound_order_id" class="pw-inp" style="flex:1;min-width:280px" required>
        <option value="">— Pilih inbound Goods Received —</option>
        <?php foreach($inbounds as $io):?>
        <option value="<?=$io['id']?>">
          <?=htmlspecialchars($io['order_number'])?> (<?=htmlspecialchars($io['shipment_no']??'-')?>) · <?=$io['item_count']?> item
          <?=Putaway::hasOpenPallets((int)$io['id'])?'· TASK SUDAH ADA':''?>
        </option>
        <?php endforeach;?>
      </select>
      <button type="submit" name="create_task" class="pw-btn pw-btn-p"><i class="fas fa-plus"></i> Buat Task</button>
    </form>
    <?php endif;?>
  </div>
</div>

<div class="pw-card">
  <div class="pw-ch"><h2><i class="fas fa-list mr-2"></i>Semua Putaway Task</h2></div>
  <div style="overflow-x:auto">
  <?php if(empty($tasks)):?>
  <div style="text-align:center;padding:40px;color:#90a4ae">
    <i class="fas fa-tasks" style="font-size:2rem;margin-bottom:10px;display:block"></i>
    Belum ada putaway task.
  </div>
  <?php else:?>
  <table class="pw-tbl">
    <thead><tr>
      <th>No. Task</th><th>Inbound</th><th>Status</th>
      <th style="text-align:right">Pallet</th><th>Operator</th><th>Partner</th>
      <th>Dibuat</th><th style="text-align:center">Aksi</th>
    </tr></thead>
    <tbody>
    <?php foreach($tasks as $t):?>
    <tr>
      <td><a href="?action=view&id=<?=$t['id']?>" style="font-weight:700;color:var(--pw-primary);text-decoration:none;font-family:monospace"><?=htmlspecialchars($t['task_number'])?></a></td>
      <td style="font-size:.82rem;font-family:monospace"><?=htmlspecialchars($t['order_number'])?></td>
      <td>
        <?php
        $cls = ['Open'=>'pw-open','In Progress'=>'pw-progress','Completed'=>'pw-completed','Cancelled'=>'pw-cancelled'][$t['status']]??'pw-open';
        echo "<span class='pw-badge $cls'>{$t['status']}</span>";
        ?>
      </td>
      <td style="text-align:right"><?=$t['confirmed_count']?>/<?=$t['item_count']?></td>
      <td style="font-size:.82rem"><?=htmlspecialchars($t['assigned_name']??'—')?></td>
      <td style="font-size:.82rem"><?=htmlspecialchars($t['partner_name']??'—')?></td>
      <td style="font-size:.78rem;color:#90a4ae"><?=date('d M Y H:i',strtotime($t['created_at']))?></td>
      <td style="text-align:center">
        <a href="?action=view&id=<?=$t['id']?>" class="pw-btn pw-btn-g pw-btn-sm"><i class="fas fa-eye"></i></a>
      </td>
    </tr>
    <?php endforeach;?>
    </tbody>
  </table>
  <?php endif;?>
  </div>
</div>

<?php else: /* blocks */?>

<div class="pw-card">
  <div class="pw-ch"><h2><i class="fas fa-ban mr-2"></i>Blokir Lokasi / Aisle</h2></div>
  <div class="pw-cb">
    <?php if($canAdmin):?>
    <form method="POST" style="display:grid;grid-template-columns:auto 1fr 1fr 1.4fr auto;gap:10px;align-items:end;margin-bottom:16px">
      <div>
        <label style="font-size:.72rem;font-weight:600;color:#37474f;display:block;margin-bottom:3px">Tipe</label>
        <select name="scope_type" class="pw-inp" id="blockScopeType" onchange="toggleBlockScope()">
          <option value="aisle">Aisle</option>
          <option value="location">Lokasi</option>
        </select>
      </div>
      <div id="aislePrefixWrap">
        <label style="font-size:.72rem;font-weight:600;color:#37474f;display:block;margin-bottom:3px">Aisle Prefix <span style="color:#e53935">*</span></label>
        <input type="text" name="aisle_prefix" class="pw-inp" placeholder="cth: A" style="text-transform:uppercase">
      </div>
      <div id="blockLocWrap" style="display:none">
        <label style="font-size:.72rem;font-weight:600;color:#37474f;display:block;margin-bottom:3px">Lokasi <span style="color:#e53935">*</span></label>
        <input type="text" name="location_code" class="pw-inp" placeholder="cth: A0101" style="text-transform:uppercase">
      </div>
      <div>
        <label style="font-size:.72rem;font-weight:600;color:#37474f;display:block;margin-bottom:3px">Alasan <span style="color:#e53935">*</span></label>
        <input type="text" name="reason" class="pw-inp" placeholder="cth: area renovasi" required>
      </div>
      <button type="submit" name="add_block" class="pw-btn pw-btn-r"><i class="fas fa-ban"></i> Blokir</button>
    </form>
    <?php endif;?>

    <?php if(empty($blocks)):?>
    <div style="text-align:center;padding:24px;color:#90a4ae">Tidak ada blokir aktif.</div>
    <?php else:?>
    <table class="pw-tbl">
      <thead><tr><th>Tipe</th><th>Target</th><th>Alasan</th><th>Diblokir Oleh</th><th>Tanggal</th><?php if($canAdmin):?><th style="text-align:center">Aksi</th><?php endif;?></tr></thead>
      <tbody>
      <?php foreach($blocks as $b):?>
      <tr>
        <td><span class="pw-badge <?=$b['scope_type']==='aisle'?'pw-open':'pw-progress'?>"><?=strtoupper(htmlspecialchars($b['scope_type']))?></span></td>
        <td style="font-family:monospace;font-weight:700"><?=htmlspecialchars($b['aisle_prefix']??$b['location_code']??'')?></td>
        <td style="font-size:.83rem"><?=htmlspecialchars($b['reason'])?></td>
        <td style="font-size:.82rem"><?=htmlspecialchars($b['blocked_by_name']??'—')?></td>
        <td style="font-size:.78rem;color:#90a4ae"><?=date('d M Y H:i',strtotime($b['created_at']))?></td>
        <?php if($canAdmin):?>
        <td style="text-align:center">
          <form method="POST" onsubmit="return confirm('Hapus blokir ini?')">
            <input type="hidden" name="id" value="<?=$b['id']?>">
            <button type="submit" name="remove_block" class="pw-btn pw-btn-g pw-btn-sm"><i class="fas fa-undo"></i></button>
          </form>
        </td>
        <?php endif;?>
      </tr>
      <?php endforeach;?>
      </tbody>
    </table>
    <?php endif;?>
  </div>
</div>

<?php endif;?>

<?php elseif ($action === 'view' && $taskDetail): $task = $taskDetail['task']; $items = $taskDetail['items'];?>

<div style="display:flex;gap:10px;margin-bottom:14px;align-items:center">
  <a href="?action=list&tab=tasks" class="pw-btn pw-btn-g pw-btn-sm"><i class="fas fa-arrow-left"></i> Kembali</a>
  <span style="font-size:1.1rem;font-weight:700;color:var(--pw-primary);font-family:monospace"><?=htmlspecialchars($task['task_number'])?></span>
  <?php
  $cls = ['Open'=>'pw-open','In Progress'=>'pw-progress','Completed'=>'pw-completed','Cancelled'=>'pw-cancelled'][$task['status']]??'pw-open';
  echo "<span class='pw-badge $cls'>{$task['status']}</span>";
  ?>
  <?php if($canWrite && in_array($task['status'],['Open','In Progress'],true)):?>
  <div style="margin-left:auto;display:flex;gap:8px">
    <?php if(!empty($items)):?>
    <button type="button" onclick="printLpnLabels()" class="pw-btn pw-btn-g pw-btn-sm"><i class="fas fa-print"></i> Cetak Label LPN</button>
    <?php endif;?>
    <?php if($task['status']==='In Progress'):?>
    <form method="POST" onsubmit="return confirm('Selesaikan task? Semua pallet harus sudah dikonfirmasi.')">
      <input type="hidden" name="id" value="<?=$task['id']?>">
      <button type="submit" name="complete_task" class="pw-btn pw-btn-p pw-btn-sm"><i class="fas fa-check-double"></i> Selesaikan Task</button>
    </form>
    <?php endif;?>
    <form method="POST" onsubmit="return confirm('Batalkan task ini?')">
      <input type="hidden" name="id" value="<?=$task['id']?>">
      <button type="submit" name="cancel_task" class="pw-btn pw-btn-r pw-btn-sm"><i class="fas fa-ban"></i> Batalkan</button>
    </form>
  </div>
  <?php endif;?>
</div>

<div class="pw-card">
  <div class="pw-ch"><h2><i class="fas fa-info-circle mr-2"></i>Detail Task</h2></div>
  <div class="pw-cb">
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:14px">
      <div><div style="font-size:.68rem;color:#90a4ae;font-weight:700">INBOUND</div><div style="font-family:monospace;font-weight:700"><?=htmlspecialchars($task['order_number'])?></div></div>
      <div><div style="font-size:.68rem;color:#90a4ae;font-weight:700">SHIPMENT</div><div style="font-family:monospace"><?=htmlspecialchars($task['shipment_no']??'—')?></div></div>
      <div><div style="font-size:.68rem;color:#90a4ae;font-weight:700">OPERATOR</div><div><?=htmlspecialchars($task['assigned_name']??'—')?></div></div>
      <div><div style="font-size:.68rem;color:#90a4ae;font-weight:700">PARTNER</div><div><?=htmlspecialchars($task['partner_name']??'—')?></div></div>
      <div><div style="font-size:.68rem;color:#90a4ae;font-weight:700">DIBUAT OLEH</div><div><?=htmlspecialchars($task['created_by_name']??'—')?></div></div>
      <?php if($task['completed_by_name']):?>
      <div><div style="font-size:.68rem;color:#90a4ae;font-weight:700">SELESAI OLEH</div><div><?=htmlspecialchars($task['completed_by_name'])?></div></div>
      <?php endif;?>
    </div>
  </div>
</div>

<?php if($canWrite && $task['status']==='Open'):?>
<div class="pw-card">
  <div class="pw-ch"><h2><i class="fas fa-users mr-2"></i>Assign Operator + Partner</h2></div>
  <div class="pw-cb">
    <form method="POST" style="display:grid;grid-template-columns:1fr 1fr auto;gap:10px;align-items:end">
      <input type="hidden" name="id" value="<?=$task['id']?>">
      <div>
        <label style="font-size:.72rem;font-weight:600;color:#37474f;display:block;margin-bottom:3px">Forklift Operator <span style="color:#e53935">*</span></label>
        <select name="assigned_to" class="pw-inp" required>
          <option value="">— Pilih operator —</option>
          <?php foreach($users as $u):?>
          <option value="<?=$u['id']?>"><?=htmlspecialchars($u['full_name'])?> (<?=htmlspecialchars($u['role'])?>)</option>
          <?php endforeach;?>
        </select>
      </div>
      <div>
        <label style="font-size:.72rem;font-weight:600;color:#37474f;display:block;margin-bottom:3px">Checklist Partner <span style="color:#e53935">*</span></label>
        <select name="team_partner" class="pw-inp" required>
          <option value="">— Pilih partner —</option>
          <?php foreach($users as $u):?>
          <option value="<?=$u['id']?>"><?=htmlspecialchars($u['full_name'])?> (<?=htmlspecialchars($u['role'])?>)</option>
          <?php endforeach;?>
        </select>
      </div>
      <button type="submit" name="assign_task" class="pw-btn pw-btn-p"><i class="fas fa-user-check"></i> Assign</button>
    </form>
  </div>
</div>
<?php endif;?>

<div class="pw-card">
  <div class="pw-ch"><h2><i class="fas fa-boxes mr-2"></i>Pallet Task Items</h2></div>
  <div style="overflow-x:auto">
  <table class="pw-tbl">
    <thead><tr>
      <th>Seq</th><th>LPN</th><th>Produk</th><th>Batch</th>
      <th style="text-align:right">Qty</th><th>UOM</th>
      <th>Saran Lokasi</th><th>Status</th><th>Override</th>
    </tr></thead>
    <tbody>
    <?php foreach($items as $it):?>
    <tr>
      <td><?=$it['pallet_seq']?></td>
      <td style="font-family:monospace;font-weight:700;color:#013d3c"><?=htmlspecialchars($it['lpn_code']??'—')?></td>
      <td>
        <div style="font-weight:600"><?=htmlspecialchars($it['product_name'])?></div>
        <div style="font-size:.7rem;color:#90a4ae;font-family:monospace"><?=htmlspecialchars($it['product_code'])?></div>
      </td>
      <td style="font-family:monospace;font-size:.8rem"><?=htmlspecialchars($it['batch_number']??'—')?></td>
      <td style="text-align:right;font-weight:600"><?=number_format($it['quantity'],2)?></td>
      <td style="font-size:.8rem"><?=htmlspecialchars($it['uom']??'')?></td>
      <td><span style="font-family:monospace;font-weight:700;color:var(--pw-primary)"><?=htmlspecialchars($it['suggested_location']??'—')?></span></td>
      <td>
        <?php
        $icl = $it['status']==='Confirmed' ? 'pw-confirmed' : 'pw-pending';
        echo "<span class='pw-badge $icl'>{$it['status']}</span>";
        ?>
      </td>
      <td>
        <?php if($it['scan_override_reason']):?>
        <span class="pw-badge pw-progress" title="<?=htmlspecialchars($it['scan_override_reason'])?>"><i class="fas fa-exclamation-triangle"></i> Ya</span>
        <?php else:?>
        <span style="color:#90a4ae;font-size:.78rem">—</span>
        <?php endif;?>
      </td>
    </tr>
    <?php endforeach;?>
    </tbody>
  </table>
  </div>
</div>

<div class="pw-alert pw-info mb-4">
  <i class="fas fa-qrcode"></i>
  <span>Operator melakukan konfirmasi pallet via <strong>Putaway Scan</strong> — scan lokasi tujuan per LPN. Konfirmasi hanya bisa dilakukan oleh operator + partner yang di-assign.</span>
</div>

<?php endif;?>
</div>

<script>
function toggleBlockScope() {
  const v = document.getElementById('blockScopeType').value;
  document.getElementById('aislePrefixWrap').style.display = (v==='aisle') ? 'block' : 'none';
  document.getElementById('blockLocWrap').style.display = (v==='location') ? 'block' : 'none';
}

<?php if ($action === 'view' && $taskDetail): ?>
const PW_LABEL_ITEMS = <?= json_encode($items ?? []) ?>;
const PW_LABEL_TASK  = <?= json_encode($task['task_number'] ?? '') ?>;
<?php else: ?>
const PW_LABEL_ITEMS = [];
const PW_LABEL_TASK  = '';
<?php endif; ?>

function printLpnLabels() {
  const items = (typeof PW_LABEL_ITEMS !== 'undefined') ? PW_LABEL_ITEMS : [];
  if (!items.length) return;
  const taskNo = (typeof PW_LABEL_TASK !== 'undefined') ? PW_LABEL_TASK : '';
  const w = window.open('', '_blank', 'width=430,height=640');
  if (!w) { alert('Popup diblokir — izinkan popup untuk mencetak label LPN.'); return; }
  const labels = items.map(it => `
    <div class="label">
      <div class="lbl-hd">
        <div class="lbl-task">${taskNo}</div>
        <div class="lbl-status">${it.status || ''}</div>
      </div>
      <div class="lbl-code" data-lpn="${String(it.lpn_code || '').replace(/"/g, '&quot;')}"></div>
      <div class="lbl-lpn">${it.lpn_code || ''}</div>
      <div class="lbl-row"><span>${it.product_code || ''}</span><span>${it.product_name || ''}</span></div>
      <div class="lbl-row"><span>Batch: ${it.batch_number || '—'}</span><span>Qty: ${Number(it.quantity || 0).toLocaleString()} ${it.uom || ''}</span></div>
      <div class="lbl-loc">Lokasi: ${it.suggested_location || '—'}</div>
    </div>`).join('');
  w.document.write(`<!doctype html><html><head><title>Label LPN</title>
    <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js"><\/script>
    <style>
      @page { size: 100mm 60mm; margin: 0; }
      body { margin: 0; font-family: Arial, sans-serif; }
      .label { width: 100mm; height: 60mm; box-sizing: border-box; padding: 6mm; border: 1px dashed #ccc; page-break-after: always; display: flex; flex-direction: column; justify-content: center; }
      .lbl-hd { display: flex; justify-content: space-between; font-size: 11px; font-weight: 700; margin-bottom: 4px; }
      .lbl-task { font-family: monospace; }
      .lbl-status { font-size: 10px; color: #607d8b; }
      .lbl-lpn { text-align: center; font-weight: 800; font-family: monospace; font-size: 15px; margin: 4px 0 6px; }
      .lbl-row { display: flex; justify-content: space-between; font-size: 11px; margin: 1px 0; }
      .lbl-loc { font-size: 11px; font-weight: 700; margin-top: 4px; }
      .lbl-code svg { width: 100% !important; }
    </style></head><body>${labels}
    <script>
      document.querySelectorAll('.lbl-code').forEach(el => {
        try { JsBarcode(el, el.dataset.lpn, { format: 'CODE128', width: 2, height: 50, displayValue: false, margin: 0 }); } catch(e) {}
      });
      window.onload = () => setTimeout(() => window.print(), 300);
    <\/script>
  </body></html>`);
  w.document.close();
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
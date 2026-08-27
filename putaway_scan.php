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
require_once __DIR__ . '/classes/Stock.php';
Auth::requireAuth();
$canWrite = Auth::canWrite();

$pageTitle   = 'Putaway Scan';
$currentPage = 'putaway_scan';

$error   = null;
$success = $_GET['success'] ?? null;
$action  = $_GET['action'] ?? 'scan';
$id      = isset($_GET['id']) ? (int)$_GET['id'] : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::requireWrite();
    try {
        if (isset($_POST['confirm_pallet'])) {
            Putaway::confirmPallet(
                (int)$_POST['item_id'],
                (string)$_POST['scanned_location'],
                $_POST['scan_override_reason'] ?? null
            );
            header('Location: putaway_scan.php?action=task&id=' . (int)$_POST['task_id'] . '&success=confirmed'); exit;
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

$uid  = $_SESSION['user_id'] ?? 0;
$mine = Putaway::listTasks(['mine' => 1, 'status' => 'In Progress']);
$open = Putaway::listTasks(['status' => 'Open']);
$taskDetail = null;
if ($action === 'task' && $id) {
    try { $taskDetail = Putaway::taskDetail($id); }
    catch (Throwable $e) { $error = $e->getMessage(); }
}

ob_end_flush();
require_once __DIR__ . '/includes/header.php';
?>
<style>
:root{--ps-primary:#026766;--ps-border:#b2dfdb;--ps-bg:#f0fdf9;--ps-muted:#607d8b}
.ps-card{background:#fff;border-radius:10px;border:1px solid var(--ps-border);box-shadow:0 2px 12px rgba(0,105,92,.08);overflow:hidden;margin-bottom:18px}
.ps-ch{padding:12px 20px;border-bottom:1px solid var(--ps-border);display:flex;align-items:center;justify-content:space-between;background:var(--ps-bg)}
.ps-ch h2{font-size:.93rem;font-weight:600;color:var(--ps-primary);margin:0}
.ps-cb{padding:20px}
.ps-tbl{width:100%;border-collapse:collapse;font-size:.875rem}
.ps-tbl thead tr{background:#e0f2f1}
.ps-tbl th{padding:9px 12px;text-align:left;font-size:.7rem;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:var(--ps-muted);border-bottom:2px solid var(--ps-border)}
.ps-tbl td{padding:9px 12px;border-bottom:1px solid #e0f2f1;color:#37474f}
.ps-tbl tbody tr:hover{background:var(--ps-bg)}
.ps-badge{display:inline-block;padding:3px 10px;border-radius:20px;font-size:.7rem;font-weight:700}
.ps-open{background:#e3f2fd;color:#01579b;border:1px solid #90caf9}
.ps-progress{background:#fff8e1;color:#e65100;border:1px solid #ffcc80}
.ps-completed{background:#e8f5e9;color:#013d3c;border:1px solid #a5d6a7}
.ps-cancelled{background:#fce4ec;color:#880e4f;border:1px solid #f48fb1}
.ps-pending{background:#fff8e1;color:#e65100;border:1px solid #ffcc80}
.ps-confirmed{background:#e8f5e9;color:#013d3c;border:1px solid #a5d6a7}
.ps-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 18px;border-radius:7px;font-size:.85rem;font-weight:600;cursor:pointer;border:none;transition:.15s;text-decoration:none}
.ps-btn-p{background:var(--ps-primary);color:#fff}.ps-btn-p:hover{background:#013d3c}
.ps-btn-g{background:#e0f2f1;color:#013d3c}.ps-btn-g:hover{background:#b2dfdb}
.ps-btn-sm{padding:4px 10px;font-size:.75rem}
.ps-inp{width:100%;padding:8px 12px;border:1.5px solid #b2dfdb;border-radius:7px;font-size:.9rem;outline:none}
.ps-inp:focus{border-color:var(--ps-primary);box-shadow:0 0 0 3px rgba(0,105,92,.1)}
.ps-alert{padding:10px 14px;border-radius:7px;font-size:.86rem;display:flex;align-items:center;gap:8px}
.ps-ok{background:#e8f5e9;border-left:4px solid #43a047;color:#013d3c}
.ps-err{background:#ffebee;border-left:4px solid #026766;color:#014f4e}
.ps-info{background:#e3f2fd;border-left:4px solid #1e88e5;color:#013d3c}
.ps-scan-box{border:2px dashed #b2dfdb;border-radius:10px;padding:20px;text-align:center;background:#f0fdf9;margin-bottom:14px}
</style>

<div style="max-width:960px;margin:0 auto;padding:18px">

<?php if($success==='confirmed'):?><div class="ps-alert ps-ok mb-4"><i class="fas fa-check-circle"></i> Pallet dikonfirmasi. Lokasi tujuan dicatat.</div><?php endif;?>
<?php if($error):?><div class="ps-alert ps-err mb-4"><i class="fas fa-exclamation-circle"></i> <?=htmlspecialchars($error)?></div><?php endif;?>

<?php if ($action === 'scan' || $action === 'task' && !$taskDetail):?>

<div style="display:flex;gap:10px;align-items:center;margin-bottom:16px;flex-wrap:wrap">
  <div style="flex:1">
    <div style="font-size:1.2rem;font-weight:700;color:#0d1f1f"><i class="fas fa-qrcode mr-2" style="color:var(--ps-primary)"></i>Putaway Scan</div>
    <div style="font-size:.82rem;color:#607d8b">Dual-scan: scan lokasi tujuan per LPN oleh operator + partner.</div>
  </div>
  <a href="?action=task&id=<?=$id?>" style="display:none"></a>
</div>

<div class="ps-card">
  <div class="ps-ch"><h2><i class="fas fa-clipboard-list mr-2"></i>Task Saya (In Progress)</h2></div>
  <div class="ps-cb">
  <?php if(empty($mine)):?>
  <div style="text-align:center;padding:24px;color:#90a4ae">
    <i class="fas fa-inbox" style="font-size:1.6rem;margin-bottom:8px;display:block"></i>
    Tidak ada task In Progress yang di-assign ke Anda.
  </div>
  <?php else:?>
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:12px">
    <?php foreach($mine as $t):?>
    <a href="?action=task&id=<?=$t['id']?>" style="text-decoration:none;color:inherit">
      <div style="border:1px solid var(--ps-border);border-radius:10px;padding:16px;background:#fff;transition:.15s"
           onmouseover="this.style.borderColor='var(--ps-primary)';this.style.boxShadow='0 4px 14px rgba(0,105,92,.12)'"
           onmouseout="this.style.borderColor='var(--ps-border)';this.style.boxShadow='none'">
        <div style="font-family:monospace;font-weight:700;color:var(--ps-primary)"><?=htmlspecialchars($t['task_number'])?></div>
        <div style="font-size:.78rem;color:#607d8b;margin-top:2px"><?=htmlspecialchars($t['order_number'])?></div>
        <div style="display:flex;justify-content:space-between;margin-top:10px;font-size:.8rem">
          <span><strong><?=$t['confirmed_count']?></strong>/<?=$t['item_count']?> pallet</span>
          <span class="ps-badge ps-progress">In Progress</span>
        </div>
        <div style="height:6px;background:#e0f2f1;border-radius:3px;margin-top:8px;overflow:hidden">
          <div style="height:100%;width:<?=$t['item_count']? (100*$t['confirmed_count']/$t['item_count']) : 0?>%;background:var(--ps-primary)"></div>
        </div>
      </div>
    </a>
    <?php endforeach;?>
  </div>
  <?php endif;?>
  </div>
</div>

<div class="ps-card">
  <div class="ps-ch"><h2><i class="fas fa-clock mr-2"></i>Task Open (belum di-assign)</h2></div>
  <div class="ps-cb">
  <?php if(empty($open)):?>
  <div style="text-align:center;padding:24px;color:#90a4ae">Tidak ada task Open. Supervisor perlu meng-assign task dulu di Putaway Tasks.</div>
  <?php else:?>
  <table class="ps-tbl">
    <thead><tr><th>No. Task</th><th>Inbound</th><th style="text-align:right">Pallet</th><th style="text-align:center">Aksi</th></tr></thead>
    <tbody>
    <?php foreach($open as $t):?>
    <tr>
      <td style="font-family:monospace;font-weight:700;color:var(--ps-primary)"><?=htmlspecialchars($t['task_number'])?></td>
      <td style="font-size:.82rem;font-family:monospace"><?=htmlspecialchars($t['order_number'])?></td>
      <td style="text-align:right"><?=$t['item_count']?></td>
      <td style="text-align:center">
        <a href="?action=task&id=<?=$t['id']?>" class="ps-btn ps-btn-g ps-btn-sm"><i class="fas fa-eye"></i> Lihat</a>
      </td>
    </tr>
    <?php endforeach;?>
    </tbody>
  </table>
  <?php endif;?>
  </div>
</div>

<?php elseif ($action === 'task' && $taskDetail): $task = $taskDetail['task']; $items = $taskDetail['items'];?>

<div style="display:flex;gap:10px;margin-bottom:14px;align-items:center">
  <a href="putaway_scan.php" class="ps-btn ps-btn-g ps-btn-sm"><i class="fas fa-arrow-left"></i> Kembali</a>
  <span style="font-size:1.1rem;font-weight:700;color:var(--ps-primary);font-family:monospace"><?=htmlspecialchars($task['task_number'])?></span>
  <?php
  $cls = ['Open'=>'ps-open','In Progress'=>'ps-progress','Completed'=>'ps-completed','Cancelled'=>'ps-cancelled'][$task['status']]??'ps-open';
  echo "<span class='ps-badge $cls'>{$task['status']}</span>";
  ?>
  <?php if(!empty($items)):?>
  <button type="button" onclick="printLpnLabels()" class="ps-btn ps-btn-g ps-btn-sm"><i class="fas fa-print"></i> Cetak Label LPN</button>
  <?php endif;?>
  <span style="font-size:.8rem;color:#607d8b;margin-left:auto">
    Operator: <strong><?=htmlspecialchars($task['assigned_name']??'—')?></strong> · Partner: <strong><?=htmlspecialchars($task['partner_name']??'—')?></strong>
  </span>
</div>

<?php if($task['status']==='Completed'):?>
<div class="ps-alert ps-ok mb-4"><i class="fas fa-check-double"></i> Task ini sudah selesai. Stok telah dipindahkan dari STAGING ke lokasi tujuan.</div>
<?php endif;?>

<div class="ps-card">
  <div class="ps-ch"><h2><i class="fas fa-boxes mr-2"></i>Pallet untuk Konfirmasi</h2></div>
  <div style="overflow-x:auto">
  <table class="ps-tbl">
    <thead><tr>
      <th>Seq</th><th>LPN</th><th>Produk</th><th>Batch</th>
      <th style="text-align:right">Qty</th><th>Saran Lokasi</th><th>Status</th><th style="text-align:center">Aksi</th>
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
      <td style="text-align:right;font-weight:600"><?=number_format($it['quantity'],2)?> <?=htmlspecialchars($it['uom']??'')?></td>
      <td><span style="font-family:monospace;font-weight:700;color:var(--ps-primary)"><?=htmlspecialchars($it['suggested_location']??'—')?></span></td>
      <td>
        <?php
        $icl = $it['status']==='Confirmed' ? 'ps-confirmed' : 'ps-pending';
        echo "<span class='ps-badge $icl'>{$it['status']}</span>";
        ?>
      </td>
      <td style="text-align:center">
        <?php if($it['status']==='Pending' && $canWrite):?>
        <button class="ps-btn ps-btn-p ps-btn-sm" onclick="openScanModal(<?=$it['id']?>,'<?=htmlspecialchars($it['lpn_code']??'',ENT_QUOTES)?>','<?=htmlspecialchars($it['suggested_location']??'',ENT_QUOTES)?>')">
          <i class="fas fa-qrcode"></i> Scan
        </button>
        <?php elseif($it['status']==='Confirmed'):?>
        <span style="color:#43a047;font-size:.85rem"><i class="fas fa-check-circle"></i></span>
        <?php endif;?>
      </td>
    </tr>
    <?php endforeach;?>
    </tbody>
  </table>
  </div>
</div>

<?php if($task['status']==='Completed' && !empty($items) && $items[0]['scan_override_reason']):?>
<div class="ps-alert ps-info mb-4">
  <i class="fas fa-info-circle"></i>
  <span>Task ini memiliki konfirmasi dengan override lokasi — catat untuk evaluasi keakuratan saran putaway.</span>
</div>
<?php endif;?>

<?php endif;?>
</div>

<!-- Scan modal -->
<div id="scanModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9999;align-items:center;justify-content:center" onclick="if(event.target===this)closeScanModal()">
  <div style="background:#fff;border-radius:14px;width:min(480px,92vw);padding:24px;box-shadow:0 20px 60px rgba(0,0,0,.3)">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px">
      <div>
        <div style="font-size:1rem;font-weight:700;color:#0d1f1f"><i class="fas fa-qrcode mr-2" style="color:var(--ps-primary)"></i>Konfirmasi Pallet</div>
        <div style="font-family:monospace;font-size:.82rem;color:#607d8b" id="scanLpnLabel">—</div>
      </div>
      <button onclick="closeScanModal()" style="background:none;border:none;font-size:1.2rem;color:#90a4ae;cursor:pointer"><i class="fas fa-times"></i></button>
    </div>
    <form method="POST" id="scanForm">
      <input type="hidden" name="item_id" id="scanItemId">
      <input type="hidden" name="task_id" value="<?=$taskDetail['task']['id']??''?>">
      <div class="ps-scan-box">
        <div style="font-size:.72rem;font-weight:700;color:#607d8b;letter-spacing:.08em;text-transform:uppercase;margin-bottom:8px">
          <i class="fas fa-map-pin mr-1"></i>Saran Lokasi
        </div>
        <div style="font-family:monospace;font-size:1.5rem;font-weight:800;color:var(--ps-primary)" id="scanSuggested">—</div>
      </div>
      <div style="margin-bottom:12px">
        <label style="font-size:.72rem;font-weight:600;color:#37474f;display:block;margin-bottom:4px">
          Scan Lokasi Tujuan <span style="color:#e53935">*</span>
        </label>
        <input type="text" name="scanned_location" id="scanLocation" class="ps-inp" placeholder="Scan barcode lokasi..." style="text-transform:uppercase;font-family:monospace;font-weight:700;font-size:1.05rem" required autofocus>
      </div>
      <div id="overrideWrap" style="display:none;margin-bottom:12px">
        <label style="font-size:.72rem;font-weight:600;color:#37474f;display:block;margin-bottom:4px">
          Lokasi tidak sesuai saran? Alasan override <span style="color:#e53935">*</span>
        </label>
        <input type="text" name="scan_override_reason" id="scanOverride" class="ps-inp" placeholder="cth: lokasi penuh, ganti ke A0102">
      </div>
      <div style="display:flex;gap:10px">
        <button type="submit" name="confirm_pallet" class="ps-btn ps-btn-p" style="flex:1;justify-content:center;padding:11px">
          <i class="fas fa-check-double"></i> Konfirmasi (Operator + Partner)
        </button>
        <button type="button" onclick="closeScanModal()" class="ps-btn ps-btn-g" style="padding:11px"><i class="fas fa-times"></i></button>
      </div>
    </form>
  </div>
</div>

<script>
function openScanModal(itemId, lpn, suggested) {
  document.getElementById('scanItemId').value = itemId;
  document.getElementById('scanLpnLabel').textContent = lpn || '—';
  document.getElementById('scanSuggested').textContent = suggested || '—';
  document.getElementById('scanLocation').value = '';
  document.getElementById('scanOverride').value = '';
  document.getElementById('overrideWrap').style.display = 'none';
  document.getElementById('scanModal').style.display = 'flex';
  setTimeout(() => document.getElementById('scanLocation').focus(), 50);
}
function closeScanModal() {
  document.getElementById('scanModal').style.display = 'none';
}
document.getElementById('scanLocation').addEventListener('input', function() {
  const sug = document.getElementById('scanSuggested').textContent.trim().toUpperCase();
  const val = this.value.trim().toUpperCase();
  const mismatch = (sug && val && val !== sug);
  document.getElementById('overrideWrap').style.display = mismatch ? 'block' : 'none';
  if (mismatch) document.getElementById('scanOverride').focus();
});

<?php if ($action === 'task' && $taskDetail): ?>
const PS_LABEL_ITEMS = <?= json_encode($items ?? []) ?>;
const PS_LABEL_TASK  = <?= json_encode($task['task_number'] ?? '') ?>;
<?php else: ?>
const PS_LABEL_ITEMS = [];
const PS_LABEL_TASK  = '';
<?php endif; ?>

function printLpnLabels() {
  const items = (typeof PS_LABEL_ITEMS !== 'undefined') ? PS_LABEL_ITEMS : [];
  if (!items.length) return;
  const taskNo = (typeof PS_LABEL_TASK !== 'undefined') ? PS_LABEL_TASK : '';
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
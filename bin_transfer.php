<?php
ob_start();
error_reporting(0);
ini_set('display_errors', '0');
session_start();
header("Cache-Control: no-store, no-cache, must-revalidate");
header("Pragma: no-cache");
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/classes/Auth.php';
require_once __DIR__ . '/classes/BinTransfer.php';
require_once __DIR__ . '/classes/ActivityLogger.php';
require_once __DIR__ . '/classes/Product.php';
Auth::requireAuth();
$canWrite = Auth::canWrite();
$canAdmin = Auth::canAdmin();

$pageTitle   = 'Bin Transfer';
$currentPage = 'bin_transfer';

$action = $_GET['action'] ?? 'list';
$id     = isset($_GET['id']) ? (int)$_GET['id'] : null;
$error  = null;
$success = $_GET['success'] ?? null;

if (isset($_GET['api']) && $_GET['api'] === 'product_locations') {
    ob_end_clean();
    header('Content-Type: application/json');
    $pid = (int)($_GET['product_id'] ?? 0);
    if ($pid) {
        echo json_encode(BinTransfer::getLocationsWithStock($pid));
    } else {
        echo json_encode([]);
    }
    exit;
}

if (isset($_GET['api']) && $_GET['api'] === 'location_stock') {
    ob_end_clean();
    header('Content-Type: application/json');
    $pid = (int)($_GET['product_id'] ?? 0);
    $loc = $_GET['location'] ?? '';
    echo json_encode(BinTransfer::getStockAtLocation($pid, $loc));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::requireWrite();
    try {
        if (isset($_POST['create_transfer'])) {
            $tid = BinTransfer::create([
                'transfer_date' => $_POST['transfer_date'],
                'product_id'    => (int)$_POST['product_id'],
                'from_location' => $_POST['from_location'],
                'to_location'   => $_POST['to_location'],
                'quantity'      => (float)$_POST['quantity'],
                'uom'           => $_POST['uom'] ?? 'Drum',
                'batch_number'  => $_POST['batch_number'] ?? null,
                'reason'        => $_POST['reason'] ?? null,
            ]);
            $tr = BinTransfer::getById($tid);
            ActivityLogger::log('BIN_TRANSFER', 'bin_transfer', 'BinTransfer', $tid,
                $tr['transfer_number'] ?? null,
                "Transfer dibuat: {$_POST['from_location']} → {$_POST['to_location']}, qty {$_POST['quantity']}");
            header('Location: bin_transfer.php?action=view&id=' . $tid . '&success=created'); exit;
        }
        if (isset($_POST['execute_transfer'])) {
            $tid = (int)$_POST['id'];
            $tr  = BinTransfer::getById($tid);
            BinTransfer::execute($tid);
            ActivityLogger::log('COMPLETE_BIN_TRANSFER', 'bin_transfer', 'BinTransfer', $tid,
                $tr['transfer_number'] ?? null,
                "Transfer dieksekusi: {$tr['from_location']} → {$tr['to_location']}, qty {$tr['quantity']}");
            header('Location: bin_transfer.php?action=view&id=' . $tid . '&success=executed'); exit;
        }
        if (isset($_POST['cancel_transfer'])) {
            $tid = (int)$_POST['id'];
            $tr  = BinTransfer::getById($tid);
            BinTransfer::cancel($tid);
            ActivityLogger::log('CANCEL_BIN_TRANSFER', 'bin_transfer', 'BinTransfer', $tid,
                $tr['transfer_number'] ?? null, "Transfer dibatalkan");
            header('Location: bin_transfer.php?action=list&success=cancelled'); exit;
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

$transfer = $id ? BinTransfer::getById($id) : null;
$products = Product::getAll();

$_btPerPage    = 50;
$_btPage       = max(1, (int)($_GET['page'] ?? 1));
$_btOffset     = ($_btPage - 1) * $_btPerPage;
$_btTotal      = ($action === 'list') ? BinTransfer::countAll() : 0;
$_btTotalPages = max(1, (int)ceil($_btTotal / $_btPerPage));
$_btPage       = min($_btPage, $_btTotalPages);
$_btOffset     = ($_btPage - 1) * $_btPerPage;
$transferList  = ($action === 'list') ? BinTransfer::getAll(null, $_btPerPage, $_btOffset) : [];

ob_end_flush();
require_once __DIR__ . '/includes/header.php';
?>
<style>
:root{--bt-primary:#026766;--bt-accent:#026766;--bt-warn:#f57c00;--bt-danger:#014f4e;--bt-border:#b2dfdb;--bt-bg:#f0fdf9;--bt-card:#fff;--bt-muted:#607d8b;--bt-r:10px;--bt-sh:0 2px 12px rgba(0,105,92,.08)}
.bt-page{font-family:'Segoe UI',system-ui,sans-serif}
.bt-hero{background:linear-gradient(135deg,#013d3c 0%,#026766 60%,#026766 100%);border-radius:var(--bt-r);padding:22px 28px;color:#fff;display:flex;align-items:center;justify-content:space-between;gap:16px;box-shadow:0 4px 18px rgba(0,105,92,.25);flex-wrap:wrap}
.bt-hero-title{font-size:1.35rem;font-weight:700;letter-spacing:-.3px}
.bt-stat{background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.25);border-radius:8px;padding:9px 15px;text-align:center;min-width:70px}
.bt-stat .n{font-size:1.3rem;font-weight:700;line-height:1}
.bt-stat .l{font-size:.68rem;opacity:.75;margin-top:2px}
.bt-card{background:#fff;border-radius:var(--bt-r);border:1px solid var(--bt-border);box-shadow:var(--bt-sh);overflow:hidden;margin-bottom:18px}
.bt-ch{padding:12px 20px;border-bottom:1px solid var(--bt-border);display:flex;align-items:center;justify-content:space-between;background:#f0fdf9}
.bt-ch h2{font-size:.93rem;font-weight:600;color:var(--bt-primary)}
.bt-cb{padding:20px}
.bt-sec{background:#f0fdf9;border:1px solid #b2dfdb;border-radius:8px;padding:16px 18px;margin-bottom:14px}
.bt-sec-title{font-size:.72rem;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--bt-primary);margin-bottom:12px;display:flex;align-items:center;gap:6px}
.bt-lbl{display:block;font-size:.76rem;font-weight:600;color:#37474f;margin-bottom:4px}
.bt-inp,.bt-sel,.bt-ta{width:100%;padding:8px 12px;border:1.5px solid #b2dfdb;border-radius:7px;font-size:.875rem;color:#263238;background:#fff;transition:border-color .15s;outline:none}
.bt-inp:focus,.bt-sel:focus{border-color:var(--bt-primary);box-shadow:0 0 0 3px rgba(0,105,92,.1)}
.bt-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 18px;border-radius:7px;font-size:.85rem;font-weight:600;cursor:pointer;border:none;transition:.15s;text-decoration:none}
.bt-primary{background:var(--bt-primary);color:#fff}.bt-primary:hover{background:#013d3c}
.bt-accent{background:#009688;color:#fff}.bt-accent:hover{background:#00796b}
.bt-warn{background:var(--bt-warn);color:#fff}.bt-warn:hover{background:#e65100}
.bt-danger{background:var(--bt-danger);color:#fff}.bt-danger:hover{background:#014f4e}
.bt-ghost{background:#e0f2f1;color:#013d3c}.bt-ghost:hover{background:#b2dfdb}
.bt-sm{padding:5px 12px;font-size:.78rem}
.bt-badge{display:inline-block;padding:3px 10px;border-radius:20px;font-size:.7rem;font-weight:700}
.bt-pending{background:#fff8e1;color:#e65100;border:1px solid #ffcc80}
.bt-completed{background:#e8f5e9;color:#013d3c;border:1px solid #a5d6a7}
.bt-cancelled{background:#fce4ec;color:#880e4f;border:1px solid #f48fb1}
.bt-tbl{width:100%;border-collapse:collapse;font-size:.875rem}
.bt-tbl thead tr{background:#e0f2f1}
.bt-tbl th{padding:9px 12px;text-align:left;font-size:.7rem;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:var(--bt-muted);border-bottom:2px solid var(--bt-border)}
.bt-tbl td{padding:9px 12px;border-bottom:1px solid #e0f2f1;color:#37474f}
.bt-tbl tbody tr:hover{background:#f0fdf9}
.bt-flow{display:flex;align-items:center;gap:10px;background:#e8f5e9;border:1px solid #a5d6a7;border-radius:8px;padding:12px 16px}
.bt-flow .loc{font-family:monospace;font-size:1rem;font-weight:700;color:#013d3c;background:#fff;border:1px solid #a5d6a7;padding:5px 14px;border-radius:6px}
.bt-flow .arrow{font-size:1.3rem;color:#026766}
.bt-alert{padding:10px 14px;border-radius:7px;font-size:.86rem;display:flex;align-items:center;gap:8px}
.bt-ok{background:#e8f5e9;border-left:4px solid #43a047;color:#013d3c}
.bt-err{background:#ffebee;border-left:4px solid #026766;color:#014f4e}
.bt-info{background:#e3f2fd;border-left:4px solid #1e88e5;color:#013d3c}
.batch-row:hover{background:#e0f7f4 !important}
</style>

<div class="bt-page" style="max-width:1080px;margin:0 auto;padding:18px">

<?php if($success==='created'):?><div class="bt-alert bt-ok mb-4"><i class="fas fa-check-circle"></i> Transfer berhasil dibuat.</div><?php endif;?>
<?php if($success==='executed'):?><div class="bt-alert bt-ok mb-4"><i class="fas fa-check-double"></i> Transfer berhasil dieksekusi — stok telah dipindahkan.</div><?php endif;?>
<?php if($success==='cancelled'):?><div class="bt-alert bt-err mb-4"><i class="fas fa-ban"></i> Transfer dibatalkan.</div><?php endif;?>
<?php if($error):?><div class="bt-alert bt-err mb-4"><i class="fas fa-exclamation-circle"></i> <?=htmlspecialchars($error)?></div><?php endif;?>

<?php

if ($action === 'list'):
    $pending   = BinTransfer::countAll('Pending');
    $completed = BinTransfer::countAll('Completed');
?>

<div class="bt-hero mb-4">
  <div>
    <div class="bt-hero-title"><i class="fas fa-exchange-alt mr-2"></i>Bin Transfer (Lokasi Transfer)</div>
    <div style="font-size:.82rem;opacity:.8;margin-top:3px">Pindahkan stok antar lokasi/bin di gudang</div>
  </div>
  <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
    <div class="bt-stat"><div class="n"><?=$pending?></div><div class="l">Pending</div></div>
    <div class="bt-stat"><div class="n"><?=$completed?></div><div class="l">Selesai</div></div>
    <?php if($canWrite):?>
    <a href="?action=create" class="bt-btn bt-accent"><i class="fas fa-plus"></i> Transfer Baru</a>
    <?php endif;?>
  </div>
</div>

<div class="bt-alert bt-info mb-4">
  <i class="fas fa-info-circle"></i>
  <div>
    <strong>Kapan pakai Bin Transfer?</strong><br>
    <span style="font-size:.83rem">Setelah outbound sebagian (partial), palet sisa bisa dipindah ke lokasi konsolidasi. Atau saat lokasi penuh dan perlu redistribusi stok.</span>
  </div>
</div>

<div class="bt-card">
  <div class="bt-ch">
    <h2><i class="fas fa-list mr-2"></i>Semua Transfer</h2>
    <input type="text" id="btSearch" placeholder="Cari produk / lokasi..." style="width:230px;padding:5px 10px;border:1px solid #b2dfdb;border-radius:6px;font-size:.83rem">
  </div>
  <div style="overflow-x:auto">
  <?php if(empty($transferList)):?>
  <div style="text-align:center;padding:40px;color:#90a4ae">
    <i class="fas fa-exchange-alt" style="font-size:2rem;margin-bottom:10px;display:block"></i>
    Belum ada transfer. <a href="?action=create" style="color:var(--bt-primary);font-weight:600">Buat transfer baru</a>
  </div>
  <?php else:?>
  <table class="bt-tbl" id="btTable">
    <thead><tr>
      <th>No. Transfer</th><th>Tanggal</th><th>Produk</th>
      <th>Dari</th><th style="text-align:center">→</th><th>Ke</th>
      <th style="text-align:right">Qty</th><th>UOM</th>
      <th>Status</th><th>Dibuat Oleh</th><th style="text-align:center">Aksi</th>
    </tr></thead>
    <tbody id="btTbody">
    <?php foreach($transferList as $tr):?>
    <tr data-s="<?=strtolower($tr['product_name'].' '.$tr['from_location'].' '.$tr['to_location'].' '.$tr['transfer_number'])?>">
      <td><a href="?action=view&id=<?=$tr['id']?>" style="font-weight:600;color:var(--bt-primary);text-decoration:none;font-family:monospace">
        <?=htmlspecialchars($tr['transfer_number'])?>
      </a></td>
      <td style="color:#546e7a"><?=date('d M Y',strtotime($tr['transfer_date']))?></td>
      <td>
        <div style="font-weight:600"><?=htmlspecialchars($tr['product_name']??'-')?></div>
        <div style="font-size:.7rem;color:#90a4ae;font-family:monospace"><?=htmlspecialchars($tr['product_code']??'')?></div>
      </td>
      <td><span style="font-family:monospace;font-size:.85rem;color:#014f4e;font-weight:700"><?=htmlspecialchars($tr['from_location'])?></span></td>
      <td style="text-align:center;color:#026766;font-size:1.1rem">→</td>
      <td><span style="font-family:monospace;font-size:.85rem;color:#013d3c;font-weight:700"><?=htmlspecialchars($tr['to_location'])?></span></td>
      <td style="text-align:right;font-weight:600"><?=number_format($tr['quantity'],2)?></td>
      <td style="font-size:.8rem;color:#546e7a"><?=htmlspecialchars($tr['uom']??'')?></td>
      <td>
        <?php
        $cls = ['Pending'=>'bt-pending','Completed'=>'bt-completed','Cancelled'=>'bt-cancelled'][$tr['status']]??'bt-pending';
        echo "<span class='bt-badge $cls'>{$tr['status']}</span>";
        ?>
      </td>
      <td style="font-size:.8rem;color:#607d8b"><?=htmlspecialchars($tr['created_by_name']??'—')?></td>
      <td style="text-align:center">
        <a href="?action=view&id=<?=$tr['id']?>" class="bt-btn bt-ghost bt-sm"><i class="fas fa-eye"></i></a>
      </td>
    </tr>
    <?php endforeach;?>
    </tbody>
  </table>
  <?php endif;?>
  </div>
  <?php if ($_btTotalPages > 1): ?>
  <div style="display:flex;gap:6px;padding:12px 16px;align-items:center;flex-wrap:wrap;border-top:1px solid var(--bt-border)">
    <?php if ($_btPage > 1): ?>
    <a href="?action=list&page=<?= $_btPage - 1 ?>" style="display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;border-radius:6px;font-size:.82rem;font-weight:600;text-decoration:none;background:#e0f2f1;color:#026766">&lsaquo;</a>
    <?php endif; ?>
    <?php
    $btStart = max(1, $_btPage - 2);
    $btEnd   = min($_btTotalPages, $_btPage + 2);
    if ($btStart > 1) echo '<span style="padding:0 4px;color:#90a4ae">…</span>';
    for ($pi = $btStart; $pi <= $btEnd; $pi++):
    ?>
    <a href="?action=list&page=<?= $pi ?>"
       style="display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;border-radius:6px;font-size:.82rem;font-weight:600;text-decoration:none;<?= $pi === $_btPage ? 'background:#026766;color:#fff' : 'background:#e0f2f1;color:#026766' ?>">
      <?= $pi ?>
    </a>
    <?php endfor; ?>
    <?php if ($btEnd < $_btTotalPages) echo '<span style="padding:0 4px;color:#90a4ae">…</span>'; ?>
    <?php if ($_btPage < $_btTotalPages): ?>
    <a href="?action=list&page=<?= $_btPage + 1 ?>" style="display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;border-radius:6px;font-size:.82rem;font-weight:600;text-decoration:none;background:#e0f2f1;color:#026766">&rsaquo;</a>
    <?php endif; ?>
    <span style="font-size:.78rem;color:#90a4ae;margin-left:4px">Hal <?= $_btPage ?> / <?= $_btTotalPages ?> (<?= number_format($_btTotal) ?> total)</span>
  </div>
  <?php endif; ?>
</div>
<script>
document.getElementById('btSearch').addEventListener('input', function() {
  const q = this.value.toLowerCase();
  document.querySelectorAll('#btTbody tr').forEach(r => {
    r.style.display = (!q || (r.dataset.s||'').includes(q)) ? '' : 'none';
  });
});
</script>

<?php

elseif ($action === 'create' && $canWrite):
?>
<div style="margin-bottom:14px">
  <a href="?action=list" class="bt-btn bt-ghost bt-sm"><i class="fas fa-arrow-left"></i> Kembali</a>
</div>
<div class="bt-card">
  <div class="bt-ch">
    <h2><i class="fas fa-exchange-alt mr-2"></i>Buat Bin Transfer Baru</h2>
  </div>
  <div class="bt-cb">

    <div class="bt-alert bt-info mb-4">
      <i class="fas fa-lightbulb"></i>
      <span>Transfer ini akan memindahkan stok secara fisik dan mencatatnya di ledger. Sistem mengikuti FEFO (expiry terdekat diambil lebih dulu).</span>
    </div>

    <form method="POST" id="transferForm">
    
    <div class="bt-sec">
      <div class="bt-sec-title"><i class="fas fa-box"></i> Produk & Tanggal</div>
      <div style="display:grid;grid-template-columns:2fr 1fr;gap:14px">
        <div>
          <label class="bt-lbl">Produk <span style="color:red">*</span></label>
          <input type="hidden" name="product_id" id="productId" required>
          <div style="position:relative">
            <input type="text" id="productSearch" class="bt-inp" placeholder="Ketik kode/nama produk..."
                   autocomplete="off" oninput="searchProducts(this.value)" onblur="hideDropDelay()">
            <div id="productDrop" onmousedown="event.preventDefault()"
                 style="display:none;position:absolute;top:100%;left:0;right:0;z-index:9999;
                        background:#fff;border:1px solid #b2dfdb;border-radius:8px;
                        box-shadow:0 6px 20px rgba(0,0,0,.12);max-height:220px;overflow-y:auto;margin-top:2px"></div>
          </div>
        </div>
        <div>
          <label class="bt-lbl">Tanggal Transfer <span style="color:red">*</span></label>
          <input type="date" name="transfer_date" class="bt-inp" value="<?=date('Y-m-d')?>" required>
        </div>
      </div>
    </div>

    <!-- Location Transfer -->
    <div class="bt-sec" id="locationSec" style="display:none">
      <div class="bt-sec-title"><i class="fas fa-map-marker-alt"></i> Lokasi Transfer</div>
      <div style="display:grid;grid-template-columns:1fr 40px 1fr;gap:0;align-items:start">

        <!-- FROM -->
        <div style="background:#f0fdf9;border:1.5px solid #b2dfdb;border-radius:8px;padding:14px">
          <div style="font-size:.68rem;font-weight:700;letter-spacing:.06em;color:#026766;margin-bottom:8px">
            <i class="fas fa-arrow-circle-right mr-1"></i>DARI LOKASI
          </div>
          <select name="from_location" id="fromLocation" class="bt-sel" required onchange="loadFromStock()">
            <option value="">— Pilih lokasi sumber —</option>
          </select>
          <div id="fromStockInfo" style="margin-top:10px;font-size:.8rem;line-height:1.7;color:#37474f"></div>
        </div>

        <!-- Arrow -->
        <div style="display:flex;align-items:center;justify-content:center;height:48px;margin-top:28px">
          <i class="fas fa-arrow-right" style="font-size:1.2rem;color:#026766"></i>
        </div>

        <!-- TO -->
        <div style="background:#f0fdf9;border:1.5px solid #b2dfdb;border-radius:8px;padding:14px">
          <div style="font-size:.68rem;font-weight:700;letter-spacing:.06em;color:#026766;margin-bottom:8px">
            <i class="fas fa-map-pin mr-1"></i>KE LOKASI
          </div>
          <div style="position:relative">
            <input type="text" name="to_location" id="toLocation" class="bt-inp"
                   placeholder="Ketik kode lokasi tujuan..."
                   autocomplete="off" oninput="searchToLocation(this.value)" onblur="hideToDropDelay()"
                   style="text-transform:uppercase">
            <div id="toLocDrop" onmousedown="event.preventDefault()"
                 style="display:none;position:absolute;top:100%;left:0;right:0;z-index:9999;background:#fff;
                        border:1px solid #b2dfdb;border-radius:8px;box-shadow:0 6px 20px rgba(0,0,0,.12);
                        max-height:180px;overflow-y:auto;margin-top:2px"></div>
          </div>
          <div id="toLocHint" style="margin-top:8px;font-size:.78rem;min-height:20px"></div>
        </div>
      </div>
    </div>

    <!-- Qty & Batch -->
    <div class="bt-sec" id="qtySec" style="display:none">
      <div class="bt-sec-title"><i class="fas fa-weight-hanging"></i> Jumlah & Detail Stok</div>

      <!-- Batch list (FEFO) -->
      <div id="batchList" style="margin-bottom:14px"></div>

      <div style="display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:14px">
        <div>
          <label class="bt-lbl">Quantity <span style="color:red">*</span></label>
          <input type="number" name="quantity" id="qtyInput" class="bt-inp"
                 min="0.01" step="0.01" placeholder="0.00" required oninput="validateQty()">
          <div id="maxQtyHint" style="font-size:.72rem;color:var(--bt-muted);margin-top:3px"></div>
        </div>
        <div>
          <label class="bt-lbl">UOM</label>
          <input type="text" name="uom" id="uomDisplay" class="bt-inp"
                 readonly style="background:#f0fdf9;font-weight:700;color:#026766">
        </div>
        <div>
          <label class="bt-lbl">Batch Number</label>
          <input type="text" name="batch_number" id="batchInput" class="bt-inp"
                 readonly style="background:#f0fdf9;font-family:monospace;font-weight:700">
        </div>
        <div>
          <label class="bt-lbl">Expiry Date</label>
          <input type="text" id="expiryDisplay" class="bt-inp"
                 readonly style="background:#f0fdf9;color:#e65100;font-weight:600">
        </div>
      </div>
      <div style="margin-top:12px">
        <label class="bt-lbl">Alasan Transfer</label>
        <textarea name="reason" class="bt-ta" rows="2"
                  placeholder="Contoh: Konsolidasi setelah partial outbound, lokasi penuh, relayout gudang..."></textarea>
      </div>
    </div>

    <div id="submitSec" style="display:none;display:flex;gap:12px;margin-top:4px">
      <button type="submit" name="create_transfer" class="bt-btn bt-primary" style="flex:1;justify-content:center;padding:11px">
        <i class="fas fa-check"></i> Buat Transfer
      </button>
      <a href="?action=list" class="bt-btn bt-ghost" style="flex:1;justify-content:center;padding:11px">
        <i class="fas fa-times"></i> Batal
      </a>
    </div>
    </form>
  </div>
</div>

<script>
const allProducts = <?=json_encode($products, JSON_HEX_TAG|JSON_UNESCAPED_UNICODE)?>;
let selectedProductId = null;
let fromLocations = [];
let fromStockRows = [];
let allLocations  = [];

fetch('api_locations.php?action=list&limit=500')
  .then(r=>r.json())
  .then(d=>{ allLocations = (d.locations||[]).map(l=>l.location_code).filter(Boolean); })
  .catch(()=>{});

function searchProducts(q) {
  const dd = document.getElementById('productDrop');
  if (!q) { dd.style.display='none'; return; }
  const hits = allProducts.filter(p =>
    (p.product_code||'').toLowerCase().includes(q.toLowerCase()) ||
    (p.product_name||'').toLowerCase().includes(q.toLowerCase())
  ).slice(0,12);
  if (!hits.length) { dd.style.display='none'; return; }
  dd.innerHTML = hits.map(p =>
    `<div onclick="selectProduct(${p.id},'${(p.product_code||'').replace(/'/g,'\\\'')}',' ${(p.product_name||'').replace(/'/g,'\\\'')}')"
      style="padding:8px 14px;cursor:pointer;font-size:.85rem;display:flex;gap:10px;align-items:center;border-bottom:1px solid #e0f2f1"
      onmouseover="this.style.background='#e0f2f1'" onmouseout="this.style.background=''">
      <span style="font-family:monospace;color:#026766;font-weight:700;font-size:.78rem">${p.product_code||''}</span>
      <span>${p.product_name||''}</span>
    </div>`
  ).join('');
  dd.style.display='block';
}
function selectProduct(id, code, name) {
  selectedProductId = id;
  document.getElementById('productId').value = id;
  document.getElementById('productSearch').value = (code+' '+name).trim();
  document.getElementById('productDrop').style.display='none';
  loadLocations(id);
}
function hideDropDelay() { setTimeout(()=>document.getElementById('productDrop').style.display='none',200); }

function loadLocations(pid) {
  fetch(`bin_transfer.php?api=product_locations&product_id=${pid}`)
    .then(r=>r.json()).then(locs => {
      fromLocations = locs;
      const sel = document.getElementById('fromLocation');
      sel.innerHTML = '<option value="">— Pilih lokasi sumber —</option>';
      if (locs.length===0) {
        sel.innerHTML += '<option disabled>Tidak ada stok tersedia</option>';
      } else {
        locs.forEach(l => {
          sel.innerHTML += `<option value="${l.location}"
            data-qty="${l.total_qty}" data-uom="${l.uom||''}">
            ${l.location}  (stok: ${parseFloat(l.total_qty).toFixed(0)} ${l.uom||''})
          </option>`;
        });
      }
      document.getElementById('locationSec').style.display='block';
      document.getElementById('submitSec').style.display='none';
      document.getElementById('qtySec').style.display='none';
    });
}
function loadFromStock() {
  const sel = document.getElementById('fromLocation');
  const loc = sel.value;
  if (!loc || !selectedProductId) return;

  document.getElementById('fromStockInfo').innerHTML =
    '<span style="color:#90a4ae"><i class="fas fa-spinner fa-spin"></i> Memuat...</span>';
  document.getElementById('qtySec').style.display = 'none';
  document.getElementById('submitSec').style.display = 'none';

  fetch(`bin_transfer.php?api=location_stock&product_id=${selectedProductId}&location=${encodeURIComponent(loc)}`)
    .then(r=>r.json()).then(rows=>{
      fromStockRows = rows;
      if (!rows.length) {
        document.getElementById('fromStockInfo').innerHTML =
          '<span style="color:#e53935"><i class="fas fa-exclamation-circle"></i> Tidak ada stok di lokasi ini</span>';
        return;
      }

      // Total stock summary in from-card
      const totalQty = rows.reduce((s,r)=>s+parseFloat(r.quantity||0),0);
      const uom      = rows[0].uom || '';
      const batches  = rows.length;
      document.getElementById('fromStockInfo').innerHTML =
        `<div style="display:flex;flex-direction:column;gap:4px">
          <div><i class="fas fa-boxes" style="color:#026766;width:14px"></i> <strong>${totalQty.toFixed(0)} ${uom}</strong> total stok</div>
          <div><i class="fas fa-layer-group" style="color:#026766;width:14px"></i> ${batches} batch</div>
         </div>`;

      // Build batch table
      const bl = document.getElementById('batchList');
      bl.innerHTML = '<div style="font-size:.75rem;font-weight:700;color:#37474f;margin-bottom:6px">'
        + '<i class="fas fa-sort-amount-up-alt mr-1" style="color:#026766"></i>Batch tersedia — FEFO (expiry terdekat di atas):</div>'
        + '<div style="border:1px solid #b2dfdb;border-radius:7px;overflow:hidden">'
        + rows.map((r,i)=>`
          <div class="batch-row" data-idx="${i}"
               onclick="selectBatch(${i})"
               style="display:grid;grid-template-columns:auto 1fr 80px 100px 100px;gap:10px;
                      align-items:center;padding:9px 14px;cursor:pointer;
                      border-bottom:${i<rows.length-1?'1px solid #e0f2f1':'none'};
                      background:${i===0?'#e0f7f4':'#fff'};
                      font-size:.82rem">
            <div style="width:20px;height:20px;border-radius:50%;display:flex;align-items:center;justify-content:center;
                        font-size:.65rem;font-weight:700;background:${i===0?'#026766':'#b2dfdb'};color:${i===0?'#fff':'#37474f'}">
              ${i+1}
            </div>
            <div style="font-family:monospace;font-weight:700;color:#013d3c">${r.batch_number||'—'}</div>
            <div style="text-align:right;font-weight:700;color:#026766">${parseFloat(r.quantity).toFixed(0)}</div>
            <div style="color:#546e7a">${r.uom||''}</div>
            <div style="color:${r.expiry_date?'#e65100':'#90a4ae'};font-size:.78rem">
              ${r.expiry_date ? '<i class="fas fa-calendar-alt mr-1"></i>'+fmtDate(r.expiry_date) : '—'}
            </div>
          </div>`
        ).join('')
        + '</div>';

      // Auto-select first batch (FEFO)
      selectBatch(0);

      document.getElementById('qtySec').style.display = 'block';
      document.getElementById('submitSec').style.display = 'flex';
    });
}

function fmtDate(d) {
  if (!d) return '—';
  const dt = new Date(d);
  const months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
  return dt.getDate() + ' ' + months[dt.getMonth()] + ' ' + dt.getFullYear();
}

function selectBatch(idx) {
  const r = fromStockRows[idx];
  if (!r) return;
  // Highlight selected row
  document.querySelectorAll('.batch-row').forEach((el,i)=>{
    el.style.background = i===idx ? '#e0f7f4' : '#fff';
    el.querySelector('div:first-child').style.background = i===idx ? '#026766' : '#b2dfdb';
    el.querySelector('div:first-child').style.color = i===idx ? '#fff' : '#37474f';
  });
  // Fill fields
  document.getElementById('batchInput').value  = r.batch_number || '';
  document.getElementById('uomDisplay').value   = r.uom || '';
  document.getElementById('expiryDisplay').value = r.expiry_date ? fmtDate(r.expiry_date) : '—';
  document.getElementById('qtyInput').max        = r.quantity;
  document.getElementById('maxQtyHint').textContent = `Maks: ${parseFloat(r.quantity).toFixed(2)} ${r.uom||''}`;
  // Set qty to full batch qty as default
  if (!document.getElementById('qtyInput').value) {
    document.getElementById('qtyInput').value = parseFloat(r.quantity).toFixed(2);
  }
  validateQty();
}

function searchToLocation(q) {
  const dd = document.getElementById('toLocDrop');
  if (!q) { dd.style.display='none'; return; }
  const hits = allLocations.filter(l=>String(l).toUpperCase().includes(q.toUpperCase())).slice(0,15);
  if (!hits.length) { dd.style.display='none'; return; }
  dd.innerHTML = hits.map(l =>
    `<div onclick="setToLoc('${String(l).replace(/'/g,'\\\'')}')"
      style="padding:8px 14px;cursor:pointer;font-size:.85rem;font-family:monospace;border-bottom:1px solid #e0f2f1"
      onmouseover="this.style.background='#e0f2f1'" onmouseout="this.style.background=''">
      ${l}
    </div>`
  ).join('');
  dd.style.display='block';
}
function setToLoc(loc) {
  var inp = document.getElementById('toLocation');
  inp.value = loc;
  document.getElementById('toLocDrop').style.display='none';
  validateToLoc();
}
function hideToDropDelay() { setTimeout(()=>{ document.getElementById('toLocDrop').style.display='none'; validateToLoc(); },200); }

function validateToLoc() {
  var inp  = document.getElementById('toLocation');
  var hint = document.getElementById('toLocHint');
  var val  = inp.value.trim().toUpperCase();
  if (!val) { inp.style.borderColor='#b2dfdb'; if(hint) hint.innerHTML=''; return true; }
  var special = ['QUA_SHELL','STAGING'];
  var valid = special.includes(val) || allLocations.includes(val);
  inp.style.borderColor = valid ? '#43a047' : '#e53935';
  if (hint) {
    hint.innerHTML = valid
      ? '<span style="color:#43a047"><i class="fas fa-check-circle"></i> Lokasi valid</span>'
      : '<span style="color:#e53935"><i class="fas fa-times-circle"></i> Lokasi tidak ditemukan di master</span>';
  }
  return valid;
}

document.getElementById('transferForm').addEventListener('submit', function(e) {
  if (!validateToLoc()) {
    e.preventDefault();
    document.getElementById('toLocation').focus();
  }
});

function validateQty() {
  const q = parseFloat(document.getElementById('qtyInput').value||0);
  const maxEl = document.getElementById('qtyInput').max;
  const max = parseFloat(maxEl||9999);
  document.getElementById('qtyInput').style.borderColor = (q>0&&q<=max) ? '#43a047' : '#026766';
}
</script>

<?php

elseif ($action === 'view' && $transfer):
$statusCls = ['Pending'=>'bt-pending','Completed'=>'bt-completed','Cancelled'=>'bt-cancelled'][$transfer['status']]??'bt-pending';
?>
<div style="display:flex;gap:10px;margin-bottom:14px;align-items:center">
  <a href="?action=list" class="bt-btn bt-ghost bt-sm"><i class="fas fa-arrow-left"></i> Kembali</a>
  <span style="font-size:1.1rem;font-weight:700;color:var(--bt-primary);font-family:monospace">
    <?=htmlspecialchars($transfer['transfer_number'])?>
  </span>
  <span class="bt-badge <?=$statusCls?>"><?=$transfer['status']?></span>
</div>

<div class="bt-card">
  <div class="bt-cb">
    <div class="bt-flow" style="margin-bottom:14px">
      <div>
        <div style="font-size:.68rem;color:#546e7a;font-weight:600;margin-bottom:3px">DARI LOKASI</div>
        <div class="loc" style="color:#014f4e"><?=htmlspecialchars($transfer['from_location'])?></div>
      </div>
      <div class="arrow"><i class="fas fa-long-arrow-alt-right"></i></div>
      <div>
        <div style="font-size:.68rem;color:#546e7a;font-weight:600;margin-bottom:3px">KE LOKASI</div>
        <div class="loc" style="color:#013d3c"><?=htmlspecialchars($transfer['to_location'])?></div>
      </div>
      <div style="margin-left:auto;text-align:right">
        <div style="font-size:.68rem;color:#546e7a;font-weight:600;margin-bottom:3px">JUMLAH</div>
        <div style="font-size:1.2rem;font-weight:700;color:#013d3c">
          <?=number_format($transfer['quantity'],2)?> <?=htmlspecialchars($transfer['uom']??'')?>
        </div>
      </div>
    </div>
    <!-- Row 1: Product (full width) -->
    <div style="padding:10px 14px;background:#f8fafc;border-radius:7px;margin-bottom:10px">
      <div style="font-size:.68rem;color:#90a4ae;font-weight:700;letter-spacing:.04em;margin-bottom:3px">PRODUK</div>
      <div style="font-weight:700;font-size:.95rem;color:#0f172a;word-break:break-word">
        <?=htmlspecialchars($transfer['product_name']??'—')?>
      </div>
      <div style="font-size:.75rem;font-family:monospace;color:#90a4ae;margin-top:2px">
        <?=htmlspecialchars($transfer['product_code']??'')?>
      </div>
    </div>
    <!-- Row 2: Batch | Tanggal | Dibuat Oleh | Dieksekusi -->
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:12px">
      <div>
        <div style="font-size:.68rem;color:#90a4ae;font-weight:700;letter-spacing:.04em;margin-bottom:3px">BATCH</div>
        <div style="font-family:monospace;font-weight:700;color:#013d3c"><?=htmlspecialchars($transfer['batch_number']??'—')?></div>
      </div>
      <div>
        <div style="font-size:.68rem;color:#90a4ae;font-weight:700;letter-spacing:.04em;margin-bottom:3px">TANGGAL</div>
        <div><?=date('d M Y',strtotime($transfer['transfer_date']))?></div>
      </div>
      <div>
        <div style="font-size:.68rem;color:#90a4ae;font-weight:700;letter-spacing:.04em;margin-bottom:3px">DIBUAT OLEH</div>
        <div><?=htmlspecialchars($transfer['created_by_name']??'—')?></div>
      </div>
      <?php if($transfer['completed_by_name']??''):?>
      <div>
        <div style="font-size:.68rem;color:#90a4ae;font-weight:700;letter-spacing:.04em;margin-bottom:3px">DIEKSEKUSI OLEH</div>
        <div><?=htmlspecialchars($transfer['completed_by_name'])?></div>
        <?php if($transfer['completed_at']):?>
        <div style="font-size:.72rem;color:#90a4ae"><?=date('d M Y H:i',strtotime($transfer['completed_at']))?></div>
        <?php endif;?>
      </div>
      <?php endif;?>
    </div>
    <?php if($transfer['reason']??''):?>
    <div style="margin-top:10px;padding:10px 14px;background:#fffde7;border-radius:7px;border-left:3px solid #f9a825">
      <div style="font-size:.68rem;color:#90a4ae;font-weight:700;letter-spacing:.04em;margin-bottom:3px">ALASAN</div>
      <div style="font-size:.88rem"><?=htmlspecialchars($transfer['reason'])?></div>
    </div>
    <?php endif;?>
  </div>
</div>

<?php if($transfer['status']==='Pending' && $canWrite):?>
<div style="display:flex;gap:10px;margin-bottom:18px">
  <form method="POST" onsubmit="return confirm('Eksekusi transfer?')">
    <input type="hidden" name="id" value="<?=$transfer['id']?>">
    <button type="submit" name="execute_transfer" class="bt-btn bt-primary">
      <i class="fas fa-play-circle"></i> Eksekusi Transfer
    </button>
  </form>
  <form method="POST" onsubmit="return confirm('Batalkan transfer ini?')">
    <input type="hidden" name="id" value="<?=$transfer['id']?>">
    <button type="submit" name="cancel_transfer" class="bt-btn bt-danger">
      <i class="fas fa-ban"></i> Batalkan
    </button>
  </form>
</div>
<?php endif;?>

<?php if($transfer['status']==='Completed'):?>
<div class="bt-alert bt-ok" style="margin-bottom:16px">
  <i class="fas fa-check-double"></i>
  <span>Transfer telah selesai dieksekusi. Stok telah dipindahkan dan dicatat di ledger.</span>
</div>
<?php endif;?>

<?php
$logs = ActivityLogger::getForReference('BinTransfer', $transfer['id']);
if (!empty($logs)):?>
<div class="bt-card">
  <div class="bt-ch"><h2><i class="fas fa-history mr-2"></i>Riwayat Aktivitas</h2></div>
  <div style="overflow-x:auto">
  <table class="bt-tbl">
    <thead><tr><th>Waktu</th><th>Aksi</th><th>User</th><th>Keterangan</th></tr></thead>
    <tbody>
    <?php foreach($logs as $lg):?>
    <tr>
      <td style="font-size:.78rem;color:#90a4ae;white-space:nowrap"><?=date('d M Y H:i',strtotime($lg['created_at']))?></td>
      <td><span style="font-size:.75rem;font-weight:700;color:var(--bt-primary)"><?=ActivityLogger::actionLabel($lg['action'])?></span></td>
      <td style="font-size:.83rem"><?=htmlspecialchars($lg['full_name']??$lg['username']??'—')?></td>
      <td style="font-size:.83rem;color:#546e7a"><?=htmlspecialchars($lg['description']??'—')?></td>
    </tr>
    <?php endforeach;?>
    </tbody>
  </table>
  </div>
</div>
<?php endif;?>

<?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

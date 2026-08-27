<?php
ob_start();
error_reporting(0);
ini_set('display_errors', '0');
session_start();
header("Cache-Control: no-store, no-cache, must-revalidate");
header("Pragma: no-cache");
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/classes/Auth.php';
require_once __DIR__ . '/classes/Asn.php';
require_once __DIR__ . '/classes/Product.php';
Auth::requireAuth();
$canWrite = Auth::canWrite();

$pageTitle   = 'ASN (Advance Shipping Notice)';
$currentPage = 'asn';

$error   = null;
$success = $_GET['success'] ?? null;
$action  = $_GET['action'] ?? 'list';
$id      = isset($_GET['id']) ? (int)$_GET['id'] : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::requireWrite();
    try {
        if (isset($_POST['create_asn'])) {
            $items = [];
            $pids  = $_POST['item_product_id'] ?? [];
            $qtys  = $_POST['item_qty'] ?? [];
            $uoms  = $_POST['item_uom'] ?? [];
            $batches = $_POST['item_batch'] ?? [];
            foreach ($pids as $i => $pid) {
                if (!(int)$pid) continue;
                $items[] = [
                    'product_id'   => (int)$pid,
                    'expected_qty' => (float)($qtys[$i] ?? 0),
                    'uom'          => $uoms[$i] ?? null,
                    'batch_number' => $batches[$i] ?? null,
                ];
            }
            $asnId = Asn::create([
                'supplier_name'         => $_POST['supplier_name'],
                'supplier_reference'    => $_POST['supplier_reference'] ?? null,
                'expected_arrival_date' => $_POST['expected_arrival_date'],
                'notes'                 => $_POST['notes'] ?? null,
                'items'                 => $items,
            ]);
            header('Location: asn.php?action=view&id=' . $asnId . '&success=created'); exit;
        }
        if (isset($_POST['update_asn'])) {
            $items = [];
            $pids  = $_POST['item_product_id'] ?? [];
            $qtys  = $_POST['item_qty'] ?? [];
            $uoms  = $_POST['item_uom'] ?? [];
            $batches = $_POST['item_batch'] ?? [];
            foreach ($pids as $i => $pid) {
                if (!(int)$pid) continue;
                $items[] = [
                    'product_id'   => (int)$pid,
                    'expected_qty' => (float)($qtys[$i] ?? 0),
                    'uom'          => $uoms[$i] ?? null,
                    'batch_number' => $batches[$i] ?? null,
                ];
            }
            Asn::update((int)$_POST['id'], [
                'supplier_name'         => $_POST['supplier_name'],
                'supplier_reference'    => $_POST['supplier_reference'] ?? null,
                'expected_arrival_date' => $_POST['expected_arrival_date'],
                'notes'                 => $_POST['notes'] ?? null,
                'items'                 => $items,
            ]);
            header('Location: asn.php?action=view&id=' . (int)$_POST['id'] . '&success=updated'); exit;
        }
        if (isset($_POST['cancel_asn'])) {
            Asn::cancel((int)$_POST['id']);
            header('Location: asn.php?success=cancelled'); exit;
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

$asns     = Asn::list();
$detail   = $id ? Asn::detail($id) : null;
$products = Product::getAll();

ob_end_flush();
require_once __DIR__ . '/includes/header.php';
?>
<style>
:root{--as-primary:#026766;--as-border:#b2dfdb;--as-bg:#f0fdf9;--as-muted:#607d8b}
.as-card{background:#fff;border-radius:10px;border:1px solid var(--as-border);box-shadow:0 2px 12px rgba(0,105,92,.08);overflow:hidden;margin-bottom:18px}
.as-ch{padding:12px 20px;border-bottom:1px solid var(--as-border);display:flex;align-items:center;justify-content:space-between;background:var(--as-bg)}
.as-ch h2{font-size:.93rem;font-weight:600;color:var(--as-primary);margin:0}
.as-cb{padding:20px}
.as-tbl{width:100%;border-collapse:collapse;font-size:.875rem}
.as-tbl thead tr{background:#e0f2f1}
.as-tbl th{padding:9px 12px;text-align:left;font-size:.7rem;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:var(--as-muted);border-bottom:2px solid var(--as-border)}
.as-tbl td{padding:9px 12px;border-bottom:1px solid #e0f2f1;color:#37474f}
.as-tbl tbody tr:hover{background:var(--as-bg)}
.as-badge{display:inline-block;padding:3px 10px;border-radius:20px;font-size:.7rem;font-weight:700}
.as-pending{background:#fff8e1;color:#e65100;border:1px solid #ffcc80}
.as-received{background:#e8f5e9;color:#013d3c;border:1px solid #a5d6a7}
.as-cancelled{background:#fce4ec;color:#880e4f;border:1px solid #f48fb1}
.as-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 18px;border-radius:7px;font-size:.85rem;font-weight:600;cursor:pointer;border:none;transition:.15s;text-decoration:none}
.as-btn-p{background:var(--as-primary);color:#fff}.as-btn-p:hover{background:#013d3c}
.as-btn-g{background:#e0f2f1;color:#013d3c}.as-btn-g:hover{background:#b2dfdb}
.as-btn-r{background:#014f4e;color:#fff}.as-btn-r:hover{background:#012d2c}
.as-btn-sm{padding:4px 10px;font-size:.75rem}
.as-inp{width:100%;padding:7px 11px;border:1.5px solid #b2dfdb;border-radius:7px;font-size:.85rem;outline:none}
.as-inp:focus{border-color:var(--as-primary);box-shadow:0 0 0 3px rgba(0,105,92,.1)}
.as-alert{padding:10px 14px;border-radius:7px;font-size:.86rem;display:flex;align-items:center;gap:8px}
.as-ok{background:#e8f5e9;border-left:4px solid #43a047;color:#013d3c}
.as-err{background:#ffebee;border-left:4px solid #026766;color:#014f4e}
.as-info{background:#e3f2fd;border-left:4px solid #1e88e5;color:#013d3c}
.as-rm{background:none;border:none;color:#e53935;cursor:pointer;font-size:.95rem;padding:4px 8px}
.as-rm:hover{color:#b71c1c}
</style>

<div style="max-width:1080px;margin:0 auto;padding:18px">

<?php if($success==='created'):?><div class="as-alert as-ok mb-4"><i class="fas fa-check-circle"></i> ASN berhasil dibuat — bisa langsung digunakan untuk prefill Inbound.</div><?php endif;?>
<?php if($success==='updated'):?><div class="as-alert as-ok mb-4"><i class="fas fa-check-circle"></i> ASN diperbarui.</div><?php endif;?>
<?php if($success==='cancelled'):?><div class="as-alert as-info mb-4"><i class="fas fa-info-circle"></i> ASN dibatalkan.</div><?php endif;?>
<?php if($error):?><div class="as-alert as-err mb-4"><i class="fas fa-exclamation-circle"></i> <?=htmlspecialchars($error)?></div><?php endif;?>

<?php if ($action === 'list'):?>

<div style="display:flex;gap:10px;align-items:center;margin-bottom:16px;flex-wrap:wrap">
  <div style="flex:1">
    <div style="font-size:1.2rem;font-weight:700;color:#0d1f1f"><i class="fas fa-file-invoice mr-2" style="color:var(--as-primary)"></i>Advance Shipping Notice</div>
    <div style="font-size:.82rem;color:#607d8b">Notifikasi kedatangan supplier untuk prefill inbound order.</div>
  </div>
  <?php if($canWrite):?>
  <a href="?action=create" class="as-btn as-btn-p"><i class="fas fa-plus"></i> Buat ASN</a>
  <?php endif;?>
</div>

<div class="as-card">
  <div class="as-ch"><h2><i class="fas fa-list mr-2"></i>Semua ASN</h2></div>
  <div style="overflow-x:auto">
  <?php if(empty($asns)):?>
  <div style="text-align:center;padding:40px;color:#90a4ae">
    <i class="fas fa-file-invoice" style="font-size:2rem;margin-bottom:10px;display:block"></i>
    Belum ada ASN.
  </div>
  <?php else:?>
  <table class="as-tbl">
    <thead><tr>
      <th>No. ASN</th><th>Supplier</th><th>Ref</th><th>E.T.A</th>
      <th style="text-align:right">Item</th><th style="text-align:right">Qty</th>
      <th>Status</th><th style="text-align:center">Aksi</th>
    </tr></thead>
    <tbody>
    <?php foreach($asns as $a):?>
    <tr>
      <td><a href="?action=view&id=<?=$a['id']?>" style="font-weight:700;color:var(--as-primary);text-decoration:none;font-family:monospace"><?=htmlspecialchars($a['asn_number'])?></a></td>
      <td>
        <div style="font-weight:600"><?=htmlspecialchars($a['supplier_name'])?></div>
      </td>
      <td style="font-size:.8rem;color:#546e7a;font-family:monospace"><?=htmlspecialchars($a['supplier_reference']??'—')?></td>
      <td style="font-size:.82rem"><?=date('d M Y',strtotime($a['expected_arrival_date']))?></td>
      <td style="text-align:right"><?=$a['item_count']?></td>
      <td style="text-align:right;font-weight:600"><?=number_format($a['expected_total'],0)?></td>
      <td>
        <?php
        $cls = ['Pending'=>'as-pending','Received'=>'as-received','Cancelled'=>'as-cancelled'][$a['status']]??'as-pending';
        echo "<span class='as-badge $cls'>{$a['status']}</span>";
        ?>
      </td>
      <td style="text-align:center">
        <a href="?action=view&id=<?=$a['id']?>" class="as-btn as-btn-g as-btn-sm"><i class="fas fa-eye"></i></a>
      </td>
    </tr>
    <?php endforeach;?>
    </tbody>
  </table>
  <?php endif;?>
  </div>
</div>

<?php elseif (($action === 'create' || $action === 'edit') && $canWrite): $editing = ($action==='edit' && $detail); $asn = $editing ? $detail['asn'] : null; $items = $editing ? $detail['items'] : [];?>

<div style="display:flex;gap:10px;margin-bottom:14px;align-items:center">
  <a href="?action=list" class="as-btn as-btn-g as-btn-sm"><i class="fas fa-arrow-left"></i> Kembali</a>
  <span style="font-size:1rem;font-weight:700;color:var(--as-primary)"><?=$editing?'Edit':'Buat'?> ASN</span>
</div>

<div class="as-card">
  <div class="as-ch"><h2><i class="fas fa-file-invoice mr-2"></i><?=$editing?htmlspecialchars($asn['asn_number']):'Form ASN Baru'?></h2></div>
  <div class="as-cb">
    <form method="POST" id="asnForm">
      <?php if($editing):?><input type="hidden" name="id" value="<?=$asn['id']?>"><?php endif;?>
      <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-bottom:14px">
        <div>
          <label style="font-size:.72rem;font-weight:600;color:#37474f;display:block;margin-bottom:3px">Supplier <span style="color:#e53935">*</span></label>
          <input type="text" name="supplier_name" class="as-inp" required value="<?=$editing?htmlspecialchars($asn['supplier_name']):''?>">
        </div>
        <div>
          <label style="font-size:.72rem;font-weight:600;color:#37474f;display:block;margin-bottom:3px">Supplier Reference</label>
          <input type="text" name="supplier_reference" class="as-inp" value="<?=$editing?htmlspecialchars($asn['supplier_reference']??''):''?>">
        </div>
        <div>
          <label style="font-size:.72rem;font-weight:600;color:#37474f;display:block;margin-bottom:3px">E.T.A <span style="color:#e53935">*</span></label>
          <input type="date" name="expected_arrival_date" class="as-inp" required value="<?=$editing?htmlspecialchars($asn['expected_arrival_date']):''?>">
        </div>
      </div>

      <div style="font-size:.75rem;font-weight:700;color:#37474f;margin-bottom:6px">
        <i class="fas fa-boxes mr-1" style="color:var(--as-primary)"></i>Item ASN
      </div>
      <div id="asnItems" style="margin-bottom:12px">
        <?php foreach($items as $it):?>
        <div class="asn-item-row" style="display:grid;grid-template-columns:2fr 1fr .8fr 1.2fr auto;gap:10px;margin-bottom:8px">
          <select name="item_product_id[]" class="as-inp" required>
            <option value="">— Produk —</option>
            <?php foreach($products as $p):?>
            <option value="<?=$p['id']?>" <?=($p['id']==$it['product_id'])?'selected':''?>><?=htmlspecialchars($p['product_code'])?> — <?=htmlspecialchars($p['product_name'])?></option>
            <?php endforeach;?>
          </select>
          <input type="number" name="item_qty[]" class="as-inp" min="0.01" step="0.01" placeholder="Qty" required value="<?=$it['expected_qty']?>">
          <input type="text" name="item_uom[]" class="as-inp" placeholder="UOM" value="<?=htmlspecialchars($it['uom']??'')?>">
          <input type="text" name="item_batch[]" class="as-inp" placeholder="Batch (opsional)" value="<?=htmlspecialchars($it['batch_number']??'')?>">
          <button type="button" class="as-rm" onclick="this.closest('.asn-item-row').remove()"><i class="fas fa-times-circle"></i></button>
        </div>
        <?php endforeach;?>
        <?php if(empty($items)):?>
        <div class="asn-item-row" style="display:grid;grid-template-columns:2fr 1fr .8fr 1.2fr auto;gap:10px;margin-bottom:8px">
          <select name="item_product_id[]" class="as-inp" required>
            <option value="">— Produk —</option>
            <?php foreach($products as $p):?>
            <option value="<?=$p['id']?>"><?=htmlspecialchars($p['product_code'])?> — <?=htmlspecialchars($p['product_name'])?></option>
            <?php endforeach;?>
          </select>
          <input type="number" name="item_qty[]" class="as-inp" min="0.01" step="0.01" placeholder="Qty" required>
          <input type="text" name="item_uom[]" class="as-inp" placeholder="UOM">
          <input type="text" name="item_batch[]" class="as-inp" placeholder="Batch (opsional)">
          <button type="button" class="as-rm" onclick="this.closest('.asn-item-row').remove()"><i class="fas fa-times-circle"></i></button>
        </div>
        <?php endif;?>
      </div>
      <button type="button" class="as-btn as-btn-g as-btn-sm" onclick="addAsnRow()"><i class="fas fa-plus"></i> Tambah Item</button>

      <div style="margin-top:14px">
        <label style="font-size:.72rem;font-weight:600;color:#37474f;display:block;margin-bottom:3px">Catatan</label>
        <textarea name="notes" class="as-inp" rows="2" style="width:100%"><?=$editing?htmlspecialchars($asn['notes']??''):''?></textarea>
      </div>

      <div style="display:flex;gap:10px;margin-top:16px">
        <button type="submit" name="<?=$editing?'update_asn':'create_asn'?>" class="as-btn as-btn-p">
          <i class="fas fa-check"></i> <?=$editing?'Simpan Perubahan':'Buat ASN'?>
        </button>
        <a href="?action=list" class="as-btn as-btn-g"><i class="fas fa-times"></i> Batal</a>
      </div>
    </form>
  </div>
</div>

<script>
function addAsnRow() {
  const row = document.createElement('div');
  row.className = 'asn-item-row';
  row.style.cssText = 'display:grid;grid-template-columns:2fr 1fr .8fr 1.2fr auto;gap:10px;margin-bottom:8px';
  row.innerHTML = `
    <select name="item_product_id[]" class="as-inp" required>
      <option value="">— Produk —</option>
      <?php foreach($products as $p):?>
      <option value="<?=$p['id']?>"><?=htmlspecialchars($p['product_code'], ENT_QUOTES)?> — <?=htmlspecialchars($p['product_name'], ENT_QUOTES)?></option>
      <?php endforeach;?>
    </select>
    <input type="number" name="item_qty[]" class="as-inp" min="0.01" step="0.01" placeholder="Qty" required>
    <input type="text" name="item_uom[]" class="as-inp" placeholder="UOM">
    <input type="text" name="item_batch[]" class="as-inp" placeholder="Batch (opsional)">
    <button type="button" class="as-rm" onclick="this.closest('.asn-item-row').remove()"><i class="fas fa-times-circle"></i></button>
  `;
  document.getElementById('asnItems').appendChild(row);
}
</script>

<?php elseif ($action === 'view' && $detail): $asn = $detail['asn'];?>
<div style="display:flex;gap:10px;margin-bottom:14px;align-items:center">
  <a href="?action=list" class="as-btn as-btn-g as-btn-sm"><i class="fas fa-arrow-left"></i> Kembali</a>
  <span style="font-size:1.1rem;font-weight:700;color:var(--as-primary);font-family:monospace"><?=htmlspecialchars($asn['asn_number'])?></span>
  <?php
  $cls = ['Pending'=>'as-pending','Received'=>'as-received','Cancelled'=>'as-cancelled'][$asn['status']]??'as-pending';
  echo "<span class='as-badge $cls'>{$asn['status']}</span>";
  ?>
  <?php if($canWrite && $asn['status']==='Pending'):?>
  <div style="margin-left:auto;display:flex;gap:8px">
    <a href="?action=edit&id=<?=$asn['id']?>" class="as-btn as-btn-g as-btn-sm"><i class="fas fa-edit"></i> Edit</a>
    <form method="POST" onsubmit="return confirm('Batalkan ASN ini?')">
      <input type="hidden" name="id" value="<?=$asn['id']?>">
      <button type="submit" name="cancel_asn" class="as-btn as-btn-r as-btn-sm"><i class="fas fa-ban"></i> Batalkan</button>
    </form>
  </div>
  <?php endif;?>
</div>

<?php if($asn['status']==='Received'):?>
<div class="as-alert as-ok mb-4"><i class="fas fa-check-double"></i> ASN telah diterima — barang sudah masuk gudang.</div>
<?php endif;?>

<div class="as-card">
  <div class="as-ch"><h2><i class="fas fa-info-circle mr-2"></i>Detail ASN</h2></div>
  <div class="as-cb">
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:14px">
      <div><div style="font-size:.68rem;color:#90a4ae;font-weight:700">SUPPLIER</div><div style="font-weight:700"><?=htmlspecialchars($asn['supplier_name'])?></div></div>
      <div><div style="font-size:.68rem;color:#90a4ae;font-weight:700">REFERENSI</div><div style="font-family:monospace"><?=htmlspecialchars($asn['supplier_reference']??'—')?></div></div>
      <div><div style="font-size:.68rem;color:#90a4ae;font-weight:700">E.T.A</div><div><?=date('d M Y',strtotime($asn['expected_arrival_date']))?></div></div>
      <div><div style="font-size:.68rem;color:#90a4ae;font-weight:700">DIBUAT OLEH</div><div><?=htmlspecialchars($asn['created_by_name']??'—')?></div></div>
    </div>
    <?php if($asn['notes']):?>
    <div style="margin-top:12px;padding:10px 14px;background:#fffde7;border-radius:7px;border-left:3px solid #f9a825">
      <div style="font-size:.68rem;color:#90a4ae;font-weight:700;margin-bottom:3px">CATATAN</div>
      <div style="font-size:.88rem"><?=htmlspecialchars($asn['notes'])?></div>
    </div>
    <?php endif;?>
  </div>
</div>

<div class="as-card">
  <div class="as-ch"><h2><i class="fas fa-boxes mr-2"></i>Item ASN</h2></div>
  <div style="overflow-x:auto">
  <table class="as-tbl">
    <thead><tr><th>Produk</th><th>Kode</th><th style="text-align:right">Expected Qty</th><th>UOM</th><th>Batch</th></tr></thead>
    <tbody>
    <?php foreach($detail['items'] as $it):?>
    <tr>
      <td style="font-weight:600"><?=htmlspecialchars($it['product_name'])?></td>
      <td style="font-family:monospace;font-size:.78rem;color:#90a4ae"><?=htmlspecialchars($it['product_code'])?></td>
      <td style="text-align:right;font-weight:700;color:var(--as-primary)"><?=number_format($it['expected_qty'],2)?></td>
      <td><?=htmlspecialchars($it['uom']??'')?></td>
      <td style="font-family:monospace;font-size:.8rem"><?=htmlspecialchars($it['batch_number']??'—')?></td>
    </tr>
    <?php endforeach;?>
    </tbody>
  </table>
  </div>
</div>

<?php endif;?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
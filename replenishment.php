<?php
ob_start();
error_reporting(0);
ini_set('display_errors', '0');
session_start();
header("Cache-Control: no-store, no-cache, must-revalidate");
header("Pragma: no-cache");
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/classes/Auth.php';
require_once __DIR__ . '/classes/Replenishment.php';
require_once __DIR__ . '/classes/BinTransfer.php';
require_once __DIR__ . '/classes/Product.php';
Auth::requireAuth();
$canWrite = Auth::canWrite();

$pageTitle   = 'Replenishment';
$currentPage = 'replenishment';

$error   = null;
$success = $_GET['success'] ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::requireWrite();
    try {
        if (isset($_POST['save_target'])) {
            $tid = Replenishment::saveTarget([
                'location_id' => (int)$_POST['location_id'],
                'product_id'  => (int)$_POST['product_id'],
                'min_qty'     => (float)$_POST['min_qty'],
                'max_qty'     => (float)$_POST['max_qty'],
            ]);
            header('Location: replenishment.php?success=saved'); exit;
        }
        if (isset($_POST['delete_target'])) {
            Replenishment::deleteTarget((int)$_POST['id']);
            header('Location: replenishment.php?success=deleted'); exit;
        }
        if (isset($_POST['generate_transfer'])) {
            $transferId = Replenishment::generateTransfers([
                'product_id'   => (int)$_POST['product_id'],
                'from_location'=> $_POST['from_location'],
                'to_location'  => $_POST['to_location'],
                'quantity'     => (float)$_POST['quantity'],
                'uom'          => $_POST['uom'] ?? null,
                'batch_number' => $_POST['batch_number'] ?? null,
                'target_id'    => (int)($_POST['target_id'] ?? 0),
                'reason'       => $_POST['reason'] ?? 'Replenishment',
            ]);
            header('Location: bin_transfer.php?action=view&id=' . $transferId . '&success=created'); exit;
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

$targets    = Replenishment::targets();
$suggestions = Replenishment::list();
$products   = Product::getAll();
$locations  = [];
try {
    $locStmt = db()->query("SELECT id, location_code, row_name, zone, aisle FROM location_master WHERE is_active = 1 ORDER BY location_code");
    $locations = $locStmt->fetchAll();
} catch (Throwable $e) { $locations = []; }

ob_end_flush();
require_once __DIR__ . '/includes/header.php';
?>
<style>
:root{--rp-primary:#026766;--rp-border:#b2dfdb;--rp-bg:#f0fdf9;--rp-muted:#607d8b;--rp-warn:#f57c00}
.rp-card{background:#fff;border-radius:10px;border:1px solid var(--rp-border);box-shadow:0 2px 12px rgba(0,105,92,.08);overflow:hidden;margin-bottom:18px}
.rp-ch{padding:12px 20px;border-bottom:1px solid var(--rp-border);display:flex;align-items:center;justify-content:space-between;background:var(--rp-bg)}
.rp-ch h2{font-size:.93rem;font-weight:600;color:var(--rp-primary);margin:0}
.rp-cb{padding:20px}
.rp-tbl{width:100%;border-collapse:collapse;font-size:.875rem}
.rp-tbl thead tr{background:#e0f2f1}
.rp-tbl th{padding:9px 12px;text-align:left;font-size:.7rem;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:var(--rp-muted);border-bottom:2px solid var(--rp-border)}
.rp-tbl td{padding:9px 12px;border-bottom:1px solid #e0f2f1;color:#37474f}
.rp-tbl tbody tr:hover{background:var(--rp-bg)}
.rp-badge{display:inline-block;padding:3px 10px;border-radius:20px;font-size:.7rem;font-weight:700}
.rp-bad-warn{background:#fff8e1;color:#e65100;border:1px solid #ffcc80}
.rp-bad-ok{background:#e8f5e9;color:#013d3c;border:1px solid #a5d6a7}
.rp-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 18px;border-radius:7px;font-size:.85rem;font-weight:600;cursor:pointer;border:none;transition:.15s;text-decoration:none}
.rp-btn-p{background:var(--rp-primary);color:#fff}.rp-btn-p:hover{background:#013d3c}
.rp-btn-g{background:#e0f2f1;color:#013d3c}.rp-btn-g:hover{background:#b2dfdb}
.rp-btn-sm{padding:4px 10px;font-size:.75rem}
.rp-inp{width:100%;padding:7px 11px;border:1.5px solid #b2dfdb;border-radius:7px;font-size:.85rem;outline:none}
.rp-inp:focus{border-color:var(--rp-primary);box-shadow:0 0 0 3px rgba(0,105,92,.1)}
.rp-alert{padding:10px 14px;border-radius:7px;font-size:.86rem;display:flex;align-items:center;gap:8px}
.rp-ok{background:#e8f5e9;border-left:4px solid #43a047;color:#013d3c}
.rp-err{background:#ffebee;border-left:4px solid #026766;color:#014f4e}
.rp-sec{background:var(--rp-bg);border:1px solid #b2dfdb;border-radius:8px;padding:14px 16px;margin-bottom:12px}
.rp-sec-title{font-size:.7rem;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--rp-primary);margin-bottom:10px}
</style>

<div style="max-width:1080px;margin:0 auto;padding:18px">

<?php if($success==='saved'):?><div class="rp-alert rp-ok mb-4"><i class="fas fa-check-circle"></i> Target pick-face berhasil disimpan.</div><?php endif;?>
<?php if($success==='deleted'):?><div class="rp-alert rp-ok mb-4"><i class="fas fa-check-circle"></i> Target dihapus.</div><?php endif;?>
<?php if($error):?><div class="rp-alert rp-err mb-4"><i class="fas fa-exclamation-circle"></i> <?=htmlspecialchars($error)?></div><?php endif;?>

<div class="rp-card">
  <div class="rp-ch">
    <h2><i class="fas fa-sync-alt mr-2"></i>Replenishment (Pick-Face Targets)</h2>
  </div>
  <div class="rp-cb">
    <div class="rp-alert rp-ok mb-4" style="background:#e3f2fd;border-left-color:#1e88e5">
      <i class="fas fa-info-circle"></i>
      <span>Target pick-face menentukan stok minimum/maksimum per produk di Level A. Sistem menyarankan replenishment dari lokasi bulk (FEFO) ketika stok turun di bawah min.</span>
    </div>

    <?php if($canWrite):?>
    <form method="POST" style="display:grid;grid-template-columns:1.4fr 1.4fr .8fr .8fr auto;gap:10px;align-items:end;margin-bottom:6px">
      <div>
        <label style="font-size:.72rem;font-weight:600;color:#37474f;display:block;margin-bottom:3px">Lokasi Pick-Face (Level A)</label>
        <select name="location_id" class="rp-inp" required>
          <option value="">— Pilih lokasi —</option>
          <?php foreach($locations as $loc):
              $isA = isset($loc['location_code'][4]) && strtoupper($loc['location_code'][4]) === 'A'; ?>
            <?php if($isA):?>
            <option value="<?=$loc['id']?>"><?=htmlspecialchars($loc['location_code'])?> — <?=htmlspecialchars($loc['row_name']??'')?></option>
            <?php endif;?>
          <?php endforeach;?>
        </select>
      </div>
      <div>
        <label style="font-size:.72rem;font-weight:600;color:#37474f;display:block;margin-bottom:3px">Produk</label>
        <select name="product_id" class="rp-inp" required>
          <option value="">— Pilih produk —</option>
          <?php foreach($products as $p):?>
          <option value="<?=$p['id']?>"><?=htmlspecialchars($p['product_code'])?> — <?=htmlspecialchars($p['product_name'])?></option>
          <?php endforeach;?>
        </select>
      </div>
      <div>
        <label style="font-size:.72rem;font-weight:600;color:#37474f;display:block;margin-bottom:3px">Min Qty</label>
        <input type="number" name="min_qty" class="rp-inp" min="0" step="0.01" value="10" required>
      </div>
      <div>
        <label style="font-size:.72rem;font-weight:600;color:#37474f;display:block;margin-bottom:3px">Max Qty</label>
        <input type="number" name="max_qty" class="rp-inp" min="0" step="0.01" value="40" required>
      </div>
      <button type="submit" name="save_target" class="rp-btn rp-btn-p"><i class="fas fa-plus"></i> Simpan</button>
    </form>
    <?php endif;?>

    <?php if(!empty($targets)):?>
    <table class="rp-tbl" style="margin-top:14px">
      <thead><tr><th>Lokasi</th><th>Produk</th><th style="text-align:right">Min</th><th style="text-align:right">Max</th><?php if($canWrite):?><th style="text-align:center">Aksi</th><?php endif;?></tr></thead>
      <tbody>
      <?php foreach($targets as $t):?>
      <tr>
        <td><span style="font-family:monospace;font-weight:700;color:#013d3c"><?=htmlspecialchars($t['location_code'])?></span></td>
        <td>
          <div style="font-weight:600"><?=htmlspecialchars($t['product_name'])?></div>
          <div style="font-size:.7rem;color:#90a4ae;font-family:monospace"><?=htmlspecialchars($t['product_code'])?></div>
        </td>
        <td style="text-align:right"><?=number_format($t['min_qty'],2)?></td>
        <td style="text-align:right"><?=number_format($t['max_qty'],2)?></td>
        <?php if($canWrite):?>
        <td style="text-align:center">
          <form method="POST" onsubmit="return confirm('Hapus target ini?')" style="display:inline">
            <input type="hidden" name="id" value="<?=$t['id']?>">
            <button type="submit" name="delete_target" class="rp-btn rp-btn-g rp-btn-sm"><i class="fas fa-trash"></i></button>
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

<div class="rp-card">
  <div class="rp-ch">
    <h2><i class="fas fa-list mr-2"></i>Saran Replenishment</h2>
  </div>
  <div style="overflow-x:auto">
  <?php if(empty($suggestions)):?>
  <div style="text-align:center;padding:40px;color:#90a4ae">
    <i class="fas fa-check-double" style="font-size:2rem;margin-bottom:10px;display:block"></i>
    Belum ada target pick-face. Tambahkan target di form atas.
  </div>
  <?php else:?>
  <table class="rp-tbl">
    <thead><tr>
      <th>Produk</th><th>Pick-Face</th><th style="text-align:right">Stok</th>
      <th style="text-align:right">Min</th><th style="text-align:right">Max</th>
      <th style="text-align:right">Kekurangan</th><th>Status</th><th>Sumber Bulk (FEFO)</th>
      <?php if($canWrite):?><th style="text-align:center">Aksi</th><?php endif;?>
    </tr></thead>
    <tbody>
    <?php foreach($suggestions as $s):?>
    <tr>
      <td>
        <div style="font-weight:600"><?=htmlspecialchars($s['product_name'])?></div>
        <div style="font-size:.7rem;color:#90a4ae;font-family:monospace"><?=htmlspecialchars($s['product_code'])?></div>
      </td>
      <td><span style="font-family:monospace;font-weight:700;color:#013d3c"><?=htmlspecialchars($s['location_code'])?></span></td>
      <td style="text-align:right;font-weight:600"><?=number_format($s['available_qty'],2)?></td>
      <td style="text-align:right"><?=number_format($s['min_qty'],2)?></td>
      <td style="text-align:right"><?=number_format($s['max_qty'],2)?></td>
      <td style="text-align:right;font-weight:700;color:<?=$s['shortage']>0?'#e65100':'#43a047'?>"><?=number_format($s['shortage'],2)?></td>
      <td>
        <?php if($s['below_min']):?>
        <span class="rp-badge rp-bad-warn"><i class="fas fa-exclamation-triangle"></i> Perlu Replenish</span>
        <?php else:?>
        <span class="rp-badge rp-bad-ok"><i class="fas fa-check"></i> Cukup</span>
        <?php endif;?>
      </td>
      <td>
        <?php if(empty($s['sources'])):?>
        <span style="color:#90a4ae;font-size:.78rem">—</span>
        <?php else:?>
        <div style="display:flex;flex-direction:column;gap:2px;font-size:.75rem">
          <?php foreach($s['sources'] as $src):?>
          <span style="font-family:monospace;color:#37474f">
            <?=htmlspecialchars($src['location'])?> · <?=number_format($src['quantity'],0)?> <?=htmlspecialchars($s['uom_type']??'')?> · B<?=htmlspecialchars($src['batch_number']??'—')?>
          </span>
          <?php endforeach;?>
        </div>
        <?php endif;?>
      </td>
      <?php if($canWrite):?>
      <td style="text-align:center">
        <?php if($s['below_min'] && !empty($s['sources'])):?>
        <form method="POST" onsubmit="return confirm('Buat transfer replenishment?')">
          <input type="hidden" name="target_id" value="<?=$s['id']?>">
          <input type="hidden" name="product_id" value="<?=$s['product_id']?>">
          <input type="hidden" name="to_location" value="<?=htmlspecialchars($s['location_code'])?>">
          <input type="hidden" name="from_location" value="<?=htmlspecialchars($s['sources'][0]['location'])?>">
          <input type="hidden" name="batch_number" value="<?=htmlspecialchars($s['sources'][0]['batch_number']??'')?>">
          <input type="hidden" name="uom" value="<?=htmlspecialchars($s['uom_type']??'Drum')?>">
          <input type="hidden" name="quantity" value="<?=min($s['shortage'], $s['sources'][0]['quantity'])?>">
          <button type="submit" name="generate_transfer" class="rp-btn rp-btn-p rp-btn-sm"><i class="fas fa-exchange-alt"></i> Replenish</button>
        </form>
        <?php endif;?>
      </td>
      <?php endif;?>
    </tr>
    <?php endforeach;?>
    </tbody>
  </table>
  <?php endif;?>
  </div>
</div>

</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
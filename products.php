<?php
session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/classes/Auth.php';
require_once __DIR__ . '/classes/Product.php';
require_once __DIR__ . '/classes/ExcelExport.php';
require_once __DIR__ . '/classes/ActivityLogger.php';

Auth::requireAuth();
Auth::requireRole(['admin', 'operator']);

$pageTitle   = 'Products';
$currentPage = 'products';
$success = $_GET['success'] ?? null;
$error   = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['create_product'])) {
            Product::create($_POST);
            ActivityLogger::log('CREATE_PRODUCT', 'stock', 'Product', null,
                $_POST['product_code'] ?? null, "Buat produk: " . ($_POST['product_name'] ?? '—'));
            header('Location: products.php?success=created'); exit;
        }
        if (isset($_POST['update_product'])) {
            Product::update($_POST['id'], $_POST);
            ActivityLogger::log('UPDATE_PRODUCT', 'stock', 'Product', (int)$_POST['id'],
                $_POST['product_code'] ?? null, "Edit produk: " . ($_POST['product_name'] ?? '—'));
            header('Location: products.php?success=updated'); exit;
        }
        if (isset($_POST['delete_product'])) {
            $pid = (int)$_POST['id'];
            $db  = db();
            $pInfo = $db->prepare("SELECT product_code, product_name FROM products WHERE id=?");
            $pInfo->execute([$pid]);
            $pRow = $pInfo->fetch();
            $db->prepare("DELETE sl FROM stock_locations sl
                JOIN stock s ON sl.stock_id = s.id WHERE s.product_id=?")->execute([$pid]);
            $db->prepare("DELETE FROM stock_locations WHERE inbound_item_id IN
                (SELECT id FROM inbound_items WHERE product_id=?)")->execute([$pid]);
            $db->prepare("DELETE FROM stock_ledger    WHERE product_id=?")->execute([$pid]);
            $db->prepare("DELETE FROM stock           WHERE product_id=?")->execute([$pid]);
            $db->prepare("DELETE FROM inbound_items   WHERE product_id=?")->execute([$pid]);
            $db->prepare("DELETE FROM outbound_items  WHERE product_id=?")->execute([$pid]);
            $db->prepare("DELETE FROM stock_take_items WHERE product_id=?")->execute([$pid]);
            Product::delete($pid);
            ActivityLogger::log('DELETE_PRODUCT', 'stock', 'Product', $pid,
                $pRow['product_code'] ?? null, "Hapus produk: " . ($pRow['product_name'] ?? $pid));
            header('Location: products.php?success=deleted'); exit;
        }
    } catch (Exception $e) { $error = $e->getMessage(); }
}

if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    ExcelExport::exportProducts(Product::getAll());
}
$search     = trim($_GET['search'] ?? '');
$perPage    = 25;
$page       = max(1, intval($_GET['page'] ?? 1));
$totalCount = Product::getCount($search);
$totalAll   = $search ? Product::getCount() : $totalCount;
$totalPages = max(1, (int)ceil($totalCount / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;
$products   = Product::getPaginated($search, $perPage, $offset);
$uomStats   = Product::getStatsByUom();
$qs         = $search ? ['search' => $search] : [];

require_once __DIR__ . '/includes/header.php';
?>
<style>
.lm-pagination { display:flex; align-items:center; gap:6px; flex-wrap:wrap; }
.pg-btn { border:1px solid #e5e7eb; border-radius:6px; padding:5px 10px; font-size:.8rem; cursor:pointer; background:#fff; color:#546e7a; transition:all .15s; text-decoration:none; display:inline-flex; align-items:center; }
.pg-btn:hover { border-color:#026766; color:#026766; }
.pg-btn.active { background:#026766; color:#fff; border-color:#026766; font-weight:700; }
.pg-btn.disabled { opacity:.4; pointer-events:none; }
.pg-info { font-size:.8rem; color:#90a4ae; margin-left:4px; }
.srch-wrap { position:relative; margin-bottom:0; }
.srch-wrap .srch-icon { position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#9ca3af;pointer-events:none }
.srch-inp { width:100%;padding:9px 12px 9px 38px;border:2px solid #e5e7eb;border-radius:10px;
            font-size:.88rem;outline:none;transition:.15s;box-sizing:border-box }
.srch-inp:focus { border-color:#026766;box-shadow:0 0 0 3px rgba(37,99,235,.1) }
.srch-count { font-size:.78rem;color:#6b7280;font-weight:600;padding:4px 10px;
              background:#f3f4f6;border-radius:20px;white-space:nowrap }
.del-warning { background:#e6f7f7;border:1px solid #b2e5e5;border-radius:8px;
               padding:10px 14px;font-size:.8rem;color:#013d3c;margin-top:8px }
</style>

<div class="space-y-5">
  <div class="wms-banner">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px">
      <div><h1><i class="fas fa-box mr-2"></i>Products</h1></div>
      <div style="display:flex;gap:8px;flex-wrap:wrap">
        <a href="<?= BASE_URL ?>/products.php?export=excel" class="wms-btn wms-btn-excel"><i class="fas fa-file-excel"></i> Export</a>
        <button onclick="openProductModal()" class="wms-btn wms-btn-primary"><i class="fas fa-plus"></i> New Product</button>
      </div>
    </div>
    <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-top:16px">
      <div class="stat-pill"><div class="num"><?= $totalAll ?></div><div class="lbl">Total</div></div>
      <div class="stat-pill"><div class="num"><?= $uomStats['Drum']??0 ?></div><div class="lbl">Drum</div></div>
      <div class="stat-pill"><div class="num"><?= ($uomStats['Carton']??0)+($uomStats['Pail']??0) ?></div><div class="lbl">Carton/Pail</div></div>
      <div class="stat-pill"><div class="num"><?= ($uomStats['EA']??0)+($uomStats['Bags']??0) ?></div><div class="lbl">EA/Bags</div></div>
    </div>
  </div>

  <?php if($success): ?><div class="wms-alert wms-alert-success"><i class="fas fa-check-circle"></i> Product <?= $success ?> successfully!</div><?php endif; ?>
  <?php if($error):   ?><div class="wms-alert wms-alert-error"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?></div><?php endif; ?>

  <div class="wms-card">
    <div class="wms-card-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px">
      <h2><i class="fas fa-table" style="color:#026766"></i> Product List</h2>
      <div style="display:flex;align-items:center;gap:10px;flex:1;max-width:420px">
        <form id="productSearchForm" method="GET" style="flex:1;display:flex;align-items:center;gap:6px">
          <div class="srch-wrap" style="flex:1">
            <i class="fas fa-search srch-icon"></i>
            <input type="text" name="search" id="productSearch" class="srch-inp"
                   placeholder="Cari kode, nama, atau kategori..."
                   value="<?= htmlspecialchars($search) ?>" autocomplete="off">
          </div>
          <?php if($search): ?><a href="products.php" style="color:#9ca3af;font-size:.9rem;text-decoration:none;flex-shrink:0" title="Hapus filter">✕</a><?php endif; ?>
        </form>
        <span class="srch-count"><?= number_format($totalCount) ?> produk</span>
      </div>
    </div>
    <div class="wms-table-wrap">
      <table class="wms-table">
        <thead><tr>
          <th>Code</th><th>Product Name</th><th class="tc">UOM</th>
          <th class="tc">/Pallet</th><th class="tr">Qty</th><th class="tr">Pallets</th>
          <th class="tc">Actions</th>
        </tr></thead>
        <tbody id="productTableBody">
          <?php foreach($products as $product):
            $uomType   = $product['uom_type']   ?? 'Drum';
            $upp       = $product['uom_per_pallet'] ?? 4;
            $totalQty  = $product['total_qty']   ?? $product['total_drums'] ?? 0;
            $totalPlt  = $product['total_pallets'] ?? 0;
            $searchStr = strtolower($product['product_code'].' '.$product['product_name'].' '.($product['category']??''));
          ?>
          <tr data-search="<?= htmlspecialchars($searchStr) ?>">
            <td><span style="font-family:monospace;font-weight:600"><?= htmlspecialchars($product['product_code']) ?></span></td>
            <td>
              <div style="font-weight:600"><?= htmlspecialchars($product['product_name']) ?></div>
              <?php if($product['category']??''): ?>
              <div style="font-size:.75rem;color:#9ca3af"><?= htmlspecialchars($product['category']) ?></div>
              <?php endif; ?>
            </td>
            <td class="tc"><span class="wms-badge wms-badge-<?= strtolower($uomType) ?>"><?= $uomType ?></span></td>
            <td class="tc" style="font-weight:600"><?= $upp ?>/plt</td>
            <td class="tr" style="font-weight:700"><?= number_format($totalQty) ?></td>
            <td class="tr" style="color:#6b7280"><?= number_format($totalPlt,2) ?></td>
            <td class="tc">
              <button onclick='openReport(<?= $product["id"] ?>, <?= htmlspecialchars(json_encode($product["product_name"])) ?>)'
                      class="wms-btn wms-btn-sm" title="Live Report"
                      style="background:#e6f7f7;color:#013d3c;border:1px solid #b2e5e5">
                <i class="fas fa-chart-line"></i>
              </button>
              <button onclick='editProduct(<?= htmlspecialchars(json_encode($product)) ?>)'
                      class="wms-btn wms-btn-ghost wms-btn-sm" title="Edit">
                <i class="fas fa-edit"></i>
              </button>
              <button onclick='confirmDeleteProduct(<?= $product["id"] ?>, <?= (int)$totalQty ?>, <?= htmlspecialchars(json_encode($product["product_name"])) ?>)'
                      class="wms-btn wms-btn-danger wms-btn-sm" title="Hapus">
                <i class="fas fa-trash"></i>
              </button>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if(empty($products)): ?>
          <tr><td colspan="7"><div class="wms-empty"><i class="fas fa-box-open"></i>No products found</div></td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
    <?php if($totalCount > 0): ?>
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;padding:10px 16px;border-top:1px solid #e5e7eb;background:#f9fafb;border-radius:0 0 12px 12px">
      <span style="font-size:.8rem;color:#6b7280">
        Menampilkan <?= number_format(($page-1)*$perPage+1) ?>–<?= number_format(min($page*$perPage,$totalCount)) ?> dari <?= number_format($totalCount) ?> produk<?= $search ? ' &middot; filter aktif' : '' ?>
      </span>
      <?php if($totalPages > 1): ?>
      <div class="lm-pagination">
        <a href="?<?= http_build_query(array_merge($qs,['page'=>1])) ?>" class="pg-btn <?= $page<=1?'disabled':'' ?>"><i class="fas fa-angle-double-left"></i></a>
        <a href="?<?= http_build_query(array_merge($qs,['page'=>max(1,$page-1)])) ?>" class="pg-btn <?= $page<=1?'disabled':'' ?>"><i class="fas fa-angle-left"></i></a>
        <?php $start=max(1,$page-2);$end=min($totalPages,$page+2); for($p=$start;$p<=$end;$p++): ?>
        <a href="?<?= http_build_query(array_merge($qs,['page'=>$p])) ?>" class="pg-btn <?= $p===$page?'active':'' ?>"><?= $p ?></a>
        <?php endfor; if($end<$totalPages) echo '<span class="pg-info">…</span>'; ?>
        <a href="?<?= http_build_query(array_merge($qs,['page'=>min($totalPages,$page+1)])) ?>" class="pg-btn <?= $page>=$totalPages?'disabled':'' ?>"><i class="fas fa-angle-right"></i></a>
        <a href="?<?= http_build_query(array_merge($qs,['page'=>$totalPages])) ?>" class="pg-btn <?= $page>=$totalPages?'disabled':'' ?>"><i class="fas fa-angle-double-right"></i></a>
      </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
</div>

<div id="deleteProductModal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;
     z-index:9999;background:rgba(0,0,0,.5);align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:16px;padding:28px;max-width:420px;width:90%;box-shadow:0 20px 60px rgba(0,0,0,.3)">
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px">
      <div style="background:#e6f7f7;border-radius:50%;width:44px;height:44px;display:flex;align-items:center;justify-content:center">
        <i class="fas fa-exclamation-triangle" style="color:#026766;font-size:1.1rem"></i>
      </div>
      <div>
        <div style="font-weight:800;font-size:1rem;color:#111">Hapus Produk?</div>
        <div style="font-size:.8rem;color:#6b7280" id="delProdName"></div>
      </div>
    </div>
    <div id="delProdWarning" class="del-warning" style="display:none">
      <i class="fas fa-database mr-1"></i>
      Produk ini masih punya <b id="delProdQty"></b> unit di stock.<br>
      <b>Semua data stock, ledger, inbound & outbound terkait akan ikut dihapus!</b>
    </div>
    <div style="margin-top:12px;font-size:.82rem;color:#374151">
      Aksi ini tidak bisa dibatalkan. Lanjutkan?
    </div>
    <form method="POST" id="deleteProdForm" style="display:flex;gap:10px;margin-top:18px">
      <input type="hidden" name="id" id="delProdId">
      <button type="submit" name="delete_product"
              style="flex:1;background:#026766;color:#fff;border:none;border-radius:8px;
                     padding:10px;font-weight:700;cursor:pointer">
        <i class="fas fa-trash mr-1"></i>Ya, Hapus Semua
      </button>
      <button type="button" onclick="document.getElementById('deleteProductModal').style.display='none'"
              style="flex:1;background:#f3f4f6;color:#374151;border:none;border-radius:8px;
                     padding:10px;font-weight:600;cursor:pointer">Batal</button>
    </form>
  </div>
</div>

<div id="productModal" class="wms-modal-overlay" style="display:none">
  <div class="wms-modal">
    <div class="wms-modal-header">
      <h3 id="modalTitle">New Product</h3>
      <button onclick="closeProductModal()" style="background:none;border:none;cursor:pointer;font-size:1.2rem;color:#9ca3af"><i class="fas fa-times"></i></button>
    </div>
    <form method="POST" id="productForm" class="wms-modal-body" style="padding:20px">
      <input type="hidden" name="id" id="productId">
      <div style="display:grid;gap:12px">
        <div><label class="wms-label">Product Code <span class="wms-req">*</span></label>
             <input type="text" name="product_code" id="productCode" required class="wms-input"></div>
        <div><label class="wms-label">Product Name <span class="wms-req">*</span></label>
             <input type="text" name="product_name" id="productName" required class="wms-input"></div>
        <div><label class="wms-label">Category</label>
             <input type="text" name="category" id="category" class="wms-input"></div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
          <div><label class="wms-label">UOM Type</label>
               <select name="uom_type" id="uomType" onchange="updateUomPerPallet()" class="wms-input">
                 <option value="Drum">Drum</option><option value="Carton">Carton</option>
                 <option value="Pail">Pail</option><option value="EA">EA</option><option value="Bags">Bags</option>
               </select></div>
          <div><label class="wms-label">UOM per Pallet</label>
               <select name="uom_per_pallet" id="uomPerPallet" class="wms-input"></select></div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
          <div><label class="wms-label">Max SKU Qty</label>
               <input type="number" name="max_sku_qty" id="maxSkuQty" value="44" class="wms-input"></div>
          <div><label class="wms-label">Max Trans Qty</label>
               <input type="number" name="max_trans_qty" id="maxTransQty" value="80" class="wms-input"></div>
        </div>
        <div><label class="wms-label">Description</label>
             <textarea name="description" id="description" rows="2" class="wms-input"></textarea></div>
      </div>
      <div style="display:flex;gap:10px;margin-top:18px">
        <button type="submit" id="saveBtn" name="create_product" class="wms-btn wms-btn-primary" style="flex:1"><i class="fas fa-save"></i> Save</button>
        <button type="button" onclick="closeProductModal()" class="wms-btn wms-btn-ghost" style="flex:1">Cancel</button>
      </div>
    </form>
  </div>
</div>

<script>
var _searchTimer;
document.getElementById('productSearch').addEventListener('input', function() {
    clearTimeout(_searchTimer);
    _searchTimer = setTimeout(function() {
        document.getElementById('productSearchForm').submit();
    }, 600);
});

function confirmDeleteProduct(id, qty, name) {
    document.getElementById('delProdId').value   = id;
    document.getElementById('delProdName').textContent = name;
    var warn = document.getElementById('delProdWarning');
    if (qty > 0) {
        document.getElementById('delProdQty').textContent = qty;
        warn.style.display = 'block';
    } else {
        warn.style.display = 'none';
    }
    document.getElementById('deleteProductModal').style.display = 'flex';
}

function openProductModal() {
    document.getElementById('modalTitle').textContent = 'New Product';
    document.getElementById('productId').value = '';
    document.getElementById('productForm').reset();
    document.getElementById('saveBtn').name = 'create_product';
    updateUomPerPallet();
    document.getElementById('productModal').style.display = 'flex';
}
function closeProductModal() { document.getElementById('productModal').style.display = 'none'; }
function editProduct(p) {
    document.getElementById('modalTitle').textContent = 'Edit Product';
    document.getElementById('productId').value        = p.id;
    document.getElementById('productCode').value      = p.product_code;
    document.getElementById('productName').value      = p.product_name;
    document.getElementById('category').value         = p.category || '';
    document.getElementById('description').value      = p.description || '';
    document.getElementById('uomType').value          = p.uom_type || 'Drum';
    updateUomPerPallet();
    document.getElementById('uomPerPallet').value     = p.uom_per_pallet || 4;
    document.getElementById('maxSkuQty').value        = p.max_sku_qty || 44;
    document.getElementById('maxTransQty').value      = p.max_trans_qty || 80;
    document.getElementById('saveBtn').name           = 'update_product';
    document.getElementById('productModal').style.display = 'flex';
}
function updateUomPerPallet() {
    var uom  = document.getElementById('uomType').value;
    var sel  = document.getElementById('uomPerPallet');
    var opts = {Drum:[4],Carton:[36,44,48,80],Pail:[24],EA:[4],Bags:[1]};
    sel.innerHTML = '';
    (opts[uom] || [4]).forEach(function(v) {
        var o = document.createElement('option');
        o.value = v; o.textContent = v + ' per pallet';
        sel.appendChild(o);
    });
}
updateUomPerPallet();

document.addEventListener('keydown', function(e) {
    if ((e.ctrlKey && e.key === 'f') || (!e.ctrlKey && !e.metaKey && e.key === '/' && document.activeElement.tagName !== 'INPUT')) {
        e.preventDefault();
        document.getElementById('productSearch').focus();
    }
});

var _reportPid = null, _reportInterval = null;

function openReport(pid, name) {
    _reportPid = pid;
    document.getElementById('rptTitle').textContent = name;
    document.getElementById('rptModal').style.display = 'flex';
    loadReport();
    if (_reportInterval) clearInterval(_reportInterval);
    _reportInterval = setInterval(loadReport, 30000); 
}
function closeReport() {
    document.getElementById('rptModal').style.display = 'none';
    if (_reportInterval) { clearInterval(_reportInterval); _reportInterval = null; }
}
function loadReport() {
    if (!_reportPid) return;
    document.getElementById('rptLastUpdate').textContent = 'Memuat...';
    fetch('products_report.php?product_id=' + _reportPid)
        .then(function(r){ return r.json(); })
        .then(function(d){ renderReport(d); })
        .catch(function(){ document.getElementById('rptBody').innerHTML = '<div style="padding:20px;text-align:center;color:#026766">Gagal memuat data</div>'; });
}
function renderReport(d) {
    var s  = d.stock    || {};
    var ib = d.inbound  || {};
    var ob = d.outbound || {};

    var avail   = parseFloat(s.qty_available||0);
    var reject  = parseFloat(s.qty_rejected||0);
    var duesIn  = parseFloat(s.qty_dues_in||0);
    var pallets = parseFloat(s.pallets||0);
    var uom     = s.uom || '';

    var html = '';

    
    html += '<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:16px">';
    html += kpi('Available', avail+' '+uom, '#013d3c','#e6f7f7','#b2e5e5');
    html += kpi('Pallets',   pallets.toFixed(2), '#026766','#e6f7f7','#e0f7f7');
    html += kpi('Dues In',   duesIn+' '+uom, '#f57c00','#fff7ed','#fef9c3');
    html += kpi('QUA_SHELL', reject+' '+uom, '#013d3c','#e6f7f7','#b2e5e5');
    html += '</div>';

    
    html += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:16px">';
    
    html += '<div style="background:#e6f7f7;border:1px solid #b2e5e5;border-radius:10px;padding:12px">';
    html += '<div style="font-weight:800;color:#013d3c;font-size:.85rem;margin-bottom:8px"><i class="fas fa-arrow-down mr-1"></i>INBOUND (Completed)</div>';
    html += row2('Total Masuk', numFmt(ib.qty_total_in)+' '+uom);
    html += row2('ATP',         numFmt(ib.qty_atp)+' '+uom);
    html += row2('Picked',      numFmt(ib.qty_picked)+' '+uom);
    html += row2('Unserviceable',numFmt(ib.qty_unserv)+' '+uom, '#013d3c');
    html += '</div>';
    
    html += '<div style="background:#e6f7f7;border:1px solid #e0f7f7;border-radius:10px;padding:12px">';
    html += '<div style="font-weight:800;color:#026766;font-size:.85rem;margin-bottom:8px"><i class="fas fa-arrow-up mr-1"></i>OUTBOUND</div>';
    html += row2('Total Orders',  numFmt(ob.total_orders)+' order');
    html += row2('Total Keluar',  numFmt(ob.qty_total_out)+' '+uom);
    html += row2('Shipped/Done',  numFmt(ob.qty_shipped)+' '+uom);
    html += '</div>';
    html += '</div>';

    
    if (d.locations && d.locations.length) {
        html += '<div style="font-weight:800;font-size:.82rem;color:#374151;margin-bottom:6px">Lokasi Stock</div>';
        html += '<div style="border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;margin-bottom:14px">';
        html += '<table style="width:100%;border-collapse:collapse;font-size:.78rem">';
        html += '<thead><tr style="background:#f9fafb"><th style="padding:6px 10px;text-align:left;color:#6b7280">Lokasi</th><th style="padding:6px 10px;text-align:left;color:#6b7280">Batch</th><th style="padding:6px 10px;text-align:right;color:#6b7280">Qty</th><th style="padding:6px 10px;text-align:right;color:#6b7280">Plt</th><th style="padding:6px 10px;color:#6b7280">Status</th><th style="padding:6px 10px;color:#6b7280">Expiry</th></tr></thead><tbody>';
        d.locations.forEach(function(l) {
            var sc = l.stock_status==='Available' ? '#013d3c' : (l.stock_status==='Rejected' ? '#013d3c' : '#f57c00');
            html += '<tr style="border-top:1px solid #f3f4f6">';
            html += '<td style="padding:6px 10px;font-family:monospace;font-weight:700;color:#026766">'+esc(l.location)+'</td>';
            html += '<td style="padding:6px 10px;font-family:monospace;font-size:.72rem">'+esc(l.batch_number||'-')+'</td>';
            html += '<td style="padding:6px 10px;text-align:right;font-weight:700">'+numFmt(l.quantity)+' '+uom+'</td>';
            html += '<td style="padding:6px 10px;text-align:right;color:#6b7280">'+parseFloat(l.pallet||0).toFixed(2)+'</td>';
            html += '<td style="padding:6px 10px"><span style="color:'+sc+';font-weight:600;font-size:.72rem">'+esc(l.stock_status)+'</span></td>';
            html += '<td style="padding:6px 10px;font-size:.72rem;color:#6b7280">'+(l.expiry_date?l.expiry_date.substr(0,10):'-')+'</td>';
            html += '</tr>';
        });
        html += '</tbody></table></div>';
    }

    
    if (d.ledger && d.ledger.length) {
        html += '<div style="font-weight:800;font-size:.82rem;color:#374151;margin-bottom:6px">Ledger Terbaru</div>';
        html += '<div style="border:1px solid #e5e7eb;border-radius:8px;overflow:hidden">';
        html += '<table style="width:100%;border-collapse:collapse;font-size:.75rem">';
        html += '<thead><tr style="background:#f9fafb"><th style="padding:5px 8px;text-align:left;color:#6b7280">Tanggal</th><th style="padding:5px 8px;color:#6b7280">Tipe</th><th style="padding:5px 8px;text-align:left;color:#6b7280">Referensi</th><th style="padding:5px 8px;text-align:right;color:#6b7280">In</th><th style="padding:5px 8px;text-align:right;color:#6b7280">Out</th><th style="padding:5px 8px;text-align:right;color:#6b7280">Balance</th></tr></thead><tbody>';
        d.ledger.forEach(function(l) {
            var isIn = l.transaction_type === 'IN';
            html += '<tr style="border-top:1px solid #f3f4f6">';
            html += '<td style="padding:5px 8px;color:#6b7280">'+esc(l.transaction_date||'').substr(0,10)+'</td>';
            html += '<td style="padding:5px 8px;text-align:center"><span style="background:'+(isIn?'#e0f7f7':'#e6f7f7')+';color:'+(isIn?'#013d3c':'#013d3c')+';padding:1px 6px;border-radius:10px;font-weight:700;font-size:.7rem">'+l.transaction_type+'</span></td>';
            html += '<td style="padding:5px 8px;font-family:monospace;font-size:.7rem">'+esc(l.reference_number||'-')+'</td>';
            html += '<td style="padding:5px 8px;text-align:right;color:#013d3c;font-weight:700">'+(parseFloat(l.quantity_in)>0?'+'+numFmt(l.quantity_in):'-')+'</td>';
            html += '<td style="padding:5px 8px;text-align:right;color:#013d3c;font-weight:700">'+(parseFloat(l.quantity_out)>0?'-'+numFmt(l.quantity_out):'-')+'</td>';
            html += '<td style="padding:5px 8px;text-align:right;font-weight:800">'+numFmt(l.balance)+'</td>';
            html += '</tr>';
        });
        html += '</tbody></table></div>';
    }

    document.getElementById('rptBody').innerHTML = html;
    document.getElementById('rptLastUpdate').textContent = 'Update: ' + new Date().toLocaleTimeString('id-ID');
}

function kpi(label, val, textCol, bg, border) {
    return '<div style="background:'+bg+';border:1px solid '+border+';border-radius:10px;padding:12px;text-align:center">'
         + '<div style="font-size:1.1rem;font-weight:800;color:'+textCol+'">'+val+'</div>'
         + '<div style="font-size:.7rem;color:#6b7280;margin-top:2px">'+label+'</div></div>';
}
function row2(label, val, col) {
    return '<div style="display:flex;justify-content:space-between;align-items:center;padding:3px 0;border-bottom:1px solid rgba(0,0,0,.05)">'
         + '<span style="font-size:.76rem;color:#6b7280">'+label+'</span>'
         + '<span style="font-size:.78rem;font-weight:700;color:'+(col||'#374151')+'">'+val+'</span></div>';
}
function numFmt(n) { return parseFloat(n||0).toLocaleString('id-ID'); }
function esc(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
</script>

<div id="rptModal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;
     z-index:99999;background:rgba(0,0,0,.55);align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:16px;width:720px;max-width:96vw;max-height:90vh;
              display:flex;flex-direction:column;box-shadow:0 24px 64px rgba(0,0,0,.3)">
    <div style="padding:16px 20px;border-bottom:1px solid #e5e7eb;display:flex;align-items:center;
                justify-content:space-between;flex-shrink:0">
      <div>
        <h3 style="margin:0;font-size:1rem;font-weight:800;color:#013d3c">
          <i class="fas fa-chart-line mr-2"></i>Live Report Produk
        </h3>
        <div style="font-size:.82rem;color:#6b7280;margin-top:2px" id="rptTitle"></div>
      </div>
      <div style="display:flex;align-items:center;gap:10px">
        <span style="font-size:.72rem;color:#6b7280" id="rptLastUpdate"></span>
        <button onclick="loadReport()" title="Refresh"
                style="background:#e6f7f7;color:#013d3c;border:1px solid #b2e5e5;border-radius:8px;
                       padding:5px 10px;cursor:pointer;font-size:.78rem">
          <i class="fas fa-sync-alt"></i> Refresh
        </button>
        <button onclick="closeReport()"
                style="background:#f3f4f6;border:none;border-radius:8px;padding:5px 10px;cursor:pointer">✕</button>
      </div>
    </div>
    <div id="rptBody" style="overflow-y:auto;padding:16px 20px;flex:1">
      <div style="text-align:center;padding:40px;color:#9ca3af">
        <i class="fas fa-spinner fa-spin" style="font-size:1.5rem"></i>
        <div style="margin-top:8px">Memuat data...</div>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

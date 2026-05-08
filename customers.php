<?php
session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/classes/Auth.php';
require_once __DIR__ . '/classes/Customer.php';
require_once __DIR__ . '/classes/ExcelExport.php';
require_once __DIR__ . '/classes/ActivityLogger.php';

Auth::requireAuth();
Auth::requireRole(['admin', 'operator']);

$pageTitle   = 'Customers';
$currentPage = 'customers';
$success = $_GET['success'] ?? null;
$error   = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['create_customer'])) {
            Customer::create($_POST);
            ActivityLogger::log('CREATE_CUSTOMER', 'outbound', 'Customer', null,
                $_POST['customer_code'] ?? null, "Buat customer: " . ($_POST['customer_name'] ?? '—'));
            header('Location: customers.php?success=created'); exit;
        }
        if (isset($_POST['update_customer'])) {
            Customer::update($_POST['id'], $_POST);
            ActivityLogger::log('UPDATE_CUSTOMER', 'outbound', 'Customer', (int)$_POST['id'],
                $_POST['customer_code'] ?? null, "Edit customer: " . ($_POST['customer_name'] ?? '—'));
            header('Location: customers.php?success=updated'); exit;
        }
        if (isset($_POST['delete_customer'])) {
            $cid = (int)$_POST['id'];
            $db  = db();
            $cInfo = $db->prepare("SELECT customer_code, customer_name FROM customers WHERE id=?");
            $cInfo->execute([$cid]);
            $cRow = $cInfo->fetch();
            $db->prepare("UPDATE outbound_orders SET customer_id=NULL WHERE customer_id=?")->execute([$cid]);
            Customer::delete($cid);
            ActivityLogger::log('DELETE_CUSTOMER', 'outbound', 'Customer', $cid,
                $cRow['customer_code'] ?? null, "Hapus customer: " . ($cRow['customer_name'] ?? $cid));
            header('Location: customers.php?success=deleted'); exit;
        }
    } catch (Exception $e) { $error = $e->getMessage(); }
}
$customers = Customer::getAll();
if (isset($_GET['export']) && $_GET['export'] === 'excel') { ExcelExport::exportCustomers($customers); }
require_once __DIR__ . '/includes/header.php';
?>
<style>
.srch-wrap { position:relative }
.srch-icon { position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#9ca3af;pointer-events:none }
.srch-inp  { width:100%;padding:9px 12px 9px 38px;border:2px solid #e5e7eb;border-radius:10px;font-size:.88rem;outline:none;transition:.15s;box-sizing:border-box }
.srch-inp:focus { border-color:#026766;box-shadow:0 0 0 3px rgba(46,125,50,.1) }
.srch-count { font-size:.78rem;color:#6b7280;font-weight:600;padding:4px 10px;background:#f3f4f6;border-radius:20px;white-space:nowrap }
</style>

<div class="space-y-5">
  <div class="wms-banner" style="background:linear-gradient(135deg,#013d3c 0%,#026766 100%)">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px">
      <div><h1><i class="fas fa-users mr-2"></i>Customers</h1></div>
      <div style="display:flex;gap:8px">
        <a href="<?= BASE_URL ?>/customers.php?export=excel" class="wms-btn wms-btn-excel"><i class="fas fa-file-excel"></i> Export</a>
        <button onclick="openModal()" class="wms-btn" style="background:#fff;color:#013d3c;font-weight:700"><i class="fas fa-plus"></i> New Customer</button>
      </div>
    </div>
    <?php $types=[];foreach($customers as $c){$k=$c['customer_type']??'DIRECT';$types[$k]=($types[$k]??0)+1;} ?>
    <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-top:16px">
      <div class="stat-pill"><div class="num"><?= count($customers) ?></div><div class="lbl">Total</div></div>
      <?php foreach(array_slice($types,0,3,true) as $k=>$v): ?>
      <div class="stat-pill"><div class="num"><?= $v ?></div><div class="lbl"><?= $k ?></div></div>
      <?php endforeach; ?>
    </div>
  </div>

  <?php if($success): ?><div class="wms-alert wms-alert-success"><i class="fas fa-check-circle"></i> Customer <?= $success ?> successfully!</div><?php endif; ?>
  <?php if($error):   ?><div class="wms-alert wms-alert-error"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?></div><?php endif; ?>

  <div class="wms-card">
    <div class="wms-card-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px">
      <h2><i class="fas fa-table" style="color:#026766"></i> Customer List</h2>
      <div style="display:flex;align-items:center;gap:10px;flex:1;max-width:420px">
        <div class="srch-wrap" style="flex:1">
          <i class="fas fa-search srch-icon"></i>
          <input type="text" id="custSearch" class="srch-inp"
                 placeholder="Cari nama, kode, kota..."
                 oninput="filterCustomers(this.value)">
        </div>
        <span class="srch-count" id="custCount"><?= count($customers) ?> customer</span>
      </div>
    </div>
    <div class="wms-table-wrap">
      <table class="wms-table">
        <thead><tr>
          <th>Code</th><th>Customer Name</th><th class="tc">Type</th>
          <th>City</th><th>Phone</th><th class="tc">Actions</th>
        </tr></thead>
        <tbody id="custTableBody">
          <?php foreach($customers as $c):
            $searchStr = strtolower(($c['customer_code']??'').' '.$c['customer_name'].' '.($c['city']??$c['kota']??'').' '.($c['customer_type']??''));
          ?>
          <tr data-search="<?= htmlspecialchars($searchStr) ?>">
            <td><span style="font-family:monospace;font-weight:600"><?= htmlspecialchars($c['customer_code'] ?? $c['id']) ?></span></td>
            <td style="font-weight:600"><?= htmlspecialchars($c['customer_name']) ?></td>
            <td class="tc"><span class="wms-badge wms-badge-active" style="background:#e0f7f7;color:#013d3c"><?= htmlspecialchars($c['customer_type']??'DIRECT') ?></span></td>
            <td style="color:#6b7280"><?= htmlspecialchars($c['city']??$c['kota']??'—') ?></td>
            <td style="color:#6b7280"><?= htmlspecialchars($c['phone']??'—') ?></td>
            <td class="tc">
              <button onclick='editCustomer(<?= htmlspecialchars(json_encode($c)) ?>)'
                      class="wms-btn wms-btn-ghost wms-btn-sm" title="Edit">
                <i class="fas fa-edit"></i>
              </button>
              <button onclick='confirmDeleteCustomer(<?= $c['id'] ?>, <?= htmlspecialchars(json_encode($c['customer_name'])) ?>)'
                      class="wms-btn wms-btn-danger wms-btn-sm" title="Hapus">
                <i class="fas fa-trash"></i>
              </button>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if(empty($customers)): ?>
          <tr><td colspan="6"><div class="wms-empty"><i class="fas fa-users"></i>No customers found</div></td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<div id="deleteCustModal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;
     z-index:9999;background:rgba(0,0,0,.5);align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:16px;padding:28px;max-width:400px;width:90%;box-shadow:0 20px 60px rgba(0,0,0,.3)">
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px">
      <div style="background:#e6f7f7;border-radius:50%;width:44px;height:44px;display:flex;align-items:center;justify-content:center">
        <i class="fas fa-exclamation-triangle" style="color:#026766;font-size:1.1rem"></i>
      </div>
      <div>
        <div style="font-weight:800;font-size:1rem">Hapus Customer?</div>
        <div style="font-size:.8rem;color:#6b7280" id="delCustName"></div>
      </div>
    </div>
    <div style="background:#fef9c3;border:1px solid #fde68a;border-radius:8px;padding:10px 14px;font-size:.8rem;color:#854d0e">
      <i class="fas fa-info-circle mr-1"></i>
      Outbound order yang menggunakan customer ini akan di-unlink (tidak dihapus).
    </div>
    <div style="margin-top:12px;font-size:.82rem;color:#374151">Lanjutkan?</div>
    <form method="POST" id="deleteCustForm" style="display:flex;gap:10px;margin-top:18px">
      <input type="hidden" name="id" id="delCustId">
      <button type="submit" name="delete_customer"
              style="flex:1;background:#026766;color:#fff;border:none;border-radius:8px;padding:10px;font-weight:700;cursor:pointer">
        <i class="fas fa-trash mr-1"></i>Hapus
      </button>
      <button type="button" onclick="document.getElementById('deleteCustModal').style.display='none'"
              style="flex:1;background:#f3f4f6;color:#374151;border:none;border-radius:8px;padding:10px;font-weight:600;cursor:pointer">Batal</button>
    </form>
  </div>
</div>

<div id="customerModal" class="wms-modal-overlay" style="display:none">
  <div class="wms-modal">
    <div class="wms-modal-header">
      <h3 id="cModalTitle">New Customer</h3>
      <button onclick="closeModal()" style="background:none;border:none;cursor:pointer;font-size:1.2rem;color:#9ca3af"><i class="fas fa-times"></i></button>
    </div>
    <form method="POST" class="wms-modal-body" style="padding:20px">
      <input type="hidden" name="id" id="customerId">
      <div style="display:grid;gap:12px">
        <div><label class="wms-label">Customer Code</label><input type="text" name="customer_code" id="customerCode" class="wms-input"></div>
        <div><label class="wms-label">Customer Name <span class="wms-req">*</span></label><input type="text" name="customer_name" id="customerName" required class="wms-input"></div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
          <div><label class="wms-label">Type</label>
               <select name="customer_type" id="customerType" class="wms-input">
                 <option>DIRECT</option><option>B2B</option><option>RETAIL</option><option>MARINE</option>
               </select></div>
          <div><label class="wms-label">City</label><input type="text" name="city" id="city" class="wms-input"></div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
          <div><label class="wms-label">Phone</label><input type="text" name="phone" id="cPhone" class="wms-input"></div>
          <div><label class="wms-label">Email</label><input type="email" name="email" id="cEmail" class="wms-input"></div>
        </div>
        <div><label class="wms-label">Address</label><textarea name="address" id="cAddress" rows="2" class="wms-input"></textarea></div>
      </div>
      <div style="display:flex;gap:10px;margin-top:18px">
        <button type="submit" id="cSaveBtn" name="create_customer" class="wms-btn wms-btn-success" style="flex:1"><i class="fas fa-save"></i> Save</button>
        <button type="button" onclick="closeModal()" class="wms-btn wms-btn-ghost" style="flex:1">Cancel</button>
      </div>
    </form>
  </div>
</div>

<script>
function filterCustomers(q) {
    var rows = document.querySelectorAll('#custTableBody tr[data-search]');
    var ql   = q.toLowerCase().trim();
    var vis  = 0;
    rows.forEach(function(r) {
        var m = !ql || r.dataset.search.indexOf(ql) >= 0;
        r.style.display = m ? '' : 'none';
        if (m) vis++;
    });
    document.getElementById('custCount').textContent = vis + ' customer';
}
function confirmDeleteCustomer(id, name) {
    document.getElementById('delCustId').value = id;
    document.getElementById('delCustName').textContent = name;
    document.getElementById('deleteCustModal').style.display = 'flex';
}
function openModal() {
    document.getElementById('cModalTitle').textContent = 'New Customer';
    document.getElementById('customerId').value = '';
    document.getElementById('cSaveBtn').name = 'create_customer';
    document.getElementById('customerModal').style.display = 'flex';
}
function closeModal() { document.getElementById('customerModal').style.display = 'none'; }
function editCustomer(c) {
    document.getElementById('cModalTitle').textContent = 'Edit Customer';
    document.getElementById('customerId').value   = c.id;
    document.getElementById('customerCode').value = c.customer_code || '';
    document.getElementById('customerName').value = c.customer_name;
    document.getElementById('customerType').value = c.customer_type || 'DIRECT';
    document.getElementById('city').value         = c.city || c.kota || '';
    document.getElementById('cPhone').value       = c.phone || '';
    document.getElementById('cEmail').value       = c.email || '';
    document.getElementById('cAddress').value     = c.address || '';
    document.getElementById('cSaveBtn').name      = 'update_customer';
    document.getElementById('customerModal').style.display = 'flex';
}
document.addEventListener('keydown', function(e) {
    if (e.key === '/' && document.activeElement.tagName !== 'INPUT' && document.activeElement.tagName !== 'TEXTAREA') {
        e.preventDefault(); document.getElementById('custSearch').focus();
    }
});
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>

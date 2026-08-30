# K-one WMS — Detailed Task Breakdown

**Start here** if you want to make all features work immediately.

Each task below has:
- ⏱️ Estimated time
- 📝 Exact changes needed
- ✅ How to verify it works
- 🔗 Dependencies

---

## TASK 1: Update Navigation in header.php

**⏱️ Effort:** 30 minutes  
**🔗 Dependencies:** None  
**Priority:** 🔴 CRITICAL (must do first)

### Change Required:

Find this section in `header.php` (around line 150):
```php
<?php endif; ?>
            </nav>
```

Replace with:
```php
<?php endif; ?>

                <!-- WAVES & PICKING SECTION -->
                <div class="border-t my-2" style="border-color:rgba(255,255,255,.07)"></div>
                <p class="px-3 text-[10px] font-semibold uppercase tracking-widest mb-1.5" style="color:#3d8080">Waves & Picking</p>
                
                <a href="<?= BASE_URL ?>/waves.php" class="sidebar-link flex items-center gap-3 px-3 py-2 rounded-lg text-sm <?= ($currentPage ?? '') == 'waves' ? 'active' : '' ?>">
                    <i class="fas fa-layer-group w-4 text-center"></i><span>Waves</span>
                </a>
                
                <a href="<?= BASE_URL ?>/picklist.php" class="sidebar-link flex items-center gap-3 px-3 py-2 rounded-lg text-sm <?= ($currentPage ?? '') == 'picklist' ? 'active' : '' ?>">
                    <i class="fas fa-list-check w-4 text-center"></i><span>Picklist</span>
                </a>
                
                <a href="<?= BASE_URL ?>/putaway_tasks.php" class="sidebar-link flex items-center gap-3 px-3 py-2 rounded-lg text-sm <?= ($currentPage ?? '') == 'putaway_tasks' ? 'active' : '' ?>">
                    <i class="fas fa-tasks w-4 text-center"></i><span>Putaway Tasks</span>
                </a>
                
                <a href="<?= BASE_URL ?>/putaway_scan.php" class="sidebar-link flex items-center gap-3 px-3 py-2 rounded-lg text-sm <?= ($currentPage ?? '') == 'putaway_scan' ? 'active' : '' ?>">
                    <i class="fas fa-barcode w-4 text-center"></i><span>Putaway Scan</span>
                </a>

                <!-- INVENTORY MANAGEMENT SECTION -->
                <div class="border-t my-2" style="border-color:rgba(255,255,255,.07)"></div>
                <p class="px-3 text-[10px] font-semibold uppercase tracking-widest mb-1.5" style="color:#3d8080">Inventory Mgmt</p>
                
                <a href="<?= BASE_URL ?>/replenishment.php" class="sidebar-link flex items-center gap-3 px-3 py-2 rounded-lg text-sm <?= ($currentPage ?? '') == 'replenishment' ? 'active' : '' ?>">
                    <i class="fas fa-retweet w-4 text-center"></i><span>Replenishment</span>
                </a>
                
                <a href="<?= BASE_URL ?>/bin_transfer.php" class="sidebar-link flex items-center gap-3 px-3 py-2 rounded-lg text-sm <?= ($currentPage ?? '') == 'bin_transfer' ? 'active' : '' ?>">
                    <i class="fas fa-exchange-alt w-4 text-center"></i><span>Bin Transfer</span>
                </a>
                
                <a href="<?= BASE_URL ?>/cyclecount.php" class="sidebar-link flex items-center gap-3 px-3 py-2 rounded-lg text-sm <?= ($currentPage ?? '') == 'cyclecount' ? 'active' : '' ?>">
                    <i class="fas fa-check-double w-4 text-center"></i><span>Cycle Count</span>
                </a>
                
                <a href="<?= BASE_URL ?>/abc.php" class="sidebar-link flex items-center gap-3 px-3 py-2 rounded-lg text-sm <?= ($currentPage ?? '') == 'abc' ? 'active' : '' ?>">
                    <i class="fas fa-chart-pie w-4 text-center"></i><span>ABC Analysis</span>
                </a>

                <!-- ADVANCED SECTION -->
                <div class="border-t my-2" style="border-color:rgba(255,255,255,.07)"></div>
                <p class="px-3 text-[10px] font-semibold uppercase tracking-widest mb-1.5" style="color:#3d8080">Advanced</p>
                
                <a href="<?= BASE_URL ?>/asn.php" class="sidebar-link flex items-center gap-3 px-3 py-2 rounded-lg text-sm <?= ($currentPage ?? '') == 'asn' ? 'active' : '' ?>">
                    <i class="fas fa-certificate w-4 text-center"></i><span>ASN</span>
                </a>
                
                <a href="<?= BASE_URL ?>/activity_log.php" class="sidebar-link flex items-center gap-3 px-3 py-2 rounded-lg text-sm <?= ($currentPage ?? '') == 'activity_log' ? 'active' : '' ?>">
                    <i class="fas fa-history w-4 text-center"></i><span>Activity Log</span>
                </a>

            </nav>
```

### ✅ Verification:

1. Reload any page in K-one (e.g., http://localhost/k-one/dashboard.php)
2. Check sidebar — should show 9 new menu items
3. Click each link — verify it navigates to the correct page
4. Verify "active" highlight works (click "Waves" → see it highlighted)

### 🎯 Success Criteria:
- [ ] All 9 new links appear in sidebar
- [ ] Links point to correct URLs
- [ ] Active link highlighting works
- [ ] No JavaScript errors in console

---

## TASK 2: Verify All Page Files Exist

**⏱️ Effort:** 30 minutes  
**🔗 Dependencies:** Task 1 (must update nav first)  
**Priority:** 🔴 CRITICAL

### Files That Must Exist:

```bash
/var/www/k-one/waves.php           # Wave management
/var/www/k-one/picklist.php        # Picklist view
/var/www/k-one/putaway_tasks.php   # Putaway task list
/var/www/k-one/putaway_scan.php    # Barcode scanning for putaway
/var/www/k-one/replenishment.php   # Replenishment management
/var/www/k-one/bin_transfer.php    # Bin transfer management
/var/www/k-one/cyclecount.php      # Cycle counting
/var/www/k-one/abc.php             # ABC analysis
/var/www/k-one/asn.php             # ASN management
/var/www/k-one/activity_log.php    # Activity logs
```

### ✅ How to Check:

```bash
cd /var/www/k-one
ls -1 waves.php picklist.php putaway_tasks.php putaway_scan.php replenishment.php bin_transfer.php cyclecount.php abc.php asn.php activity_log.php

# Should show all 9 files
```

Or test in browser:
```
http://localhost/k-one/waves.php
http://localhost/k-one/picklist.php
# etc.
```

### 🔧 If File Missing:

Create basic template (e.g., `waves.php`):

```php
<?php
session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/classes/Auth.php';

Auth::requireAuth();

$pageTitle = 'Waves';
$currentPage = 'waves';
require_once __DIR__ . '/includes/header.php';
?>

<div class="container mx-auto">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-2xl font-bold">Wave Management</h1>
        <button class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">
            New Wave
        </button>
    </div>
    
    <!-- TODO: Add wave list table here -->
    <div class="bg-white rounded-lg shadow p-6">
        <p class="text-gray-600">Wave management interface coming soon...</p>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
```

Repeat for all 9 pages using same template.

### 🎯 Success Criteria:
- [ ] All 9 pages exist in `/var/www/k-one/`
- [ ] Each page loads without 404 error
- [ ] Each page shows header with navigation
- [ ] No PHP fatal errors

---

## TASK 3: Verify API Endpoints Are Registered

**⏱️ Effort:** 1 hour  
**🔗 Dependencies:** None (can run in parallel)  
**Priority:** 🔴 CRITICAL

### Check If API Handlers Are Registered:

Open `api/registry.php` and verify these lines exist:

```php
// waves
set_permission('waves', 'list', 'read');
set_permission('waves', 'get', 'read');
set_permission('waves', 'create', 'write');
set_permission('waves', 'release', 'write');
set_permission('waves', 'cancel', 'write');
set_module_departments('waves', ['picking', 'outbound']);

// picklist
set_permission('picklist', 'list', 'read');
set_permission('picklist', 'get', 'read');
set_permission('picklist', 'create', 'write');
set_permission('picklist', 'pick', 'write');
set_permission('picklist', 'complete', 'write');
set_module_departments('picklist', ['picking']);

// bintransfer
set_permission('bintransfer', 'list', 'read');
set_permission('bintransfer', 'get', 'read');
set_permission('bintransfer', 'create', 'write');
set_permission('bintransfer', 'execute', 'write');
set_permission('bintransfer', 'cancel', 'write');
set_module_departments('bintransfer', ['inventory']);

// replenishment
set_permission('replenishment', 'list', 'read');
set_permission('replenishment', 'detect', 'read');
set_permission('replenishment', 'suggest', 'read');
set_permission('replenishment', 'generate', 'write');
set_module_departments('replenishment', ['inventory']);

// putaway
set_permission('putaway', 'list', 'read');
set_permission('putaway', 'get_task', 'read');
set_permission('putaway', 'create', 'write');
set_permission('putaway', 'confirm', 'write');
set_module_departments('putaway', ['inbound']);

// cyclecount
set_permission('cyclecount', 'list', 'read');
set_permission('cyclecount', 'create', 'write');
set_permission('cyclecount', 'count', 'write');
set_permission('cyclecount', 'submit', 'write');
set_module_departments('cyclecount', ['inventory']);

// asn
set_permission('asn', 'list', 'read');
set_permission('asn', 'get', 'read');
set_permission('asn', 'create', 'write');
set_permission('asn', 'receive', 'write');
set_module_departments('asn', ['inbound']);

// abc
set_permission('abc', 'list', 'read');
set_permission('abc', 'analyze', 'write');
set_module_departments('abc', ['inventory']);
```

### ✅ How to Test:

```bash
# Test each endpoint
curl "http://localhost/k-one/api/index.php?module=waves&action=list"
curl "http://localhost/k-one/api/index.php?module=picklist&action=list"
curl "http://localhost/k-one/api/index.php?module=bintransfer&action=list"
curl "http://localhost/k-one/api/index.php?module=replenishment&action=list"
curl "http://localhost/k-one/api/index.php?module=cyclecount&action=list"
curl "http://localhost/k-one/api/index.php?module=asn&action=list"
curl "http://localhost/k-one/api/index.php?module=abc&action=list"

# Should return JSON (not "Unknown module" error)
```

### 🎯 Success Criteria:
- [ ] All endpoints return valid JSON
- [ ] No "Unknown module" or "Unknown action" errors
- [ ] HTTP status 200 (not 400/500)
- [ ] Response contains data array

---

## TASK 4: Add API Calls to Each Page

**⏱️ Effort:** 2-3 hours per page × 9 pages = 18-27 hours  
**🔗 Dependencies:** Task 2 & 3  
**Priority:** 🔴 CRITICAL

### Example: waves.php

```php
<?php
session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/classes/Auth.php';

Auth::requireAuth();

$pageTitle = 'Waves';
$currentPage = 'waves';

$error = null;
$waves = [];

// Fetch waves from API
try {
    $url = 'http://localhost' . BASE_URL . '/api/index.php?module=waves&action=list';
    $response = @file_get_contents($url, false, stream_context_create([
        'http' => ['timeout' => 5]
    ]));
    if ($response === false) {
        $error = 'Failed to connect to API';
    } else {
        $data = json_decode($response, true);
        if ($data && isset($data['waves'])) {
            $waves = $data['waves'];
        } else {
            $error = 'Invalid API response';
        }
    }
} catch (Exception $e) {
    $error = $e->getMessage();
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="container mx-auto">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-2xl font-bold">Wave Management</h1>
        <?php if (!$_isViewer): ?>
        <a href="#" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">
            New Wave
        </a>
        <?php endif; ?>
    </div>
    
    <?php if ($error): ?>
    <div class="alert alert-error mb-4"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    
    <div class="bg-white rounded-lg shadow overflow-hidden">
        <table class="w-full border-collapse">
            <thead class="bg-gray-100 border-b">
                <tr>
                    <th class="px-6 py-3 text-left">Wave #</th>
                    <th class="px-6 py-3 text-left">Status</th>
                    <th class="px-6 py-3 text-left">Orders</th>
                    <th class="px-6 py-3 text-left">Created</th>
                    <th class="px-6 py-3 text-left">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($waves)): ?>
                <tr><td colspan="5" class="px-6 py-4 text-center text-gray-500">No waves found</td></tr>
                <?php endif; ?>
                
                <?php foreach ($waves as $wave): ?>
                <tr class="border-b hover:bg-gray-50">
                    <td class="px-6 py-4"><?= htmlspecialchars($wave['wave_number']) ?></td>
                    <td class="px-6 py-4">
                        <span class="px-2 py-1 rounded text-sm font-medium
                            <?php
                            echo match($wave['status']) {
                                'Planning' => 'bg-yellow-100 text-yellow-800',
                                'Active' => 'bg-blue-100 text-blue-800',
                                'Completed' => 'bg-green-100 text-green-800',
                                'Cancelled' => 'bg-red-100 text-red-800',
                                default => 'bg-gray-100 text-gray-800',
                            };
                            ?>
                        ">
                            <?= htmlspecialchars($wave['status']) ?>
                        </span>
                    </td>
                    <td class="px-6 py-4"><?= htmlspecialchars($wave['order_count'] ?? 0) ?></td>
                    <td class="px-6 py-4"><?= date('d M Y H:i', strtotime($wave['created_at'])) ?></td>
                    <td class="px-6 py-4">
                        <a href="#" class="text-blue-600 hover:underline">View</a>
                        <?php if ($wave['status'] === 'Planning' && !$_isViewer): ?>
                        | <a href="#" class="text-green-600 hover:underline">Release</a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
```

### Repeat For:
1. **picklist.php** - List all picklists, show status, pick items
2. **bin_transfer.php** - List transfers, create, execute
3. **replenishment.php** - Detect shortages, suggest, generate transfers
4. **putaway_tasks.php** - List putaway tasks, assign location
5. **cyclecount.php** - Create, count items, submit
6. **asn.php** - List ASNs, receive goods
7. **abc.php** - Show ABC analysis, run analysis
8. **activity_log.php** - Show activity log with filters
9. **putaway_scan.php** - Barcode scanner interface

---

## TASK 5: Create Modal Forms for Create/Edit

**⏱️ Effort:** 3-4 hours  
**🔗 Dependencies:** Task 4  
**Priority:** 🟡 HIGH

For each page, add:

```html
<!-- New Wave Modal -->
<div id="newWaveModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
    <div class="bg-white rounded-lg p-6 max-w-md w-full mx-4">
        <h2 class="text-lg font-bold mb-4">Create Wave</h2>
        <form id="newWaveForm">
            <div class="mb-4">
                <label class="block text-sm font-medium mb-1">Select Orders</label>
                <select name="order_ids[]" multiple class="w-full border rounded p-2 text-sm" required>
                    <option disabled>Choose orders...</option>
                    <!-- Populate from API -->
                </select>
            </div>
            <div class="flex gap-2 justify-end">
                <button type="button" class="px-4 py-2 border rounded hover:bg-gray-100" onclick="closeModal('newWaveModal')">
                    Cancel
                </button>
                <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700">
                    Create
                </button>
            </div>
        </form>
    </div>
</div>

<script>
document.getElementById('newWaveForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const formData = new FormData(e.target);
    const response = await fetch('/api/index.php?module=waves&action=create', {
        method: 'POST',
        body: new URLSearchParams(formData)
    });
    if (response.ok) {
        // Reload waves list
        location.reload();
    }
});
</script>
```

---

## TASK 6: Test Complete Workflows

**⏱️ Effort:** 2-3 days  
**🔗 Dependencies:** Task 1-5  
**Priority:** 🔴 CRITICAL

### Test Scenario 1: Inbound → Putaway → Stock

```
1. Go to Inbound page
2. Create new inbound order
   - Fill: Order date, carrier, container no
   - Add items: Product, Qty, Batch, Expiry
   - Submit
3. Receive order (change status to "Goods Received")
4. Check Putaway Tasks page
   - Should show putaway tasks for received items
5. Go to Putaway Tasks
   - Assign location for each item
   - Confirm putaway
6. Go to Stock page
   - Verify stock is now in the assigned location
7. Check Stock Ledger
   - Should show: Inbound Received → Putaway Created → Stock Available
```

**✅ How to Verify:**
- [ ] Order appears in Inbound list with status "Goods Received"
- [ ] Putaway tasks appear immediately after receipt
- [ ] Stock quantities match received quantities
- [ ] Ledger shows all transactions

### Test Scenario 2: Replenishment → Bin Transfer → Stock Update

```
1. Go to Replenishment page
2. Click "Detect Shortages"
   - Should show pick-face locations below minimum
3. Click "Suggest Transfers"
   - Should show FEFO-ordered sources
4. Click "Generate Transfers"
   - Should create bin transfer records
5. Go to Bin Transfer page
   - New transfers should appear
6. Execute transfer
   - Select transfer, click "Execute"
7. Verify Stock
   - Source location quantity should decrease
   - Destination location quantity should increase
```

**✅ How to Verify:**
- [ ] Shortages detected correctly (available < min_qty)
- [ ] Suggested sources are in FEFO order (by expiry date)
- [ ] Transfers created with correct quantities
- [ ] Stock updated after transfer execution

### Test Scenario 3: Wave → Picklist → Picking

```
1. Go to Waves page
2. Create new wave
   - Select 2-3 outbound orders
   - Submit
3. Wave should appear in status "Planning"
4. Click "Release" wave
   - Status changes to "Active"
5. Go to Picklist page
   - Picklist for this wave should be created
6. Click on picklist to "View Details"
   - Should show items to pick with locations
7. "Pick" items (mark as picked)
8. "Complete" picklist
   - Status should change to "Completed"
9. Go back to Waves page
   - Wave status should update to "Completed"
10. Go to Outbound page
    - Orders should be shippable now
```

**✅ How to Verify:**
- [ ] Wave status progresses: Planning → Active → Completed
- [ ] Picklist created automatically when wave released
- [ ] All items in picklist match wave orders
- [ ] Picking updates stock locations

---

## TASK 7: Fix Database Inconsistencies

**⏱️ Effort:** 1-2 days  
**🔗 Dependencies:** Task 6 complete  
**Priority:** 🟡 HIGH

After running tests, check for:

```sql
-- Check 1: Stock locations don't match stock table
SELECT sl.id, sl.stock_id, sl.quantity, s.quantity
FROM stock_locations sl
LEFT JOIN stock s ON s.id = sl.stock_id
WHERE sl.quantity != s.quantity OR s.id IS NULL;

-- Check 2: Ledger doesn't match stock
SELECT s.id, s.product_id, s.quantity, SUM(sl.qty_delta) as ledger_qty
FROM stock s
LEFT JOIN stock_ledger sl ON sl.product_id = s.product_id
GROUP BY s.id
HAVING s.quantity != ledger_qty;

-- Check 3: Orders with mismatched items
SELECT o.id, COUNT(oi.id) as item_count, SUM(oi.actual_qty) as total_qty
FROM outbound_orders o
LEFT JOIN outbound_items oi ON oi.outbound_order_id = o.id
WHERE o.status = 'Shipped' AND oi.actual_qty < oi.qty_ordered
GROUP BY o.id;
```

For each issue found, investigate and fix.

---

## FINAL CHECKLIST

After all tasks complete:

- [ ] Task 1: Navigation updated (9 new menu items)
- [ ] Task 2: All 9 page files exist and load
- [ ] Task 3: All API endpoints registered and working
- [ ] Task 4: Each page fetches and displays data
- [ ] Task 5: Create/Edit modals functional
- [ ] Task 6: 3 workflows tested end-to-end
- [ ] Task 7: No database inconsistencies found

**Expected Timeline:** 3-4 weeks with 1 developer working full-time

---

## HELP! Something's Broken

If you get stuck on any task:

1. **404 error on page load** → Check if file exists in `/var/www/k-one/`
2. **API returns error** → Check if endpoint is registered in `api/registry.php`
3. **Data not showing** → Check API response with curl, verify JSON structure
4. **Workflow doesn't work** → Test each step manually, check database tables
5. **Button doesn't do anything** → Check browser console for JavaScript errors


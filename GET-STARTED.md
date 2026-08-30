# K-one WMS — Get All Features Working (Quick Start)

**Goal:** Make all 20+ WMS features work and accessible from the UI  
**Time Required:** 3-4 weeks (1 developer full-time)  
**Difficulty:** Medium

---

## THE PROBLEM (In 30 Seconds)

Your WMS has **9 features built but not connected to the UI navigation:**
- Waves ❌
- Picklist ❌
- Bin Transfer ❌
- Replenishment ❌
- Putaway Tasks ❌
- Putaway Scan ❌
- Cycle Count ❌
- ABC Analysis ❌
- ASN ❌

**Result:** Staff can't access these features even though they're fully implemented!

---

## THE SOLUTION (7 Steps)

### Step 1: Add Missing Navigation (30 mins) 🟢 **EASIEST**

**File to edit:** `header.php`

**Action:** Add 9 new menu items to the sidebar.

[→ See full instructions in INTEGRATION-TASKS.md — TASK 1](INTEGRATION-TASKS.md#task-1-update-navigation-in-headerphp)

**Quick version:**
```bash
# Backup original
cp header.php header.php.backup

# Add these 9 links in the navigation menu:
# - Waves
# - Picklist
# - Bin Transfer
# - Replenishment
# - Putaway Tasks
# - Putaway Scan
# - Cycle Count
# - ABC Analysis
# - ASN
# - Activity Log
```

**After:** Sidebar has all 20 features visible ✅

---

### Step 2: Verify All Pages Exist (30 mins) 🟢 **EASIEST**

**Action:** Check if all 9 page files exist in `/var/www/k-one/`

**Command:**
```bash
cd /var/www/k-one
ls -1 waves.php picklist.php putaway_tasks.php putaway_scan.php replenishment.php bin_transfer.php cyclecount.php abc.php asn.php

# Should show 9 files
```

**If any file missing:**
1. Copy a similar page file (e.g., copy `bin_transfer.php`)
2. Edit it to load the correct API module
3. Save with new filename

**After:** All 9 pages load without errors ✅

---

### Step 3: Test API Endpoints (1 hour) 🟡 **MODERATE**

**Action:** Verify all API endpoints work

**Command:**
```bash
# Test each API endpoint
curl "http://localhost/k-one/api/index.php?module=waves&action=list"
curl "http://localhost/k-one/api/index.php?module=picklist&action=list"
curl "http://localhost/k-one/api/index.php?module=bintransfer&action=list"
curl "http://localhost/k-one/api/index.php?module=replenishment&action=list"
curl "http://localhost/k-one/api/index.php?module=cyclecount&action=list"
curl "http://localhost/k-one/api/index.php?module=asn&action=list"
curl "http://localhost/k-one/api/index.php?module=abc&action=list"

# Each should return JSON with data, not an error
```

**If endpoint fails:**
1. Check if handler exists in `api/handlers/waves.php` (etc.)
2. Check if action is registered in `api/registry.php`
3. Check database has test data

**After:** All APIs respond with JSON ✅

---

### Step 4: Add Data Display to Pages (3-4 hours) 🟡 **MODERATE**

**Action:** Add code to each page to fetch and display data from API

**For each page (waves.php, picklist.php, etc.):**

1. Add API fetch code:
```php
try {
    $url = BASE_URL . '/api/index.php?module=waves&action=list';
    $response = @file_get_contents($url);
    $data = json_decode($response, true);
    $waves = $data['waves'] ?? [];
} catch (Exception $e) {
    $error = $e->getMessage();
}
```

2. Add HTML table to display data:
```html
<table class="w-full">
    <tr><th>Wave #</th><th>Status</th><th>Orders</th></tr>
    <?php foreach ($waves as $w): ?>
    <tr>
        <td><?= $w['wave_number'] ?></td>
        <td><?= $w['status'] ?></td>
        <td><?= $w['order_count'] ?></td>
    </tr>
    <?php endforeach; ?>
</table>
```

[→ See full example in INTEGRATION-TASKS.md — TASK 4](INTEGRATION-TASKS.md#task-4-add-api-calls-to-each-page)

**After:** Each page displays data from the database ✅

---

### Step 5: Add Forms for Create/Edit (3-4 hours) 🟡 **MODERATE**

**Action:** Add forms to create new records

**For each page:**
1. Add "New" button (e.g., "New Wave")
2. Add modal form with input fields
3. Add JavaScript to submit form to API

**Example:**
```html
<button onclick="document.getElementById('newWaveModal').classList.remove('hidden')">
    New Wave
</button>

<div id="newWaveModal" class="hidden modal">
    <form onsubmit="createWave(event)">
        <input name="order_ids" placeholder="Select orders..." required>
        <button type="submit">Create</button>
    </form>
</div>

<script>
async function createWave(event) {
    event.preventDefault();
    const response = await fetch('/api/index.php?module=waves&action=create', {
        method: 'POST',
        body: new FormData(event.target)
    });
    if (response.ok) location.reload();
}
</script>
```

[→ See full example in INTEGRATION-TASKS.md — TASK 5](INTEGRATION-TASKS.md#task-5-create-modal-forms-for-createedit)

**After:** Users can create new records from UI ✅

---

### Step 6: Test Complete Workflows (2-3 days) 🔴 **HARD**

**Action:** Test each major workflow end-to-end

**Test 3 critical workflows:**

1. **Inbound → Putaway → Stock**
   - Create inbound order
   - Receive goods
   - Check putaway tasks appear
   - Confirm putaway
   - Verify stock updated

2. **Replenishment → Bin Transfer**
   - Go to Replenishment
   - Detect shortages
   - Suggest sources
   - Generate transfers
   - Execute transfers
   - Verify stock changed

3. **Wave → Picklist → Pick**
   - Create wave with orders
   - Release wave
   - Check picklist created
   - Pick items
   - Complete picklist
   - Verify orders ready to ship

[→ See full test scenarios in INTEGRATION-TASKS.md — TASK 6](INTEGRATION-TASKS.md#task-6-test-complete-workflows)

**After:** Workflows execute end-to-end without errors ✅

---

### Step 7: Fix Data Inconsistencies (1-2 days) 🔴 **HARD**

**Action:** Fix any mismatches between tables

**Run these SQL checks:**
```sql
-- Check 1: Stock locations match stock table
SELECT sl.id FROM stock_locations sl 
LEFT JOIN stock s ON s.id = sl.stock_id 
WHERE s.id IS NULL;

-- Check 2: Ledger matches stock
SELECT COUNT(*) as mismatches FROM stock s
WHERE s.quantity NOT IN (SELECT SUM(qty_delta) FROM stock_ledger WHERE product_id = s.product_id);

-- Check 3: Orders have all items
SELECT COUNT(*) as issues FROM outbound_orders o
WHERE status = 'Shipped' AND o.id NOT IN (
    SELECT outbound_order_id FROM outbound_items WHERE actual_qty > 0
);
```

**If issues found:**
- Investigate root cause
- Fix database directly or via API
- Re-test workflow

**After:** No data inconsistencies ✅

---

## SUMMARY

| Step | Task | Time | Difficulty | Impact |
|------|------|------|------------|--------|
| 1 | Add navigation | 30m | 🟢 Easy | All features visible |
| 2 | Verify pages | 30m | 🟢 Easy | Pages load without error |
| 3 | Test APIs | 1h | 🟡 Medium | APIs working |
| 4 | Add data tables | 4h | 🟡 Medium | Users see data |
| 5 | Add forms | 4h | 🟡 Medium | Users can create records |
| 6 | Test workflows | 2-3d | 🔴 Hard | End-to-end working |
| 7 | Fix data issues | 1-2d | 🔴 Hard | Data consistent |
| **TOTAL** | **All features working** | **3-4 weeks** | **Medium** | **✅ Complete WMS** |

---

## PRIORITY

**🔴 DO THESE FIRST (Week 1):**
1. Step 1 — Add navigation
2. Step 2 — Verify pages
3. Step 3 — Test APIs
4. Step 4 — Add data tables

**Then you'll have:**
- ✅ All features accessible
- ✅ Staff can view all data
- ✅ Basic workflows work

**🟡 DO THESE NEXT (Weeks 2-3):**
5. Step 5 — Add forms (create new records)
6. Step 6 — Test workflows (critical paths)

**Then you'll have:**
- ✅ Full feature set working
- ✅ No manual workarounds
- ✅ Professional system

**🟢 DO THESE LAST (Week 4):**
7. Step 7 — Fix data issues (polish)

**Then you'll have:**
- ✅ Production-ready system
- ✅ Data integrity verified
- ✅ Ready to scale

---

## WHAT'S ALREADY DONE ✅

Don't rebuild these — they already work:

- ✅ All databases tables created (40+ tables)
- ✅ All service classes implemented (35 classes)
- ✅ All API handlers created (41 handlers)
- ✅ All business logic in place (FEFO, replenishment, waves)
- ✅ Authentication & permissions working
- ✅ Frontend framework set up

**You just need to:** CONNECT THE PIECES together

---

## REFERENCE DOCUMENTS

After reading this, check these detailed guides:

1. **FEATURES-INTEGRATION-PLAN.md** — Understanding the architecture & gaps
2. **INTEGRATION-TASKS.md** — Detailed step-by-step instructions with code
3. **WMS-ASSESSMENT.md** — Current maturity score & areas to improve

---

## COMMON QUESTIONS

**Q: Can I skip any steps?**  
A: No. Each step depends on the previous one. But you can combine Steps 1-3 in one day.

**Q: How many people do I need?**  
A: 1 developer (full-time for 3-4 weeks) or 2 developers (part-time for 2 weeks).

**Q: What if I get stuck?**  
A: See the troubleshooting section in INTEGRATION-TASKS.md. Or check the API responses with curl to debug.

**Q: Can I use the React app instead of PHP pages?**  
A: React components exist but aren't integrated. Stick with PHP for now (faster). Migrate to React later.

**Q: How long until everything is 100% working?**  
A: 3-4 weeks for full feature set. After that, you can keep improving (performance, analytics, mobile, etc.).

---

## LET'S GET STARTED! 🚀

**Next action:**
1. Open `header.php`
2. Find line ~150 (the `</nav>` closing tag)
3. Follow Step 1 instructions in INTEGRATION-TASKS.md
4. Test by reloading the dashboard page
5. Verify 9 new menu items appear

**Timeline:**
- Week 1: Steps 1-4 ✅ (foundation working)
- Week 2-3: Steps 5-6 ✅ (full features)
- Week 4: Step 7 ✅ (polish & fix)

Good luck! 💪

---

## NEED HELP?

- Can't find a file? → Check INTEGRATION-TASKS.md — TASK 2
- API returning error? → Check INTEGRATION-TASKS.md — TASK 3
- Form not working? → Check browser console for JavaScript errors
- Workflow doesn't work? → Test each step manually with curl
- Data mismatch? → Run SQL checks in INTEGRATION-TASKS.md — TASK 7


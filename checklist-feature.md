# K-one v2 Exclusive Features Checklist

> These features exist ONLY in k-one-v2 and are NOT in the original PHP system.

---

## 1. Stock Hold / Quarantine (Phase 1)

- [ ] `stock.hold_status` (available|on_hold|quarantine|damaged) + hold_reason/hold_by/hold_at
- [ ] `stock::hold` / `stock::release` actions (write permission, ledger + activity_log)
- [ ] Excluded from ALL picking/allocation: outbound pickItems/addItemWithFEFO/getAvailableStock, import fefoAllocation, picklist createFromOutbound
- [ ] StockTake autoLoadByLocations STILL includes held stock (physical count)
- [ ] Frontend: Hold Status column + Hold/Quarantine/Release buttons + modals on StockPage

---

## 2. Audit Trail Hardening (Phase 5)

- [ ] Audited every stock-mutating site for same-tx ledger writes
- [ ] Gap 1 fixed: `inbound.changeItemStatus` now atomic (stock+ledger in one tx)
- [ ] Gap 2 fixed: `import.stockCommitTx` replace/add modes now write ledger rows
- [ ] Gap 3 fixed: `outbound.deleteItem` on Shipped order now removes OUT ledger rows

---

## 3. Barcode Scanning UX (Phase 2)

- [ ] `stock::scan`: single-query product_code lookup + FEFO-first expected location
- [ ] `stock::scan_override`: mismatch override with reason → activity_log SCAN_OVERRIDE
- [ ] `ScanInput` component: autofocused, Enter-submit, clears+refocuses (USB scanner friendly)
- [ ] PicklistDetail: scan auto-confirms next Pending item; mismatch → reason → Override & Lanjut
- [ ] InboundDetail: scan validates against putaway target; green match / red mismatch + override
- [ ] BinTransferPage: scan auto-selects product or validates against selected; mismatch → override

---

## 4. Replenishment Suggestions (Phase 3)

- [ ] `pick_face_targets` table: location_id, product_id, min_qty, max_qty
- [ ] `replenishment::list`: below-min_qty suggestions with FEFO-ordered bulk/reserve sources (non-hold)
- [ ] `replenishment::targets/save_target/delete_target`: management CRUD
- [ ] Frontend ReplenishmentPage: suggestions table + one-click "Create Transfer" → pre-filled bin_transfer

---

## 5. Wave Planning (Phase 4)5.xcxz/

- [ ] `waves` + `wave_orders` tables: wave_number, status (Planning/Active/Completed/Cancelled), carrier, cutoff_time
- [ ] `waves::create`: multi-order → single consolidated picklist (location-sorted for pick-path)
- [ ] `waves::cancel`: deletes Draft picklists only, preserves progressed ones
- [ ] `waves::candidate_orders`: Open orders without picklists
- [ ] `picklist.service.ts` refactored: shared `insertPicklistItems` + `createFromOrders` (single-order flow unchanged)
- [ ] Frontend WavesPage: wave list + New Wave modal (multi-select orders) + detail modal

---

## 6. ASN — Advance Shipping Notice (Phase 6)

- [ ] `asn` + `asn_items` tables: ASN-YYYYMM-NNNN numbers, Pending/Received/Cancelled
- [ ] `asn::create/update/cancel`: supplier notification with expected items
- [ ] Inbound integration: `create()` pre-fills from ASN items; `complete()` flips ASN → Received
- [ ] `export::asn`: XLSX export
- [ ] Frontend: AsnList + AsnDetail pages, InboundList `?asn_id` pre-fill

---

## 7. ABC Analysis / Velocity-Based Ranking (Phase 8)

- [ ] `products.velocity_class` (A|B|C) + `velocity_class_at`
- [ ] `abc::analyze`: read-only preview (OUT volume ranking, cumulative-share bucketing)
- [ ] `abc::recompute`: admin writes classes in one tx
- [ ] `abc::status`: classified/total + last_computed_at (for admin dashboard)
- [ ] Frontend: ABC Analysis modal on ProductsPage, Velocity column on StockPage

---

## 8. Cycle Count Scheduling (Phase 10)

- [ ] `cycle_count_schedules` table: frequency (weekly/monthly/quarterly), scope (full/location/velocity), next_run_date
- [ ] `cyclecount::run_due`: generates stock takes from due schedules, advances next_run_date
- [ ] `cyclecount::run_now`: single schedule regardless of due date
- [ ] Velocity-scoped schedules auto-load only that ABC class
- [ ] Reuses `StockTakeService.create()` — no duplicate logic
- [ ] Frontend CycleCountPage: table + New/Edit modal + Run Due/Run Now

---

## 9. Cross-Docking (Phase 7)

- [ ] `inbound_items.cross_dock_outbound_order_id`: route incoming stock to outbound without putaway
- [ ] ATP transition bypasses normal putaway/FEFO: stages at STAGING, writes ledger, inserts stock_locations, adds cross-dock item to outbound's Draft picklist
- [ ] `outbound.pickItems` cross-docked lines pick from STAGING only
- [ ] Frontend: InboundDetail cross-dock selector + CROSS-DOCK badge; OutboundDetail CROSS-DOCK badge

---

## 10. Department-Based Roles & Dashboards (Phase 0)

### Department Roles
- [ ] `users.department` (inbound|outbound|inventory|ops|all)
- [ ] Module-level department guards: inbound→inbound, outbound→outbound, picklist→outbound, stocktake→inventory, bintransfer→inventory, stock/ledger→inventory, dashboard→[inbound,outbound,inventory,all], import/export/print→all, master→all
- [ ] Read-only lookup exceptions: `customers::all`→outbound, `locations::all`→inventory, `inbound::search_products`→[inbound,inventory]
- [ ] Login redirect to `departmentHome(department)`
- [ ] Layout nav filtered by department
- [ ] Header dept badge when != 'all'

### Inbound Dashboard (S29)
- [ ] "ASN Pending" 5th KPI card + top-10 ASN table (by expected arrival)
- [ ] Cross-dock badge on pending-orders table (`cross_dock_count` aggregate)
- [ ] Per-section resilience (ASN fetch independent of main dashboard)

### Outbound Dashboard (S30)
- [ ] "Wave Aktif" 5th KPI card + top-10 waves table (by cutoff)
- [ ] Cross-dock badge on open-orders table (scalar subquery)
- [ ] Per-section resilience (waves fetch independent of main dashboard)

### Inventory Dashboard (S31)
- [ ] "Stok Di-Hold / Karantina" KPI card (client-side held filter)
- [ ] "Replenishment Dibutuhkan" KPI + top-10 table (sorted by shortage)
- [ ] "Cycle Count Jatuh Tempo" KPI + due schedules table
- [ ] Per-section resilience (stock/replenishment/cyclecount fetches independent)

### Admin/Combined Dashboard (S32)
- [ ] "Recent Scan Overrides" card (activitylog filtered to SCAN_OVERRIDE)
- [ ] "ABC Analysis — Status ABC" card (classified/total, freshness badge)
- [ ] Admin-only (`canAdmin`), per-section resilience

---

## 11. Putaway Intelligence & Physical Warehouse Features

### Putaway Recommendation Engine (S33)
- [ ] Connected `putaway::recommend` to Inbound UI (was built but never called)
- [ ] Auto-suggest on Manage Pallet Locations modal (when no saved locations)
- [ ] "Saran Lokasi (Putaway)" button for manual re-suggest
- [ ] Add Item modal also gets putaway suggestion button
- [ ] Cross-docked items excluded from auto-suggest

### Per-SKU Palletization (UPP from Pack Size) (S35)
- [ ] `deriveUppFromPackSize()`: parses product name → correct UPP (16 pack-size rules)
- [ ] Precedence: request → derived from name → stored product default → 4
- [ ] Import auto-derives UPP from product name at create time
- [ ] Frontend shows "N pallet penuh @ UPP + sisa X ke pick-face"

### 3D Warehouse Map (S36)
- [ ] 3D rack map (`putaway::bins`) populated after stock/auto import (was empty)
- [ ] `syncLocations()` helper: stock import now creates `stock_locations` rows
- [ ] Backfills previously imported stock on re-run

### Inbound Items Per-Pallet View (S37)
- [ ] Items table shows ONE row per pallet/location (same product repeated, like spreadsheet)
- [ ] Pallet seq, per-pallet qty/actual, location displayed

### Auto-Putaway on Add (S38)
- [ ] Items added with just product+qty auto-suggest bins (no more UNALLOCATED)
- [ ] Remainder always goes to PICK_FAST (Level A)

### Rack Map Location on Locations Tab (S39)
- [ ] 2D Rack Map + 3D Rack View moved from Zoning → Locations tab
- [ ] Zoning tab is now rules-only (UOM/Produk & Putaway Rules/Zones/Zone Aisles)

### Putaway Location Blocking (S40)
- [ ] Admin can block entire aisles (prefix match) or specific bins
- [ ] Excluded from `putaway::recommend` (both full-pallet and pick-face)
- [ ] Rejected by `putaway::validate` (manual saves)
- [ ] Soft delete (`is_active=false`) for history
- [ ] 3D rack view: blocked bins render warning-red + stay opaque
- [ ] 2D map: `⛔` marker on blocked bins

### BULK/PICK FACE Badge Fix (S41)
- [ ] `pallet_function` now written by ALL save paths (was only written by import)
- [ ] Shared helpers `palletFunctionFor()` + `isFullPallet()` as single source of truth
- [ ] 3D detail panel: RESERVE → blue BULK, PICK_FACE → green PICK FACE, MIXED → purple
- [ ] 2D detail table: Fungsi column

### Putaway Task Queue (S42)
- [ ] `putaway_tasks` + `putaway_task_items` tables (PKA-YYYYMMDD-NNNN)
- [ ] Auto-create on Goods Received (deferred stock_locations write)
- [ ] Block inbound complete while open tasks have Pending pallets
- [ ] Per-inbound granularity (one task batches all items' pallets)
- [ ] `completeTask`: materializes stock_locations only after ALL pallets confirmed
- [ ] Manual Manage Pallet Locations reconciles task rows
- [ ] Frontend PutawayTasksPage: queue list + detail + Assign/Complete/Cancel

### LPN + 2-Person Team + Mobile Dual-Scan (S49)
- [ ] `lpn_code` per pallet (unique, generated at Goods Received)
- [ ] LPN label printing (CODE128 barcode, swappable `LabelPrinterService` interface)
- [ ] 2-person team: forklift operator + checklist partner
- [ ] `PutawayScanPage`: mobile LPN→bin dual-scan confirmation
- [ ] Mismatch → reason → `scan_override` → `task_complete_pallet`
- [ ] Strict role split: partner confirms pallets, inbound operator finishes task
- [ ] Inbound detail shows Putaway Task card (LPN labels + team assignment)
- [ ] `myTasks`: partner's own Pending/In Progress tasks with forklift name

---

## 12. Operations Department & Handheld Menu

- [ ] `ops` department (migration 017): handheld menu set for putaway + outbound operators
- [ ] Putaway Tasks nav visible to inbound/inventory/ops/all
- [ ] Outbound + Picklist nav visible to outbound/ops/all
- [ ] Putaway Saya (mobile scan) visible to inbound/outbound/inventory/ops/all
- [ ] Dashboard excluded from ops (no dashboard access)
- [ ] `task_list mine=1`: filters to current user's tasks (assigned + on team)
- [ ] "Tugas Saya" toggle on PutawayTasksPage (default ON for ops)

---

## 13. Barcode-Driven Putaway (Handheld)

- [ ] Bin barcode labels: `locations::print_labels` (rack-walk order, zone filter)
- [ ] `LocationLabels` component: CODE128 barcodes, 4-column grid print layout
- [ ] LocationsPage "Cetak Label Lokasi" button (respects zone filter)

---

## 14. Reset Operational Data

- [ ] Fixed FK constraint error: added `waves` + `wave_orders` to truncate list
- [ ] Admin reset truncates all 18 operational tables, preserves master data

---

## Summary

| Category | Feature Count |
|----------|---------------|
| Stock Hold / Quarantine | 5 |
| Audit Trail Hardening | 4 |
| Barcode Scanning UX | 6 |
| Replenishment Suggestions | 4 |
| Wave Planning | 6 |
| ASN | 5 |
| ABC Analysis | 5 |
| Cycle Count Scheduling | 6 |
| Cross-Docking | 4 |
| Department Roles & Dashboards | 22 |
| Putaway Intelligence | 44 |
| Operations & Handheld | 7 |
| Barcode-Driven Putaway | 3 |
| Reset Operational Data | 2 |
| **Total** | **123** |

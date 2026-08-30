# K-one WMS — Today's Checklist (Start Now!)

**Start Time:** ___________  
**Target End Time:** 8 hours from start  
**Use Model:** Opus-5 (best quality) or Sonnet-5 (faster)

---

## ⏰ 0-30 MIN: SETUP PHASE

- [ ] Open terminal 1
- [ ] Run: `php run_migrations.php` in `/var/www/k-one`
- [ ] Wait for completion message
- [ ] Verify: `curl http://localhost/k-one/api/index.php?module=dashboard&action=status` returns JSON

- [ ] Open terminal 2
- [ ] Run: `cd frontend && npm install && npm run dev`
- [ ] Wait for: "Local: http://localhost:5173"
- [ ] Keep this terminal open all day

- [ ] Open browser: `http://localhost:5173/login`
- [ ] Login: admin / admin123
- [ ] Verify: Dashboard loads, all menu items visible

**✅ Setup Complete @ ___:___**

---

## 2:30-4:00 HOUR: LIST PAGES (Using Claude Code)

### WavesPage.tsx (30 min)
```
[ ] Open Claude Code on this file
[ ] Prompt: "Complete list page. Fetch /api/index.php?module=waves&action=list. 
    Show table with wave_number, status, order_count. Add 'New Wave' button."
[ ] Claude generates code
[ ] Save file
[ ] Test: http://localhost:5173/waves shows list
[ ] ✅ DONE
```

### PicklistList.tsx (30 min)
```
[ ] Same process as waves
[ ] Test: http://localhost:5173/picklist shows list
[ ] ✅ DONE
```

### BinTransferPage.tsx (30 min)
```
[ ] Same process
[ ] Test: http://localhost:5173/bin-transfer shows list
[ ] ✅ DONE
```

### ReplenishmentPage.tsx (30 min)
```
[ ] Same process
[ ] Test: http://localhost:5173/replenishment shows list
[ ] ✅ DONE
```

**Do these in parallel (4 Claude tabs = 30 min total instead of 2 hours!)**

**✅ All 4 List Pages Complete @ ___:___**

---

## 4:00-5:30 HOUR: DETAIL PAGES (Using Claude Code)

### WaveDetail.tsx (20 min)
```
[ ] Open Claude Code
[ ] Prompt: "Complete detail page. Get ID from URL. Fetch /api/index.php?module=waves&action=get&id=ID.
    Show wave info in cards. Show orders in table. Add Release/Cancel buttons."
[ ] Save and test: http://localhost:5173/waves/1
[ ] ✅ DONE
```

### PicklistDetail.tsx (20 min)
```
[ ] Same pattern
[ ] Test: http://localhost:5173/picklist/1 shows detail
[ ] ✅ DONE
```

### AsnDetail.tsx (20 min)
```
[ ] Same pattern
[ ] ✅ DONE
```

### InboundDetail.tsx (20 min)
```
[ ] Same pattern
[ ] ✅ DONE
```

**Parallel: 4 detail pages = 30 min total instead of 1.5 hours**

**✅ All 4 Detail Pages Complete @ ___:___**

---

## 5:30-7:00 HOUR: MODALS & FORMS (Using Claude Code)

### CreateWaveModal.tsx (25 min)
```
[ ] Open Claude Code
[ ] Prompt: "Create form modal. Fields: order_ids (multi-select). 
    Call /api/index.php?module=waves&action=create on submit.
    Show loading state, error message, success close."
[ ] Save
[ ] Import in WavesPage.tsx
[ ] Test: Click "New Wave" button opens modal
[ ] ✅ DONE
```

### CreatePicklistModal.tsx (15 min)
```
[ ] Same pattern
[ ] ✅ DONE
```

### CreateBinTransferModal.tsx (20 min)
```
[ ] Same pattern
[ ] ✅ DONE
```

**Parallel: 3 modals = 30 min**

### Update api.ts with Missing Methods (20 min)
```
[ ] Open Claude Code on frontend/src/lib/api.ts
[ ] Prompt: "Add API methods for waves, picklist, bintransfer, replenishment, cyclecount.
    Each needs: list(), get(id), create(data), update(id,data), delete(id)"
[ ] Save
[ ] Test API in browser console: 
    fetch('/api/index.php?module=waves&action=list').then(r => r.json())
[ ] Should return wave data
[ ] ✅ DONE
```

**✅ All Modals & APIs Complete @ ___:___**

---

## 7:00-8:00 HOUR: INTEGRATION & TESTING

### Manual Integration
```
[ ] In WavesPage.tsx: Add link to detail page
    <Link to={`/waves/${wave.id}`}>View</Link>

[ ] In WavesPage.tsx: Import and wire CreateWaveModal
    <CreateWaveModal isOpen={showModal} onClose={closeModal} />

[ ] Repeat for: PicklistList, BinTransferPage, ReplenishmentPage
```

### Test Critical Workflows

**Workflow 1: Create & Release Wave**
```
[ ] Go to http://localhost:5173/waves
[ ] Click "New Wave"
[ ] Modal opens: ✅
[ ] Select orders and submit: ✅
[ ] New wave appears in list: ✅
[ ] Click wave to go to detail: ✅
[ ] Click "Release": ✅
[ ] Status changes to "Active": ✅
```

**Workflow 2: Create Picklist**
```
[ ] Go to http://localhost:5173/picklist
[ ] Click "New Picklist"
[ ] Modal opens: ✅
[ ] Submit: ✅
[ ] Picklist appears in list: ✅
[ ] Click to view detail: ✅
```

**Workflow 3: Bin Transfer**
```
[ ] Go to http://localhost:5173/bin-transfer
[ ] Click "New Transfer"
[ ] Modal opens: ✅
[ ] Submit: ✅
[ ] Transfer appears: ✅
```

### Check for Errors
```
[ ] Open DevTools (F12)
[ ] Go to Console tab
[ ] Do workflows above
[ ] Any red errors?: Fix them with Claude Code
[ ] Network tab: All API calls 200 OK?: Check if not
```

---

## ✅ FINAL VERIFICATION

By 8:00 hours from start, verify:

### Navigation & Access
- [ ] http://localhost:5173/waves loads
- [ ] http://localhost:5173/picklist loads
- [ ] http://localhost:5173/bin-transfer loads
- [ ] http://localhost:5173/replenishment loads
- [ ] All show data (not blank tables)

### Create Features
- [ ] Can create wave ✅
- [ ] Can create picklist ✅
- [ ] Can create transfer ✅
- [ ] Can create replenishment task ✅

### Edit Features
- [ ] Can release wave ✅
- [ ] Can complete picklist ✅
- [ ] Can execute transfer ✅

### Data Verification
- [ ] All tables show data from database ✅
- [ ] No "undefined" values ✅
- [ ] Dates formatted correctly ✅
- [ ] Status badges colored correctly ✅

### Error Checking
- [ ] Browser console: No red errors ✅
- [ ] Network tab: No 404s ✅
- [ ] Network tab: API calls return 200 ✅
- [ ] Forms: No validation errors on valid input ✅

### Final Test
```bash
# Quick sanity check
curl http://localhost:5173/waves
# Should return HTML page (not error)

curl http://localhost/k-one/api/index.php?module=waves&action=list
# Should return JSON with waves array
```

---

## 🎉 IF ALL CHECKS PASS

**YOU'RE DONE!**

The WMS is now:
- ✅ All 20+ features accessible
- ✅ All features with working UI
- ✅ All workflows tested
- ✅ All data displaying
- ✅ All forms working

---

## 🆘 TROUBLESHOOTING (If Something Breaks)

### Problem: "Component not showing data"
```
Action: 
1. Open DevTools → Network tab
2. Find API call
3. Check response: Is it valid JSON?
4. If no: Backend issue, check /api/handlers/[module].php
5. If yes: Frontend issue, show Claude the response and ask to fix component
```

### Problem: "Button doesn't do anything"
```
Action:
1. Check console for JavaScript errors
2. If error: Show Claude the error, ask to fix
3. If no error: Button might not be wired, ask Claude to check onClick handler
```

### Problem: "Form won't submit"
```
Action:
1. Check console for errors
2. Open Network tab and try to submit
3. Is there an API call? If yes, what's the response?
4. Show Claude the response, ask why form doesn't handle it
```

### Problem: "API returning error"
```
Action:
1. Test API directly: curl http://localhost/k-one/api/index.php?module=waves&action=list
2. If error: Issue in PHP backend
3. Check that all migrations ran: php run_migrations.php
4. If migrations passed: Check API handler /api/handlers/waves.php
```

### Quick Fix with Claude
```bash
# For any broken file:
claude --file broken_file.tsx
# "Why isn't this working? Error is: [paste error]"
# Claude fixes it immediately
```

---

## ⏱️ TIME LOG

```
Start Time:        ___:___
Setup Complete:    ___:___ (should be +30min)
List Pages Done:   ___:___ (should be +1.5hrs = 2hrs total)
Detail Pages Done: ___:___ (should be +1.5hrs = 3.5hrs total)
Modals & APIs Done:___:___ (should be +1.5hrs = 5hrs total)
Testing Done:      ___:___ (should be +1.5hrs = 6.5hrs total)
Everything Done:   ___:___ (should be ~7-8hrs total)
```

---

## 🏁 YOU'VE GOT THIS!

**Questions?** At any point, just ask Claude Code to help.

**Stuck?** Section "🆘 TROUBLESHOOTING" above.

**Starting now?** Go to "⏰ 0-30 MIN" section and begin!

**Target:** Have a complete, working WMS by end of day! 🚀


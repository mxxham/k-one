# K-one WMS — Complete TODAY Using Claude Code

**Goal:** Get the entire WMS working TODAY using Claude Code with parallel AI execution  
**Approach:** Use Opus (best model) + aggressive parallelization  
**Expected Time:** 6-8 hours wall-clock time

---

## 🚀 Phase 1: Setup (30 minutes)

### 1.1: Run Migrations
```bash
cd /var/www/k-one
php run_migrations.php
# Wait for completion
```

### 1.2: Start Vite Dev Server
```bash
cd frontend
npm install  # if needed
npm run dev
# Keep this terminal open
# Should see: ➜  Local:   http://localhost:5173/
```

### 1.3: Verify Backend Running
```bash
curl http://localhost/k-one/api/index.php?module=dashboard&action=status
# Should return JSON, not error
```

**After 30 min:** ✅ Infrastructure ready

---

## 🤖 Phase 2: Use Claude Code with Opus Model (5-6 hours)

### 2.1: Configure Claude Code for Opus

In your terminal or Claude Code settings:
```bash
# Set to use Opus (best for complex code generation)
claude config --model opus-5

# Or use the fast flag for parallel execution
claude --fast
```

### 2.2: Task 1 - Complete List Pages (2 hours)

**Use Claude Code to implement in PARALLEL:**

```bash
# Launch Claude Code in each directory
claude --file frontend/src/pages/WavesPage.tsx
claude --file frontend/src/pages/PicklistList.tsx
claude --file frontend/src/pages/BinTransferPage.tsx
claude --file frontend/src/pages/ReplenishmentPage.tsx
```

**Prompt to give Claude:**
```
Complete this React component for [FEATURE_NAME]:

1. Add useEffect to fetch data from API:
   const data = await fetch('/api/index.php?module=[MODULE]&action=list')

2. Add useState for:
   - data (array)
   - loading (boolean)
   - error (string)

3. Render a table showing all records with columns:
   - ID/Name
   - Status
   - Created date
   - Action links

4. Add "New [FEATURE]" button that opens a modal

5. Use existing components: Spinner, StatusBadge, Modal

6. Match the styling in ProductsPage.tsx (use it as template)

7. Add loading state (show Spinner while loading)
8. Add error state (show error message)

Use TypeScript with proper types.
```

**Expected output per page:** 150-200 lines of complete, working code

**Do in parallel:** 4 developers (or 1 Claude Code with 4 tabs open) = 2 hours for all

---

### 2.3: Task 2 - Complete Detail Pages (1.5 hours)

**Use Claude Code:**
```bash
claude --file frontend/src/pages/WaveDetail.tsx
claude --file frontend/src/pages/PicklistDetail.tsx
claude --file frontend/src/pages/AsnDetail.tsx
claude --file frontend/src/pages/InboundDetail.tsx
```

**Prompt:**
```
Complete this React detail page for [FEATURE]:

1. Get ID from URL: const { id } = useParams();

2. useEffect to fetch single record:
   const data = await fetch(`/api/index.php?module=[MODULE]&action=get&id=${id}`)

3. Display main record info in cards (KPI style)

4. Show related items in a table (e.g., wave orders, picklist items)

5. Add action buttons based on status:
   - If status === 'Planning' → show Release button
   - If status === 'Active' → show Complete/Cancel buttons
   - Etc.

6. Each button calls an API action (create, update, delete)

7. On success, reload data or navigate back

8. Show loading spinner and error messages

Use TypeScript. Match InboundDetail.tsx pattern.
```

**Do in parallel:** 4 pages × 1.5 hours ÷ 4 parallel = 1.5 hours

---

### 2.4: Task 3 - Create Modals (1.5 hours)

**Use Claude Code:**
```bash
claude --file frontend/src/components/CreateWaveModal.tsx
claude --file frontend/src/components/CreatePicklistModal.tsx
claude --file frontend/src/components/CreateBinTransferModal.tsx
```

**Prompt:**
```
Create a form modal for creating [FEATURE]:

1. Accept props: isOpen (bool), onClose (func), onSuccess (func)

2. Form fields:
   - [List fields needed from API docs]
   - Use Input, Select, TextField components

3. Form submission:
   - Call API: await fetch('/api/index.php?module=[MODULE]&action=create', {
       method: 'POST',
       body: new FormData(form)
     })

4. On success: call onSuccess(), close modal

5. On error: show error message

6. Loading state on submit button

Use existing Modal and form components as templates.
Validate required fields.
```

**Do in parallel:** 3 modals × 1.5 hours ÷ 3 parallel = 1.5 hours

---

### 2.5: Task 4 - Wire Up APIs (1 hour)

**Use Claude Code to update api.ts:**

```bash
claude --file frontend/src/lib/api.ts
```

**Prompt:**
```
Update the API client to add missing methods:

Current pattern: export const [module] = { list, get, create, update, delete }

Add for these modules:
- waves
- picklist
- bintransfer
- replenishment
- cyclecount
- putawayTasks
- asn
- abc

Each should have:
- list() → GET /api/index.php?module=[module]&action=list
- get(id) → GET /api/index.php?module=[module]&action=get&id=[id]
- create(data) → POST with data
- update(id, data) → POST with id + data
- delete(id) → POST with id
- action(id, action) → for custom actions (release, execute, etc.)

Use fetch with error handling.
Return parsed JSON.
Throw on error.
```

**Effort:** 1 hour for all API methods

---

## 📊 Phase 3: Manual Integration & Testing (1-2 hours)

### 3.1: Hook Components Together
```bash
# For each list page, update to use the detail page:
# Replace: <a href={...}>View</a>
# With:    <Link to={`/waves/${wave.id}`}>View</Link>

# For each modal, import in list page:
# import { CreateWaveModal } from '@/components/CreateWaveModal'
# Add: const [showModal, setShowModal] = useState(false)
```

### 3.2: Test Critical Workflows

**Test in browser at http://localhost:5173:**

```
✅ Workflow 1: Create Wave
1. Go to /waves
2. Click "New Wave"
3. Select orders
4. Submit
5. Wave should appear in list

✅ Workflow 2: Release Wave
1. Click on a wave (go to detail)
2. Click "Release"
3. Status should change to "Active"

✅ Workflow 3: Complete Picklist
1. Go to /picklist
2. Click on a picklist
3. Mark items as picked
4. Click "Complete"
5. Status changes to "Completed"
```

### 3.3: Fix Any Bugs

As you test, if anything breaks:
```bash
# Use Claude Code to fix
claude --file [broken_file].tsx

# Show Claude the error and ask to fix
```

---

## 🎯 Parallel Execution Strategy

To get this done TODAY, run multiple Claude Code instances:

```bash
# Terminal 1: Vite dev server
cd frontend && npm run dev

# Terminal 2: List pages (Claude Code)
claude --file frontend/src/pages/WavesPage.tsx
# Follow prompts, save when done
# Then open next page: PicklistList.tsx

# Terminal 3: Detail pages (Claude Code)
claude --file frontend/src/pages/WaveDetail.tsx
# Etc.

# Terminal 4: Modals (Claude Code)
claude --file frontend/src/components/CreateWaveModal.tsx
# Etc.

# Terminal 5: Manual testing & integration
# Open browser, test workflows, fix bugs
```

Or use **Claude Code IDE** (if you have it):
- Open 4-5 files side-by-side
- Use Claude in each editor
- All working in parallel

---

## ⏱️ Timeline (Same-Day)

```
00:00 - 00:30  Phase 1: Setup (migrations, Vite, verify backend)
00:30 - 02:30  Phase 2.2: List pages (4 pages × parallel)
02:30 - 04:00  Phase 2.3: Detail pages (4 pages × parallel)
04:00 - 05:30  Phase 2.4: Modals (3 modals × parallel)
05:30 - 06:30  Phase 2.5: APIs (wire everything)
06:30 - 08:00  Phase 3: Integration & testing
─────────────────────────────────────
08:00 hours total (6-8 hours wall-clock with parallelization)
```

**If you start at 8am → Done by 4pm**  
**If you start at 9am → Done by 5pm**

---

## 💻 How to Use Claude Code Effectively

### Best Settings for This Task:

```bash
# Use Opus for quality (best reasoning for complex code)
claude --model opus-5

# Or use Fast mode (Opus but optimized)
claude --fast

# For maximum speed with acceptable quality:
claude --model sonnet-5

# Enable streaming output (see progress immediately)
claude --stream
```

### Prompting Strategy:

**DO:**
- ✅ Give exact file paths
- ✅ Show example from existing code
- ✅ Ask for complete, working code
- ✅ Specify TypeScript types
- ✅ Ask to match existing patterns

**DON'T:**
- ❌ Ask vague questions
- ❌ Ask for refactoring after coding
- ❌ Mix multiple features in one prompt
- ❌ Skip type definitions

---

## 🔧 Claude Code Commands Reference

```bash
# Create new file
claude create frontend/src/pages/NewPage.tsx

# Edit existing file
claude edit frontend/src/pages/WavesPage.tsx --focus "list rendering"

# View file
claude view frontend/src/pages/WavesPage.tsx

# Run tests
claude test frontend/src/pages/WavesPage.tsx

# Check for errors
claude lint frontend/src/pages/WavesPage.tsx

# Generate from template
claude generate frontend/src/pages/WavesPage.tsx --template list-page
```

---

## ✅ Definition of "Done Today"

By end of day, you should have:

- [x] All list pages showing data
- [x] All detail pages working
- [x] All create modals functional
- [x] All API methods wired
- [x] Critical workflows tested:
  - [x] Create wave → Release → Complete
  - [x] Create transfer → Execute
  - [x] Create picklist → Pick → Complete
- [x] Zero broken links
- [x] Zero console errors (on happy path)

---

## 🚨 If You Get Stuck

### Problem: "Component not rendering data"
**Solution:** 
```bash
claude --file [component].tsx
# Show Claude the error
# Ask: "Fix the data rendering. API returns {data structure}. 
#       Component should show in a table with columns..."
```

### Problem: "API calls failing"
**Solution:**
```bash
# Check what API returns
curl http://localhost/k-one/api/index.php?module=waves&action=list

# Show Claude the response
# Ask: "Update the component to handle this API response"
```

### Problem: "TypeScript errors"
**Solution:**
```bash
claude --file [component].tsx
# Show the TS error
# Ask: "Fix TypeScript errors. Use proper types from the API"
```

### Problem: "Takes too long"
**Solution:**
- Open 2-3 Claude Code instances in parallel
- Each works on different files simultaneously
- Reduces total time by 50-70%

---

## 🎯 Success Checklist

At end of day, verify:

- [ ] Vite running at http://localhost:5173
- [ ] Can log in as admin/admin123
- [ ] Dashboard loads
- [ ] All 20 menu items visible
- [ ] Click Waves → shows list of waves ✅
- [ ] Click "New Wave" → modal opens ✅
- [ ] Can create a new wave ✅
- [ ] Click wave in list → goes to detail page ✅
- [ ] Click "Release" → wave status changes ✅
- [ ] Same tests pass for: Picklist, Bin Transfer, Replenishment, etc.
- [ ] No 404 errors
- [ ] No console JavaScript errors
- [ ] API calls working (check Network tab)
- [ ] Forms submit successfully

---

## 📝 Next Steps (Starting Now)

1. **Open this file** ← You are here
2. **Follow Phase 1** (30 min setup)
3. **Open Claude Code** with Opus model
4. **Follow Phase 2** (5-6 hours of coding)
5. **Follow Phase 3** (1-2 hours testing)
6. **Done!** 🎉

---

## 🎓 Estimated Effort per Task

| Task | Complexity | Claude Effort | Human Effort | Parallel |
|------|-----------|---------------|--------------|----------|
| List pages (4) | Low | 30m × 4 | 10m review | ✅ Yes |
| Detail pages (4) | Medium | 45m × 4 | 15m review | ✅ Yes |
| Modals (3) | Low | 30m × 3 | 10m review | ✅ Yes |
| API methods | Low | 60m | 20m review | ✅ Maybe |
| Integration | Medium | 0m | 60m manual | ❌ No |
| Testing | Medium | 0m | 60m manual | ❌ No |
| **TOTAL** | **Medium** | **~6-7 hours** | **~2-3 hours** | **~4 hours parallel** |

**With parallelization: 6-8 hours total (start 8am, done by 4pm)**

---

## 🚀 Let's Go!

**You've got this. Let me know when you're ready to start, or if you hit any blockers!**


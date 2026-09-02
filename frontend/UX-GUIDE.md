# K-one Frontend UX Guide

A living reference for every component pattern, color decision, and layout rule in the K-one frontend. When in doubt, check here first.

---

## 1. Component Usage

All shared components live in `frontend/src/components/`. Import from the file's named exports:

```tsx
import { Card, EmptyState } from '@/components/Card';
import { Field, TextInput, Select, Grid } from '@/components/Field';
import PageHeader from '@/components/PageHeader';
```

### PageHeader

```tsx
import PageHeader from '@/components/PageHeader';

<PageHeader
  title="Inbound"
  subtitle="Manage inbound receipts"
  actions={<WebBtn href={url} label="Print" />}
/>
```

**When to use:** Top of every page. The gradient banner (`from-brand-900 via-brand-600 to-brand-400`) gives the page a branded header. Always include `title`; add `subtitle` for context. Place action buttons (print, export, create new) in `actions`.

### Card and EmptyState

```tsx
import { Card, EmptyState } from '@/components/Card';

<Card title="Stock Overview" actions={<button>Add Item</button>}>
  {items.length > 0 ? (
    <table>...</table>
  ) : (
    <EmptyState message="No stock records found" />
  )}
</Card>
```

**When to use:** Every content section wraps in a `Card`. Use the optional `title` prop for section headers. Pair with `EmptyState` when data might be empty. The card has `rounded-xl`, `shadow-sm`, and a `brand-50/50` header tint.

### Spinner

```tsx
import Spinner from '@/components/Spinner';

<Spinner label="Loading stock data..." />
```

**When to use:** Full-page or section-level loading. Centered with brand-600 spinning ring. Add a `label` to tell the user what's happening. For smaller inline loaders, see the Loading States section.

### StatusBadge

```tsx
import StatusBadge from '@/components/StatusBadge';

<StatusBadge status="Receiving" />
```

**When to use:** Anywhere a status value appears in a table row, card, or detail view. The component handles 30+ statuses with automatic color coding. Pass the exact status string; it falls back to gray if unknown.

### Field, TextInput, Select, TextArea, Grid

```tsx
import { Field, TextInput, Select, TextArea, Grid } from '@/components/Field';

<Grid cols={2}>
  <Field label="Product" required>
    <TextInput value={name} onChange={...} />
  </Field>
  <Field label="Status">
    <Select value={status} onChange={...}>
      <option>Draft</option>
      <option>Active</option>
    </Select>
  </Field>
  <Field label="Notes" hint="Optional notes for this record" className="col-span-2">
    <TextArea rows={3} value={notes} onChange={...} />
  </Field>
</Grid>
```

**When to use:** Every form in the app. `Field` wraps a label with optional `required` asterisk and `hint` text. `Grid` handles responsive columns: single column on mobile, 2/3/4 columns on `md:` breakpoint. Always use `Field` for labels, never raw `<label>`.

### ScanInput

```tsx
import ScanInput from '@/components/ScanInput';

<ScanInput
  onScan={(code) => handleScan(code)}
  placeholder="Scan barcode..."
  disabled={isProcessing}
/>
```

**When to use:** Barcode/QR scanning workflows. Autofocuses on mount, clears after each scan, refocuses for rapid sequential reads. The USB/Bluetooth scanner acts as a keyboard, so the Enter key triggers `onScan`. Disabled state prevents double-scans while processing.

### Modal

```tsx
import Modal from '@/components/Modal';

<Modal open={showModal} onClose={() => setShowModal(false)} title="Edit Product" size="md">
  <form>...</form>
</Modal>
```

**When to use:** Any overlay dialog. Sizes: `sm` (max-w-md), `md` (max-w-2xl, default), `lg` (max-w-4xl), `xl` (max-w-6xl). Closes on Escape key. Uses `fixed inset-0` with `z-[100]` and `backdrop-blur-[2px]`. Place form actions at the bottom of the modal body.

### Pagination

```tsx
import Pagination from '@/components/Pagination';

<Pagination page={currentPage} totalPages={totalPages} total={totalCount} onChange={setPage} />
```

**When to use:** Below any paginated table. Shows windowed page buttons with ellipsis for large page counts. The current page gets `bg-brand-600 text-white`. Pass `total` to show a record count. Auto-hides when `totalPages <= 1`.

### ConfirmButton

```tsx
import ConfirmButton from '@/components/ConfirmButton';

<ConfirmButton
  label="Delete"
  confirmText="Delete this record permanently?"
  onConfirm={() => handleDelete(id)}
/>
```

**When to use:** Destructive actions (delete, cancel, reset). First click shows "Konfirmasi" / "Batal" confirmation pair. Second click executes. Two variants: `danger` (red, default) for destructive actions, `ghost` (gray) for softer cancellations. Always include a meaningful `confirmText`.

### WebBtn

```tsx
import { WebBtn } from '@/components/WebBtn';

<WebBtn href={printUrl} label="Print Receipt" />
<WebBtn href={exportUrl} label="Export Excel" tone="brand" />
<WebBtn href={actionUrl} label="Confirm Shipment" tone="green" />
```

**When to use:** Links that open binary endpoints (print, Excel export) in a new tab. Three tones: `dark` (white text on dark, for PageHeader actions), `brand` (teal, for inline actions), `green` (emerald, for confirmations). Default icon is a printer; override with `icon` prop.

### Toast (useToast)

```tsx
import { useToast } from '@/components/Toast';

const toast = useToast();
toast('success', 'Inbound received successfully');
toast('error', 'Failed to save record');
toast('info', 'Processing batch import...');
```

**When to use:** Feedback after user actions. Auto-dismisses after 4.5 seconds. Position: fixed bottom-right with `z-[100]`. Three kinds: `success` (emerald), `error` (red), `info` (sky). Must be used inside a `ToastProvider` (already wrapped in the app root).

### LocationBadge

```tsx
import LocationBadge from '@/components/LocationBadge';

<LocationBadge locationCode="CD01A02" format="short" />
<LocationBadge locationCode="CD01A02" format="full" showLevel />
<LocationBadge locationCode="CD01A02" format="detailed" showIcon />
```

**When to use:** Displaying warehouse location codes. Parses the code into rack/level/position components. Formats: `short` (rack + level badge), `full` (adds position), `detailed` (stacked layout with level name). Color-coded level badge (bottom/mid/top tiers). Invalid codes render as plain mono text in gray.

### LoadingBar

The app-level `LoadingBar` (in `Layout.tsx`) shows a thin brand-500 pulse bar at the top of the viewport during route transitions. No manual usage needed; it fires on every `location.pathname` change.

---

## 2. Color Semantics

### Brand Palette

The brand palette is defined in `tailwind.config.js` under `colors.brand`. Use these tokens everywhere, never hardcode hex values.

| Token | Hex | Usage |
|-------|-----|-------|
| `brand-50` | `#e6f7f7` | Card header backgrounds, badge fills, subtle tints |
| `brand-100` | `#b2e5e5` | Badge borders, hover backgrounds |
| `brand-200` | `#80d2d1` | Spinner ring (inactive), input focus ring |
| `brand-300` | `#4dbfbe` | Spinner ring (active), gradient midpoint |
| `brand-400` | `#26b1af` | Gradient accent |
| `brand-500` | `#026766` | Primary actions, focus borders, active states, LoadingBar |
| `brand-600` | `#025958` | Hover states for primary buttons, active page button |
| `brand-700` | `#014f4e` | Card title text, LocationBadge rack text, sidebar accents |
| `brand-800` | `#013d3c` | Modal title, dark gradients |
| `brand-900` | `#012d2c` | PageHeader gradient start, sidebar background (`#0d1f1f` is similar) |

### When to use each shade

- **brand-50 through brand-100:** Backgrounds and borders for subtle emphasis. Card headers, badge fills, form field focus rings.
- **brand-200 through brand-400:** Gradient midpoints and decorative elements. The PageHeader gradient flows from brand-900 to brand-400.
- **brand-500:** The primary action color. Use for focus borders, active pagination buttons, links, and the LoadingBar.
- **brand-600:** Hover state for brand-500 elements. Slightly darker to show interaction.
- **brand-700 through brand-900:** Text on light backgrounds (brand-700), modal headers (brand-800), and gradient start points (brand-900).

### Text Colors

- **brand-900** (`text-brand-900`): Body text in form inputs
- **brand-700** (`text-brand-700`): Card titles, emphasis text
- **brand-600** (`text-brand-600`): Active states, links
- **gray-600** (`text-gray-600`): Secondary text, labels, hints
- **gray-400** (`text-gray-400`): Disabled text, EmptyState text, separators
- **gray-700** (`text-gray-700`): StatusBadge default text

### Semantic Colors

| Color family | Meaning | Tailwind classes |
|-------------|---------|-----------------|
| Emerald/Green | Success, good, completed | `bg-emerald-50 text-emerald-700 border-emerald-300` |
| Amber/Yellow | Warning, in-progress, pending | `bg-amber-50 text-amber-700 border-amber-300` |
| Red | Error, danger, rejected | `bg-red-50 text-red-700 border-red-300` |
| Sky/Blue | Info, open, planning | `bg-sky-50 text-sky-700 border-sky-300` |
| Gray | Neutral, draft, clear | `bg-gray-100 text-gray-700 border-gray-300` |

---

## 3. Table Patterns

Every data table follows the same structure:

```tsx
<Card title="Stock Overview" actions={<Pagination ... />}>
  <div className="overflow-x-auto">
    <table className="w-full text-sm">
      <thead>
        <tr className="border-b border-gray-200 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">
          <th className="pb-2 px-3">Product</th>
          <th className="pb-2 px-3">Location</th>
          <th className="pb-2 px-3">Qty</th>
          <th className="pb-2 px-3">Status</th>
        </tr>
      </thead>
      <tbody className="divide-y divide-gray-100">
        {items.map((item) => (
          <tr key={item.id} className="hover:bg-brand-50/30 transition-colors">
            <td className="py-2.5 px-3 font-medium">{item.product_name}</td>
            <td className="py-2.5 px-3">
              <LocationBadge locationCode={item.location} format="short" />
            </td>
            <td className="py-2.5 px-3 text-right">{item.qty}</td>
            <td className="py-2.5 px-3">
              <StatusBadge status={item.status} />
            </td>
          </tr>
        ))}
      </tbody>
    </table>
  </div>
  {items.length === 0 && <EmptyState message="No records found" />}
</Card>
```

### Table conventions

- **Header:** `text-xs font-semibold text-gray-500 uppercase tracking-wide` with `border-b border-gray-200`
- **Rows:** `hover:bg-brand-50/30` for hover feedback, `divide-y divide-gray-100` for row separation
- **Cells:** `py-2.5 px-3` padding, `text-sm` body size
- **Alignment:** Text left by default, numbers right (`text-right`)
- **Responsive:** Always wrap in `overflow-x-auto` for mobile scroll
- **Empty state:** Show `EmptyState` when the array is empty, not a spinner
- **Pagination:** Place `Pagination` in the Card `actions` slot or below the table

---

## 4. Form Patterns

### Standard form layout

```tsx
<Card title="New Inbound">
  <form onSubmit={handleSubmit}>
    <Grid cols={2}>
      <Field label="Supplier" required>
        <Select value={supplier} onChange={...}>
          <option value="">Select supplier</option>
          {suppliers.map(s => <option key={s.id} value={s.id}>{s.name}</option>)}
        </Select>
      </Field>
      <Field label="Expected Date">
        <TextInput type="date" value={date} onChange={...} />
      </Field>
      <Field label="Reference No" hint="OD or SO number">
        <TextInput value={ref} onChange={...} placeholder="OD-12345" />
      </Field>
      <Field label="Notes">
        <TextArea rows={2} value={notes} onChange={...} />
      </Field>
    </Grid>
    <div className="flex justify-end gap-2 mt-4">
      <button type="button" onClick={onCancel} className="px-4 py-2 text-sm rounded-lg border border-gray-300 hover:bg-gray-50">
        Cancel
      </button>
      <button type="submit" className="px-4 py-2 text-sm font-semibold rounded-lg bg-brand-600 text-white hover:bg-brand-700">
        Save
      </button>
    </div>
  </form>
</Card>
```

### Form conventions

- **Grid:** Always wrap fields in `<Grid cols={2}>` (or 3/4 for wider forms). This stacks to single-column on mobile automatically.
- **Labels:** Always use `Field` with a `label` prop. Mark required fields with `required` prop (renders red asterisk).
- **Hints:** Add `hint` to `Field` for helper text below the input.
- **Inputs:** Use `TextInput` for text/number/date, `Select` for dropdowns, `TextArea` for multi-line. All share the same base style: `border-[1.5px] border-gray-300 rounded-lg text-sm` with `focus:border-brand-500 focus:ring-[3px] focus:ring-brand-500/15`.
- **Actions:** Place Cancel (ghost/outline) and Save (brand-600 solid) at the bottom right.
- **Barcode scanning:** Use `ScanInput` instead of `TextInput` when the field accepts scanned codes.

### Validation display

Show inline errors below the field using the `hint` slot or a red text div. Keep error messages short and specific.

---

## 5. Loading States

The app uses a three-tier loading hierarchy. Pick the right one based on what's loading.

### Tier 1: Route transition (automatic)

The `LoadingBar` component fires on every route change. A thin brand-500 bar pulses at the top of the viewport for 300ms. No manual usage needed.

### Tier 2: Full-page or section data loading (Spinner)

```tsx
{loading ? (
  <Spinner label="Loading stock records..." />
) : (
  <Card>...</Card>
)}
```

Use `Spinner` when the page or a large section is fetching data from the API. Centered with vertical padding (`py-16`). Always include a `label` describing what's loading.

### Tier 3: No data available (EmptyState)

```tsx
{items.length === 0 ? (
  <EmptyState message="No inbound records found" />
) : (
  <table>...</table>
)}
```

Use `EmptyState` when the fetch completed but returned zero results. Never show a spinner for empty data; it implies the system is still working when it's actually done.

### Planned: Skeleton loading

Skeleton placeholder components are planned for inline row-level loading (e.g., table rows appearing one by one). Not yet implemented. When built, use skeletons for list/table content that loads progressively, and spinners for full-section waits.

---

## 6. Responsive Breakpoints

The Layout component defines the responsive behavior. All breakpoints use Tailwind's `md:` (768px) as the primary threshold.

### Layout structure

```
+--sidebar (w-64)----+--main content-----------+
|                     |  header (h-14)          |
|  fixed left         |  <Outlet />             |
|  bg-[#0d1f1f]       |  overflow-y-auto        |
|                     |  p-4 md:p-6             |
+---------------------+-------------------------+
```

### Sidebar

- **Desktop (md+):** Fixed `w-64` sidebar, always visible. `md:relative md:transform-none` keeps it in the document flow.
- **Mobile (<md):** Hidden off-screen (`-translate-x-full`). Toggled via hamburger menu button in the header. When open, a `fixed inset-0 bg-black/50` overlay covers the content. Tapping the overlay or pressing the X button closes it.

### Header

- **Height:** Fixed `h-14` (56px).
- **Mobile:** Shows hamburger button (`md:hidden`), page title, and logout button.
- **Desktop (sm+):** Adds the user info pill (`hidden sm:flex`) with name, role badge, and department badge.

### Content area

- **Mobile:** `p-4` (16px padding)
- **Desktop (md+):** `p-6` (24px padding)
- **Scrolling:** `overflow-y-auto` on the main container

### Grid component breakpoints

`Grid` uses `grid-cols-1` by default (mobile) and switches at `md:`:

| `cols` prop | Mobile | Desktop (md+) |
|-------------|--------|---------------|
| `2` (default) | 1 column | 2 columns |
| `3` | 1 column | 3 columns |
| `4` | 1 column | 4 columns |

### Key mobile patterns

- Sidebar becomes overlay with backdrop
- Hamburger button in header (`w-9 h-9 rounded-lg bg-brand-50 text-brand-600`)
- Tables scroll horizontally (`overflow-x-auto`)
- Forms stack to single column
- Card padding reduces from 24px to 16px
- User info pill hides, only avatar remains

---

## 7. Status Color System

StatusBadge handles 30+ statuses with automatic color coding. Here's the full mapping organized by domain.

### Inbound statuses

| Status | Color | Meaning |
|--------|-------|---------|
| Draft | Gray | Not yet submitted |
| Dues In | Blue | Expected, not arrived |
| Receiving | Orange | Being processed at dock |
| Good Received | Green | Checked in, quality pass |
| Goods Received | Green | Alias for Good Received |
| Unserviceable | Red | Failed quality check |
| Picked | Indigo | Items picked for putaway |
| ATP | Emerald | Available to promise |
| Completed | Emerald | Fully processed |
| Cancelled | Red | Voided |

### Outbound statuses

| Status | Color | Meaning |
|--------|-------|---------|
| Open | Sky | New order, not yet picked |
| Picking | Amber | Actively being picked |
| Shipped | Violet | Left the warehouse |
| Delivered | Teal | Confirmed delivery |

### Picklist statuses

| Status | Color | Meaning |
|--------|-------|---------|
| Confirmed | Cyan | Picker confirmed completion |

### Wave statuses

| Status | Color | Meaning |
|--------|-------|---------|
| Planning | Sky | Wave being built |
| Active | Amber | Wave released for picking |

### Stock statuses

| Status | Color | Meaning |
|--------|-------|---------|
| Available | Emerald | Good stock, can be allocated |
| Reserved | Indigo | Allocated to an order |
| on_hold | Amber | Restricted, cannot move |
| quarantine | Orange | Under inspection |
| damaged | Red | Write-off pending |
| Expired | Red | Past expiry date |

### ABC velocity

| Grade | Color | Meaning |
|-------|-------|---------|
| A | Red | High movers, top priority |
| B | Amber | Medium movers |
| C | Gray | Low movers |

### Stock take statuses

| Status | Color | Meaning |
|--------|-------|---------|
| In Progress | Amber | Counting active |
| Counting | Amber | Specific counter working |
| Review | Purple | Awaiting manager review |
| Adjusted | Emerald | Variance posted |

### Stock ledger movement types

| Status | Color | Meaning |
|--------|-------|---------|
| Plus | Emerald | Stock increase |
| Minus | Red | Stock decrease |
| Clear | Gray | Neutral/adjustment |

### Adding new statuses

To add a status, edit `STATUS_STYLES` in `frontend/src/components/StatusBadge.tsx`. Follow the pattern:

```tsx
'Your Status': 'bg-{color}-50 text-{color}-700 border-{color}-300',
```

Use the same color families documented in the semantic colors table above. Keep the pattern consistent: light background, medium text, matching border.

---

## Quick Reference

### Import cheat sheet

```tsx
// Layout & navigation
import Layout from '@/components/Layout';

// Page structure
import PageHeader from '@/components/PageHeader';
import { Card, EmptyState } from '@/components/Card';

// Forms
import { Field, TextInput, Select, TextArea, Grid } from '@/components/Field';
import ScanInput from '@/components/ScanInput';

// Status & location
import StatusBadge from '@/components/StatusBadge';
import LocationBadge from '@/components/LocationBadge';

// Feedback
import Spinner from '@/components/Spinner';
import { useToast } from '@/components/Toast';

// Modals & pagination
import Modal from '@/components/Modal';
import Pagination from '@/components/Pagination';

// Actions
import ConfirmButton from '@/components/ConfirmButton';
import { WebBtn } from '@/components/WebBtn';
```

### Color tokens to remember

- Primary actions: `bg-brand-600 text-white hover:bg-brand-700`
- Subtle backgrounds: `bg-brand-50`
- Focus states: `focus:border-brand-500 focus:ring-[3px] focus:ring-brand-500/15`
- Success: `bg-emerald-50 text-emerald-700`
- Warning: `bg-amber-50 text-amber-700`
- Error/danger: `bg-red-50 text-red-700`
- Info: `bg-sky-50 text-sky-700`

# CLAUDE.md — K-one Warehouse Management System

> **Project**: K-one (Shell CKB Warehouse Management)  
> **Stack**: PHP 8.2+, MySQL/MariaDB, React 18, TypeScript, Vite, Tailwind CSS, PhpSpreadsheet, PHPMailer, Three.js  
> **Entry Point**: `dashboard.php` (redirects from `index.php`)

---

## Architecture Overview

```
K-one/
├── api/                    # REST API endpoints
│   ├── index.php           # API router
│   ├── registry.php        # Route registry
│   ├── handlers/           # API handlers (waves, users, etc.)
│   └── helpers.php         # API utilities
├── classes/                # Core business logic (service layer)
│   ├── Auth.php            # Authentication & RBAC
│   ├── Inbound.php         # Inbound receiving + putaway
│   ├── Outbound.php        # Outbound shipping (FEFO)
│   ├── Stock.php           # Stock management
│   ├── Picklist.php        # Picklist management
│   ├── BinTransfer.php     # Bin-to-bin transfers
│   ├── StockTake.php       # Cycle counting / stock opname
│   ├── LocationManager.php # Warehouse location master
│   ├── Product.php         # Product master data
│   ├── Supplier.php        # Supplier master
│   ├── Customer.php        # Customer master
│   ├── Wave.php            # Wave picking orchestration
│   ├── PickingService.php  # Pick execution logic
│   ├── FefoAllocator.php   # FEFO allocation engine
│   ├── Replenishment.php   # Replenishment logic
│   ├── CycleCount.php      # Cycle counting
│   ├── AbcAnalysis.php     # ABC analysis
│   ├── Report.php          # Report generation
│   ├── ExcelExport.php     # PhpSpreadsheet exports
│   ├── ActivityLogger.php  # Audit trail
│   ├── PalletHelper.php    # Pallet ↔ UOM conversion
│   ├── DispatchService.php # Dispatch orchestration
│   ├── StagingService.php  # Staging area management
│   ├── ConsolidationService.php
│   ├── TaskAssignmentService.php
│   ├── OrderService.php
│   ├── Asn.php             # Advanced Shipping Notice
│   ├── DiscrepancyService.php
│   ├── GiExportService.php # Goods Issue export
│   └── LabelPrinter.php    # Label printing
├── config/
│   └── database.php        # DB, SMTP, app constants
├── migrations/             # Incremental SQL schema (revision_001.sql → hotfix_023)
├── seeders/                # Seed data
├── frontend/               # Vite + TypeScript + React + Tailwind
│   ├── src/
│   ├── vite.config.ts
│   ├── tailwind.config.js
│   └── tsconfig.json
├── *.php                   # Page controllers (dashboard.php, inbound.php, etc.)
├── header.php / footer.php # Shared layout
├── composer.json
└── database.sql            # Full schema dump
```

---

## Key Domain Concepts

| Concept | Description |
|---------|-------------|
| **Inbound** | Receiving goods with multi-status, multi-OD/SO, putaway to locations |
| **Outbound** | Shipping with FEFO (First Expired First Out) allocation |
| **Picklist** | Picking work assignments per outbound order |
| **Wave** | Batch of orders released for picking together |
| **Bin Transfer** | Stock movement between locations/bins |
| **Stock Take** | Cycle counting with per-product counters |
| **Location Master** | Aisle → Rack → Level → Bin hierarchy |
| **Stock Ledger** | Immutable audit trail of all stock movements |

---

## Authentication & Roles

```php
// Auth.php - check role
Auth::requireRole('admin');   // Full access + user mgmt + reset
Auth::requireRole('operator'); // Create/edit orders, view reports
Auth::requireRole('viewer');  // Read-only
```

Default login: `admin` / `admin123`

---

## Database

- **Config**: `config/database.php` (PDO singleton via `classes/database.php`)
- **Migrations**: Run sequentially: `revision_001.sql` → `hotfix_023` → `seed_locations.sql`
- **Naming**: `snake_case` tables, `id` primary keys, `created_at`/`updated_at` timestamps

---

## Frontend (Vite + TypeScript + React + Tailwind)

```bash
cd frontend
npm install
npm run dev      # Dev server
npm run build    # Production build
npm test         # Vitest unit tests
```

- Entry: `frontend/src/main.tsx`
- Tailwind: `frontend/tailwind.config.js`
- Router: React Router v6
- 3D: Three.js / React Three Fiber
- Icons: Lucide React
- Barcodes: JsBarcode, QRCode.react
- Components: React + TypeScript + Alpine.js (legacy pages in `header.php`/`footer.php`)

---

## Backend Libraries (Composer)

| Package | Purpose |
|---------|---------|
| PhpSpreadsheet | Excel import/export |
| PHPMailer | Email notifications |

---

## API Endpoints

| Endpoint | Handler | Purpose |
|----------|---------|---------|
| `/api/inbound` | `inbound_api.php` | Inbound CRUD + receive |
| `/api/outbound` | `outbound_api.php` | Outbound CRUD + ship |
| `/api/stocktake` | `stocktake_api.php` | Cycle count submit |
| `/api/locations` | `api_locations.php` | Location lookup |
| `/api/waves` | `api/handlers/waves.php` | Wave management |
| `/api/users` | `api/handlers/users.php` | User management |

---

## Common Patterns

### Service Layer Usage
```php
// Instantiate service
$inbound = new Inbound($pdo);

// Call business method
$result = $inbound->receive($inboundId, $receivedItems, $userId);
```

### Error Handling
Custom exceptions in `classes/`:
- `InvalidLpnException`
- `InsufficientStockException`
- `DispatchReferenceConflictException`
- `PickReferenceConflictException`

### Activity Logging
```php
ActivityLogger::log($pdo, $userId, 'inbound_receive', $inboundId, $details);
```

### Excel Export
```php
$export = new ExcelExport();
$export->exportInbound($inboundId)->download('inbound.xlsx');
```

---

## Development Workflow

1. **Schema changes**: Add migration in `migrations/` with next sequence number
2. **New feature**: Create service in `classes/` → Add page controller → Wire in `header.php` nav
3. **API**: Add handler in `api/handlers/` → Register in `api/registry.php`
4. **Frontend**: Edit `frontend/src/` → `npm run build` → assets auto-included via `footer.php`

---

## Testing

```bash
# PHPUnit (backend)
./vendor/bin/phpunit

# Vitest (frontend)
cd frontend && npm test
```

| Layer | Framework |
|-------|-----------|
| Backend | PHPUnit |
| Frontend | Vitest + Testing Library + jsdom |

---

## Deployment Notes

- **Web root**: Point to `K-one/` directory
- **PHP**: 8.2+ with PDO MySQL, GD, Zip, OpenSSL
- **MySQL**: 5.7+ / MariaDB 10.3+
- **Permissions**: `storage/` writable (logs, exports, uploads)
- **Cron**: Scheduled jobs for replenishment, wave auto-release, report emails

---

## Key Files to Understand First

1. `config/database.php` — All configuration
2. `classes/Auth.php` — Auth & RBAC
3. `classes/Inbound.php` / `Outbound.php` — Core flows
4. `classes/FefoAllocator.php` — Allocation logic
5. `classes/Wave.php` + `classes/PickingService.php` — Wave picking
6. `api/index.php` — API routing
7. `frontend/src/main.tsx` — Frontend entry

---

## AI Agent Guidelines

- **Follow existing patterns**: Service classes in `classes/`, page controllers at root
- **Database**: Use PDO via `classes/database.php` singleton; never raw `new PDO()`
- **Errors**: Throw typed exceptions; catch at controller level
- **Security**: All queries via prepared statements; validate all inputs
- **Frontend**: Minimal JS — prefer server-rendered HTML + Alpine.js for interactivity
- **Migrations**: Never edit existing migrations; add new sequential files
- **Roles**: Check `Auth::requireRole()` at top of every controller/API handler
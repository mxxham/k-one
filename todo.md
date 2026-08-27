# K-one Port to-Do

PHP -> TypeScript port tracker. Stack: Node/Express + mysql2 (server), React + Vite + Tailwind (frontend).

## Done

### Server (`server/`)
- [x] Express entry point + token auth middleware (`src/index.ts`)
- [x] DB helper (`src/db.ts`), request-context helpers (`src/helpers.ts`)
- [x] Activity log helper (`src/activityLog.ts`), auth tokens helper (`src/authTokens.ts`)
- [x] Services (all 12):
  - [x] `services/Inbound.ts`
  - [x] `services/Outbound.ts`
  - [x] `services/Stock.ts`
  - [x] `services/Picklist.ts`
  - [x] `services/BinTransfer.ts`
  - [x] `services/StockTake.ts`
  - [x] `services/LocationManager.ts`
  - [x] `services/Product.ts`
  - [x] `services/Customer.ts`
  - [x] `services/Report.ts`
  - [x] `services/PalletHelper.ts`
- [x] Handlers (all modules):
  - [x] `handlers/auth.ts` (login/logout/me)
  - [x] `handlers/inbound.ts`
  - [x] `handlers/outbound.ts`
  - [x] `handlers/stock.ts`
  - [x] `handlers/picklist.ts`
  - [x] `handlers/bintransfer.ts`
  - [x] `handlers/stocktake.ts`
  - [x] `handlers/master.ts` (products/customers/locations/users)
  - [x] `handlers/dashboard.ts`
  - [x] `handlers/ledger.ts`
  - [x] `handlers/reports.ts` (report + activitylog)
  - [x] `handlers/system.ts`
  - [x] `handlers/import.ts` + import helpers (see "Done (this session)")
- [x] TypeScript compiles clean (`npm run lint` = `tsc --noEmit` passes)

### Frontend (`frontend/`)
- [x] Login + auth context
- [x] All pages: Dashboard, Inbound (list/detail), Outbound (list/detail), Picklist (list/detail), Stock, Ledger, StockTake (list/detail), Locations, BinTransfer, Products, Customers, Users, Reports, Import, AutoImport, ResetData, ActivityLog
- [x] Shared components (Layout, Card, Modal, Field, Pagination, Toast, StatusBadge, etc.)
- [x] API client (`lib/api.ts`), format helpers
- [x] Production build passes (`npm run build`)

### Deployment / Support
- [x] Dockerfile + docker-compose.yml + docker/ scripts
- [x] `start-dev.ps1`
- [x] `.htaccess`, `.env`, `.dockerignore`

## Done (this session)
- [x] Wire ALL handlers into `handlers/index.ts` (was only `inbound`; now 16 modules: auth, bintransfer, dashboard, inbound, import, ledger, products, customers, locations, users, outbound, picklist, report, activitylog, stock, stocktake, system)
- [x] Port `import` module (was missing entirely):
  - [x] `handlers/import.ts` dispatcher: `tpl_inbound`, `tpl_outbound`, `tpl_stock`, `inbound`, `outbound`, `stock_preview`, `stock_commit`, `auto`
  - [x] `importHelpers.ts` — Excel/csv parsing via `exceljs`, date/UOM/header helpers
  - [x] `handlers/importStock.ts` — stock parse/validate/commit (add|replace|skip)
  - [x] `handlers/importInbound.ts` — inbound Excel/csv import
  - [x] `handlers/importOutbound.ts` — outbound sheet processor + FEFO allocation
  - [x] `handlers/importAuto.ts` — multi-sheet auto import
  - [x] `handlers/importTemplates.ts` — xlsx template downloads (GET, binary)
- [x] multer file upload support in `index.ts` (memory storage, 50MB limit)
- [x] `helpers.ts`: `uploadedFile()` + `sendBuffer()` for binary template responses
- [x] `db.ts`: `inTx()` + `withTransactionOwned()` (PHP `$ownsTransaction` pattern)
- [x] Smoke-tested against live DB on port 4000: login, stock template download (valid xlsx), stock_preview, stock_commit (auto-create product + dedupe), inbound import (grouping by shipment); all pass. Test data cleaned up.

## Remaining
- [ ] End-to-end browser test: server + frontend against seeded DB (Vite dev + `tsx watch`)
- [ ] Commit port work

## Reference
- Original PHP source: `api/` (new handlers), root `*.php` (legacy pages)
- Frontend module/action names found via `api('<module>','<action>')` calls in `frontend/src/pages/*`

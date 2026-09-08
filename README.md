# K-one

A web-based warehouse management system for Shell CKB warehouse operations, built with PHP, React, TypeScript, and Tailwind CSS.

## Tech Stack

| Category | Technology |
|----------|------------|
| **Backend** | PHP 8.2+, MySQL/MariaDB |
| **Frontend** | React 18, TypeScript, Vite |
| **Styling** | Tailwind CSS |
| **Libraries** | PhpSpreadsheet ^5.5, PHPMailer ^7.0, Three.js |
| **Testing** | PHPUnit ^11.0, Vitest |
| **3D Visualization** | React Three Fiber |

---

## Key Features

| Module | Description |
|--------|-------------|
| **Dashboard** | Visual warehouse operations summary + interactive aisle map |
| **Inbound** | Goods receiving with multi-status, multi-OD/SO, putaway to locations |
| **Outbound** | Shipping with FEFO (First Expired First Out) logic |
| **Wave Picking** | Wave-based batch picking orchestration |
| **Picklist** | Picking work assignments per outbound order |
| **Bin Transfer** | Stock movement between locations/bins within warehouse |
| **Stock** | Real-time stock view per batch/location with expiry monitoring |
| **Stock Ledger** | Complete transaction history (audit trail) |
| **Stock Take** | Cycle counting with per-product counters |
| **Location Master** | Warehouse location/rack management |
| **Reports** | Operational reports + Excel export |
| **Activity Log** | System-wide user activity log |
| **Master Data** | Products, Suppliers, Customers, Locations |

### Additional Features

- Excel export from all modules (Inbound, Outbound, Stock, Ledger, Reports, etc.)
- Bulk data import via Excel (Inbound, Outbound, Stock)
- Print Inbound Receipt, Outbound DO, Picklist, Delivery Order, Putaway Sheet
- REST API endpoints for external system integration (`inbound_api.php`, `outbound_api.php`, `stocktake_api.php`, `api_locations.php`)

---

## Quick Start

### Prerequisites

- PHP 8.2+ with PDO MySQL, GD, Zip, OpenSSL extensions
- MySQL 5.7+ or MariaDB 10.3+
- Node.js 18+ (for frontend development)
- Composer

### Installation

```bash
# Clone the repository
git clone https://github.com/mxxham/k-one.git
cd k-one

# Install PHP dependencies
composer install

# Install frontend dependencies
cd frontend
npm install

# Import database schema
mysql -u root -p k_one < database.sql

# Run database migrations (in order)
mysql -u root -p k_one < migrations/revision_001.sql
# ... continue with subsequent migrations
```

### Running the Application

```bash
# Start frontend dev server
cd frontend
npm run dev

# Build for production
npm run build

# Access in browser
# http://localhost/k-one
```

### Default Login

- **Username:** `admin`
- **Password:** `admin123`

> **Security:** Change the default password immediately after first login. Do not use these credentials in production.

---

## Database Migrations

The `migrations/` folder contains incremental SQL for schema updates:

```
migrations/
├── revision_001.sql          # Initial schema
├── revision_002.sql
├── revision_003.sql
├── revision_004_location_pallet.sql
├── hotfix_001 – hotfix_023   # Operational data fixes
└── seed_locations.sql        # Warehouse location seeds
```

Run sequentially in order.

---

## Project Structure

```
k-one/
├── api/                        # REST API endpoints
│   ├── index.php               # API router
│   ├── registry.php            # Route registry
│   └── handlers/               # API handlers (waves, users, etc.)
├── classes/                    # Core business logic (service layer)
│   ├── Auth.php                # Authentication & RBAC
│   ├── Inbound.php             # Inbound receiving + putaway
│   ├── Outbound.php            # Outbound shipping (FEFO)
│   ├── Stock.php               # Stock management
│   ├── Picklist.php            # Picklist management
│   ├── Wave.php                # Wave picking orchestration
│   ├── PickingService.php      # Pick execution logic
│   ├── FefoAllocator.php       # FEFO allocation engine
│   ├── BinTransfer.php         # Bin-to-bin transfers
│   ├── StockTake.php           # Cycle counting / stock opname
│   ├── CycleCount.php          # Cycle counting logic
│   ├── AbcAnalysis.php         # ABC analysis
│   ├── Replenishment.php       # Replenishment logic
│   ├── LocationManager.php     # Warehouse location master
│   ├── Product.php             # Product master data
│   ├── Supplier.php            # Supplier master
│   ├── Customer.php            # Customer master
│   ├── DispatchService.php     # Dispatch orchestration
│   ├── StagingService.php      # Staging area management
│   ├── ConsolidationService.php # Order consolidation
│   ├── TaskAssignmentService.php # Task assignment logic
│   ├── OrderService.php        # Order management
│   ├── Asn.php                 # Advanced Shipping Notice
│   ├── DiscrepancyService.php  # Discrepancy handling
│   ├── GiExportService.php     # Goods Issue export
│   ├── ExcelExport.php         # PhpSpreadsheet exports
│   ├── ActivityLogger.php      # Audit trail
│   ├── PalletHelper.php        # Pallet ↔ UOM conversion
│   ├── LabelPrinter.php        # Label printing
│   ├── Report.php              # Report generation
│   ├── database.php            # PDO singleton helper
│   ├── header.php              # Navigation & layout header
│   └── footer.php              # Scripts & layout footer
├── config/
│   └── database.php            # DB, SMTP, app constants
├── frontend/                   # Vite + TypeScript + React + Tailwind
│   ├── src/
│   ├── vite.config.ts
│   ├── tailwind.config.js
│   └── tsconfig.json
├── migrations/                 # Incremental SQL schema
├── seeders/                    # Sample data / seeds
├── dashboard.php               # Main dashboard
├── inbound.php                 # Inbound management
├── outbound.php                # Outbound management
├── picklist.php                # Picklist
├── bin_transfer.php            # Bin transfer
├── stock.php                   # Stock view
├── ledger.php                  # Stock ledger
├── stocktake.php               # Stock take
├── location_master.php         # Location master
├── reports.php                 # Reports
├── products.php                # Product master
├── suppliers.php               # Supplier master
├── customers.php               # Customer master
├── users.php                   # User management
├── activity_log.php            # Activity log
├── import_inbound.php          # Inbound import via Excel
├── import_outbound.php         # Outbound import via Excel
├── import_stock.php            # Stock import via Excel
├── print_inbound.php           # Print inbound receipt
├── print_outbound.php          # Print delivery order
├── print_picklist.php          # Print picklist
├── putaway_sheet.php           # Print putaway sheet
├── surat_jalan.php             # Delivery order
├── inbound_api.php             # API endpoint inbound
├── outbound_api.php            # API endpoint outbound
├── stocktake_api.php           # API endpoint stock take
├── api_locations.php           # API endpoint locations
├── reset_operational_data.php  # Reset operational data (admin only)
├── login.php / logout.php
├── index.php                   # Entry point (redirects to dashboard)
├── database.sql                # Full database schema
└── composer.json
```

---

## User Roles

| Role | Access |
|------|--------|
| `admin` | Full access: all features + user management + data reset |
| `operator` | Create & edit all operational orders, view all reports |
| `viewer` | Read-only: can only view data |

---

## Development

### Frontend

```bash
cd frontend
npm install          # Install dependencies
npm run dev          # Start dev server
npm run build        # Production build
npm test             # Run Vitest tests
```

### Backend

```bash
composer install     # Install PHP dependencies
./vendor/bin/phpunit # Run PHPUnit tests
```

---

**Version:** 2.0.0  
**Built for:** Shell CKB  
**Stack:** PHP 8.2+, React 18, TypeScript, Vite, MySQL/MariaDB, Tailwind CSS, PhpSpreadsheet, PHPMailer, Three.js

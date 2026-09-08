<div align="center">

# K-one

### Warehouse Management System for Shell CKB

A modern, web-based warehouse management system for Shell CKB built with PHP 8.2+, React 18, TypeScript, and Tailwind CSS.

[![PHP](https://img.shields.io/badge/PHP-8.2%2B-777BB4?style=for-the-badge&logo=php&logoColor=white)](https://php.net)
[![React](https://img.shields.io/badge/React-18-61DAFB?style=for-the-badge&logo=react&logoColor=black)](https://react.dev)
[![TypeScript](https://img.shields.io/badge/TypeScript-5.x-3178C6?style=for-the-badge&logo=typescript&logoColor=white)](https://typescriptlang.org)
[![Vite](https://img.shields.io/badge/Vite-5-646CFF?style=for-the-badge&logo=vite&logoColor=white)](https://vitejs.dev)
[![Tailwind CSS](https://img.shields.io/badge/Tailwind_CSS-3-06B6D4?style=for-the-badge&logo=tailwindcss&logoColor=white)](https://tailwindcss.com)
[![MySQL](https://img.shields.io/badge/MySQL-5.7%2B-4479A1?style=for-the-badge&logo=mysql&logoColor=white)](https://mysql.com)
[![Three.js](https://img.shields.io/badge/Three.js-R152-000000?style=for-the-badge&logo=three.js&logoColor=white)](https://threejs.org)

<br>

[![PHPUnit](https://img.shields.io/badge/PHPUnit-11-3DA343?style=flat-square&logo=phpunit&logoColor=white)](#testing)
[![Vitest](https://img.shields.io/badge/Vitest-1-729B1B?style=flat-square&logo=vitest&logoColor=white)](#testing)
[![MIT License](https://img.shields.io/badge/License-MIT-blue?style=flat-square)](#license)

</div>

---

## Overview

K-one is a comprehensive warehouse management system designed for **Shell CKB** operations. It handles the full warehouse lifecycle — from inbound receiving and putaway to outbound shipping with FEFO allocation, cycle counting, and real-time stock monitoring with 3D visualization.

---

## Features

<table>
<tr>
<td width="50%" valign="top">

### Core Modules

| Module | Description |
|--------|-------------|
| **Dashboard** | Visual operations summary + interactive aisle map |
| **Inbound** | Multi-status receiving, putaway to locations |
| **Outbound** | FEFO-based shipping allocation |
| **Wave Picking** | Batch picking orchestration |
| **Picklist** | Per-order picking assignments |
| **Bin Transfer** | Inter-location stock movement |

</td>
<td width="50%" valign="top">

### Stock & Reporting

| Module | Description |
|--------|-------------|
| **Stock** | Real-time view per batch/location |
| **Stock Ledger** | Full transaction audit trail |
| **Stock Take** | Cycle counting per product |
| **Location Master** | Warehouse rack management |
| **Reports** | Operational reports + Excel export |
| **Activity Log** | System-wide audit log |

</td>
</tr>
</table>

### Additional Features

<details>
<summary><strong>Excel Import & Export</strong></summary>

- Export Excel from all modules (Inbound, Outbound, Stock, Ledger, Reports)
- Bulk data import via Excel for Inbound, Outbound, and Stock modules
- Powered by PhpSpreadsheet ^5.5

</details>

<details>
<summary><strong>Document Printing</strong></summary>

- Inbound Receipt
- Outbound Delivery Order
- Picklist
- Delivery Order (Surat Jalan)
- Putaway Sheet
- Label Printing via LabelPrinter service

</details>

<details>
<summary><strong>REST API Integration</strong></summary>

| Endpoint | Handler | Purpose |
|----------|---------|---------|
| `/api/inbound` | `inbound_api.php` | Inbound CRUD + receive |
| `/api/outbound` | `outbound_api.php` | Outbound CRUD + ship |
| `/api/stocktake` | `stocktake_api.php` | Cycle count submit |
| `/api/locations` | `api_locations.php` | Location lookup |

</details>

---

## Tech Stack

<table>
<tr>
<td width="50%" valign="top">

#### Backend
- **PHP 8.2+** with strict typing
- **MySQL 5.7+ / MariaDB 10.3+**
- **PDO** singleton pattern
- Prepared statements (SQL injection safe)

#### Frontend
- **React 18** with TypeScript
- **Vite 5** for blazing fast builds
- **Tailwind CSS 3** for styling
- **React Three Fiber** for 3D visualization

</td>
<td width="50%" valign="top">

#### Libraries
- **PhpSpreadsheet ^5.5** — Excel import/export
- **PHPMailer ^7.0** — Email notifications
- **Three.js R152** — 3D warehouse visualization
- **JsBarcode** — Barcode generation
- **QRCode.react** — QR code generation
- **Lucide React** — Icon system

#### Testing
- **PHPUnit 11** — Backend tests
- **Vitest** — Frontend unit tests
- **Testing Library** — Component testing

</td>
</tr>
</table>

---

## Quick Start

### Prerequisites

| Requirement | Version |
|-------------|---------|
| PHP | 8.2+ (PDO MySQL, GD, Zip, OpenSSL) |
| MySQL / MariaDB | 5.7+ / 10.3+ |
| Node.js | 18+ |
| Composer | Latest |

### Installation

```bash
# 1. Clone the repository
git clone https://github.com/mxxham/k-one.git
cd k-one

# 2. Install PHP dependencies
composer install

# 3. Install frontend dependencies
cd frontend
npm install
cd ..

# 4. Import database schema
mysql -u root -p k_one < database.sql

# 5. Run database migrations (in order)
mysql -u root -p k_one < migrations/revision_001.sql
# Continue with subsequent revisions and hotfixes
```

### Running

```bash
# Frontend dev server (hot reload)
cd frontend
npm run dev

# Production build
npm run build
```

Then open `http://localhost/k-one` in your browser.

### Default Credentials

> [!WARNING]
> **Change the default password immediately after first login.**
> Do not use these credentials in production.

| Username | Password |
|----------|----------|
| `admin` | `admin123` |

---

## User Roles

<table>
<tr>
<th>Role</th>
<th>Access Level</th>
<th>Capabilities</th>
</tr>
<tr>
<td><code>admin</code></td>
<td>Full</td>
<td>All features + user management + data reset</td>
</tr>
<tr>
<td><code>operator</code></td>
<td>Standard</td>
<td>Create/edit orders, view all reports</td>
</tr>
<tr>
<td><code>viewer</code></td>
<td>Read-only</td>
<td>View data only</td>
</tr>
</table>

---

## Architecture

<details>
<summary><strong>Database Migrations</strong></summary>

The `migrations/` folder contains incremental SQL for schema updates:

```
migrations/
├── revision_001.sql              # Initial schema
├── revision_002.sql
├── revision_003.sql
├── revision_004_location_pallet.sql
├── hotfix_001 – hotfix_023       # Operational data fixes
└── seed_locations.sql            # Warehouse location seeds
```

> [!NOTE]
> Run migrations sequentially in order. Never edit existing migrations — add new sequential files.

</details>

<details>
<summary><strong>Project Structure</strong></summary>

```
k-one/
├── api/                        # REST API endpoints
│   ├── index.php               # API router
│   ├── registry.php            # Route registry
│   └── handlers/               # API handlers
├── classes/                    # Core business logic (service layer)
│   ├── Auth.php                # Authentication & RBAC
│   ├── Inbound.php             # Inbound receiving + putaway
│   ├── Outbound.php            # Outbound shipping (FEFO)
│   ├── Stock.php               # Stock management
│   ├── Wave.php                # Wave picking orchestration
│   ├── PickingService.php      # Pick execution logic
│   ├── FefoAllocator.php       # FEFO allocation engine
│   ├── BinTransfer.php         # Bin-to-bin transfers
│   ├── StockTake.php           # Cycle counting
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
│   ├── TaskAssignmentService.php # Task assignment
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
│   ├── header.php              # Navigation & layout
│   └── footer.php              # Scripts & layout
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
├── inbound_api.php             # API endpoint inbound
├── outbound_api.php            # API endpoint outbound
├── stocktake_api.php           # API endpoint stock take
├── api_locations.php           # API endpoint locations
├── database.sql                # Full database schema
└── composer.json
```

</details>

<details>
<summary><strong>Service Layer Architecture</strong></summary>

The application follows a service-layer architecture:

```
Page Controllers (*.php) → Service Classes (classes/) → Database (PDO singleton)
```

| Layer | Responsibility |
|-------|----------------|
| **Page Controllers** | Handle HTTP requests, auth checks, call services |
| **Service Classes** | Business logic, validation, data manipulation |
| **Database Layer** | PDO singleton, prepared statements, query execution |
| **Activity Logger** | Audit trail for all operations |

</details>

---

## Development

### Frontend

```bash
cd frontend
npm install          # Install dependencies
npm run dev          # Start dev server (hot reload)
npm run build        # Production build
npm test             # Run Vitest unit tests
```

### Backend

```bash
composer install                    # Install PHP dependencies
./vendor/bin/phpunit                # Run PHPUnit tests
./vendor/bin/phpunit --coverage     # Run with coverage report
```

### Database

```bash
# Import full schema
mysql -u root -p k_one < database.sql

# Run incremental migrations
mysql -u root -p k_one < migrations/revision_001.sql
```

> [!TIP]
> Always backup your database before running migrations in production.

---

## License

This project is licensed under the **MIT License**.

---

<div align="center">

**Version:** 2.0.0 · **Built for:** Shell CKB · **By:** [mxxham](https://github.com/mxxham)

</div>

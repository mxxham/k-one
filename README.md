# K-one 

Sistem manajemen gudang berbasis web untuk operasi gudang Shell CKB, dibangun dengan PHP, MySQL, dan Tailwind CSS.

## Fitur Utama

| Modul | Keterangan |
|---|---|
| **Dashboard** | Ringkasan visual operasional gudang + peta aisle interaktif |
| **Inbound** | Penerimaan barang dengan multi-status, multi-OD/SO, putaway ke lokasi |
| **Outbound** | Pengiriman dengan logika FEFO (First Expired First Out) |
| **Picklist** | Manajemen picking per outbound order |
| **Bin Transfer** | Perpindahan stok antar lokasi/bin di dalam gudang |
| **Stock** | Tampilan stok real-time per batch/lokasi dengan monitoring expiry |
| **Stock Ledger** | Riwayat transaksi lengkap (audit trail) |
| **Stock Take** | Opname stok dengan counter per produk |
| **Location Master** | Manajemen lokasi/rak gudang |
| **Reports** | Laporan operasional + export ke Excel |
| **Activity Log** | Log aktivitas user seluruh sistem |
| **Master Data** | Produk, Supplier, Customer, Locations |

### Fitur Pendukung

- Export Excel dari semua modul (Inbound, Outbound, Stock, Ledger, Reports, dll.)
- Import data massal via Excel (Inbound, Outbound, Stock)
- Print Inbound Receipt, Outbound DO, Picklist, Surat Jalan, Putaway Sheet
- API endpoint untuk integrasi sistem eksternal (`inbound_api.php`, `outbound_api.php`, `stocktake_api.php`, `api_locations.php`)

### Akses Web

Buka browser → `http://localhost/k-one`

Login default:
- **Username:** `admin`
- **Password:** `admin123`
---

## Migrasi & Hotfix Database

Folder `migrations/` berisi SQL incremental untuk update schema:

```
migrations/
├── revision_001.sql       # Schema awal
├── revision_002.sql
├── revision_003.sql
├── revision_004_location_pallet.sql
├── hotfix_001 – hotfix_023  # Perbaikan data operasional
└── seed_locations.sql     # Seed lokasi gudang
```

Jalankan secara berurutan sesuai nomor.


---

## Struktur File

```
sanchaya/
├── config/
│   └── database.php          # Konfigurasi DB, SMTP, konstanta app
├── classes/
│   ├── Auth.php              # Autentikasi & role guard
│   ├── Inbound.php           # Logika inbound (termasuk putaway)
│   ├── Outbound.php          # Logika outbound (FEFO)
│   ├── Stock.php             # Manajemen stok
│   ├── Picklist.php          # Picklist management
│   ├── BinTransfer.php       # Bin transfer
│   ├── StockTake.php         # Stock opname
│   ├── LocationManager.php   # Master lokasi
│   ├── ExcelExport.php       # Export Excel (PhpSpreadsheet)
│   ├── ActivityLogger.php    # Log aktivitas
│   ├── PalletHelper.php      # Konversi pallet ↔ UOM
│   ├── Product.php           # Master produk
│   ├── Supplier.php          # Master supplier
│   ├── Customer.php          # Master customer
│   ├── Report.php            # Laporan & email
│   ├── database.php          # PDO singleton helper
│   ├── header.php            # Navigasi & layout header
│   └── footer.php            # Script & layout footer
├── includes/                 # Shared includes
├── assets/
│   └── logo.png
├── migrations/               # SQL schema incremental
├── seeders/                  # Data contoh / seed
│   └── README.md
├── dashboard.php             # Dashboard utama
├── inbound.php               # Manajemen inbound
├── outbound.php              # Manajemen outbound
├── picklist.php              # Picklist
├── bin_transfer.php          # Bin transfer
├── stock.php                 # Tampilan stok
├── ledger.php                # Stock ledger
├── stocktake.php             # Stock take
├── location_master.php       # Master lokasi
├── reports.php               # Laporan
├── products.php              # Master produk
├── products_report.php       # Laporan produk
├── suppliers.php             # Master supplier
├── customers.php             # Master customer
├── users.php                 # Manajemen user
├── activity_log.php          # Log aktivitas
├── import_inbound.php        # Import inbound via Excel
├── import_outbound.php       # Import outbound via Excel
├── import_stock.php          # Import stok via Excel
├── print_inbound.php         # Print receipt inbound
├── print_outbound.php        # Print delivery order
├── print_picklist.php        # Print picklist
├── putaway_sheet.php         # Print putaway sheet
├── surat_jalan.php           # Surat jalan
├── inbound_api.php           # API endpoint inbound
├── outbound_api.php          # API endpoint outbound
├── stocktake_api.php         # API endpoint stock take
├── api_locations.php         # API endpoint lokasi
├── reset_operational_data.php # Reset data operasional (admin only)
├── login.php / logout.php
├── index.php                 # Entry point (redirect ke dashboard)
├── database.sql              # Schema database lengkap
└── composer.json
```

---

## Role Pengguna

| Role | Akses |
|---|---|
| `admin` | Full access: semua fitur + user management + reset data |
| `operator` | Buat & edit semua order operasional, lihat semua laporan |
| `viewer` | Read-only: hanya bisa melihat data |

---

**Versi:** 1.0.0  
**Dikembangkan untuk:** Shell CKB  
**Stack:** PHP 7.4+, MySQL/MariaDB, Tailwind CSS, PhpSpreadsheet, PHPMailer

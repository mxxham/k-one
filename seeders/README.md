# TrianaWMS V2 - Database Seeders

Seeders untuk mengisi data dummy/testing ke database TrianaWMS V2.

## 📁 Daftar Seeder

| Seeder | Deskripsi | Jumlah Data |
|--------|-----------|-------------|
| `seed_users.php` | User admin, warehouse, supervisor, operator | 4 users |
| `seed_suppliers.php` | Data supplier (Shell Indonesia, PT ABC, CV XYZ) | 3 suppliers |
| `seed_customers.php` | Data customer (PT Petro, CV Energy, dll) | 4 customers |
| `seed_locations.php` | Lokasi gudang (A-Z zones) | 85 locations |
| `seed_products.php` | Produk Shell CKB (Drum, Carton, Pail) | 20 products |
| `seed_stock.php` | Data stock dengan batch & expiry dates | ~50+ batches |
| `seed_inbound.php` | Inbound orders (Received, Pending) | 3 orders |
| `seed_outbound.php` | Outbound orders (Shipped, Open) | 4 orders |
| `seed_stock_take.php` | Stock take records (Completed, In Progress) | 3 stock takes |
| `seed_picklists.php` | Picklist untuk outbound | 3 picklists |
| `seed_location_allocations.php` | Alokasi lokasi untuk stock & inbound | ~25 allocations |
| `seed_email_logs.php` | Log email laporan | 6 logs |

## 🚀 Cara Penggunaan

### Option 1: Jalankan Semua Seeder (Recommended)

Buka terminal/command prompt dan jalankan:

```bash
cd d:\xampp\htdocs\trianawms
php seeders\seed_all.php
```

Ini akan menjalankan semua seeder dalam urutan yang benar untuk menjaga foreign key constraints.

### Option 2: Jalankan Seeder Individual

Jika hanya ingin menjalankan seeder tertentu:

```bash
# Users
php seeders\seed_users.php

# Products
php seeders\seed_products.php

# Stock (harus run setelah products & locations)
php seeders\seed_stock.php

# etc.
```

## ⚠️ Urutan Penting

Seeders **HARUS** dijalankan dalam urutan ini karena foreign key constraints:

1. `seed_users.php` - Tidak ada dependencies
2. `seed_suppliers.php` - Tidak ada dependencies
3. `seed_customers.php` - Tidak ada dependencies
4. `seed_locations.php` - Tidak ada dependencies
5. `seed_products.php` - Tidak ada dependencies
6. `seed_stock.php` - **Butuh:** products, locations
7. `seed_inbound.php` - **Butuh:** suppliers, products, locations
8. `seed_outbound.php` - **Butuh:** customers, products
9. `seed_stock_take.php` - **Butuh:** stock
10. `seed_picklists.php` - **Butuh:** outbound_orders, outbound_items
11. `seed_location_allocations.php` - **Butuh:** stock, inbound_items
12. `seed_email_logs.php` - Tidak ada dependencies

## 👤 Default Login Credentials

Setelah menjalankan seeder, Anda bisa login dengan:

| Role | Username | Password |
|------|----------|----------|
| Admin | admin | admin123 |
| Warehouse Manager | warehouse | warehouse123 |
| Supervisor | supervisor | supervisor123 |
| Operator | operator | operator123 |

## 📊 Data yang Dibuat

### Produk (20 items)
- **Drum (8 items)**: 4 drums/pallet
  - Rimula R4, Rimula R6, Gadus S2, Toneway O, Mysella S2, Omala O, Corena O, Dorna AR
- **Carton (7 items)**: 36/44/48 cartons/pallet
  - Helix 10W, Helix 5W, Advance 4T, Advance AX7, Rimula C1, Helix HX7, Gadus S3
- **Pail (5 items)**: 24 pails/pallet
  - Gadus 18KG, Gadus 50KG, Omala P68, Corena P32, Mysella P

### Lokasi (85 locations)
- **Zone A (10)**: Dues In area (h-1)
- **Zone B (20)**: Main storage
- **Zone C (20)**: Main storage
- **Zone D (15)**: Cool storage
- **Zone E (10)**: Quarantine
- **Zone R (5)**: Receiving area
- **Zone S (5)**: Shipping area

### Stock
- 2-3 batch per produk
- Expiry date: Production date + 4 years
- Variasi expiry: 1-36 months ago (untuk testing FEFO)
- Status: Available, Critical (<120 hari), Expired

### Transaksi
- **Inbound Orders**: 3 orders (2 received, 1 pending)
- **Outbound Orders**: 4 orders (2 shipped, 2 open)
- **Stock Take**: 3 records (2 completed, 1 in progress)

## 🔧 Troubleshooting

### Error: Foreign key constraint fails

**Solusi:** Jalankan `seed_all.php` untuk memastikan urutan yang benar.

### Error: Table doesn't exist

**Solusi:** Pastikan database sudah di-import dengan SQL schema:
```bash
mysql -u root trianawms_v2 < trianawms_v2_complete.sql
```

### Error: Duplicate entry

**Solusi:** Seeder akan menghapus data yang sudah ada (`DELETE FROM table`) sebelum insert. Jika masih error, truncate manual:
```sql
TRUNCATE TABLE users;
TRUNCATE TABLE products;
-- dll.
```

## 📝 Notes

- Semua tanggal menggunakan format YYYY-MM-DD
- Production date + 4 years = Expiry date (berlaku untuk semua produk)
- Stock status 'Available' digunakan untuk stock yang bisa di-outbound
- Stock status 'Critical' untuk expiry ≤ 120 hari
- Stock status 'Dues In' untuk inbound yang masih pending
- Stock status 'Reserved' untuk stock yang sudah di-allocate ke order tapi belum ship

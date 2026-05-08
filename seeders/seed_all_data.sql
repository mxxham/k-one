SET FOREIGN_KEY_CHECKS = 0;

DELETE FROM users;

INSERT INTO users (username, password, full_name, email, role, is_active, created_at) VALUES
('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Administrator', 'admin@trianawms.com', 'admin', 1, NOW()),
('warehouse', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Warehouse Manager', 'warehouse@trianawms.com', 'warehouse', 1, NOW()),
('supervisor', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Warehouse Supervisor', 'supervisor@trianawms.com', 'supervisor', 1, NOW()),
('operator', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Warehouse Operator', 'operator@trianawms.com', 'operator', 1, NOW());

DELETE FROM customers;

INSERT INTO customers (customer_code, customer_name, contact_person, phone, email, address, created_at) VALUES
('CUST-001', 'PT Petro Indonesia', 'Dedi Kurniawan', '021-45678901', 'dedi@petro.co.id', 'Graha Petro, Jl. Rasuna Said Kav. 10, Jakarta 12950', NOW()),
('CUST-002', 'CV Energy Mandiri', 'Eko Prasetyo', '031-56789012', 'eko@energymandiri.com', 'Jl. Industri Raya No. 88, Surabaya 60111', NOW()),
('CUST-003', 'PT Global Fuel', 'Feri Susanto', '021-67890123', 'feri@globalfuel.co.id', 'Biz Park, Jl. Boulevard Raya, Jakarta 14240', NOW()),
('CUST-004', 'UD Jaya Abadi', 'Gunawan', '024-78901234', 'gunawan@jayaabadi.com', 'Jl. Semarang-Solo Km. 12, Semarang 50222', NOW());

DELETE FROM locations;

INSERT INTO locations (location_code, location_name, zone, aisle, position, level, location_type, capacity_pallets, status, created_at) VALUES
('A01', 'Aisle A - Position 1', 'A', 'A', 1, 'Ground', 'Storage', 4, 'Available', NOW()),
('A02', 'Aisle A - Position 2', 'A', 'A', 2, 'Ground', 'Storage', 4, 'Available', NOW()),
('A03', 'Aisle A - Position 3', 'A', 'A', 3, 'Ground', 'Storage', 4, 'Available', NOW()),
('A04', 'Aisle A - Position 4', 'A', 'A', 4, 'Ground', 'Storage', 4, 'Available', NOW()),
('A05', 'Aisle A - Position 5', 'A', 'A', 5, 'Ground', 'Storage', 4, 'Available', NOW()),
('A06', 'Aisle A - Position 6', 'A', 'A', 6, 'Ground', 'Storage', 4, 'Available', NOW()),
('A07', 'Aisle A - Position 7', 'A', 'A', 7, 'Ground', 'Storage', 4, 'Available', NOW()),
('A08', 'Aisle A - Position 8', 'A', 'A', 8, 'Ground', 'Storage', 4, 'Available', NOW()),
('A09', 'Aisle A - Position 9', 'A', 'A', 9, 'Ground', 'Storage', 4, 'Available', NOW()),
('A10', 'Aisle A - Position 10', 'A', 'A', 10, 'Ground', 'Storage', 4, 'Available', NOW());

INSERT INTO locations (location_code, location_name, zone, aisle, position, level, location_type, capacity_pallets, status, created_at) VALUES
('B01', 'Aisle B - Position 1', 'B', 'B', 1, 'Ground', 'Storage', 4, 'Available', NOW()),
('B02', 'Aisle B - Position 2', 'B', 'B', 2, 'Ground', 'Storage', 4, 'Available', NOW()),
('B03', 'Aisle B - Position 3', 'B', 'B', 3, 'Ground', 'Storage', 4, 'Available', NOW()),
('B04', 'Aisle B - Position 4', 'B', 'B', 4, 'Ground', 'Storage', 4, 'Available', NOW()),
('B05', 'Aisle B - Position 5', 'B', 'B', 5, 'Ground', 'Storage', 4, 'Available', NOW()),
('B06', 'Aisle B - Position 6', 'B', 'B', 6, 'Ground', 'Storage', 4, 'Available', NOW()),
('B07', 'Aisle B - Position 7', 'B', 'B', 7, 'Ground', 'Storage', 4, 'Available', NOW()),
('B08', 'Aisle B - Position 8', 'B', 'B', 8, 'Ground', 'Storage', 4, 'Available', NOW()),
('B09', 'Aisle B - Position 9', 'B', 'B', 9, 'Ground', 'Storage', 4, 'Available', NOW()),
('B10', 'Aisle B - Position 10', 'B', 'B', 10, 'Ground', 'Storage', 4, 'Available', NOW()),
('B11', 'Aisle B - Position 11', 'B', 'B', 11, 'Ground', 'Storage', 4, 'Available', NOW()),
('B12', 'Aisle B - Position 12', 'B', 'B', 12, 'Ground', 'Storage', 4, 'Available', NOW()),
('B13', 'Aisle B - Position 13', 'B', 'B', 13, 'Ground', 'Storage', 4, 'Available', NOW()),
('B14', 'Aisle B - Position 14', 'B', 'B', 14, 'Ground', 'Storage', 4, 'Available', NOW()),
('B15', 'Aisle B - Position 15', 'B', 'B', 15, 'Ground', 'Storage', 4, 'Available', NOW()),
('B16', 'Aisle B - Position 16', 'B', 'B', 16, 'Ground', 'Storage', 4, 'Available', NOW()),
('B17', 'Aisle B - Position 17', 'B', 'B', 17, 'Ground', 'Storage', 4, 'Available', NOW()),
('B18', 'Aisle B - Position 18', 'B', 'B', 18, 'Ground', 'Storage', 4, 'Available', NOW()),
('B19', 'Aisle B - Position 19', 'B', 'B', 19, 'Ground', 'Storage', 4, 'Available', NOW()),
('B20', 'Aisle B - Position 20', 'B', 'B', 20, 'Ground', 'Storage', 4, 'Available', NOW());

INSERT INTO locations (location_code, location_name, zone, aisle, position, level, location_type, capacity_pallets, status, created_at) VALUES
('C01', 'Aisle C - Position 1', 'C', 'C', 1, 'Ground', 'Storage', 4, 'Available', NOW()),
('C02', 'Aisle C - Position 2', 'C', 'C', 2, 'Ground', 'Storage', 4, 'Available', NOW()),
('C03', 'Aisle C - Position 3', 'C', 'C', 3, 'Ground', 'Storage', 4, 'Available', NOW()),
('C04', 'Aisle C - Position 4', 'C', 'C', 4, 'Ground', 'Storage', 4, 'Available', NOW()),
('C05', 'Aisle C - Position 5', 'C', 'C', 5, 'Ground', 'Storage', 4, 'Available', NOW()),
('C06', 'Aisle C - Position 6', 'C', 'C', 6, 'Ground', 'Storage', 4, 'Available', NOW()),
('C07', 'Aisle C - Position 7', 'C', 'C', 7, 'Ground', 'Storage', 4, 'Available', NOW()),
('C08', 'Aisle C - Position 8', 'C', 'C', 8, 'Ground', 'Storage', 4, 'Available', NOW()),
('C09', 'Aisle C - Position 9', 'C', 'C', 9, 'Ground', 'Storage', 4, 'Available', NOW()),
('C10', 'Aisle C - Position 10', 'C', 'C', 10, 'Ground', 'Storage', 4, 'Available', NOW()),
('C11', 'Aisle C - Position 11', 'C', 'C', 11, 'Ground', 'Storage', 4, 'Available', NOW()),
('C12', 'Aisle C - Position 12', 'C', 'C', 12, 'Ground', 'Storage', 4, 'Available', NOW()),
('C13', 'Aisle C - Position 13', 'C', 'C', 13, 'Ground', 'Storage', 4, 'Available', NOW()),
('C14', 'Aisle C - Position 14', 'C', 'C', 14, 'Ground', 'Storage', 4, 'Available', NOW()),
('C15', 'Aisle C - Position 15', 'C', 'C', 15, 'Ground', 'Storage', 4, 'Available', NOW()),
('C16', 'Aisle C - Position 16', 'C', 'C', 16, 'Ground', 'Storage', 4, 'Available', NOW()),
('C17', 'Aisle C - Position 17', 'C', 'C', 17, 'Ground', 'Storage', 4, 'Available', NOW()),
('C18', 'Aisle C - Position 18', 'C', 'C', 18, 'Ground', 'Storage', 4, 'Available', NOW()),
('C19', 'Aisle C - Position 19', 'C', 'C', 19, 'Ground', 'Storage', 4, 'Available', NOW()),
('C20', 'Aisle C - Position 20', 'C', 'C', 20, 'Ground', 'Storage', 4, 'Available', NOW());

INSERT INTO locations (location_code, location_name, zone, aisle, position, level, location_type, capacity_pallets, status, created_at) VALUES
('D01', 'Aisle D - Position 1', 'D', 'D', 1, 'Ground', 'Cool Storage', 3, 'Available', NOW()),
('D02', 'Aisle D - Position 2', 'D', 'D', 2, 'Ground', 'Cool Storage', 3, 'Available', NOW()),
('D03', 'Aisle D - Position 3', 'D', 'D', 3, 'Ground', 'Cool Storage', 3, 'Available', NOW()),
('D04', 'Aisle D - Position 4', 'D', 'D', 4, 'Ground', 'Cool Storage', 3, 'Available', NOW()),
('D05', 'Aisle D - Position 5', 'D', 'D', 5, 'Ground', 'Cool Storage', 3, 'Available', NOW()),
('D06', 'Aisle D - Position 6', 'D', 'D', 6, 'Ground', 'Cool Storage', 3, 'Available', NOW()),
('D07', 'Aisle D - Position 7', 'D', 'D', 7, 'Ground', 'Cool Storage', 3, 'Available', NOW()),
('D08', 'Aisle D - Position 8', 'D', 'D', 8, 'Ground', 'Cool Storage', 3, 'Available', NOW()),
('D09', 'Aisle D - Position 9', 'D', 'D', 9, 'Ground', 'Cool Storage', 3, 'Available', NOW()),
('D10', 'Aisle D - Position 10', 'D', 'D', 10, 'Ground', 'Cool Storage', 3, 'Available', NOW()),
('D11', 'Aisle D - Position 11', 'D', 'D', 11, 'Ground', 'Cool Storage', 3, 'Available', NOW()),
('D12', 'Aisle D - Position 12', 'D', 'D', 12, 'Ground', 'Cool Storage', 3, 'Available', NOW()),
('D13', 'Aisle D - Position 13', 'D', 'D', 13, 'Ground', 'Cool Storage', 3, 'Available', NOW()),
('D14', 'Aisle D - Position 14', 'D', 'D', 14, 'Ground', 'Cool Storage', 3, 'Available', NOW()),
('D15', 'Aisle D - Position 15', 'D', 'D', 15, 'Ground', 'Cool Storage', 3, 'Available', NOW());

INSERT INTO locations (location_code, location_name, zone, aisle, position, level, location_type, capacity_pallets, status, created_at) VALUES
('E01', 'Aisle E - Position 1', 'E', 'E', 1, 'Ground', 'Quarantine', 2, 'Available', NOW()),
('E02', 'Aisle E - Position 2', 'E', 'E', 2, 'Ground', 'Quarantine', 2, 'Available', NOW()),
('E03', 'Aisle E - Position 3', 'E', 'E', 3, 'Ground', 'Quarantine', 2, 'Available', NOW()),
('E04', 'Aisle E - Position 4', 'E', 'E', 4, 'Ground', 'Quarantine', 2, 'Available', NOW()),
('E05', 'Aisle E - Position 5', 'E', 'E', 5, 'Ground', 'Quarantine', 2, 'Available', NOW()),
('E06', 'Aisle E - Position 6', 'E', 'E', 6, 'Ground', 'Quarantine', 2, 'Available', NOW()),
('E07', 'Aisle E - Position 7', 'E', 'E', 7, 'Ground', 'Quarantine', 2, 'Available', NOW()),
('E08', 'Aisle E - Position 8', 'E', 'E', 8, 'Ground', 'Quarantine', 2, 'Available', NOW()),
('E09', 'Aisle E - Position 9', 'E', 'E', 9, 'Ground', 'Quarantine', 2, 'Available', NOW()),
('E10', 'Aisle E - Position 10', 'E', 'E', 10, 'Ground', 'Quarantine', 2, 'Available', NOW());

INSERT INTO locations (location_code, location_name, zone, aisle, position, level, location_type, capacity_pallets, status, created_at) VALUES
('R01', 'Receiving Area - Position 1', 'R', 'R', 1, 'Ground', 'Receiving', 10, 'Available', NOW()),
('R02', 'Receiving Area - Position 2', 'R', 'R', 2, 'Ground', 'Receiving', 10, 'Available', NOW()),
('R03', 'Receiving Area - Position 3', 'R', 'R', 3, 'Ground', 'Receiving', 10, 'Available', NOW()),
('R04', 'Receiving Area - Position 4', 'R', 'R', 4, 'Ground', 'Receiving', 10, 'Available', NOW()),
('R05', 'Receiving Area - Position 5', 'R', 'R', 5, 'Ground', 'Receiving', 10, 'Available', NOW());

INSERT INTO locations (location_code, location_name, zone, aisle, position, level, location_type, capacity_pallets, status, created_at) VALUES
('S01', 'Shipping Area - Position 1', 'S', 'S', 1, 'Ground', 'Shipping', 10, 'Available', NOW()),
('S02', 'Shipping Area - Position 2', 'S', 'S', 2, 'Ground', 'Shipping', 10, 'Available', NOW()),
('S03', 'Shipping Area - Position 3', 'S', 'S', 3, 'Ground', 'Shipping', 10, 'Available', NOW()),
('S04', 'Shipping Area - Position 4', 'S', 'S', 4, 'Ground', 'Shipping', 10, 'Available', NOW()),
('S05', 'Shipping Area - Position 5', 'S', 'S', 5, 'Ground', 'Shipping', 10, 'Available', NOW());

DELETE FROM products;

INSERT INTO products (product_code, product_name, category, uom_type, uom_per_pallet, description, reorder_level, max_per_transaction, created_at) VALUES

('RIMULA-R4', 'Shell Rimula R4 15W-40', 'Drum', 'Drum', 4, 'Heavy-duty diesel engine oil 209L', 20, 80, NOW()),
('RIMULA-R6', 'Shell Rimula R6 15W-40', 'Drum', 'Drum', 4, 'Synthetic technology diesel engine oil 209L', 16, 80, NOW()),
('GADUS-S2', 'Shell Gadus S2 V220AC', 'Drum', 'Drum', 4, 'Multipurpose extreme pressure grease 180KG', 12, 80, NOW()),
('TONEWAY-O', 'Shell Toneway S2 OGC 460', 'Drum', 'Drum', 4, 'Open gear lubricant 209L', 16, 80, NOW()),
('MYSella-S2', 'Shell Mysella S2 N40', 'Drum', 'Drum', 4, 'Gas engine oil 209L', 12, 80, NOW()),
('OMALA-O', 'Shell Omala S4 GX 460', 'Drum', 'Drum', 4, 'Synthetic industrial gear oil 209L', 16, 80, NOW()),
('CORENA-O', 'Shell Corena S4 R 68', 'Drum', 'Drum', 4, 'Air compressor oil 209L', 12, 80, NOW()),
('DORNA-AR', 'Shell Dorna AR 100', 'Drum', 'Drum', 4, 'Refrigeration compressor oil 209L', 12, 80, NOW()),

('HELIX-10W', 'Shell Helix HX8 10W-40', 'Carton', 'Carton', 36, 'Premium synthetic motor oil 1L x 12 bottles/carton', 72, 432, NOW()),
('HELIX-5W', 'Shell Helix Ultra 5W-40', 'Carton', 'Carton', 36, 'PurePlus synthetic motor oil 1L x 12 bottles/carton', 72, 432, NOW()),
('ADVANCE-4T', 'Shell Advance Ultra 4T 10W-40', 'Carton', 'Carton', 44, 'Synthetic motorcycle oil 1L x 12 bottles/carton', 88, 528, NOW()),
('ADVANCE-AX7', 'Shell Advance AX7 10W-40', 'Carton', 'Carton', 48, 'Semi-synthetic motorcycle oil 1L x 12 bottles/carton', 96, 576, NOW()),
('RIMULA-C1', 'Shell Rimula R4 15W-40 (Carton)', 'Carton', 'Carton', 36, 'Diesel engine oil 4L x 4/carton', 72, 432, NOW()),
('HELIX-HX7', 'Shell Helix HX7 10W-40', 'Carton', 'Carton', 44, 'Semi-synthetic motor oil 4L x 4/carton', 88, 528, NOW()),
('GADUS-S3', 'Shell Gadus S3 V220C 3KG', 'Carton', 'Carton', 48, 'Multipurpose grease 3KG x 8 pails/carton', 96, 576, NOW()),

('GADUS-P18', 'Shell Gadus S2 V220AC 18KG', 'Pail', 'Pail', 24, 'Multipurpose grease 18KG pail', 48, 288, NOW()),
('GADUS-P50', 'Shell Gadus S2 V220AC 50KG', 'Pail', 'Pail', 24, 'Multipurpose grease 50KG pail', 48, 288, NOW()),
('OMALA-P68', 'Shell Omala S2 GX 68 (Pail)', 'Pail', 'Pail', 24, 'Industrial gear oil 20L pail', 48, 288, NOW()),
('CORENA-P32', 'Shell Corena S3 R 32', 'Pail', 'Pail', 24, 'Air compressor oil 20L pail', 48, 288, NOW()),
('MYSella-P', 'Shell Mysella S2 N40 (Pail)', 'Pail', 'Pail', 24, 'Gas engine oil 20L pail', 48, 288, NOW());

SET FOREIGN_KEY_CHECKS = 1;

SELECT '✅ Basic seed data imported successfully!' AS Status;
SELECT 'Next: Import seed_stock.sql' AS NextStep;

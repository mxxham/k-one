SET FOREIGN_KEY_CHECKS = 0;

DELETE FROM users;

INSERT INTO users (username, password, full_name, email, role, is_active, created_at) VALUES
('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Administrator', 'admin@trianawms.com', 'admin', 1, NOW()),
('warehouse', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Warehouse Manager', 'warehouse@trianawms.com', 'warehouse', 1, NOW()),
('staff', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Warehouse Staff', 'staff@trianawms.com', 'staff', 1, NOW()),
('supervisor', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Warehouse Supervisor', 'supervisor@trianawms.com', 'warehouse', 1, NOW());

DELETE FROM customers;

INSERT INTO customers (customer_code, customer_name, contact_person, phone, email, address, city, is_active, created_at) VALUES
('CUST-001', 'PT Petro Indonesia', 'Dedi Kurniawan', '021-45678901', 'dedi@petro.co.id', 'Graha Petro, Jl. Rasuna Said Kav. 10, Jakarta 12950', 'Jakarta', 1, NOW()),
('CUST-002', 'CV Energy Mandiri', 'Eko Prasetyo', '031-56789012', 'eko@energymandiri.com', 'Jl. Industri Raya No. 88, Surabaya 60111', 'Surabaya', 1, NOW()),
('CUST-003', 'PT Global Fuel', 'Feri Susanto', '021-67890123', 'feri@globalfuel.co.id', 'Biz Park, Jl. Boulevard Raya, Jakarta 14240', 'Jakarta', 1, NOW()),
('CUST-004', 'UD Jaya Abadi', 'Gunawan', '024-78901234', 'gunawan@jayaabadi.com', 'Jl. Semarang-Solo Km. 12, Semarang 50222', 'Semarang', 1, NOW());

DELETE FROM products;

INSERT INTO products (product_code, product_name, category, uom_type, uom_per_pallet, description, max_sku_qty, max_trans_qty, reorder_level, is_active, created_at) VALUES

('RIMULA-R4', 'Shell Rimula R4 15W-40', 'Drum', 'Drum', 4, 'Heavy-duty diesel engine oil 209L', 44, 80, 20, 1, NOW()),
('RIMULA-R6', 'Shell Rimula R6 15W-40', 'Drum', 'Drum', 4, 'Synthetic technology diesel engine oil 209L', 44, 80, 16, 1, NOW()),
('GADUS-S2', 'Shell Gadus S2 V220AC', 'Drum', 'Drum', 4, 'Multipurpose extreme pressure grease 180KG', 44, 80, 12, 1, NOW()),
('TONEWAY-O', 'Shell Toneway S2 OGC 460', 'Drum', 'Drum', 4, 'Open gear lubricant 209L', 44, 80, 16, 1, NOW()),
('MYSella-S2', 'Shell Mysella S2 N40', 'Drum', 'Drum', 4, 'Gas engine oil 209L', 44, 80, 12, 1, NOW()),
('OMALA-O', 'Shell Omala S4 GX 460', 'Drum', 'Drum', 4, 'Synthetic industrial gear oil 209L', 44, 80, 16, 1, NOW()),
('CORENA-O', 'Shell Corena S4 R 68', 'Drum', 'Drum', 4, 'Air compressor oil 209L', 44, 80, 12, 1, NOW()),
('DORNA-AR', 'Shell Dorna AR 100', 'Drum', 'Drum', 4, 'Refrigeration compressor oil 209L', 44, 80, 12, 1, NOW()),

('HELIX-10W', 'Shell Helix HX8 10W-40', 'Carton', 'Carton', 36, 'Premium synthetic motor oil 1L x 12 bottles/carton', 44, 432, 72, 1, NOW()),
('HELIX-5W', 'Shell Helix Ultra 5W-40', 'Carton', 'Carton', 36, 'PurePlus synthetic motor oil 1L x 12 bottles/carton', 44, 432, 72, 1, NOW()),
('ADVANCE-4T', 'Shell Advance Ultra 4T 10W-40', 'Carton', 'Carton', 44, 'Synthetic motorcycle oil 1L x 12 bottles/carton', 44, 528, 88, 1, NOW()),
('ADVANCE-AX7', 'Shell Advance AX7 10W-40', 'Carton', 'Carton', 48, 'Semi-synthetic motorcycle oil 1L x 12 bottles/carton', 44, 576, 96, 1, NOW()),
('RIMULA-C1', 'Shell Rimula R4 15W-40 (Carton)', 'Carton', 'Carton', 36, 'Diesel engine oil 4L x 4/carton', 44, 432, 72, 1, NOW()),
('HELIX-HX7', 'Shell Helix HX7 10W-40', 'Carton', 'Carton', 44, 'Semi-synthetic motor oil 4L x 4/carton', 44, 528, 88, 1, NOW()),
('GADUS-S3', 'Shell Gadus S3 V220C 3KG', 'Carton', 'Carton', 48, 'Multipurpose grease 3KG x 8 pails/carton', 44, 576, 96, 1, NOW()),

('GADUS-P18', 'Shell Gadus S2 V220AC 18KG', 'Pail', 'Pail', 24, 'Multipurpose grease 18KG pail', 44, 288, 48, 1, NOW()),
('GADUS-P50', 'Shell Gadus S2 V220AC 50KG', 'Pail', 'Pail', 24, 'Multipurpose grease 50KG pail', 44, 288, 48, 1, NOW()),
('OMALA-P68', 'Shell Omala S2 GX 68 (Pail)', 'Pail', 'Pail', 24, 'Industrial gear oil 20L pail', 44, 288, 48, 1, NOW()),
('CORENA-P32', 'Shell Corena S3 R 32', 'Pail', 'Pail', 24, 'Air compressor oil 20L pail', 44, 288, 48, 1, NOW()),
('MYSella-P', 'Shell Mysella S2 N40 (Pail)', 'Pail', 'Pail', 24, 'Gas engine oil 20L pail', 44, 288, 48, 1, NOW());

DELETE FROM stock_ledger;
DELETE FROM stock;

INSERT INTO stock (product_id, batch_number, quantity, uom, pallet, manufacture_date, expiry_date, location, stock_status, created_at) VALUES

(1, 'RIMULA-R4-20230115', 20, 'Drum', 5.00, '2023-01-15', '2027-01-15', 'B01', 'Available', NOW()),
(1, 'RIMULA-R4-20230720', 16, 'Drum', 4.00, '2023-07-20', '2027-07-20', 'B02', 'Available', NOW()),
(2, 'RIMULA-R6-20230210', 12, 'Drum', 3.00, '2023-02-10', '2027-02-10', 'B03', 'Available', NOW()),
(2, 'RIMULA-R6-20230815', 20, 'Drum', 5.00, '2023-08-15', '2027-08-15', 'B04', 'Available', NOW()),
(3, 'GADUS-S2-20230301', 16, 'Drum', 4.00, '2023-03-01', '2027-03-01', 'B05', 'Available', NOW()),
(3, 'GADUS-S2-20230905', 12, 'Drum', 3.00, '2023-09-05', '2027-09-05', 'B06', 'Available', NOW()),
(4, 'TONEWAY-O-20230410', 20, 'Drum', 5.00, '2023-04-10', '2027-04-10', 'B07', 'Available', NOW()),
(4, 'TONEWAY-O-20231015', 16, 'Drum', 4.00, '2023-10-15', '2027-10-15', 'B08', 'Available', NOW()),
(5, 'MYSella-S2-20230520', 12, 'Drum', 3.00, '2023-05-20', '2027-05-20', 'B09', 'Available', NOW()),
(5, 'MYSella-S2-20231125', 16, 'Drum', 4.00, '2023-11-25', '2027-11-25', 'B10', 'Available', NOW()),
(6, 'OMALA-O-20230601', 20, 'Drum', 5.00, '2023-06-01', '2027-06-01', 'B11', 'Available', NOW()),
(6, 'OMALA-O-20231205', 12, 'Drum', 3.00, '2023-12-05', '2027-12-05', 'B12', 'Available', NOW()),
(7, 'CORENA-O-20230710', 16, 'Drum', 4.00, '2023-07-10', '2027-07-10', 'B13', 'Available', NOW()),
(7, 'CORENA-O-20240115', 20, 'Drum', 5.00, '2024-01-15', '2028-01-15', 'B14', 'Available', NOW()),
(8, 'DORNA-AR-20230820', 12, 'Drum', 3.00, '2023-08-20', '2027-08-20', 'B15', 'Available', NOW()),
(8, 'DORNA-AR-20240225', 16, 'Drum', 4.00, '2024-02-25', '2028-02-25', 'B16', 'Available', NOW()),

(9, 'HELIX-10W-20230901', 72, 'Carton', 2.00, '2023-09-01', '2027-09-01', 'C01', 'Available', NOW()),
(9, 'HELIX-10W-20240310', 108, 'Carton', 3.00, '2024-03-10', '2028-03-10', 'C02', 'Available', NOW()),
(10, 'HELIX-5W-20231015', 72, 'Carton', 2.00, '2023-10-15', '2027-10-15', 'C03', 'Available', NOW()),
(10, 'HELIX-5W-20240420', 108, 'Carton', 3.00, '2024-04-20', '2028-04-20', 'C04', 'Available', NOW()),
(11, 'ADVANCE-4T-20231101', 88, 'Carton', 2.00, '2023-11-01', '2027-11-01', 'C05', 'Available', NOW()),
(11, 'ADVANCE-4T-20240505', 132, 'Carton', 3.00, '2024-05-05', '2028-05-05', 'C06', 'Available', NOW()),
(12, 'ADVANCE-AX7-20231210', 96, 'Carton', 2.00, '2023-12-10', '2027-12-10', 'C07', 'Available', NOW()),
(12, 'ADVANCE-AX7-20240615', 144, 'Carton', 3.00, '2024-06-15', '2028-06-15', 'C08', 'Available', NOW()),
(13, 'RIMULA-C1-20240120', 72, 'Carton', 2.00, '2024-01-20', '2028-01-20', 'C09', 'Available', NOW()),
(13, 'RIMULA-C1-20240725', 108, 'Carton', 3.00, '2024-07-25', '2028-07-25', 'C10', 'Available', NOW()),
(14, 'HELIX-HX7-20240205', 88, 'Carton', 2.00, '2024-02-05', '2028-02-05', 'C11', 'Available', NOW()),
(14, 'HELIX-HX7-20240810', 132, 'Carton', 3.00, '2024-08-10', '2028-08-10', 'C12', 'Available', NOW()),
(15, 'GADUS-S3-20240315', 96, 'Carton', 2.00, '2024-03-15', '2028-03-15', 'C13', 'Available', NOW()),
(15, 'GADUS-S3-20240920', 144, 'Carton', 3.00, '2024-09-20', '2028-09-20', 'C14', 'Available', NOW()),

(16, 'GADUS-P18-20240401', 48, 'Pail', 2.00, '2024-04-01', '2028-04-01', 'D01', 'Available', NOW()),
(16, 'GADUS-P18-20241005', 72, 'Pail', 3.00, '2024-10-05', '2028-10-05', 'D02', 'Available', NOW()),
(17, 'GADUS-P50-20240510', 48, 'Pail', 2.00, '2024-05-10', '2028-05-10', 'D03', 'Available', NOW()),
(17, 'GADUS-P50-20241115', 72, 'Pail', 3.00, '2024-11-15', '2028-11-15', 'D04', 'Available', NOW()),
(18, 'OMALA-P68-20240620', 48, 'Pail', 2.00, '2024-06-20', '2028-06-20', 'D05', 'Available', NOW()),
(18, 'OMALA-P68-20241225', 72, 'Pail', 3.00, '2024-12-25', '2028-12-25', 'D06', 'Available', NOW()),
(19, 'CORENA-P32-20240705', 48, 'Pail', 2.00, '2024-07-05', '2028-07-05', 'D07', 'Available', NOW()),
(19, 'CORENA-P32-20250110', 72, 'Pail', 3.00, '2025-01-10', '2029-01-10', 'D08', 'Available', NOW()),
(20, 'MYSella-P-20240815', 48, 'Pail', 2.00, '2024-08-15', '2028-08-15', 'D09', 'Available', NOW()),
(20, 'MYSella-P-20250220', 72, 'Pail', 3.00, '2025-02-20', '2029-02-20', 'D10', 'Available', NOW());

INSERT INTO stock_ledger (transaction_date, product_id, transaction_type, reference_type, reference_number, batch_number, quantity_in, uom, pallet, balance, location, created_at) VALUES
('2023-01-15', 1, 'IN', 'Inbound', 'INITIAL', 'RIMULA-R4-20230115', 20, 'Drum', 5.00, 20, 'B01', NOW()),
('2023-07-20', 1, 'IN', 'Inbound', 'INITIAL', 'RIMULA-R4-20230720', 16, 'Drum', 4.00, 36, 'B02', NOW()),
('2023-02-10', 2, 'IN', 'Inbound', 'INITIAL', 'RIMULA-R6-20230210', 12, 'Drum', 3.00, 12, 'B03', NOW()),
('2023-08-15', 2, 'IN', 'Inbound', 'INITIAL', 'RIMULA-R6-20230815', 20, 'Drum', 5.00, 32, 'B04', NOW()),
('2023-03-01', 3, 'IN', 'Inbound', 'INITIAL', 'GADUS-S2-20230301', 16, 'Drum', 4.00, 16, 'B05', NOW()),
('2023-09-05', 3, 'IN', 'Inbound', 'INITIAL', 'GADUS-S2-20230905', 12, 'Drum', 3.00, 28, 'B06', NOW()),
('2023-09-01', 9, 'IN', 'Inbound', 'INITIAL', 'HELIX-10W-20230901', 72, 'Carton', 2.00, 72, 'C01', NOW()),
('2024-03-10', 9, 'IN', 'Inbound', 'INITIAL', 'HELIX-10W-20240310', 108, 'Carton', 3.00, 180, 'C02', NOW()),
('2024-04-01', 16, 'IN', 'Inbound', 'INITIAL', 'GADUS-P18-20240401', 48, 'Pail', 2.00, 48, 'D01', NOW()),
('2024-10-05', 16, 'IN', 'Inbound', 'INITIAL', 'GADUS-P18-20241005', 72, 'Pail', 3.00, 120, 'D02', NOW());

DELETE FROM location_allocations;
DELETE FROM inbound_items;
DELETE FROM inbound_orders;

INSERT INTO inbound_orders (order_number, order_date, carrier_name, container_no, armada_no, shipment_no, po_number, expected_date, received_date, received_by, status, notes, created_by, created_at) VALUES
('INB-2024-0001', '2024-01-15', 'PT Maju Jaya Logistics', 'MSCU-1234567', 'B-1234-XYZ', 'SHP-20240115', 'PO-2024-001234', '2024-01-20', '2024-01-19', 1, 'Goods Received', 'Goods received in good condition', 1, '2024-01-15');

INSERT INTO inbound_items (inbound_order_id, product_id, batch_no, location, quantity, uom, pallet, manufacture_date, exp_date, stock_status, in_process_status, actual_qty, notes, created_at) VALUES
(1, 1, 'RIMULA-R4-20240110', 'B01', 20, 'Drum', 5.00, '2024-01-10', '2028-01-10', 'Accepted', 'Goods Received', 20, 'Received in good condition', NOW()),
(1, 2, 'RIMULA-R6-20240112', 'B02', 16, 'Drum', 4.00, '2024-01-12', '2028-01-12', 'Accepted', 'Goods Received', 16, 'Received in good condition', NOW());

INSERT INTO inbound_orders (order_number, order_date, carrier_name, container_no, armada_no, shipment_no, po_number, expected_date, received_date, received_by, status, notes, created_by, created_at) VALUES
('INB-2024-0002', '2024-02-01', 'CV Cepat Kirim', 'TCLU-7654321', 'B-5678-ABC', 'SHP-20240201', 'PO-2024-001235', '2024-02-15', NULL, NULL, 'Dues In', 'Expected delivery next week', 1, '2024-02-01');

INSERT INTO inbound_items (inbound_order_id, product_id, batch_no, location, quantity, uom, pallet, manufacture_date, exp_date, stock_status, in_process_status, actual_qty, notes, created_at) VALUES
(2, 3, 'GADUS-S2-20240210', 'A01', 12, 'Drum', 3.00, '2024-02-10', '2028-02-10', 'Pending', 'Dues In', 0, 'Scheduled for A01', NOW()),
(2, 4, 'TONEWAY-O-20240212', 'A02', 16, 'Drum', 4.00, '2024-02-12', '2028-02-12', 'Pending', 'Dues In', 0, 'Scheduled for A02', NOW());

INSERT INTO inbound_orders (order_number, order_date, carrier_name, container_no, armada_no, shipment_no, po_number, expected_date, received_date, received_by, status, notes, created_by, created_at) VALUES
('INB-2024-0003', '2024-02-10', 'PT Angkasa Logistik', 'HLBU-9876543', 'B-9999-XXX', 'SHP-20240210', 'PO-2024-001236', '2024-02-20', '2024-02-18', 1, 'Goods Received', 'All items verified and received', 1, '2024-02-10');

INSERT INTO inbound_items (inbound_order_id, product_id, batch_no, location, quantity, uom, pallet, manufacture_date, exp_date, stock_status, in_process_status, actual_qty, notes, created_at) VALUES
(3, 5, 'MYSELLA-S2-20240215', 'C01', 24, 'Drum', 6.00, '2024-02-15', '2028-02-15', 'Accepted', 'Goods Received', 24, 'Received in good condition', NOW()),
(3, 6, 'OMALA-O-20240216', 'C02', 20, 'Drum', 5.00, '2024-02-16', '2028-02-16', 'Accepted', 'Goods Received', 20, 'Received in good condition', NOW()),
(3, 7, 'CORENA-O-20240217', 'C03', 16, 'Drum', 4.00, '2024-02-17', '2028-02-17', 'Accepted', 'Goods Received', 16, 'Received in good condition', NOW());

DELETE FROM picklist_items;
DELETE FROM picklists;
DELETE FROM outbound_items;
DELETE FROM outbound_orders;

INSERT INTO outbound_orders (order_number, order_date, customer_id, so_number, do_number, destination, kota, armada_no, expected_date, status, notes, shipped_by, created_by, created_at) VALUES
('OTB-2024-0001', '2024-02-01', 1, 'SO-2024-00001', 'DO-2024-00001', 'Jakarta Warehouse', 'Jakarta', 'B-9999-ZZZ', '2024-02-10', 'Shipped', 'Delivery completed successfully', 4, 1, '2024-02-01');

INSERT INTO outbound_items (outbound_order_id, product_id, quantity, uom, pallet, batch_no, exp_date, location, notes, created_at) VALUES
(1, 1, 8, 'Drum', 2.00, 'RIMULA-R4-20230115', '2027-01-15', 'B01', 'Picked and verified', NOW()),
(1, 2, 8, 'Drum', 2.00, 'RIMULA-R6-20230210', '2027-02-10', 'B03', 'Picked and verified', NOW());

INSERT INTO outbound_orders (order_number, order_date, customer_id, so_number, do_number, destination, kota, armada_no, expected_date, status, notes, shipped_by, created_by, created_at) VALUES
('OTB-2024-0002', '2024-02-20', 2, 'SO-2024-00002', NULL, 'Surabaya Branch', 'Surabaya', NULL, '2024-03-01', 'Open', 'Awaiting pickup confirmation', NULL, 1, '2024-02-20');

INSERT INTO outbound_items (outbound_order_id, product_id, quantity, uom, pallet, batch_no, exp_date, location, notes, created_at) VALUES
(2, 3, 12, 'Drum', 3.00, 'GADUS-S2-20230301', '2027-03-01', 'B05', 'Ready for picking', NOW()),
(2, 4, 8, 'Drum', 2.00, 'TONEWAY-O-20230410', '2027-04-10', 'B07', 'Ready for picking', NOW()),
(2, 5, 12, 'Drum', 3.00, 'MYSella-S2-20230520', '2027-05-20', 'B09', 'Ready for picking', NOW());

INSERT INTO outbound_orders (order_number, order_date, customer_id, so_number, do_number, destination, kota, armada_no, expected_date, status, notes, shipped_by, created_by, created_at) VALUES
('OTB-2024-0003', '2024-02-15', 3, 'SO-2024-00003', 'DO-2024-00003', 'Semarang Distribution', 'Semarang', 'B-8888-YYY', '2024-02-25', 'Shipped', 'Express delivery completed', 4, 1, '2024-02-15');

INSERT INTO outbound_items (outbound_order_id, product_id, quantity, uom, pallet, batch_no, exp_date, location, notes, created_at) VALUES
(3, 6, 16, 'Drum', 4.00, 'OMALA-O-20230601', '2027-06-01', 'B11', 'Picked and verified', NOW()),
(3, 7, 12, 'Drum', 3.00, 'CORENA-O-20230710', '2027-07-10', 'B13', 'Picked and verified', NOW());

INSERT INTO outbound_orders (order_number, order_date, customer_id, so_number, do_number, destination, kota, armada_no, expected_date, status, notes, shipped_by, created_by, created_at) VALUES
('OTB-2024-0004', '2024-03-01', 4, 'SO-2024-00004', NULL, 'Bandung Warehouse', 'Bandung', NULL, '2024-03-10', 'Open', 'New order, preparing for shipment', NULL, 1, '2024-03-01');

INSERT INTO outbound_items (outbound_order_id, product_id, quantity, uom, pallet, batch_no, exp_date, location, notes, created_at) VALUES
(4, 8, 16, 'Drum', 4.00, 'DORNA-AR-20230820', '2027-08-20', 'B15', 'Ready for picking', NOW());

INSERT INTO picklists (outbound_order_id, picklist_number, created_date, status, notes, created_by, created_at) VALUES
(1, 'PKL-2024-0001', '2024-02-07', 'Completed', 'All items picked and verified', 4, '2024-02-07'),
(3, 'PKL-2024-0003', '2024-02-22', 'Completed', 'All items picked and verified', 4, '2024-02-22');

INSERT INTO picklist_items (picklist_id, product_id, batch_no, location, quantity, uom, pallet, picked_quantity, status, picker_id, notes, created_at) VALUES
(1, 1, 'RIMULA-R4-20230115', 'B01', 8, 'Drum', 2.00, 8, 'Picked', 4, 'Item verified and picked', NOW()),
(1, 2, 'RIMULA-R6-20230210', 'B03', 8, 'Drum', 2.00, 8, 'Picked', 4, 'Item verified and picked', NOW()),
(2, 6, 'OMALA-O-20230601', 'B11', 16, 'Drum', 4.00, 16, 'Picked', 4, 'Item verified and picked', NOW()),
(2, 7, 'CORENA-O-20230710', 'B13', 12, 'Drum', 3.00, 12, 'Picked', 4, 'Item verified and picked', NOW());

DELETE FROM stock_take_items;
DELETE FROM stock_take;

INSERT INTO stock_take (take_number, take_date, status, notes, created_by, created_at) VALUES
('ST-2024-001', '2024-01-31', 'Completed', 'Monthly stock count - January 2024', 2, '2024-01-31');

INSERT INTO stock_take_items (stock_take_id, product_id, batch_number, location, qty_system, qty_physical, difference, status, notes, created_at) VALUES
(1, 1, 'RIMULA-R4-20230115', 'B01', 20, 20, 0, 'Clear', 'Count verified - exact match', NOW()),
(1, 2, 'RIMULA-R6-20230210', 'B03', 12, 12, 0, 'Clear', 'Count verified - exact match', NOW()),
(1, 3, 'GADUS-S2-20230301', 'B05', 16, 15, -1, 'Minus', 'Shortage found - investigating', NOW()),
(1, 4, 'TONEWAY-O-20230410', 'B07', 20, 20, 0, 'Clear', 'Count verified - exact match', NOW()),
(1, 5, 'MYSella-S2-20230520', 'B09', 12, 13, 1, 'Plus', 'Excess found - investigating', NOW());

INSERT INTO stock_take (take_number, take_date, status, notes, created_by, created_at) VALUES
('ST-2024-002', '2024-02-28', 'Completed', 'Monthly stock count - February 2024', 3, '2024-02-28');

INSERT INTO stock_take_items (stock_take_id, product_id, batch_number, location, qty_system, qty_physical, difference, status, notes, created_at) VALUES
(2, 6, 'OMALA-O-20230601', 'B11', 20, 20, 0, 'Clear', 'Count verified - exact match', NOW()),
(2, 7, 'CORENA-O-20230710', 'B13', 16, 16, 0, 'Clear', 'Count verified - exact match', NOW()),
(2, 8, 'DORNA-AR-20230820', 'B15', 12, 12, 0, 'Clear', 'Count verified - exact match', NOW()),
(2, 9, 'HELIX-10W-20230901', 'C01', 72, 72, 0, 'Clear', 'Count verified - exact match', NOW()),
(2, 10, 'HELIX-5W-20231015', 'C03', 72, 71, -1, 'Minus', 'Minor shortage recorded', NOW());

INSERT INTO stock_take (take_number, take_date, status, notes, created_by, created_at) VALUES
('ST-2024-003', '2024-03-05', 'In Progress', 'Quarterly stock count - Q1 2024', 4, '2024-03-05');

INSERT INTO stock_take_items (stock_take_id, product_id, batch_number, location, qty_system, qty_physical, difference, status, notes, created_at) VALUES
(3, 11, 'ADVANCE-4T-20231101', 'C05', 88, NULL, NULL, 'Clear', 'Not yet counted', NOW()),
(3, 12, 'ADVANCE-AX7-20231210', 'C07', 96, NULL, NULL, 'Clear', 'Not yet counted', NOW()),
(3, 13, 'RIMULA-C1-20240120', 'C09', 72, NULL, NULL, 'Clear', 'Not yet counted', NOW()),
(3, 16, 'GADUS-P18-20240401', 'D01', 48, NULL, NULL, 'Clear', 'Not yet counted', NOW()),
(3, 17, 'GADUS-P50-20240510', 'D03', 48, NULL, NULL, 'Clear', 'Not yet counted', NOW());

INSERT INTO location_allocations (reference_type, reference_id, item_id, pallet_number, location, quantity, uom, is_full, created_at) VALUES

('Stock', 1, 1, 1, 'B01', 20, 'Drum', 1, NOW()),
('Stock', 2, 2, 1, 'B02', 16, 'Drum', 1, NOW()),
('Stock', 3, 3, 1, 'B03', 12, 'Drum', 0, NOW()),
('Stock', 4, 4, 1, 'B04', 20, 'Drum', 1, NOW()),
('Stock', 5, 5, 1, 'B05', 16, 'Drum', 1, NOW()),
('Stock', 6, 6, 1, 'B06', 12, 'Drum', 0, NOW()),
('Stock', 7, 7, 1, 'B07', 20, 'Drum', 1, NOW()),
('Stock', 8, 8, 1, 'B08', 16, 'Drum', 1, NOW()),
('Stock', 9, 9, 1, 'B09', 12, 'Drum', 0, NOW()),
('Stock', 10, 10, 1, 'B10', 16, 'Drum', 1, NOW()),
('Stock', 11, 11, 1, 'B11', 20, 'Drum', 1, NOW()),
('Stock', 12, 12, 1, 'B12', 12, 'Drum', 0, NOW()),
('Stock', 13, 13, 1, 'B13', 16, 'Drum', 1, NOW()),
('Stock', 14, 14, 1, 'B14', 20, 'Drum', 1, NOW()),
('Stock', 15, 15, 1, 'B15', 12, 'Drum', 0, NOW()),
('Stock', 16, 16, 1, 'B16', 16, 'Drum', 1, NOW()),
('Stock', 17, 17, 1, 'C01', 72, 'Carton', 1, NOW()),
('Stock', 18, 18, 1, 'C02', 108, 'Carton', 1, NOW()),
('Stock', 19, 19, 1, 'C03', 72, 'Carton', 1, NOW()),
('Stock', 20, 20, 1, 'C04', 108, 'Carton', 1, NOW()),
('Stock', 21, 21, 1, 'C05', 88, 'Carton', 0, NOW()),
('Stock', 22, 22, 1, 'C06', 132, 'Carton', 1, NOW()),
('Stock', 23, 23, 1, 'D01', 48, 'Pail', 1, NOW()),
('Stock', 24, 24, 1, 'D02', 72, 'Pail', 1, NOW());

SET FOREIGN_KEY_CHECKS = 1;

SELECT '========================================' AS '';
SELECT '✅ SEEDING COMPLETED SUCCESSFULLY!' AS Status;
SELECT '========================================' AS '';
SELECT '' AS '';
SELECT '📊 Data Summary:' AS '';
SELECT CONCAT('- Users: ', COUNT(*)) AS '' FROM users;
SELECT CONCAT('- Customers: ', COUNT(*)) AS '' FROM customers;
SELECT CONCAT('- Products: ', COUNT(*)) AS '' FROM products;
SELECT CONCAT('- Stock Items: ', COUNT(*)) AS '' FROM stock;
SELECT CONCAT('- Stock Ledger: ', COUNT(*)) AS '' FROM stock_ledger;
SELECT CONCAT('- Inbound Orders: ', COUNT(*)) AS '' FROM inbound_orders;
SELECT CONCAT('- Inbound Items: ', COUNT(*)) AS '' FROM inbound_items;
SELECT CONCAT('- Outbound Orders: ', COUNT(*)) AS '' FROM outbound_orders;
SELECT CONCAT('- Outbound Items: ', COUNT(*)) AS '' FROM outbound_items;
SELECT CONCAT('- Picklists: ', COUNT(*)) AS '' FROM picklists;
SELECT CONCAT('- Picklist Items: ', COUNT(*)) AS '' FROM picklist_items;
SELECT CONCAT('- Stock Takes: ', COUNT(*)) AS '' FROM stock_take;
SELECT CONCAT('- Stock Take Items: ', COUNT(*)) AS '' FROM stock_take_items;
SELECT CONCAT('- Location Allocations: ', COUNT(*)) AS '' FROM location_allocations;
SELECT '' AS '';
SELECT '========================================' AS '';
SELECT '🔐 Default Login Credentials:' AS '';
SELECT '========================================' AS '';
SELECT 'Username: admin' AS '';
SELECT 'Password: password' AS '';
SELECT '' AS '';
SELECT '🌐 Access the application at:' AS '';
SELECT 'http://localhost/trianawms/' AS '';
SELECT '========================================' AS '';

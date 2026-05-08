DELETE FROM location_allocations;

INSERT INTO location_allocations (reference_type, reference_id, product_id, batch_number, location, quantity, pallet, allocated_date, status) VALUES
('STOCK', 1, 1, 'RIMULA-R4-20230115', 'B01', 20, 5.00, '2024-01-15', 'Active'),
('STOCK', 2, 1, 'RIMULA-R4-20230720', 'B02', 16, 4.00, '2024-02-01', 'Active'),
('STOCK', 3, 2, 'RIMULA-R6-20230210', 'B03', 12, 3.00, '2024-01-20', 'Active'),
('STOCK', 4, 2, 'RIMULA-R6-20230815', 'B04', 20, 5.00, '2024-02-05', 'Active'),
('STOCK', 5, 3, 'GADUS-S2-20230301', 'B05', 16, 4.00, '2024-01-25', 'Active'),
('STOCK', 6, 3, 'GADUS-S2-20230905', 'B06', 12, 3.00, '2024-02-10', 'Active'),
('STOCK', 7, 4, 'TONEWAY-O-20230410', 'B07', 20, 5.00, '2024-02-12', 'Active'),
('STOCK', 8, 4, 'TONEWAY-O-20231015', 'B08', 16, 4.00, '2024-02-15', 'Active'),
('STOCK', 9, 5, 'MYSella-S2-20230520', 'B09', 12, 3.00, '2024-02-18', 'Active'),
('STOCK', 10, 5, 'MYSella-S2-20231125', 'B10', 16, 4.00, '2024-02-20', 'Active'),
('STOCK', 11, 6, 'OMALA-O-20230601', 'B11', 20, 5.00, '2024-02-22', 'Active'),
('STOCK', 12, 6, 'OMALA-O-20231205', 'B12', 12, 3.00, '2024-02-25', 'Active'),
('STOCK', 13, 7, 'CORENA-O-20230710', 'B13', 16, 4.00, '2024-02-28', 'Active'),
('STOCK', 14, 7, 'CORENA-O-20240115', 'B14', 20, 5.00, '2024-03-01', 'Active'),
('STOCK', 15, 8, 'DORNA-AR-20230820', 'B15', 12, 3.00, '2024-03-03', 'Active'),
('STOCK', 16, 8, 'DORNA-AR-20240225', 'B16', 16, 4.00, '2024-03-05', 'Active'),
('STOCK', 17, 9, 'HELIX-10W-20230901', 'C01', 72, 2.00, '2024-03-07', 'Active'),
('STOCK', 18, 9, 'HELIX-10W-20240310', 'C02', 108, 3.00, '2024-03-08', 'Active'),
('STOCK', 19, 10, 'HELIX-5W-20231015', 'C03', 72, 2.00, '2024-03-10', 'Active'),
('STOCK', 20, 10, 'HELIX-5W-20240420', 'C04', 108, 3.00, '2024-03-12', 'Active'),
('STOCK', 21, 11, 'ADVANCE-4T-20231101', 'C05', 88, 2.00, '2024-03-15', 'Active'),
('STOCK', 22, 11, 'ADVANCE-4T-20240505', 'C06', 132, 3.00, '2024-03-16', 'Active'),
('STOCK', 23, 16, 'GADUS-P18-20240401', 'D01', 48, 2.00, '2024-03-18', 'Active'),
('STOCK', 24, 16, 'GADUS-P18-20241005', 'D02', 72, 3.00, '2024-03-19', 'Active');

INSERT INTO location_allocations (reference_type, reference_id, product_id, batch_number, location, quantity, pallet, allocated_date, status) VALUES
('INBOUND', 3, 3, 'GADUS-S2-20240210', 'A01', 12, 3.00, '2024-02-05', 'Reserved'),
('INBOUND', 4, 4, 'TONEWAY-O-20240212', 'A02', 16, 4.00, '2024-02-05', 'Reserved');

DELETE FROM stock_take_items;
DELETE FROM stock_take;

INSERT INTO stock_take (take_number, take_date, notes, status, created_by, created_at) VALUES
('ST-2024-001', '2024-01-31', 'Monthly stock count - January 2024', 'Completed', 2, '2024-01-31');

INSERT INTO stock_take_items (stock_take_id, product_id, batch_number, location, qty_system, qty_physical, difference, status, notes) VALUES
(1, 1, 'RIMULA-R4-20230115', 'B01', 20, 20, 0, 'Clear', 'Count verified - exact match'),
(1, 2, 'RIMULA-R6-20230210', 'B03', 12, 12, 0, 'Clear', 'Count verified - exact match'),
(1, 3, 'GADUS-S2-20230301', 'B05', 16, 15, -1, 'Minus', 'Shortage found - investigating'),
(1, 4, 'TONEWAY-O-20230410', 'B07', 20, 20, 0, 'Clear', 'Count verified - exact match'),
(1, 5, 'MYSella-S2-20230520', 'B09', 12, 13, 1, 'Plus', 'Excess found - investigating');

INSERT INTO stock_take (take_number, take_date, notes, status, created_by, created_at) VALUES
('ST-2024-002', '2024-02-28', 'Monthly stock count - February 2024', 'Completed', 3, '2024-02-28');

INSERT INTO stock_take_items (stock_take_id, product_id, batch_number, location, qty_system, qty_physical, difference, status, notes) VALUES
(2, 6, 'OMALA-O-20230601', 'B11', 20, 20, 0, 'Clear', 'Count verified - exact match'),
(2, 7, 'CORENA-O-20230710', 'B13', 16, 16, 0, 'Clear', 'Count verified - exact match'),
(2, 8, 'DORNA-AR-20230820', 'B15', 12, 12, 0, 'Clear', 'Count verified - exact match'),
(2, 9, 'HELIX-10W-20230901', 'C01', 72, 72, 0, 'Clear', 'Count verified - exact match'),
(2, 10, 'HELIX-5W-20231015', 'C03', 72, 71, -1, 'Minus', 'Minor shortage recorded');

INSERT INTO stock_take (take_number, take_date, notes, status, created_by, created_at) VALUES
('ST-2024-003', '2024-03-05', 'Quarterly stock count - Q1 2024', 'In Progress', 4, '2024-03-05');

INSERT INTO stock_take_items (stock_take_id, product_id, batch_number, location, qty_system, qty_physical, difference, status, notes) VALUES
(3, 11, 'ADVANCE-4T-20231101', 'C05', 88, NULL, NULL, 'Pending', 'Not yet counted'),
(3, 12, 'ADVANCE-AX7-20231210', 'C07', 96, NULL, NULL, 'Pending', 'Not yet counted'),
(3, 13, 'RIMULA-C1-20240120', 'C09', 72, NULL, NULL, 'Pending', 'Not yet counted'),
(3, 16, 'GADUS-P18-20240401', 'D01', 48, NULL, NULL, 'Pending', 'Not yet counted'),
(3, 17, 'GADUS-P50-20240510', 'D03', 48, NULL, NULL, 'Pending', 'Not yet counted');

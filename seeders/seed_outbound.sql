DELETE FROM picklist_items;
DELETE FROM picklists;
DELETE FROM outbound_items;
DELETE FROM outbound_orders;

INSERT INTO outbound_orders (order_number, customer_id, so_number, order_date, expected_date, shipped_date, status, notes, created_by, created_at) VALUES
('OTB-2024-0001', 1, 'SO-2024-00001', '2024-02-01', '2024-02-10', '2024-02-08', 'Shipped', 'Delivery completed successfully', 1, '2024-02-01');

INSERT INTO outbound_items (outbound_order_id, product_id, quantity, pallet, batch_number, location, notes) VALUES
(1, 1, 8, 2.00, 'RIMULA-R4-20230115', 'B01', 'Picked and verified'),
(1, 2, 8, 2.00, 'RIMULA-R6-20230210', 'B03', 'Picked and verified');

INSERT INTO outbound_orders (order_number, customer_id, so_number, order_date, expected_date, shipped_date, status, notes, created_by, created_at) VALUES
('OTB-2024-0002', 2, 'DO-2024-00002', '2024-02-20', '2024-03-01', NULL, 'Open', 'Awaiting pickup confirmation', 1, '2024-02-20');

INSERT INTO outbound_items (outbound_order_id, product_id, quantity, pallet, batch_number, location, notes) VALUES
(2, 3, 12, 3.00, 'GADUS-S2-20230301', 'B05', 'Ready for picking'),
(2, 4, 8, 2.00, 'TONEWAY-O-20230410', 'B07', 'Ready for picking'),
(2, 5, 12, 3.00, 'MYSella-S2-20230520', 'B09', 'Ready for picking');

INSERT INTO outbound_orders (order_number, customer_id, so_number, order_date, expected_date, shipped_date, status, notes, created_by, created_at) VALUES
('OTB-2024-0003', 3, 'SO-2024-00003', '2024-02-15', '2024-02-25', '2024-02-23', 'Shipped', 'Express delivery completed', 1, '2024-02-15');

INSERT INTO outbound_items (outbound_order_id, product_id, quantity, pallet, batch_number, location, notes) VALUES
(3, 6, 16, 4.00, 'OMALA-O-20230601', 'B11', 'Picked and verified'),
(3, 7, 12, 3.00, 'CORENA-O-20230710', 'B13', 'Picked and verified');

INSERT INTO outbound_orders (order_number, customer_id, so_number, order_date, expected_date, shipped_date, status, notes, created_by, created_at) VALUES
('OTB-2024-0004', 4, 'DO-2024-00004', '2024-03-01', '2024-03-10', NULL, 'Open', 'New order, preparing for shipment', 1, '2024-03-01');

INSERT INTO outbound_items (outbound_order_id, product_id, quantity, pallet, batch_number, location, notes) VALUES
(4, 8, 16, 4.00, 'DORNA-AR-20230820', 'B15', 'Ready for picking');

INSERT INTO picklists (outbound_order_id, picklist_number, picklist_date, status, picker_id, notes, created_at) VALUES
(1, 'PKL-2024-0001', '2024-02-07', 'Completed', 4, 'All items picked and verified', '2024-02-07'),
(3, 'PKL-2024-0003', '2024-02-22', 'Completed', 4, 'All items picked and verified', '2024-02-22');

INSERT INTO picklist_items (picklist_id, product_id, batch_number, location, quantity, pallet, picked_qty, status, notes) VALUES

(1, 1, 'RIMULA-R4-20230115', 'B01', 8, 2.00, 8, 'Picked', 'Item verified and picked'),
(1, 2, 'RIMULA-R6-20230210', 'B03', 8, 2.00, 8, 'Picked', 'Item verified and picked'),

(2, 6, 'OMALA-O-20230601', 'B11', 16, 4.00, 16, 'Picked', 'Item verified and picked'),
(2, 7, 'CORENA-O-20230710', 'B13', 12, 3.00, 12, 'Picked', 'Item verified and picked');

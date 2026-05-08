SET FOREIGN_KEY_CHECKS = 0;
DELETE FROM location_allocations WHERE reference_type = 'INBOUND';
DELETE FROM inbound_items;
DELETE FROM inbound_orders;
SET FOREIGN_KEY_CHECKS = 1;

INSERT INTO inbound_orders (order_number, order_date, carrier_name, container_no, armada_no, shipment_no, po_number, expected_date, received_date, received_by, status, notes, created_by, created_at) VALUES
('INB-2024-0001', '2024-01-15', 'PT Maju Jaya Logistics', 'MSCU-1234567', 'Truck B-1234-XYZ', 'SHP-20240115', 'PO-2024-001234', '2024-01-20', '2024-01-19', 1, 'Goods Received', 'Goods received in good condition', 1, '2024-01-15');

INSERT INTO inbound_items (inbound_order_id, product_id, quantity, pallet, batch_no, manufacture_date, exp_date, stock_status, in_process_status, location, actual_qty, notes) VALUES
(1, 1, 20, 5.00, 'RIMULA-R4-20240110', '2024-01-10', '2028-01-10', 'Accepted', 'Goods Received', 'B01', 20, 'Received in good condition'),
(1, 2, 16, 4.00, 'RIMULA-R6-20240112', '2024-01-12', '2028-01-12', 'Accepted', 'Goods Received', 'B02', 16, 'Received in good condition');

INSERT INTO inbound_orders (order_number, order_date, carrier_name, container_no, armada_no, shipment_no, po_number, expected_date, received_date, received_by, status, notes, created_by, created_at) VALUES
('INB-2024-0002', '2024-02-01', 'CV Cepat Kirim', 'TCLU-7654321', 'Truck B-5678-ABC', 'SHP-20240201', 'PO-2024-001235', '2024-02-15', NULL, NULL, 'Dues In', 'Expected delivery next week', 1, '2024-02-01');

INSERT INTO inbound_items (inbound_order_id, product_id, quantity, pallet, batch_no, manufacture_date, exp_date, stock_status, in_process_status, location, actual_qty, notes) VALUES
(2, 3, 12, 3.00, 'GADUS-S2-20240210', '2024-02-10', '2028-02-10', 'Pending', 'Dues In', 'A01', 0, 'Scheduled for A01'),
(2, 4, 16, 4.00, 'TONEWAY-O-20240212', '2024-02-12', '2028-02-12', 'Pending', 'Dues In', 'A02', 0, 'Scheduled for A02');

INSERT INTO inbound_orders (order_number, order_date, carrier_name, container_no, armada_no, shipment_no, po_number, expected_date, received_date, received_by, status, notes, created_by, created_at) VALUES
('INB-2024-0003', '2024-02-10', 'PT Angkasa Logistik', 'HLBU-9876543', 'Truck B-9999-XXX', 'SHP-20240210', 'PO-2024-001236', '2024-02-20', '2024-02-18', 1, 'Goods Received', 'All items verified and received', 1, '2024-02-10');

INSERT INTO inbound_items (inbound_order_id, product_id, quantity, pallet, batch_no, manufacture_date, exp_date, stock_status, in_process_status, location, actual_qty, notes) VALUES
(3, 5, 24, 6.00, 'MYSELLA-S2-20240215', '2024-02-15', '2028-02-15', 'Accepted', 'Goods Received', 'C01', 24, 'Received in good condition'),
(3, 6, 20, 5.00, 'OMALA-O-20240216', '2024-02-16', '2028-02-16', 'Accepted', 'Goods Received', 'C02', 20, 'Received in good condition'),
(3, 7, 16, 4.00, 'CORENA-O-20240217', '2024-02-17', '2028-02-17', 'Accepted', 'Goods Received', 'C03', 16, 'Received in good condition');

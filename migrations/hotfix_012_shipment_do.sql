ALTER TABLE `inbound_orders`
    ADD COLUMN `shipment_no` VARCHAR(100) DEFAULT NULL COMMENT 'Shipment Number' AFTER `po_number`,
    ADD COLUMN `do_number`   VARCHAR(100) DEFAULT NULL COMMENT 'Delivery Order Number' AFTER `shipment_no`;

DESCRIBE inbound_orders;

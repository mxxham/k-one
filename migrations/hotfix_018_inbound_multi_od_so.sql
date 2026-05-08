ALTER TABLE `inbound_orders`
    ADD COLUMN IF NOT EXISTS `po_number`    VARCHAR(100) NULL COMMENT 'Purchase Order number' AFTER `order_number`,
    ADD COLUMN IF NOT EXISTS `shipment_no`  VARCHAR(100) NULL COMMENT 'Shipment number (satu per inbound)' AFTER `po_number`,
    ADD COLUMN IF NOT EXISTS `do_number`    VARCHAR(100) NULL COMMENT 'Delivery Order (header level, opsional)' AFTER `shipment_no`,
    ADD COLUMN IF NOT EXISTS `received_by_name` VARCHAR(100) NULL COMMENT 'Name of receiver' AFTER `received_by`,
    ADD COLUMN IF NOT EXISTS `remarks`      TEXT NULL AFTER `notes`;

ALTER TABLE `inbound_items`
    ADD COLUMN IF NOT EXISTS `od_number`    VARCHAR(100) NULL COMMENT 'OD Number per item (from planning)' AFTER `inbound_order_id`,
    ADD COLUMN IF NOT EXISTS `so_number`    VARCHAR(100) NULL COMMENT 'SO Number per item (from planning)' AFTER `od_number`,
    ADD COLUMN IF NOT EXISTS `in_process_status` VARCHAR(50) NULL DEFAULT 'Dues In' COMMENT 'Dues In/Goods Received/ATP/Unserviceable/Picked' AFTER `stock_status`,
    ADD COLUMN IF NOT EXISTS `pallet_no`    VARCHAR(50) NULL COMMENT 'Physical pallet label, e.g. PLT-001' AFTER `pallet`;

ALTER TABLE `inbound_items`
    ADD INDEX IF NOT EXISTS `idx_od_number` (`od_number`),
    ADD INDEX IF NOT EXISTS `idx_so_number_item` (`so_number`);

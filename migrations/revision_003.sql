USE trianawms;

ALTER TABLE `inbound_items` 
    ADD COLUMN IF NOT EXISTS `batch_number` VARCHAR(100) DEFAULT NULL AFTER `product_id`;

UPDATE `inbound_items` i
SET i.batch_number = (
    SELECT ii.batch_no FROM (SELECT id, batch_no FROM inbound_items WHERE batch_no IS NOT NULL) ii
    WHERE ii.id = i.id
)
WHERE i.batch_number IS NULL;

ALTER TABLE `stock_ledger`
    ADD COLUMN IF NOT EXISTS `batch_number` VARCHAR(100) DEFAULT NULL;

ALTER TABLE `products`
    ADD COLUMN IF NOT EXISTS `max_trans_qty` INT DEFAULT 9999 
    COMMENT 'Max quantity per transaction (set high to avoid blocking)';

ALTER TABLE `products`
    ADD COLUMN IF NOT EXISTS `max_sku_qty` INT DEFAULT 9999;

UPDATE `products` SET max_trans_qty = 9999 WHERE max_trans_qty < 500;
UPDATE `products` SET max_sku_qty = 9999 WHERE max_sku_qty < 500;

SELECT 'Migration 003 complete' AS result;

ALTER TABLE `customers`
    ADD COLUMN IF NOT EXISTS `city` VARCHAR(100) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `customer_type` ENUM('DIRECT','DISTRIBUTOR','RESELLER') DEFAULT 'DIRECT';

ALTER TABLE `inbound_orders`
    ADD COLUMN IF NOT EXISTS `po_number` VARCHAR(50) DEFAULT NULL;

SELECT 'Migration 003 complete (updated)' AS result;

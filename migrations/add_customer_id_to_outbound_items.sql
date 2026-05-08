ALTER TABLE `outbound_orders`
    MODIFY COLUMN `customer_id` int(11) DEFAULT NULL COMMENT 'Legacy: customer per order. Digantikan customer_id per item.';

SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'outbound_items'
      AND COLUMN_NAME  = 'customer_id'
);

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `outbound_items` ADD COLUMN `customer_id` int(11) DEFAULT NULL COMMENT ''Customer per item (multi-customer per shipment)'' AFTER `destination_id`',
    'SELECT ''customer_id column already exists'' AS note'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE outbound_items oi
JOIN outbound_orders o ON oi.outbound_order_id = o.id
SET oi.customer_id = o.customer_id
WHERE oi.customer_id IS NULL AND o.customer_id IS NOT NULL;

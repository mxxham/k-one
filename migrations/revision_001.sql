SET @exist = (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'inbound_orders' AND column_name = 'po_number');
SET @sql = IF(@exist = 0,
    'ALTER TABLE inbound_orders ADD COLUMN po_number VARCHAR(50) NULL AFTER order_number COMMENT ''Purchase Order number''',
    'SELECT ''Column po_number already exists''');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @exist = (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'inbound_orders' AND column_name = 'order_month');
SET @sql = IF(@exist = 0,
    'ALTER TABLE inbound_orders ADD COLUMN order_month VARCHAR(7) NULL AFTER order_date COMMENT ''Month in MM/YYYY format''',
    'SELECT ''Column order_month already exists''');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @exist = (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'inbound_orders' AND column_name = 'remarks');
SET @sql = IF(@exist = 0,
    'ALTER TABLE inbound_orders ADD COLUMN remarks TEXT NULL AFTER notes COMMENT ''Additional remarks''',
    'SELECT ''Column remarks already exists''');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @exist = (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'outbound_orders' AND column_name = 'remarks');
SET @sql = IF(@exist = 0,
    'ALTER TABLE outbound_orders ADD COLUMN remarks TEXT NULL AFTER notes COMMENT ''Additional remarks''',
    'SELECT ''Column remarks already exists''');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

ALTER TABLE outbound_orders MODIFY COLUMN status ENUM('Open','Picking','Picked','Shipped','Delivered','Completed','Cancelled') DEFAULT 'Open';

SET @exist = (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'stock_ledger' AND column_name = 'expiry_date');
SET @sql = IF(@exist = 0,
    'ALTER TABLE stock_ledger ADD COLUMN expiry_date DATE NULL AFTER location',
    'SELECT ''Column expiry_date already exists''');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @exist = (SELECT COUNT(*) FROM information_schema.statistics
              WHERE table_schema = DATABASE() AND table_name = 'stock' AND index_name = 'idx_fefo');
SET @sql = IF(@exist = 0,
    'CREATE INDEX idx_fefo ON stock (product_id, stock_status, expiry_date, quantity)',
    'SELECT ''Index idx_fefo already exists''');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

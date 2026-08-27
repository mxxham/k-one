-- hotfix_027_v2_inbound_putaway.sql
-- stock.stock_status was missing 'Rejected' though changeItemStatus()/complete()
-- write it for QUA_SHELL stock and stock.php/products_report.php read it.
-- Idempotent: only alters when 'Rejected' is not already in the enum.
SET @has_rejected := (
    SELECT INSTR(COLUMN_TYPE, 'Rejected')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'stock'
      AND COLUMN_NAME = 'stock_status'
);
SET @ddl := IF(
    @has_rejected IS NULL OR @has_rejected = 0,
    "ALTER TABLE stock MODIFY COLUMN stock_status
        ENUM('Available','Reserved','Expired','Dues In','Rejected') DEFAULT 'Available'",
    'SELECT 1'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
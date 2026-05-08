SET FOREIGN_KEY_CHECKS = 0;

DROP PROCEDURE IF EXISTS truncate_if_exists;
DELIMITER $$
CREATE PROCEDURE truncate_if_exists(IN tbl VARCHAR(128))
BEGIN
    IF EXISTS (
        SELECT 1
        FROM information_schema.tables
        WHERE table_schema = DATABASE()
          AND table_name = tbl
    ) THEN
        SET @sql = CONCAT('TRUNCATE TABLE `', tbl, '`');
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END$$
DELIMITER ;

CALL truncate_if_exists('activity_log');

CALL truncate_if_exists('stock_ledger');

CALL truncate_if_exists('outbound_item_locations');
CALL truncate_if_exists('picklist_items');
CALL truncate_if_exists('picklists');
CALL truncate_if_exists('location_allocations');

CALL truncate_if_exists('stock_take_items');
CALL truncate_if_exists('stock_take');
CALL truncate_if_exists('bin_transfers');

CALL truncate_if_exists('outbound_destinations');
CALL truncate_if_exists('outbound_items');
CALL truncate_if_exists('outbound_orders');

CALL truncate_if_exists('inbound_items');
CALL truncate_if_exists('inbound_orders');

CALL truncate_if_exists('stock_locations');
CALL truncate_if_exists('stock');

DROP PROCEDURE IF EXISTS truncate_if_exists;
SET FOREIGN_KEY_CHECKS = 1;
inbound_itemsinbound_itemsinbound_items
-- FULL WIPE: hapus SEMUA data mockup/master kecuali tabel users.
-- Akan diikuti auto-create produk/lokasi dari Excel import.
SET FOREIGN_KEY_CHECKS = 0;

DROP PROCEDURE IF EXISTS wipe_if_exists;
DELIMITER $$
CREATE PROCEDURE wipe_if_exists(IN tbl VARCHAR(128))
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

CALL wipe_if_exists('activity_log');
CALL wipe_if_exists('bin_transfers');
CALL wipe_if_exists('customers');
CALL wipe_if_exists('inbound_items');
CALL wipe_if_exists('inbound_orders');
CALL wipe_if_exists('location_allocations');
CALL wipe_if_exists('location_master');
CALL wipe_if_exists('outbound_destinations');
CALL wipe_if_exists('outbound_item_locations');
CALL wipe_if_exists('outbound_items');
CALL wipe_if_exists('outbound_orders');
CALL wipe_if_exists('picklist_items');
CALL wipe_if_exists('picklists');
CALL wipe_if_exists('products');
CALL wipe_if_exists('stock_ledger');
CALL wipe_if_exists('stock_locations');
CALL wipe_if_exists('stock_take_items');
CALL wipe_if_exists('stock_take');
CALL wipe_if_exists('stock');
CALL wipe_if_exists('warehouse_locations');

DROP PROCEDURE IF EXISTS wipe_if_exists;
SET FOREIGN_KEY_CHECKS = 1;

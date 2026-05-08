ALTER TABLE `stock_take_items`
    ADD COLUMN `counter_1` DECIMAL(10,2) DEFAULT NULL COMMENT 'Hitungan pertama (Counter 1)' AFTER `qty_system`,
    ADD COLUMN `counter_2` DECIMAL(10,2) DEFAULT NULL COMMENT 'Hitungan kedua (Counter 2)' AFTER `counter_1`,
    ADD COLUMN `counter_3` DECIMAL(10,2) DEFAULT NULL COMMENT 'Hitungan ketiga, opsional (Counter 3)' AFTER `counter_2`,
    ADD COLUMN `uom` VARCHAR(20) DEFAULT NULL COMMENT 'UOM item (DRUMS, CARTON, PAIL, dll)' AFTER `batch_number`,
    ADD COLUMN `counter_by` VARCHAR(100) DEFAULT NULL COMMENT 'Nama counter/petugas' AFTER `notes`;

DESCRIBE `stock_take_items`;

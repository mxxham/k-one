USE trianawms;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS `location_master` (
    `id`            INT AUTO_INCREMENT PRIMARY KEY,
    `location_code` VARCHAR(20) NOT NULL UNIQUE COMMENT 'e.g. CA01A01',
    `aisle`         VARCHAR(10) DEFAULT NULL,
    `rack`          VARCHAR(10) DEFAULT NULL,
    `row_name`      VARCHAR(10) DEFAULT NULL,
    `position`      VARCHAR(10) DEFAULT NULL,
    `zone`          VARCHAR(20) DEFAULT NULL COMMENT 'Bulk / Carton / Pail / Special',
    `is_active`     TINYINT(1) NOT NULL DEFAULT 1,
    `created_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_aisle` (`aisle`),
    KEY `idx_zone`  (`zone`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Master data semua lokasi gudang';

CREATE TABLE IF NOT EXISTS `stock_locations` (
    `id`            INT AUTO_INCREMENT PRIMARY KEY,
    `stock_id`      INT NULL DEFAULT NULL COMMENT 'FK → stock.id, diisi saat inbound complete',
    `location_code` VARCHAR(20) NOT NULL COMMENT 'FK → location_master.location_code',
    `pallet_seq`    SMALLINT NOT NULL DEFAULT 1 COMMENT 'Pallet sequence within this inbound item',
    `quantity`      DECIMAL(10,2) NOT NULL DEFAULT 0,
    `uom`           VARCHAR(20) DEFAULT 'EA',
    `is_full_pallet` TINYINT(1) DEFAULT 1,
    `batch_number`  VARCHAR(100) DEFAULT NULL,
    `inbound_item_id` INT DEFAULT NULL,
    `status`        ENUM('Available','Reserved','Picked','Empty') DEFAULT 'Available',
    `created_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_stock`    (`stock_id`),
    KEY `idx_location` (`location_code`),
    KEY `idx_status`   (`status`),
    KEY `idx_batch`    (`batch_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Stok per pallet per lokasi';

CREATE TABLE IF NOT EXISTS `outbound_item_locations` (
    `id`               INT AUTO_INCREMENT PRIMARY KEY,
    `outbound_item_id` INT NOT NULL,
    `stock_location_id` INT NOT NULL,
    `quantity`         DECIMAL(10,2) NOT NULL DEFAULT 0,
    `created_at`       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_item_loc` (`outbound_item_id`, `stock_location_id`),
    KEY `idx_oi`  (`outbound_item_id`),
    KEY `idx_sl`  (`stock_location_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Per-lokasi alokasi untuk outbound item';

ALTER TABLE `stock`
    ADD COLUMN IF NOT EXISTS `uom`              VARCHAR(20) DEFAULT 'EA',
    ADD COLUMN IF NOT EXISTS `pallet`           DECIMAL(10,2) DEFAULT 0,
    ADD COLUMN IF NOT EXISTS `manufacture_date` DATE DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `uom_per_pallet`   SMALLINT DEFAULT 4;

ALTER TABLE `inbound_items`
    ADD COLUMN IF NOT EXISTS `batch_number` VARCHAR(100) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `uom`          VARCHAR(20) DEFAULT 'EA',
    ADD COLUMN IF NOT EXISTS `actual_qty`   DECIMAL(10,2) DEFAULT 0,
    ADD COLUMN IF NOT EXISTS `pallet`       DECIMAL(10,2) DEFAULT 0,
    ADD COLUMN IF NOT EXISTS `manufacture_date` DATE DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `exp_date`     DATE DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `stock_status` VARCHAR(20) DEFAULT 'Accepted',
    ADD COLUMN IF NOT EXISTS `notes`        TEXT DEFAULT NULL;

UPDATE `inbound_items` SET `batch_number` = `batch_no`
WHERE `batch_number` IS NULL AND `batch_no` IS NOT NULL;

ALTER TABLE `outbound_items`
    ADD COLUMN IF NOT EXISTS `batch_number` VARCHAR(100) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `location`     VARCHAR(20) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `exp_date`     DATE DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `pallet`       DECIMAL(10,2) DEFAULT 0,
    ADD COLUMN IF NOT EXISTS `uom`          VARCHAR(20) DEFAULT 'EA',
    ADD COLUMN IF NOT EXISTS `stock_location_id` INT DEFAULT NULL;

UPDATE `outbound_items` SET `batch_number` = `batch_no`
WHERE `batch_number` IS NULL AND `batch_no` IS NOT NULL;

ALTER TABLE `picklist_items`
    ADD COLUMN IF NOT EXISTS `batch_number`      VARCHAR(100) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `stock_location_id` INT DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS `pallet_seq`        SMALLINT DEFAULT 1;

UPDATE `picklist_items` SET `batch_number` = `batch_no`
WHERE `batch_number` IS NULL AND `batch_no` IS NOT NULL;

SET FOREIGN_KEY_CHECKS = 1;

SELECT 'Migration 004 complete' AS result;

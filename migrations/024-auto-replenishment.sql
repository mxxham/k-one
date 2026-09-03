-- Auto-Replenishment System Migration
-- Date: 2026-08-29
-- Covers: sku_pickface_config, replen_task, outbound_items.blocked_on_replen_task_id,
--         picklist_items.replen_task_id

-- =============================================================================
-- Link picklist_items to replenishment tasks
-- Nullable INT — FK constraint added after replen_task table is created
-- =============================================================================

-- Helper: add column only if it doesn't exist (idempotent)
DROP PROCEDURE IF EXISTS _add_col_if_missing;
DELIMITER //
CREATE PROCEDURE _add_col_if_missing(
    IN p_table VARCHAR(64), IN p_column VARCHAR(64),
    IN p_def VARCHAR(500))
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = p_table AND column_name = p_column
    ) THEN
        SET @sql = CONCAT('ALTER TABLE `', p_table, '` ADD COLUMN `', p_column, '` ', p_def);
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END //
DELIMITER ;

CALL _add_col_if_missing('picklist_items', 'replen_task_id',
    "INT(11) NULL DEFAULT NULL COMMENT 'FK to replen_task.id — links pick line to replenishment task' AFTER `pallet_seq`");

-- Drop helper procedure
DROP PROCEDURE IF EXISTS _add_col_if_missing;

-- SKU Pickface Configuration (per-SKU pick face min/max levels)
CREATE TABLE IF NOT EXISTS `sku_pickface_config` (
    `id`              INT(11) AUTO_INCREMENT PRIMARY KEY,
    `sku_id`          INT(11) NOT NULL,
    `pickface_bin_id` INT(11) NOT NULL,
    `pickface_max`    INT(11) NOT NULL DEFAULT 0,
    `pickface_min`    INT(11) NOT NULL DEFAULT 0,
    `created_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_sku_pickface` (`sku_id`),
    FOREIGN KEY (`sku_id`)          REFERENCES `products`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`pickface_bin_id`) REFERENCES `location_master`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- Replenishment Tasks: move stock from bulk locations to pickface bins
-- =============================================================================

CREATE TABLE IF NOT EXISTS `replen_task` (
    `id`                  INT(11)      AUTO_INCREMENT PRIMARY KEY,
    `sku_id`              INT(11)      NOT NULL COMMENT 'FK products.id',
    `source_bin_id`       INT(11)      NOT NULL COMMENT 'FK location_master.id — bulk location',
    `destination_bin_id`  INT(11)      NOT NULL COMMENT 'FK location_master.id — pickface bin',
    `qty`                 INT(11)      NOT NULL COMMENT 'Quantity to move',
    `triggering_order_id` INT(11)      DEFAULT NULL COMMENT 'FK outbound_orders.id — order that triggered replenishment',
    `status`              ENUM('pending','printed','in_progress','completed') NOT NULL DEFAULT 'pending',
    `created_at`          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `completed_at`        TIMESTAMP    NULL DEFAULT NULL,
    FOREIGN KEY (`sku_id`) REFERENCES `products`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`source_bin_id`) REFERENCES `location_master`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`destination_bin_id`) REFERENCES `location_master`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`triggering_order_id`) REFERENCES `outbound_orders`(`id`) ON DELETE SET NULL,
    INDEX `idx_replen_task_status` (`status`),
    INDEX `idx_replen_task_sku` (`sku_id`),
    INDEX `idx_replen_task_source` (`source_bin_id`),
    INDEX `idx_replen_task_dest` (`destination_bin_id`),
    INDEX `idx_replen_task_trigger` (`triggering_order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Auto-replenishment tasks: move stock from bulk locations to pickface bins';

ALTER TABLE `picklist_items`
    ADD CONSTRAINT `fk_picklist_items_replen_task`
    FOREIGN KEY (`replen_task_id`) REFERENCES `replen_task`(`id`)
    ON DELETE SET NULL ON UPDATE CASCADE;

-- =============================================================================
-- Link outbound_items to replenishment tasks
-- Nullable INT — outbound line blocked on a pending replenishment
-- FK constraint added after replen_task table (above)
-- =============================================================================

-- Helper: add column only if it doesn't exist (idempotent)
DROP PROCEDURE IF EXISTS _add_col_if_missing;
DELIMITER //
CREATE PROCEDURE _add_col_if_missing(
    IN p_table VARCHAR(64), IN p_column VARCHAR(64),
    IN p_def VARCHAR(500))
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = p_table AND column_name = p_column
    ) THEN
        SET @sql = CONCAT('ALTER TABLE `', p_table, '` ADD COLUMN `', p_column, '` ', p_def);
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END //
DELIMITER ;

CALL _add_col_if_missing('outbound_items', 'blocked_on_replen_task_id',
    "INT(11) NULL DEFAULT NULL COMMENT 'FK replen_task.id — outbound line blocked pending replenishment' AFTER `stock_location_id`");

-- Drop helper procedure
DROP PROCEDURE IF EXISTS _add_col_if_missing;

ALTER TABLE `outbound_items`
    ADD CONSTRAINT `fk_outbound_items_replen_task`
    FOREIGN KEY (`blocked_on_replen_task_id`) REFERENCES `replen_task`(`id`)
    ON DELETE SET NULL ON UPDATE CASCADE;

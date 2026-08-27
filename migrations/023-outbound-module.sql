-- =============================================================================
-- 023 — Outbound Module: LPN-granular picking, staging, dispatch, GI, discrepancies, audit
-- Idempotent: safe to run multiple times via conditional procedure.
-- =============================================================================
USE sanchaya;

-- Helper procedure: add column only if it doesn't exist
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

-- Helper procedure: add index only if it doesn't exist
DROP PROCEDURE IF EXISTS _add_idx_if_missing;
DELIMITER //
CREATE PROCEDURE _add_idx_if_missing(
    IN p_table VARCHAR(64), IN p_index VARCHAR(64), IN p_cols VARCHAR(200))
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.statistics
        WHERE table_schema = DATABASE() AND table_name = p_table AND index_name = p_index
    ) THEN
        SET @sql = CONCAT('CREATE INDEX `', p_index, '` ON `', p_table, '`(', p_cols, ')');
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END //
DELIMITER ;

-- =============================================================================
-- 1. ALTER EXISTING TABLES
-- =============================================================================

-- outbound_items
CALL _add_col_if_missing('outbound_items', 'qty_allocated',
    "DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT 'Qty reserved by FEFO allocator' AFTER `actual_qty`");
CALL _add_col_if_missing('outbound_items', 'qty_shipped',
    "DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT 'Qty physically shipped' AFTER `qty_allocated`");
CALL _add_col_if_missing('outbound_items', 'lpn_code',
    "VARCHAR(50) NULL COMMENT 'LPN barcode picked for this line' AFTER `batch_number`");
CALL _add_col_if_missing('outbound_items', 'stock_location_id',
    "INT(11) NULL COMMENT 'FK stock_locations.id' AFTER `lpn_code`");

-- picklist_items
CALL _add_col_if_missing('picklist_items', 'lpn_code',
    "VARCHAR(50) NULL COMMENT 'LPN barcode assigned to this pick line' AFTER `pallet_seq`");
CALL _add_col_if_missing('picklist_items', 'bin_location',
    "VARCHAR(30) NULL COMMENT 'Source bin location code' AFTER `lpn_code`");
CALL _add_col_if_missing('picklist_items', 'qty_to_pick',
    "DECIMAL(10,2) NULL COMMENT 'Target qty to pick' AFTER `bin_location`");
CALL _add_col_if_missing('picklist_items', 'pick_reference',
    "VARCHAR(100) NULL COMMENT 'Idempotency key for pick.confirm' AFTER `qty_to_pick`");

-- stock_locations
CALL _add_col_if_missing('stock_locations', 'qty_reserved',
    "DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT 'Qty reserved for outbound' AFTER `quantity`");

-- =============================================================================
-- 2. NEW TABLES (IF NOT EXISTS)
-- =============================================================================

CREATE TABLE IF NOT EXISTS `operators` (
    `id`               INT(11)      AUTO_INCREMENT PRIMARY KEY,
    `user_id`          INT(11)      NOT NULL,
    `name`             VARCHAR(100) DEFAULT NULL,
    `capacity`         INT(11)      NOT NULL DEFAULT 50 COMMENT 'Max concurrent picks',
    `zone_assignment`  VARCHAR(20)  DEFAULT NULL COMMENT 'Aisle prefix restricting which bins this operator can pick from',
    `is_active`        TINYINT(1)   NOT NULL DEFAULT 1,
    `created_at`       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_operators_user_id` (`user_id`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Outbound pickers — linked to users for auth + audit';

CREATE TABLE IF NOT EXISTS `staging` (
    `id`               INT(11)      AUTO_INCREMENT PRIMARY KEY,
    `picklist_item_id` INT(11)      NOT NULL COMMENT 'FK picklist_items.id',
    `lpn_code`         VARCHAR(50)  NOT NULL COMMENT 'LPN barcode staged for dispatch',
    `staging_bin`      VARCHAR(30)  NOT NULL COMMENT 'Bin location where pallet is staged',
    `quantity`         DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT 'Quantity staged',
    `operator_id`      INT(11)      DEFAULT NULL COMMENT 'FK users.id — who scanned into staging',
    `scanned_at`       TIMESTAMP    NULL DEFAULT NULL,
    `created_at`       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`picklist_item_id`) REFERENCES `picklist_items`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`operator_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    INDEX `idx_staging_pli` (`picklist_item_id`),
    INDEX `idx_staging_lpn` (`lpn_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Picked LPNs staged in staging bins before dispatch';

CREATE TABLE IF NOT EXISTS `gi_exports` (
    `id`               INT(11)      AUTO_INCREMENT PRIMARY KEY,
    `gi_number`        VARCHAR(50)  NOT NULL COMMENT 'GI document number (GI-YYYYMM-NNNN)',
    `lpn_code`         VARCHAR(50)  NOT NULL COMMENT 'LPN dispatched',
    `do_number`        VARCHAR(50)  DEFAULT NULL COMMENT 'Delivery Order number',
    `truck_no`         VARCHAR(30)  DEFAULT NULL COMMENT 'Truck plate number',
    `driver_name`      VARCHAR(100) DEFAULT NULL COMMENT 'Driver name',
    `outbound_order_id` INT(11)     DEFAULT NULL COMMENT 'FK outbound_orders.id',
    `status`           VARCHAR(20)  NOT NULL DEFAULT 'Pending' COMMENT 'Pending|Dispatched|Exported',
    `operator_id`      INT(11)      DEFAULT NULL COMMENT 'FK users.id — who dispatched',
    `exported_at`      TIMESTAMP    NULL DEFAULT NULL,
    `dispatched_at`    TIMESTAMP    NULL DEFAULT NULL,
    `created_at`       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_gi_number` (`gi_number`),
    FOREIGN KEY (`outbound_order_id`) REFERENCES `outbound_orders`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`operator_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    INDEX `idx_gi_lpn` (`lpn_code`),
    INDEX `idx_gi_do` (`do_number`),
    INDEX `idx_gi_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Good Issue dispatch records + export payload';

CREATE TABLE IF NOT EXISTS `discrepancies` (
    `id`               INT(11)      AUTO_INCREMENT PRIMARY KEY,
    `picklist_item_id` INT(11)      DEFAULT NULL,
    `type`             VARCHAR(50)  NOT NULL COMMENT 'Missing|Wrong Qty|Wrong Product|Damaged|Expired',
    `description`      TEXT,
    `discrepancy_qty`  DECIMAL(10,2) DEFAULT 0,
    `status`           VARCHAR(20)  NOT NULL DEFAULT 'Open' COMMENT 'Open|Resolved|Dismissed',
    `operator_id`      INT(11)      DEFAULT NULL,
    `reported_at`      TIMESTAMP    NULL DEFAULT NULL,
    `created_at`       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`picklist_item_id`) REFERENCES `picklist_items`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`operator_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    INDEX `idx_disco_pli` (`picklist_item_id`),
    INDEX `idx_disco_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Pick/dispatch discrepancy log';

CREATE TABLE IF NOT EXISTS `audit_log` (
    `id`               INT(11)      AUTO_INCREMENT PRIMARY KEY,
    `module`           VARCHAR(50)  NOT NULL COMMENT 'Module name (picklist_item, outbound_order, stock_location)',
    `module_id`        INT(11)      NOT NULL COMMENT 'Entity ID',
    `action`           VARCHAR(100) NOT NULL COMMENT 'e.g. PICK_CONFIRMED, DISPATCH',
    `user_id`          INT(11)      DEFAULT NULL COMMENT 'FK users.id',
    `details`          JSON         DEFAULT NULL COMMENT 'Action details as JSON',
    `created_at`       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    INDEX `idx_al_module` (`module`, `module_id`),
    INDEX `idx_al_action` (`action`),
    INDEX `idx_al_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Structured audit trail for outbound actions';

-- =============================================================================
-- 3. INDEXES (conditional)
-- =============================================================================

CALL _add_idx_if_missing('stock_locations', 'idx_sl_qty_reserved', 'qty_reserved');
CALL _add_idx_if_missing('outbound_items', 'idx_ob_lpn', 'lpn_code');
CALL _add_idx_if_missing('outbound_items', 'idx_ob_sl_id', 'stock_location_id');
CALL _add_idx_if_missing('picklist_items', 'idx_pi_lpn', 'lpn_code');
CALL _add_idx_if_missing('picklist_items', 'idx_pi_pick_ref', 'pick_reference');

-- =============================================================================
-- 4. ENUM EXTENSIONS and missing columns discovered during verification
-- =============================================================================

-- Extend picklist_items.status ENUM to include 'In Progress', 'Staged', 'Cancelled'
-- (original ENUM only had 'Pending','Picked','Verified')
CALL _add_col_if_missing('picklist_items', 'assigned_to',
    "INT(11) DEFAULT NULL COMMENT 'FK operators.id — operator assigned to pick' AFTER `pallet_seq`");
CALL _add_col_if_missing('picklist_items', 'picked_by',
    "INT(11) DEFAULT NULL COMMENT 'FK operators.id — operator who picked' AFTER `picked_at`");
CALL _add_col_if_missing('picklist_items', 'qty_picked',
    "DECIMAL(10,2) DEFAULT NULL COMMENT 'Actual qty picked' AFTER `quantity`");
CALL _add_col_if_missing('picklist_items', 'outbound_item_id',
    "INT(11) DEFAULT NULL COMMENT 'FK outbound_items.id' AFTER `picklist_id`");

-- Extend stock_locations.status ENUM to include 'Consolidated', 'Dispatched'
-- (original ENUM only had 'Available','Reserved','Picked','Empty')

-- Cleanup helper procedures
DROP PROCEDURE IF EXISTS _add_col_if_missing;
DROP PROCEDURE IF EXISTS _add_idx_if_missing;

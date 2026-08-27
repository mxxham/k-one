-- =============================================================================
-- hotfix_024_v2_features.sql
-- Ports v2-exclusive WMS features into the v1 (PHP/MySQL) schema.
-- Idempotent: safe to run multiple times. Matches hotfix_017 style.
-- Requires MySQL 8.0.29+ (ADD COLUMN IF NOT EXISTS).
-- =============================================================================

-- =============================================================================
-- 1. Department-Based Roles (Phase 0)
-- =============================================================================
ALTER TABLE `users`
    ADD COLUMN IF NOT EXISTS `department` ENUM('inbound','outbound','inventory','ops','all')
        NOT NULL DEFAULT 'all' COMMENT 'Department: inbound|outbound|inventory|ops|all'
        AFTER `role`;

-- =============================================================================
-- 2. Stock Hold / Quarantine (S19)
-- =============================================================================
ALTER TABLE `stock`
    ADD COLUMN IF NOT EXISTS `hold_status` VARCHAR(20) NOT NULL DEFAULT 'available'
        COMMENT 'available|on_hold|quarantine|damaged' AFTER `stock_status`,
    ADD COLUMN IF NOT EXISTS `hold_reason` TEXT NULL AFTER `hold_status`,
    ADD COLUMN IF NOT EXISTS `hold_by` INT(11) NULL AFTER `hold_reason`,
    ADD COLUMN IF NOT EXISTS `hold_at` TIMESTAMP NULL AFTER `hold_by`;

ALTER TABLE `stock_ledger`
    MODIFY COLUMN `transaction_type` ENUM('IN','OUT','ADJUSTMENT','TRANSFER','TRANSFER_IN','TRANSFER_OUT','HOLD','RELEASE')
        NOT NULL COMMENT 'HOLD/RELEASE added for stock hold (S19)';

-- =============================================================================
-- 3. Pick-face / Replenishment (S22)
-- =============================================================================
CREATE TABLE IF NOT EXISTS `pick_face_targets` (
    `id`          INT(11) AUTO_INCREMENT PRIMARY KEY,
    `location_id` INT(11) NOT NULL COMMENT 'FK location_master.id (pick face / Level A bin)',
    `product_id`  INT(11) NOT NULL COMMENT 'FK products.id',
    `min_qty`     DECIMAL(12,4) NOT NULL DEFAULT 0,
    `max_qty`     DECIMAL(12,4) NOT NULL DEFAULT 0,
    `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_target` (`location_id`, `product_id`),
    FOREIGN KEY (`location_id`) REFERENCES `location_master`(`id`),
    FOREIGN KEY (`product_id`)  REFERENCES `products`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Replenishment pick-face targets (S22)';

ALTER TABLE `bin_transfers`
    ADD COLUMN IF NOT EXISTS `transfer_type` VARCHAR(20) NOT NULL DEFAULT 'MANUAL'
        COMMENT 'MANUAL|REPLENISHMENT|BREAKDOWN' AFTER `reason`,
    ADD COLUMN IF NOT EXISTS `pick_face_target_id` INT(11) NULL
        COMMENT 'FK pick_face_targets.id for replenishment transfers' AFTER `transfer_type`,
    ADD COLUMN IF NOT EXISTS `is_breakdown` TINYINT(1) NOT NULL DEFAULT 0
        COMMENT '1 = decomposed bulk pallet into pick-face qty' AFTER `pick_face_target_id`;

-- =============================================================================
-- 4. Wave Planning (S23)
-- =============================================================================
CREATE TABLE IF NOT EXISTS `waves` (
    `id`           INT(11) AUTO_INCREMENT PRIMARY KEY,
    `wave_number`  VARCHAR(50) UNIQUE NOT NULL COMMENT 'WAV-YYYYMM-NNNN',
    `status`       ENUM('Planning','Active','Completed','Cancelled') NOT NULL DEFAULT 'Planning',
    `carrier`      VARCHAR(100) DEFAULT NULL,
    `cutoff_time`  DATETIME DEFAULT NULL,
    `created_by`   INT(11) NULL,
    `created_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    INDEX `idx_wave_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Wave planning headers (S23)';

CREATE TABLE IF NOT EXISTS `wave_orders` (
    `id`                INT(11) AUTO_INCREMENT PRIMARY KEY,
    `wave_id`           INT(11) NOT NULL,
    `outbound_order_id` INT(11) NOT NULL,
    `created_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_wave_order` (`wave_id`, `outbound_order_id`),
    FOREIGN KEY (`wave_id`)           REFERENCES `waves`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`outbound_order_id`) REFERENCES `outbound_orders`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Wave -> outbound order memberships (S23)';

ALTER TABLE `picklists`
    ADD COLUMN IF NOT EXISTS `wave_id` INT(11) NULL COMMENT 'FK waves.id (wave-consolidated picklist)' AFTER `outbound_order_id`;

ALTER TABLE `picklists`
    MODIFY COLUMN `outbound_order_id` INT(11) NULL COMMENT 'NULL when wave picklist (wave_id set)';

-- =============================================================================
-- 5. ASN — Advance Shipping Notice (S24)
-- =============================================================================
CREATE TABLE IF NOT EXISTS `asn` (
    `id`                     INT(11) AUTO_INCREMENT PRIMARY KEY,
    `asn_number`             VARCHAR(50) UNIQUE NOT NULL COMMENT 'ASN-YYYYMM-NNNN',
    `supplier_name`          VARCHAR(200) DEFAULT NULL,
    `supplier_reference`     VARCHAR(100) DEFAULT NULL,
    `expected_arrival_date`  DATE DEFAULT NULL,
    `status`                 ENUM('Pending','Received','Cancelled') NOT NULL DEFAULT 'Pending',
    `notes`                  TEXT,
    `created_by`             INT(11) NULL,
    `created_at`             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    INDEX `idx_asn_status` (`status`),
    INDEX `idx_asn_arrival` (`expected_arrival_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Advance Shipping Notices (S24)';

CREATE TABLE IF NOT EXISTS `asn_items` (
    `id`             INT(11) AUTO_INCREMENT PRIMARY KEY,
    `asn_id`         INT(11) NOT NULL,
    `product_id`     INT(11) NOT NULL,
    `expected_qty`   DECIMAL(12,4) NOT NULL DEFAULT 0,
    `uom`            VARCHAR(20) DEFAULT 'Drum',
    `batch_number`   VARCHAR(100) DEFAULT NULL,
    `exp_date`       DATE DEFAULT NULL,
    `created_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`asn_id`)     REFERENCES `asn`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`product_id`) REFERENCES `products`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='ASN expected items (S24)';

ALTER TABLE `inbound_orders`
    ADD COLUMN IF NOT EXISTS `asn_id` INT(11) NULL COMMENT 'FK asn.id — inbound created from ASN' AFTER `do_number`;

-- =============================================================================
-- 6. ABC Analysis / Velocity-Based Ranking (S26)
-- =============================================================================
ALTER TABLE `products`
    ADD COLUMN IF NOT EXISTS `velocity_class` CHAR(1) NULL COMMENT 'A|B|C — computed by abc::recompute' AFTER `reorder_level`,
    ADD COLUMN IF NOT EXISTS `velocity_class_at` TIMESTAMP NULL AFTER `velocity_class`;

-- =============================================================================
-- 7. Cycle Count Scheduling (S28)
-- =============================================================================
CREATE TABLE IF NOT EXISTS `cycle_count_schedules` (
    `id`              INT(11) AUTO_INCREMENT PRIMARY KEY,
    `schedule_name`   VARCHAR(150) NOT NULL,
    `frequency`       ENUM('weekly','monthly','quarterly') NOT NULL DEFAULT 'monthly',
    `scope_type`      ENUM('full','location','velocity') NOT NULL DEFAULT 'full',
    `scope_locations` TEXT NULL COMMENT 'JSON array of location codes (scope_type=location)',
    `velocity_class`  CHAR(1) NULL COMMENT 'A|B|C (scope_type=velocity)',
    `next_run_date`   DATE NOT NULL,
    `is_active`       TINYINT(1) NOT NULL DEFAULT 1,
    `created_by`      INT(11) NULL,
    `created_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    INDEX `idx_cc_due` (`is_active`, `next_run_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Cycle-count scheduling (S28)';

ALTER TABLE `stock_take`
    ADD COLUMN IF NOT EXISTS `schedule_id` INT(11) NULL COMMENT 'FK cycle_count_schedules.id' AFTER `notes`,
    ADD COLUMN IF NOT EXISTS `scope_locations` TEXT NULL AFTER `schedule_id`,
    ADD COLUMN IF NOT EXISTS `scope_type` VARCHAR(20) DEFAULT 'full' AFTER `scope_locations`;

-- Widen stock_take.status so CycleCount / existing class flow works
ALTER TABLE `stock_take`
    MODIFY COLUMN `status` ENUM('Draft','In Progress','Counting','Review','Adjusted','Completed','Cancelled')
        NOT NULL DEFAULT 'Draft';

-- =============================================================================
-- 8. Cross-Docking (S25)
-- =============================================================================
ALTER TABLE `inbound_items`
    ADD COLUMN IF NOT EXISTS `cross_dock_outbound_order_id` INT(11) NULL
        COMMENT 'FK outbound_orders.id — route to outbound without putaway (S25)' AFTER `in_process_status`;

-- =============================================================================
-- 9. Putaway Intelligence (S33–S41)
-- =============================================================================
ALTER TABLE `location_master`
    ADD COLUMN IF NOT EXISTS `level` VARCHAR(2) NULL COMMENT 'A..E level (row_name alias)' AFTER `zone`,
    ADD COLUMN IF NOT EXISTS `zone_code` VARCHAR(30) NULL COMMENT 'PICK_FAST|RESERVE|BULK|...' AFTER `level`,
    ADD COLUMN IF NOT EXISTS `is_pick_face` TINYINT(1) NOT NULL DEFAULT 0
        COMMENT '1 = Level A pick face (row_name = A)' AFTER `zone_code`,
    ADD COLUMN IF NOT EXISTS `max_weight_kg` DECIMAL(10,2) NULL AFTER `is_pick_face`,
    ADD COLUMN IF NOT EXISTS `max_height_cm` DECIMAL(10,2) NULL AFTER `max_weight_kg`,
    ADD COLUMN IF NOT EXISTS `equipment_accessible` TINYINT(1) NOT NULL DEFAULT 0
        COMMENT '1 = heavy equipment can reach this bin' AFTER `max_height_cm`;

CREATE TABLE IF NOT EXISTS `zones` (
    `id`         INT(11) AUTO_INCREMENT PRIMARY KEY,
    `zone_code`  VARCHAR(30) UNIQUE NOT NULL,
    `zone_name`  VARCHAR(100) DEFAULT NULL,
    `zone_type`  VARCHAR(30) NOT NULL DEFAULT 'RESERVE'
                 COMMENT 'PICK_FAST|RESERVE|BULK|QUARANTINE|STAGING|UNALLOCATED',
    `priority`   INT(11) NOT NULL DEFAULT 100,
    `is_active`  TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Putaway zone definitions (S33)';

INSERT INTO `zones` (`zone_code`, `zone_name`, `zone_type`, `priority`, `is_active`) VALUES
    ('PICK_FAST',   'Pick Fast (Level A)',    'PICK_FAST',   1,   1),
    ('RESERVE',     'Reserve (Levels B–E)',   'RESERVE',     10,  1),
    ('BULK',        'Bulk Storage',           'BULK',        15,  1),
    ('STAGING',     'Staging / Cross-Dock',   'STAGING',     90,  1),
    ('UNALLOCATED', 'Unallocated',            'UNALLOCATED', 99,  1),
    ('QUARANTINE',  'Quarantine',             'QUARANTINE',  100, 1)
ON DUPLICATE KEY UPDATE `zone_name` = VALUES(`zone_name`), `priority` = VALUES(`priority`);

CREATE TABLE IF NOT EXISTS `uom_physical_limits` (
    `uom_type`           VARCHAR(20) PRIMARY KEY,
    `min_level`          VARCHAR(2) NOT NULL DEFAULT 'A',
    `max_level`          VARCHAR(2) NOT NULL DEFAULT 'E',
    `allow_pick_face`    TINYINT(1) NOT NULL DEFAULT 1,
    `max_weight_kg`      DECIMAL(10,2) NULL,
    `max_height_cm`      DECIMAL(10,2) NULL,
    `requires_equipment` TINYINT(1) NOT NULL DEFAULT 0,
    `updated_at`         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Physical storage limits per UOM (S33)';

INSERT INTO `uom_physical_limits`
    (`uom_type`, `min_level`, `max_level`, `allow_pick_face`, `requires_equipment`) VALUES
    ('Drum',    'A', 'E', 1, 1),
    ('Carton',  'A', 'E', 1, 0),
    ('CAR',     'A', 'E', 1, 0),
    ('Pail',    'A', 'E', 1, 0),
    ('EA',      'A', 'E', 1, 0),
    ('Bags',    'A', 'E', 1, 0),
    ('Fluidbag','A', 'D', 1, 1),
    ('IBC',     'A', 'C', 0, 1)
ON DUPLICATE KEY UPDATE `max_level` = VALUES(`max_level`), `allow_pick_face` = VALUES(`allow_pick_face`),
                          `requires_equipment` = VALUES(`requires_equipment`);

CREATE TABLE IF NOT EXISTS `product_putaway_rules` (
    `product_id`         INT(11) PRIMARY KEY,
    `preferred_zone_code` VARCHAR(30) NOT NULL DEFAULT 'RESERVE',
    `max_level`          VARCHAR(2) NULL,
    `allow_pick_face`    TINYINT(1) NULL,
    `full_pallet_to_pick` TINYINT(1) NOT NULL DEFAULT 0,
    `min_pick_face_qty`  DECIMAL(12,4) NOT NULL DEFAULT 0,
    `max_pick_face_qty`  DECIMAL(12,4) NOT NULL DEFAULT 0,
    `consolidate`        TINYINT(1) NOT NULL DEFAULT 1,
    `updated_at`         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`product_id`) REFERENCES `products`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Per-product putaway rules (S33)';

CREATE TABLE IF NOT EXISTS `zone_aisles` (
    `id`         INT(11) AUTO_INCREMENT PRIMARY KEY,
    `zone_code`  VARCHAR(30) NOT NULL,
    `aisle`      VARCHAR(10) NOT NULL,
    `min_level`  VARCHAR(2) NOT NULL DEFAULT 'A',
    `max_level`  VARCHAR(2) NOT NULL DEFAULT 'E',
    `is_active`  TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_zone_aisle_levels` (`zone_code`, `aisle`, `min_level`, `max_level`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Zone -> aisle + level-range bindings (S33)';

INSERT IGNORE INTO `zone_aisles` (`zone_code`, `aisle`, `min_level`, `max_level`, `is_active`)
SELECT 'PICK_FAST', a.aisle, 'A', 'A', 1
FROM (SELECT DISTINCT aisle FROM location_master WHERE aisle IS NOT NULL AND aisle NOT IN ('QUA','UNA','STG')) a;
INSERT IGNORE INTO `zone_aisles` (`zone_code`, `aisle`, `min_level`, `max_level`, `is_active`)
SELECT 'RESERVE', a.aisle, 'B', 'E', 1
FROM (SELECT DISTINCT aisle FROM location_master WHERE aisle IS NOT NULL AND aisle NOT IN ('QUA','UNA','STG')) a;

-- Backfill location_master level / is_pick_face / zone_code from row_name
UPDATE `location_master`
SET `level`        = UPPER(TRIM(COALESCE(`row_name`, ''))),
    `is_pick_face` = (UPPER(TRIM(COALESCE(`row_name`, ''))) = 'A')
WHERE `level` IS NULL;

UPDATE `location_master` lm
LEFT JOIN `zones` z ON z.zone_type = CASE
    WHEN lm.zone_code IS NOT NULL THEN z.zone_type
    WHEN UPPER(COALESCE(lm.zone,'')) IN ('QUARANTINE') THEN 'QUARANTINE'
    WHEN UPPER(COALESCE(lm.zone,'')) IN ('STAGING','UNALLOCATED') THEN UPPER(COALESCE(lm.zone,''))
    WHEN UPPER(COALESCE(lm.row_name,'')) = 'A' THEN 'PICK_FAST'
    ELSE 'RESERVE'
END
SET lm.zone_code = COALESCE(lm.zone_code, z.zone_code)
WHERE lm.zone_code IS NULL AND z.zone_code IS NOT NULL;

-- =============================================================================
-- 10. Putaway Location Blocking (S40)
-- =============================================================================
CREATE TABLE IF NOT EXISTS `putaway_location_blocks` (
    `id`            INT(11) AUTO_INCREMENT PRIMARY KEY,
    `scope_type`    ENUM('aisle','location') NOT NULL,
    `aisle_prefix`  VARCHAR(10) NULL COMMENT 'Block whole aisle(s) by prefix (scope_type=aisle)',
    `location_code` VARCHAR(20) NULL COMMENT 'Block exact bin (scope_type=location)',
    `reason`        TEXT NOT NULL,
    `is_active`     TINYINT(1) NOT NULL DEFAULT 1,
    `blocked_by`    INT(11) NULL,
    `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`blocked_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    INDEX `idx_block_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Putaway location blocks — soft-delete via is_active (S40)';

-- =============================================================================
-- 11. Putaway Task Queue + LPN (S42, S49)
-- =============================================================================
CREATE TABLE IF NOT EXISTS `putaway_tasks` (
    `id`             INT(11) AUTO_INCREMENT PRIMARY KEY,
    `task_number`    VARCHAR(50) UNIQUE NOT NULL COMMENT 'PKA-YYYYMMDD-NNNN',
    `inbound_order_id` INT(11) NOT NULL,
    `status`         ENUM('Open','In Progress','Completed','Cancelled') NOT NULL DEFAULT 'Open',
    `assigned_to`    INT(11) NULL COMMENT 'Forklift operator (team member 1)',
    `team_partner`   INT(11) NULL COMMENT 'Checklist partner (team member 2)',
    `created_by`     INT(11) NULL,
    `completed_by`   INT(11) NULL,
    `completed_at`   TIMESTAMP NULL,
    `created_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`inbound_order_id`) REFERENCES `inbound_orders`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`assigned_to`)      REFERENCES `users`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`team_partner`)     REFERENCES `users`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`created_by`)       REFERENCES `users`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`completed_by`)     REFERENCES `users`(`id`) ON DELETE SET NULL,
    INDEX `idx_pt_status` (`status`),
    INDEX `idx_pt_inbound` (`inbound_order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Putaway task queue (S42)';

CREATE TABLE IF NOT EXISTS `putaway_task_items` (
    `id`                    INT(11) AUTO_INCREMENT PRIMARY KEY,
    `task_id`               INT(11) NOT NULL,
    `inbound_item_id`       INT(11) NOT NULL,
    `product_id`            INT(11) NOT NULL,
    `batch_number`          VARCHAR(100) DEFAULT NULL,
    `quantity`              DECIMAL(12,4) NOT NULL DEFAULT 0,
    `uom`                   VARCHAR(20) DEFAULT 'Drum',
    `from_location`         VARCHAR(20) DEFAULT 'STAGING',
    `suggested_location`    VARCHAR(20) DEFAULT NULL COMMENT 'Target bin from putaway recommendation',
    `lpn_code`              VARCHAR(50) DEFAULT NULL COMMENT 'LPN label barcode (S49)',
    `pallet_seq`            SMALLINT(6) DEFAULT 1,
    `status`                ENUM('Pending','Confirmed','Cancelled') NOT NULL DEFAULT 'Pending',
    `confirmed_by`          INT(11) NULL,
    `confirmed_at`          TIMESTAMP NULL,
    `scan_override_reason`  TEXT NULL COMMENT 'Mismatch reason when scanning override (S49)',
    `created_at`            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`task_id`)         REFERENCES `putaway_tasks`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`inbound_item_id`) REFERENCES `inbound_items`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`product_id`)      REFERENCES `products`(`id`),
    FOREIGN KEY (`confirmed_by`)    REFERENCES `users`(`id`) ON DELETE SET NULL,
    INDEX `idx_pti_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Putaway task items — one row per pallet (S42/S49)';

ALTER TABLE `stock_locations`
    ADD COLUMN IF NOT EXISTS `lpn_code` VARCHAR(50) NULL
        COMMENT 'LPN label barcode per pallet (S49)' AFTER `batch_number`;

-- =============================================================================
-- 12. Scan override audit (S21) — reuse activity_log; add convenience index
-- =============================================================================
ALTER TABLE `activity_log`
    ADD COLUMN IF NOT EXISTS `scan_override_reason` TEXT NULL
        COMMENT 'Barcode scan mismatch reason (S21)' AFTER `ip_address`;

-- =============================================================================
-- Indexes
-- =============================================================================
CREATE INDEX IF NOT EXISTS `idx_stock_hold`  ON `stock`(`hold_status`);
CREATE INDEX IF NOT EXISTS `idx_sl_lpn`      ON `stock_locations`(`lpn_code`);
CREATE INDEX IF NOT EXISTS `idx_ib_crossdock` ON `inbound_items`(`cross_dock_outbound_order_id`);
CREATE INDEX IF NOT EXISTS `idx_pkl_wave`     ON `picklists`(`wave_id`);
CREATE INDEX IF NOT EXISTS `idx_pt_assigned`  ON `putaway_tasks`(`assigned_to`);
CREATE INDEX IF NOT EXISTS `idx_pt_partner`   ON `putaway_tasks`(`team_partner`);
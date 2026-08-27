-- =============================================================================
-- 007 — Zoning, UOM physical limits, Putaway rules & pallet conversion
-- Source: D:\K-one-v2\apps\api\src\database\migrations\007-zoning-putaway-replenishment.sql
-- Translations:
--   PG `UPDATE ... FROM` (2×) → MariaDB multi-table UPDATE (JOIN ... SET);
--   `ON CONFLICT (zone_code) DO NOTHING` → INSERT IGNORE;
--   `ON CONFLICT (uom_type) DO NOTHING` → INSERT IGNORE;
--   BIGSERIAL → INT(11) AUTO_INCREMENT.
-- =============================================================================

-- 1. ZONES master
CREATE TABLE IF NOT EXISTS `zones` (
    `id`         INT(11) AUTO_INCREMENT PRIMARY KEY,
    `zone_code`  VARCHAR(20) UNIQUE NOT NULL,
    `zone_name`  VARCHAR(100) NOT NULL,
    `zone_type`  VARCHAR(20) NOT NULL DEFAULT 'RESERVE',
    `priority`   INT NOT NULL DEFAULT 10,
    `is_active`  TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `chk_zones_zone_type` CHECK (`zone_type` IN ('PICK_FAST','RESERVE','BULK','QUARANTINE','STAGING','UNALLOCATED'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE INDEX IF NOT EXISTS `idx_zones_type` ON `zones`(`zone_type`);

-- 2. location_master — level + zone columns
ALTER TABLE `location_master`
  ADD COLUMN IF NOT EXISTS `level` VARCHAR(2) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `level_height` SMALLINT DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `zone_code` VARCHAR(20) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `is_pick_face` TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `max_weight_kg` DECIMAL(10,2) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `max_height_cm` DECIMAL(10,2) DEFAULT NULL;

UPDATE `location_master`
   SET `level` = `row_name`,
       `level_height` = CASE `row_name`
                         WHEN 'A' THEN 1 WHEN 'B' THEN 2 WHEN 'C' THEN 3
                         WHEN 'D' THEN 4 WHEN 'E' THEN 5 END,
       `is_pick_face` = CASE WHEN `row_name` = 'A' THEN 1 ELSE 0 END
 WHERE `row_name` IS NOT NULL
   AND (`level` IS NULL OR `level_height` IS NULL);

UPDATE `location_master`
   SET `zone_code` = CASE `zone`
                      WHEN 'Quarantine' THEN 'QUARANTINE'
                      WHEN 'Staging'    THEN 'STAGING'
                      WHEN 'Unallocated' THEN 'UNALLOCATED'
                      ELSE NULL END
 WHERE `zone_code` IS NULL;

-- 3. UOM physical limits
CREATE TABLE IF NOT EXISTS `uom_physical_limits` (
    `uom_type`           VARCHAR(20) PRIMARY KEY,
    `min_level`          VARCHAR(2) NOT NULL DEFAULT 'A',
    `max_level`          VARCHAR(2) NOT NULL DEFAULT 'E',
    `allow_pick_face`    TINYINT(1) NOT NULL DEFAULT 1,
    `max_weight_kg`      DECIMAL(10,2) DEFAULT NULL,
    `max_height_cm`      DECIMAL(10,2) DEFAULT NULL,
    `requires_equipment` TINYINT(1) NOT NULL DEFAULT 0,
    `updated_at`         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `chk_uom_physical_limits_uom_type` CHECK (`uom_type` IN ('Drum','Carton','Pail','EA','Bags'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. product_putaway_rules
CREATE TABLE IF NOT EXISTS `product_putaway_rules` (
    `product_id`          INT(11) PRIMARY KEY,
    `preferred_zone_code` VARCHAR(20) NOT NULL DEFAULT 'RESERVE',
    `max_level`           VARCHAR(2) DEFAULT NULL,
    `allow_pick_face`     TINYINT(1) DEFAULT NULL,
    `full_pallet_to_pick` TINYINT(1) NOT NULL DEFAULT 0,
    `min_pick_face_qty`   DECIMAL(10,2) NOT NULL DEFAULT 0,
    `max_pick_face_qty`   DECIMAL(10,2) NOT NULL DEFAULT 0,
    `consolidate`         TINYINT(1) NOT NULL DEFAULT 1,
    `updated_at`          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`product_id`) REFERENCES `products`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. bin_transfers — movement origin classification
ALTER TABLE `bin_transfers`
  ADD COLUMN IF NOT EXISTS `transfer_type` VARCHAR(20) NOT NULL DEFAULT 'MANUAL',
  ADD COLUMN IF NOT EXISTS `pick_face_target_id` INT(11) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `is_breakdown` TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE `bin_transfers` DROP CONSTRAINT IF EXISTS `chk_bin_transfers_transfer_type`;
ALTER TABLE `bin_transfers` ADD CONSTRAINT `chk_bin_transfers_transfer_type`
  CHECK (`transfer_type` IN ('MANUAL','PUTAWAY','REPLENISHMENT','MOVE'));
CREATE INDEX IF NOT EXISTS `idx_bin_transfers_type` ON `bin_transfers`(`transfer_type`);

-- 6. stock_locations — pallet function (RESERVE / PICK_FACE / MIXED)
ALTER TABLE `stock_locations`
  ADD COLUMN IF NOT EXISTS `pallet_function` VARCHAR(20) NOT NULL DEFAULT 'RESERVE';
ALTER TABLE `stock_locations` DROP CONSTRAINT IF EXISTS `chk_stock_locations_pallet_function`;
ALTER TABLE `stock_locations` ADD CONSTRAINT `chk_stock_locations_pallet_function`
  CHECK (`pallet_function` IN ('RESERVE','PICK_FACE','MIXED'));

UPDATE `stock_locations` sl
  JOIN `location_master` lm ON lm.location_code = sl.location_code
   SET sl.pallet_function = 'PICK_FACE'
 WHERE lm.is_pick_face = 1
   AND sl.pallet_function = 'RESERVE';

-- 7. Seed zones + UOM limits (INSERT IGNORE replaces ON CONFLICT DO NOTHING)
INSERT IGNORE INTO `zones` (`zone_code`, `zone_name`, `zone_type`, `priority`) VALUES
  ('PICK_FAST',   'Pick-Fast (Level A)', 'PICK_FAST', 1),
  ('RESERVE',     'Reserve / Bulk (Level B-E)', 'RESERVE', 10),
  ('BULK',        'Bulk Storage', 'BULK', 15),
  ('QUARANTINE',  'Quarantine', 'QUARANTINE', 100),
  ('STAGING',     'Staging', 'STAGING', 90),
  ('UNALLOCATED', 'Unallocated', 'UNALLOCATED', 99);

INSERT IGNORE INTO `uom_physical_limits`
  (`uom_type`, `min_level`, `max_level`, `allow_pick_face`, `max_weight_kg`, `max_height_cm`, `requires_equipment`)
VALUES
  ('Drum',   'A', 'E', 1, 1000, 200, 1),
  ('Carton', 'A', 'E', 1,  300, 180, 0),
  ('Pail',   'A', 'E', 1,  400, 160, 0),
  ('EA',     'A', 'E', 1,  NULL, NULL, 0),
  ('Bags',   'A', 'E', 1,  NULL, NULL, 0);
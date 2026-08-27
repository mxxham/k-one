-- =============================================================================
-- 008 — Zoning page upgrade
-- Source: D:\K-one-v2\apps\api\src\database\migrations\008-zoning-upgrade.sql
-- Translations:
--   CHECK widen via DROP CONSTRAINT + ADD CONSTRAINT (idempotent);
--   PG `FROM (VALUES ...) AS v(a)` (2×) → UNION ALL derived table;
--   `ON CONFLICT DO NOTHING` → `ON DUPLICATE KEY UPDATE id = id`;
--   NOW() → CURRENT_TIMESTAMP.
-- =============================================================================

-- 1. Widen UOM master lists (Drum/Carton/CAR/Pail/EA/Bags/Fluidbag/IBC)
ALTER TABLE `uom_physical_limits` DROP CONSTRAINT IF EXISTS `chk_uom_physical_limits_uom_type`;
ALTER TABLE `uom_physical_limits` ADD CONSTRAINT `chk_uom_physical_limits_uom_type`
  CHECK (`uom_type` IN ('Drum','Carton','CAR','Pail','EA','Bags','Fluidbag','IBC'));

ALTER TABLE `products` DROP CONSTRAINT IF EXISTS `chk_products_uom_type`;
ALTER TABLE `products` ADD CONSTRAINT `chk_products_uom_type`
  CHECK (`uom_type` IN ('Drum','Carton','CAR','Pail','EA','Bags','Fluidbag','IBC'));

-- 2. Equipment accessibility per location
ALTER TABLE `location_master`
  ADD COLUMN IF NOT EXISTS `equipment_accessible` TINYINT(1) NOT NULL DEFAULT 0;

-- 3. Zone ↔ aisle/level bindings
CREATE TABLE IF NOT EXISTS `zone_aisles` (
    `id`         INT(11) AUTO_INCREMENT PRIMARY KEY,
    `zone_code`  VARCHAR(20) NOT NULL,
    `aisle`      VARCHAR(10) NOT NULL,
    `min_level`  VARCHAR(2)  NOT NULL DEFAULT 'A',
    `max_level`  VARCHAR(2)  NOT NULL DEFAULT 'E',
    `is_active`  TINYINT(1)  NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_zone_aisles` (`zone_code`, `aisle`, `min_level`, `max_level`),
    FOREIGN KEY (`zone_code`) REFERENCES `zones`(`zone_code`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE INDEX IF NOT EXISTS `idx_zone_aisles_zone`  ON `zone_aisles`(`zone_code`);
CREATE INDEX IF NOT EXISTS `idx_zone_aisles_aisle` ON `zone_aisles`(`aisle`);

-- 4. Seed UOM limits for the real UOMs (INSERT IGNORE)
INSERT IGNORE INTO `uom_physical_limits`
  (`uom_type`, `min_level`, `max_level`, `allow_pick_face`, `max_weight_kg`, `max_height_cm`, `requires_equipment`, `updated_at`)
VALUES
  ('CAR',      'A', 'E', 1,  300, 180, 0, CURRENT_TIMESTAMP),
  ('Fluidbag', 'A', 'D', 1,  800, 200, 1, CURRENT_TIMESTAMP),
  ('IBC',      'A', 'C', 0, 1500, 220, 1, CURRENT_TIMESTAMP);

UPDATE `uom_physical_limits` SET `requires_equipment` = 1 WHERE `uom_type` = 'Drum' AND `requires_equipment` = 0;

-- 5. Default zone bindings (PICK_FAST owns Level A; RESERVE owns Levels B–E; aisles CA–CG)
INSERT INTO `zone_aisles` (`zone_code`, `aisle`, `min_level`, `max_level`)
SELECT 'PICK_FAST', a, 'A', 'A'
FROM (
  SELECT 'CA' AS a UNION ALL SELECT 'CB' UNION ALL SELECT 'CC' UNION ALL SELECT 'CD'
  UNION ALL SELECT 'CE' UNION ALL SELECT 'CF' UNION ALL SELECT 'CG'
) v
ON DUPLICATE KEY UPDATE `id` = `id`;

INSERT INTO `zone_aisles` (`zone_code`, `aisle`, `min_level`, `max_level`)
SELECT 'RESERVE', a, 'B', 'E'
FROM (
  SELECT 'CA' AS a UNION ALL SELECT 'CB' UNION ALL SELECT 'CC' UNION ALL SELECT 'CD'
  UNION ALL SELECT 'CE' UNION ALL SELECT 'CF' UNION ALL SELECT 'CG'
) v
ON DUPLICATE KEY UPDATE `id` = `id`;
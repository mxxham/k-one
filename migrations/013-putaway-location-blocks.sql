-- =============================================================================
-- 013 — Putaway Location Blocking
-- Source: D:\K-one-v2\apps\api\src\database\migrations\013-putaway-location-blocks.sql
-- Translations (MariaDB 10.4):
--   BOOLEAN → TINYINT(1);
--   PG partial unique indexes (WHERE is_active) → generated-column emulation:
--     a STORED generated column yields NULL when the row is inactive, and
--     MariaDB unique indexes ignore NULLs → same "one active block per target"
--     invariant as the PG partial unique index.
--   CHECK constraint chk_putaway_block_target kept verbatim.
-- =============================================================================

CREATE TABLE IF NOT EXISTS `putaway_location_blocks` (
    `id`            INT(11) AUTO_INCREMENT PRIMARY KEY,
    `scope_type`    VARCHAR(10) NOT NULL,
    `aisle_prefix`  VARCHAR(10) DEFAULT NULL,
    `location_code` VARCHAR(20) DEFAULT NULL,
    `reason`        TEXT NOT NULL,
    `is_active`     TINYINT(1) NOT NULL DEFAULT 1,
    `blocked_by`    INT(11) DEFAULT NULL,
    `blocked_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `chk_putaway_block_target` CHECK (
      (`scope_type` = 'aisle'    AND `aisle_prefix`  IS NOT NULL AND `location_code` IS NULL) OR
      (`scope_type` = 'location' AND `location_code` IS NOT NULL AND `aisle_prefix`  IS NULL)
    ),
    CONSTRAINT `chk_putaway_block_scope_type` CHECK (`scope_type` IN ('aisle','location'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Fast lookups during recommend / validate / rack render.
CREATE INDEX IF NOT EXISTS `idx_putaway_blocks_scope_active`
  ON `putaway_location_blocks`(`scope_type`, `is_active`);

-- Live-DB ensure: the table pre-dates the port (hotfix era), so the CREATE
-- above is a no-op and `blocked_at` must be added idempotently.
ALTER TABLE `putaway_location_blocks`
  ADD COLUMN IF NOT EXISTS `blocked_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP;

-- Only ONE active block per target (partial-unique emulation via generated columns).
ALTER TABLE `putaway_location_blocks`
  ADD COLUMN `active_aisle_key` VARCHAR(10) AS (
    CASE WHEN `is_active` = 1 AND `aisle_prefix` IS NOT NULL THEN `aisle_prefix` ELSE NULL END
  ) STORED,
  ADD COLUMN `active_loc_key` VARCHAR(20) AS (
    CASE WHEN `is_active` = 1 AND `location_code` IS NOT NULL THEN `location_code` ELSE NULL END
  ) STORED;

ALTER TABLE `putaway_location_blocks`
  ADD UNIQUE KEY `uq_putaway_blocks_active_aisle` (`active_aisle_key`),
  ADD UNIQUE KEY `uq_putaway_blocks_active_loc`   (`active_loc_key`);
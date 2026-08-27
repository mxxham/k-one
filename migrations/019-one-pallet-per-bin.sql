-- =============================================================================
-- 019 — One pallet per bin: putaway duplicate-bin guard
-- Source: D:\K-one-v2\apps\api\src\database\migrations\019-one-pallet-per-bin.sql
-- Translations (MariaDB 10.4):
--   PG `DELETE ... USING` → MariaDB multi-table DELETE (DELETE a FROM a JOIN b ...);
--   PG partial unique index (WHERE status IN ... AND location_code NOT IN ...)
--     → generated-column emulation: STORED column yields NULL for non-active /
--     virtual-location rows; unique index ignores NULLs → same invariant.
--   Virtual locations (STAGING / QUA_SHELL) excluded (cross-dock stages many rows).
-- =============================================================================

-- 1. Cleanup: keep the newest active row per real bin, drop older duplicates.
DELETE a FROM `stock_locations` a
  JOIN `stock_locations` b ON a.location_code = b.location_code AND a.id < b.id
 WHERE a.status IN ('Available','Reserved')
   AND b.status IN ('Available','Reserved')
   AND a.location_code NOT IN ('QUA_SHELL','STAGING')
   AND NOT EXISTS (SELECT 1 FROM `outbound_item_locations` o WHERE o.stock_location_id = a.id);

-- 2. DB-level guard: at most one active pallet per real bin.
ALTER TABLE `stock_locations`
  ADD COLUMN `active_bin_guard` VARCHAR(20) AS (
    CASE WHEN `status` IN ('Available','Reserved')
          AND `location_code` NOT IN ('QUA_SHELL','STAGING','QUARANTINE')
          THEN `location_code` ELSE NULL END
  ) STORED;

ALTER TABLE `stock_locations`
  ADD UNIQUE KEY `uq_stock_locations_active_bin` (`active_bin_guard`);
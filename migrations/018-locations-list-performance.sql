-- =============================================================================
-- 018 — Locations list performance
-- Source: D:\K-one-v2\apps\api\src\database\migrations\018-locations-list-performance.sql
-- Translations: PG `id DESC` index member → plain `id` (MariaDB accepts DESC
--   but a plain index is equivalent for this query shape).
-- =============================================================================

CREATE INDEX IF NOT EXISTS `idx_stock_locations_loc_status_id`
  ON `stock_locations`(`location_code`, `status`, `id`);
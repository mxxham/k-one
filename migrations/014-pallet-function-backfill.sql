-- =============================================================================
-- 014 — Backfill pallet_function for stock_locations rows written AFTER 007
-- Source: D:\K-one-v2\apps\api\src\database\migrations\014-pallet-function-backfill.sql
-- Translations: PG `UPDATE ... FROM` → MariaDB multi-table UPDATE (JOIN ... SET).
-- =============================================================================

UPDATE `stock_locations` sl
  JOIN `location_master` lm ON lm.location_code = sl.location_code
   SET sl.pallet_function = 'PICK_FACE'
 WHERE lm.is_pick_face = 1
   AND sl.pallet_function = 'RESERVE';
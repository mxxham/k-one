-- =============================================================================
-- 002 — Parse and update location_master structured fields
-- Source: D:\K-one-v2\apps\api\src\database\migrations\002-parse-locations.sql
-- Translations: PG regex `~` → MariaDB REGEXP; diagnostic SELECT dropped
--   (execution-side concern, not a schema change).
-- Format: CD01A02 → rack=CD01, aisle=CD, row_name=A, position=02
-- =============================================================================

UPDATE location_master
SET
  aisle = SUBSTRING(location_code, 1, 2),
  rack = SUBSTRING(location_code, 1, 4),
  row_name = SUBSTRING(location_code, 5, 1),
  position = SUBSTRING(location_code, 6, 2)
WHERE
  location_code REGEXP '^[A-Z]{2}[0-9]{2}[A-E][0-9]{2}$'
  AND (
    aisle IS NULL OR
    rack IS NULL OR
    row_name IS NULL OR
    position IS NULL
  );
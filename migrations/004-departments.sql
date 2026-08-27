-- =============================================================================
-- 004 — Department-based access (Phase 0 of WMS upgrade — new-roles.md)
-- Source: D:\K-one-v2\apps\api\src\database\migrations\004-departments.sql
-- Translations: PG `DO $$` block → drop+add constraint pattern (idempotent).
--   Column added as VARCHAR(20) + CHECK (v2 parity; MariaDB 10.2+ enforces).
-- =============================================================================

-- 1. users.department column (idempotent)
ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `department` VARCHAR(20) NOT NULL DEFAULT 'all';

ALTER TABLE `users` DROP CONSTRAINT IF EXISTS `users_department_check`;
ALTER TABLE `users` ADD CONSTRAINT `users_department_check`
  CHECK (`department` IN ('inbound','outbound','inventory','all'));

-- 2. Seed sensible department values by role (admin/supervisor→all, warehouse→inventory,
--    operator→outbound, staff→inventory)
UPDATE `users` SET `department` = CASE
  WHEN `role` IN ('admin','supervisor') THEN 'all'
  WHEN `role` IN ('warehouse')          THEN 'inventory'
  WHEN `role` IN ('operator')           THEN 'outbound'
  WHEN `role` IN ('staff')              THEN 'inventory'
  ELSE 'all'
END
WHERE `department` = 'all';
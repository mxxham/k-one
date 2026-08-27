-- =============================================================================
-- 017 — New "ops" (Operations) department — handheld menu set
-- Source: D:\K-one-v2\apps\api\src\database\migrations\017-departments-ops.sql
-- Translations: CHECK widen via DROP + ADD CONSTRAINT (idempotent, strict widening).
-- =============================================================================

ALTER TABLE `users` DROP CONSTRAINT IF EXISTS `users_department_check`;
ALTER TABLE `users` ADD CONSTRAINT `users_department_check`
  CHECK (`department` IN ('inbound','outbound','inventory','ops','all'));
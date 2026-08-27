-- =============================================================================
-- 010 — ABC Analysis / Velocity-Based Ranking
-- Source: D:\K-one-v2\apps\api\src\database\migrations\010-abc-analysis.sql
-- Translations: none required beyond column typing (VARCHAR CHECK kept).
-- =============================================================================

ALTER TABLE `products`
  ADD COLUMN IF NOT EXISTS `velocity_class` VARCHAR(1) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `velocity_class_at` TIMESTAMP NULL DEFAULT NULL;

ALTER TABLE `products` DROP CONSTRAINT IF EXISTS `chk_products_velocity_class`;
ALTER TABLE `products` ADD CONSTRAINT `chk_products_velocity_class`
  CHECK (`velocity_class` IN ('A','B','C'));
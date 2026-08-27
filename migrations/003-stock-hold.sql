-- =============================================================================
-- 003 — Stock Hold / Quarantine Status (Phase 1 of WMS upgrade)
-- Source: D:\K-one-v2\apps\api\src\database\migrations\003-stock-hold.sql
-- Translations: PG `DO $$ ... $$` block → plain conditional statements;
--   stock_ledger.transaction_type widened via MODIFY COLUMN (ENUM style,
--   consistent with the v1 base schema) instead of CHECK drop/add.
-- =============================================================================

-- 1. stock hold columns (idempotent)
ALTER TABLE `stock`
  ADD COLUMN IF NOT EXISTS `hold_status` VARCHAR(20) NOT NULL DEFAULT 'available',
  ADD COLUMN IF NOT EXISTS `hold_reason` TEXT,
  ADD COLUMN IF NOT EXISTS `hold_by` INT(11) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `hold_at` TIMESTAMP NULL DEFAULT NULL;

-- hold_status CHECK (drop+add pattern, idempotent)
ALTER TABLE `stock` DROP CONSTRAINT IF EXISTS `stock_hold_status_check`;
ALTER TABLE `stock` ADD CONSTRAINT `stock_hold_status_check`
  CHECK (`hold_status` IN ('available','on_hold','quarantine','damaged'));

-- 2. widen stock_ledger.transaction_type to include TRANSFER_IN/TRANSFER_OUT/HOLD/RELEASE
ALTER TABLE `stock_ledger`
  MODIFY COLUMN `transaction_type` ENUM('IN','OUT','ADJUSTMENT','TRANSFER','TRANSFER_IN','TRANSFER_OUT','HOLD','RELEASE') NOT NULL;
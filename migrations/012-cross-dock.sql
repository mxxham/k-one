-- =============================================================================
-- 012 — Cross-Docking (S25 Phase 7)
-- Source: D:\K-one-v2\apps\api\src\database\migrations\012-cross-dock.sql
-- Translations: none required.
-- =============================================================================

ALTER TABLE `inbound_items`
    ADD COLUMN IF NOT EXISTS `cross_dock_outbound_order_id` INT(11) DEFAULT NULL;

CREATE INDEX IF NOT EXISTS `idx_inbound_items_cross_dock`
    ON `inbound_items` (`cross_dock_outbound_order_id`);
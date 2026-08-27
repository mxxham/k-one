-- =============================================================================
-- hotfix_025_v2_inbound_putaway.sql
-- Adds stock_id pin to putaway_task_items for v2 inbound workflow rework.
-- Idempotent: safe to run multiple times.
-- =============================================================================

-- stock_id pins the exact STAGING stock row for each putaway task item
ALTER TABLE `putaway_task_items`
    ADD COLUMN IF NOT EXISTS `stock_id` INT(11) NULL
        COMMENT 'FK stock.id - pinned STAGING row created during GR' AFTER `inbound_item_id`;

CREATE INDEX IF NOT EXISTS `idx_pti_stock` ON `putaway_task_items`(`stock_id`);

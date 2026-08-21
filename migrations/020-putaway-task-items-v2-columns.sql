-- =============================================================================
-- 020 — Add missing v2 columns to putaway_task_items
-- Source: v2 had confirmed_by, confirmed_at, stock_id, from_location, scan_override_reason
-- Issue: Putaway::reconcileItemRows() references ti.confirmed_by, ti.confirmed_at
-- Also: status CHECK constraint needs 'Confirmed' value
-- =============================================================================

ALTER TABLE `putaway_task_items`
  ADD COLUMN IF NOT EXISTS `stock_id` INT(11) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `from_location` VARCHAR(30) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `confirmed_by` INT(11) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `confirmed_at` TIMESTAMP NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `scan_override_reason` VARCHAR(255) DEFAULT NULL;

-- Update CHECK constraint to include 'Confirmed' status (v2 uses Confirmed)
ALTER TABLE `putaway_task_items` DROP CONSTRAINT IF EXISTS `chk_putaway_task_items_status`;
ALTER TABLE `putaway_task_items`
  ADD CONSTRAINT `chk_putaway_task_items_status` CHECK (`status` IN ('Pending','Confirmed','Done','Cancelled'));

-- Foreign keys
ALTER TABLE `putaway_task_items`
  ADD CONSTRAINT `fk_pti_confirmed_by` FOREIGN KEY (`confirmed_by`) REFERENCES `users`(`id`);
ALTER TABLE `putaway_task_items`
  ADD CONSTRAINT `fk_pti_stock_id` FOREIGN KEY (`stock_id`) REFERENCES `stock`(`id`);

-- Indexes
CREATE INDEX IF NOT EXISTS `idx_putaway_task_items_confirmed_by` ON `putaway_task_items` (`confirmed_by`);

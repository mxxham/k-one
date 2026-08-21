-- =============================================================================
-- 021 — Fix putaway_tasks status CHECK constraint (add 'Open')
-- Issue: Code uses 'Open' status but CHECK constraint only allows 'Pending','In Progress','Completed','Cancelled'
-- =============================================================================

ALTER TABLE `putaway_tasks` DROP CONSTRAINT IF EXISTS `chk_putaway_tasks_status`;
ALTER TABLE `putaway_tasks`
  ADD CONSTRAINT `chk_putaway_tasks_status` CHECK (`status` IN ('Pending','Open','In Progress','Completed','Cancelled'));
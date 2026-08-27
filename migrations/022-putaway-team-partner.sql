-- =============================================================================
-- 022 — Add missing v2 columns to putaway_tasks
-- 1. team_partner: listTasks() and assignTask() reference pt.team_partner
-- 2. completed_by: taskDetail() references pt.completed_by for completed-by name
-- =============================================================================

ALTER TABLE `putaway_tasks`
  ADD COLUMN IF NOT EXISTS `team_partner` INT(11) DEFAULT NULL AFTER `assigned_to`,
  ADD COLUMN IF NOT EXISTS `completed_by` INT(11) DEFAULT NULL AFTER `completed_at`;

CREATE INDEX IF NOT EXISTS `idx_putaway_tasks_team_partner` ON `putaway_tasks` (`team_partner`);
CREATE INDEX IF NOT EXISTS `idx_putaway_tasks_completed_by` ON `putaway_tasks` (`completed_by`);

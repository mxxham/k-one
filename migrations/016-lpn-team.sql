-- =============================================================================
-- 016 — LPN + two-person putaway team assignment (extends 015 task queue)
-- Source: D:\K-one-v2\apps\api\src\database\migrations\016-lpn-team.sql
-- Translations: PG `lpn_code TEXT UNIQUE` → MariaDB `VARCHAR(255) UNIQUE`
--   (TEXT cannot carry a unique index in MariaDB; LPN codes are short barcodes).
-- =============================================================================

ALTER TABLE `putaway_task_items`
  ADD COLUMN IF NOT EXISTS `lpn_code` VARCHAR(255) DEFAULT NULL;
ALTER TABLE `putaway_task_items` ADD UNIQUE KEY `uq_putaway_task_items_lpn_code` (`lpn_code`);

ALTER TABLE `putaway_tasks`
  ADD COLUMN IF NOT EXISTS `forklift_operator_id` INT(11) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `checklist_partner_id` INT(11) DEFAULT NULL;

CREATE INDEX IF NOT EXISTS `idx_putaway_tasks_forklift` ON `putaway_tasks` (`forklift_operator_id`);
CREATE INDEX IF NOT EXISTS `idx_putaway_tasks_checklist_partner` ON `putaway_tasks` (`checklist_partner_id`, `status`);
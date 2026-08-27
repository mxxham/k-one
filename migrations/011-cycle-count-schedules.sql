-- =============================================================================
-- 011 — Cycle Count Scheduling (S28 Phase 10)
-- Source: D:\K-one-v2\apps\api\src\database\migrations\011-cycle-count-schedules.sql
-- Translations: BOOLEAN → TINYINT(1); BIGSERIAL → INT(11) AUTO_INCREMENT;
--   partial-ish composite index kept as plain index.
-- =============================================================================

CREATE TABLE IF NOT EXISTS `cycle_count_schedules` (
    `id`              INT(11) AUTO_INCREMENT PRIMARY KEY,
    `schedule_name`   VARCHAR(150) NOT NULL,
    `frequency`       VARCHAR(20) NOT NULL DEFAULT 'monthly',
    `scope_type`      VARCHAR(20) NOT NULL DEFAULT 'full',
    `scope_locations` TEXT,
    `velocity_class`  VARCHAR(1) DEFAULT NULL,
    `next_run_date`   DATE NOT NULL,
    `is_active`       TINYINT(1) NOT NULL DEFAULT 1,
    `created_by`      INT(11) NOT NULL,
    `created_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `chk_cycle_count_frequency`  CHECK (`frequency` IN ('weekly','monthly','quarterly')),
    CONSTRAINT `chk_cycle_count_scope_type` CHECK (`scope_type` IN ('full','location','velocity')),
    CONSTRAINT `chk_cycle_count_velocity`   CHECK (`velocity_class` IN ('A','B','C')),
    FOREIGN KEY (`created_by`) REFERENCES `users`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX IF NOT EXISTS `idx_cycle_count_schedules_due`
    ON `cycle_count_schedules` (`is_active`, `next_run_date`);

ALTER TABLE `stock_take` ADD COLUMN IF NOT EXISTS `schedule_id` INT(11) DEFAULT NULL;
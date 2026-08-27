-- =============================================================================
-- 006 — Wave Planning (Phase 4 of WMS upgrade — spec-4)
-- Source: D:\K-one-v2\apps\api\src\database\migrations\006-waves.sql
-- Translations: BIGSERIAL → INT(11) AUTO_INCREMENT;
--   `ALTER COLUMN ... DROP NOT NULL` → MariaDB `MODIFY COLUMN ... NULL`.
-- =============================================================================

CREATE TABLE IF NOT EXISTS `waves` (
    `id`          INT(11) AUTO_INCREMENT PRIMARY KEY,
    `wave_number` VARCHAR(50) UNIQUE NOT NULL,
    `status`      VARCHAR(20) NOT NULL DEFAULT 'Planning',
    `carrier`     VARCHAR(100) DEFAULT NULL,
    `cutoff_time` TIMESTAMP NULL DEFAULT NULL,
    `created_by`  INT(11) NOT NULL,
    `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `chk_waves_status` CHECK (`status` IN ('Planning','Active','Completed','Cancelled')),
    FOREIGN KEY (`created_by`) REFERENCES `users`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE INDEX IF NOT EXISTS `idx_waves_status` ON `waves`(`status`);
CREATE INDEX IF NOT EXISTS `idx_waves_created_by` ON `waves`(`created_by`);

CREATE TABLE IF NOT EXISTS `wave_orders` (
    `wave_id`           INT(11) NOT NULL,
    `outbound_order_id` INT(11) NOT NULL,
    PRIMARY KEY (`wave_id`, `outbound_order_id`),
    FOREIGN KEY (`wave_id`)           REFERENCES `waves`(`id`)           ON DELETE CASCADE,
    FOREIGN KEY (`outbound_order_id`) REFERENCES `outbound_orders`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE INDEX IF NOT EXISTS `idx_wave_orders_outbound` ON `wave_orders`(`outbound_order_id`);

-- A picklist may belong to a wave (consolidated across many orders).
ALTER TABLE `picklists` ADD COLUMN IF NOT EXISTS `wave_id` INT(11) DEFAULT NULL;
CREATE INDEX IF NOT EXISTS `idx_picklists_wave` ON `picklists`(`wave_id`);

-- Wave picklists span many orders → outbound_order_id becomes nullable.
ALTER TABLE `picklists` MODIFY COLUMN `outbound_order_id` INT(11) NULL;
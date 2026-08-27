-- =============================================================================
-- 015 — Putaway task queue
-- Source: D:\K-one-v2\apps\api\src\database\migrations\015-putaway-tasks.sql
-- Translations: BIGSERIAL → INT(11) AUTO_INCREMENT; NUMERIC(15,4) → DECIMAL(15,4);
--   VARCHAR(255) → VARCHAR(255) kept; CHECK constraints verbatim.
-- =============================================================================

CREATE TABLE IF NOT EXISTS `putaway_tasks` (
  `id` INT(11) AUTO_INCREMENT PRIMARY KEY,
  `task_number` VARCHAR(30) NOT NULL UNIQUE,
  `inbound_order_id` INT(11) DEFAULT NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'Pending',
  `priority` INT NOT NULL DEFAULT 5,
  `assigned_to` INT(11) DEFAULT NULL,
  `notes` TEXT,
  `created_by` INT(11) DEFAULT NULL,
  `started_at` TIMESTAMP NULL DEFAULT NULL,
  `completed_at` TIMESTAMP NULL DEFAULT NULL,
  `cancelled_at` TIMESTAMP NULL DEFAULT NULL,
  `cancelled_by` INT(11) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT `chk_putaway_tasks_status` CHECK (`status` IN ('Pending','In Progress','Completed','Cancelled')),
  FOREIGN KEY (`inbound_order_id`) REFERENCES `inbound_orders`(`id`),
  FOREIGN KEY (`assigned_to`) REFERENCES `users`(`id`),
  FOREIGN KEY (`created_by`)   REFERENCES `users`(`id`),
  FOREIGN KEY (`cancelled_by`) REFERENCES `users`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX IF NOT EXISTS `idx_putaway_tasks_status` ON `putaway_tasks` (`status`);
CREATE INDEX IF NOT EXISTS `idx_putaway_tasks_inbound` ON `putaway_tasks` (`inbound_order_id`);

CREATE TABLE IF NOT EXISTS `putaway_task_items` (
  `id` INT(11) AUTO_INCREMENT PRIMARY KEY,
  `task_id` INT(11) NOT NULL,
  `inbound_item_id` INT(11) DEFAULT NULL,
  `product_id` INT(11) DEFAULT NULL,
  `batch_number` VARCHAR(255) DEFAULT NULL,
  `uom` VARCHAR(20) DEFAULT NULL,
  `pallet_seq` INT NOT NULL DEFAULT 1,
  `quantity` DECIMAL(15,4) NOT NULL DEFAULT 0,
  `suggested_location` VARCHAR(30) DEFAULT NULL,
  `actual_location` VARCHAR(30) DEFAULT NULL,
  `pallet_function` VARCHAR(20) NOT NULL DEFAULT 'RESERVE',
  `reason` VARCHAR(30) DEFAULT NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'Pending',
  `completed_by` INT(11) DEFAULT NULL,
  `completed_at` TIMESTAMP NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT `chk_putaway_task_items_status` CHECK (`status` IN ('Pending','Done','Cancelled')),
  FOREIGN KEY (`task_id`)         REFERENCES `putaway_tasks`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`inbound_item_id`) REFERENCES `inbound_items`(`id`),
  FOREIGN KEY (`product_id`)      REFERENCES `products`(`id`),
  FOREIGN KEY (`completed_by`)    REFERENCES `users`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX IF NOT EXISTS `idx_putaway_task_items_task` ON `putaway_task_items` (`task_id`, `status`);
CREATE INDEX IF NOT EXISTS `idx_putaway_task_items_item` ON `putaway_task_items` (`inbound_item_id`);

-- Live-DB ensure: putaway_tasks / putaway_task_items pre-date the port (hotfix
-- era, different column set), so the CREATE TABLEs above are no-ops and these
-- v2 columns must be added idempotently.
ALTER TABLE `putaway_tasks`
  ADD COLUMN IF NOT EXISTS `priority` INT NOT NULL DEFAULT 5,
  ADD COLUMN IF NOT EXISTS `notes` TEXT,
  ADD COLUMN IF NOT EXISTS `started_at` TIMESTAMP NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `cancelled_at` TIMESTAMP NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `cancelled_by` INT(11) DEFAULT NULL;

ALTER TABLE `putaway_task_items`
  ADD COLUMN IF NOT EXISTS `actual_location` VARCHAR(30) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `pallet_function` VARCHAR(20) NOT NULL DEFAULT 'RESERVE',
  ADD COLUMN IF NOT EXISTS `reason` VARCHAR(30) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `completed_by` INT(11) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `completed_at` TIMESTAMP NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;
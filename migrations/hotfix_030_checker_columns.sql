-- Checker columns for outbound picking verification
-- Adds check_status tracking to picklist_items and audit trail in check_scans

ALTER TABLE picklist_items
  ADD COLUMN `check_status` ENUM('Pending','Checked','Discrepancy') NOT NULL DEFAULT 'Pending',
  ADD COLUMN `checked_by` INT NULL,
  ADD COLUMN `checked_at` DATETIME NULL,
  ADD COLUMN `scanned_lpn` VARCHAR(50) NULL,
  ADD COLUMN `check_override_reason` TEXT NULL,
  ADD FOREIGN KEY (`checked_by`) REFERENCES `users`(`id`);

ALTER TABLE outbound_orders
  ADD COLUMN `all_lines_checked` TINYINT(1) NOT NULL DEFAULT 0;

CREATE TABLE `check_scans` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `context_type` ENUM('picking','putaway') NOT NULL,
    `context_id` INT NOT NULL,
    `scan_type` ENUM('LPN','SKU') NOT NULL,
    `expected_value` VARCHAR(100) NOT NULL,
    `scanned_value` VARCHAR(100) NOT NULL,
    `result` ENUM('MATCH','MISMATCH') NOT NULL,
    `user_id` INT NOT NULL,
    `scanned_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

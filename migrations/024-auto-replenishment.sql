-- Auto-Replenishment System Migration
-- Date: 2026-08-29

-- Replenishment rules per product/location
CREATE TABLE IF NOT EXISTS `replenishment_rules` (
    `id` INT(11) AUTO_INCREMENT PRIMARY KEY,
    `product_id` INT(11) NOT NULL,
    `location_id` INT(11) NOT NULL,
    `min_qty` DECIMAL(10,2) DEFAULT 1,
    `max_qty` DECIMAL(10,2) DEFAULT NULL,
    `priority` ENUM('urgent','high','normal','low') DEFAULT 'normal',
    `auto_generate` TINYINT(1) DEFAULT 1,
    `cooldown_minutes` INT DEFAULT 30,
    `is_active` TINYINT(1) DEFAULT 1,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`product_id`) REFERENCES `products`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`location_id`) REFERENCES `location_master`(`id`) ON DELETE CASCADE,
    UNIQUE KEY `uk_replenishment_rule` (`product_id`, `location_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Track replenishment history
CREATE TABLE IF NOT EXISTS `replenishment_log` (
    `id` INT(11) AUTO_INCREMENT PRIMARY KEY,
    `product_id` INT(11) NOT NULL,
    `location_code` VARCHAR(20) NOT NULL,
    `trigger_type` ENUM('scheduled','stock_drop','inbound','manual') NOT NULL,
    `shortage_qty` DECIMAL(10,2) DEFAULT NULL,
    `transfer_id` INT(11) DEFAULT NULL,
    `status` ENUM('pending','generated','executed','failed','skipped') DEFAULT 'pending',
    `skip_reason` VARCHAR(255) DEFAULT NULL,
    `executed_at` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`product_id`) REFERENCES `products`(`id`) ON DELETE CASCADE,
    INDEX `idx_replen_location` (`location_code`),
    INDEX `idx_replen_status` (`status`),
    INDEX `idx_replen_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Configuration for auto-replenishment
CREATE TABLE IF NOT EXISTS `replenishment_config` (
    `id` INT(11) AUTO_INCREMENT PRIMARY KEY,
    `config_key` VARCHAR(50) NOT NULL,
    `config_value` TEXT NOT NULL,
    `description` VARCHAR(255) DEFAULT NULL,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_config_key` (`config_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default configuration values
INSERT IGNORE INTO `replenishment_config` (`config_key`, `config_value`, `description`) VALUES
('enabled', '1', 'Enable/disable auto-replenishment system'),
('schedule_minutes', '15', 'Run interval in minutes for scheduled replenishment'),
('batch_size', '10', 'Maximum number of transfers to generate per cycle'),
('cooldown_minutes', '30', 'Minimum minutes between replenishments for same location'),
('require_approval', '0', 'Require supervisor approval before executing transfers'),
('notify_on_generate', '1', 'Send notification when transfers are generated'),
('notify_on_execute', '1', 'Send notification when transfers are executed'),
('notify_on_failure', '1', 'Send notification when replenishment fails');

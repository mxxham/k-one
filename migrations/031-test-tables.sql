-- Notifications, RMA, and Quality tables
-- These were originally in 027/028/029 but used underscore naming
-- which doesn't match the bootstrap glob pattern 0[0-9][0-9]-*.sql

-- Notifications table for shared notification infrastructure
CREATE TABLE IF NOT EXISTS `notifications` (
    `id` INT(11) AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT(11) DEFAULT NULL,
    `type` VARCHAR(50) NOT NULL,
    `title` VARCHAR(255) NOT NULL,
    `body` TEXT DEFAULT NULL,
    `status` VARCHAR(20) NOT NULL DEFAULT 'sent',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_notifications_user_id` (`user_id`),
    INDEX `idx_notifications_created_at` (`created_at`),
    INDEX `idx_notifications_type` (`type`),
    INDEX `idx_notifications_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- RMA (Return Merchandise Authorization) tables
CREATE TABLE IF NOT EXISTS `rma` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `rma_number` VARCHAR(50) NOT NULL UNIQUE,
    `outbound_order_id` INT NOT NULL,
    `status` ENUM('Pending','Approved','Received','Completed','Rejected') DEFAULT 'Pending',
    `reason` TEXT,
    `created_by` INT,
    `approved_by` INT,
    `approved_at` DATETIME NULL,
    `completed_at` DATETIME NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_status` (`status`),
    INDEX `idx_outbound` (`outbound_order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `rma_items` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `rma_id` INT NOT NULL,
    `product_id` INT NOT NULL,
    `quantity` DECIMAL(15,4) NOT NULL,
    `received_qty` DECIMAL(15,4) DEFAULT 0,
    `condition_type` ENUM('Good','Damaged','Defective') DEFAULT 'Good',
    `location` VARCHAR(50) NULL,
    `notes` TEXT NULL,
    FOREIGN KEY (`rma_id`) REFERENCES `rma`(`id`) ON DELETE CASCADE,
    INDEX `idx_product` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Quality Management tables
CREATE TABLE IF NOT EXISTS `quality_inspections` (
    `id` INT(11) AUTO_INCREMENT PRIMARY KEY,
    `inspection_number` VARCHAR(50) NOT NULL,
    `stock_id` INT(11) NOT NULL,
    `inspection_type` VARCHAR(50) NOT NULL DEFAULT 'Incoming',
    `inspector` INT(11) DEFAULT NULL,
    `status` VARCHAR(20) NOT NULL DEFAULT 'Pending',
    `result` VARCHAR(20) DEFAULT NULL,
    `notes` TEXT DEFAULT NULL,
    `approved_by` INT(11) DEFAULT NULL,
    `approved_at` DATETIME DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_qi_stock_id` (`stock_id`),
    INDEX `idx_qi_inspector` (`inspector`),
    INDEX `idx_qi_status` (`status`),
    INDEX `idx_qi_inspection_type` (`inspection_type`),
    INDEX `idx_qi_created_at` (`created_at`),
    CONSTRAINT `fk_qi_stock` FOREIGN KEY (`stock_id`) REFERENCES `stock`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_qi_inspector` FOREIGN KEY (`inspector`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_qi_approved_by` FOREIGN KEY (`approved_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `quality_results` (
    `id` INT(11) AUTO_INCREMENT PRIMARY KEY,
    `inspection_id` INT(11) NOT NULL,
    `parameter` VARCHAR(100) NOT NULL,
    `value` VARCHAR(255) DEFAULT NULL,
    `unit` VARCHAR(50) DEFAULT NULL,
    `pass_fail` VARCHAR(10) DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_qr_inspection_id` (`inspection_id`),
    CONSTRAINT `fk_qr_inspection` FOREIGN KEY (`inspection_id`) REFERENCES `quality_inspections`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

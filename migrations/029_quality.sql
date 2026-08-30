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

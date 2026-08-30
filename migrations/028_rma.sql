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

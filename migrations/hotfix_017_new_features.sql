CREATE TABLE IF NOT EXISTS `activity_log` (
    `id`            INT AUTO_INCREMENT PRIMARY KEY,
    `user_id`       INT NULL,
    `username`      VARCHAR(100) NULL,
    `full_name`     VARCHAR(100) NULL,
    `action`        VARCHAR(100) NOT NULL COMMENT 'e.g. CREATE_INBOUND, PICK_OUTBOUND, BIN_TRANSFER',
    `module`        VARCHAR(50)  NOT NULL COMMENT 'e.g. inbound, outbound, bin_transfer',
    `reference_type` VARCHAR(50) NULL COMMENT 'e.g. Inbound, Outbound, BinTransfer',
    `reference_id`  INT NULL,
    `reference_no`  VARCHAR(100) NULL,
    `description`   TEXT NULL,
    `old_value`     TEXT NULL COMMENT 'JSON snapshot sebelum perubahan',
    `new_value`     TEXT NULL COMMENT 'JSON snapshot sesudah perubahan',
    `ip_address`    VARCHAR(45) NULL,
    `created_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_user_id`    (`user_id`),
    INDEX `idx_module`     (`module`),
    INDEX `idx_ref`        (`reference_type`, `reference_id`),
    INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `bin_transfers` (
    `id`              INT AUTO_INCREMENT PRIMARY KEY,
    `transfer_number` VARCHAR(50) UNIQUE NOT NULL,
    `transfer_date`   DATE NOT NULL,
    `product_id`      INT NOT NULL,
    `stock_id`        INT NULL,
    `batch_number`    VARCHAR(100) NULL,
    `from_location`   VARCHAR(100) NOT NULL,
    `to_location`     VARCHAR(100) NOT NULL,
    `quantity`        DECIMAL(12,4) NOT NULL,
    `uom`             VARCHAR(20)  DEFAULT 'Drum',
    `reason`          TEXT NULL,
    `status`          ENUM('Pending','Completed','Cancelled') DEFAULT 'Pending',
    `created_by`      INT NULL,
    `completed_by`    INT NULL,
    `completed_at`    TIMESTAMP NULL,
    `created_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`product_id`) REFERENCES `products`(`id`),
    FOREIGN KEY (`created_by`)   REFERENCES `users`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`completed_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    INDEX `idx_transfer_date` (`transfer_date`),
    INDEX `idx_product`       (`product_id`),
    INDEX `idx_from_loc`      (`from_location`),
    INDEX `idx_to_loc`        (`to_location`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `outbound_destinations` (
    `id`              INT AUTO_INCREMENT PRIMARY KEY,
    `outbound_id`     INT NOT NULL,
    `seq`             TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Urutan tujuan ke-n',
    `ship_to_name`    VARCHAR(200) NULL,
    `ship_to_location` VARCHAR(200) NULL,
    `ship_to_street`  VARCHAR(300) NULL,
    `kota`            VARCHAR(100) NULL,
    `destination`     VARCHAR(300) NULL,
    `notes`           TEXT NULL,
    `created_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`outbound_id`) REFERENCES `outbound_orders`(`id`) ON DELETE CASCADE,
    INDEX `idx_outbound_id` (`outbound_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `inbound_items`
    ADD COLUMN IF NOT EXISTS `pallet_no` VARCHAR(50) NULL
        COMMENT 'Nomor label palet fisik, e.g. PLT-001'
        AFTER `pallet`;

-- =============================================================================
-- 009 — Advance Shipping Notice (ASN)
-- Source: D:\K-one-v2\apps\api\src\database\migrations\009-asn.sql
-- Translations: BIGSERIAL → INT(11) AUTO_INCREMENT; NUMERIC → DECIMAL.
-- =============================================================================

CREATE TABLE IF NOT EXISTS `asn` (
    `id`                    INT(11) AUTO_INCREMENT PRIMARY KEY,
    `asn_number`            VARCHAR(50) UNIQUE NOT NULL,
    `supplier_name`         VARCHAR(255) DEFAULT NULL,
    `supplier_reference`    VARCHAR(100) DEFAULT NULL,
    `expected_arrival_date` DATE DEFAULT NULL,
    `status`                VARCHAR(20) NOT NULL DEFAULT 'Pending',
    `notes`                 TEXT,
    `created_by`            INT(11) NOT NULL,
    `created_at`            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `chk_asn_status` CHECK (`status` IN ('Pending','Received','Cancelled')),
    FOREIGN KEY (`created_by`) REFERENCES `users`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE INDEX IF NOT EXISTS `idx_asn_status`     ON `asn`(`status`);
CREATE INDEX IF NOT EXISTS `idx_asn_created_by` ON `asn`(`created_by`);
CREATE INDEX IF NOT EXISTS `idx_asn_arrival`    ON `asn`(`expected_arrival_date`);

CREATE TABLE IF NOT EXISTS `asn_items` (
    `id`           INT(11) AUTO_INCREMENT PRIMARY KEY,
    `asn_id`       INT(11) NOT NULL,
    `product_id`   INT(11) NOT NULL,
    `expected_qty` DECIMAL(10,2) NOT NULL DEFAULT 0,
    `uom`          VARCHAR(20)   NOT NULL DEFAULT 'Drum',
    `batch_number` VARCHAR(100) DEFAULT NULL,
    `exp_date`     DATE DEFAULT NULL,
    `created_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`asn_id`)     REFERENCES `asn`(`id`)     ON DELETE CASCADE,
    FOREIGN KEY (`product_id`) REFERENCES `products`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE INDEX IF NOT EXISTS `idx_asn_items_asn`     ON `asn_items`(`asn_id`);
CREATE INDEX IF NOT EXISTS `idx_asn_items_product` ON `asn_items`(`product_id`);

ALTER TABLE `inbound_orders` ADD COLUMN IF NOT EXISTS `asn_id` INT(11) DEFAULT NULL;
CREATE INDEX IF NOT EXISTS `idx_inbound_orders_asn` ON `inbound_orders`(`asn_id`);
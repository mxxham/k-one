-- =============================================================================
-- K-one v2 → PHP/MariaDB port — Base schema (001)
-- Source: D:\K-one\k-one\database.sql (primary, v1) cross-checked vs
--         D:\K-one-v2\apps\api\src\database\migrations\001-schema.sql (v2)
-- Folded v2-001-only additions NOT covered by migrations 002-019:
--   * stock_take rich columns (scope_locations, scope_type, counting_round)
--     + v2 status set ('Draft','Counting','Review','Adjusted','Completed','Cancelled')
--   * outbound_items.customer_id (v1 had via hotfix add_customer_id_to_outbound_items.sql)
--   * picklist_items.outbound_item_id (v1 had via hotfix_023_picklist_item_outbound_link.sql)
--   * activity_log.updated_at
--   * auth_tokens table (v1 creates on-demand in api/index.php; kept parity)
-- Seed hashes verified 2026-08-19: admin=admin123, warehouse=warehouse123,
--   operator=operator123, supervisor=supervisor123 (v2 parity).
-- Naming decision: new port files use NNN-*.sql matching v2 migration numbers;
-- legacy revision_*.sql / hotfix_*.sql files are untouched.
-- MariaDB 10.4.32 target (XAMPP).
-- =============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- =============================================================================
-- MASTER DATA TABLES
-- =============================================================================

CREATE TABLE IF NOT EXISTS `users` (
    `id`          INT(11) AUTO_INCREMENT PRIMARY KEY,
    `username`    VARCHAR(50)  UNIQUE NOT NULL,
    `password`    VARCHAR(255) NOT NULL,
    `full_name`   VARCHAR(100) NOT NULL,
    `email`       VARCHAR(100) NOT NULL,
    `role`        ENUM('admin','warehouse','supervisor','operator','staff') DEFAULT 'staff',
    `is_active`   TINYINT(1)   DEFAULT 1,
    `created_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `customers` (
    `id`             INT(11) AUTO_INCREMENT PRIMARY KEY,
    `customer_code`  VARCHAR(50)  UNIQUE NOT NULL,
    `customer_name`  VARCHAR(255) NOT NULL,
    `contact_person` VARCHAR(100) DEFAULT NULL,
    `phone`          VARCHAR(50)  DEFAULT NULL,
    `email`          VARCHAR(100) DEFAULT NULL,
    `address`        TEXT,
    `city`           VARCHAR(100) DEFAULT NULL,
    `is_active`      TINYINT(1)  DEFAULT 1,
    `created_at`     TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_customer_code` (`customer_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `products` (
    `id`               INT(11) AUTO_INCREMENT PRIMARY KEY,
    `product_code`     VARCHAR(50)   UNIQUE NOT NULL,
    `product_name`     VARCHAR(255)  NOT NULL,
    `category`         VARCHAR(100)  DEFAULT NULL,
    `description`      TEXT,
    `drums_per_pallet` INT(11)       DEFAULT 4,
    `uom_type`         ENUM('Drum','Carton','Pail','EA','Bags') DEFAULT 'Drum',
    `uom_per_pallet`   INT(11)       DEFAULT 4,
    `liters_per_unit`  DECIMAL(10,2) DEFAULT 209.00,
    `max_sku_qty`      INT(11)       DEFAULT 44,
    `max_trans_qty`    INT(11)       DEFAULT 80,
    `default_location` VARCHAR(50)   DEFAULT NULL,
    `max_per_transaction` INT(11)    DEFAULT 80,
    `reorder_level`    INT(11)       DEFAULT 0,
    `is_active`        TINYINT(1)    DEFAULT 1,
    `created_at`       TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_product_code` (`product_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- LOCATION TABLES
-- =============================================================================

CREATE TABLE IF NOT EXISTS `location_master` (
    `id`            INT(11) AUTO_INCREMENT PRIMARY KEY,
    `location_code` VARCHAR(20) NOT NULL UNIQUE,
    `aisle`         VARCHAR(10) DEFAULT NULL,
    `rack`          VARCHAR(10) DEFAULT NULL,
    `row_name`      VARCHAR(10) DEFAULT NULL,
    `position`      VARCHAR(10) DEFAULT NULL,
    `zone`          VARCHAR(20) DEFAULT NULL,
    `is_active`     TINYINT(1)  NOT NULL DEFAULT 1,
    `created_at`    TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_aisle` (`aisle`),
    KEY `idx_zone`  (`zone`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `warehouse_locations` (
    `id`         INT(11) AUTO_INCREMENT PRIMARY KEY,
    `location`   VARCHAR(20) UNIQUE NOT NULL,
    `aisle`      VARCHAR(5)  DEFAULT NULL,
    `bay`        INT(11)     DEFAULT NULL,
    `row_code`   VARCHAR(2)  DEFAULT NULL,
    `slot`       INT(11)     DEFAULT NULL,
    `is_active`  TINYINT(1)  DEFAULT 1,
    `created_at` TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- INBOUND TABLES
-- =============================================================================

CREATE TABLE IF NOT EXISTS `inbound_orders` (
    `id`               INT(11) AUTO_INCREMENT PRIMARY KEY,
    `order_number`     VARCHAR(50)  UNIQUE NOT NULL,
    `order_date`       DATE         NOT NULL,
    `carrier_name`     VARCHAR(100) DEFAULT NULL,
    `container_no`     VARCHAR(50)  DEFAULT NULL,
    `po_number`        VARCHAR(50)  DEFAULT NULL,
    `shipment_no`      VARCHAR(100) DEFAULT NULL,
    `do_number`        VARCHAR(100) DEFAULT NULL,
    `armada_no`        VARCHAR(50)  DEFAULT NULL,
    `production_date`  DATE         DEFAULT NULL,
    `expected_date`    DATE         DEFAULT NULL,
    `status`           ENUM('Draft','Dues In','Receiving','Good Received','Goods Received','Unserviceable','Picked','ATP','Completed','Cancelled')
                       NOT NULL DEFAULT 'Draft',
    `notes`            TEXT,
    `remarks`          TEXT,
    `received_by`      INT(11)      DEFAULT NULL,
    `received_by_name` VARCHAR(100) DEFAULT NULL,
    `received_date`    DATE         DEFAULT NULL,
    `created_by`       INT(11)      NOT NULL,
    `created_at`       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`created_by`)  REFERENCES `users`(`id`),
    FOREIGN KEY (`received_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    UNIQUE KEY `uk_order_number` (`order_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `inbound_items` (
    `id`                INT(11) AUTO_INCREMENT PRIMARY KEY,
    `inbound_order_id`  INT(11) NOT NULL,
    `od_number`         VARCHAR(100) DEFAULT NULL,
    `so_number`         VARCHAR(100) DEFAULT NULL,
    `product_id`        INT(11) NOT NULL,
    `batch_no`          VARCHAR(100) DEFAULT NULL,
    `location`          VARCHAR(30)  DEFAULT NULL,
    `quantity`          DECIMAL(10,2) NOT NULL DEFAULT 0,
    `uom`               VARCHAR(20)   DEFAULT 'Drum',
    `actual_qty`        DECIMAL(10,2) DEFAULT 0,
    `pallet`            DECIMAL(10,2) DEFAULT 0,
    `pallet_no`         VARCHAR(50)   DEFAULT NULL,
    `manufacture_date`  DATE          DEFAULT NULL,
    `exp_date`          DATE          DEFAULT NULL,
    `stock_status`      ENUM('Accepted','Rejected','Pending') NOT NULL DEFAULT 'Pending',
    `in_process_status` ENUM('Dues In','Goods Received','ATP','Unserviceable','Picked')
                        NOT NULL DEFAULT 'Dues In',
    `notes`             TEXT,
    `created_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `batch_number`      VARCHAR(100) DEFAULT NULL,
    FOREIGN KEY (`inbound_order_id`) REFERENCES `inbound_orders`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`product_id`)       REFERENCES `products`(`id`),
    KEY `idx_od_number`      (`od_number`),
    KEY `idx_so_number_item` (`so_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- OUTBOUND TABLES
-- =============================================================================

CREATE TABLE IF NOT EXISTS `outbound_orders` (
    `id`               INT(11) AUTO_INCREMENT PRIMARY KEY,
    `order_number`     VARCHAR(50)  UNIQUE NOT NULL,
    `order_date`       DATE         NOT NULL,
    `customer_id`      INT(11)      NOT NULL,
    `so_number`        VARCHAR(50)  DEFAULT NULL,
    `do_number`        VARCHAR(50)  DEFAULT NULL,
    `shipment_number`  VARCHAR(50)  DEFAULT NULL,
    `ship_to_name`     VARCHAR(255) DEFAULT NULL,
    `ship_to_location` VARCHAR(100) DEFAULT NULL,
    `ship_to_street`   VARCHAR(500) DEFAULT NULL,
    `destination`      VARCHAR(255) DEFAULT NULL,
    `kota`             VARCHAR(100) DEFAULT NULL,
    `armada_no`        VARCHAR(50)  DEFAULT NULL,
    `container_no`     VARCHAR(50)  DEFAULT NULL,
    `jenis_armada`     VARCHAR(50)  DEFAULT NULL,
    `expected_date`    DATE         DEFAULT NULL,
    `status`           ENUM('Open','Picking','Picked','Shipped','Delivered','Completed','Cancelled')
                       DEFAULT 'Open',
    `shipped_date`     DATE         DEFAULT NULL,
    `notes`            TEXT,
    `shipped_by`       INT(11)      DEFAULT NULL,
    `created_by`       INT(11)      NOT NULL,
    `created_at`       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`),
    FOREIGN KEY (`created_by`)  REFERENCES `users`(`id`),
    FOREIGN KEY (`shipped_by`)  REFERENCES `users`(`id`) ON DELETE SET NULL,
    UNIQUE KEY `uk_outbound_order_number` (`order_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `outbound_destinations` (
    `id`               INT(11) AUTO_INCREMENT PRIMARY KEY,
    `outbound_id`      INT(11) NOT NULL,
    `seq`              TINYINT(3) UNSIGNED NOT NULL DEFAULT 1,
    `ship_to_name`     VARCHAR(200) DEFAULT NULL,
    `ship_to_location` VARCHAR(200) DEFAULT NULL,
    `ship_to_street`   VARCHAR(300) DEFAULT NULL,
    `kota`             VARCHAR(100) DEFAULT NULL,
    `destination`      VARCHAR(300) DEFAULT NULL,
    `notes`            TEXT,
    `created_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`outbound_id`) REFERENCES `outbound_orders`(`id`) ON DELETE CASCADE,
    INDEX `idx_outbound_id` (`outbound_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `outbound_items` (
    `id`                 INT(11) AUTO_INCREMENT PRIMARY KEY,
    `outbound_order_id`  INT(11) NOT NULL,
    `product_id`         INT(11) NOT NULL,
    `quantity`           DECIMAL(10,2) NOT NULL DEFAULT 0,
    `uom`                VARCHAR(20)  DEFAULT 'Drum',
    `actual_qty`         DECIMAL(10,2) DEFAULT 0,
    `pallet`             DECIMAL(10,2) DEFAULT 0,
    `batch_no`           VARCHAR(100) DEFAULT NULL,
    `exp_date`           DATE         DEFAULT NULL,
    `location`           VARCHAR(30)  DEFAULT NULL,
    `in_process_status`  ENUM('Goods Received','ATP','Unserviceable') DEFAULT 'Goods Received',
    `gr_plan_no`         VARCHAR(100) DEFAULT NULL,
    `transaction_no`     VARCHAR(100) DEFAULT NULL,
    `notes`              TEXT,
    `od_number`          VARCHAR(100) DEFAULT NULL,
    `so_number`          VARCHAR(100) DEFAULT NULL,
    `destination_id`     INT(11)      DEFAULT NULL,
    `customer_id`        INT(11)      DEFAULT NULL,
    `created_at`         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `batch_number`       VARCHAR(100) DEFAULT NULL,
    `stock_location_id`  INT(11)      DEFAULT NULL,
    FOREIGN KEY (`outbound_order_id`) REFERENCES `outbound_orders`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`product_id`)        REFERENCES `products`(`id`),
    FOREIGN KEY (`customer_id`)       REFERENCES `customers`(`id`),
    INDEX `idx_ob_od_number`  (`od_number`),
    INDEX `idx_ob_so_number`  (`so_number`),
    INDEX `idx_ob_dest_id`    (`destination_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- STOCK TABLES
-- =============================================================================

CREATE TABLE IF NOT EXISTS `stock` (
    `id`               INT(11) AUTO_INCREMENT PRIMARY KEY,
    `product_id`       INT(11) NOT NULL,
    `batch_number`     VARCHAR(100) DEFAULT NULL,
    `location`         VARCHAR(30)  DEFAULT NULL,
    `quantity`         DECIMAL(10,2) NOT NULL DEFAULT 0,
    `uom`              VARCHAR(20)  DEFAULT 'Drum',
    `uom_type`         ENUM('Drum','Carton','Pail') DEFAULT 'Drum',
    `uom_per_pallet`   INT(11)      DEFAULT 4,
    `pallet`           DECIMAL(10,2) DEFAULT 0,
    `manufacture_date` DATE         DEFAULT NULL,
    `production_date`  DATE         DEFAULT NULL,
    `expiry_date`      DATE         DEFAULT NULL,
    `stock_status`     ENUM('Available','Reserved','Expired','Dues In') DEFAULT 'Available',
    `created_at`       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`product_id`) REFERENCES `products`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `stock_locations` (
    `id`                INT(11) AUTO_INCREMENT PRIMARY KEY,
    `stock_id`          INT(11)      DEFAULT NULL,
    `location_code`     VARCHAR(20)  NOT NULL,
    `pallet_seq`        SMALLINT(6)  NOT NULL DEFAULT 1,
    `quantity`          DECIMAL(10,2) NOT NULL DEFAULT 0,
    `original_quantity` DECIMAL(10,2) NOT NULL DEFAULT 0,
    `uom`               VARCHAR(20)  DEFAULT 'EA',
    `is_full_pallet`    TINYINT(1)   DEFAULT 1,
    `batch_number`      VARCHAR(100) DEFAULT NULL,
    `inbound_item_id`   INT(11)      DEFAULT NULL,
    `status`            ENUM('Available','Reserved','Picked','Empty') DEFAULT 'Available',
    `created_at`        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_stock`    (`stock_id`),
    KEY `idx_location` (`location_code`),
    KEY `idx_status`   (`status`),
    KEY `idx_batch`    (`batch_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `outbound_item_locations` (
    `id`                INT(11) AUTO_INCREMENT PRIMARY KEY,
    `outbound_item_id`  INT(11) NOT NULL,
    `stock_location_id` INT(11) NOT NULL,
    `quantity`          DECIMAL(10,2) NOT NULL DEFAULT 0,
    `created_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_item_loc` (`outbound_item_id`, `stock_location_id`),
    KEY `idx_oi` (`outbound_item_id`),
    KEY `idx_sl` (`stock_location_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `location_allocations` (
    `id`             INT(11) AUTO_INCREMENT PRIMARY KEY,
    `reference_type` ENUM('Inbound','Outbound','Stock') NOT NULL,
    `reference_id`   INT(11) NOT NULL,
    `item_id`        INT(11) NOT NULL,
    `pallet_number`  INT(11) NOT NULL,
    `location`       VARCHAR(50) NOT NULL,
    `quantity`       DECIMAL(10,2) NOT NULL,
    `uom`            VARCHAR(20)  DEFAULT 'Drum',
    `is_full`        TINYINT(1)   DEFAULT 1,
    `created_at`     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_ref` (`reference_type`, `reference_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `stock_ledger` (
    `id`               INT(11) AUTO_INCREMENT PRIMARY KEY,
    `transaction_date` DATE NOT NULL,
    `product_id`       INT(11) NOT NULL,
    `transaction_type` ENUM('IN','OUT','ADJUSTMENT','TRANSFER') NOT NULL,
    `reference_type`   VARCHAR(50) DEFAULT NULL,
    `reference_id`     INT(11)     DEFAULT NULL,
    `reference_number` VARCHAR(50) DEFAULT NULL,
    `batch_number`     VARCHAR(100) DEFAULT NULL,
    `quantity_in`      DECIMAL(10,2) DEFAULT 0,
    `quantity_out`     DECIMAL(10,2) DEFAULT 0,
    `uom`              VARCHAR(20)  DEFAULT 'Drum',
    `pallet`           DECIMAL(10,2) DEFAULT 0,
    `balance`          DECIMAL(10,2) DEFAULT 0,
    `location`         VARCHAR(50)  DEFAULT NULL,
    `notes`            TEXT,
    `created_at`       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`product_id`) REFERENCES `products`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- PICKLIST TABLES
-- =============================================================================

CREATE TABLE IF NOT EXISTS `picklists` (
    `id`                INT(11) AUTO_INCREMENT PRIMARY KEY,
    `outbound_order_id` INT(11) NOT NULL,
    `picklist_number`   VARCHAR(50) UNIQUE NOT NULL,
    `created_date`      DATE        NOT NULL,
    `status`            ENUM('Draft','Confirmed','Picking','Picked','Completed','Cancelled') DEFAULT 'Draft',
    `notes`             TEXT,
    `created_by`        INT(11) NOT NULL,
    `confirmed_at`      TIMESTAMP NULL DEFAULT NULL,
    `picked_at`         TIMESTAMP NULL DEFAULT NULL,
    `completed_at`      TIMESTAMP NULL DEFAULT NULL,
    `created_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`outbound_order_id`) REFERENCES `outbound_orders`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`created_by`)        REFERENCES `users`(`id`),
    UNIQUE KEY `uk_picklist_number` (`picklist_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `picklist_items` (
    `id`                INT(11) AUTO_INCREMENT PRIMARY KEY,
    `picklist_id`       INT(11) NOT NULL,
    `outbound_item_id`  INT(11) DEFAULT NULL,
    `product_id`        INT(11) NOT NULL,
    `batch_no`          VARCHAR(100) NOT NULL,
    `location`          VARCHAR(30)  DEFAULT NULL,
    `quantity`          DECIMAL(10,2) NOT NULL,
    `uom`               VARCHAR(20)  NOT NULL,
    `pallet`            DECIMAL(10,2) NOT NULL,
    `picked_quantity`   DECIMAL(10,2) DEFAULT 0,
    `status`            ENUM('Pending','Picked','Verified') DEFAULT 'Pending',
    `picker_id`         INT(11)      DEFAULT NULL,
    `notes`             TEXT,
    `created_at`        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `picked_at`         TIMESTAMP    NULL DEFAULT NULL,
    `batch_number`      VARCHAR(100) DEFAULT NULL,
    `stock_location_id` INT(11)      DEFAULT NULL,
    `pallet_seq`        SMALLINT(6)  DEFAULT 1,
    FOREIGN KEY (`picklist_id`) REFERENCES `picklists`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`product_id`)  REFERENCES `products`(`id`),
    FOREIGN KEY (`outbound_item_id`) REFERENCES `outbound_items`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- STOCK TAKE TABLES (v2 rich form: scope_locations/scope_type/counting_round,
-- v2 status set — folded from v2 001-schema.sql, no migration covers these)
-- =============================================================================

CREATE TABLE IF NOT EXISTS `stock_take` (
    `id`              INT(11) AUTO_INCREMENT PRIMARY KEY,
    `take_number`     VARCHAR(50) UNIQUE NOT NULL,
    `take_date`       DATE NOT NULL,
    `status`          ENUM('Draft','Counting','Review','Adjusted','Completed','Cancelled') DEFAULT 'Draft',
    `notes`           TEXT,
    `scope_locations` TEXT,
    `scope_type`      VARCHAR(20) NOT NULL DEFAULT 'full',
    `counting_round`  VARCHAR(10) DEFAULT NULL,
    `created_by`      INT(11) NOT NULL,
    `created_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`created_by`) REFERENCES `users`(`id`),
    UNIQUE KEY `uk_take_number` (`take_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `stock_take_items` (
    `id`            INT(11) AUTO_INCREMENT PRIMARY KEY,
    `stock_take_id` INT(11) NOT NULL,
    `product_id`    INT(11) NOT NULL,
    `batch_number`  VARCHAR(100) DEFAULT NULL,
    `uom`           VARCHAR(20)  DEFAULT NULL,
    `location`      VARCHAR(100) DEFAULT NULL,
    `qty_system`    DECIMAL(10,2) NOT NULL DEFAULT 0,
    `counter_1`     DECIMAL(10,2) DEFAULT NULL,
    `counter_2`     DECIMAL(10,2) DEFAULT NULL,
    `counter_3`     DECIMAL(10,2) DEFAULT NULL,
    `qty_physical`  DECIMAL(10,2) NOT NULL DEFAULT 0,
    `difference`    DECIMAL(10,2) NOT NULL DEFAULT 0,
    `status`        ENUM('Plus','Minus','Clear') NOT NULL DEFAULT 'Clear',
    `notes`         TEXT,
    `counter_by`    VARCHAR(100) DEFAULT NULL,
    `created_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`stock_take_id`) REFERENCES `stock_take`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`product_id`)    REFERENCES `products`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- BIN TRANSFER TABLE
-- =============================================================================

CREATE TABLE IF NOT EXISTS `bin_transfers` (
    `id`              INT(11) AUTO_INCREMENT PRIMARY KEY,
    `transfer_number` VARCHAR(50)   UNIQUE NOT NULL,
    `transfer_date`   DATE          NOT NULL,
    `product_id`      INT(11)       NOT NULL,
    `stock_id`        INT(11)       DEFAULT NULL,
    `batch_number`    VARCHAR(100)  DEFAULT NULL,
    `from_location`   VARCHAR(100)  NOT NULL,
    `to_location`     VARCHAR(100)  NOT NULL,
    `quantity`        DECIMAL(12,4) NOT NULL,
    `uom`             VARCHAR(20)   DEFAULT 'Drum',
    `reason`          TEXT          DEFAULT NULL,
    `status`          ENUM('Pending','Completed','Cancelled') DEFAULT 'Pending',
    `created_by`      INT(11)       DEFAULT NULL,
    `completed_by`    INT(11)       DEFAULT NULL,
    `completed_at`    TIMESTAMP     NULL DEFAULT NULL,
    `created_at`      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`product_id`)    REFERENCES `products`(`id`),
    FOREIGN KEY (`created_by`)    REFERENCES `users`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`completed_by`)  REFERENCES `users`(`id`) ON DELETE SET NULL,
    INDEX `idx_transfer_date` (`transfer_date`),
    INDEX `idx_product`       (`product_id`),
    INDEX `idx_from_loc`      (`from_location`),
    INDEX `idx_to_loc`        (`to_location`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- ACTIVITY LOG TABLE (updated_at folded from v2 001)
-- =============================================================================

CREATE TABLE IF NOT EXISTS `activity_log` (
    `id`             INT(11) AUTO_INCREMENT PRIMARY KEY,
    `user_id`        INT(11)      DEFAULT NULL,
    `username`       VARCHAR(100) DEFAULT NULL,
    `full_name`      VARCHAR(100) DEFAULT NULL,
    `action`         VARCHAR(100) NOT NULL,
    `module`         VARCHAR(50)  NOT NULL,
    `reference_type` VARCHAR(50)  DEFAULT NULL,
    `reference_id`   INT(11)      DEFAULT NULL,
    `reference_no`   VARCHAR(100) DEFAULT NULL,
    `description`    TEXT         DEFAULT NULL,
    `old_value`      TEXT         DEFAULT NULL,
    `new_value`      TEXT         DEFAULT NULL,
    `ip_address`     VARCHAR(45)  DEFAULT NULL,
    `created_at`     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_user_id`    (`user_id`),
    INDEX `idx_module`     (`module`),
    INDEX `idx_ref`        (`reference_type`, `reference_id`),
    INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- SETTINGS TABLE
-- =============================================================================

CREATE TABLE IF NOT EXISTS `settings` (
    `id`            INT(11) AUTO_INCREMENT PRIMARY KEY,
    `setting_key`   VARCHAR(50) UNIQUE NOT NULL,
    `setting_value` TEXT,
    `updated_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- AUTH TOKENS (folded from v2 001-schema.sql; token = SHA-256 hex, VARCHAR(64))
-- =============================================================================

CREATE TABLE IF NOT EXISTS `auth_tokens` (
    `id`         INT(11) AUTO_INCREMENT PRIMARY KEY,
    `user_id`    INT(11) NOT NULL,
    `token`      VARCHAR(64) UNIQUE NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `expires_at` TIMESTAMP NOT NULL DEFAULT (CURRENT_TIMESTAMP + INTERVAL 12 HOUR),
    KEY `idx_auth_tokens_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- INDEXES (v1 database.sql trailing block)
-- =============================================================================

CREATE INDEX IF NOT EXISTS `idx_stock_product`  ON `stock`(`product_id`);
CREATE INDEX IF NOT EXISTS `idx_stock_expiry`   ON `stock`(`expiry_date`);
CREATE INDEX IF NOT EXISTS `idx_stock_status`   ON `stock`(`stock_status`);
CREATE INDEX IF NOT EXISTS `idx_stock_location` ON `stock`(`location`);
CREATE INDEX IF NOT EXISTS `idx_fefo`           ON `stock`(`product_id`, `stock_status`, `expiry_date`);

CREATE INDEX IF NOT EXISTS `idx_ledger_product` ON `stock_ledger`(`product_id`);
CREATE INDEX IF NOT EXISTS `idx_ledger_date`    ON `stock_ledger`(`transaction_date`);
CREATE INDEX IF NOT EXISTS `idx_ledger_ref`     ON `stock_ledger`(`reference_type`, `reference_id`);

CREATE INDEX IF NOT EXISTS `idx_inbound_status`  ON `inbound_orders`(`status`);
CREATE INDEX IF NOT EXISTS `idx_outbound_status` ON `outbound_orders`(`status`);

-- =============================================================================
-- SEEDS (v2-verified hashes: admin123 / warehouse123 / operator123 / supervisor123)
-- =============================================================================

INSERT INTO `users` (`username`, `password`, `full_name`, `email`, `role`, `is_active`) VALUES
('admin',      '$2y$10$n2jAlRXBXHYD0njcCfiBw.a.tPAkbUBU2cGAq4kyLFRzUuvsZRxw2', 'Administrator',        'admin@sanchaya.com',      'admin',      1),
('warehouse',  '$2y$10$4xXDxQnKQkVTTVzQl9BJc.aal4czR3m8l.SDtwEvlvP94egJkjb72', 'Warehouse Manager',    'warehouse@sanchaya.com', 'warehouse',  1),
('operator',   '$2y$10$YEycbXQYHYaCSt/tVd9e8eLzNU1tmo/GQXNnv9shNjfrJMM2XJxD2', 'Warehouse Operator',   'operator@sanchaya.com',  'operator',   1),
('supervisor', '$2y$10$BxRn7nZX1CR3XMyKikHuQOLj6aM7mVVu/yBTK6kA870xsZIcNLa9q', 'Warehouse Supervisor', 'supervisor@sanchaya.com','supervisor', 1)
ON DUPLICATE KEY UPDATE `id` = `id`;

INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES
('warehouse_name',    'Shell CKB Warehouse'),
('drums_per_pallet',  '4')
ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`);

INSERT IGNORE INTO `location_master`
    (`location_code`, `aisle`, `rack`, `row_name`, `position`, `zone`, `is_active`)
VALUES
    ('QUA_SHELL',   'QUA', 'SHELL', 'A', '01', 'Quarantine',  1),
    ('UNALLOCATED', 'UNA', NULL,    NULL, NULL, 'Unallocated', 1),
    ('STAGING',     'STG', NULL,    NULL, NULL, 'Staging',     1);

-- =============================================================================
-- INCREMENTAL ENSURE BLOCK (live-DB safe)
-- On a fresh DB every column below already exists (created above); on the live
-- dev DB the tables pre-date the port, so CREATE TABLE IF NOT EXISTS is a no-op
-- and these folded columns must be added idempotently. MariaDB 10.4 supports
-- `ADD COLUMN IF NOT EXISTS`; each statement is a no-op when already present.
-- =============================================================================

ALTER TABLE `stock_take`
  ADD COLUMN IF NOT EXISTS `counting_round` VARCHAR(10) DEFAULT NULL;

ALTER TABLE `activity_log`
  ADD COLUMN IF NOT EXISTS `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

SET FOREIGN_KEY_CHECKS = 1;
-- =============================================================================
-- Sanchaya WMS - Complete Database Schema (Final Version)
-- CKB × Shell Warehouse Management System
-- Updated: 2026-04-25 (synced with k-one.sql production dump)
-- =============================================================================

CREATE DATABASE IF NOT EXISTS sanchaya
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;
USE sanchaya;

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
    `drums_per_pallet` INT(11)       DEFAULT 4    COMMENT 'Legacy: drums per pallet',
    `uom_type`         ENUM('Drum','Carton','Pail','EA','Bags') DEFAULT 'Drum',
    `uom_per_pallet`   INT(11)       DEFAULT 4    COMMENT 'UOM quantity per pallet: 4/36/44/48/24',
    `liters_per_unit`  DECIMAL(10,2) DEFAULT 209.00 COMMENT 'Liters per unit for reporting',
    `max_sku_qty`      INT(11)       DEFAULT 44   COMMENT 'Max quantity per SKU',
    `max_trans_qty`    INT(11)       DEFAULT 80,
    `default_location` VARCHAR(50)   DEFAULT NULL,
    `max_per_transaction` INT(11)    DEFAULT 80,
    `reorder_level`    INT(11)       DEFAULT 0    COMMENT 'Reorder level',
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
    `location_code` VARCHAR(20) NOT NULL UNIQUE  COMMENT 'e.g. CA01A01',
    `aisle`         VARCHAR(10) DEFAULT NULL,
    `rack`          VARCHAR(10) DEFAULT NULL,
    `row_name`      VARCHAR(10) DEFAULT NULL,
    `position`      VARCHAR(10) DEFAULT NULL,
    `zone`          VARCHAR(20) DEFAULT NULL      COMMENT 'Bulk / Carton / Pail / Special',
    `is_active`     TINYINT(1)  NOT NULL DEFAULT 1,
    `created_at`    TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_aisle` (`aisle`),
    KEY `idx_zone`  (`zone`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Master data semua lokasi gudang';

CREATE TABLE IF NOT EXISTS `warehouse_locations` (
    `id`         INT(11) AUTO_INCREMENT PRIMARY KEY,
    `location`   VARCHAR(20) UNIQUE NOT NULL,
    `aisle`      VARCHAR(5)  DEFAULT NULL,
    `bay`        INT(11)     DEFAULT NULL,
    `row_code`   VARCHAR(2)  DEFAULT NULL,
    `slot`       INT(11)     DEFAULT NULL,
    `is_active`  TINYINT(1)  DEFAULT 1,
    `created_at` TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- =============================================================================
-- INBOUND TABLES
-- =============================================================================

CREATE TABLE IF NOT EXISTS `inbound_orders` (
    `id`               INT(11) AUTO_INCREMENT PRIMARY KEY,
    `order_number`     VARCHAR(50)  UNIQUE NOT NULL        COMMENT 'Auto-generated order number (IN-YYYYMM-NNNN)',
    `order_date`       DATE         NOT NULL,
    `carrier_name`     VARCHAR(100) DEFAULT NULL           COMMENT 'Carrier / Transporter name (free text)',
    `container_no`     VARCHAR(50)  DEFAULT NULL           COMMENT 'Container number',
    `po_number`        VARCHAR(50)  DEFAULT NULL,
    `shipment_no`      VARCHAR(100) DEFAULT NULL           COMMENT 'Shipment Number',
    `do_number`        VARCHAR(100) DEFAULT NULL           COMMENT 'Delivery Order Number',
    `armada_no`        VARCHAR(50)  DEFAULT NULL           COMMENT 'Vehicle/Armada number',
    `production_date`  DATE         DEFAULT NULL           COMMENT 'Production date for auto expiry calculation',
    `expected_date`    DATE         DEFAULT NULL,
    `status`           ENUM('Draft','Dues In','Receiving','Good Received','Goods Received','Unserviceable','Picked','ATP','Completed','Cancelled')
                       NOT NULL DEFAULT 'Draft',
    `notes`            TEXT,
    `remarks`          TEXT,
    `received_by`      INT(11)      DEFAULT NULL           COMMENT 'User who received',
    `received_by_name` VARCHAR(100) DEFAULT NULL           COMMENT 'Name of receiver',
    `received_date`    DATE         DEFAULT NULL           COMMENT 'Actual receive date',
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
    `od_number`         VARCHAR(100) DEFAULT NULL   COMMENT 'OD Number per item (from planning)',
    `so_number`         VARCHAR(100) DEFAULT NULL   COMMENT 'SO Number per item (from planning)',
    `product_id`        INT(11) NOT NULL,
    `batch_no`          VARCHAR(100) DEFAULT NULL,
    `location`          VARCHAR(30)  DEFAULT NULL,
    `quantity`          DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT 'Order quantity',
    `uom`               VARCHAR(20)   DEFAULT 'Drum' COMMENT 'Unit of Measure',
    `actual_qty`        DECIMAL(10,2) DEFAULT 0     COMMENT 'Actual received quantity',
    `pallet`            DECIMAL(10,2) DEFAULT 0     COMMENT 'Pallet quantity',
    `pallet_no`         VARCHAR(50)   DEFAULT NULL  COMMENT 'Nomor label palet fisik, e.g. PLT-001',
    `manufacture_date`  DATE          DEFAULT NULL  COMMENT 'Manufacture/Production date',
    `exp_date`          DATE          DEFAULT NULL  COMMENT 'Expiry date',
    `stock_status`      ENUM('Accepted','Rejected','Pending') NOT NULL DEFAULT 'Pending'
                        COMMENT 'Stock acceptance status - auto-set from in_process_status',
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
    `order_number`     VARCHAR(50)  UNIQUE NOT NULL        COMMENT 'Auto-generated order number (OUT-YYYYMM-NNNN)',
    `order_date`       DATE         NOT NULL,
    `customer_id`      INT(11)      NOT NULL               COMMENT 'Legacy: customer per order',
    `so_number`        VARCHAR(50)  DEFAULT NULL           COMMENT 'Sales Order number',
    `do_number`        VARCHAR(50)  DEFAULT NULL           COMMENT 'Delivery Order number',
    `shipment_number`  VARCHAR(50)  DEFAULT NULL           COMMENT 'Shipment Number from VL06L/Outbound Excel',
    `ship_to_name`     VARCHAR(255) DEFAULT NULL           COMMENT 'Name of ship-to party',
    `ship_to_location` VARCHAR(100) DEFAULT NULL           COMMENT 'Location/city of ship-to party',
    `ship_to_street`   VARCHAR(500) DEFAULT NULL           COMMENT 'Street address of ship-to party',
    `destination`      VARCHAR(255) DEFAULT NULL,
    `kota`             VARCHAR(100) DEFAULT NULL           COMMENT 'Destination city',
    `armada_no`        VARCHAR(50)  DEFAULT NULL           COMMENT 'Vehicle number',
    `container_no`     VARCHAR(50)  DEFAULT NULL           COMMENT 'Container number',
    `jenis_armada`     VARCHAR(50)  DEFAULT NULL           COMMENT 'Vehicle type',
    `expected_date`    DATE         DEFAULT NULL,
    `status`           ENUM('Open','Picking','Picked','Shipped','Delivered','Completed','Cancelled')
                       DEFAULT 'Open',
    `shipped_date`     DATE         DEFAULT NULL,
    `notes`            TEXT,
    `shipped_by`       INT(11)      DEFAULT NULL           COMMENT 'User who shipped',
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
    `seq`              TINYINT(3) UNSIGNED NOT NULL DEFAULT 1  COMMENT 'Urutan tujuan ke-n',
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
    `quantity`           DECIMAL(10,2) NOT NULL DEFAULT 0  COMMENT 'Order quantity',
    `uom`                VARCHAR(20)  DEFAULT 'Drum'       COMMENT 'Unit of Measure',
    `actual_qty`         DECIMAL(10,2) DEFAULT 0           COMMENT 'Actual shipped quantity',
    `pallet`             DECIMAL(10,2) DEFAULT 0           COMMENT 'Pallet quantity',
    `batch_no`           VARCHAR(100) DEFAULT NULL         COMMENT 'Batch number (FEFO)',
    `exp_date`           DATE         DEFAULT NULL         COMMENT 'Expiry date (FEFO)',
    `location`           VARCHAR(30)  DEFAULT NULL,
    `in_process_status`  ENUM('Goods Received','ATP','Unserviceable') DEFAULT 'Goods Received'
                         COMMENT 'Outbound item process status',
    `gr_plan_no`         VARCHAR(100) DEFAULT NULL         COMMENT 'GR Plan Number',
    `transaction_no`     VARCHAR(100) DEFAULT NULL         COMMENT 'Transaction Number',
    `notes`              TEXT,
    `od_number`          VARCHAR(100) DEFAULT NULL         COMMENT 'Outbound Delivery number per item',
    `so_number`          VARCHAR(100) DEFAULT NULL         COMMENT 'Sales Order number per item',
    `destination_id`     INT(11)      DEFAULT NULL         COMMENT 'FK to outbound_destinations',
    `created_at`         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `batch_number`       VARCHAR(100) DEFAULT NULL,
    `stock_location_id`  INT(11)      DEFAULT NULL,
    FOREIGN KEY (`outbound_order_id`) REFERENCES `outbound_orders`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`product_id`)        REFERENCES `products`(`id`),
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
    `uom`              VARCHAR(20)  DEFAULT 'Drum'  COMMENT 'Unit of Measure',
    `uom_type`         ENUM('Drum','Carton','Pail') DEFAULT 'Drum',
    `uom_per_pallet`   INT(11)      DEFAULT 4,
    `pallet`           DECIMAL(10,2) DEFAULT 0      COMMENT 'Pallet quantity',
    `manufacture_date` DATE         DEFAULT NULL,
    `production_date`  DATE         DEFAULT NULL,
    `expiry_date`      DATE         DEFAULT NULL,
    `stock_status`     ENUM('Available','Reserved','Expired','Dues In') DEFAULT 'Available',
    `created_at`       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`product_id`) REFERENCES `products`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `stock_locations` (
    `id`               INT(11) AUTO_INCREMENT PRIMARY KEY,
    `stock_id`         INT(11)      DEFAULT NULL        COMMENT 'FK → stock.id, NULL during inbound Draft/Open',
    `location_code`    VARCHAR(20)  NOT NULL             COMMENT 'FK → location_master.location_code',
    `pallet_seq`       SMALLINT(6)  NOT NULL DEFAULT 1   COMMENT 'Pallet sequence within this inbound item',
    `quantity`         DECIMAL(10,2) NOT NULL DEFAULT 0,
    `original_quantity` DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT 'Qty saat inbound — tidak berubah walaupun sudah dipick',
    `uom`              VARCHAR(20)  DEFAULT 'EA',
    `is_full_pallet`   TINYINT(1)   DEFAULT 1,
    `batch_number`     VARCHAR(100) DEFAULT NULL,
    `inbound_item_id`  INT(11)      DEFAULT NULL,
    `status`           ENUM('Available','Reserved','Picked','Empty') DEFAULT 'Available',
    `created_at`       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_stock`    (`stock_id`),
    KEY `idx_location` (`location_code`),
    KEY `idx_status`   (`status`),
    KEY `idx_batch`    (`batch_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Stok per pallet per lokasi';

CREATE TABLE IF NOT EXISTS `outbound_item_locations` (
    `id`                INT(11) AUTO_INCREMENT PRIMARY KEY,
    `outbound_item_id`  INT(11) NOT NULL,
    `stock_location_id` INT(11) NOT NULL,
    `quantity`          DECIMAL(10,2) NOT NULL DEFAULT 0,
    `created_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_item_loc` (`outbound_item_id`, `stock_location_id`),
    KEY `idx_oi` (`outbound_item_id`),
    KEY `idx_sl` (`stock_location_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Per-lokasi alokasi untuk outbound item';

CREATE TABLE IF NOT EXISTS `location_allocations` (
    `id`             INT(11) AUTO_INCREMENT PRIMARY KEY,
    `reference_type` ENUM('Inbound','Outbound','Stock') NOT NULL,
    `reference_id`   INT(11) NOT NULL,
    `item_id`        INT(11) NOT NULL              COMMENT 'Item ID from inbound_items/outbound_items',
    `pallet_number`  INT(11) NOT NULL              COMMENT 'Sequential pallet number',
    `location`       VARCHAR(50) NOT NULL,
    `quantity`       DECIMAL(10,2) NOT NULL,
    `uom`            VARCHAR(20)  DEFAULT 'Drum',
    `is_full`        TINYINT(1)   DEFAULT 1        COMMENT '1 = full pallet, 0 = partial',
    `created_at`     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_ref` (`reference_type`, `reference_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Legacy allocation table (kept for compatibility)';

CREATE TABLE IF NOT EXISTS `stock_ledger` (
    `id`               INT(11) AUTO_INCREMENT PRIMARY KEY,
    `transaction_date` DATE NOT NULL,
    `product_id`       INT(11) NOT NULL,
    `transaction_type` ENUM('IN','OUT','ADJUSTMENT','TRANSFER') NOT NULL,
    `reference_type`   VARCHAR(50) DEFAULT NULL    COMMENT 'Inbound/Outbound/Adjustment',
    `reference_id`     INT(11)     DEFAULT NULL,
    `reference_number` VARCHAR(50) DEFAULT NULL    COMMENT 'Order number',
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
    FOREIGN KEY (`product_id`)  REFERENCES `products`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- STOCK TAKE TABLES
-- =============================================================================

CREATE TABLE IF NOT EXISTS `stock_take` (
    `id`          INT(11) AUTO_INCREMENT PRIMARY KEY,
    `take_number` VARCHAR(50) UNIQUE NOT NULL,
    `take_date`   DATE NOT NULL,
    `status`      ENUM('Draft','In Progress','Completed','Cancelled') DEFAULT 'Draft',
    `notes`       TEXT,
    `created_by`  INT(11) NOT NULL,
    `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`created_by`) REFERENCES `users`(`id`),
    UNIQUE KEY `uk_take_number` (`take_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `stock_take_items` (
    `id`            INT(11) AUTO_INCREMENT PRIMARY KEY,
    `stock_take_id` INT(11) NOT NULL,
    `product_id`    INT(11) NOT NULL,
    `batch_number`  VARCHAR(100) DEFAULT NULL,
    `uom`           VARCHAR(20)  DEFAULT NULL   COMMENT 'UOM item (DRUMS, CARTON, PAIL, dll)',
    `location`      VARCHAR(100) DEFAULT NULL,
    `qty_system`    DECIMAL(10,2) NOT NULL DEFAULT 0,
    `counter_1`     DECIMAL(10,2) DEFAULT NULL  COMMENT 'Hitungan pertama (Counter 1)',
    `counter_2`     DECIMAL(10,2) DEFAULT NULL  COMMENT 'Hitungan kedua (Counter 2)',
    `counter_3`     DECIMAL(10,2) DEFAULT NULL  COMMENT 'Hitungan ketiga, opsional (Counter 3)',
    `qty_physical`  DECIMAL(10,2) NOT NULL DEFAULT 0,
    `difference`    DECIMAL(10,2) NOT NULL DEFAULT 0,
    `status`        ENUM('Plus','Minus','Clear') NOT NULL DEFAULT 'Clear',
    `notes`         TEXT,
    `counter_by`    VARCHAR(100) DEFAULT NULL   COMMENT 'Nama counter/petugas',
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
-- ACTIVITY LOG TABLE
-- =============================================================================

CREATE TABLE IF NOT EXISTS `activity_log` (
    `id`             INT(11) AUTO_INCREMENT PRIMARY KEY,
    `user_id`        INT(11)      DEFAULT NULL,
    `username`       VARCHAR(100) DEFAULT NULL,
    `full_name`      VARCHAR(100) DEFAULT NULL,
    `action`         VARCHAR(100) NOT NULL   COMMENT 'e.g. CREATE_INBOUND, PICK_OUTBOUND, BIN_TRANSFER',
    `module`         VARCHAR(50)  NOT NULL   COMMENT 'e.g. inbound, outbound, bin_transfer',
    `reference_type` VARCHAR(50)  DEFAULT NULL COMMENT 'e.g. Inbound, Outbound, BinTransfer',
    `reference_id`   INT(11)      DEFAULT NULL,
    `reference_no`   VARCHAR(100) DEFAULT NULL,
    `description`    TEXT         DEFAULT NULL,
    `old_value`      TEXT         DEFAULT NULL COMMENT 'JSON snapshot sebelum perubahan',
    `new_value`      TEXT         DEFAULT NULL COMMENT 'JSON snapshot sesudah perubahan',
    `ip_address`     VARCHAR(45)  DEFAULT NULL,
    `created_at`     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
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
-- INDEXES
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
-- DEFAULT DATA
-- =============================================================================

-- Default admin user (password: password)
INSERT INTO `users` (`username`, `password`, `full_name`, `email`, `role`, `is_active`) VALUES
('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Administrator', 'admin@sanchaya.com', 'admin', 1)
ON DUPLICATE KEY UPDATE `id` = `id`;

-- Default settings
INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES
('warehouse_name',    'Shell CKB Warehouse'),
('drums_per_pallet',  '4')
ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`);

-- Special location entries
INSERT IGNORE INTO `location_master`
    (`location_code`, `aisle`, `rack`, `row_name`, `position`, `zone`, `is_active`)
VALUES
    ('QUA_SHELL',   'QUA', 'SHELL', 'A', '01', 'Quarantine',  1),
    ('UNALLOCATED', 'UNA', NULL,    NULL, NULL, 'Unallocated', 1),
    ('STAGING',     'STG', NULL,    NULL, NULL, 'Staging',     1);

SET FOREIGN_KEY_CHECKS = 1;

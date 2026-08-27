-- =============================================================================
-- 005 — Replenishment Suggestions (Phase 3 of WMS upgrade — spec-4)
-- Source: D:\K-one-v2\apps\api\src\database\migrations\005-replenishment.sql
-- Translations: BIGSERIAL → INT(11) AUTO_INCREMENT; NUMERIC → DECIMAL.
--   pick_face_targets is already defined in 001-base-schema.sql — kept
--   idempotent (CREATE TABLE IF NOT EXISTS) exactly as in v2.
-- =============================================================================

CREATE TABLE IF NOT EXISTS `pick_face_targets` (
    `id`          INT(11) AUTO_INCREMENT PRIMARY KEY,
    `location_id` INT(11) NOT NULL,
    `product_id`  INT(11) NOT NULL,
    `min_qty`     DECIMAL(10,2) NOT NULL DEFAULT 0,
    `max_qty`     DECIMAL(10,2) NOT NULL DEFAULT 0,
    `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_pft_location_product` (`location_id`, `product_id`),
    CONSTRAINT `chk_pft_min_qty` CHECK (`min_qty` >= 0),
    CONSTRAINT `chk_pft_max_qty` CHECK (`max_qty` >= 0),
    FOREIGN KEY (`location_id`) REFERENCES `location_master`(`id`),
    FOREIGN KEY (`product_id`)  REFERENCES `products`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX IF NOT EXISTS `idx_pft_location` ON `pick_face_targets`(`location_id`);
CREATE INDEX IF NOT EXISTS `idx_pft_product`  ON `pick_face_targets`(`product_id`);
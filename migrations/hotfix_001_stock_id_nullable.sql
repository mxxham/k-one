ALTER TABLE `stock_locations`
    MODIFY COLUMN `stock_id` INT NULL DEFAULT NULL
        COMMENT 'FK → stock.id, diisi saat inbound complete (NULL saat inbound masih Draft/Open)';

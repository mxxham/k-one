ALTER TABLE `picklist_items`
    ADD COLUMN IF NOT EXISTS `outbound_item_id` INT DEFAULT NULL
        COMMENT 'FK → outbound_items.id for per-item SO/customer info'
        AFTER `picklist_id`;

ALTER TABLE `picklist_items`
    ADD INDEX IF NOT EXISTS `idx_pkl_outbound_item` (`outbound_item_id`);

UPDATE picklist_items pki
JOIN picklists pkl ON pkl.id = pki.picklist_id
JOIN outbound_item_locations oil
    ON oil.stock_location_id = pki.stock_location_id
JOIN outbound_items oi
    ON oi.id = oil.outbound_item_id
   AND oi.outbound_order_id = pkl.outbound_order_id
SET pki.outbound_item_id = oi.id
WHERE pki.outbound_item_id IS NULL;

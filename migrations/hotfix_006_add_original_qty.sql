ALTER TABLE `stock_locations`
    ADD COLUMN `original_quantity` DECIMAL(10,2) NOT NULL DEFAULT 0
        COMMENT 'Qty saat inbound — tidak berubah walaupun sudah dipick'
        AFTER `quantity`;

UPDATE stock_locations
SET original_quantity = quantity
WHERE quantity > 0;

UPDATE stock_locations sl
JOIN inbound_items ii ON sl.inbound_item_id = ii.id
JOIN products p ON ii.product_id = p.id
SET sl.original_quantity = CASE
    WHEN sl.is_full_pallet = 1 THEN COALESCE(p.uom_per_pallet, 4)
    ELSE COALESCE(ii.actual_qty / NULLIF(ii.pallet, 0), 4)
END
WHERE sl.original_quantity = 0;

UPDATE stock_locations
SET original_quantity = 4
WHERE original_quantity = 0;

UPDATE stock_locations
SET original_quantity = quantity
WHERE quantity > 0 AND original_quantity = 0;

UPDATE stock_locations sl
JOIN inbound_items ii ON sl.inbound_item_id = ii.id
JOIN products p ON ii.product_id = p.id
SET sl.original_quantity = CASE
    WHEN sl.is_f ll_pallet = 1 THEN COALESCE(p.uom_per_pallet, 4)
    ELSE COALESCE(ii.actual_qty / NULLIF(ii.pallet, 0), 4)
END
WHERE sl.original_quantity = 0;

UPDATE stock_locations
SET original_quantity = 4
WHERE original_quantity = 0;

SELECT pallet_seq, location_code, quantity, original_quantity, is_full_pallet, status
FROM stock_locations
ORDER BY id DESC
LIMIT 20;

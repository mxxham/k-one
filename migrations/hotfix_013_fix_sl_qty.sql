UPDATE stock_locations sl
JOIN inbound_items ii ON sl.inbound_item_id = ii.id
JOIN products p ON p.id = ii.product_id
SET
    sl.quantity = CASE
        WHEN sl.is_full_pallet = 1 THEN COALESCE(p.uom_per_pallet, 4)
        ELSE MOD(ii.actual_qty, COALESCE(p.uom_per_pallet, 4))
    END,
    sl.original_quantity = CASE
        WHEN sl.is_full_pallet = 1 THEN COALESCE(p.uom_per_pallet, 4)
        ELSE MOD(ii.actual_qty, COALESCE(p.uom_per_pallet, 4))
    END
WHERE sl.quantity = 0
  AND ii.actual_qty > 0;

SELECT sl.location_code, sl.quantity, sl.is_full_pallet, ii.actual_qty
FROM stock_locations sl
JOIN inbound_items ii ON sl.inbound_item_id = ii.id
WHERE sl.quantity > 0
LIMIT 10;

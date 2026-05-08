UPDATE stock_locations sl
JOIN inbound_items ii ON sl.inbound_item_id = ii.id
JOIN products p ON ii.product_id = p.id
SET sl.original_quantity = COALESCE(p.uom_per_pallet, 4)
WHERE sl.is_full_pallet = 1
  AND sl.original_quantity != COALESCE(p.uom_per_pallet, 4);

UPDATE stock_locations sl
JOIN inbound_items ii ON sl.inbound_item_id = ii.id
JOIN products p ON ii.product_id = p.id
JOIN (
    SELECT inbound_item_id,
           COUNT(*) as full_count,
           SUM(COALESCE(p2.uom_per_pallet,4)) as full_qty
    FROM stock_locations sl2
    JOIN inbound_items ii2 ON sl2.inbound_item_id = ii2.id
    JOIN products p2 ON ii2.product_id = p2.id
    WHERE sl2.is_full_pallet = 1
    GROUP BY sl2.inbound_item_id
) fc ON fc.inbound_item_id = sl.inbound_item_id
SET sl.original_quantity = GREATEST(0,
    COALESCE(ii.actual_qty, ii.quantity) - fc.full_qty
)
WHERE sl.is_full_pallet = 0;

SELECT
    sl.pallet_seq,
    sl.location_code,
    sl.quantity AS current_qty,
    sl.original_quantity,
    sl.is_full_pallet,
    sl.status,
    p.uom_per_pallet
FROM stock_locations sl
JOIN inbound_items ii ON sl.inbound_item_id = ii.id
JOIN products p ON ii.product_id = p.id
ORDER BY sl.inbound_item_id, sl.pallet_seq;

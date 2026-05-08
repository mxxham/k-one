USE trianawms;

INSERT INTO stock
    (product_id, batch_number, location, quantity, uom,
     pallet, manufacture_date, expiry_date, stock_status)
SELECT
    ii.product_id,
    ii.batch_number,
    COALESCE(NULLIF(ii.location,''), 'UNALLOCATED') AS location,
    (ii.actual_qty - COALESCE(sl_sum.allocated_qty, 0)) AS remainder_qty,
    ii.uom,
    CEIL((ii.actual_qty - COALESCE(sl_sum.allocated_qty, 0))
         / GREATEST(p.uom_per_pallet, 1))              AS pallet,
    ii.manufacture_date,
    ii.exp_date,
    CASE ii.in_process_status
        WHEN 'Unserviceable' THEN 'Rejected'
        WHEN 'ATP'           THEN 'Available'
        WHEN 'Goods Received'THEN 'Available'
        WHEN 'Picked'        THEN 'Available'
        ELSE 'Dues In'
    END AS stock_status
FROM inbound_items ii
JOIN inbound_orders io  ON io.id  = ii.inbound_order_id
JOIN products p         ON p.id   = ii.product_id
LEFT JOIN (
    SELECT inbound_item_id, SUM(quantity) AS allocated_qty
    FROM stock_locations
    GROUP BY inbound_item_id
) sl_sum ON sl_sum.inbound_item_id = ii.id
WHERE io.status = 'Completed'
  AND ii.in_process_status NOT IN ('Dues In')
  AND ii.in_process_status != 'Unserviceable'  
  AND (ii.actual_qty - COALESCE(sl_sum.allocated_qty, 0)) > 0.001;

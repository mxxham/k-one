DELETE s FROM stock s
WHERE s.stock_status = 'Available'
  AND EXISTS (
    SELECT 1 FROM inbound_items ii
    WHERE (ii.stock_status = 'Rejected' OR ii.in_process_status = 'Unserviceable')
      AND ii.product_id = s.product_id
      AND COALESCE(ii.batch_number, ii.batch_no, '') = COALESCE(s.batch_number, '')
  );

DELETE sl FROM stock_locations sl
INNER JOIN inbound_items ii ON sl.inbound_item_id = ii.id
WHERE ii.stock_status = 'Rejected'
   OR ii.in_process_status = 'Unserviceable';

UPDATE inbound_items
SET stock_status = 'Rejected',
    in_process_status = 'Unserviceable',
    location = 'QUA_SHELL'
WHERE stock_status = 'Rejected'
   OR in_process_status = 'Unserviceable';

INSERT INTO stock (product_id, batch_number, location, quantity, uom, pallet,
                   manufacture_date, expiry_date, stock_status)
SELECT
    ii.product_id,
    COALESCE(ii.batch_number, ii.batch_no),
    'QUA_SHELL',
    SUM(ii.actual_qty),
    MAX(ii.uom),
    SUM(ii.pallet),
    MAX(ii.manufacture_date),
    MAX(ii.exp_date),
    'Rejected'
FROM inbound_items ii
JOIN inbound_orders io ON io.id = ii.inbound_order_id
WHERE (ii.stock_status = 'Rejected' OR ii.in_process_status = 'Unserviceable')
  AND io.status = 'Completed'
GROUP BY ii.product_id, COALESCE(ii.batch_number, ii.batch_no)
ON DUPLICATE KEY UPDATE
    quantity = quantity + VALUES(quantity),
    pallet   = pallet + VALUES(pallet);

SELECT
    CASE WHEN stock_status = 'Available' THEN '✅ Available (pickable)'
         WHEN stock_status = 'Rejected'  THEN '⛔ Rejected (QUA_SHELL only)'
         ELSE stock_status END AS status_label,
    COUNT(*) as jumlah,
    SUM(quantity) as total_qty
FROM stock GROUP BY stock_status;

DELETE oi1 FROM outbound_items oi1
INNER JOIN outbound_items oi2
    ON oi1.outbound_order_id = oi2.outbound_order_id
    AND oi1.product_id = oi2.product_id
    AND oi1.batch_no <=> oi2.batch_no
    AND oi1.id > oi2.id;

SELECT product_id, batch_number, location, stock_status, COUNT(*) as cnt, SUM(quantity) as total_qty
FROM stock
GROUP BY product_id, batch_number, location, stock_status
HAVING cnt > 1;

UPDATE stock SET stock_status = 'Rejected'
WHERE location = 'QUA_SHELL' AND stock_status = 'Available';

UPDATE outbound_items oi
JOIN outbound_orders oo ON oo.id = oi.outbound_order_id
SET oi.location = NULL
WHERE oi.location = 'QUA_SHELL'
  AND (oi.in_process_status IS NULL OR oi.in_process_status = 'ATP');

SELECT oi.id, oi.in_process_status, oi.location, oi.quantity
FROM outbound_items oi
JOIN outbound_orders oo ON oo.id = oi.outbound_order_id
WHERE oo.status IN ('Picked','Picking','Open')
ORDER BY oi.outbound_order_id, oi.id;

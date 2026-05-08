UPDATE outbound_items
SET batch_number = batch_no
WHERE (batch_number IS NULL OR batch_number = '')
  AND batch_no IS NOT NULL
  AND batch_no != '';

UPDATE outbound_items
SET batch_no = batch_number
WHERE (batch_no IS NULL OR batch_no = '')
  AND batch_number IS NOT NULL
  AND batch_number != '';

UPDATE stock s
SET s.stock_status = 'Available'
WHERE s.stock_status = 'Reserved'
  AND s.quantity > 0
  AND NOT EXISTS (
      SELECT 1 FROM outbound_items oi
      JOIN outbound_orders oo ON oi.outbound_order_id = oo.id
      WHERE oi.product_id = s.product_id
        AND (oi.batch_no = s.batch_number OR oi.batch_number = s.batch_number)
        AND oo.status IN ('Open','Picking','Picked')
  );

DELETE FROM stock WHERE quantity <= 0 AND stock_status = 'Reserved';

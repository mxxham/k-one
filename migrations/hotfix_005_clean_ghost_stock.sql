DELETE FROM stock
WHERE quantity <= 0;

DELETE FROM stock
WHERE quantity <= 0
  AND (batch_number IS NULL OR batch_number = '');

UPDATE stock_locations
SET status = 'Picked'
WHERE quantity <= 0
  AND status != 'Picked';

SELECT
    p.product_code,
    s.batch_number,
    s.location,
    s.quantity,
    s.uom,
    s.stock_status
FROM stock s
JOIN products p ON s.product_id = p.id
ORDER BY p.product_code, s.batch_number, s.location;

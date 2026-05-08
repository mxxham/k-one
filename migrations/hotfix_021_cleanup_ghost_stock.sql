USE trianawms;

SELECT s.id, s.product_id, p.product_name, s.batch_number,
       s.location, s.quantity, s.stock_status, s.created_at,
       'GHOST (no sl link, duplicate loc exists)' AS reason
FROM stock s
JOIN products p ON p.id = s.product_id
WHERE s.quantity > 0
  AND s.stock_status != 'Rejected'
  AND NOT EXISTS (
      SELECT 1 FROM stock_locations sl WHERE sl.stock_id = s.id
  )
  AND EXISTS (
      SELECT 1 FROM stock s2
      JOIN stock_locations sl2 ON sl2.stock_id = s2.id
      WHERE s2.product_id = s.product_id
        AND s2.batch_number <=> s.batch_number
        AND s2.location = s.location
        AND s2.id != s.id
  )
ORDER BY s.product_id, s.batch_number, s.location;

SELECT
    p.product_name,
    s.batch_number,
    SUM(s.quantity)                            AS total_stock,
    SUM(CASE WHEN s.stock_status='Rejected'
             OR s.location='QUA_SHELL' THEN s.quantity ELSE 0 END) AS rejected_stock,
    SUM(CASE WHEN s.stock_status!='Rejected'
             AND (s.location IS NULL OR s.location!='QUA_SHELL')
             THEN s.quantity ELSE 0 END)       AS available_stock,
    ib.total_inbound_qty,
    ib.total_inbound_qty - SUM(CASE WHEN s.stock_status!='Rejected'
             AND (s.location IS NULL OR s.location!='QUA_SHELL')
             THEN s.quantity ELSE 0 END)       AS discrepancy
FROM stock s
JOIN products p ON p.id = s.product_id
LEFT JOIN (
    SELECT ii.product_id, ii.batch_number,
           SUM(ii.actual_qty) AS total_inbound_qty
    FROM inbound_items ii
    JOIN inbound_orders io ON io.id = ii.inbound_order_id
    WHERE io.status = 'Completed'
      AND ii.in_process_status NOT IN ('Dues In')
    GROUP BY ii.product_id, ii.batch_number
) ib ON ib.product_id = s.product_id AND ib.batch_number <=> s.batch_number
WHERE s.quantity > 0
GROUP BY s.product_id, s.batch_number
HAVING discrepancy != 0
ORDER BY ABS(discrepancy) DESC;

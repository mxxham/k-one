CREATE TABLE IF NOT EXISTS stock_backup_hotfix004
    AS SELECT * FROM stock;

DELETE s FROM stock s
WHERE EXISTS (
    SELECT 1
    FROM stock_locations sl
    JOIN inbound_items ii ON sl.inbound_item_id = ii.id
    JOIN inbound_orders io ON ii.inbound_order_id = io.id
    WHERE sl.stock_id = s.id
      AND io.status = 'Completed'
);

INSERT INTO stock
    (product_id, batch_number, location, quantity, uom, pallet,
     manufacture_date, expiry_date, stock_status)
SELECT
    ii.product_id                                                        AS product_id,
    COALESCE(sl.batch_number, ii.batch_number, ii.batch_no)             AS batch_number,
    sl.location_code                                                     AS location,
    sl.quantity                                                          AS quantity,
    COALESCE(sl.uom, ii.uom)                                            AS uom,
    ROUND(sl.quantity / GREATEST(COALESCE(p.uom_per_pallet, 4), 1), 4) AS pallet,
    ii.manufacture_date                                                  AS manufacture_date,
    ii.exp_date                                                          AS expiry_date,
    'Available'                                                          AS stock_status
FROM stock_locations sl
JOIN inbound_items ii  ON sl.inbound_item_id = ii.id
JOIN inbound_orders io ON ii.inbound_order_id = io.id
JOIN products p        ON ii.product_id = p.id
WHERE io.status = 'Completed'
  AND ii.stock_status != 'Rejected'
  AND sl.quantity > 0;

UPDATE stock_locations sl
JOIN inbound_items ii  ON sl.inbound_item_id = ii.id
JOIN inbound_orders io ON ii.inbound_order_id = io.id
JOIN stock s ON
    s.product_id   = ii.product_id
    AND s.location = sl.location_code
    AND (s.batch_number <=> COALESCE(sl.batch_number, ii.batch_number, ii.batch_no))
SET sl.stock_id = s.id
WHERE io.status = 'Completed';

SELECT
    p.product_code,
    p.product_name,
    s.batch_number,
    s.location,
    s.quantity,
    s.uom,
    s.stock_status
FROM stock s
JOIN products p ON s.product_id = p.id
WHERE s.stock_status = 'Available'
ORDER BY p.product_code, s.batch_number, s.location;

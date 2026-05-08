DELETE sl1 FROM stock_ledger sl1
INNER JOIN stock_ledger sl2
    ON  sl1.reference_type = 'Inbound'
    AND sl1.reference_type = sl2.reference_type
    AND sl1.reference_id   = sl2.reference_id
    AND sl1.product_id     = sl2.product_id
    AND sl1.batch_number  <=> sl2.batch_number
    AND sl1.id > sl2.id;

UPDATE stock s SET s.quantity = 0, s.pallet = 0
WHERE EXISTS (
    SELECT 1 FROM stock_locations sl
    JOIN inbound_items ii ON sl.inbound_item_id = ii.id
    WHERE sl.stock_id = s.id
);

UPDATE stock s
JOIN (
    SELECT sl.stock_id,
           SUM(sl.quantity) AS total_qty,
           SUM(CASE WHEN sl.is_full_pallet THEN 1 ELSE 0.5 END) AS total_plt
    FROM stock_locations sl
    WHERE sl.stock_id IS NOT NULL
      AND sl.status IN ('Available','Reserved')
    GROUP BY sl.stock_id
) agg ON s.id = agg.stock_id
SET s.quantity = agg.total_qty,
    s.pallet   = agg.total_plt;

DELETE FROM stock WHERE quantity <= 0 AND stock_status != 'Rejected';

SELECT stock_status, COUNT(*) as jumlah, SUM(quantity) as total_qty
FROM stock GROUP BY stock_status;

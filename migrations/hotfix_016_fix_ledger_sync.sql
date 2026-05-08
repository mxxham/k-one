DELETE FROM stock WHERE stock_status = 'Rejected' AND location = 'QUA_SHELL';

INSERT INTO stock (product_id, batch_number, location, quantity, uom, pallet,
                   manufacture_date, expiry_date, stock_status)
SELECT
    ii.product_id,
    COALESCE(ii.batch_number, ii.batch_no) AS batch_number,
    'QUA_SHELL' AS location,
    SUM(ii.actual_qty) AS quantity,
    ii.uom,
    SUM(ii.pallet) AS pallet,
    MAX(ii.manufacture_date) AS manufacture_date,
    MAX(ii.exp_date) AS expiry_date,
    'Rejected' AS stock_status
FROM inbound_items ii
JOIN inbound_orders io ON io.id = ii.inbound_order_id
WHERE ii.in_process_status = 'Unserviceable'
  AND io.status = 'Completed'
GROUP BY ii.product_id, COALESCE(ii.batch_number, ii.batch_no), ii.uom;

DELETE s1 FROM stock s1
INNER JOIN stock s2
    ON s1.product_id = s2.product_id
    AND s1.batch_number <=> s2.batch_number
    AND s1.location = s2.location
    AND s1.stock_status = s2.stock_status
    AND s1.id < s2.id
WHERE s1.stock_status = 'Available';

DELETE FROM stock WHERE quantity <= 0;

DELETE sl1 FROM stock_ledger sl1
INNER JOIN stock_ledger sl2
    ON sl1.reference_type = sl2.reference_type
    AND sl1.reference_id   = sl2.reference_id
    AND sl1.product_id     = sl2.product_id
    AND sl1.batch_number  <=> sl2.batch_number
    AND sl1.id > sl2.id
WHERE sl1.reference_type = 'Inbound';

SET @prod_id = 0;
SET @running = 0;
UPDATE stock_ledger sl
JOIN (
    SELECT id,
           product_id,
           @running := IF(@prod_id = product_id,
                          @running + quantity_in - quantity_out,
                          quantity_in - quantity_out) AS new_balance,
           @prod_id := product_id
    FROM stock_ledger
    ORDER BY product_id, id ASC
) calc ON sl.id = calc.id
SET sl.balance = calc.new_balance;

SELECT 'Stock summary:' AS info;
SELECT stock_status, location,
       COUNT(*) AS rows_count,
       SUM(quantity) AS total_qty
FROM stock
GROUP BY stock_status, location
ORDER BY stock_status, location;

SELECT 'Ledger summary:' AS info;
SELECT product_id,
       SUM(quantity_in) AS total_in,
       SUM(quantity_out) AS total_out,
       MAX(balance) AS last_balance
FROM stock_ledger
GROUP BY product_id;

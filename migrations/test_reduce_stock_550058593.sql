-- Reduce stock at pickface bin to trigger replenishment
UPDATE stock SET quantity = 5.00
WHERE product_id = 13338 AND location = 'CB30A01';

-- Verify
SELECT product_id, location, quantity FROM stock
WHERE product_id = 13338 AND location = 'CB30A01';

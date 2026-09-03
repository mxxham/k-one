-- Simulate low pickface stock for testing
-- Reduce CA01D01 stock from 48 to 5 units (below pickface_min=10)

UPDATE stock SET quantity = 5.00
WHERE product_id = 13343 AND location = 'CA01D01';

-- Verify
SELECT 'Stock after reduction:' as info;
SELECT product_id, location, quantity FROM stock
WHERE product_id = 13343 AND location = 'CA01D01';

SELECT 'Pickface config:' as info;
SELECT * FROM sku_pickface_config WHERE sku_id = 13343;

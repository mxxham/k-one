-- Set up pickface config for product 550058593 (id=13338)
-- Pickface bin: CB30A01 (has 12 units - below typical min)
-- Max = 50, Min = 10

INSERT INTO sku_pickface_config (sku_id, pickface_bin_id, pickface_max, pickface_min)
VALUES (13338, (SELECT id FROM location_master WHERE location_code = 'CB30A01'), 50, 10)
ON DUPLICATE KEY UPDATE pickface_max = 50, pickface_min = 10;

-- Verify
SELECT 'Pickface config:' as info;
SELECT * FROM sku_pickface_config WHERE sku_id = 13338;

SELECT 'Stock at CB30A01:' as info;
SELECT product_id, location, quantity FROM stock WHERE product_id = 13338 AND location = 'CB30A01';

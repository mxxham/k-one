-- Test Scenario: Auto-Replenishment
-- Product 13343 has stock in bulk locations
-- Setting up pickface config to trigger replenishment

-- Step 1: Pickface config for product 13343
-- Pickface bin: CA01D01 (location_code = 'CA01D01', id = 3967)
-- Max = 50, Min = 10
INSERT INTO sku_pickface_config (sku_id, pickface_bin_id, pickface_max, pickface_min)
VALUES (13343, 3967, 50, 10)
ON DUPLICATE KEY UPDATE pickface_max = 50, pickface_min = 10;

-- Verify setup
SELECT 'Pickface config:' as info;
SELECT * FROM sku_pickface_config WHERE sku_id = 13343;

SELECT 'Current stock for product 13343:' as info;
SELECT product_id, location, quantity, batch_number FROM stock WHERE product_id = 13343;

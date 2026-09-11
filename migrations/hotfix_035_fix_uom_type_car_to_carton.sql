-- Fix ALL products with uom_type='CAR' (invalid ENUM value)
-- 'CAR' is not in the ENUM('Drum','Carton','Pail','EA','Bags','Fluidbag','IBC')
-- These should be 'Carton' with UPP=44

-- Fix product 550025043 (known problematic)
UPDATE products SET uom_type = 'Carton', uom_per_pallet = 44 WHERE product_code = '550025043';

-- Fix all other products with uom_type='CAR'
UPDATE products SET uom_type = 'Carton', uom_per_pallet = 44 WHERE product_code = '550025195';
UPDATE products SET uom_type = 'Carton', uom_per_pallet = 44 WHERE product_code = '550043578';
UPDATE products SET uom_type = 'Carton', uom_per_pallet = 44 WHERE product_code = '550044280';
UPDATE products SET uom_type = 'Carton', uom_per_pallet = 44 WHERE product_code = '550046865';
UPDATE products SET uom_type = 'Carton', uom_per_pallet = 44 WHERE product_code = '550046910';
UPDATE products SET uom_type = 'Carton', uom_per_pallet = 44 WHERE product_code = '550048515';
UPDATE products SET uom_type = 'Carton', uom_per_pallet = 44 WHERE product_code = '550048516';
UPDATE products SET uom_type = 'Carton', uom_per_pallet = 44 WHERE product_code = '550049105';
UPDATE products SET uom_type = 'Carton', uom_per_pallet = 44 WHERE product_code = '550049106';
UPDATE products SET uom_type = 'Carton', uom_per_pallet = 44 WHERE product_code = '550051327';
UPDATE products SET uom_type = 'Carton', uom_per_pallet = 44 WHERE product_code = '550054809';
UPDATE products SET uom_type = 'Carton', uom_per_pallet = 44 WHERE product_code = '550072331';
UPDATE products SET uom_type = 'Carton', uom_per_pallet = 44 WHERE product_code = '550072332';
UPDATE products SET uom_type = 'Carton', uom_per_pallet = 44 WHERE product_code = '550076635';
UPDATE products SET uom_type = 'Carton', uom_per_pallet = 44 WHERE product_code = '550076636';
UPDATE products SET uom_type = 'Carton', uom_per_pallet = 44 WHERE product_code = '800007106';

-- Fix products with non-standard UPP values
UPDATE products SET uom_type = 'Carton', uom_per_pallet = 44 WHERE product_code = '550076253';
UPDATE products SET uom_type = 'Carton', uom_per_pallet = 44 WHERE product_code = '550028259';
UPDATE products SET uom_type = 'Carton', uom_per_pallet = 44 WHERE product_code = '550044598';
UPDATE products SET uom_type = 'Carton', uom_per_pallet = 44 WHERE product_code = '550046911';
UPDATE products SET uom_type = 'Carton', uom_per_pallet = 44 WHERE product_code = '550066664';

DELETE FROM products;

INSERT INTO products (product_code, product_name, category, uom_type, uom_per_pallet, description, reorder_level, max_per_transaction, created_at) VALUES

('RIMULA-R4', 'Shell Rimula R4 15W-40', 'Drum', 'Drum', 4, 'Heavy-duty diesel engine oil 209L', 20, 80, NOW()),
('RIMULA-R6', 'Shell Rimula R6 15W-40', 'Drum', 'Drum', 4, 'Synthetic technology diesel engine oil 209L', 16, 80, NOW()),
('GADUS-S2', 'Shell Gadus S2 V220AC', 'Drum', 'Drum', 4, 'Multipurpose extreme pressure grease 180KG', 12, 80, NOW()),
('TONEWAY-O', 'Shell Toneway S2 OGC 460', 'Drum', 'Drum', 4, 'Open gear lubricant 209L', 16, 80, NOW()),
('MYSella-S2', 'Shell Mysella S2 N40', 'Drum', 'Drum', 4, 'Gas engine oil 209L', 12, 80, NOW()),
('OMALA-O', 'Shell Omala S4 GX 460', 'Drum', 'Drum', 4, 'Synthetic industrial gear oil 209L', 16, 80, NOW()),
('CORENA-O', 'Shell Corena S4 R 68', 'Drum', 'Drum', 4, 'Air compressor oil 209L', 12, 80, NOW()),
('DORNA-AR', 'Shell Dorna AR 100', 'Drum', 'Drum', 4, 'Refrigeration compressor oil 209L', 12, 80, NOW()),

('HELIX-10W', 'Shell Helix HX8 10W-40', 'Carton', 'Carton', 36, 'Premium synthetic motor oil 1L x 12 bottles/carton', 72, 432, NOW()),
('HELIX-5W', 'Shell Helix Ultra 5W-40', 'Carton', 'Carton', 36, 'PurePlus synthetic motor oil 1L x 12 bottles/carton', 72, 432, NOW()),
('ADVANCE-4T', 'Shell Advance Ultra 4T 10W-40', 'Carton', 'Carton', 44, 'Synthetic motorcycle oil 1L x 12 bottles/carton', 88, 528, NOW()),
('ADVANCE-AX7', 'Shell Advance AX7 10W-40', 'Carton', 'Carton', 48, 'Semi-synthetic motorcycle oil 1L x 12 bottles/carton', 96, 576, NOW()),
('RIMULA-C1', 'Shell Rimula R4 15W-40 (Carton)', 'Carton', 'Carton', 36, 'Diesel engine oil 4L x 4/carton', 72, 432, NOW()),
('HELIX-HX7', 'Shell Helix HX7 10W-40', 'Carton', 'Carton', 44, 'Semi-synthetic motor oil 4L x 4/carton', 88, 528, NOW()),
('GADUS-S3', 'Shell Gadus S3 V220C 3KG', 'Carton', 'Carton', 48, 'Multipurpose grease 3KG x 8 pails/carton', 96, 576, NOW()),

('GADUS-P18', 'Shell Gadus S2 V220AC 18KG', 'Pail', 'Pail', 24, 'Multipurpose grease 18KG pail', 48, 288, NOW()),
('GADUS-P50', 'Shell Gadus S2 V220AC 50KG', 'Pail', 'Pail', 24, 'Multipurpose grease 50KG pail', 48, 288, NOW()),
('OMALA-P68', 'Shell Omala S2 GX 68 (Pail)', 'Pail', 'Pail', 24, 'Industrial gear oil 20L pail', 48, 288, NOW()),
('CORENA-P32', 'Shell Corena S3 R 32', 'Pail', 'Pail', 24, 'Air compressor oil 20L pail', 48, 288, NOW()),
('MYSella-P', 'Shell Mysella S2 N40 (Pail)', 'Pail', 'Pail', 24, 'Gas engine oil 20L pail', 48, 288, NOW());

<?php

require_once __DIR__ . '/../config/database.php';

$db = db();

$db->exec("SET FOREIGN_KEY_CHECKS=0");
$db->exec("DELETE FROM products");
$db->exec("SET FOREIGN_KEY_CHECKS=1");

$products = [
    
    [
        'product_code' => 'RIMULA-R4',
        'product_name' => 'Shell Rimula R4 15W-40',
        'category' => 'Drum',
        'uom_type' => 'Drum',
        'uom_per_pallet' => 4,
        'description' => 'Heavy-duty diesel engine oil 209L',
        'reorder_level' => 20,
        'max_per_transaction' => 80
    ],
    [
        'product_code' => 'RIMULA-R6',
        'product_name' => 'Shell Rimula R6 15W-40',
        'category' => 'Drum',
        'uom_type' => 'Drum',
        'uom_per_pallet' => 4,
        'description' => 'Synthetic technology diesel engine oil 209L',
        'reorder_level' => 16,
        'max_per_transaction' => 80
    ],
    [
        'product_code' => 'GADUS-S2',
        'product_name' => 'Shell Gadus S2 V220AC',
        'category' => 'Drum',
        'uom_type' => 'Drum',
        'uom_per_pallet' => 4,
        'description' => 'Multipurpose extreme pressure grease 180KG',
        'reorder_level' => 12,
        'max_per_transaction' => 80
    ],
    [
        'product_code' => 'TONEWAY-O',
        'product_name' => 'Shell Toneway S2 OGC 460',
        'category' => 'Drum',
        'uom_type' => 'Drum',
        'uom_per_pallet' => 4,
        'description' => 'Open gear lubricant 209L',
        'reorder_level' => 16,
        'max_per_transaction' => 80
    ],
    [
        'product_code' => 'MYSella-S2',
        'product_name' => 'Shell Mysella S2 N40',
        'category' => 'Drum',
        'uom_type' => 'Drum',
        'uom_per_pallet' => 4,
        'description' => 'Gas engine oil 209L',
        'reorder_level' => 12,
        'max_per_transaction' => 80
    ],
    [
        'product_code' => 'OMALA-O',
        'product_name' => 'Shell Omala S4 GX 460',
        'category' => 'Drum',
        'uom_type' => 'Drum',
        'uom_per_pallet' => 4,
        'description' => 'Synthetic industrial gear oil 209L',
        'reorder_level' => 16,
        'max_per_transaction' => 80
    ],
    [
        'product_code' => 'CORENA-O',
        'product_name' => 'Shell Corena S4 R 68',
        'category' => 'Drum',
        'uom_type' => 'Drum',
        'uom_per_pallet' => 4,
        'description' => 'Air compressor oil 209L',
        'reorder_level' => 12,
        'max_per_transaction' => 80
    ],
    [
        'product_code' => 'DORNA-AR',
        'product_name' => 'Shell Dorna AR 100',
        'category' => 'Drum',
        'uom_type' => 'Drum',
        'uom_per_pallet' => 4,
        'description' => 'Refrigeration compressor oil 209L',
        'reorder_level' => 12,
        'max_per_transaction' => 80
    ],

    
    [
        'product_code' => 'HELIX-10W',
        'product_name' => 'Shell Helix HX8 10W-40',
        'category' => 'Carton',
        'uom_type' => 'Carton',
        'uom_per_pallet' => 36,
        'description' => 'Premium synthetic motor oil 1L x 12 bottles/carton',
        'reorder_level' => 72,
        'max_per_transaction' => 432
    ],
    [
        'product_code' => 'HELIX-5W',
        'product_name' => 'Shell Helix Ultra 5W-40',
        'category' => 'Carton',
        'uom_type' => 'Carton',
        'uom_per_pallet' => 36,
        'description' => 'PurePlus synthetic motor oil 1L x 12 bottles/carton',
        'reorder_level' => 72,
        'max_per_transaction' => 432
    ],
    [
        'product_code' => 'ADVANCE-4T',
        'product_name' => 'Shell Advance Ultra 4T 10W-40',
        'category' => 'Carton',
        'uom_type' => 'Carton',
        'uom_per_pallet' => 44,
        'description' => 'Synthetic motorcycle oil 1L x 12 bottles/carton',
        'reorder_level' => 88,
        'max_per_transaction' => 528
    ],
    [
        'product_code' => 'ADVANCE-AX7',
        'product_name' => 'Shell Advance AX7 10W-40',
        'category' => 'Carton',
        'uom_type' => 'Carton',
        'uom_per_pallet' => 48,
        'description' => 'Semi-synthetic motorcycle oil 1L x 12 bottles/carton',
        'reorder_level' => 96,
        'max_per_transaction' => 576
    ],
    [
        'product_code' => 'RIMULA-C1',
        'product_name' => 'Shell Rimula R4 15W-40 (Carton)',
        'category' => 'Carton',
        'uom_type' => 'Carton',
        'uom_per_pallet' => 36,
        'description' => 'Diesel engine oil 4L x 4/carton',
        'reorder_level' => 72,
        'max_per_transaction' => 432
    ],
    [
        'product_code' => 'HELIX-HX7',
        'product_name' => 'Shell Helix HX7 10W-40',
        'category' => 'Carton',
        'uom_type' => 'Carton',
        'uom_per_pallet' => 44,
        'description' => 'Semi-synthetic motor oil 4L x 4/carton',
        'reorder_level' => 88,
        'max_per_transaction' => 528
    ],
    [
        'product_code' => 'GADUS-S3',
        'product_name' => 'Shell Gadus S3 V220C 3KG',
        'category' => 'Carton',
        'uom_type' => 'Carton',
        'uom_per_pallet' => 48,
        'description' => 'Multipurpose grease 3KG x 8 pails/carton',
        'reorder_level' => 96,
        'max_per_transaction' => 576
    ],

    
    [
        'product_code' => 'GADUS-P18',
        'product_name' => 'Shell Gadus S2 V220AC 18KG',
        'category' => 'Pail',
        'uom_type' => 'Pail',
        'uom_per_pallet' => 24,
        'description' => 'Multipurpose grease 18KG pail',
        'reorder_level' => 48,
        'max_per_transaction' => 288
    ],
    [
        'product_code' => 'GADUS-P50',
        'product_name' => 'Shell Gadus S2 V220AC 50KG',
        'category' => 'Pail',
        'uom_type' => 'Pail',
        'uom_per_pallet' => 24,
        'description' => 'Multipurpose grease 50KG pail',
        'reorder_level' => 48,
        'max_per_transaction' => 288
    ],
    [
        'product_code' => 'OMALA-P68',
        'product_name' => 'Shell Omala S2 GX 68 (Pail)',
        'category' => 'Pail',
        'uom_type' => 'Pail',
        'uom_per_pallet' => 24,
        'description' => 'Industrial gear oil 20L pail',
        'reorder_level' => 48,
        'max_per_transaction' => 288
    ],
    [
        'product_code' => 'CORENA-P32',
        'product_name' => 'Shell Corena S3 R 32',
        'category' => 'Pail',
        'uom_type' => 'Pail',
        'uom_per_pallet' => 24,
        'description' => 'Air compressor oil 20L pail',
        'reorder_level' => 48,
        'max_per_transaction' => 288
    ],
    [
        'product_code' => 'MYSella-P',
        'product_name' => 'Shell Mysella S2 N40 (Pail)',
        'category' => 'Pail',
        'uom_type' => 'Pail',
        'uom_per_pallet' => 24,
        'description' => 'Gas engine oil 20L pail',
        'reorder_level' => 48,
        'max_per_transaction' => 288
    ]
];

$stmt = $db->prepare("INSERT INTO products (product_code, product_name, category, uom_type, uom_per_pallet, description, reorder_level, max_per_transaction, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");

$drumCount = 0;
$cartonCount = 0;
$pailCount = 0;

foreach ($products as $product) {
    $stmt->execute([
        $product['product_code'],
        $product['product_name'],
        $product['category'],
        $product['uom_type'],
        $product['uom_per_pallet'],
        $product['description'],
        $product['reorder_level'],
        $product['max_per_transaction']
    ]);

    if ($product['uom_type'] === 'Drum')
        $drumCount++;
    elseif ($product['uom_type'] === 'Carton')
        $cartonCount++;
    elseif ($product['uom_type'] === 'Pail')
        $pailCount++;

    echo "✓ Product created: {$product['product_code']} - {$product['product_name']} ({$product['uom_type']}: {$product['uom_per_pallet']}/pal)\n";
}

echo "\n✅ Products seeded successfully! Total: " . count($products) . " products\n";
echo "📊 Breakdown:\n";
echo "   - Drum products: {$drumCount}\n";
echo "   - Carton products: {$cartonCount}\n";
echo "   - Pail products: {$pailCount}\n";
?>
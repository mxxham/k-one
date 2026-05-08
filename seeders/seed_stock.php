<?php

require_once __DIR__ . '/../config/database.php';

$db = db();

$db->exec("DELETE FROM stock_ledger");
$db->exec("DELETE FROM stock");

function calculateExpiry($productionDate)
{
    return date('Y-m-d', strtotime($productionDate . ' +4 years'));
}

$productsStmt = $db->query("SELECT id, product_code, product_name, uom_type, uom_per_pallet FROM products ORDER BY id");
$products = $productsStmt->fetchAll();

$locationsStmt = $db->query("SELECT location_code FROM locations WHERE location_type = 'Storage' ORDER BY location_code");
$locations = $locationsStmt->fetchAll(PDO::FETCH_COLUMN);

$stockItems = [];
$locationIndex = 0;

foreach ($products as $product) {
    $productId = $product['id'];
    $uomPerPallet = $product['uom_per_pallet'];

    
    $numBatches = rand(2, 3);

    for ($batch = 1; $batch <= $numBatches; $batch++) {
        
        $monthsAgo = rand(1, 36);
        $productionDate = date('Y-m-d', strtotime("-{$monthsAgo} months"));
        $expiryDate = calculateExpiry($productionDate);

        
        $daysUntilExpiry = floor((strtotime($expiryDate) - time()) / 86400);

        
        if ($daysUntilExpiry <= 0) {
            $stockStatus = 'Expired';
        } elseif ($daysUntilExpiry <= 120) {
            $stockStatus = 'Critical';
        } else {
            $stockStatus = 'Available';
        }

        
        $quantity = rand($uomPerPallet, $uomPerPallet * 5);
        $pallet = $quantity / $uomPerPallet;

        
        $location = $locations[$locationIndex % count($locations)];
        $locationIndex++;

        $stockItems[] = [
            'product_id' => $productId,
            'product_code' => $product['product_code'],
            'batch_number' => $product['product_code'] . '-' . date('Ym', strtotime($productionDate)) . sprintf('%02d', $batch),
            'quantity' => $quantity,
            'pallet' => $pallet,
            'uom_type' => $product['uom_type'],
            'uom_per_pallet' => $uomPerPallet,
            'production_date' => $productionDate,
            'expiry_date' => $expiryDate,
            'location' => $location,
            'stock_status' => $stockStatus
        ];

        
        $balance = $quantity;
        $ledgerStmt = $db->prepare("INSERT INTO stock_ledger (transaction_date, product_id, batch_number, transaction_type, quantity_in, reference_number, expiry_date, balance) VALUES (?, ?, ?, 'IN', ?, 'INITIAL', ?, ?)");
        $ledgerStmt->execute([
            $productionDate,
            $productId,
            $stockItems[count($stockItems) - 1]['batch_number'],
            $quantity,
            $expiryDate,
            $balance
        ]);
    }
}

$stmt = $db->prepare("INSERT INTO stock (product_id, batch_number, quantity, pallet, uom_type, uom_per_pallet, production_date, expiry_date, location, stock_status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");

$drumStock = 0;
$cartonStock = 0;
$pailStock = 0;

foreach ($stockItems as $stock) {
    $stmt->execute([
        $stock['product_id'],
        $stock['batch_number'],
        $stock['quantity'],
        $stock['pallet'],
        $stock['uom_type'],
        $stock['uom_per_pallet'],
        $stock['production_date'],
        $stock['expiry_date'],
        $stock['location'],
        $stock['stock_status']
    ]);

    if ($stock['uom_type'] === 'Drum')
        $drumStock += $stock['quantity'];
    elseif ($stock['uom_type'] === 'Carton')
        $cartonStock += $stock['quantity'];
    elseif ($stock['uom_type'] === 'Pail')
        $pailStock += $stock['quantity'];

    $daysLeft = floor((strtotime($stock['expiry_date']) - time()) / 86400);
    echo "✓ Stock created: {$stock['product_code']} | Batch: {$stock['batch_number']} | Qty: {$stock['quantity']} ({$stock['pallet']} pal) | Loc: {$stock['location']} | Exp: " . date('d M Y', strtotime($stock['expiry_date'])) . " ({$daysLeft} days) | Status: {$stock['stock_status']}\n";
}

echo "\n✅ Stock seeded successfully!\n";
echo "📊 Stock Summary:\n";
echo "   - Total batches: " . count($stockItems) . "\n";
echo "   - Drum items: {$drumStock}\n";
echo "   - Carton items: {$cartonStock}\n";
echo "   - Pail items: {$pailStock}\n";
?>
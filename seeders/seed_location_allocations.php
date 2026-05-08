<?php

require_once __DIR__ . '/../config/database.php';

$db = db();

$db->exec("DELETE FROM location_allocations");

$stockStmt = $db->query("SELECT id, product_id, batch_number, quantity, pallet, location FROM stock LIMIT 20");
$stockItems = $stockStmt->fetchAll();

$inboundStmt = $db->query("
    SELECT ii.id, ii.inbound_order_id, ii.product_id, ii.batch_no, ii.quantity, ii.pallet, ii.location
    FROM inbound_items ii
    JOIN inbound_orders io ON ii.inbound_order_id = io.id
    WHERE io.status = 'Pending'
    LIMIT 5
");
$inboundItems = $inboundStmt->fetchAll();

$allocationCount = 0;

foreach ($stockItems as $stock) {
    $db->prepare("INSERT INTO location_allocations (reference_type, reference_id, product_id, batch_number, location, quantity, pallet, allocated_date, status) VALUES ('STOCK', ?, ?, ?, ?, ?, ?, CURDATE(), 'Active')")->execute([
        $stock['id'],
        $stock['product_id'],
        $stock['batch_number'],
        $stock['location'],
        $stock['quantity'],
        $stock['pallet']
    ]);
    $allocationCount++;
    echo "✓ Stock allocated: Product ID {$stock['product_id']} | Batch: {$stock['batch_number']} | Loc: {$stock['location']}\n";
}

$aZoneLocations = ['A01', 'A02', 'A03', 'A04', 'A05'];
$aIndex = 0;

foreach ($inboundItems as $item) {
    $location = $aZoneLocations[$aIndex % count($aZoneLocations)];
    $aIndex++;

    $db->prepare("INSERT INTO location_allocations (reference_type, reference_id, product_id, batch_number, location, quantity, pallet, allocated_date, status) VALUES ('INBOUND', ?, ?, ?, ?, ?, ?, CURDATE(), 'Reserved')")->execute([
        $item['id'],
        $item['product_id'],
        $item['batch_no'],
        $location,
        $item['quantity'],
        $item['pallet']
    ]);
    $allocationCount++;
    echo "✓ Inbound allocated: Product ID {$item['product_id']} | Batch: {$item['batch_no']} | Loc: {$location} (Reserved)\n";
}

echo "\n✅ Location allocations seeded successfully! Total: {$allocationCount} allocations\n";
?>
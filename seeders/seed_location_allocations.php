<?php

require_once __DIR__ . '/../config/database.php';

$db = db();

$db->exec("SET FOREIGN_KEY_CHECKS=0");
$db->exec("DELETE FROM location_allocations");
$db->exec("SET FOREIGN_KEY_CHECKS=1");

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
    $db->prepare("INSERT INTO location_allocations (reference_type, reference_id, item_id, pallet_number, location, quantity, uom, is_full) VALUES ('STOCK', ?, ?, ?, ?, ?, 'Drum', 1)")->execute([
        $stock['product_id'],
        $stock['id'],
        ceil($stock['pallet']),
        $stock['location'],
        $stock['quantity']
    ]);
    $allocationCount++;
    echo "✓ Stock allocated: Product ID {$stock['product_id']} | Batch: {$stock['batch_number']} | Loc: {$stock['location']}\n";
}

$aZoneLocations = ['CA01A01', 'CA01A02', 'CA01B01', 'CA01B02', 'CA01C01'];
$aIndex = 0;

foreach ($inboundItems as $item) {
    $location = $aZoneLocations[$aIndex % count($aZoneLocations)];
    $aIndex++;

    $db->prepare("INSERT INTO location_allocations (reference_type, reference_id, item_id, pallet_number, location, quantity, uom, is_full) VALUES ('INBOUND', ?, ?, ?, ?, ?, 'Drum', 0)")->execute([
        $item['inbound_order_id'],
        $item['id'],
        ceil($item['pallet']),
        $location,
        $item['quantity']
    ]);
    $allocationCount++;
    echo "✓ Inbound allocated: Product ID {$item['product_id']} | Batch: {$item['batch_no']} | Loc: {$location} (Reserved)\n";
}

echo "\n✅ Location allocations seeded successfully! Total: {$allocationCount} allocations\n";
?>
<?php

require_once __DIR__ . '/../config/database.php';

$db = db();

$db->exec("DELETE FROM picklist_items");
$db->exec("DELETE FROM picklists");

$outboundStmt = $db->query("SELECT id, order_number FROM outbound_orders WHERE status = 'Shipped' LIMIT 3");
$outboundOrders = $outboundStmt->fetchAll();

$outboundItemsStmt = $db->query("
    SELECT oi.*, p.product_code, p.product_name, p.uom_per_pallet
    FROM outbound_items oi
    JOIN products p ON oi.product_id = p.id
    WHERE oi.outbound_order_id IN (
        SELECT id FROM outbound_orders WHERE status = 'Shipped' LIMIT 3
    )
");
$outboundItems = $outboundItemsStmt->fetchAll();

$stmtPicklist = $db->prepare("INSERT INTO picklists (outbound_order_id, picklist_number, picklist_date, status, picker_id, notes, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)");
$stmtPicklistItem = $db->prepare("INSERT INTO picklist_items (picklist_id, product_id, batch_number, location, quantity, pallet, picked_qty, status, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");

foreach ($outboundOrders as $order) {
    $picklistNumber = 'PKL-' . str_replace('OTB-', '', $order['order_number']);

    
    $stmtPicklist->execute([
        $order['id'],
        $picklistNumber,
        date('Y-m-d'),
        'Completed',
        4, 
        'All items picked and verified',
        date('Y-m-d')
    ]);

    $picklistId = $db->lastInsertId();
    echo "✓ Picklist created: {$picklistNumber} | Order: {$order['order_number']}\n";

    
    $orderItems = array_filter($outboundItems, function ($item) use ($order) {
        return $item['outbound_order_id'] == $order['id'];
    });

    foreach ($orderItems as $item) {
        $pickedQty = $item['quantity'];

        $stmtPicklistItem->execute([
            $picklistId,
            $item['product_id'],
            $item['batch_number'],
            $item['location'],
            $item['quantity'],
            $item['pallet'],
            $pickedQty,
            'Picked',
            'Item verified and picked'
        ]);

        echo "  - Item: {$item['product_code']} | Qty: {$pickedQty} ({$item['pallet']} pal) | Loc: {$item['location']}\n";
    }
}

echo "\n✅ Picklists seeded successfully! Total: " . count($outboundOrders) . " picklists\n";
?>
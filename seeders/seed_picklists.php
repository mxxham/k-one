<?php

require_once __DIR__ . '/../config/database.php';

$db = db();
$db->exec("SET FOREIGN_KEY_CHECKS=0");
$db->exec("DELETE FROM picklist_items");
$db->exec("DELETE FROM picklists");
$db->exec("SET FOREIGN_KEY_CHECKS=1");

$completedIds = $db->query("SELECT id FROM outbound_orders WHERE status = 'Shipped' ORDER BY id LIMIT 3")->fetchAll(PDO::FETCH_COLUMN);

$outboundOrders = [];
if ($completedIds) {
    $in = implode(',', array_map('intval', $completedIds));
    $outboundOrders = $db->query("SELECT id, order_number FROM outbound_orders WHERE id IN ($in)")->fetchAll();
}

$stmtPicklist = $db->prepare("INSERT INTO picklists (outbound_order_id, picklist_number, created_date, status, notes, created_by, created_at)
    VALUES (?, ?, ?, ?, ?, ?, ?)");
$stmtPicklistItem = $db->prepare("INSERT INTO picklist_items (picklist_id, product_id, batch_no, location, quantity, uom, pallet, picked_quantity, status, picker_id, notes)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

$adminId = (int)$db->query("SELECT id FROM users WHERE username = 'admin' LIMIT 1")->fetchColumn() ?: 1;

foreach ($outboundOrders as $order) {
    $picklistNumber = 'PKL-' . str_replace('OTB-', '', $order['order_number']);

    $stmtPicklist->execute([
        $order['id'],
        $picklistNumber,
        date('Y-m-d'),
        'Completed',
        'All items picked and verified',
        $adminId,
        date('Y-m-d H:i:s')
    ]);

    $picklistId = $db->lastInsertId();
    echo "✓ Picklist created: {$picklistNumber} | Order: {$order['order_number']}\n";

    $outboundItemsStmt = $db->prepare("
        SELECT oi.*, p.product_code, p.product_name
        FROM outbound_items oi
        JOIN products p ON oi.product_id = p.id
        WHERE oi.outbound_order_id = ?
    ");
    $outboundItemsStmt->execute([$order['id']]);
    $outboundItems = $outboundItemsStmt->fetchAll();

    foreach ($outboundItems as $item) {
        $pickedQty = $item['quantity'];

        $stmtPicklistItem->execute([
            $picklistId,
            $item['product_id'],
            $item['batch_no'],
            $item['location'],
            $item['quantity'],
            $item['uom'],
            $item['pallet'],
            $pickedQty,
            'Picked',
            $adminId,
            'Item verified and picked'
        ]);

        echo "  - Item: {$item['product_code']} | Qty: {$pickedQty} ({$item['pallet']} pal) | Loc: {$item['location']}\n";
    }
}

echo "\n✅ Picklists seeded successfully! Total: " . count($outboundOrders) . " picklists\n";
?>
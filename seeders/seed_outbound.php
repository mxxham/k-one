<?php

require_once __DIR__ . '/../config/database.php';

$db = db();

$db->exec("SET FOREIGN_KEY_CHECKS=0");
$db->exec("DELETE FROM picklist_items");
$db->exec("DELETE FROM picklists");
$db->exec("DELETE FROM outbound_items");
$db->exec("DELETE FROM outbound_orders");
$db->exec("SET FOREIGN_KEY_CHECKS=1");

$customersStmt = $db->query("SELECT id FROM customers");
$customers = $customersStmt->fetchAll(PDO::FETCH_COLUMN);

$productsStmt = $db->query("SELECT id, product_code, product_name, uom_type, uom_per_pallet FROM products LIMIT 8");
$products = $productsStmt->fetchAll();

$outboundOrders = [];

$outboundOrders[] = [
    'order_number'  => 'OTB-2024-0001',
    'customer_id'   => $customers[0],
    'so_number'     => 'SO-2024-00001',
    'do_number'     => 'DO-2024-00001',
    'shipment_number' => 'SHP-OUT-20240201',
    'order_date'    => '2024-02-01',
    'expected_date' => '2024-02-10',
    'shipped_date'  => '2024-02-08',
    'status'        => 'Shipped',
    'notes'         => 'Delivery completed successfully',
    'items' => [
        ['product_id' => $products[0]['id'], 'quantity' => 8,  'batch_no' => 'SHELL-20240101', 'location' => 'B01'],
        ['product_id' => $products[1]['id'], 'quantity' => 8,  'batch_no' => 'SHELL-20240105', 'location' => 'B02']
    ]
];

$outboundOrders[] = [
    'order_number'  => 'OTB-2024-0002',
    'customer_id'   => $customers[1] ?? $customers[0],
    'so_number'     => 'SO-2024-00002',
    'do_number'     => null,
    'shipment_number' => null,
    'order_date'    => '2024-02-20',
    'expected_date' => '2024-03-01',
    'shipped_date'  => null,
    'status'        => 'Open',
    'notes'         => 'Awaiting pickup confirmation',
    'items' => [
        ['product_id' => $products[2]['id'], 'quantity' => 12, 'batch_no' => 'SHELL-20240210', 'location' => 'B03'],
        ['product_id' => $products[3]['id'], 'quantity' => 8,  'batch_no' => 'SHELL-20240212', 'location' => 'B04'],
        ['product_id' => $products[4]['id'], 'quantity' => 16, 'batch_no' => 'SHELL-20240215', 'location' => 'C01']
    ]
];

$outboundOrders[] = [
    'order_number'  => 'OTB-2024-0003',
    'customer_id'   => $customers[2] ?? $customers[0],
    'so_number'     => 'SO-2024-00003',
    'do_number'     => 'DO-2024-00003',
    'shipment_number' => 'SHP-OUT-20240215',
    'order_date'    => '2024-02-15',
    'expected_date' => '2024-02-25',
    'shipped_date'  => '2024-02-23',
    'status'        => 'Shipped',
    'notes'         => 'Express delivery',
    'items' => [
        ['product_id' => $products[5]['id'], 'quantity' => 20, 'batch_no' => 'SHELL-20240216', 'location' => 'C02'],
        ['product_id' => $products[6]['id'], 'quantity' => 16, 'batch_no' => 'SHELL-20240217', 'location' => 'C03']
    ]
];

$outboundOrders[] = [
    'order_number'  => 'OTB-2024-0004',
    'customer_id'   => $customers[3] ?? $customers[0],
    'so_number'     => 'SO-2024-00004',
    'do_number'     => null,
    'shipment_number' => null,
    'order_date'    => date('Y-m-d'),
    'expected_date' => date('Y-m-d', strtotime('+7 days')),
    'shipped_date'  => null,
    'status'        => 'Open',
    'notes'         => 'New order, preparing for shipment',
    'items' => [
        ['product_id' => $products[7]['id'], 'quantity' => 24, 'batch_no' => 'SHELL-20240220', 'location' => 'D01']
    ]
];

$stmtOrder = $db->prepare("INSERT INTO outbound_orders
    (order_number, customer_id, so_number, do_number, shipment_number,
     order_date, expected_date, shipped_date, status, notes, created_by, created_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)");

$stmtItem = $db->prepare("INSERT INTO outbound_items
    (outbound_order_id, product_id, quantity, pallet, batch_no, location, notes)
    VALUES (?, ?, ?, ?, ?, ?, ?)");

foreach ($outboundOrders as $order) {

    $stmtOrder->execute([
        $order['order_number'],
        $order['customer_id'],
        $order['so_number'],
        $order['do_number'],
        $order['shipment_number'],
        $order['order_date'],
        $order['expected_date'],
        $order['shipped_date'],
        $order['status'],
        $order['notes'],
        $order['order_date']
    ]);

    $outboundId = $db->lastInsertId();
    echo "✓ Outbound order created: {$order['order_number']} | Status: {$order['status']}\n";

    foreach ($order['items'] as $item) {
        $uomPerPallet = 4;
        foreach ($products as $p) {
            if ($p['id'] == $item['product_id']) {
                $uomPerPallet = $p['uom_per_pallet'];
                break;
            }
        }

        $pallet = $item['quantity'] / $uomPerPallet;

        $stmtItem->execute([
            $outboundId,
            $item['product_id'],
            $item['quantity'],
            $pallet,
            $item['batch_no'],
            $item['location'],
            'Seeded data'
        ]);

        echo "  - Item: Product ID {$item['product_id']} | Qty: {$item['quantity']} ({$pallet} pal) | Batch: {$item['batch_no']} | Loc: {$item['location']}\n";
    }
}

echo "\n✅ Outbound orders seeded successfully! Total: " . count($outboundOrders) . " orders\n";
?>

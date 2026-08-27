<?php

require_once __DIR__ . '/../config/database.php';

$db = db();

$db->exec("SET FOREIGN_KEY_CHECKS=0");
$db->exec("DELETE FROM location_allocations");
$db->exec("DELETE FROM inbound_items");
$db->exec("DELETE FROM inbound_orders");
$db->exec("SET FOREIGN_KEY_CHECKS=1");

$productsStmt = $db->query("SELECT id, product_code, product_name, uom_type, uom_per_pallet FROM products LIMIT 10");
$products = $productsStmt->fetchAll();

$inboundOrders = [];

$inboundOrders[] = [
    'order_number'  => 'INB-2024-0001',
    'po_number'     => 'PO-2024-001234',
    'shipment_no'   => 'SHP-20240115',
    'carrier_name'  => 'PT Maju Jaya Logistics',
    'container_no'  => 'MSCU-1234567',
    'armada_no'     => 'Truck B-1234-XYZ',
    'order_date'    => '2024-01-15',
    'expected_date' => '2024-01-20',
    'received_date' => '2024-01-19',
    'received_by'   => 1,
    'status'        => 'Goods Received',
    'notes'         => 'Goods received in good condition',
    'items' => [
        ['product_id' => $products[0]['id'], 'quantity' => 20, 'batch_no' => 'SHELL-20240101', 'manufacture_date' => '2024-01-01', 'exp_date' => '2028-01-01', 'in_process_status' => 'Goods Received', 'stock_status' => 'Accepted', 'location' => 'B01'],
        ['product_id' => $products[1]['id'], 'quantity' => 16, 'batch_no' => 'SHELL-20240105', 'manufacture_date' => '2024-01-05', 'exp_date' => '2028-01-05', 'in_process_status' => 'Goods Received', 'stock_status' => 'Accepted', 'location' => 'B02']
    ]
];

$inboundOrders[] = [
    'order_number'  => 'INB-2024-0002',
    'po_number'     => 'PO-2024-001235',
    'shipment_no'   => 'SHP-20240201',
    'carrier_name'  => 'CV Cepat Kirim',
    'container_no'  => 'TCLU-7654321',
    'armada_no'     => 'Truck B-5678-ABC',
    'order_date'    => '2024-02-01',
    'expected_date' => '2024-02-15',
    'received_date' => null,
    'received_by'   => null,
    'status'        => 'Dues In',
    'notes'         => 'Expected delivery next week',
    'items' => [
        ['product_id' => $products[2]['id'], 'quantity' => 12, 'batch_no' => 'SHELL-20240210', 'manufacture_date' => '2024-02-10', 'exp_date' => '2028-02-10', 'in_process_status' => 'Dues In', 'stock_status' => 'Pending', 'location' => 'A01'],
        ['product_id' => $products[3]['id'], 'quantity' => 16, 'batch_no' => 'SHELL-20240212', 'manufacture_date' => '2024-02-12', 'exp_date' => '2028-02-12', 'in_process_status' => 'Dues In', 'stock_status' => 'Pending', 'location' => 'A02']
    ]
];

$inboundOrders[] = [
    'order_number'  => 'INB-2024-0003',
    'po_number'     => 'PO-2024-001236',
    'shipment_no'   => 'SHP-20240210',
    'carrier_name'  => 'PT Angkasa Logistik',
    'container_no'  => 'HLBU-9876543',
    'armada_no'     => 'Truck B-9999-XXX',
    'order_date'    => '2024-02-10',
    'expected_date' => '2024-02-20',
    'received_date' => '2024-02-18',
    'received_by'   => 1,
    'status'        => 'Goods Received',
    'notes'         => 'All items verified and received',
    'items' => [
        ['product_id' => $products[4]['id'], 'quantity' => 24, 'batch_no' => 'SHELL-20240215', 'manufacture_date' => '2024-02-15', 'exp_date' => '2028-02-15', 'in_process_status' => 'Goods Received', 'stock_status' => 'Accepted', 'location' => 'C01'],
        ['product_id' => $products[5]['id'], 'quantity' => 20, 'batch_no' => 'SHELL-20240216', 'manufacture_date' => '2024-02-16', 'exp_date' => '2028-02-16', 'in_process_status' => 'Goods Received', 'stock_status' => 'Accepted', 'location' => 'C02'],
        ['product_id' => $products[6]['id'], 'quantity' => 16, 'batch_no' => 'SHELL-20240217', 'manufacture_date' => '2024-02-17', 'exp_date' => '2028-02-17', 'in_process_status' => 'Goods Received', 'stock_status' => 'Accepted', 'location' => 'C03']
    ]
];

$stmtOrder = $db->prepare("INSERT INTO inbound_orders
    (order_number, po_number, shipment_no, carrier_name, container_no, armada_no,
     order_date, expected_date, received_date, received_by, status, notes, created_by, created_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)");

$stmtItem = $db->prepare("INSERT INTO inbound_items
    (inbound_order_id, product_id, quantity, pallet, batch_no,
     manufacture_date, exp_date, in_process_status, stock_status, location, actual_qty, notes)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

foreach ($inboundOrders as $order) {

    $stmtOrder->execute([
        $order['order_number'],
        $order['po_number'],
        $order['shipment_no'],
        $order['carrier_name'],
        $order['container_no'],
        $order['armada_no'],
        $order['order_date'],
        $order['expected_date'],
        $order['received_date'],
        $order['received_by'],
        $order['status'],
        $order['notes'],
        $order['order_date']
    ]);

    $inboundId = $db->lastInsertId();
    echo "✓ Inbound order created: {$order['order_number']} | Status: {$order['status']}\n";

    foreach ($order['items'] as $item) {
        $uomPerPallet = 4;
        foreach ($products as $p) {
            if ($p['id'] == $item['product_id']) {
                $uomPerPallet = $p['uom_per_pallet'];
                break;
            }
        }

        $pallet    = $item['quantity'] / $uomPerPallet;
        $actualQty = $order['status'] === 'Goods Received' ? $item['quantity'] : 0;

        $stmtItem->execute([
            $inboundId,
            $item['product_id'],
            $item['quantity'],
            $pallet,
            $item['batch_no'],
            $item['manufacture_date'],
            $item['exp_date'],
            $item['in_process_status'],
            $item['stock_status'],
            $item['location'],
            $actualQty,
            'Seeded data'
        ]);

        echo "  - Item: Product ID {$item['product_id']} | Qty: {$item['quantity']} ({$pallet} pal) | Batch: {$item['batch_no']}\n";
    }
}

echo "\n✅ Inbound orders seeded successfully! Total: " . count($inboundOrders) . " orders\n";
?>

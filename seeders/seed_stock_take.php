<?php

require_once __DIR__ . '/../config/database.php';

$db = db();

$db->exec("DELETE FROM stock_take_items");
$db->exec("DELETE FROM stock_take");

$stockStmt = $db->query("SELECT id, product_id, batch_number, location, quantity FROM stock LIMIT 15");
$stockItems = $stockStmt->fetchAll();

$stockTakes = [
    [
        'take_number' => 'ST-2024-001',
        'take_date' => '2024-01-31',
        'notes' => 'Monthly stock count - January',
        'status' => 'Completed',
        'created_by' => 2
    ],
    [
        'take_number' => 'ST-2024-002',
        'take_date' => '2024-02-28',
        'notes' => 'Monthly stock count - February',
        'status' => 'Completed',
        'created_by' => 3
    ],
    [
        'take_number' => 'ST-2024-003',
        'take_date' => date('Y-m-d'),
        'notes' => 'Quarterly stock count - Q1 2024',
        'status' => 'In Progress',
        'created_by' => 4
    ]
];

$stmtTake = $db->prepare("INSERT INTO stock_take (take_number, take_date, notes, status, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?)");
$stmtItem = $db->prepare("INSERT INTO stock_take_items (stock_take_id, product_id, batch_number, location, qty_system, qty_physical, difference, status, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");

foreach ($stockTakes as $take) {
    
    $stmtTake->execute([
        $take['take_number'],
        $take['take_date'],
        $take['notes'],
        $take['status'],
        $take['created_by'],
        $take['take_date']
    ]);

    $stockTakeId = $db->lastInsertId();
    echo "✓ Stock Take created: {$take['take_number']} | Date: {$take['take_date']} | Status: {$take['status']}\n";

    
    $itemsForTake = array_slice($stockItems, 0, 5);

    $clear = 0;
    $minus = 0;
    $plus = 0;

    foreach ($itemsForTake as $stock) {
        $qtySystem = $stock['quantity'];
        
        $variance = rand(-10, 10);
        $qtyPhysical = $qtySystem + ($qtySystem * $variance / 100);
        $qtyPhysical = round($qtyPhysical);

        $difference = $qtyPhysical - $qtySystem;

        if ($difference == 0) {
            $status = 'Clear';
            $clear++;
        } elseif ($difference < 0) {
            $status = 'Minus';
            $minus++;
        } else {
            $status = 'Plus';
            $plus++;
        }

        $stmtItem->execute([
            $stockTakeId,
            $stock['product_id'],
            $stock['batch_number'],
            $stock['location'],
            $qtySystem,
            $qtyPhysical,
            $difference,
            $status,
            $status === 'Clear' ? 'Count verified' : 'Discrepancy found, requires investigation'
        ]);

        echo "  - Item: Product ID {$stock['product_id']} | System: {$qtySystem} | Physical: {$qtyPhysical} | Diff: {$difference} | Status: {$status}\n";
    }

    $totalItems = $clear + $minus + $plus;
    $accuracy = $totalItems > 0 ? round(($clear / $totalItems) * 100, 2) : 0;
    echo "  Summary: {$totalItems} items | Clear: {$clear} | Minus: {$minus} | Plus: {$plus} | Accuracy: {$accuracy}%\n";
}

echo "\n✅ Stock Take seeded successfully! Total: " . count($stockTakes) . " stock takes\n";
?>
<?php

require_once __DIR__ . '/../config/database.php';

$db = db();

$db->exec("DELETE FROM locations");

$locations = [];

for ($i = 1; $i <= 10; $i++) {
    $locations[] = [
        'location_code' => 'A' . str_pad($i, 2, '0', STR_PAD_LEFT),
        'location_name' => 'Aisle A - Position ' . $i,
        'zone' => 'A',
        'aisle' => 'A',
        'position' => $i,
        'level' => 'Ground',
        'location_type' => 'Storage',
        'capacity_pallets' => 4,
        'status' => 'Available'
    ];
}

for ($i = 1; $i <= 20; $i++) {
    $locations[] = [
        'location_code' => 'B' . str_pad($i, 2, '0', STR_PAD_LEFT),
        'location_name' => 'Aisle B - Position ' . $i,
        'zone' => 'B',
        'aisle' => 'B',
        'position' => $i,
        'level' => 'Ground',
        'location_type' => 'Storage',
        'capacity_pallets' => 4,
        'status' => 'Available'
    ];
}

for ($i = 1; $i <= 20; $i++) {
    $locations[] = [
        'location_code' => 'C' . str_pad($i, 2, '0', STR_PAD_LEFT),
        'location_name' => 'Aisle C - Position ' . $i,
        'zone' => 'C',
        'aisle' => 'C',
        'position' => $i,
        'level' => 'Ground',
        'location_type' => 'Storage',
        'capacity_pallets' => 4,
        'status' => 'Available'
    ];
}

for ($i = 1; $i <= 15; $i++) {
    $locations[] = [
        'location_code' => 'D' . str_pad($i, 2, '0', STR_PAD_LEFT),
        'location_name' => 'Aisle D - Position ' . $i,
        'zone' => 'D',
        'aisle' => 'D',
        'position' => $i,
        'level' => 'Ground',
        'location_type' => 'Cool Storage',
        'capacity_pallets' => 3,
        'status' => 'Available'
    ];
}

for ($i = 1; $i <= 10; $i++) {
    $locations[] = [
        'location_code' => 'E' . str_pad($i, 2, '0', STR_PAD_LEFT),
        'location_name' => 'Aisle E - Position ' . $i,
        'zone' => 'E',
        'aisle' => 'E',
        'position' => $i,
        'level' => 'Ground',
        'location_type' => 'Quarantine',
        'capacity_pallets' => 2,
        'status' => 'Available'
    ];
}

for ($i = 1; $i <= 5; $i++) {
    $locations[] = [
        'location_code' => 'R' . str_pad($i, 2, '0', STR_PAD_LEFT),
        'location_name' => 'Receiving Area - Position ' . $i,
        'zone' => 'R',
        'aisle' => 'R',
        'position' => $i,
        'level' => 'Ground',
        'location_type' => 'Receiving',
        'capacity_pallets' => 10,
        'status' => 'Available'
    ];
}

for ($i = 1; $i <= 5; $i++) {
    $locations[] = [
        'location_code' => 'S' . str_pad($i, 2, '0', STR_PAD_LEFT),
        'location_name' => 'Shipping Area - Position ' . $i,
        'zone' => 'S',
        'aisle' => 'S',
        'position' => $i,
        'level' => 'Ground',
        'location_type' => 'Shipping',
        'capacity_pallets' => 10,
        'status' => 'Available'
    ];
}

$stmt = $db->prepare("INSERT INTO locations (location_code, location_name, zone, aisle, position, level, location_type, capacity_pallets, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");

foreach ($locations as $location) {
    $stmt->execute([
        $location['location_code'],
        $location['location_name'],
        $location['zone'],
        $location['aisle'],
        $location['position'],
        $location['level'],
        $location['location_type'],
        $location['capacity_pallets'],
        $location['status']
    ]);
    echo "✓ Location created: {$location['location_code']} - {$location['location_name']}\n";
}

echo "\n✅ Locations seeded successfully! Total: " . count($locations) . " locations\n";
echo "📊 Breakdown:\n";
echo "   - Zone A (Dues In): 10 locations\n";
echo "   - Zone B (Storage): 20 locations\n";
echo "   - Zone C (Storage): 20 locations\n";
echo "   - Zone D (Cool Storage): 15 locations\n";
echo "   - Zone E (Quarantine): 10 locations\n";
echo "   - Zone R (Receiving): 5 locations\n";
echo "   - Zone S (Shipping): 5 locations\n";
?>
<?php

echo "\n";
echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║   TrianaWMS V2 - Master Seeder                              ║\n";
echo "║   Shell CKB Warehouse Management System                     ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n\n";

$seeders = [
    ['name' => 'Users', 'file' => 'seed_users.php'],
    ['name' => 'Customers', 'file' => 'seed_customers.php'],
    ['name' => 'Locations', 'file' => 'seed_locations.php'],
    ['name' => 'Products', 'file' => 'seed_products.php'],
    ['name' => 'Stock', 'file' => 'seed_stock.php'],
    ['name' => 'Inbound Orders', 'file' => 'seed_inbound.php'],
    ['name' => 'Outbound Orders', 'file' => 'seed_outbound.php'],
    ['name' => 'Stock Take', 'file' => 'seed_stock_take.php'],
    ['name' => 'Picklists', 'file' => 'seed_picklists.php'],
    ['name' => 'Location Allocations', 'file' => 'seed_location_allocations.php']
];

$successCount = 0;
$failedCount = 0;

foreach ($seeders as $index => $seeder) {
    $num = $index + 1;
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "[{$num}/" . count($seeders) . "] Seeding: {$seeder['name']}\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

    $file = __DIR__ . '/' . $seeder['file'];

    if (!file_exists($file)) {
        echo "❌ Error: File not found - {$file}\n";
        $failedCount++;
        continue;
    }

    $output = [];
    $returnCode = 0;

    
    exec("php \"{$file}\" 2>&1", $output, $returnCode);

    if ($returnCode === 0) {
        echo implode("\n", $output);
        echo "\n✅ {$seeder['name']} seeded successfully!\n";
        $successCount++;
    } else {
        echo implode("\n", $output);
        echo "\n❌ Error seeding {$seeder['name']}\n";
        $failedCount++;
    }
    echo "\n";
}

echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║   Seeding Summary                                            ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n";
echo "✅ Successful: {$successCount}/" . count($seeders) . "\n";

if ($failedCount > 0) {
    echo "❌ Failed: {$failedCount}/" . count($seeders) . "\n";
}

echo "\n🎉 Database seeding completed!\n\n";
echo "📝 Default Login Credentials:\n";
echo "   Username: admin\n";
echo "   Password: admin123\n\n";
echo "🌐 Access the application at: http://localhost/sanchaya/\n\n";
?>

<?php
/**
 * Migration Runner — executes pending database migrations.
 * 
 * Usage: php run_migrations.php
 */

require_once __DIR__ . '/config/database.php';

$db = db();

$migrations = [
    __DIR__ . '/migrations/024-auto-replenishment.sql',
    __DIR__ . '/migrations/025-performance-indexes.sql',
    __DIR__ . '/migrations/026-security-audit.sql',
];

echo "=== K-one Database Migrations ===\n\n";

$success = 0;
$failed = 0;

foreach ($migrations as $file) {
    $name = basename($file);
    echo "Running: {$name} ... ";
    
    if (!file_exists($file)) {
        echo "SKIPPED (file not found)\n";
        continue;
    }
    
    $sql = file_get_contents($file);
    if (empty(trim($sql))) {
        echo "SKIPPED (empty)\n";
        continue;
    }
    
    try {
        // Split by semicolons (simple approach)
        $statements = array_filter(array_map('trim', explode(';', $sql)));
        
        $executed = 0;
        foreach ($statements as $stmt) {
            if (!empty($stmt) && !str_starts_with($stmt, '--')) {
                $db->exec($stmt);
                $executed++;
            }
        }
        
        echo "OK ({$executed} statements)\n";
        $success++;
        
    } catch (PDOException $e) {
        echo "FAILED: " . $e->getMessage() . "\n";
        $failed++;
    }
}

echo "\n=== Summary ===\n";
echo "Success: {$success}\n";
echo "Failed: {$failed}\n";
echo "Total: " . count($migrations) . "\n";

if ($failed > 0) {
    echo "\nSome migrations failed. Check errors above.\n";
    exit(1);
} else {
    echo "\nAll migrations completed successfully!\n";
    exit(0);
}
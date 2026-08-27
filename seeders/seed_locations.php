<?php

require_once __DIR__ . '/../config/database.php';

$db = db();
$db->exec("SET FOREIGN_KEY_CHECKS=0");
$db->exec("DELETE FROM location_master");
$db->exec("SET FOREIGN_KEY_CHECKS=1");

$migrationFile = __DIR__ . '/../migrations/seed_locations.sql';
if (!is_file($migrationFile)) {
    echo "❌ Error: Migration file not found - $migrationFile\n";
    exit(1);
}

$sql = file_get_contents($migrationFile);
$sql = preg_replace('/^USE\s+[^;]+;/im', '', $sql);

$db->exec($sql);

$total = (int)$db->query("SELECT COUNT(*) FROM location_master")->fetchColumn();
echo "✅ Locations seeded successfully! Total: {$total} locations\n";
?>
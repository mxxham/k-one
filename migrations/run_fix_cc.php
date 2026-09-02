<?php
/**
 * Migration: Fix CC aisle side B location codes
 * 
 * Problem: CC Side B has incorrect bay numbers
 *   - Expected: 17 bays (CC18-CC34) for Side B
 *   - Actual (in seed): CC35-CC40 (wrong numbering)
 * 
 * Solution: Delete CC35-CC40 (wrong), keep CC18-CC34 (correct Side B)
 */

require_once __DIR__ . '/config/database.php';

$db = db();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$db->beginTransaction();

try {
    // Step 1: Verify current state
    $stmt = $db->query("SELECT COUNT(*) as cnt FROM location_master WHERE aisle = 'CC'");
    $beforeCount = $stmt->fetch()['cnt'];
    echo "CC locations before migration: $beforeCount\n";
    
    // Get distinct racks before
    $stmt = $db->query("SELECT DISTINCT rack FROM location_master WHERE aisle = 'CC' ORDER BY rack");
    $beforeRacks = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo "Racks before: " . implode(', ', $beforeRacks) . "\n";
    
    // Step 2: Delete incorrectly numbered CC Side B entries (racks 35-40)
    // CC34 is correct Side B; only CC35-CC40 are wrong
    $deleted = $db->exec("DELETE FROM location_master WHERE aisle = 'CC' AND rack BETWEEN '35' AND '40'");
    echo "Deleted $deleted incorrect rows\n";
    
    // Step 3: Insert correct CC18-CC34 (17 bays × 5 levels × 2 positions = 170 rows)
    $sql = "INSERT IGNORE INTO location_master (location_code, aisle, rack, row_name, position, zone) VALUES
    ('CC18A01','CC','18','A','01','Bulk'),('CC18A02','CC','18','A','02','Bulk'),
    ('CC18B01','CC','18','B','01','Bulk'),('CC18B02','CC','18','B','02','Bulk'),
    ('CC18C01','CC','18','C','01','Bulk'),('CC18C02','CC','18','C','02','Bulk'),
    ('CC18D01','CC','18','D','01','Bulk'),('CC18D02','CC','18','D','02','Bulk'),
    ('CC18E01','CC','18','E','01','Bulk'),('CC18E02','CC','18','E','02','Bulk'),
    ('CC19A01','CC','19','A','01','Bulk'),('CC19A02','CC','19','A','02','Bulk'),
    ('CC19B01','CC','19','B','01','Bulk'),('CC19B02','CC','19','B','02','Bulk'),
    ('CC19C01','CC','19','C','01','Bulk'),('CC19C02','CC','19','C','02','Bulk'),
    ('CC19D01','CC','19','D','01','Bulk'),('CC19D02','CC','19','D','02','Bulk'),
    ('CC19E01','CC','19','E','01','Bulk'),('CC19E02','CC','19','E','02','Bulk'),
    ('CC20A01','CC','20','A','01','Bulk'),('CC20A02','CC','20','A','02','Bulk'),
    ('CC20B01','CC','20','B','01','Bulk'),('CC20B02','CC','20','B','02','Bulk'),
    ('CC20C01','CC','20','C','01','Bulk'),('CC20C02','CC','20','C','02','Bulk'),
    ('CC20D01','CC','20','D','01','Bulk'),('CC20D02','CC','20','D','02','Bulk'),
    ('CC20E01','CC','20','E','01','Bulk'),('CC20E02','CC','20','E','02','Bulk'),
    ('CC21A01','CC','21','A','01','Bulk'),('CC21A02','CC','21','A','02','Bulk'),
    ('CC21B01','CC','21','B','01','Bulk'),('CC21B02','CC','21','B','02','Bulk'),
    ('CC21C01','CC','21','C','01','Bulk'),('CC21C02','CC','21','C','02','Bulk'),
    ('CC21D01','CC','21','D','01','Bulk'),('CC21D02','CC','21','D','02','Bulk'),
    ('CC21E01','CC','21','E','01','Bulk'),('CC21E02','CC','21','E','02','Bulk'),
    ('CC22A01','CC','22','A','01','Bulk'),('CC22A02','CC','22','A','02','Bulk'),
    ('CC22B01','CC','22','B','01','Bulk'),('CC22B02','CC','22','B','02','Bulk'),
    ('CC22C01','CC','22','C','01','Bulk'),('CC22C02','CC','22','C','02','Bulk'),
    ('CC22D01','CC','22','D','01','Bulk'),('CC22D02','CC','22','D','02','Bulk'),
    ('CC22E01','CC','22','E','01','Bulk'),('CC22E02','CC','22','E','02','Bulk'),
    ('CC23A01','CC','23','A','01','Bulk'),('CC23A02','CC','23','A','02','Bulk'),
    ('CC23B01','CC','23','B','01','Bulk'),('CC23B02','CC','23','B','02','Bulk'),
    ('CC23C01','CC','23','C','01','Bulk'),('CC23C02','CC','23','C','02','Bulk'),
    ('CC23D01','CC','23','D','01','Bulk'),('CC23D02','CC','23','D','02','Bulk'),
    ('CC23E01','CC','23','E','01','Bulk'),('CC23E02','CC','23','E','02','Bulk'),
    ('CC24A01','CC','24','A','01','Bulk'),('CC24A02','CC','24','A','02','Bulk'),
    ('CC24B01','CC','24','B','01','Bulk'),('CC24B02','CC','24','B','02','Bulk'),
    ('CC24C01','CC','24','C','01','Bulk'),('CC24C02','CC','24','C','02','Bulk'),
    ('CC24D01','CC','24','D','01','Bulk'),('CC24D02','CC','24','D','02','Bulk'),
    ('CC24E01','CC','24','E','01','Bulk'),('CC24E02','CC','24','E','02','Bulk'),
    ('CC25A01','CC','25','A','01','Bulk'),('CC25A02','CC','25','A','02','Bulk'),
    ('CC25B01','CC','25','B','01','Bulk'),('CC25B02','CC','25','B','02','Bulk'),
    ('CC25C01','CC','25','C','01','Bulk'),('CC25C02','CC','25','C','02','Bulk'),
    ('CC25D01','CC','25','D','01','Bulk'),('CC25D02','CC','25','D','02','Bulk'),
    ('CC25E01','CC','25','E','01','Bulk'),('CC25E02','CC','25','E','02','Bulk'),
    ('CC26A01','CC','26','A','01','Bulk'),('CC26A02','CC','26','A','02','Bulk'),
    ('CC26B01','CC','26','B','01','Bulk'),('CC26B02','CC','26','B','02','Bulk'),
    ('CC26C01','CC','26','C','01','Bulk'),('CC26C02','CC','26','C','02','Bulk'),
    ('CC26D01','CC','26','D','01','Bulk'),('CC26D02','CC','26','D','02','Bulk'),
    ('CC26E01','CC','26','E','01','Bulk'),('CC26E02','CC','26','E','02','Bulk'),
    ('CC27A01','CC','27','A','01','Bulk'),('CC27A02','CC','27','A','02','Bulk'),
    ('CC27B01','CC','27','B','01','Bulk'),('CC27B02','CC','27','B','02','Bulk'),
    ('CC27C01','CC','27','C','01','Bulk'),('CC27C02','CC','27','C','02','Bulk'),
    ('CC27D01','CC','27','D','01','Bulk'),('CC27D02','CC','27','D','02','Bulk'),
    ('CC27E01','CC','27','E','01','Bulk'),('CC27E02','CC','27','E','02','Bulk'),
    ('CC28A01','CC','28','A','01','Bulk'),('CC28A02','CC','28','A','02','Bulk'),
    ('CC28B01','CC','28','B','01','Bulk'),('CC28B02','CC','28','B','02','Bulk'),
    ('CC28C01','CC','28','C','01','Bulk'),('CC28C02','CC','28','C','02','Bulk'),
    ('CC28D01','CC','28','D','01','Bulk'),('CC28D02','CC','28','D','02','Bulk'),
    ('CC28E01','CC','28','E','01','Bulk'),('CC28E02','CC','28','E','02','Bulk'),
    ('CC29A01','CC','29','A','01','Bulk'),('CC29A02','CC','29','A','02','Bulk'),
    ('CC29B01','CC','29','B','01','Bulk'),('CC29B02','CC','29','B','02','Bulk'),
    ('CC29C01','CC','29','C','01','Bulk'),('CC29C02','CC','29','C','02','Bulk'),
    ('CC29D01','CC','29','D','01','Bulk'),('CC29D02','CC','29','D','02','Bulk'),
    ('CC29E01','CC','29','E','01','Bulk'),('CC29E02','CC','29','E','02','Bulk'),
    ('CC30A01','CC','30','A','01','Bulk'),('CC30A02','CC','30','A','02','Bulk'),
    ('CC30B01','CC','30','B','01','Bulk'),('CC30B02','CC','30','B','02','Bulk'),
    ('CC30C01','CC','30','C','01','Bulk'),('CC30C02','CC','30','C','02','Bulk'),
    ('CC30D01','CC','30','D','01','Bulk'),('CC30D02','CC','30','D','02','Bulk'),
    ('CC30E01','CC','30','E','01','Bulk'),('CC30E02','CC','30','E','02','Bulk'),
    ('CC31A01','CC','31','A','01','Bulk'),('CC31A02','CC','31','A','02','Bulk'),
    ('CC31B01','CC','31','B','01','Bulk'),('CC31B02','CC','31','B','02','Bulk'),
    ('CC31C01','CC','31','C','01','Bulk'),('CC31C02','CC','31','C','02','Bulk'),
    ('CC31D01','CC','31','D','01','Bulk'),('CC31D02','CC','31','D','02','Bulk'),
    ('CC31E01','CC','31','E','01','Bulk'),('CC31E02','CC','31','E','02','Bulk'),
    ('CC32A01','CC','32','A','01','Bulk'),('CC32A02','CC','32','A','02','Bulk'),
    ('CC32B01','CC','32','B','01','Bulk'),('CC32B02','CC','32','B','02','Bulk'),
    ('CC32C01','CC','32','C','01','Bulk'),('CC32C02','CC','32','C','02','Bulk'),
    ('CC32D01','CC','32','D','01','Bulk'),('CC32D02','CC','32','D','02','Bulk'),
    ('CC32E01','CC','32','E','01','Bulk'),('CC32E02','CC','32','E','02','Bulk'),
    ('CC33A01','CC','33','A','01','Bulk'),('CC33A02','CC','33','A','02','Bulk'),
    ('CC33B01','CC','33','B','01','Bulk'),('CC33B02','CC','33','B','02','Bulk'),
    ('CC33C01','CC','33','C','01','Bulk'),('CC33C02','CC','33','C','02','Bulk'),
    ('CC33D01','CC','33','D','01','Bulk'),('CC33D02','CC','33','D','02','Bulk'),
    ('CC33E01','CC','33','E','01','Bulk'),('CC33E02','CC','33','E','02','Bulk'),
    ('CC34A01','CC','34','A','01','Bulk'),('CC34A02','CC','34','A','02','Bulk'),
    ('CC34B01','CC','34','B','01','Bulk'),('CC34B02','CC','34','B','02','Bulk'),
    ('CC34C01','CC','34','C','01','Bulk'),('CC34C02','CC','34','C','02','Bulk'),
    ('CC34D01','CC','34','D','01','Bulk'),('CC34D02','CC','34','D','02','Bulk'),
    ('CC34E01','CC','34','E','01','Bulk'),('CC34E02','CC','34','E','02','Bulk')";
    
    $db->exec($sql);
    
    $db->commit();
    echo "Migration completed successfully!\n";
    
} catch (Exception $e) {
    $db->rollBack();
    echo "Migration FAILED: " . $e->getMessage() . "\n";
    exit(1);
}

// Step 4: Verify after migration
$stmt = $db->query("SELECT COUNT(*) as cnt FROM location_master WHERE aisle = 'CC'");
$afterCount = $stmt->fetch()['cnt'];
echo "CC locations after migration: $afterCount\n";

$stmt = $db->query("SELECT DISTINCT rack FROM location_master WHERE aisle = 'CC' ORDER BY rack");
$afterRacks = $stmt->fetchAll(PDO::FETCH_COLUMN);
echo "Racks after: " . implode(', ', $afterRacks) . "\n";

echo "\n";
echo "Verification:\n";
echo "- CC Side A (01-17): CC01-CC17 should exist\n";
echo "- CC Side B (18-34): CC18-CC34 should exist\n";
echo "- Total: 34 bays expected\n";
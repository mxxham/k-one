<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class PickfaceAllocationSplitTest extends TestCase
{
    private static PDO $pdo;

    public static function setUpBeforeClass(): void
    {
        self::$pdo = ApiTestHelpers::pdo();

        putenv('DB_NAME=' . TEST_DB_NAME);
        putenv('DB_HOST=' . TEST_DB_HOST);
        putenv('DB_PORT=' . TEST_DB_PORT);
        putenv('DB_USER=' . TEST_DB_USER);
        putenv('DB_PASS=' . TEST_DB_PASS);

        require_once dirname(__DIR__) . '/config/database.php';
    }

    protected function setUp(): void
    {
        ApiTestHelpers::resetDb();
        self::$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        self::$pdo->exec('TRUNCATE TABLE `sku_pickface_config`');
        self::$pdo->exec('TRUNCATE TABLE `replen_task`');
        self::$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    public function testSplit120UnitsPickfaceMax48(): void
    {
        $pid = TestDataFactory::createProduct();

        $pfCode = 'CA01A01';
        $pfRow = self::$pdo->prepare("SELECT id FROM location_master WHERE location_code = ?");
        $pfRow->execute([$pfCode]);
        $pfBinId = (int)$pfRow->fetchColumn();

        self::$pdo->prepare(
            "INSERT INTO sku_pickface_config (sku_id, pickface_bin_id, pickface_max, pickface_min)
             VALUES (?, ?, 48, 5)"
        )->execute([$pid, $pfBinId]);

        $bulkLocations = ['CA01B01', 'CA01C01', 'CA01D01'];
        foreach ($bulkLocations as $loc) {
            TestDataFactory::createStock($pid, $loc, 50.0, 'BATCH-BULK', '2030-12-31');
        }

        TestDataFactory::createStock($pid, $pfCode, 30.0, 'BATCH-PF', '2030-12-31');

        $data = FefoAllocator::allocate($pid, 120.0, null, 'bulk_first');

        self::assertTrue($data['sufficient']);
        self::assertEqualsWithDelta(0.0, $data['shortage'], 0.01);

        $bulkAlloc = array_values(array_filter($data['allocation'], fn($a) => $a['bin_location'] !== $pfCode));
        $pfAlloc = array_values(array_filter($data['allocation'], fn($a) => $a['bin_location'] === $pfCode));

        $bulkTotal = array_sum(array_map(fn($a) => $a['qty_to_take'], $bulkAlloc));
        self::assertEqualsWithDelta(96.0, $bulkTotal, 0.01);

        $pfTotal = array_sum(array_map(fn($a) => $a['qty_to_take'], $pfAlloc));
        self::assertEqualsWithDelta(24.0, $pfTotal, 0.01);

        self::assertArrayHasKey('pickface_qty_used', $data);
        self::assertEqualsWithDelta(24.0, $data['pickface_qty_used'], 0.01);
    }

    public function testBulkShortageReturnsShortfall(): void
    {
        $pid = TestDataFactory::createProduct();

        $pfCode = 'CA01A01';
        $pfRow = self::$pdo->prepare("SELECT id FROM location_master WHERE location_code = ?");
        $pfRow->execute([$pfCode]);
        $pfBinId = (int)$pfRow->fetchColumn();

        self::$pdo->prepare(
            "INSERT INTO sku_pickface_config (sku_id, pickface_bin_id, pickface_max, pickface_min)
             VALUES (?, ?, 48, 5)"
        )->execute([$pid, $pfBinId]);

        TestDataFactory::createStock($pid, 'CA01B01', 30.0, 'BATCH-LOW', '2030-12-31');
        TestDataFactory::createStock($pid, $pfCode, 50.0, 'BATCH-PF', '2030-12-31');

        $data = FefoAllocator::allocate($pid, 120.0, null, 'bulk_first');

        self::assertFalse($data['sufficient']);
        self::assertEqualsWithDelta(40.0, $data['shortage'], 0.01);
        self::assertEqualsWithDelta(50.0, $data['pickface_qty_used'], 0.01);

        $bulkAlloc = array_values(array_filter($data['allocation'], fn($a) => $a['bin_location'] !== $pfCode));
        $bulkTotal = array_sum(array_map(fn($a) => $a['qty_to_take'], $bulkAlloc));
        self::assertEqualsWithDelta(30.0, $bulkTotal, 0.01);
    }

    public function testPickfaceQtyZeroSkipsPickface(): void
    {
        $pid = TestDataFactory::createProduct();

        $pfCode = 'CA01A01';
        $pfRow = self::$pdo->prepare("SELECT id FROM location_master WHERE location_code = ?");
        $pfRow->execute([$pfCode]);
        $pfBinId = (int)$pfRow->fetchColumn();

        self::$pdo->prepare(
            "INSERT INTO sku_pickface_config (sku_id, pickface_bin_id, pickface_max, pickface_min)
             VALUES (?, ?, 48, 5)"
        )->execute([$pid, $pfBinId]);

        foreach (['CA01B01', 'CA01C01'] as $loc) {
            TestDataFactory::createStock($pid, $loc, 50.0, 'BATCH-BULK', '2030-12-31');
        }

        $data = FefoAllocator::allocate($pid, 96.0, null, 'bulk_first');

        self::assertTrue($data['sufficient']);
        self::assertEqualsWithDelta(0.0, $data['pickface_qty_used'], 0.01);

        $pfAlloc = array_values(array_filter($data['allocation'], fn($a) => $a['bin_location'] === $pfCode));
        self::assertEmpty($pfAlloc);
    }

    public function testBulkShortageFoldsIntoPickface(): void
    {
        $pid = TestDataFactory::createProduct();

        $pfCode = 'CA01A01';
        $pfRow = self::$pdo->prepare("SELECT id FROM location_master WHERE location_code = ?");
        $pfRow->execute([$pfCode]);
        $pfBinId = (int)$pfRow->fetchColumn();

        self::$pdo->prepare(
            "INSERT INTO sku_pickface_config (sku_id, pickface_bin_id, pickface_max, pickface_min)
             VALUES (?, ?, 48, 5)"
        )->execute([$pid, $pfBinId]);

        TestDataFactory::createStock($pid, 'CA01B01', 90.0, 'BATCH-BULK', '2030-12-31');
        TestDataFactory::createStock($pid, $pfCode, 54.0, 'BATCH-PF', '2030-12-31');

        $data = FefoAllocator::allocate($pid, 120.0, null, 'bulk_first');

        self::assertTrue($data['sufficient']);
        self::assertEqualsWithDelta(0.0, $data['shortage'], 0.01);

        $bulkAlloc = array_values(array_filter($data['allocation'], fn($a) => $a['bin_location'] !== $pfCode));
        $pfAlloc = array_values(array_filter($data['allocation'], fn($a) => $a['bin_location'] === $pfCode));

        $bulkTotal = array_sum(array_map(fn($a) => $a['qty_to_take'], $bulkAlloc));
        self::assertEqualsWithDelta(90.0, $bulkTotal, 0.01);

        $pfTotal = array_sum(array_map(fn($a) => $a['qty_to_take'], $pfAlloc));
        self::assertEqualsWithDelta(30.0, $pfTotal, 0.01);

        self::assertArrayHasKey('pickface_qty_used', $data);
        self::assertEqualsWithDelta(30.0, $data['pickface_qty_used'], 0.01);
    }

    public function testEmptyPickfaceFallsBackToBulk(): void
    {
        $pid = TestDataFactory::createProduct();

        $pfCode = 'CA01A01';
        $pfRow = self::$pdo->prepare("SELECT id FROM location_master WHERE location_code = ?");
        $pfRow->execute([$pfCode]);
        $pfBinId = (int)$pfRow->fetchColumn();

        self::$pdo->prepare(
            "INSERT INTO sku_pickface_config (sku_id, pickface_bin_id, pickface_max, pickface_min)
             VALUES (?, ?, 48, 5)"
        )->execute([$pid, $pfBinId]);

        foreach (['CA01B01', 'CA01C01', 'CA01D01'] as $loc) {
            TestDataFactory::createStock($pid, $loc, 48.0, 'BULK-' . $loc, '2030-12-31');
        }

        $data = FefoAllocator::allocate($pid, 120.0, null, 'bulk_first');

        self::assertTrue($data['sufficient']);
        self::assertEqualsWithDelta(0.0, $data['shortage'], 0.01);

        $totalFromBulk = array_sum(array_map(
            fn($a) => $a['qty_to_take'],
            array_values(array_filter($data['allocation'], fn($a) => $a['bin_location'] !== $pfCode))
        ));
        self::assertEqualsWithDelta(120.0, $totalFromBulk, 0.01);

        self::assertEqualsWithDelta(0.0, $data['pickface_qty_used'], 0.01);
    }

    public function testEmptyPickfaceReturnsOriginalPickfaceQty(): void
    {
        $pid = TestDataFactory::createProduct();

        $pfCode = 'CA01A01';
        $pfRow = self::$pdo->prepare("SELECT id FROM location_master WHERE location_code = ?");
        $pfRow->execute([$pfCode]);
        $pfBinId = (int)$pfRow->fetchColumn();

        self::$pdo->prepare(
            "INSERT INTO sku_pickface_config (sku_id, pickface_bin_id, pickface_max, pickface_min)
             VALUES (?, ?, 48, 5)"
        )->execute([$pid, $pfBinId]);

        foreach (['CA01B01', 'CA01C01', 'CA01D01'] as $loc) {
            TestDataFactory::createStock($pid, $loc, 48.0, 'BULK-' . $loc, '2030-12-31');
        }

        $data = FefoAllocator::allocate($pid, 120.0, null, 'bulk_first');

        self::assertTrue($data['sufficient']);
        self::assertArrayHasKey('pickface_qty_intended', $data);
        self::assertEqualsWithDelta(24.0, $data['pickface_qty_intended'], 0.01);
        self::assertEqualsWithDelta(0.0, $data['pickface_qty_used'], 0.01);
    }

    public function testEmptyPickfaceTriggersReplenishmentCheck(): void
    {
        $pid = TestDataFactory::createProduct();

        $pfCode = 'CA01A01';
        $pfRow = self::$pdo->prepare("SELECT id FROM location_master WHERE location_code = ?");
        $pfRow->execute([$pfCode]);
        $pfBinId = (int)$pfRow->fetchColumn();

        self::$pdo->prepare(
            "INSERT INTO sku_pickface_config (sku_id, pickface_bin_id, pickface_max, pickface_min)
             VALUES (?, ?, 48, 5)"
        )->execute([$pid, $pfBinId]);

        foreach (['CA01B01', 'CA01C01', 'CA01D01'] as $loc) {
            TestDataFactory::createStock($pid, $loc, 48.0, 'BULK-' . $loc, '2030-12-31');
        }

        $split = PickfaceSplitter::splitOrderLine(120.0, 48);
        $intendedPickfaceQty = (float)$split['pickface_qty'];

        self::assertEqualsWithDelta(24.0, $intendedPickfaceQty, 0.01);

        $check = PickfaceSplitter::checkReplenishment($pid, $intendedPickfaceQty, self::$pdo);

        self::assertTrue($check['needs_replenishment']);

        $replenishQty = (int)$check['config']['pickface_max'] - (int)$check['projected_on_hand'];
        self::assertGreaterThan(0, $replenishQty);
    }

    public function testBulkFallbackPickfaceIntendedVsUsedAndReplenishment(): void
    {
        $pid = TestDataFactory::createProduct();

        $pfCode = 'CA01A01';
        $pfRow = self::$pdo->prepare("SELECT id FROM location_master WHERE location_code = ?");
        $pfRow->execute([$pfCode]);
        $pfBinId = (int)$pfRow->fetchColumn();

        self::$pdo->prepare(
            "INSERT INTO sku_pickface_config (sku_id, pickface_bin_id, pickface_max, pickface_min)
             VALUES (?, ?, 48, 10)"
        )->execute([$pid, $pfBinId]);

        // Pickface bin is EMPTY — this is the scenario that needs replenishment
        // Bulk bins have sufficient stock to cover the full order via fallback
        foreach (['CA01B01', 'CA01C01', 'CA01D01'] as $loc) {
            TestDataFactory::createStock($pid, $loc, 48.0, 'BULK-' . $loc, '2030-12-31');
        }

        // splitOrderLine(120, 48) → bulk=96, pickface=24
        $data = FefoAllocator::allocate($pid, 120.0, null, 'bulk_first');

        // (a) Allocation succeeds via bulk fallback
        self::assertTrue($data['sufficient']);
        self::assertEqualsWithDelta(0.0, $data['shortage'], 0.01);

        // (b) pickface_qty_intended = 24, pickface_qty_used = 0
        self::assertEqualsWithDelta(24.0, $data['pickface_qty_intended'], 0.01);
        self::assertEqualsWithDelta(0.0, $data['pickface_qty_used'], 0.01);

        // (c) Replenishment task created targeting the SKU's actual pickface bin
        $check = PickfaceSplitter::checkReplenishment($pid, $data['pickface_qty_intended'], self::$pdo);
        self::assertTrue($check['needs_replenishment'], 'Intended qty triggers replenishment even when pickface was bypassed');

        $replenishQty = (int)$check['config']['pickface_max'] - (int)$check['projected_on_hand'];
        self::assertGreaterThan(0, $replenishQty, 'Replenishment should top up pickface from 0 toward pickface_max');
        self::assertEquals(48, $replenishQty, 'Should replenish full pickface_max since bin is empty');
    }
}

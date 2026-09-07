<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests for PickfaceSplitter — split edge cases, replenishment trigger logic,
 * in-flight dedup, and createReplenTask.
 *
 * Spec references:
 *   §3 — Split order line: bulk_qty = floor(qty/max)*max, pickface_qty = qty % max
 *   §4 — Trigger replenishment when projected_on_hand <= pickface_min
 */
final class PickfaceSplitterTest extends TestCase
{
    private static PDO $pdo;

    public static function setUpBeforeClass(): void
    {
        self::$pdo = ApiTestHelpers::pdo();
    }

    protected function setUp(): void
    {
        // Clean tables that truncateTransactional doesn't cover
        self::$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        self::$pdo->exec('TRUNCATE TABLE `replen_task`');
        self::$pdo->exec('TRUNCATE TABLE `sku_pickface_config`');
        self::$pdo->exec('TRUNCATE TABLE `outbound_item_locations`');
        self::$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

        // Also truncate standard transactional tables
        ApiTestHelpers::truncateTransactional(self::$pdo);
    }

    /* ================================================================== */
    /* splitOrderLine — pure calculation tests (no DB)                     */
    /* ================================================================== */

    /** §3 Even split: order_qty is exact multiple of pickface_max → pickface_qty = 0 */
    public function testSplitOrderLineEvenSplit(): void
    {
        // 20 / 10 = 2 full picks, remainder 0
        $result = PickfaceSplitter::splitOrderLine(20.0, 10);
        $this->assertEquals(20.0, $result['bulk_qty']);
        $this->assertEquals(0.0, $result['pickface_qty']);
    }

    /** §3 Remainder: order_qty is not a multiple → pickface_qty > 0 */
    public function testSplitOrderLineRemainder(): void
    {
        // 25 / 10 = 2 full picks (20), remainder 5
        $result = PickfaceSplitter::splitOrderLine(25.0, 10);
        $this->assertEquals(20.0, $result['bulk_qty']);
        $this->assertEquals(5.0, $result['pickface_qty']);
    }

    /** §3 Small order: order_qty < pickface_max → bulk_qty = 0 */
    public function testSplitOrderLineSmallOrder(): void
    {
        // 3 / 10 = 0 full picks, remainder 3
        $result = PickfaceSplitter::splitOrderLine(3.0, 10);
        $this->assertEquals(0.0, $result['bulk_qty']);
        $this->assertEquals(3.0, $result['pickface_qty']);
    }

    /** §3 Exact pickface_max: order_qty == pickface_max → bulk_qty=0, pickface_qty=0 */
    public function testSplitOrderLineExactPickfaceMax(): void
    {
        // 10 / 10 = 1 full pick → bulk_qty=10, pickface_qty=0
        $result = PickfaceSplitter::splitOrderLine(10.0, 10);
        $this->assertEquals(10.0, $result['bulk_qty']);
        $this->assertEquals(0.0, $result['pickface_qty']);
    }

    /** §3 Zero order_qty → bulk_qty=0, pickface_qty=0 */
    public function testSplitOrderLineZeroQty(): void
    {
        $result = PickfaceSplitter::splitOrderLine(0.0, 10);
        $this->assertEquals(0.0, $result['bulk_qty']);
        $this->assertEquals(0.0, $result['pickface_qty']);
    }

    /** §3 pickfaceMax <= 0 throws InvalidArgumentException */
    public function testSplitOrderLineInvalidMaxThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PickfaceSplitter::splitOrderLine(10.0, 0);
    }

    /** §3 Negative orderQty throws InvalidArgumentException */
    public function testSplitOrderLineNegativeQtyThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PickfaceSplitter::splitOrderLine(-5.0, 10);
    }

    /** §3 Large multiple: 100 / 15 = 6*15=90, remainder 10 */
    public function testSplitOrderLineLargeMultiple(): void
    {
        $result = PickfaceSplitter::splitOrderLine(100.0, 15);
        $this->assertEquals(90.0, $result['bulk_qty']);
        $this->assertEquals(10.0, $result['pickface_qty']);
    }

    /* ================================================================== */
    /* getPickfaceConfig — DB-dependent                                    */
    /* ================================================================== */

    /** Returns config when SKU has pickface setup */
    public function testGetPickfaceConfigReturnsConfig(): void
    {
        $pid = $this->createSkuWithPickface(20, 5);
        $config = PickfaceSplitter::getPickfaceConfig($pid, self::$pdo);

        $this->assertNotNull($config);
        $this->assertEquals($pid, $config['sku_id']);
        $this->assertEquals(20, $config['pickface_max']);
        $this->assertEquals(5, $config['pickface_min']);
    }

    /** Auto-detect kicks in when SKU has no pickface config — claims an empty A-level bin */
    public function testGetPickfaceConfigAutoDetectsForUnconfigured(): void
    {
        $pid = TestDataFactory::createProduct();
        $config = PickfaceSplitter::getPickfaceConfig($pid, self::$pdo);
        $this->assertNotNull($config);
        $this->assertGreaterThan(0, $config['id'], 'Auto-detected config should be persisted');
        $this->assertMatchesRegularExpression(
            '/^[A-Z]{2}[0-9]{2}A[0-9]{2}$/',
            $config['pickface_location_code']
        );
    }

    /* ================================================================== */
    /* checkReplenishment — trigger logic (§4)                             */
    /* ================================================================== */

    /** §4 projected_on_hand <= pickface_min → triggers replenishment */
    public function testCheckReplenishmentTriggersWhenLow(): void
    {
        // pickface_max=20, pickface_min=5, stock at pickface=3 (below min)
        $pid = $this->createSkuWithPickface(20, 5);
        $pfLoc = $this->getPickfaceLocationCode($pid);
        TestDataFactory::createStock($pid, $pfLoc, 3.0, 'BATCH-LOW');

        $result = PickfaceSplitter::checkReplenishment($pid, 0.0, self::$pdo);

        $this->assertTrue($result['needs_replenishment']);
        $this->assertEqualsWithDelta(3.0, $result['projected_on_hand'], 0.01);
        $this->assertEquals(5.0, $result['pickface_min']);
    }

    /** §4 projected_on_hand > pickface_min → no trigger */
    public function testCheckReplenishmentDoesNotTriggerWhenSufficient(): void
    {
        // pickface_max=20, pickface_min=5, stock at pickface=15 (above min)
        $pid = $this->createSkuWithPickface(20, 5);
        $pfLoc = $this->getPickfaceLocationCode($pid);
        TestDataFactory::createStock($pid, $pfLoc, 15.0, 'BATCH-HIGH');

        $result = PickfaceSplitter::checkReplenishment($pid, 0.0, self::$pdo);

        $this->assertFalse($result['needs_replenishment']);
        $this->assertGreaterThan(5.0, $result['projected_on_hand']);
    }

    /** §4 Auto-detect provides config → replenishment triggers when projected <= min */
    public function testCheckReplenishmentAutoDetectTriggersWhenLow(): void
    {
        $pid = TestDataFactory::createProduct();
        $result = PickfaceSplitter::checkReplenishment($pid, 0.0, self::$pdo);

        $this->assertNotNull($result['config'], 'Auto-detect should provide config');
        $this->assertTrue($result['needs_replenishment'], 'Projected 0 <= pickface_min → triggers');
    }

    /** §4 projected_on_hand exactly equals pickface_min → triggers (<=) */
    public function testCheckReplenishmentTriggersAtExactMin(): void
    {
        // pickface_max=20, pickface_min=10, stock at pickface=10 (exactly at min)
        $pid = $this->createSkuWithPickface(20, 10);
        $pfLoc = $this->getPickfaceLocationCode($pid);
        TestDataFactory::createStock($pid, $pfLoc, 10.0, 'BATCH-EXACT');

        $result = PickfaceSplitter::checkReplenishment($pid, 0.0, self::$pdo);

        $this->assertTrue($result['needs_replenishment']);
        $this->assertEqualsWithDelta(10.0, $result['projected_on_hand'], 0.01);
    }

    /** §4 In_transit stock from pending replen_task is counted in projected_on_hand */
    public function testCheckReplenishmentCountsInTransit(): void
    {
        // pickface_max=20, pickface_min=10, stock at pickface=0, but 8 in transit
        $pid = $this->createSkuWithPickface(20, 10);
        $config = PickfaceSplitter::getPickfaceConfig($pid, self::$pdo);
        $pfBinId = $config['pickface_bin_id'];

        // Create a pending replen_task that contributes in_transit qty
        $this->createReplenTaskDirect($pid, $pfBinId, 8, 'pending');

        // No stock at pickface, but 8 in transit → projected = 0 + 8 - 0 = 8 < 10 → trigger
        $result = PickfaceSplitter::checkReplenishment($pid, 0.0, self::$pdo);

        // Key assertion: in-transit stock IS counted in projected_on_hand
        $this->assertEqualsWithDelta(8.0, $result['projected_on_hand'], 0.01);

        // In-flight dedup: pending task exists → needs_replenishment = false (correct behavior)
        $this->assertFalse($result['needs_replenishment'], 'Should not trigger duplicate while in-flight task exists');
    }

    /* ================================================================== */
    /* In-flight dedup: existing pending task prevents duplicate trigger   */
    /* ================================================================== */

    /** §4 Existing open replen_task prevents duplicate creation */
    public function testInFlightDedup(): void
    {
        // pickface_max=20, pickface_min=10, stock at pickface=2 (below min)
        $pid = $this->createSkuWithPickface(20, 10);
        $config = PickfaceSplitter::getPickfaceConfig($pid, self::$pdo);
        $pfBinId = $config['pickface_bin_id'];
        $pfLoc = $config['pickface_location_code'];

        // Stock at pickface: 2 units
        TestDataFactory::createStock($pid, $pfLoc, 2.0, 'BATCH-DUP');

        // Already have an in-flight replen_task for this SKU+bin
        $this->createReplenTaskDirect($pid, $pfBinId, 10, 'pending');

        $result = PickfaceSplitter::checkReplenishment($pid, 0.0, self::$pdo);

        // Projected = 2 + 10 (in_transit) - 0 = 12 > 10 → no trigger
        // AND even if projected <= min, the in-flight task prevents a new trigger
        $this->assertFalse($result['needs_replenishment']);
    }

    /** §4 In-flight task with 'in_progress' status also blocks duplicate */
    public function testInFlightDedupInProgress(): void
    {
        $pid = $this->createSkuWithPickface(20, 10);
        $config = PickfaceSplitter::getPickfaceConfig($pid, self::$pdo);
        $pfBinId = $config['pickface_bin_id'];
        $pfLoc = $config['pickface_location_code'];

        // Zero stock at pickface, in_progress task moving 5 units
        TestDataFactory::createStock($pid, $pfLoc, 0.0, 'BATCH-IP');
        // Actually, stock with 0 qty won't be useful. Let's create 0 stock by not creating any.
        // But we need stock to exist for the query... Let's create stock at a bulk location instead.
        // The check only looks at pickface location stock, so no stock there = 0 on hand.

        $this->createReplenTaskDirect($pid, $pfBinId, 5, 'in_progress');

        $result = PickfaceSplitter::checkReplenishment($pid, 0.0, self::$pdo);

        // Projected = 0 + 5 - 0 = 5 <= 10, but in-flight task exists → no trigger
        $this->assertFalse($result['needs_replenishment']);
    }

    /** §4 Completed replen_task does NOT block trigger */
    public function testCompletedTaskDoesNotBlock(): void
    {
        $pid = $this->createSkuWithPickface(20, 10);
        $config = PickfaceSplitter::getPickfaceConfig($pid, self::$pdo);
        $pfBinId = $config['pickface_bin_id'];
        $pfLoc = $config['pickface_location_code'];

        // Zero stock at pickface
        // Create a completed replen_task (should not count as in-flight)
        $this->createReplenTaskDirect($pid, $pfBinId, 10, 'completed');

        $result = PickfaceSplitter::checkReplenishment($pid, 0.0, self::$pdo);

        // Projected = 0 + 0 (completed tasks don't count as in_transit) - 0 = 0 <= 10 → trigger
        $this->assertTrue($result['needs_replenishment']);
    }

    /* ================================================================== */
    /* createReplenTask — creates correct record                          */
    /* ================================================================== */

    /** Creates replen_task record with correct fields */
    public function testCreateReplenTaskCreatesRecord(): void
    {
        $pid = $this->createSkuWithPickface(20, 5);
        $config = PickfaceSplitter::getPickfaceConfig($pid, self::$pdo);
        $pfBinId = $config['pickface_bin_id'];
        $pfLoc = $config['pickface_location_code'];

        // Create stock in a bulk bin (row B-E) for the source bin lookup
        $bulkLoc = $this->createBulkBin('CA02B01', 'B');
        TestDataFactory::createStock($pid, $bulkLoc, 50.0, 'BULK-BATCH');

        $taskId = PickfaceSplitter::createReplenTask($pid, 10.0, null, self::$pdo);

        $this->assertGreaterThan(0, $taskId);

        // Verify the record was created with correct fields
        $rows = ApiTestHelpers::q(
            "SELECT * FROM replen_task WHERE id = ?",
            [$taskId]
        );
        $this->assertCount(1, $rows);
        $task = $rows[0];
        $this->assertEquals($pid, (int)$task['sku_id']);
        $this->assertEquals(10, (int)$task['qty']);
        $this->assertEquals('pending', $task['status']);
        $this->assertNotNull($task['created_at']);
    }

    /** createReplenTask with triggering order ID stores it */
    public function testCreateReplenTaskWithOrderId(): void
    {
        $pid = $this->createSkuWithPickface(20, 5);
        $config = PickfaceSplitter::getPickfaceConfig($pid, self::$pdo);

        $bulkLoc = $this->createBulkBin('CA03B01', 'B');
        TestDataFactory::createStock($pid, $bulkLoc, 50.0, 'BULK-B2');

        // Create a dummy outbound order for the triggering_order_id FK
        $outboundId = TestDataFactory::createOutbound(1, [
            ['product_id' => $pid, 'quantity' => 15.0],
        ]);

        $taskId = PickfaceSplitter::createReplenTask($pid, 8.0, $outboundId, self::$pdo);

        $rows = ApiTestHelpers::q("SELECT * FROM replen_task WHERE id = ?", [$taskId]);
        $this->assertEquals($outboundId, (int)$rows[0]['triggering_order_id']);
    }

    /** createReplenTask throws when pickfaceQty <= 0 */
    public function testCreateReplenTaskThrowsOnZeroQty(): void
    {
        $pid = $this->createSkuWithPickface(20, 5);

        $this->expectException(\InvalidArgumentException::class);
        PickfaceSplitter::createReplenTask($pid, 0.0, null, self::$pdo);
    }

    /** createReplenTask throws when no pickface config exists */
    public function testCreateReplenTaskThrowsOnMissingConfig(): void
    {
        $pid = TestDataFactory::createProduct();

        $this->expectException(\Exception::class);
        PickfaceSplitter::createReplenTask($pid, 10.0, null, self::$pdo);
    }

    /** createReplenTask throws when no suitable source bin found */
    public function testCreateReplenTaskThrowsOnNoSourceBin(): void
    {
        $pid = $this->createSkuWithPickface(20, 5);
        // No stock in any bulk bin → _findSourceBin returns null

        $this->expectException(\Exception::class);
        PickfaceSplitter::createReplenTask($pid, 10.0, null, self::$pdo);
    }

    /* ================================================================== */
    /* Helpers                                                             */
    /* ================================================================== */

    /**
     * Create a product + pickface config + pickface location.
     * Returns the product ID.
     */
    private function createSkuWithPickface(int $pickfaceMax, int $pickfaceMin): int
    {
        $pid = TestDataFactory::createProduct();

        // Create a pickface location (row A = pick face)
        $pfCode = 'PF' . str_pad((string) $pid, 4, '0', STR_PAD_LEFT) . 'A01';
        self::$pdo->prepare(
            "INSERT IGNORE INTO location_master
                (location_code, aisle, rack, row_name, position, is_pick_face, equipment_accessible, is_active)
             VALUES (?, 'PF', '01', 'A', '01', 1, 1, 1)"
        )->execute([$pfCode]);

        $pfBinId = (int) self::$pdo->lastInsertId();
        if ($pfBinId === 0) {
            // Already existed — fetch its id
            $row = ApiTestHelpers::q("SELECT id FROM location_master WHERE location_code = ?", [$pfCode]);
            $pfBinId = (int) $row[0]['id'];
        }

        // Create pickface config
        self::$pdo->prepare(
            "INSERT INTO sku_pickface_config (sku_id, pickface_bin_id, pickface_max, pickface_min)
             VALUES (?, ?, ?, ?)"
        )->execute([$pid, $pfBinId, $pickfaceMax, $pickfaceMin]);

        return $pid;
    }

    /**
     * Get the pickface location_code for a SKU's pickface config.
     */
    private function getPickfaceLocationCode(int $skuId): string
    {
        $config = PickfaceSplitter::getPickfaceConfig($skuId, self::$pdo);
        return $config['pickface_location_code'];
    }

    /**
     * Create a bulk bin location (row B-E) and return its location_code.
     */
    private function createBulkBin(string $code, string $rowName): string
    {
        self::$pdo->prepare(
            "INSERT IGNORE INTO location_master
                (location_code, aisle, rack, row_name, position, is_pick_face, equipment_accessible, is_active)
             VALUES (?, ?, '01', ?, '01', 0, 0, 1)"
        )->execute([$code, substr($code, 0, 2), $rowName]);
        return $code;
    }

    /**
     * Insert a replen_task record directly for test setup.
     */
    private function createReplenTaskDirect(
        int    $skuId,
        int    $destBinId,
        int    $qty,
        string $status
    ): int {
        // Find any source bin (just use a dummy location_id)
        $srcRow = ApiTestHelpers::q(
            "SELECT id FROM location_master WHERE location_code = 'CA01B01' LIMIT 1"
        );
        $srcBinId = !empty($srcRow) ? (int) $srcRow[0]['id'] : 1;

        self::$pdo->prepare(
            "INSERT INTO replen_task
                (sku_id, source_bin_id, destination_bin_id, qty, status, created_at)
             VALUES (?, ?, ?, ?, ?, NOW())"
        )->execute([$skuId, $srcBinId, $destBinId, $qty, $status]);

        return (int) self::$pdo->lastInsertId();
    }

    /* ================================================================== */
    /* Auto-detect pickface config — occupancy-based assignment            */
    /* ================================================================== */

    /** Low occupancy (<90%): SKU with no A-level stock → empty bin claimed and persisted */
    public function testAutoDetectLowOccupancyClaimsEmptyBin(): void
    {
        // Ensure low occupancy: clear all stock from A-level bins
        self::$pdo->exec(
            "DELETE s FROM stock s
             JOIN location_master lm ON lm.location_code = s.location
             WHERE lm.location_code REGEXP '[A-Z]{2}[0-9]{2}A[0-9]{2}$'"
        );

        // Create a product with no existing pickface config
        $pid = TestDataFactory::createProduct();

        $config = PickfaceSplitter::getPickfaceConfig($pid, self::$pdo);

        // Should have auto-detected and persisted a config
        self::assertNotNull($config, 'Auto-detect should claim an empty A-level bin');
        self::assertGreaterThan(0, $config['id'], 'Config should be persisted with a real ID');
        self::assertMatchesRegularExpression(
            '/^[A-Z]{2}[0-9]{2}A[0-9]{2}$/',
            $config['pickface_location_code']
        );

        // Confirm row exists in sku_pickface_config
        $check = ApiTestHelpers::q(
            "SELECT * FROM sku_pickface_config WHERE sku_id = ?",
            [$pid]
        );
        self::assertCount(1, $check);
    }

    /** High occupancy (≥90%): SKU with existing A-level stock → stock-based bin returned, not persisted */
    public function testAutoDetectHighOccupancyFallsBackToStock(): void
    {
        // Create product and seed stock in an A-level bin
        $pid = TestDataFactory::createProduct();
        $aLoc = 'CA01A01';
        TestDataFactory::createStock($pid, $aLoc, 50.0, 'LPN-HIGH', '2030-12-31');

        // Force high occupancy: fill all but one A-level bin with dummy stock
        $allABins = ApiTestHelpers::q(
            "SELECT location_code FROM location_master
             WHERE location_code REGEXP '[A-Z]{2}[0-9]{2}A[0-9]{2}$'
               AND is_active = 1 AND location_code != ?
             ORDER BY location_code",
            [$aLoc]
        );
        $dummyPid = TestDataFactory::createProduct();
        foreach ($allABins as $row) {
            TestDataFactory::createStock($dummyPid, $row['location_code'], 1.0, 'LPN-DUM', '2030-12-31');
        }
        // 11/12 occupied = 91.7% ≥ 90%

        $config = PickfaceSplitter::getPickfaceConfig($pid, self::$pdo);

        self::assertNotNull($config, 'Stock-based fallback should find a bin');
        self::assertEquals(0, $config['id'], 'Stock-based path is not persisted (id=0)');
        self::assertEquals($aLoc, $config['pickface_location_code']);
    }

    /** High occupancy + SKU with no existing A-level stock at all → null */
    public function testAutoDetectHighOccupancyNoStockReturnsNull(): void
    {
        // Force high occupancy: fill all A-level bins with dummy stock
        $allABins = ApiTestHelpers::q(
            "SELECT location_code FROM location_master
             WHERE location_code REGEXP '[A-Z]{2}[0-9]{2}A[0-9]{2}$' AND is_active = 1
             ORDER BY location_code"
        );
        $dummyPid = TestDataFactory::createProduct();
        foreach ($allABins as $row) {
            TestDataFactory::createStock($dummyPid, $row['location_code'], 1.0, 'LPN-DUM', '2030-12-31');
        }
        // 12/12 = 100% ≥ 90%

        // New product with no stock anywhere
        $pid = TestDataFactory::createProduct();

        $config = PickfaceSplitter::getPickfaceConfig($pid, self::$pdo);

        self::assertNull($config, 'No A-level stock + high occupancy → null');
    }
}

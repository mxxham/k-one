<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Inbound.php — pure calculation methods + DB-dependent methods
 * using injected PDO from the test database.
 *
 * Covers: calculatePallet, calculateExpiryDate, calculatePalletDistribution,
 *         calcPalletByLocation, generateNumber, create, getById, getItems,
 *         addItem, complete, delete, getStats.
 */
class InboundUnitTest extends TestCase
{
    private static PDO $pdo;

    public static function setUpBeforeClass(): void
    {
        self::$pdo = ApiTestHelpers::pdo();
    }

    protected function setUp(): void
    {
        ApiTestHelpers::truncateTransactional(self::$pdo);
    }

    /* ================================================================== */
    /* Pure calculation methods (no DB)                                     */
    /* ================================================================== */

    /** calculatePallet: standard division rounds up. */
    public function testCalculatePalletStandard(): void
    {
        $this->assertSame(1.0, Inbound::calculatePallet(10, 4));
        $this->assertSame(1.0, Inbound::calculatePallet(1, 4));
        $this->assertSame(1.0, Inbound::calculatePallet(4, 4));
    }

    /** calculatePallet: exact multiple is not rounded up. */
    public function testCalculatePalletExactMultiple(): void
    {
        $this->assertSame(2.0, Inbound::calculatePallet(8, 4));
        $this->assertSame(3.0, Inbound::calculatePallet(12, 4));
    }

    /** calculatePallet: remainder causes ceil. */
    public function testCalculatePalletRemainder(): void
    {
        $this->assertSame(3.0, Inbound::calculatePallet(10, 4));
        $this->assertSame(4.0, Inbound::calculatePallet(13, 4));
    }

    /** calculatePallet: zero uomPerPallet returns 0. */
    public function testCalculatePalletZeroUom(): void
    {
        $this->assertSame(0, Inbound::calculatePallet(10, 0));
    }

    /** calculatePallet: zero quantity returns 0. */
    public function testCalculatePalletZeroQuantity(): void
    {
        $this->assertSame(0.0, Inbound::calculatePallet(0, 4));
    }

    /** calculateExpiryDate: adds default 4 years. */
    public function testCalculateExpiryDateDefault(): void
    {
        $result = Inbound::calculateExpiryDate('2024-01-15');
        $this->assertSame('2028-01-15', $result);
    }

    /** calculateExpiryDate: custom years parameter. */
    public function testCalculateExpiryDateCustomYears(): void
    {
        $result = Inbound::calculateExpiryDate('2024-06-01', 2);
        $this->assertSame('2026-06-01', $result);
    }

    /** calculateExpiryDate: empty input returns null. */
    public function testCalculateExpiryDateEmpty(): void
    {
        $this->assertNull(Inbound::calculateExpiryDate(''));
        $this->assertNull(Inbound::calculateExpiryDate(null));
    }

    /** calculateExpiryDate: leap year handling. */
    public function testCalculateExpiryDateLeapYear(): void
    {
        $result = Inbound::calculateExpiryDate('2024-02-29');
        $this->assertSame('2028-02-29', $result);
    }

    /** calculatePalletDistribution: exact multiple → all full pallets. */
    public function testCalculatePalletDistributionExact(): void
    {
        $dist = Inbound::calculatePalletDistribution(8, 4);
        $this->assertCount(2, $dist);
        $this->assertTrue($dist[0]['is_full']);
        $this->assertTrue($dist[1]['is_full']);
        $this->assertSame(4, $dist[0]['quantity']);
        $this->assertSame(4, $dist[1]['quantity']);
    }

    /** calculatePalletDistribution: remainder → last pallet partial. */
    public function testCalculatePalletDistributionWithRemainder(): void
    {
        $dist = Inbound::calculatePalletDistribution(10, 4);
        $this->assertCount(3, $dist);
        $this->assertTrue($dist[0]['is_full']);
        $this->assertTrue($dist[1]['is_full']);
        $this->assertFalse($dist[2]['is_full']);
        $this->assertSame(2, $dist[2]['quantity']);
    }

    /** calculatePalletDistribution: quantity < uomPerPallet → one partial pallet. */
    public function testCalculatePalletDistributionSmall(): void
    {
        $dist = Inbound::calculatePalletDistribution(3, 4);
        $this->assertCount(1, $dist);
        $this->assertFalse($dist[0]['is_full']);
        $this->assertSame(3, $dist[0]['quantity']);
    }

    /** calculatePalletDistribution: pallet_seq increments. */
    public function testCalculatePalletDistributionSequence(): void
    {
        $dist = Inbound::calculatePalletDistribution(13, 4);
        $this->assertSame(1, $dist[0]['pallet_seq']);
        $this->assertSame(2, $dist[1]['pallet_seq']);
        $this->assertSame(3, $dist[2]['pallet_seq']);
    }

    /** calcPalletByLocation: pick-face (level A) returns fractional pallet. */
    public function testCalcPalletByLocationPickFace(): void
    {
        $result = Inbound::calcPalletByLocation(10, 4, 'CA01A01');
        $this->assertIsFloat($result);
        $this->assertSame(2.5, $result);
    }

    /** calcPalletByLocation: non-pick-face returns integer ceil. */
    public function testCalcPalletByLocationNonPickFace(): void
    {
        $result = Inbound::calcPalletByLocation(10, 4, 'CA01B01');
        $this->assertIsInt($result);
        $this->assertSame(3, $result);
    }

    /** calcPalletByLocation: zero UOM returns 0. */
    public function testCalcPalletByLocationZeroUom(): void
    {
        $this->assertSame(0, Inbound::calcPalletByLocation(10, 0, 'CA01A01'));
    }

    /* ================================================================== */
    /* DB-dependent: generateNumber                                        */
    /* ================================================================== */

    /** generateNumber: returns format IN-YYYYMM-NNNN. */
    public function testGenerateNumberFormat(): void
    {
        $num = Inbound::generateNumber(self::$pdo);
        $pattern = '/^IN-\d{6}-\d{4}$/';
        $this->assertMatchesRegularExpression($pattern, $num);
    }

    /** generateNumber: sequential increment. */
    public function testGenerateNumberSequential(): void
    {
        $num1 = Inbound::generateNumber(self::$pdo);
        $num2 = Inbound::generateNumber(self::$pdo);
        // Both should be valid format and different
        $this->assertNotSame($num1, $num2);
    }

    /* ================================================================== */
    /* DB-dependent: create / getById / getItems / delete                  */
    /* ================================================================== */

    /** create: minimal inbound order. */
    public function testCreateMinimal(): void
    {
        $userId = TestDataFactory::createUser('operator');
        $inboundId = Inbound::create([
            'order_date'  => date('Y-m-d'),
            'created_by'  => $userId,
        ], self::$pdo);

        $this->assertGreaterThan(0, $inboundId);

        $order = Inbound::getById($inboundId, self::$pdo);
        $this->assertIsArray($order);
        $this->assertSame($userId, (int) $order['created_by']);
        $this->assertSame('Dues In', $order['status']);
    }

    /** create: with shipment number uses it as order_number. */
    public function testCreateWithShipmentNo(): void
    {
        $userId = TestDataFactory::createUser('operator');
        $inboundId = Inbound::create([
            'order_date'  => date('Y-m-d'),
            'shipment_no' => 'SHP-20240101-001',
            'created_by'  => $userId,
        ], self::$pdo);

        $order = Inbound::getById($inboundId, self::$pdo);
        $this->assertSame('SHP-20240101-001', $order['order_number']);
    }

    /** create: with items. */
    public function testCreateWithItems(): void
    {
        $pid = TestDataFactory::createProduct(['product_code' => 'INP01']);
        $userId = TestDataFactory::createUser('operator');
        $inboundId = Inbound::create([
            'order_date' => date('Y-m-d'),
            'created_by' => $userId,
            'items' => [
                [
                    'product_id' => $pid,
                    'quantity'   => 20.0,
                    'batch_number' => 'BAT-INP01',
                ],
            ],
        ], self::$pdo);

        $items = Inbound::getItems($inboundId, self::$pdo);
        $this->assertCount(1, $items);
        $this->assertSame($pid, (int) $items[0]['product_id']);
        $this->assertSame(20.0, (float) $items[0]['quantity']);
    }

    /** addItem: adds item to existing inbound. */
    public function testAddItem(): void
    {
        $pid = TestDataFactory::createProduct(['product_code' => 'INP02']);
        $userId = TestDataFactory::createUser('operator');
        $inboundId = Inbound::create([
            'order_date' => date('Y-m-d'),
            'created_by' => $userId,
        ], self::$pdo);

        $itemId = Inbound::addItem($inboundId, [
            'product_id' => $pid,
            'quantity'   => 15.0,
            'batch_number' => 'BAT-INP02',
        ], self::$pdo);

        $this->assertGreaterThan(0, $itemId);
        $items = Inbound::getItems($inboundId, self::$pdo);
        $this->assertCount(1, $items);
    }

    /** addItem: calculates pallet from quantity / uom_per_pallet. */
    public function testAddItemCalculatesPallet(): void
    {
        $pid = TestDataFactory::createProduct([
            'product_code'    => 'INP03',
            'uom_per_pallet' => 4,
        ]);
        $userId = TestDataFactory::createUser('operator');
        $inboundId = Inbound::create([
            'order_date' => date('Y-m-d'),
            'created_by' => $userId,
        ], self::$pdo);

        Inbound::addItem($inboundId, [
            'product_id' => $pid,
            'quantity'   => 10.0,
        ], self::$pdo);

        $items = Inbound::getItems($inboundId, self::$pdo);
        $this->assertSame(3.0, (float) $items[0]['pallet']);
    }

    /** addItem: manufacture_date + 4 years = expiry date. */
    public function testAddItemExpiryFromManufactureDate(): void
    {
        $pid = TestDataFactory::createProduct(['product_code' => 'INP04']);
        $userId = TestDataFactory::createUser('operator');
        $inboundId = Inbound::create([
            'order_date' => date('Y-m-d'),
            'created_by' => $userId,
        ], self::$pdo);

        Inbound::addItem($inboundId, [
            'product_id'      => $pid,
            'quantity'        => 10.0,
            'manufacture_date' => '2024-03-15',
        ], self::$pdo);

        $items = Inbound::getItems($inboundId, self::$pdo);
        $this->assertSame('2028-03-15', $items[0]['exp_date']);
    }

    /** getById: returns null for non-existent id. */
    public function testGetByIdNotFound(): void
    {
        $result = Inbound::getById(99999, self::$pdo);
        $this->assertFalse($result);
    }

    /** getItems: returns empty array for empty order. */
    public function testGetItemsEmpty(): void
    {
        $userId = TestDataFactory::createUser('operator');
        $inboundId = Inbound::create([
            'order_date' => date('Y-m-d'),
            'created_by' => $userId,
        ], self::$pdo);

        $items = Inbound::getItems($inboundId, self::$pdo);
        $this->assertIsArray($items);
        $this->assertEmpty($items);
    }

    /** getStats: returns zeroed stats when empty. */
    public function testGetStatsEmpty(): void
    {
        $stats = Inbound::getStats(self::$pdo);
        $this->assertIsArray($stats);
        $this->assertArrayHasKey('this_month', $stats);
        $this->assertArrayHasKey('by_status', $stats);
    }

    /** countAll: returns 0 when no orders. */
    public function testCountAllEmpty(): void
    {
        $count = Inbound::countAll(null, null, self::$pdo);
        $this->assertSame(0, $count);
    }

    /** countAll: counts after insert. */
    public function testCountAllWithOrders(): void
    {
        $userId = TestDataFactory::createUser('operator');
        Inbound::create([
            'order_date' => date('Y-m-d'),
            'created_by' => $userId,
        ], self::$pdo);

        Inbound::create([
            'order_date' => date('Y-m-d'),
            'created_by' => $userId,
            'status'     => 'Completed',
        ], self::$pdo);

        $count = Inbound::countAll(null, null, self::$pdo);
        $this->assertSame(2, $count);
    }

    /** countAll: status filter. */
    public function testCountAllWithStatusFilter(): void
    {
        $userId = TestDataFactory::createUser('operator');
        Inbound::create([
            'order_date' => date('Y-m-d'),
            'created_by' => $userId,
            'status'     => 'Completed',
        ], self::$pdo);

        Inbound::create([
            'order_date' => date('Y-m-d'),
            'created_by' => $userId,
            'status'     => 'Dues In',
        ], self::$pdo);

        $this->assertSame(1, Inbound::countAll('Completed', null, self::$pdo));
        $this->assertSame(1, Inbound::countAll('Dues In', null, self::$pdo));
        $this->assertSame(0, Inbound::countAll('Receiving', null, self::$pdo));
    }

    /** delete: removes order and items. */
    public function testDelete(): void
    {
        $pid = TestDataFactory::createProduct(['product_code' => 'INP05']);
        $userId = TestDataFactory::createUser('operator');
        $inboundId = Inbound::create([
            'order_date' => date('Y-m-d'),
            'created_by' => $userId,
            'items' => [
                ['product_id' => $pid, 'quantity' => 5.0],
            ],
        ], self::$pdo);

        $result = Inbound::delete($inboundId, self::$pdo);
        $this->assertTrue($result);

        $order = Inbound::getById($inboundId, self::$pdo);
        $this->assertFalse($order);

        $items = Inbound::getItems($inboundId, self::$pdo);
        $this->assertEmpty($items);
    }

    /** delete: stock rows from completed inbound are also cleaned up. */
    public function testDeleteCleansStock(): void
    {
        $pid = TestDataFactory::createProduct(['product_code' => 'INP06']);
        $userId = TestDataFactory::createUser('operator');
        $inboundId = Inbound::create([
            'order_date' => date('Y-m-d'),
            'created_by' => $userId,
            'items' => [
                [
                    'product_id' => $pid,
                    'quantity'   => 10.0,
                    'batch_number' => 'DEL-BAT01',
                ],
            ],
        ], self::$pdo);

        // Simulate completed inbound with stock
        self::$pdo->prepare("UPDATE inbound_orders SET status='Completed' WHERE id=?")
            ->execute([$inboundId]);

        // Insert stock as complete() would
        self::$pdo->prepare(
            "INSERT INTO stock (product_id, batch_number, location, quantity, uom, pallet, stock_status)
             VALUES (?, 'DEL-BAT01', 'CA01A01', 10, 'Drum', 3, 'Available')"
        )->execute([$pid]);

        $stockBefore = ApiTestHelpers::q(
            "SELECT id FROM stock WHERE product_id = ? AND batch_number = 'DEL-BAT01'",
            [$pid]
        );
        $this->assertNotEmpty($stockBefore);

        Inbound::delete($inboundId, self::$pdo);

        $stockAfter = ApiTestHelpers::q(
            "SELECT id FROM stock WHERE product_id = ? AND batch_number = 'DEL-BAT01'",
            [$pid]
        );
        $this->assertEmpty($stockAfter);
    }

    /** getAll: returns orders ordered by date desc. */
    public function testGetAllOrdering(): void
    {
        $userId = TestDataFactory::createUser('operator');

        Inbound::create([
            'order_date' => '2024-01-01',
            'created_by' => $userId,
        ], self::$pdo);

        Inbound::create([
            'order_date' => '2024-06-15',
            'created_by' => $userId,
        ], self::$pdo);

        $rows = Inbound::getAll(null, null, 0, null, self::$pdo);
        $this->assertCount(2, $rows);
        // Most recent first
        $this->assertGreaterThanOrEqual(
            strtotime($rows[1]['order_date']),
            strtotime($rows[0]['order_date'])
        );
    }

    /** getAll: limit/offset pagination. */
    public function testGetAllPagination(): void
    {
        $userId = TestDataFactory::createUser('operator');
        for ($i = 0; $i < 5; $i++) {
            Inbound::create([
                'order_date' => date('Y-m-d'),
                'created_by' => $userId,
            ], self::$pdo);
        }

        $page1 = Inbound::getAll(null, 2, 0, null, self::$pdo);
        $page2 = Inbound::getAll(null, 2, 2, null, self::$pdo);
        $this->assertCount(2, $page1);
        $this->assertCount(2, $page2);
        // Different orders
        $this->assertNotSame($page1[0]['id'], $page2[0]['id']);
    }

    /** addItem: Unserviceable status skips pallet_locations. */
    public function testAddItemUnserviceableNoPalletLocations(): void
    {
        $pid = TestDataFactory::createProduct(['product_code' => 'INP07']);
        $userId = TestDataFactory::createUser('operator');
        $inboundId = Inbound::create([
            'order_date' => date('Y-m-d'),
            'created_by' => $userId,
        ], self::$pdo);

        $itemId = Inbound::addItem($inboundId, [
            'product_id'        => $pid,
            'quantity'          => 10.0,
            'in_process_status' => 'Unserviceable',
            'stock_status'      => 'Rejected',
        ], self::$pdo);

        // No stock_locations should be created for unserviceable items
        $locs = ApiTestHelpers::q(
            "SELECT * FROM stock_locations WHERE inbound_item_id = ?",
            [$itemId]
        );
        $this->assertEmpty($locs);
    }
}

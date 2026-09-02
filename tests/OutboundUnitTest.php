<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Outbound.php — pure calculation methods + DB-dependent methods
 * using injected PDO from the test database.
 *
 * Covers: displayOrderNo, calculatePallet, calculatePalletDistribution,
 *         generateNumber, create, getById, getItems, delete, getAll,
 *         countAll, getTotalAvailableQty, getAvailableStock.
 */
class OutboundUnitTest extends TestCase
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

    /** displayOrderNo: prefers shipment_number over order_number. */
    public function testDisplayOrderNoShipmentPriority(): void
    {
        $result = Outbound::displayOrderNo([
            'shipment_number' => 'SHP-001',
            'order_number'    => 'OUT-001',
        ]);
        $this->assertSame('SHP-001', $result);
    }

    /** displayOrderNo: falls back to order_number when shipment is empty. */
    public function testDisplayOrderNoFallbackToOrder(): void
    {
        $result = Outbound::displayOrderNo([
            'shipment_number' => '',
            'order_number'    => 'OUT-002',
        ]);
        $this->assertSame('OUT-002', $result);
    }

    /** displayOrderNo: both empty returns dash. */
    public function testDisplayOrderNoBothEmpty(): void
    {
        $result = Outbound::displayOrderNo([
            'shipment_number' => '',
            'order_number'    => '',
        ]);
        $this->assertSame('-', $result);
    }

    /** displayOrderNo: missing keys return dash. */
    public function testDisplayOrderNoMissingKeys(): void
    {
        $result = Outbound::displayOrderNo([]);
        $this->assertSame('-', $result);
    }

    /** displayOrderNo: null shipment_number treated as empty. */
    public function testDisplayOrderNoNullShipment(): void
    {
        $result = Outbound::displayOrderNo([
            'shipment_number' => null,
            'order_number'    => 'OUT-003',
        ]);
        $this->assertSame('OUT-003', $result);
    }

    /** displayOrderNo: whitespace-only shipment falls back to order. */
    public function testDisplayOrderNoWhitespaceShipment(): void
    {
        $result = Outbound::displayOrderNo([
            'shipment_number' => '   ',
            'order_number'    => 'OUT-004',
        ]);
        $this->assertSame('OUT-004', $result);
    }

    /** calculatePallet: standard ceil division. */
    public function testCalculatePalletStandard(): void
    {
        $this->assertSame(1.0, Outbound::calculatePallet(10, 4));
        $this->assertSame(1.0, Outbound::calculatePallet(4, 4));
        $this->assertSame(3.0, Outbound::calculatePallet(10, 4));
        $this->assertSame(2.0, Outbound::calculatePallet(8, 4));
    }

    /** calculatePallet: zero uom returns 0. */
    public function testCalculatePalletZeroUom(): void
    {
        $this->assertSame(0, Outbound::calculatePallet(10, 0));
    }

    /** calculatePallet: zero quantity returns 0. */
    public function testCalculatePalletZeroQuantity(): void
    {
        $this->assertSame(0.0, Outbound::calculatePallet(0, 4));
    }

    /** calculatePalletDistribution: exact multiple → all full. */
    public function testCalculatePalletDistributionExact(): void
    {
        $dist = Outbound::calculatePalletDistribution(8, 4);
        $this->assertCount(2, $dist);
        $this->assertTrue($dist[0]['is_full']);
        $this->assertTrue($dist[1]['is_full']);
        $this->assertSame(4, $dist[0]['quantity']);
        $this->assertSame(4, $dist[1]['quantity']);
        $this->assertSame(1, $dist[0]['pallet_number']);
        $this->assertSame(2, $dist[1]['pallet_number']);
    }

    /** calculatePalletDistribution: remainder → partial last. */
    public function testCalculatePalletDistributionWithRemainder(): void
    {
        $dist = Outbound::calculatePalletDistribution(10, 4);
        $this->assertCount(3, $dist);
        $this->assertTrue($dist[0]['is_full']);
        $this->assertTrue($dist[1]['is_full']);
        $this->assertFalse($dist[2]['is_full']);
        $this->assertSame(2, $dist[2]['quantity']);
        $this->assertSame(3, $dist[2]['pallet_number']);
    }

    /** calculatePalletDistribution: qty < uom → one partial. */
    public function testCalculatePalletDistributionSmall(): void
    {
        $dist = Outbound::calculatePalletDistribution(3, 4);
        $this->assertCount(1, $dist);
        $this->assertFalse($dist[0]['is_full']);
        $this->assertSame(3, $dist[0]['quantity']);
    }

    /* ================================================================== */
    /* DB-dependent: generateNumber                                        */
    /* ================================================================== */

    /** generateNumber: returns format OUT-YYYYMM-NNNN. */
    public function testGenerateNumberFormat(): void
    {
        $num = Outbound::generateNumber(self::$pdo);
        $pattern = '/^OUT-\d{6}-\d{4}$/';
        $this->assertMatchesRegularExpression($pattern, $num);
    }

    /** generateNumber: sequential calls return different numbers. */
    public function testGenerateNumberSequential(): void
    {
        $num1 = Outbound::generateNumber(self::$pdo);
        $num2 = Outbound::generateNumber(self::$pdo);
        $this->assertNotSame($num1, $num2);
    }

    /* ================================================================== */
    /* DB-dependent: create / getById / getItems / delete                  */
    /* ================================================================== */

    /** create: minimal outbound order. */
    public function testCreateMinimal(): void
    {
        $custId = TestDataFactory::createCustomer();
        $userId = TestDataFactory::createUser('operator');

        $_SESSION['user_id'] = $userId;
        $outboundId = Outbound::create([
            'order_date'   => date('Y-m-d'),
            'customer_id'  => $custId,
            'status'       => 'Open',
            'created_by'   => $userId,
        ], self::$pdo);

        $this->assertGreaterThan(0, $outboundId);

        $order = Outbound::getById($outboundId, self::$pdo);
        $this->assertIsArray($order);
        $this->assertSame('Open', $order['status']);
    }

    /** create: generates unique order number. */
    public function testCreateGeneratesOrderNumber(): void
    {
        $custId = TestDataFactory::createCustomer();
        $userId = TestDataFactory::createUser('operator');

        $_SESSION['user_id'] = $userId;
        $outboundId = Outbound::create([
            'order_date'   => date('Y-m-d'),
            'customer_id'  => $custId,
        ], self::$pdo);

        $order = Outbound::getById($outboundId, self::$pdo);
        $this->assertMatchesRegularExpression('/^OUT-\d{6}-\d{4}$/', $order['order_number']);
    }

    /** create: with inline items. */
    public function testCreateWithItems(): void
    {
        $pid    = TestDataFactory::createProduct(['product_code' => 'OB01']);
        $custId = TestDataFactory::createCustomer();
        $userId = TestDataFactory::createUser('operator');

        // Put stock for FEFO allocation
        TestDataFactory::createStock($pid, 'CA01A01', 20.0, 'BATCH-OB01');

        $_SESSION['user_id'] = $userId;
        $outboundId = Outbound::create([
            'order_date'   => date('Y-m-d'),
            'customer_id'  => $custId,
            'items' => [
                [
                    'product_id' => $pid,
                    'quantity'   => 5.0,
                ],
            ],
        ], self::$pdo);

        $items = Outbound::getItems($outboundId, self::$pdo);
        $this->assertCount(1, $items);
        $this->assertSame($pid, (int) $items[0]['product_id']);
    }

    /** getById: non-existent returns null/false. */
    public function testGetByIdNotFound(): void
    {
        $result = Outbound::getById(99999, self::$pdo);
        $this->assertFalse($result);
    }

    /** getItems: empty order returns empty array. */
    public function testGetItemsEmpty(): void
    {
        $custId = TestDataFactory::createCustomer();
        $userId = TestDataFactory::createUser('operator');

        $_SESSION['user_id'] = $userId;
        $outboundId = Outbound::create([
            'order_date'   => date('Y-m-d'),
            'customer_id'  => $custId,
        ], self::$pdo);

        $items = Outbound::getItems($outboundId, self::$pdo);
        $this->assertIsArray($items);
        $this->assertEmpty($items);
    }

    /** delete: removes order and items. */
    public function testDelete(): void
    {
        $pid    = TestDataFactory::createProduct(['product_code' => 'OBD01']);
        $custId = TestDataFactory::createCustomer();
        $userId = TestDataFactory::createUser('operator');

        TestDataFactory::createStock($pid, 'CA01A01', 20.0, 'BATCH-OBD1');

        $_SESSION['user_id'] = $userId;
        $outboundId = Outbound::create([
            'order_date'   => date('Y-m-d'),
            'customer_id'  => $custId,
            'items' => [
                ['product_id' => $pid, 'quantity' => 5.0],
            ],
        ], self::$pdo);

        $result = Outbound::delete($outboundId, self::$pdo);
        $this->assertTrue($result);

        $order = Outbound::getById($outboundId, self::$pdo);
        $this->assertFalse($order);
    }

    /** delete: restores stock for picked orders. */
    public function testDeleteRestoresStock(): void
    {
        $pid    = TestDataFactory::createProduct(['product_code' => 'OBD02']);
        $custId = TestDataFactory::createCustomer();
        $userId = TestDataFactory::createUser('operator');

        $stockId = TestDataFactory::createStock($pid, 'CA01A01', 20.0, 'BATCH-OBD2');
        $origQty = 20.0;

        $_SESSION['user_id'] = $userId;
        $outboundId = Outbound::create([
            'order_date'   => date('Y-m-d'),
            'customer_id'  => $custId,
            'items' => [
                ['product_id' => $pid, 'quantity' => 5.0],
            ],
        ], self::$pdo);

        // Simulate picked state
        self::$pdo->prepare("UPDATE outbound_orders SET status='Picking' WHERE id=?")
            ->execute([$outboundId]);

        $stockBefore = Stock::getById($stockId, self::$pdo);

        Outbound::delete($outboundId, self::$pdo);

        $stockAfter = Stock::getById($stockId, self::$pdo);
        // Stock should be restored
        $this->assertGreaterThan((float) $stockBefore['quantity'], (float) $stockAfter['quantity']);
    }

    /** getAll: returns orders. */
    public function testGetAll(): void
    {
        $custId = TestDataFactory::createCustomer();
        $userId = TestDataFactory::createUser('operator');

        $_SESSION['user_id'] = $userId;
        Outbound::create([
            'order_date'   => date('Y-m-d'),
            'customer_id'  => $custId,
        ], self::$pdo);

        $rows = Outbound::getAll(null, null, 0, null, null, self::$pdo);
        $this->assertNotEmpty($rows);
    }

    /** countAll: returns 0 when empty. */
    public function testCountAllEmpty(): void
    {
        $count = Outbound::countAll(null, null, null, self::$pdo);
        $this->assertSame(0, $count);
    }

    /** countAll: counts after insert. */
    public function testCountAllWithOrders(): void
    {
        $custId = TestDataFactory::createCustomer();
        $userId = TestDataFactory::createUser('operator');

        $_SESSION['user_id'] = $userId;
        Outbound::create(['order_date' => date('Y-m-d'), 'customer_id' => $custId], self::$pdo);
        Outbound::create(['order_date' => date('Y-m-d'), 'customer_id' => $custId, 'status' => 'Shipped'], self::$pdo);

        $this->assertSame(2, Outbound::countAll(null, null, null, self::$pdo));
    }

    /** countAll: status filter. */
    public function testCountAllStatusFilter(): void
    {
        $custId = TestDataFactory::createCustomer();
        $userId = TestDataFactory::createUser('operator');

        $_SESSION['user_id'] = $userId;
        Outbound::create(['order_date' => date('Y-m-d'), 'customer_id' => $custId, 'status' => 'Open'], self::$pdo);
        Outbound::create(['order_date' => date('Y-m-d'), 'customer_id' => $custId, 'status' => 'Shipped'], self::$pdo);

        $this->assertSame(1, Outbound::countAll('Open', null, null, self::$pdo));
        $this->assertSame(1, Outbound::countAll('Shipped', null, null, self::$pdo));
        $this->assertSame(0, Outbound::countAll('Completed', null, null, self::$pdo));
    }

    /** getTotalAvailableQty: returns 0 when no stock. */
    public function testGetTotalAvailableQtyEmpty(): void
    {
        $pid = TestDataFactory::createProduct(['product_code' => 'TAV01']);
        $qty = Outbound::getTotalAvailableQty($pid, self::$pdo);
        $this->assertSame(0.0, $qty);
    }

    /** getTotalAvailableQty: sums available stock for product. */
    public function testGetTotalAvailableQtyWithStock(): void
    {
        $pid = TestDataFactory::createProduct(['product_code' => 'TAV02']);
        TestDataFactory::createStock($pid, 'CA01A01', 10.0, 'BATCH-TAV2');
        TestDataFactory::createStock($pid, 'CA02B01', 5.0, 'BATCH-TAV3');

        $qty = Outbound::getTotalAvailableQty($pid, self::$pdo);
        $this->assertSame(15.0, $qty);
    }

    /** getTotalAvailableQty: excludes held stock. */
    public function testGetTotalAvailableQtyExcludesHeld(): void
    {
        $pid  = TestDataFactory::createProduct(['product_code' => 'TAV03']);
        $sid1 = TestDataFactory::createStock($pid, 'CA01A01', 10.0, 'BATCH-TAV4');
        TestDataFactory::createStock($pid, 'CA02B01', 5.0, 'BATCH-TAV5');

        Stock::hold($sid1, 'quarantine', 'test', 1, self::$pdo);

        $qty = Outbound::getTotalAvailableQty($pid, self::$pdo);
        $this->assertSame(5.0, $qty);
    }

    /** getAvailableStock: returns empty when no stock. */
    public function testGetAvailableStockEmpty(): void
    {
        $pid = TestDataFactory::createProduct(['product_code' => 'AVS01']);
        $rows = Outbound::getAvailableStock($pid, 0, null, self::$pdo);
        $this->assertIsArray($rows);
        $this->assertEmpty($rows);
    }

    /** getAvailableStock: returns FEFO-ordered stock rows. */
    public function testGetAvailableStockFeFoOrder(): void
    {
        $pid = TestDataFactory::createProduct(['product_code' => 'AVS02']);
        TestDataFactory::createStock($pid, 'CA01A01', 10.0, 'BATCH-LATE', '2028-12-31');
        TestDataFactory::createStock($pid, 'CA02B01', 10.0, 'BATCH-EARLY', '2025-06-01');

        $rows = Outbound::getAvailableStock($pid, 0, null, self::$pdo);
        $this->assertCount(2, $rows);
        // Earliest expiry first (FEFO)
        $this->assertSame('BATCH-EARLY', $rows[0]['batch_number']);
    }

    /** getAvailableStock: excludes QUA_SHELL and STAGING. */
    public function testGetAvailableStockExcludesSpecialLocations(): void
    {
        $pid = TestDataFactory::createProduct(['product_code' => 'AVS03']);
        TestDataFactory::createStock($pid, 'CA01A01', 10.0, 'BATCH-NORMAL');
        TestDataFactory::createStock($pid, 'QUA_SHELL', 10.0, 'BATCH-QUA');
        TestDataFactory::createStock($pid, 'STAGING', 10.0, 'BATCH-STG');

        $rows = Outbound::getAvailableStock($pid, 0, null, self::$pdo);
        $this->assertCount(1, $rows);
        $this->assertSame('BATCH-NORMAL', $rows[0]['batch_number']);
    }

    /** getAvailableStock: location filter. */
    public function testGetAvailableStockLocationFilter(): void
    {
        $pid = TestDataFactory::createProduct(['product_code' => 'AVS04']);
        TestDataFactory::createStock($pid, 'CA01A01', 10.0, 'BATCH-LOC1');
        TestDataFactory::createStock($pid, 'CA02B01', 10.0, 'BATCH-LOC2');

        $rows = Outbound::getAvailableStock($pid, 0, 'CA01A01', self::$pdo);
        $this->assertCount(1, $rows);
        $this->assertSame('CA01A01', $rows[0]['location']);
    }

    /** getAvailableStock: excludes held stock. */
    public function testGetAvailableStockExcludesHeld(): void
    {
        $pid  = TestDataFactory::createProduct(['product_code' => 'AVS05']);
        $sid  = TestDataFactory::createStock($pid, 'CA01A01', 10.0, 'BATCH-HLD');
        Stock::hold($sid, 'quarantine', 'test', 1, self::$pdo);

        $rows = Outbound::getAvailableStock($pid, 0, null, self::$pdo);
        $this->assertEmpty($rows);
    }

    /** complete: sets status to Completed. */
    public function testComplete(): void
    {
        $custId = TestDataFactory::createCustomer();
        $userId = TestDataFactory::createUser('operator');

        $_SESSION['user_id'] = $userId;
        $outboundId = Outbound::create([
            'order_date'   => date('Y-m-d'),
            'customer_id'  => $custId,
        ], self::$pdo);

        $result = Outbound::complete($outboundId, self::$pdo);
        $this->assertTrue($result);

        $order = Outbound::getById($outboundId, self::$pdo);
        $this->assertSame('Completed', $order['status']);
    }

    /** getAll: pagination with limit/offset. */
    public function testGetAllPagination(): void
    {
        $custId = TestDataFactory::createCustomer();
        $userId = TestDataFactory::createUser('operator');

        $_SESSION['user_id'] = $userId;
        for ($i = 0; $i < 5; $i++) {
            Outbound::create([
                'order_date'   => date('Y-m-d'),
                'customer_id'  => $custId,
            ], self::$pdo);
        }

        $page1 = Outbound::getAll(null, 2, 0, null, null, self::$pdo);
        $page2 = Outbound::getAll(null, 2, 2, null, null, self::$pdo);
        $this->assertCount(2, $page1);
        $this->assertCount(2, $page2);
        $this->assertNotSame($page1[0]['id'], $page2[0]['id']);
    }

    /** getItemPickedLocations: returns empty for unpicked item. */
    public function testGetItemPickedLocationsEmpty(): void
    {
        $pid    = TestDataFactory::createProduct(['product_code' => 'IPL01']);
        $custId = TestDataFactory::createCustomer();
        $userId = TestDataFactory::createUser('operator');

        TestDataFactory::createStock($pid, 'CA01A01', 20.0, 'BATCH-IPL1');

        $_SESSION['user_id'] = $userId;
        $outboundId = Outbound::create([
            'order_date'   => date('Y-m-d'),
            'customer_id'  => $custId,
            'items' => [
                ['product_id' => $pid, 'quantity' => 5.0],
            ],
        ], self::$pdo);

        $items = Outbound::getItems($outboundId, self::$pdo);
        $locs  = Outbound::getItemPickedLocations((int) $items[0]['id'], self::$pdo);
        $this->assertIsArray($locs);
        $this->assertEmpty($locs);
    }
}

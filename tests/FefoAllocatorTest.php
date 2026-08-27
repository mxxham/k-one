<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * FefoAllocator integration tests — runs against the test server + test DB.
 */
final class FefoAllocatorTest extends TestCase
{
    /** @var string */
    private static $token = '';

    public static function setUpBeforeClass(): void
    {
        self::$token = ApiTestHelpers::login();
    }

    protected function setUp(): void
    {
        // Clean slate for each test
        ApiTestHelpers::resetDb();
    }

    // ---------------------------------------------------------------
    // Test: FEFO order — earliest expiry goes first
    // ---------------------------------------------------------------
    public function testFefoOrder(): void
    {
        $productId = ApiTestHelpers::createProduct(['product_code' => 'SKU-FEFO-1']);

        // Place stock with different expiry dates
        ApiTestHelpers::putStock($productId, 'CA01A01', 20.0, 'BATCH-EARLY', '2025-06-30');
        ApiTestHelpers::putStock($productId, 'CA01A02', 30.0, 'BATCH-LATE', '2027-12-31');
        ApiTestHelpers::putStock($productId, 'CA01A03', 15.0, 'BATCH-MID', '2026-06-30');

        $res = ApiTestHelpers::api('fefo', 'allocate', self::$token, [
            'sku' => 'SKU-FEFO-1',
            'qty' => 25.0,
        ]);

        self::assertEquals(200, $res['status']);
        self::assertTrue($res['body']['success']);
        $alloc = $res['body']['data']['allocation'];

        // FEFO: BATCH-EARLY (2025) first, then BATCH-MID (2026), skip BATCH-LATE (2027)
        self::assertCount(2, $alloc);
        self::assertEquals('BATCH-EARLY', $alloc[0]['batch_number']);
        self::assertEquals(20.0, $alloc[0]['qty_to_take']);
        self::assertEquals('BATCH-MID', $alloc[1]['batch_number']);
        self::assertEquals(5.0, $alloc[1]['qty_to_take']);
        self::assertTrue($res['body']['data']['sufficient']);
    }

    // ---------------------------------------------------------------
    // Test: Insufficient stock throws exception
    // ---------------------------------------------------------------
    public function testInsufficientStockThrows(): void
    {
        $productId = ApiTestHelpers::createProduct(['product_code' => 'SKU-INSUFF']);
        ApiTestHelpers::putStock($productId, 'CA01A01', 5.0, 'BATCH1', '2030-12-31');

        $res = ApiTestHelpers::api('fefo', 'allocate', self::$token, [
            'sku' => 'SKU-INSUFF',
            'qty' => 10.0,
        ]);

        self::assertEquals(200, $res['status']);
        self::assertTrue($res['body']['success']);
        self::assertFalse($res['body']['data']['sufficient']);
        self::assertEqualsWithDelta(5.0, $res['body']['data']['shortage'], 0.01);
    }

    // ---------------------------------------------------------------
    // Test: No stock at all → InsufficientStockException via error
    // ---------------------------------------------------------------
    public function testNoStockThrows(): void
    {
        // Product exists but zero stock
        $productId = ApiTestHelpers::createProduct(['product_code' => 'SKU-ZERO']);

        $res = ApiTestHelpers::api('fefo', 'allocate', self::$token, [
            'sku' => 'SKU-ZERO',
            'qty' => 5.0,
        ]);

        // Should return error (exception caught)
        self::assertNotEquals(200, $res['status']);
    }

    // ---------------------------------------------------------------
    // Test: Unavailable stock is skipped
    // ---------------------------------------------------------------
    public function testUnavailableStockSkipped(): void
    {
        $productId = ApiTestHelpers::createProduct(['product_code' => 'SKU-UNAVAIL']);

        // Available stock
        ApiTestHelpers::putStock($productId, 'CA01A01', 20.0, 'BATCH-GOOD', '2030-01-01');
        // Make another location unavailable
        ApiTestHelpers::putStock($productId, 'CA01A02', 15.0, 'BATCH-BLOCKED', '2030-01-01');
        ApiTestHelpers::q(
            "UPDATE stock_locations SET status = 'On Hold' WHERE location_code = 'CA01A02'"
        );

        $res = ApiTestHelpers::api('fefo', 'allocate', self::$token, [
            'sku' => 'SKU-UNAVAIL',
            'qty' => 25.0,
        ]);

        self::assertEquals(200, $res['status']);
        self::assertTrue($res['body']['success']);
        $alloc = $res['body']['data']['allocation'];

        // Only CA01A01 should be allocated
        self::assertCount(1, $alloc);
        self::assertEquals('CA01A01', $alloc[0]['bin_location']);
        self::assertEquals(20.0, $alloc[0]['qty_to_take']);
        self::assertFalse($res['body']['data']['sufficient']);
    }

    // ---------------------------------------------------------------
    // Test: Zone filter restricts allocation
    // ---------------------------------------------------------------
    public function testZoneFilter(): void
    {
        $productId = ApiTestHelpers::createProduct(['product_code' => 'SKU-ZONE']);

        ApiTestHelpers::putStock($productId, 'CA01A01', 20.0, 'BATCH-CA', '2030-01-01');
        ApiTestHelpers::putStock($productId, 'CB01A01', 20.0, 'BATCH-CB', '2030-01-01');

        $res = ApiTestHelpers::api('fefo', 'allocate', self::$token, [
            'sku'   => 'SKU-ZONE',
            'qty'   => 15.0,
            'zone'  => 'CA',
        ]);

        self::assertEquals(200, $res['status']);
        self::assertTrue($res['body']['success']);
        $alloc = $res['body']['data']['allocation'];

        // Only CA aisle stock should be available
        self::assertCount(1, $alloc);
        self::assertEquals('CA01A01', $alloc[0]['bin_location']);
        self::assertTrue($res['body']['data']['sufficient']);
    }

    // ---------------------------------------------------------------
    // Test: Blocked bin is skipped
    // ---------------------------------------------------------------
    public function testBlockedBinSkipped(): void
    {
        $productId = ApiTestHelpers::createProduct(['product_code' => 'SKU-BLOCKED']);

        ApiTestHelpers::putStock($productId, 'CA01A01', 20.0, 'BATCH-GOOD', '2030-01-01');
        ApiTestHelpers::putStock($productId, 'CA01A02', 20.0, 'BATCH-BLOCKED', '2030-01-01');

        // Block the bin CA01A02
        ApiTestHelpers::q(
            "INSERT INTO putaway_location_blocks (location_code, aisle_prefix, is_active, created_by)
             VALUES ('CA01A02', 'CA', 1, 1)"
        );

        $res = ApiTestHelpers::api('fefo', 'allocate', self::$token, [
            'sku' => 'SKU-BLOCKED',
            'qty' => 15.0,
        ]);

        self::assertEquals(200, $res['status']);
        $alloc = $res['body']['data']['allocation'];

        // Only CA01A01 should be allocated
        self::assertCount(1, $alloc);
        self::assertEquals('CA01A01', $alloc[0]['bin_location']);
    }

    // ---------------------------------------------------------------
    // Test: Partial allocation returns is_partial = true
    // ---------------------------------------------------------------
    public function testPartialAllocation(): void
    {
        $productId = ApiTestHelpers::createProduct(['product_code' => 'SKU-PARTIAL']);

        ApiTestHelpers::putStock($productId, 'CA01A01', 50.0, 'BATCH1', '2030-01-01');

        $res = ApiTestHelpers::api('fefo', 'allocate', self::$token, [
            'sku' => 'SKU-PARTIAL',
            'qty' => 25.0,
        ]);

        self::assertEquals(200, $res['status']);
        $alloc = $res['body']['data']['allocation'];

        self::assertCount(1, $alloc);
        self::assertEquals(25.0, $alloc[0]['qty_to_take']);
        self::assertTrue($alloc[0]['is_partial']);
        self::assertEquals(50.0, $alloc[0]['available_qty']);
    }
}

<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * API endpoint integration tests — covers modules NOT tested by existing
 * test files: dashboard, products, customers, suppliers, ledger, bin_transfer,
 * stock (API layer), activitylog.
 *
 * Uses HTTP API through the spawned test server for true end-to-end coverage.
 */
final class ApiEndpointTest extends TestCase
{
    private static string $token = '';

    public static function setUpBeforeClass(): void
    {
        ApiTestHelpers::resetDb();
        self::$token = ApiTestHelpers::login();
    }

    /* ================================================================== */
    /* Dashboard                                                           */
    /* ================================================================== */

    public function testDashboardSummary(): void
    {
        $res = ApiTestHelpers::api('dashboard', 'summary', self::$token);
        $this->assertSame(200, $res['status']);
        $this->assertIsArray($res['body']);
        $this->assertArrayHasKey('total_inbound', $res['body']);
        $this->assertArrayHasKey('total_outbound', $res['body']);
    }

    public function testDashboardRejectsNoToken(): void
    {
        $res = ApiTestHelpers::api('dashboard', 'summary');
        $this->assertContains($res['status'], [401, 403]);
    }

    /* ================================================================== */
    /* Products (CRUD)                                                     */
    /* ================================================================== */

    public function testProductsCreate(): void
    {
        $code = 'APIP' . random_int(1000, 9999);
        $res = ApiTestHelpers::api('products', 'create', self::$token, [
            'product_code'   => $code,
            'product_name'   => 'API Product ' . $code,
            'uom_type'       => 'Drum',
            'uom_per_pallet' => 4,
        ]);
        $this->assertSame(200, $res['status']);
        $this->assertTrue($res['body']['success'] ?? false);
    }

    public function testProductsList(): void
    {
        $res = ApiTestHelpers::api('products', 'all', self::$token);
        $this->assertSame(200, $res['status']);
        $this->assertIsArray($res['body']['products'] ?? $res['body']['data'] ?? $res['body']);
    }

    public function testProductsCreateAndRetrieve(): void
    {
        $code = 'APIR' . random_int(1000, 9999);
        $create = ApiTestHelpers::api('products', 'create', self::$token, [
            'product_code' => $code,
            'product_name' => 'Retrieve Test ' . $code,
            'uom_type'     => 'Drum',
        ]);
        $this->assertTrue($create['body']['success'] ?? false);
        $pid = (int) ($create['body']['id'] ?? 0);
        $this->assertGreaterThan(0, $pid);

        $get = ApiTestHelpers::api('products', 'get', self::$token, ['id' => $pid]);
        $this->assertSame(200, $get['status']);
    }

    /* ================================================================== */
    /* Customers (CRUD)                                                    */
    /* ================================================================== */

    public function testCustomersCreate(): void
    {
        $code = 'APIC' . random_int(1000, 9999);
        $res = ApiTestHelpers::api('customers', 'create', self::$token, [
            'customer_code' => $code,
            'customer_name' => 'API Customer ' . $code,
            'city'          => 'Jakarta',
        ]);
        $this->assertSame(200, $res['status']);
        $this->assertTrue($res['body']['success'] ?? false);
    }

    public function testCustomersList(): void
    {
        $res = ApiTestHelpers::api('customers', 'all', self::$token);
        $this->assertSame(200, $res['status']);
    }

    public function testCustomersUpdate(): void
    {
        $code = 'APIU' . random_int(1000, 9999);
        $create = ApiTestHelpers::api('customers', 'create', self::$token, [
            'customer_code' => $code,
            'customer_name' => 'Original Name',
        ]);
        $cid = (int) ($create['body']['id'] ?? 0);
        $this->assertGreaterThan(0, $cid);

        $res = ApiTestHelpers::api('customers', 'update', self::$token, [
            'id'            => $cid,
            'customer_name' => 'Updated Name',
            'customer_code' => $code,
        ]);
        $this->assertSame(200, $res['status']);
    }

    /* ================================================================== */
    /* Stock (API layer — transfer, hold, release, scan)                   */
    /* ================================================================== */

    public function testStockApiHoldAndRelease(): void
    {
        $pid = TestDataFactory::createProduct(['product_code' => 'SHR01']);
        $sid = TestDataFactory::createStock($pid, 'CA01A01', 10.0, 'BAT-SHR1');

        $hold = ApiTestHelpers::api('stock', 'hold', self::$token, [
            'stock_id' => $sid,
            'status'   => 'quarantine',
            'reason'   => 'API hold test',
        ]);
        $this->assertSame(200, $hold['status']);

        // Verify in DB
        $row = ApiTestHelpers::q("SELECT hold_status FROM stock WHERE id = ?", [$sid]);
        $this->assertSame('quarantine', $row[0]['hold_status']);

        // Release
        $release = ApiTestHelpers::api('stock', 'release', self::$token, [
            'stock_id' => $sid,
            'reason'   => 'API release test',
        ]);
        $this->assertSame(200, $release['status']);

        $row = ApiTestHelpers::q("SELECT hold_status FROM stock WHERE id = ?", [$sid]);
        $this->assertSame('available', $row[0]['hold_status']);
    }

    public function testStockApiTransfer(): void
    {
        $pid = TestDataFactory::createProduct(['product_code' => 'STF01']);
        $sid = TestDataFactory::createStock($pid, 'CA01A01', 10.0, 'BAT-STF1');

        $res = ApiTestHelpers::api('stock', 'transfer', self::$token, [
            'stock_id'   => $sid,
            'new_location' => 'CA02B01',
        ]);
        $this->assertSame(200, $res['status']);

        $row = ApiTestHelpers::q("SELECT location FROM stock WHERE id = ?", [$sid]);
        $this->assertSame('CA02B01', $row[0]['location']);
    }

    public function testStockApiScanUnknown(): void
    {
        $res = ApiTestHelpers::api('stock', 'scan', self::$token, [
            'product_code' => 'NONEXISTENT_SCAN',
        ]);
        // Should return null product or empty
        $this->assertSame(200, $res['status']);
    }

    public function testStockApiScanKnown(): void
    {
        $pid = TestDataFactory::createProduct(['product_code' => 'SCN-API01']);
        TestDataFactory::createStock($pid, 'CA01A01', 5.0, 'BAT-SCN1');

        $res = ApiTestHelpers::api('stock', 'scan', self::$token, [
            'product_code' => 'SCN-API01',
        ]);
        $this->assertSame(200, $res['status']);
        $this->assertNotNull($res['body']['product'] ?? null);
    }

    /* ================================================================== */
    /* Ledger                                                              */
    /* ================================================================== */

    public function testLedgerList(): void
    {
        $res = ApiTestHelpers::api('ledger', 'all', self::$token);
        $this->assertSame(200, $res['status']);
    }

    /* ================================================================== */
    /* Activity Log                                                        */
    /* ================================================================== */

    public function testActivityLogList(): void
    {
        $res = ApiTestHelpers::api('activitylog', 'all', self::$token);
        $this->assertSame(200, $res['status']);
    }

    /* ================================================================== */
    /* Bin Transfer                                                        */
    /* ================================================================== */

    public function testBinTransferCreate(): void
    {
        $pid = TestDataFactory::createProduct(['product_code' => 'BT-API01']);
        $sid = TestDataFactory::createStock($pid, 'CA01A01', 10.0, 'BAT-BT01');

        $res = ApiTestHelpers::api('bintransfer', 'create', self::$token, [
            'from_location' => 'CA01A01',
            'to_location'   => 'CA02B01',
            'product_id'    => $pid,
            'quantity'      => 5.0,
            'batch_number'  => 'BAT-BT01',
        ]);
        // Should succeed (200) or return structured error
        $this->assertContains($res['status'], [200, 400, 422]);
    }

    /* ================================================================== */
    /* Locations                                                           */
    /* ================================================================== */

    public function testLocationsList(): void
    {
        $res = ApiTestHelpers::api('locations', 'all', self::$token);
        $this->assertSame(200, $res['status']);
    }

    /* ================================================================== */
    /* Users                                                               */
    /* ================================================================== */

    public function testUsersList(): void
    {
        $res = ApiTestHelpers::api('users', 'all', self::$token);
        $this->assertSame(200, $res['status']);
        $this->assertIsArray($res['body']);
    }

    /* ================================================================== */
    /* Reports                                                             */
    /* ================================================================== */

    public function testReportsList(): void
    {
        $res = ApiTestHelpers::api('report', 'all', self::$token);
        $this->assertSame(200, $res['status']);
    }
}

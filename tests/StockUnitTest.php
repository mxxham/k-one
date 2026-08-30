<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Stock.php — pure calculation methods + DB-dependent methods
 * using injected PDO from the test database.
 *
 * Covers: getExpiryInfo, availableHoldClause, transfer, adjust, hold, release,
 *         scan, scanOverride, getAll, getById, getSummary.
 */
class StockUnitTest extends TestCase
{
    private static PDO $pdo;

    public static function setUpBeforeClass(): void
    {
        self::$pdo = ApiTestHelpers::pdo();
    }

    protected function setUp(): void
    {
        // Reset transactional tables between tests
        ApiTestHelpers::truncateTransactional(self::$pdo);
    }

    /* ================================================================== */
    /* Pure methods (no DB)                                                */
    /* ================================================================== */

    /** getExpiryInfo: null input returns 'no expiry' shape. */
    public function testGetExpiryInfoNull(): void
    {
        $info = Stock::getExpiryInfo(null);
        $this->assertFalse($info['has_expiry']);
        $this->assertFalse($info['is_expired']);
        $this->assertSame('No expiry', $info['text']);
        $this->assertSame(0, $info['months']);
        $this->assertSame(0, $info['days']);
    }

    /** getExpiryInfo: empty string treated as null. */
    public function testGetExpiryInfoEmptyString(): void
    {
        $info = Stock::getExpiryInfo('');
        $this->assertFalse($info['has_expiry']);
    }

    /** getExpiryInfo: future date 6+ months out — not expired, not critical, not warning. */
    public function testGetExpiryInfoFutureDate(): void
    {
        $future = date('Y-m-d', strtotime('+2 years'));
        $info = Stock::getExpiryInfo($future);

        $this->assertTrue($info['has_expiry']);
        $this->assertFalse($info['is_expired']);
        $this->assertFalse($info['is_critical']);
        $this->assertFalse($info['is_warning']);
        $this->assertGreaterThan(120, $info['total_days']);
    }

    /** getExpiryInfo: date within 120 days → critical. */
    public function testGetExpiryInfoCritical(): void
    {
        $soon = date('Y-m-d', strtotime('+60 days'));
        $info = Stock::getExpiryInfo($soon);

        $this->assertTrue($info['has_expiry']);
        $this->assertFalse($info['is_expired']);
        $this->assertTrue($info['is_critical']);
        $this->assertStringContainsString('font-bold', $info['css_class']);
    }

    /** getExpiryInfo: date within 180 days but > 120 → warning. */
    public function testGetExpiryInfoWarning(): void
    {
        $future = date('Y-m-d', strtotime('+150 days'));
        $info = Stock::getExpiryInfo($future);

        $this->assertTrue($info['has_expiry']);
        $this->assertFalse($info['is_expired']);
        $this->assertTrue($info['is_warning']);
        $this->assertFalse($info['is_critical']);
    }

    /** getExpiryInfo: past date → expired. */
    public function testGetExpiryInfoExpired(): void
    {
        $past = date('Y-m-d', strtotime('-30 days'));
        $info = Stock::getExpiryInfo($past);

        $this->assertTrue($info['has_expiry']);
        $this->assertTrue($info['is_expired']);
        $this->assertFalse($info['is_critical']);
        $this->assertStringContainsString('Expired', $info['text']);
        $this->assertStringContainsString('ago', $info['text']);
    }

    /** getExpiryInfo: today is exactly the expiry date → not expired (0 days left). */
    public function testGetExpiryInfoToday(): void
    {
        $today = date('Y-m-d');
        $info = Stock::getExpiryInfo($today);

        $this->assertTrue($info['has_expiry']);
        $this->assertFalse($info['is_expired']);
        $this->assertSame(0, $info['months']);
        $this->assertSame(0, $info['days']);
        $this->assertSame(0, $info['total_days']);
    }

    /** availableHoldClause returns the expected SQL fragment. */
    public function testAvailableHoldClause(): void
    {
        $clause = Stock::availableHoldClause();
        $this->assertIsString($clause);
        $this->assertStringContainsString("hold_status = 'available'", $clause);
        $this->assertStringContainsString('IS NULL', $clause);
    }

    /* ================================================================== */
    /* DB-dependent methods                                                */
    /* ================================================================== */

    /** getAll returns empty array when no stock exists. */
    public function testGetAllEmpty(): void
    {
        $result = Stock::getAll(null, false, null, self::$pdo);
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    /** getById returns null for non-existent id. */
    public function testGetByIdNotFound(): void
    {
        $result = Stock::getById(99999, self::$pdo);
        $this->assertFalse($result);
    }

    /** getSummary returns zeroed summary when empty. */
    public function testGetSummaryEmpty(): void
    {
        $summary = Stock::getSummary(self::$pdo);
        $this->assertIsArray($summary);
        $this->assertSame(0, (int) $summary['total_products']);
        $this->assertSame(0.0, (float) $summary['total_drums']);
        $this->assertSame(0, (int) $summary['available_items']);
        $this->assertSame(0, (int) $summary['expired_items']);
    }

    /** getAll with stock returns rows with product join data. */
    public function testGetAllWithStock(): void
    {
        $pid = TestDataFactory::createProduct(['product_code' => 'GET01']);
        TestDataFactory::createStock($pid, 'CA01A01', 10.0, 'B001');

        $rows = Stock::getAll(null, false, null, self::$pdo);
        $this->assertNotEmpty($rows);
        $this->assertSame('GET01', $rows[0]['product_code']);
    }

    /** getAll with status filter. */
    public function testGetAllWithStatusFilter(): void
    {
        $pid = TestDataFactory::createProduct(['product_code' => 'STS01']);
        TestDataFactory::createStock($pid, 'CA01A01', 10.0, 'B002', '2030-12-31', 'Available');

        $rows = Stock::getAll('Available', false, null, self::$pdo);
        $this->assertNotEmpty($rows);

        $rows = Stock::getAll('Expired', false, null, self::$pdo);
        $this->assertEmpty($rows);
    }

    /** getById returns stock row when exists. */
    public function testGetByIdFound(): void
    {
        $pid = TestDataFactory::createProduct(['product_code' => 'GBY01']);
        $sid = TestDataFactory::createStock($pid, 'CA02B01', 20.0, 'B003');

        $row = Stock::getById($sid, self::$pdo);
        $this->assertIsArray($row);
        $this->assertSame($pid, (int) $row['product_id']);
        $this->assertSame('GBY01', $row['product_code']);
    }

    /** transfer: full quantity move updates location. */
    public function testTransferFull(): void
    {
        $pid = TestDataFactory::createProduct(['product_code' => 'TRF01']);
        $sid = TestDataFactory::createStock($pid, 'CA01A01', 10.0, 'B004');

        Stock::transfer($sid, 'CA02B01', null, self::$pdo);

        $row = Stock::getById($sid, self::$pdo);
        $this->assertSame('CA02B01', $row['location']);
    }

    /** transfer: partial quantity creates a new stock row at destination. */
    public function testTransferPartial(): void
    {
        $pid  = TestDataFactory::createProduct(['product_code' => 'TRF02']);
        $sid  = TestDataFactory::createStock($pid, 'CA01A01', 20.0, 'B005');

        Stock::transfer($sid, 'CA02B01', 8.0, self::$pdo);

        // Source should have reduced quantity
        $source = Stock::getById($sid, self::$pdo);
        $this->assertGreaterThan(0.0, (float) $source['quantity']);
        $this->assertLessThan(20.0, (float) $source['quantity']);

        // New row at destination
        $destRows = Stock::getByProduct($pid, self::$pdo);
        $atDest = array_filter($destRows, fn($r) => $r['location'] === 'CA02B01');
        $this->assertNotEmpty($atDest);
    }

    /** transfer: exceeding available stock throws exception. */
    public function testTransferExceedsQuantity(): void
    {
        $pid = TestDataFactory::createProduct(['product_code' => 'TRF03']);
        $sid = TestDataFactory::createStock($pid, 'CA01A01', 5.0, 'B006');

        $this->expectException(Exception::class);
        Stock::transfer($sid, 'CA02B01', 100.0, self::$pdo);
    }

    /** adjust: increase quantity writes ledger entry. */
    public function testAdjustIncrease(): void
    {
        $pid = TestDataFactory::createProduct(['product_code' => 'ADJ01']);
        $sid = TestDataFactory::createStock($pid, 'CA01A01', 10.0, 'B007');

        Stock::adjust($sid, 15.0, 'Cycle count correction', self::$pdo);

        $row = Stock::getById($sid, self::$pdo);
        $this->assertSame(15.0, (float) $row['quantity']);

        // Ledger should have an IN entry
        $ledger = ApiTestHelpers::q(
            "SELECT * FROM stock_ledger WHERE product_id = ? AND reference_type = 'Adjustment'",
            [$pid]
        );
        $this->assertNotEmpty($ledger);
        $this->assertSame('IN', $ledger[0]['transaction_type']);
    }

    /** adjust: decrease quantity. */
    public function testAdjustDecrease(): void
    {
        $pid = TestDataFactory::createProduct(['product_code' => 'ADJ02']);
        $sid = TestDataFactory::createStock($pid, 'CA01A01', 10.0, 'B008');

        Stock::adjust($sid, 3.0, 'Damaged units', self::$pdo);

        $row = Stock::getById($sid, self::$pdo);
        $this->assertSame(3.0, (float) $row['quantity']);
    }

    /** hold: set stock to quarantine with reason. */
    public function testHoldQuarantine(): void
    {
        $pid = TestDataFactory::createProduct(['product_code' => 'HLD01']);
        $sid = TestDataFactory::createStock($pid, 'CA01A01', 10.0, 'B009');

        Stock::hold($sid, 'quarantine', 'Suspected contamination', 1, self::$pdo);

        $row = Stock::getById($sid, self::$pdo);
        $this->assertSame('quarantine', $row['hold_status']);
        $this->assertSame('Suspected contamination', $row['hold_reason']);
    }

    /** hold: set stock to on_hold. */
    public function testHoldOnHold(): void
    {
        $pid = TestDataFactory::createProduct(['product_code' => 'HLD02']);
        $sid = TestDataFactory::createStock($pid, 'CA01A01', 10.0, 'B010');

        Stock::hold($sid, 'on_hold', 'Pending inspection', 1, self::$pdo);

        $row = Stock::getById($sid, self::$pdo);
        $this->assertSame('on_hold', $row['hold_status']);
    }

    /** hold: damaged status does not require a reason. */
    public function testHoldDamagedNoReason(): void
    {
        $pid = TestDataFactory::createProduct(['product_code' => 'HLD03']);
        $sid = TestDataFactory::createStock($pid, 'CA01A01', 10.0, 'B011');

        Stock::hold($sid, 'damaged', null, 1, self::$pdo);

        $row = Stock::getById($sid, self::$pdo);
        $this->assertSame('damaged', $row['hold_status']);
    }

    /** hold: invalid status throws exception. */
    public function testHoldInvalidStatus(): void
    {
        $pid = TestDataFactory::createProduct(['product_code' => 'HLD04']);
        $sid = TestDataFactory::createStock($pid, 'CA01A01', 10.0, 'B012');

        $this->expectException(Exception::class);
        Stock::hold($sid, 'invalid_status', 'test', 1, self::$pdo);
    }

    /** hold: on_hold without reason throws exception. */
    public function testHoldOnHoldNoReason(): void
    {
        $pid = TestDataFactory::createProduct(['product_code' => 'HLD05']);
        $sid = TestDataFactory::createStock($pid, 'CA01A01', 10.0, 'B013');

        $this->expectException(Exception::class);
        Stock::hold($sid, 'on_hold', '', 1, self::$pdo);
    }

    /** hold: already in same status throws exception. */
    public function testHoldAlreadyInStatus(): void
    {
        $pid = TestDataFactory::createProduct(['product_code' => 'HLD06']);
        $sid = TestDataFactory::createStock($pid, 'CA01A01', 10.0, 'B014');

        Stock::hold($sid, 'quarantine', 'First hold', 1, self::$pdo);

        $this->expectException(Exception::class);
        Stock::hold($sid, 'quarantine', 'Second hold', 1, self::$pdo);
    }

    /** release: reset held stock back to available. */
    public function testRelease(): void
    {
        $pid = TestDataFactory::createProduct(['product_code' => 'REL01']);
        $sid = TestDataFactory::createStock($pid, 'CA01A01', 10.0, 'B015');

        Stock::hold($sid, 'quarantine', 'test', 1, self::$pdo);
        Stock::release($sid, 'Issue resolved', 1, self::$pdo);

        $row = Stock::getById($sid, self::$pdo);
        $this->assertSame('available', $row['hold_status']);
        $this->assertNull($row['hold_reason']);
    }

    /** release: already available throws exception. */
    public function testReleaseAlreadyAvailable(): void
    {
        $pid = TestDataFactory::createProduct(['product_code' => 'REL02']);
        $sid = TestDataFactory::createStock($pid, 'CA01A01', 10.0, 'B016');

        $this->expectException(Exception::class);
        Stock::release($sid, null, 1, self::$pdo);
    }

    /** hold + release writes ledger entries. */
    public function testHoldReleaseLedgerEntries(): void
    {
        $pid = TestDataFactory::createProduct(['product_code' => 'REL03']);
        $sid = TestDataFactory::createStock($pid, 'CA01A01', 10.0, 'B017');

        Stock::hold($sid, 'on_hold', 'Audit hold', 1, self::$pdo);
        Stock::release($sid, 'Audit cleared', 1, self::$pdo);

        $ledger = ApiTestHelpers::q(
            "SELECT * FROM stock_ledger WHERE product_id = ? AND transaction_type IN ('HOLD','RELEASE') ORDER BY id",
            [$pid]
        );
        $this->assertCount(2, $ledger);
        $this->assertSame('HOLD', $ledger[0]['transaction_type']);
        $this->assertSame('RELEASE', $ledger[1]['transaction_type']);
    }

    /** scan: unknown product code returns null. */
    public function testScanUnknownProduct(): void
    {
        $result = Stock::scan('UNKNOWN_CODE_XYZ', self::$pdo);
        $this->assertNull($result);
    }

    /** scan: known product with no stock returns empty locations. */
    public function testScanProductNoStock(): void
    {
        $pid = TestDataFactory::createProduct(['product_code' => 'SCN01']);

        $result = Stock::scan('SCN01', self::$pdo);
        $this->assertIsArray($result);
        $this->assertSame('SCN01', $result['product']['product_code']);
        $this->assertEmpty($result['locations']);
        $this->assertNull($result['expected_location']);
    }

    /** scan: product with stock returns locations ordered by FEFO (earliest expiry first). */
    public function testScanProductWithStock(): void
    {
        $pid = TestDataFactory::createProduct(['product_code' => 'SCN02']);
        TestDataFactory::createStock($pid, 'CA02B01', 5.0, 'BATCH-EARLY', '2025-06-01');
        TestDataFactory::createStock($pid, 'CA01A01', 5.0, 'BATCH-LATE', '2028-12-31');

        $result = Stock::scan('SCN02', self::$pdo);
        $this->assertNotNull($result);
        $this->assertCount(2, $result['locations']);
        // Earliest expiry should be first (FEFO)
        $this->assertSame('BATCH-EARLY', $result['locations'][0]['batch_number']);
        $this->assertSame('CA02B01', $result['expected_location']);
    }

    /** scan: held stock excluded from locations. */
    public function testScanExcludesHeldStock(): void
    {
        $pid = TestDataFactory::createProduct(['product_code' => 'SCN03']);
        $sid = TestDataFactory::createStock($pid, 'CA01A01', 5.0, 'BATCH-HELD');
        Stock::hold($sid, 'quarantine', 'test', 1, self::$pdo);

        $result = Stock::scan('SCN03', self::$pdo);
        $this->assertNotNull($result);
        $this->assertEmpty($result['locations']);
    }

    /** scan: stock at QUA_SHELL excluded from scan results. */
    public function testScanExcludesQuaShell(): void
    {
        $pid = TestDataFactory::createProduct(['product_code' => 'SCN04']);
        TestDataFactory::createStock($pid, 'QUA_SHELL', 5.0, 'BATCH-QUA');

        $result = Stock::scan('SCN04', self::$pdo);
        $this->assertNotNull($result);
        $this->assertEmpty($result['locations']);
    }

    /** scanOverride: logs an activity entry. */
    public function testScanOverrideLogsActivity(): void
    {
        $pid = TestDataFactory::createProduct(['product_code' => 'SOV01']);
        // Set session vars needed by scanOverride
        $_SESSION['user_id']    = 1;
        $_SESSION['username']   = 'testadmin';
        $_SESSION['full_name']  = 'Test Admin';

        Stock::scanOverride($pid, 'SCANNED_LOC', 'EXPECTED_LOC', 'Wrong location scanned', 1, self::$pdo);

        $log = ApiTestHelpers::q(
            "SELECT * FROM activity_log WHERE module = 'stock' AND action = 'SCAN_OVERRIDE'"
        );
        $this->assertNotEmpty($log);
        $this->assertStringContainsString('SCANNED_LOC', $log[0]['new_value']);
        $this->assertStringContainsString('EXPECTED_LOC', $log[0]['old_value']);
    }

    /** getExpiryInfo: correctly counts months and days. */
    public function testGetExpiryInfoMonthsAndDays(): void
    {
        $date = date('Y-m-d', strtotime('+3 months +15 days'));
        $info = Stock::getExpiryInfo($date);

        $this->assertTrue($info['has_expiry']);
        $this->assertGreaterThanOrEqual(3, $info['months']);
    }

    /** hold: sets hold_by and hold_at. */
    public function testHoldSetsMetadata(): void
    {
        $pid = TestDataFactory::createProduct(['product_code' => 'HLD07']);
        $sid = TestDataFactory::createStock($pid, 'CA01A01', 10.0, 'B018');

        Stock::hold($sid, 'quarantine', 'Metadata test', 1, self::$pdo);

        $row = Stock::getById($sid, self::$pdo);
        $this->assertSame('1', (string) $row['hold_by']);
        $this->assertNotNull($row['hold_at']);
    }

    /** getAll: year filter. */
    public function testGetAllWithYearFilter(): void
    {
        $pid = TestDataFactory::createProduct(['product_code' => 'YRF01']);
        TestDataFactory::createStock($pid, 'CA01A01', 10.0, 'B019', date('Y') . '-06-15');

        $thisYear = (int) date('Y');
        $rows = Stock::getAll(null, false, $thisYear, self::$pdo);
        $this->assertNotEmpty($rows);

        $pastRows = Stock::getAll(null, false, $thisYear - 10, self::$pdo);
        $this->assertEmpty($pastRows);
    }
}

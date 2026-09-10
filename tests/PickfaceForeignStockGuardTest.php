<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests for pickface foreign-stock guard in api/handlers/pickface.php.
 *
 * When assigning a bin as a SKU's pickface, the handler must refuse if
 * the bin currently holds stock for a DIFFERENT SKU — unless
 * force_override: true is sent.
 */
final class PickfaceForeignStockGuardTest extends TestCase
{
    private static PDO $pdo;

    public static function setUpBeforeClass(): void
    {
        self::$pdo = ApiTestHelpers::pdo();
    }

    protected function setUp(): void
    {
        self::$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        self::$pdo->exec('TRUNCATE TABLE `sku_pickface_config`');
        self::$pdo->exec('TRUNCATE TABLE `stock`');
        self::$pdo->exec('TRUNCATE TABLE `replen_task`');
        self::$pdo->exec('TRUNCATE TABLE `products`');
        self::$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

        // Seed test products the handler needs (must exist for FK + validation)
        self::$pdo->exec("INSERT INTO products (id, product_code, product_name, uom_type, uom_per_pallet, is_active)
                          VALUES (100, 'TEST_SKU_A', 'Test Product A', 'Carton', 10, 1),
                                 (200, 'TEST_SKU_B', 'Test Product B', 'Carton', 10, 1),
                                 (300, 'TEST_SKU_C', 'Test Product C', 'Carton', 10, 1)");
    }

    /**
     * Insert a pickface bin that doesn't exist in the controlled set.
     * Uses CC aisle (not seeded by controlledBinsSql) so INSERT IGNORE always succeeds.
     * Returns the auto-generated bin ID.
     */
    private function createBin(string $code): int
    {
        self::$pdo->prepare(
            "INSERT IGNORE INTO location_master (location_code, row_name, aisle, zone, is_active, is_pick_face)
             VALUES (?, 'A', 1, 'CC', 1, 1)"
        )->execute([$code]);

        $row = self::$pdo->prepare("SELECT id FROM location_master WHERE location_code = ?");
        $row->execute([$code]);
        return (int)$row->fetch()['id'];
    }

    /** Helper: insert stock for a product at a location. */
    private function insertStock(int $productId, string $locationCode, float $qty): void
    {
        self::$pdo->prepare(
            "INSERT INTO stock (product_id, location, quantity, stock_status, batch_number)
             VALUES (?, ?, ?, 'Available', 'TEST')"
        )->execute([$productId, $locationCode, $qty]);
    }

    /** Helper: get the admin auth token. */
    private function token(): string
    {
        return ApiTestHelpers::login();
    }

    /* ================================================================== */
    /* CREATE — foreign stock blocks assignment without override            */
    /* ================================================================== */

    public function testCreateRefusesBinWithForeignStock(): void
    {
        $binId = $this->createBin('CC01A01');
        $this->insertStock(200, 'CC01A01', 10.0);

        $res = ApiTestHelpers::api('pickface', 'create', $this->token(), [
            'sku_id'          => 100,
            'pickface_bin_id' => $binId,
            'pickface_min'    => 1,
            'pickface_max'    => 10,
        ]);

        $this->assertSame(409, $res['status'], 'Expected 409 when bin holds foreign stock');
        $this->assertStringContainsString('SKU #200', $res['body']['message'] ?? '');
        $this->assertStringContainsString('force_override', $res['body']['message'] ?? '');
    }

    public function testCreateSucceedsWithForceOverride(): void
    {
        $binId = $this->createBin('CC01A02');
        $this->insertStock(200, 'CC01A02', 5.0);

        $res = ApiTestHelpers::api('pickface', 'create', $this->token(), [
            'sku_id'          => 100,
            'pickface_bin_id' => $binId,
            'pickface_min'    => 1,
            'pickface_max'    => 10,
            'force_override'  => true,
        ]);

        $this->assertSame(200, $res['status'], 'Expected 200 with force_override');
        $this->assertTrue($res['body']['success'] ?? false);
    }

    public function testCreateSucceedsOnEmptyBin(): void
    {
        $binId = $this->createBin('CC01A03');

        $res = ApiTestHelpers::api('pickface', 'create', $this->token(), [
            'sku_id'          => 100,
            'pickface_bin_id' => $binId,
            'pickface_min'    => 1,
            'pickface_max'    => 10,
        ]);

        $this->assertSame(200, $res['status'], 'Expected 200 on empty bin');
        $this->assertTrue($res['body']['success'] ?? false);
    }

    /* ================================================================== */
    /* UPDATE — foreign stock blocks reassignment without override          */
    /* ================================================================== */

    public function testUpdateRefusesBinWithForeignStock(): void
    {
        $initialBin = $this->createBin('CC02A01');
        $createRes = ApiTestHelpers::api('pickface', 'create', $this->token(), [
            'sku_id'          => 100,
            'pickface_bin_id' => $initialBin,
            'pickface_min'    => 1,
            'pickface_max'    => 10,
        ]);
        $this->assertSame(200, $createRes['status'], 'Setup: create initial config');
        $configId = $createRes['body']['id'];

        $targetBin = $this->createBin('CC02A02');
        $this->insertStock(300, 'CC02A02', 20.0);

        $res = ApiTestHelpers::api('pickface', 'update', $this->token(), [
            'id'              => $configId,
            'pickface_bin_id' => $targetBin,
            'pickface_min'    => 1,
            'pickface_max'    => 10,
        ]);

        $this->assertSame(409, $res['status'], 'Expected 409 when reassigning to foreign-stock bin');
        $this->assertStringContainsString('SKU #300', $res['body']['message'] ?? '');
    }

    public function testUpdateSucceedsWithForceOverride(): void
    {
        $initialBin = $this->createBin('CC02A03');
        $createRes = ApiTestHelpers::api('pickface', 'create', $this->token(), [
            'sku_id'          => 100,
            'pickface_bin_id' => $initialBin,
            'pickface_min'    => 1,
            'pickface_max'    => 10,
        ]);
        $this->assertSame(200, $createRes['status'], 'Setup: create initial config');
        $configId = $createRes['body']['id'];

        $targetBin = $this->createBin('CC02A04');
        $this->insertStock(300, 'CC02A04', 15.0);

        $res = ApiTestHelpers::api('pickface', 'update', $this->token(), [
            'id'              => $configId,
            'pickface_bin_id' => $targetBin,
            'pickface_min'    => 1,
            'pickface_max'    => 10,
            'force_override'  => true,
        ]);

        $this->assertSame(200, $res['status'], 'Expected 200 with force_override');
        $this->assertTrue($res['body']['success'] ?? false);
    }

    public function testUpdateSucceedsOnEmptyBin(): void
    {
        $initialBin = $this->createBin('CC02A05');
        $createRes = ApiTestHelpers::api('pickface', 'create', $this->token(), [
            'sku_id'          => 100,
            'pickface_bin_id' => $initialBin,
            'pickface_min'    => 1,
            'pickface_max'    => 10,
        ]);
        $this->assertSame(200, $createRes['status'], 'Setup: create initial config');
        $configId = $createRes['body']['id'];

        $targetBin = $this->createBin('CC02A06');
        $res = ApiTestHelpers::api('pickface', 'update', $this->token(), [
            'id'              => $configId,
            'pickface_bin_id' => $targetBin,
            'pickface_min'    => 1,
            'pickface_max'    => 10,
        ]);

        $this->assertSame(200, $res['status'], 'Expected 200 on empty bin');
        $this->assertTrue($res['body']['success'] ?? false);
    }
}

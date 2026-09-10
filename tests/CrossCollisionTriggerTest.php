<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Verifies the DB-level cross-column collision triggers fire correctly.
 * These triggers (hotfix_027/hotfix_033) are the authoritative guard —
 * the application-level check in pickface.php is a friendlier fail-fast layer.
 */
final class CrossCollisionTriggerTest extends TestCase
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
        self::$pdo->exec('TRUNCATE TABLE `products`');
        self::$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

        self::$pdo->exec("INSERT INTO products (id, product_code, product_name, uom_type, uom_per_pallet, is_active)
                          VALUES (100, 'TRG_SKU_A', 'Trigger Test A', 'Carton', 10, 1),
                                 (200, 'TRG_SKU_B', 'Trigger Test B', 'Carton', 10, 1)");
    }

    /** Ensure a bin exists in location_master (CC aisle, unique codes). */
    private function ensureBin(int $id, string $code): void
    {
        self::$pdo->prepare(
            "INSERT IGNORE INTO location_master (id, location_code, row_name, aisle, zone, is_active, is_pick_face)
             VALUES (?, ?, 'A', 1, 'CC', 1, 1)"
        )->execute([$id, $code]);
    }

    public function testInsertRejectsOutboundBinAsAnotherSkuInbound(): void
    {
        $this->ensureBin(9100, 'TC01A01');
        self::$pdo->exec("INSERT INTO sku_pickface_config (sku_id, inbound_pickface_bin_id, pickface_min, pickface_max)
                          VALUES (200, 9100, 1, 10)");

        $this->ensureBin(9101, 'TC01A02');
        $this->expectException(PDOException::class);
        $this->expectExceptionMessage('Cross-column collision: outbound bin is another SKU inbound bin');

        self::$pdo->exec("INSERT INTO sku_pickface_config (sku_id, pickface_bin_id, pickface_min, pickface_max)
                          VALUES (100, 9100, 1, 10)");
    }

    public function testInsertRejectsInboundBinAsAnotherSkuOutbound(): void
    {
        $this->ensureBin(9200, 'TC02A01');
        self::$pdo->exec("INSERT INTO sku_pickface_config (sku_id, pickface_bin_id, pickface_min, pickface_max)
                          VALUES (200, 9200, 1, 10)");

        $this->ensureBin(9201, 'TC02A02');
        $this->expectException(PDOException::class);
        $this->expectExceptionMessage('Cross-column collision: inbound bin is another SKU outbound bin');

        self::$pdo->exec("INSERT INTO sku_pickface_config (sku_id, inbound_pickface_bin_id, pickface_min, pickface_max)
                          VALUES (100, 9200, 1, 10)");
    }

    public function testUpdateRejectsOutboundBinAsAnotherSkuInbound(): void
    {
        $this->ensureBin(9300, 'TC03A01');
        $this->ensureBin(9301, 'TC03A02');
        $this->ensureBin(9302, 'TC03A03');

        self::$pdo->exec("INSERT INTO sku_pickface_config (sku_id, inbound_pickface_bin_id, pickface_min, pickface_max)
                          VALUES (200, 9300, 1, 10)");

        self::$pdo->exec("INSERT INTO sku_pickface_config (sku_id, pickface_bin_id, pickface_min, pickface_max)
                          VALUES (100, 9301, 1, 10)");
        $configId = (int)self::$pdo->lastInsertId();

        $this->expectException(PDOException::class);
        $this->expectExceptionMessage('Cross-column collision: outbound bin is another SKU inbound bin');

        self::$pdo->exec("UPDATE sku_pickface_config SET pickface_bin_id = 9300 WHERE id = {$configId}");
    }

    public function testInsertAllowsSameSkuBothColumns(): void
    {
        $this->ensureBin(9500, 'TC05A01');
        self::$pdo->exec("INSERT INTO sku_pickface_config (sku_id, pickface_bin_id, inbound_pickface_bin_id, pickface_min, pickface_max)
                          VALUES (100, 9500, 9500, 1, 10)");

        $row = self::$pdo->query("SELECT id FROM sku_pickface_config WHERE sku_id = 100 AND pickface_bin_id = 9500")->fetch();
        $this->assertNotEmpty($row, 'Same-SKU dual-column assignment should succeed');
    }

    public function testInsertAllowsUnrelatedBins(): void
    {
        $this->ensureBin(9600, 'TC06A01');
        $this->ensureBin(9700, 'TC06A02');
        $this->ensureBin(9800, 'TC06A03');
        $this->ensureBin(9900, 'TC06A04');

        self::$pdo->exec("INSERT INTO sku_pickface_config (sku_id, pickface_bin_id, inbound_pickface_bin_id, pickface_min, pickface_max)
                          VALUES (100, 9600, 9700, 1, 10)");
        self::$pdo->exec("INSERT INTO sku_pickface_config (sku_id, pickface_bin_id, inbound_pickface_bin_id, pickface_min, pickface_max)
                          VALUES (200, 9800, 9900, 1, 10)");

        $cnt = self::$pdo->query("SELECT COUNT(*) AS c FROM sku_pickface_config")->fetch()['c'];
        $this->assertSame(2, (int)$cnt, 'Non-overlapping configs should both exist');
    }
}

<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests for inbound partial pallet routing to dedicated inbound pickface bin (A02).
 */
final class InboundPickfaceRoutingTest extends TestCase
{
    private static \PDO $db;

    public static function setUpBeforeClass(): void
    {
        self::$db = \ApiTestHelpers::pdo();
        $db = self::$db;
        \ApiTestHelpers::truncateTransactional($db);
    }

    /** Controlled bins use bays 01-03; use 07+ to avoid collisions */
    private function seedBins(array $codes): array
    {
        $db = self::$db;
        $ids = [];
        foreach ($codes as $code) {
            $db->exec("INSERT INTO location_master (location_code, is_active) VALUES ('$code', 1)");
            $ids[$code] = (int)$db->lastInsertId();
        }
        return $ids;
    }

    private function seedProduct(string $code, int $upp = 100): int
    {
        $db = self::$db;
        $db->exec("INSERT INTO products (product_code, product_name, uom_per_pallet, uom_type)
                    VALUES ('$code', 'Test $code', $upp, 'Carton')");
        return (int)$db->lastInsertId();
    }

    /**
     * Test 1: SKU with inbound_pickface_bin_id → getPickfaceConfig returns both bins
     */
    public function testConfigReturnsBothBins(): void
    {
        $db = self::$db;
        $bins = $this->seedBins(['CB07A01', 'CB07A02']);
        $outboundBinId = $bins['CB07A01'];
        $inboundBinId = $bins['CB07A02'];

        $productId = $this->seedProduct('SKU-TEST-001', 100);
        $db->exec("INSERT INTO sku_pickface_config
                    (sku_id, pickface_bin_id, inbound_pickface_bin_id, pickface_max, pickface_min)
                    VALUES ($productId, $outboundBinId, $inboundBinId, 100, 10)");

        $pfConfig = \PickfaceSplitter::getPickfaceConfig($productId, $db);

        $this->assertNotNull($pfConfig);
        $this->assertEquals($outboundBinId, $pfConfig['pickface_bin_id']);
        $this->assertEquals('CB07A01', $pfConfig['pickface_location_code']);
        $this->assertEquals($inboundBinId, $pfConfig['inbound_pickface_bin_id']);
        $this->assertEquals('CB07A02', $pfConfig['inbound_pickface_location_code']);
    }

    /**
     * Test 2: SKU with no inbound bin → inbound fields are null
     */
    public function testConfigWithoutInboundReturnsNulls(): void
    {
        $db = self::$db;
        $bins = $this->seedBins(['CB08A01']);
        $outboundBinId = $bins['CB08A01'];

        $productId = $this->seedProduct('SKU-TEST-002', 100);
        $db->exec("INSERT INTO sku_pickface_config
                    (sku_id, pickface_bin_id, pickface_max, pickface_min)
                    VALUES ($productId, $outboundBinId, 100, 10)");

        $pfConfig = \PickfaceSplitter::getPickfaceConfig($productId, $db);

        $this->assertNotNull($pfConfig);
        $this->assertEquals($outboundBinId, $pfConfig['pickface_bin_id']);
        $this->assertNull($pfConfig['inbound_pickface_bin_id']);
        $this->assertNull($pfConfig['inbound_pickface_location_code']);
    }

    /**
     * Test 3: Outbound pickface bin never equals inbound pickface bin
     */
    public function testOutboundNeverEqualsInbound(): void
    {
        $db = self::$db;

        // With inbound set
        $bins1 = $this->seedBins(['CB09A01', 'CB09A02']);
        $pid1 = $this->seedProduct('SKU-TEST-003a', 100);
        $db->exec("INSERT INTO sku_pickface_config
                    (sku_id, pickface_bin_id, inbound_pickface_bin_id, pickface_max, pickface_min)
                    VALUES ($pid1, {$bins1['CB09A01']}, {$bins1['CB09A02']}, 100, 10)");

        $cfg1 = \PickfaceSplitter::getPickfaceConfig($pid1, $db);
        $this->assertNotEquals($cfg1['pickface_bin_id'], $cfg1['inbound_pickface_bin_id']);
        $this->assertNotEquals($cfg1['pickface_location_code'], $cfg1['inbound_pickface_location_code']);

        // Without inbound set
        $bins2 = $this->seedBins(['CB10A01']);
        $pid2 = $this->seedProduct('SKU-TEST-003b', 100);
        $db->exec("INSERT INTO sku_pickface_config
                    (sku_id, pickface_bin_id, pickface_max, pickface_min)
                    VALUES ($pid2, {$bins2['CB10A01']}, 100, 10)");

        $cfg2 = \PickfaceSplitter::getPickfaceConfig($pid2, $db);
        $this->assertNull($cfg2['inbound_pickface_bin_id']);
        $this->assertNotEquals($cfg2['pickface_location_code'], null);
    }

    /**
     * Test 4: Auto-detect returns A01-style location for outbound
     */
    public function testAutoDetectReturnsA01ForOutbound(): void
    {
        $db = self::$db;
        $productId = $this->seedProduct('SKU-AUTO-001', 100);

        $pfConfig = \PickfaceSplitter::getPickfaceConfig($productId, $db);

        // Auto-detect should find an A-level bin
        $this->assertNotNull($pfConfig);
        $this->assertNotEmpty($pfConfig['pickface_location_code']);
        $this->assertMatchesRegularExpression('/A\d{2}$/', $pfConfig['pickface_location_code']);
    }

    /**
     * Test 5: Auto-detect skips bins already claimed by other SKUs
     */
    public function testAutoDetectSkipsClaimedBins(): void
    {
        $db = self::$db;
        $bins = $this->seedBins(['CB11A01', 'CB11A02']);
        $outboundBinId = $bins['CB11A01'];

        // Another product already claims CB11A01
        $otherProduct = $this->seedProduct('SKU-OTHER', 100);
        $db->exec("INSERT INTO sku_pickface_config
                    (sku_id, pickface_bin_id, pickface_max, pickface_min)
                    VALUES ($otherProduct, $outboundBinId, 100, 10)");

        $productId = $this->seedProduct('SKU-AUTO-002', 100);
        $pfConfig = \PickfaceSplitter::getPickfaceConfig($productId, $db);

        if ($pfConfig && $pfConfig['pickface_location_code']) {
            $this->assertNotEquals('CB11A01', $pfConfig['pickface_location_code'],
                'Auto-detect must not claim bin already used by another SKU');
        }
    }
}

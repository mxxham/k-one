<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Port of v2 apps/api/test/replenishment.e2e-spec.ts.
 * Tests: for_demand (shortage + enough), save_target + targets + delete_target.
 */
final class ReplenishmentTest extends TestCase
{
    private static string $token;
    private static int $productId;

    public static function setUpBeforeClass(): void
    {
        self::$token = ApiTestHelpers::login();
        self::$productId = ApiTestHelpers::createProduct(['uom_type' => 'Drum']);
        // Reserve stock in CA (reserve zone, upper levels) — same as v2 spec
        ApiTestHelpers::putStock(self::$productId, 'CA02E01', 20, 'RES-BATCH', '2031-01-01');
    }

    /** Scenario 1: demand exceeds pick-face stock → shortage > 0 */
    public function testForDemandReportsShortage(): void
    {
        $res = ApiTestHelpers::api('replenishment', 'for_demand', self::$token, [
            'product_id' => self::$productId,
            'quantity' => 25,
            'create_transfer' => false,
        ]);
        $this->assertTrue($res['body']['success']);
        $this->assertGreaterThan(0, $res['body']['shortage']);
    }

    /** Scenario 2: demand fits but pick_available is 0 → full demand is shortage */
    public function testForDemandReportsEnoughReserve(): void
    {
        $res = ApiTestHelpers::api('replenishment', 'for_demand', self::$token, [
            'product_id' => self::$productId,
            'quantity' => 5,
            'create_transfer' => false,
        ]);
        $this->assertTrue($res['body']['success']);
        $this->assertEquals(5, $res['body']['shortage']);
        $this->assertEquals(0, $res['body']['pick_available']);
        $this->assertTrue($res['body']['can_fulfill']);
        $this->assertGreaterThanOrEqual(5, $res['body']['available']);
    }

    /** Scenario 3: save_target + targets + delete_target lifecycle */
    public function testTargetLifecycle(): void
    {
        // Get a valid location_id
        $rows = ApiTestHelpers::q("SELECT id FROM location_master WHERE location_code = 'CA02E01'");
        $this->assertNotEmpty($rows);
        $locationId = (int)$rows[0]['id'];

        // Save target
        $save = ApiTestHelpers::api('replenishment', 'save_target', self::$token, [
            'location_id' => $locationId,
            'product_id' => self::$productId,
            'min_qty' => 4,
            'max_qty' => 8,
        ]);
        $this->assertTrue($save['body']['success']);
        $tid = (int)$save['body']['id'];
        $this->assertGreaterThan(0, $tid);

        // List targets — our target should appear
        $list = ApiTestHelpers::api('replenishment', 'targets', self::$token);
        $this->assertTrue($list['body']['success']);
        $found = false;
        foreach ($list['body']['targets'] ?? [] as $t) {
            if ((int)$t['id'] === $tid) { $found = true; break; }
        }
        $this->assertTrue($found, 'Newly created target should appear in targets list');

        // Delete target
        $del = ApiTestHelpers::api('replenishment', 'delete_target', self::$token, ['id' => $tid]);
        $this->assertTrue($del['body']['success']);
    }
}

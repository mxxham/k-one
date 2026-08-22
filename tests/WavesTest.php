<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Waves module tests — no dedicated v2 spec, built from the verified curl flow.
 * Tests: list shape, candidate_orders, create + detail + cancel lifecycle.
 */
final class WavesTest extends TestCase
{
    private static string $token;

    public static function setUpBeforeClass(): void
    {
        self::$token = ApiTestHelpers::login();
        // Seed a customer for outbound order creation
        ApiTestHelpers::q(
            "INSERT IGNORE INTO customers (id, customer_name, customer_code, address, city) VALUES (1, 'Test Customer', 'CUST-TEST', 'Test Address', 'SURABAYA')"
        );
    }

    /** list returns the correct v2 shape */
    public function testListShape(): void
    {
        $res = ApiTestHelpers::api('waves', 'list', self::$token);
        $this->assertTrue($res['body']['success']);
        $this->assertArrayHasKey('rows', $res['body']);
        $this->assertArrayHasKey('total', $res['body']);
        $this->assertArrayHasKey('page', $res['body']);
        $this->assertArrayHasKey('per_page', $res['body']);
        $this->assertArrayHasKey('statuses', $res['body']);
        $this->assertEquals(['Planning', 'Active', 'Completed', 'Cancelled'], $res['body']['statuses']);
    }

    /** candidate_orders returns eligible Open orders without picklists */
    public function testCandidateOrders(): void
    {
        $res = ApiTestHelpers::api('waves', 'candidate_orders', self::$token);
        $this->assertTrue($res['body']['success']);
        $this->assertArrayHasKey('orders', $res['body']);
        $this->assertIsArray($res['body']['orders']);
    }

    /** full lifecycle: create → detail → cancel */
    public function testCreateDetailCancelLifecycle(): void
    {
        // Create 2 outbound orders with stock for the wave
        $prodId = ApiTestHelpers::createProduct(['uom_type' => 'Drum']);
        ApiTestHelpers::putStock($prodId, 'CA01A01', 50, 'WV-BATCH', '2030-12-31');

        // Create two outbound orders
        $ob1 = ApiTestHelpers::api('outbound', 'create', self::$token, [
            'order_date' => date('Y-m-d'),
            'customer_id' => 1,
        ]);
        $this->assertTrue($ob1['body']['success']);
        $obId1 = (int)$ob1['body']['id'];

        $ob2 = ApiTestHelpers::api('outbound', 'create', self::$token, [
            'order_date' => date('Y-m-d'),
            'customer_id' => 1,
        ]);
        $this->assertTrue($ob2['body']['success']);
        $obId2 = (int)$ob2['body']['id'];

        // Add items to both orders
        ApiTestHelpers::api('outbound', 'add_item', self::$token, [
            'outbound_id' => $obId1,
            'product_id' => $prodId,
            'quantity' => 10,
        ]);
        ApiTestHelpers::api('outbound', 'add_item', self::$token, [
            'outbound_id' => $obId2,
            'product_id' => $prodId,
            'quantity' => 5,
        ]);

        // Verify candidate_orders includes our orders
        $cand = ApiTestHelpers::api('waves', 'candidate_orders', self::$token);
        $this->assertTrue($cand['body']['success']);
        $candIds = array_column($cand['body']['orders'], 'id');
        $this->assertContains($obId1, $candIds);
        $this->assertContains($obId2, $candIds);

        // Create wave with both orders
        $create = ApiTestHelpers::api('waves', 'create', self::$token, [
            'order_ids' => [$obId1, $obId2],
        ]);
        $this->assertTrue($create['body']['success']);
        $this->assertArrayHasKey('wave_id', $create['body']);
        $this->assertArrayHasKey('picklist_id', $create['body']);
        $this->assertArrayHasKey('skipped', $create['body']);
        $waveId = (int)$create['body']['wave_id'];
        $picklistId = (int)$create['body']['picklist_id'];
        $this->assertGreaterThan(0, $waveId);
        $this->assertGreaterThan(0, $picklistId);

        // Detail: wave with nested orders + picklist
        $detail = ApiTestHelpers::api('waves', 'detail', self::$token, [], ['id' => $waveId]);
        $this->assertTrue($detail['body']['success']);
        $this->assertArrayHasKey('wave', $detail['body']);
        $wave = $detail['body']['wave'];
        $this->assertEquals($waveId, (int)$wave['id']);
        $this->assertEquals('Planning', $wave['status']);
        $this->assertIsArray($wave['orders']);
        $this->assertCount(2, $wave['orders']);
        $this->assertNotNull($wave['picklist']);
        $this->assertEquals($picklistId, (int)$wave['picklist']['id']);

        // Cancel
        $cancel = ApiTestHelpers::api('waves', 'cancel', self::$token, ['id' => $waveId]);
        $this->assertTrue($cancel['body']['success']);
        $this->assertEquals($waveId, (int)$cancel['body']['id']);

        // Verify cancelled in list
        $list = ApiTestHelpers::api('waves', 'list', self::$token);
        $found = null;
        foreach ($list['body']['rows'] as $r) {
            if ((int)$r['id'] === $waveId) { $found = $r; break; }
        }
        $this->assertNotNull($found);
        $this->assertEquals('Cancelled', $found['status']);
    }
}

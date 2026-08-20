<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Port of v2 apps/api/test/stocktake.e2e-spec.ts — Stocktake create + count/review/adjust flow.
 */
final class StockTakeTest extends TestCase
{
    private static string $token = '';
    private static int $productId = 0;

    public static function setUpBeforeClass(): void
    {
        ApiTestHelpers::resetDb();
        self::$token = ApiTestHelpers::login();
        self::$productId = ApiTestHelpers::createProduct(['uom_type' => 'Drum']);
        ApiTestHelpers::putStock(self::$productId, 'CA01B01', 10, 'STK-BATCH', '2030-01-01');
    }

    public function testCreatesAStockTakeWithAutoLoadedScopeLocations(): void
    {
        $res = ApiTestHelpers::api('stocktake', 'create', self::$token, [
            'take_name'        => 'STK-' . random_int(0, 99999),
            'take_date'        => '2026-08-15',
            'scope_locations'  => ['CA01B01'],
        ]);
        $this->assertTrue($res['body']['success']);
        $id = (int) $res['body']['id'];
        $this->assertGreaterThan(0, $id);

        $items = ApiTestHelpers::q('SELECT stock_take_id, location FROM stock_take_items WHERE stock_take_id = ?', [$id]);
        $this->assertTrue(
            in_array('CA01B01', array_column($items, 'location'), true),
            'expected stock_take_items to contain location CA01B01, got: ' . json_encode($items)
        );
    }

    public function testCountsADifferenceReviewsItAndAppliesAnAdjustment(): void
    {
        $created = ApiTestHelpers::api('stocktake', 'create', self::$token, [
            'take_name'        => 'STK-ADJ-' . random_int(0, 99999),
            'take_date'        => '2026-08-15',
            'scope_locations'  => ['CA01B01'],
        ]);
        $id = (int) $created['body']['id'];

        $items = ApiTestHelpers::q(
            'SELECT id, product_id FROM stock_take_items WHERE stock_take_id = ? AND location = ?',
            [$id, 'CA01B01']
        );
        $this->assertCount(1, $items);
        $this->assertSame(self::$productId, (int) $items[0]['product_id']);
        $itemId = (int) $items[0]['id'];

        $start = ApiTestHelpers::api('stocktake', 'start_counting', self::$token, ['id' => $id]);
        $this->assertTrue($start['body']['success']);

        $counters = ApiTestHelpers::api('stocktake', 'save_counters', self::$token, [
            'id'       => $id,
            'counters' => [(string) $itemId => ['c1' => 7, 'c2' => 7, 'c3' => null]],
        ]);
        $this->assertTrue($counters['body']['success']);

        $c2 = ApiTestHelpers::api('stocktake', 'advance_to_c2', self::$token, ['id' => $id, 'counters' => []]);
        $this->assertTrue($c2['body']['success']);

        $finish = ApiTestHelpers::api('stocktake', 'finish_counting', self::$token, ['id' => $id, 'counters' => []]);
        $this->assertTrue($finish['body']['success']);

        $review = ApiTestHelpers::api('stocktake', 'save_review', self::$token, [
            'id'         => $id,
            'physicals'  => [(string) $itemId => 7],
        ]);
        $this->assertTrue($review['body']['success']);

        $apply = ApiTestHelpers::api('stocktake', 'apply_adjustment', self::$token, ['id' => $id]);
        $this->assertTrue($apply['body']['success']);

        $stock = ApiTestHelpers::q(
            'SELECT quantity FROM stock WHERE product_id = ? AND location = ?',
            [self::$productId, 'CA01B01']
        );
        $this->assertSame(7, (int) $stock[0]['quantity']);
    }
}

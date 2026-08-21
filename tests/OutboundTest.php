<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Port of v2 apps/api/test/outbound.e2e-spec.ts — Outbound FEFO picking.
 * Tests: create outbound order, FEFO pick (oldest batch first), ship fully-picked order,
 * create order with inline items (FK on same transaction).
 */
final class OutboundTest extends TestCase
{
    private static string $token = '';
    private static int $customerId = 0;
    private static int $productId = 0;

    public static function setUpBeforeClass(): void
    {
        ApiTestHelpers::resetDb();
        self::$token = ApiTestHelpers::login();

        // Create customer (port of v2 helpers.ts customer creation)
        $code = 'CUST' . random_int(0, 99999);
        $stmt = ApiTestHelpers::pdo()->prepare(
            "INSERT INTO customers (customer_name, customer_code, address, city) VALUES (?, ?, 'Jln Test', 'Jakarta')"
        );
        $stmt->execute(['Test Customer', $code]);
        self::$customerId = (int) ApiTestHelpers::pdo()->lastInsertId();

        self::$productId = ApiTestHelpers::createProduct(['uom_type' => 'Drum']);
    }

    public function testCreatesAnOutboundOrder(): void
    {
        $shipmentNumber = 'SHIP' . random_int(0, 99999);
        $res = ApiTestHelpers::api('outbound', 'create', self::$token, [
            'customer_id'      => self::$customerId,
            'order_date'       => '2026-08-15',
            'expected_date'    => '2026-08-16',
            'shipment_number'  => $shipmentNumber,
        ]);
        $this->assertTrue($res['body']['success']);
        $id = (int) $res['body']['id'];
        $this->assertGreaterThan(0, $id);
    }

    public function testPicksStockFEFOOldestBatchFirst(): void
    {
        // Insert older batch (expires sooner)
        $older = ApiTestHelpers::q(
            "INSERT INTO stock (product_id, batch_number, location, quantity, uom, pallet, expiry_date, stock_status)
             VALUES (?, 'BATCH-OLD', 'CA01A01', 10, 'Drum', 3, '2026-12-01', 'Available')",
            [self::$productId]
        );
        $olderId = (int) ApiTestHelpers::pdo()->lastInsertId();

        // Insert newer batch (expires later)
        $newer = ApiTestHelpers::q(
            "INSERT INTO stock (product_id, batch_number, location, quantity, uom, pallet, expiry_date, stock_status)
             VALUES (?, 'BATCH-NEW', 'CB01A01', 10, 'Drum', 3, '2027-12-01', 'Available')",
            [self::$productId]
        );
        $newerId = (int) ApiTestHelpers::pdo()->lastInsertId();

        // Create stock_locations for both
        ApiTestHelpers::putStock(self::$productId, 'CA01A01', 10, 'BATCH-OLD', '2026-12-01');
        ApiTestHelpers::putStock(self::$productId, 'CB01A01', 10, 'BATCH-NEW', '2027-12-01');

        $created = ApiTestHelpers::api('outbound', 'create', self::$token, [
            'customer_id'   => self::$customerId,
            'order_date'    => '2026-08-15',
            'expected_date' => '2026-08-16',
        ]);
        $this->assertTrue($created['body']['success']);
        $orderId = (int) $created['body']['id'];

        $add = ApiTestHelpers::api('outbound', 'add_item', self::$token, [
            'outbound_id' => $orderId,
            'item'        => [
                'product_id' => self::$productId,
                'quantity'   => 8,
                'uom'        => 'Drum',
                'batch_no'   => '',
                'location'   => '',
            ],
        ]);
        $this->assertTrue($add['body']['success']);

        $pick = ApiTestHelpers::api('outbound', 'pick_items', self::$token, [], ['id' => $orderId]);
        $this->assertTrue($pick['body']['success']);

        // Verify FEFO: older batch (BATCH-OLD) should be picked first
        // 8 units picked from older batch (10 -> 2), newer batch untouched (10)
        $oldRow = ApiTestHelpers::q('SELECT quantity FROM stock WHERE id = ?', [$olderId]);
        $newRow = ApiTestHelpers::q('SELECT quantity FROM stock WHERE id = ?', [$newerId]);
        $this->assertSame(2, (int) $oldRow[0]['quantity']);
        $this->assertSame(10, (int) $newRow[0]['quantity']);
    }

    public function testShipsAFullyPickedOrder(): void
    {
        $shipProductId = ApiTestHelpers::createProduct(['uom_type' => 'Drum']);
        ApiTestHelpers::putStock($shipProductId, 'CA01B01', 10, 'BATCH-OLD', '2026-12-01');

        $created = ApiTestHelpers::api('outbound', 'create', self::$token, [
            'customer_id'   => self::$customerId,
            'order_date'    => '2026-08-15',
            'expected_date' => '2026-08-16',
        ]);
        $this->assertTrue($created['body']['success']);
        $orderId = (int) $created['body']['id'];

        ApiTestHelpers::api('outbound', 'add_item', self::$token, [
            'outbound_id' => $orderId,
            'item'        => [
                'product_id' => $shipProductId,
                'quantity'   => 4,
                'uom'        => 'Drum',
                'batch_no'   => 'BATCH-OLD',
                'location'   => 'CA01A01',
            ],
        ]);

        $pick = ApiTestHelpers::api('outbound', 'pick_items', self::$token, [], ['id' => $orderId]);
        $this->assertTrue($pick['body']['success']);

        $ship = ApiTestHelpers::api('outbound', 'ship', self::$token, ['shipped_date' => '2026-08-16'], ['id' => $orderId]);
        $this->assertTrue($ship['body']['success']);

        $rows = ApiTestHelpers::q('SELECT status FROM outbound_orders WHERE id = ?', [$orderId]);
        $this->assertSame('Shipped', $rows[0]['status']);
    }

    public function testCreatesOrderWithInlineItemsRegressionFKOnSameTransaction(): void
    {
        $inlineProductId = ApiTestHelpers::createProduct(['uom_type' => 'Drum']);
        ApiTestHelpers::putStock($inlineProductId, 'CA01C01', 20, 'BATCH-INLINE', '2026-12-01');

        $res = ApiTestHelpers::api('outbound', 'create', self::$token, [
            'customer_id'   => self::$customerId,
            'order_date'    => '2026-08-15',
            'expected_date' => '2026-08-16',
            'items'         => [
                ['product_id' => $inlineProductId, 'quantity' => 6, 'uom' => 'Drum'],
                ['product_id' => $inlineProductId, 'quantity' => 4, 'uom' => 'Drum'],
            ],
        ]);
        $this->assertTrue($res['body']['success']);
        $orderId = (int) $res['body']['id'];

        $itemRows = ApiTestHelpers::q(
            'SELECT product_id, quantity FROM outbound_items WHERE outbound_order_id = ? ORDER BY id',
            [$orderId]
        );
        $this->assertCount(2, $itemRows);
        $this->assertTrue(
            array_reduce($itemRows, fn($carry, $r) => $carry && (int) $r['product_id'] === $inlineProductId, true)
        );
        $totalQty = array_reduce($itemRows, fn($carry, $r) => $carry + (int) $r['quantity'], 0);
        $this->assertSame(10, $totalQty);
    }
}